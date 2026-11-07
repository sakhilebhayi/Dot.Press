<?php

namespace Tests\Feature\Press;

use App\Models\Asset;
use App\Models\Deck;
use App\Models\DeckShare;
use App\Models\Slide;
use App\Services\Press\DeckAccess;
use Illuminate\Support\Facades\Storage;

/**
 * Every kind of person against every route this module owns plus the existing deck, slide and asset routes.
 * Ability columns: view, comment, update, manage.
 */
class AccessRoleMatrixTest extends AccessTestCase
{
    public function test_roles_and_abilities_for_every_kind_of_person(): void
    {
        $people = $this->people($this->deck);

        foreach ($this->expectations() as $key => [$role, $comment, $update, $manage]) {
            $person = $people[$key];
            $this->assertSame($role, DeckAccess::roleFor($person, $this->deck), "role of {$key}");
            $this->assertSame($role !== null, DeckAccess::can($person, $this->deck, 'view'), "view for {$key}");
            $this->assertSame($comment, DeckAccess::can($person, $this->deck, 'comment'), "comment for {$key}");
            $this->assertSame($update, DeckAccess::can($person, $this->deck, 'update'), "update for {$key}");
            $this->assertSame($manage, DeckAccess::can($person, $this->deck, 'manage'), "manage for {$key}");
            $this->assertSame($role !== null, DeckAccess::can($person, $this->deck, 'present'), "present for {$key}");
            $this->assertSame($role !== null, DeckAccess::can($person, $this->deck, 'export'), "export for {$key}");
        }
    }

    public function test_every_route_follows_the_role(): void
    {
        $people = $this->people($this->deck);
        $id = $this->deck->id;

        foreach ($this->expectations() as $key => [$role, $comment, $update, $manage]) {
            $person = $people[$key];
            $see = $role !== null;
            $this->actingAs($person);

            // reads need view; an outsider gets 404 (the deck does not exist for them), not 403
            foreach (["/api/decks/{$id}", "/api/decks/{$id}/people", "/api/decks/{$id}/comments", "/api/decks/{$id}/reviews", "/api/decks/{$id}/activity", "/api/decks/{$id}/document", "/api/decks/{$id}/changes", "/api/decks/{$id}/versions"] as $url) {
                $see ? $this->getJson($url)->assertOk() : $this->getJson($url)->assertNotFound();
            }
            $this->getJson("/api/slides/{$this->slide->id}")->assertStatus($see ? 200 : 404);
            $this->assertSame($see, in_array($id, collect($this->getJson('/api/decks?per_page=60')->assertOk()->json('data'))->pluck('id')->all(), true), "library for {$key}");

            // manage-only reads
            foreach (["/api/decks/{$id}/sharing", "/api/decks/{$id}/sharing/people"] as $url) {
                $this->getJson($url)->assertStatus($manage ? 200 : ($see ? 403 : 404));
            }

            // comment
            $this->postJson("/api/decks/{$id}/comments", ['body' => "hello from {$key}"])
                ->assertStatus($comment ? 201 : ($see ? 403 : 404));

            // update: operations, renaming (an editing right), versions
            $this->postJson("/api/decks/{$id}/ops", ['client' => 'tab-1', 'key' => 'k'.uniqid(), 'ops' => [['op' => 'slide.set', 'id' => $this->slide->id, 'title' => 'Slide one']]])
                ->assertStatus($update ? 200 : ($see ? 403 : 404));
            $this->patchJson("/api/decks/{$id}", ['title' => 'Quarterly deck'])
                ->assertStatus($update ? 200 : ($see ? 403 : 404));
            $this->postJson("/api/decks/{$id}/versions", ['label' => 'v'])
                ->assertStatus($update ? 201 : ($see ? 403 : 404));

            // presence is a read: anyone who can open the deck
            $this->postJson("/api/decks/{$id}/presence", ['client' => 'c'.$person->id])
                ->assertStatus($see ? 200 : 404);

            // manage: sharing change and review request
            $this->putJson("/api/decks/{$id}/sharing", ['allow_download' => false])
                ->assertStatus($manage ? 200 : ($see ? 403 : 404));
            $this->postJson("/api/decks/{$id}/reviews", ['reviewer_ids' => [$this->viewer->id]])
                ->assertStatus($manage ? 201 : ($see ? 403 : 404));

            // delete is manage: a throwaway copy of the deck, created by the creator
            $throwaway = $this->makeDeck('team', "temp {$key}");
            $throwaway->forceFill(['user_id' => $this->editor->id])->save();
            DeckShare::where('deck_id', $this->deck->id)->get()->each(fn ($s) => DeckShare::create(['deck_id' => $throwaway->id, 'user_id' => $s->user_id, 'role' => $s->role]));
            $this->deleteJson("/api/decks/{$throwaway->id}")->assertStatus($manage ? 204 : ($see ? 403 : 404));
        }
    }

    public function test_assets_follow_team_membership_and_deck_use(): void
    {
        $used = $this->asset('used.png');
        $unused = $this->asset('unused.png');
        $this->slide->forceFill(['els' => [['id' => 'im', 'type' => 'image', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'src' => ['asset' => $used->id]]]])->save();

        $sharee = $this->sharedWith('viewer');

        foreach ([$this->owner, $this->admin, $this->editor, $this->viewer] as $member) {
            $this->actingAs($member)->getJson("/api/assets/{$used->id}")->assertOk();
            $this->actingAs($member)->getJson("/api/assets/{$unused->id}")->assertOk();
        }

        // the asset policy lets a sharee reach an asset the shared deck uses, and not the rest of the library
        $this->assertTrue($sharee->can('view', $used));
        $this->assertFalse($sharee->can('view', $unused));

        $this->actingAs($this->outsider)->getJson("/api/assets/{$used->id}")->assertNotFound();

        // writes need a team editor or better
        Storage::fake('local');
        $this->actingAs($this->viewer)->deleteJson("/api/assets/{$unused->id}")->assertForbidden();
        $this->actingAs($this->editor)->deleteJson("/api/assets/{$unused->id}")->assertNoContent();
    }

    public function test_team_roles_never_drop_below_the_team_role_when_shared_lower(): void
    {
        DeckShare::create(['deck_id' => $this->deck->id, 'user_id' => $this->admin->id, 'role' => 'viewer']);
        DeckShare::create(['deck_id' => $this->deck->id, 'user_id' => $this->editor->id, 'role' => 'viewer']);
        DeckShare::create(['deck_id' => $this->deck->id, 'user_id' => $this->viewer->id, 'role' => 'editor']);

        $this->assertSame('owner', DeckAccess::roleFor($this->admin, $this->deck));
        $this->assertSame('owner', DeckAccess::roleFor($this->editor, $this->deck), 'the creator stays owner');
        $this->assertSame('editor', DeckAccess::roleFor($this->viewer, $this->deck), 'a share raises a team viewer');
    }

    public function test_a_member_who_left_the_team_loses_the_creator_role(): void
    {
        $this->team->users()->detach($this->editor);

        $this->assertNull(DeckAccess::roleFor($this->editor->fresh(), $this->deck));
        $this->actingAs($this->editor->fresh())->getJson("/api/decks/{$this->deck->id}")->assertNotFound();
    }

    public function test_link_visibility_decks_are_still_team_decks_for_team_roles(): void
    {
        $this->deck->forceFill(['visibility' => 'link', 'share_token' => str_repeat('a', 40)])->save();

        $this->assertSame('owner', DeckAccess::roleFor($this->admin, $this->deck));
        $this->assertSame('viewer', DeckAccess::roleFor($this->viewer, $this->deck));
        $this->assertNull(DeckAccess::roleFor($this->outsider, $this->deck));
    }

    public function test_the_deck_list_and_slide_count_only_hold_what_the_person_can_see(): void
    {
        $shared = $this->makeDeck('private', 'Shared private');
        $sharee = $this->sharedWith('viewer', $shared);
        $hidden = $this->makeDeck('private', 'Hidden private');

        $this->actingAs($sharee);
        $this->assertSame(['Shared private'], Deck::query()->pluck('title')->all());
        $this->assertSame(1, Slide::count());
        $this->assertSame(['Shared private'], collect($this->getJson('/api/decks')->assertOk()->json('data'))->pluck('title')->all());
        $this->getJson("/api/decks/{$hidden->id}/document")->assertNotFound();
        $this->getJson('/api/projects')->assertOk();

        // a team admin sees the team decks and neither private deck
        $this->actingAs($this->admin);
        $this->assertSame(['Quarterly deck'], collect($this->getJson('/api/decks')->json('data'))->pluck('title')->all());
        $this->assertSame(1, Slide::count());
    }
}
