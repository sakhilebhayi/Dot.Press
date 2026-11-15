<?php

namespace Tests\Feature\Press;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * R-52 tenant-OR sweep. The library search (`GET /api/decks?q=`, which is also the header pill's list) combines a title match
 * with the visibility rule `visibility != private OR owner OR shared`. That OR is grouped in a closure and sits beside the
 * team scope (DeckAccessScope), so a team-visible deck of ANOTHER team whose title matches the query never appears.
 */
class SearchTenantScopeTest extends TestCase
{
    use MakesDecks;
    use RefreshDatabase;

    public function test_a_matching_deck_of_another_team_never_appears_in_the_library_search(): void
    {
        $mine = $this->owner();
        $theirs = $this->owner();
        $own = $this->deckFor($mine, 'Zebra plan (mine)', 0);
        $other = $this->deckFor($theirs, 'Zebra plan (theirs)', 0);
        $other->update(['visibility' => 'team']);

        $titles = array_column($this->actingAs($mine)->getJson('/api/decks?q=zebra')->assertOk()->json('data'), 'title');
        $this->assertSame(['Zebra plan (mine)'], $titles);
        $this->assertNotContains('Zebra plan (theirs)', array_column($this->actingAs($mine)->getJson('/api/decks?q=plan&filter=team')->assertOk()->json('data'), 'title'));
        $this->assertSame(['Zebra plan (theirs)'], array_column($this->actingAs($theirs)->getJson('/api/decks?q=zebra')->assertOk()->json('data'), 'title'));
        $this->assertNotNull($own);
    }
}
