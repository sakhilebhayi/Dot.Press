<?php

namespace Tests\Feature\Press;

use App\Models\Deck;
use App\Models\DeckPresence;
use App\Models\DeckShare;
use App\Services\Press\VersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DeckLibraryTest extends TestCase
{
    use MakesDecks;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function titles($response): array
    {
        return array_column($response->assertOk()->json('data'), 'title');
    }

    public function test_each_row_carries_what_a_library_card_needs(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user, 'Roadmap', 0);
        $first = $this->slideFor($deck, 'One', 0, [$this->shape('a'), $this->shape('hidden', ['hide' => true]), $this->shape('b')]);
        $first->update(['bg' => '#112233']);
        $this->slideFor($deck, 'Two', 1, [$this->shape('z')]);
        $this->actingAs($user)->postJson("/api/decks/{$deck->id}/favourite");

        $row = $this->actingAs($user)->getJson('/api/decks')->assertOk()->json('data.0');

        $this->assertSame('Roadmap', $row['title']);
        $this->assertSame([1920, 1080], [$row['w'], $row['h']]);
        $this->assertSame('draft', $row['status']);
        $this->assertSame('team', $row['visibility']);
        $this->assertSame(2, $row['slides']);
        $this->assertSame($user->name, $row['owner']);
        $this->assertTrue($row['favourite']);
        $this->assertSame($first->id, $row['first_slide']);
        $this->assertSame('#112233', $row['preview']['bg']);
        $this->assertSame(['a', 'b'], array_column($row['preview']['els'], 'id'));
        $this->assertArrayHasKey('updated_at', $row);
        $this->assertArrayHasKey('last_opened_at', $row);
    }

    public function test_the_preview_is_capped_in_size(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user, 'Heavy', 0);
        $els = [];
        for ($i = 0; $i < 10; $i++) {
            $els[] = $this->shape("e{$i}", ['alt' => str_repeat('x', 400), 'name' => str_repeat('n', 120)]);
        }
        $els[] = ['id' => 'big', 'type' => 'table', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'rows' => array_fill(0, 100, array_fill(0, 5, ['t' => str_repeat('q', 200)]))];
        $this->slideFor($deck, 'One', 0, $els);

        $preview = $this->actingAs($user)->getJson('/api/decks')->json('data.0.preview.els');

        $this->assertLessThanOrEqual(60000, strlen(json_encode($preview)));
        $this->assertNotContains('big', array_column($preview, 'id'));
        $this->assertCount(10, $preview);
    }

    public function test_search_sort_and_cursor_pagination(): void
    {
        $user = $this->owner();
        Carbon::setTestNow('2026-11-01 09:00:00');
        $this->deckFor($user, 'Beta plan');
        Carbon::setTestNow('2026-11-02 09:00:00');
        $this->deckFor($user, 'alpha 100%');
        Carbon::setTestNow('2026-11-03 09:00:00');
        $this->deckFor($user, 'Gamma');
        Carbon::setTestNow();

        $this->assertSame(['Gamma', 'alpha 100%', 'Beta plan'], $this->titles($this->actingAs($user)->getJson('/api/decks')));
        $this->assertSame(['alpha 100%', 'Beta plan', 'Gamma'], $this->titles($this->actingAs($user)->getJson('/api/decks?sort=title')));
        $this->assertSame(['Gamma', 'alpha 100%', 'Beta plan'], $this->titles($this->actingAs($user)->getJson('/api/decks?sort=created')));
        $this->assertSame(['Beta plan'], $this->titles($this->actingAs($user)->getJson('/api/decks?q=beta')));
        // LIKE wildcards in the search are literal.
        $this->assertSame(['alpha 100%'], $this->titles($this->actingAs($user)->getJson('/api/decks?q=100%25')));
        $this->assertSame([], $this->titles($this->actingAs($user)->getJson('/api/decks?q=_')));

        $page1 = $this->actingAs($user)->getJson('/api/decks?per_page=2')->assertOk();
        $this->assertSame(['Gamma', 'alpha 100%'], array_column($page1->json('data'), 'title'));
        $this->assertNotNull($page1->json('next_cursor'));
        $page2 = $this->actingAs($user)->getJson('/api/decks?per_page=2&cursor='.$page1->json('next_cursor'))->assertOk();
        $this->assertSame(['Beta plan'], array_column($page2->json('data'), 'title'));
        $this->assertNull($page2->json('next_cursor'));

        $this->actingAs($user)->getJson('/api/decks?per_page=500')->assertStatus(422);
        $this->actingAs($user)->getJson('/api/decks?filter=everything')->assertStatus(422);
    }

    public function test_filters(): void
    {
        $owner = $this->owner();
        $mate = $this->teammate($owner);
        $mine = $this->deckFor($owner, 'Mine');
        $theirs = $this->deckFor($mate, 'Theirs', 1, $owner->currentTeam);
        $fav = $this->deckFor($owner, 'Fav');
        $recent = $this->deckFor($owner, 'Recent');
        $archived = $this->deckFor($owner, 'Archived');
        $template = $this->deckFor($owner, 'Template');
        $template->update(['is_template' => true]);
        $archived->update(['archived_at' => now()]);

        $this->actingAs($owner)->postJson("/api/decks/{$fav->id}/favourite");
        Carbon::setTestNow('2026-11-05 10:00:00');
        $this->actingAs($owner)->postJson("/api/decks/{$recent->id}/touch");
        Carbon::setTestNow('2026-11-05 11:00:00');
        $this->actingAs($owner)->postJson("/api/decks/{$mine->id}/touch");
        Carbon::setTestNow();

        $get = fn ($user, $q = '') => $this->titles($this->actingAs($user)->getJson('/api/decks'.$q));

        $this->assertEqualsCanonicalizing(['Mine', 'Theirs', 'Fav', 'Recent'], $get($owner));
        $this->assertEqualsCanonicalizing(['Mine', 'Fav', 'Recent'], $get($owner, '?filter=mine'));
        $this->assertEqualsCanonicalizing(['Theirs'], $get($mate, '?filter=mine'));
        $this->assertEqualsCanonicalizing(['Mine', 'Theirs', 'Fav', 'Recent'], $get($owner, '?filter=team'));
        $this->assertEqualsCanonicalizing(['Theirs'], $get($owner, '?filter=shared'));
        $this->assertSame(['Fav'], $get($owner, '?filter=favourites'));
        $this->assertSame(['Mine', 'Recent'], $get($owner, '?filter=recent'));
        $this->assertSame(['Archived'], $get($owner, '?filter=archived'));
        $this->assertSame(['Template'], $get($owner, '?filter=templates'));
        $this->assertSame([], $get($mate, '?filter=favourites'));
    }

    public function test_private_decks_show_only_to_their_owner_and_people_they_are_shared_with(): void
    {
        $owner = $this->owner();
        $mate = $this->teammate($owner);
        $deck = $this->deckFor($owner, 'Secret');
        $deck->update(['visibility' => 'private']);

        $this->assertSame(['Secret'], $this->titles($this->actingAs($owner)->getJson('/api/decks')));
        $this->assertSame([], $this->titles($this->actingAs($mate)->getJson('/api/decks')));

        DeckShare::create(['deck_id' => $deck->id, 'user_id' => $mate->id, 'role' => 'viewer', 'invited_by' => $owner->id]);
        $this->assertSame(['Secret'], $this->titles($this->actingAs($mate)->getJson('/api/decks')));
    }

    public function test_a_user_of_another_team_can_never_reach_a_deck_through_any_route(): void
    {
        $a = $this->owner();
        $b = $this->owner();
        $deck = $this->deckFor($a, 'Team A deck');
        $slide = $deck->slides()->first();
        $version = VersionService::snapshot($deck, $a, 'manual', 'x');
        $id = $deck->id;

        $this->assertSame([], $this->titles($this->actingAs($b)->getJson('/api/decks')));
        $this->assertSame([], $this->titles($this->actingAs($b)->getJson('/api/decks?filter=team')));
        $this->assertSame([], $this->titles($this->actingAs($b)->getJson('/api/decks?filter=shared')));

        $this->actingAs($b)->getJson("/api/decks/{$id}")->assertNotFound();
        $this->actingAs($b)->patchJson("/api/decks/{$id}", ['title' => 'Mine now'])->assertNotFound();
        $this->actingAs($b)->deleteJson("/api/decks/{$id}")->assertNotFound();
        $this->actingAs($b)->deleteJson("/api/decks/{$id}?permanent=1")->assertNotFound();
        $this->actingAs($b)->postJson("/api/decks/{$id}/duplicate")->assertNotFound();
        $this->actingAs($b)->postJson("/api/decks/{$id}/favourite")->assertNotFound();
        $this->actingAs($b)->postJson("/api/decks/{$id}/touch")->assertNotFound();
        $this->actingAs($b)->postJson("/api/decks/{$id}/unarchive")->assertNotFound();
        $this->actingAs($b)->getJson("/api/decks/{$id}/document")->assertNotFound();
        $this->actingAs($b)->getJson("/api/decks/{$id}/changes")->assertNotFound();
        $this->actingAs($b)->postJson("/api/decks/{$id}/ops", ['client' => 'c', 'key' => 'k', 'ops' => [['op' => 'deck.set', 'title' => 'x']]])->assertNotFound();
        $this->actingAs($b)->postJson("/api/decks/{$id}/presence", ['client' => 'c'])->assertNotFound();
        $this->actingAs($b)->getJson("/api/decks/{$id}/versions")->assertNotFound();
        $this->actingAs($b)->getJson("/api/decks/{$id}/versions/{$version->id}")->assertNotFound();
        $this->actingAs($b)->postJson("/api/decks/{$id}/versions/{$version->id}/restore")->assertNotFound();
        $this->actingAs($b)->getJson("/api/slides/{$slide->id}")->assertNotFound();
        $this->actingAs($b)->postJson("/api/ai/decks/{$id}/generate-slide", ['prompt' => 'x'])->assertNotFound();
        $this->actingAs($b)->postJson("/api/ai/decks/{$id}/generate-deck", ['prompt' => 'x'])->assertNotFound();
        $this->actingAs($b)->postJson("/api/ai/slides/{$slide->id}/rewrite-text", ['text' => 'x', 'mode' => 'shorten'])->assertNotFound();

        $this->assertSame('Team A deck', Deck::withoutGlobalScopes()->find($id)->title);
        $this->assertNull(Deck::withoutGlobalScopes()->find($id)->archived_at);
        $this->assertSame(0, DeckPresence::count());
    }

    public function test_guests_get_401_everywhere(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user);

        foreach (["decks/{$deck->id}/document", "decks/{$deck->id}/changes", "decks/{$deck->id}/versions", 'decks'] as $path) {
            $this->getJson("/api/{$path}")->assertUnauthorized();
        }
        $this->postJson("/api/decks/{$deck->id}/ops", ['client' => 'c', 'key' => 'k', 'ops' => []])->assertUnauthorized();
    }
}
