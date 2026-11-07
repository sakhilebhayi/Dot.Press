<?php

namespace Tests\Feature\Press;

use App\Models\DeckActivity;
use App\Models\DeckShare;
use App\Models\User;
use App\Services\Press\DeckAccess;
use Illuminate\Support\Facades\Hash;

class AccessSharingApiTest extends AccessTestCase
{
    private function url(string $suffix = ''): string
    {
        return "/api/decks/{$this->deck->id}/sharing{$suffix}";
    }

    public function test_show_reports_visibility_link_and_people_without_secrets(): void
    {
        $this->actingAs($this->owner)->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('visibility', 'team')
            ->assertJsonPath('link.enabled', false)
            ->assertJsonPath('link.has_password', false)
            ->assertJsonPath('people', []);
    }

    public function test_turning_the_link_on_creates_a_forty_character_token_and_records_activity(): void
    {
        $response = $this->actingAs($this->admin)->putJson($this->url(), ['visibility' => 'link'])->assertOk();

        $token = $response->json('link.token');
        $this->assertSame(40, strlen($token));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $token);
        $this->assertStringEndsWith("/s/{$token}", $response->json('link.url'));
        $this->assertSame($token, $this->deck->refresh()->share_token);
        $this->assertDatabaseHas('deck_activity', ['deck_id' => $this->deck->id, 'event' => 'visibility', 'user_id' => $this->admin->id]);
    }

    public function test_a_password_is_hashed_and_never_returned(): void
    {
        $response = $this->actingAs($this->owner)->putJson($this->url(), [
            'visibility' => 'link', 'password' => 'correct horse', 'allow_download' => true,
        ])->assertOk()->assertJsonPath('link.has_password', true)->assertJsonPath('link.allow_download', true);

        $this->assertStringNotContainsString('correct horse', $response->getContent());
        $this->assertStringNotContainsString('share_password', $response->getContent());

        $stored = $this->deck->refresh()->share_password;
        $this->assertNotSame('correct horse', $stored);
        $this->assertTrue(Hash::check('correct horse', $stored));

        // reading the deck through the normal API never exposes it either
        $this->actingAs($this->owner)->getJson("/api/decks/{$this->deck->id}")->assertOk()
            ->assertJsonMissingPath('share_password')->assertJsonMissingPath('share_token');

        $this->actingAs($this->owner)->putJson($this->url(), ['password' => null])->assertOk()->assertJsonPath('link.has_password', false);
        $this->assertNull($this->deck->refresh()->share_password);
    }

    public function test_expiry_must_be_in_the_future_and_is_returned(): void
    {
        $this->actingAs($this->owner)->putJson($this->url(), ['visibility' => 'link', 'expires_at' => now()->subDay()->toIso8601String()])->assertUnprocessable();

        $when = now()->addDays(3)->startOfSecond();
        $this->actingAs($this->owner)->putJson($this->url(), ['expires_at' => $when->toIso8601String()])
            ->assertOk()->assertJsonPath('link.expires_at', $when->toIso8601String());
    }

    public function test_rotate_replaces_the_token_and_needs_the_link_on(): void
    {
        $this->actingAs($this->owner)->postJson($this->url('/rotate-link'))->assertUnprocessable();

        $first = $this->actingAs($this->owner)->putJson($this->url(), ['visibility' => 'link'])->json('link.token');
        $second = $this->actingAs($this->owner)->postJson($this->url('/rotate-link'))->assertOk()->json('link.token');

        $this->assertNotSame($first, $second);
        $this->assertSame(40, strlen($second));
        $this->get("/s/{$first}")->assertNotFound();
        $this->assertDatabaseHas('deck_activity', ['deck_id' => $this->deck->id, 'event' => 'shared']);
    }

    public function test_leaving_link_visibility_revokes_the_token(): void
    {
        $token = $this->actingAs($this->owner)->putJson($this->url(), ['visibility' => 'link'])->json('link.token');
        $this->actingAs($this->owner)->putJson($this->url(), ['visibility' => 'team'])->assertOk()->assertJsonPath('link.token', null);

        $this->assertNull($this->deck->refresh()->share_token);
        $this->get("/s/{$token}")->assertNotFound();

        $again = $this->actingAs($this->owner)->putJson($this->url(), ['visibility' => 'link'])->json('link.token');
        $this->assertNotSame($token, $again);
    }

    public function test_visibility_is_validated(): void
    {
        $this->actingAs($this->owner)->putJson($this->url(), ['visibility' => 'public'])->assertUnprocessable();
    }

    public function test_adding_people_by_id_and_email_with_roles(): void
    {
        // By number only for someone already in one of your teams; anyone else is found by address.
        $byId = $this->member('viewer', 'By Id');
        $byEmail = User::factory()->create(['name' => 'By Email', 'email' => 'Someone@Example.test']);

        $this->actingAs($this->owner)->postJson($this->url('/people'), ['user_id' => $byId->id, 'role' => 'viewer'])->assertCreated();
        $this->actingAs($this->owner)->postJson($this->url('/people'), ['email' => 'someone@example.test', 'role' => 'editor'])->assertCreated()
            ->assertJsonCount(2, 'people');

        $this->assertSame('viewer', DeckAccess::roleFor($byId, $this->deck));
        $this->assertSame('editor', DeckAccess::roleFor($byEmail, $this->deck));
        $this->assertDatabaseHas('deck_activity', ['deck_id' => $this->deck->id, 'event' => 'shared']);

        $this->actingAs($byEmail)->getJson("/api/decks/{$this->deck->id}")->assertOk();
    }

    public function test_a_person_without_an_account_cannot_be_added(): void
    {
        $this->actingAs($this->owner)->postJson($this->url('/people'), ['email' => 'ghost@nowhere.test', 'role' => 'viewer'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->actingAs($this->owner)->postJson($this->url('/people'), ['user_id' => 999999, 'role' => 'viewer'])->assertUnprocessable();
        $this->assertSame(0, DeckShare::count());
    }

    public function test_roles_are_limited_and_the_creator_cannot_be_shared_with(): void
    {
        $person = User::factory()->create();
        $this->actingAs($this->owner)->postJson($this->url('/people'), ['user_id' => $person->id, 'role' => 'owner'])->assertUnprocessable();
        $this->actingAs($this->owner)->postJson($this->url('/people'), ['user_id' => $person->id])->assertUnprocessable();
        $this->actingAs($this->owner)->postJson($this->url('/people'), ['user_id' => $this->editor->id, 'role' => 'viewer'])->assertUnprocessable();
        $this->actingAs($this->owner)->postJson($this->url('/people'), ['role' => 'viewer'])->assertUnprocessable();
    }

    public function test_update_and_remove_a_person(): void
    {
        $person = User::factory()->create();
        $this->actingAs($this->owner)->postJson($this->url('/people'), ['email' => $person->email, 'role' => 'viewer'])->assertCreated();

        $this->actingAs($this->owner)->patchJson($this->url("/people/{$person->id}"), ['role' => 'commenter'])->assertOk()
            ->assertJsonPath('people.0.role', 'commenter');
        $this->assertSame('commenter', DeckAccess::roleFor($person, $this->deck));

        $this->actingAs($this->owner)->deleteJson($this->url("/people/{$person->id}"))->assertNoContent();
        $this->assertNull(DeckAccess::roleFor($person, $this->deck));
        $this->actingAs($this->owner)->deleteJson($this->url("/people/{$person->id}"))->assertNotFound();
        $this->actingAs($this->owner)->patchJson($this->url("/people/{$person->id}"), ['role' => 'viewer'])->assertNotFound();

        $this->assertSame(['added', 'changed', 'removed'], DeckActivity::where('event', 'shared')->orderBy('id')->get()->pluck('meta.action')->all());
    }

    public function test_people_endpoint_lists_everyone_with_access_and_hides_emails_from_non_managers(): void
    {
        $sharee = $this->sharedWith('commenter', $this->deck, 'Zed Sharee');

        $manager = $this->actingAs($this->owner)->getJson("/api/decks/{$this->deck->id}/people")->assertOk()->json('people');
        $names = collect($manager)->pluck('name')->all();
        foreach (['Olive Owner', 'Adam Admin', 'Edie Editor', 'Vera Viewer', 'Zed Sharee'] as $name) {
            $this->assertContains($name, $names);
        }
        $this->assertNotContains('Otto Outsider', $names);
        $this->assertArrayHasKey('email', $manager[0]);

        $roles = collect($manager)->pluck('role', 'name');
        $this->assertSame('owner', $roles['Adam Admin']);
        $this->assertSame('commenter', $roles['Zed Sharee']);

        $viewerView = $this->actingAs($this->viewer)->getJson("/api/decks/{$this->deck->id}/people")->assertOk();
        $this->assertArrayNotHasKey('email', $viewerView->json('people.0'));
        $this->assertContains('comment', $viewerView->json('you'));

        $this->actingAs($this->owner)->getJson("/api/decks/{$this->deck->id}/people?q=zed")->assertJsonCount(1, 'people');
        $this->assertSame($sharee->id, $this->actingAs($this->owner)->getJson("/api/decks/{$this->deck->id}/people?q=zed")->json('people.0.id'));
    }
}
