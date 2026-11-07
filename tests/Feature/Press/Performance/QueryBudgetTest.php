<?php

namespace Tests\Feature\Press\Performance;

use App\Models\DeckShare;
use App\Models\Project;
use App\Services\Press\Bench\DeckGenerator;
use App\Services\Press\DeckAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Press\MakesDecks;
use Tests\TestCase;

/**
 * The number of queries the hot endpoints run, pinned so that a change that adds one per slide, per deck or per
 * element is caught here and not by the hosting bill. The budgets are the measured numbers (docs/programme/R40_PERFORMANCE.md)
 * plus a little room; what matters most is that they do not grow with the size of the deck or the library.
 */
class QueryBudgetTest extends TestCase
{
    use MakesDecks;
    use RefreshDatabase;

    /** @return array{0: int, 1: list<string>} query count and statements */
    private function queries(callable $fn): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $fn();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        return [count($log), array_column($log, 'query')];
    }

    private function bigDeck($user, int $slides)
    {
        $project = Project::firstOrCreate(['team_id' => $user->currentTeam->id, 'name' => 'Board'], ['user_id' => $user->id, 'slug' => 'board-x']);

        return (new DeckGenerator([1]))->createDeck($project, $user, 'Big '.$slides, $slides);
    }

    public function test_reading_the_document_costs_the_same_for_3_slides_and_30(): void
    {
        $user = $this->owner();
        $small = $this->bigDeck($user, 3);
        $large = $this->bigDeck($user, 30);

        $this->actingAs($user)->getJson("/api/decks/{$small->id}/document")->assertOk()->streamedContent(); // warm up: the first request of a test boots the session and the guard
        [$a] = $this->queries(fn () => $this->actingAs($user)->getJson("/api/decks/{$small->id}/document")->assertOk()->streamedContent());
        [$b, $sql] = $this->queries(fn () => $this->actingAs($user)->getJson("/api/decks/{$large->id}/document")->assertOk()->streamedContent());

        $this->assertSame($a, $b, 'the number of queries does not depend on the number of slides');
        $this->assertLessThanOrEqual(12, $b, implode("\n", $sql));
    }

    public function test_an_idle_poll_of_the_changes_feed_is_a_handful_of_queries(): void
    {
        $user = $this->owner();
        $deck = $this->bigDeck($user, 5);
        $seq = $this->ops($user, $deck, [['op' => 'deck.set', 'title' => 'x']])->json('seq');

        [$n, $sql] = $this->queries(fn () => $this->actingAs($user)->getJson("/api/decks/{$deck->id}/changes?after={$seq}")->assertOk()->assertJsonPath('changes', []));

        $this->assertLessThanOrEqual(9, $n, implode("\n", $sql));
    }

    public function test_a_presence_beat_is_a_handful_of_queries(): void
    {
        $user = $this->owner();
        $deck = $this->bigDeck($user, 5);
        $slide = $deck->slides()->first();
        $this->actingAs($user)->postJson("/api/decks/{$deck->id}/presence", ['client' => 'tab-a', 'slide' => $slide->id])->assertOk();

        [$n, $sql] = $this->queries(fn () => $this->actingAs($user)->postJson("/api/decks/{$deck->id}/presence", ['client' => 'tab-a', 'slide' => $slide->id, 'cursor' => ['x' => 1, 'y' => 2]])->assertOk());

        $this->assertLessThanOrEqual(11, $n, implode("\n", $sql));
    }

    public function test_an_operations_batch_costs_about_the_same_for_one_op_and_thirty(): void
    {
        $user = $this->owner();
        $deck = $this->bigDeck($user, 4);
        $slide = $deck->slides()->first();
        $ids = array_column($slide->els, 'id');
        $this->ops($user, $deck, DeckGenerator::dragOps($slide->id, $ids, 1)); // the first batch also takes the automatic version

        [$one, $sqlOne] = $this->queries(fn () => $this->ops($user, $deck, DeckGenerator::dragOps($slide->id, $ids, 1, 3))->assertOk());
        [$many, $sql] = $this->queries(fn () => $this->ops($user, $deck, DeckGenerator::dragOps($slide->id, $ids, 30, 5))->assertOk());

        // A batch that grows the document is also checked against the deck's content budget (two statements). Nothing
        // else depends on the number of ops: they are applied in memory and the slide is written once.
        $this->assertLessThanOrEqual($one + 2, $many, implode("\n", $sqlOne)."\n---\n".implode("\n", $sql));
        $this->assertLessThanOrEqual(24, $many, implode("\n", $sql));
    }

    public function test_adding_a_slide_does_not_update_every_slide_below_it_one_by_one(): void
    {
        $user = $this->owner();
        $deck = $this->bigDeck($user, 40);
        $first = $deck->slides()->orderBy('sort_order')->first();

        [$n, $sql] = $this->queries(fn () => $this->ops($user, $deck, [['op' => 'slide.add', 'tid' => 'new-1', 'after' => $first->id, 'slide' => ['title' => 'New']]])->assertOk());

        $this->assertLessThanOrEqual(34, $n, 'one UPDATE moves all 39 slides below the new one: '.implode("\n", $sql));
        $this->assertSame(range(0, 40), $deck->slides()->orderBy('sort_order')->pluck('sort_order')->all());
        $this->assertSame('New', $deck->slides()->orderBy('sort_order')->skip(1)->first()->title);
    }

    public function test_the_library_page_costs_the_same_for_3_decks_and_24(): void
    {
        $user = $this->owner();
        $this->deckFor($user, 'A');
        $this->deckFor($user, 'B');
        $this->deckFor($user, 'C');
        [$few] = $this->queries(fn () => $this->actingAs($user)->getJson('/api/decks?per_page=24')->assertOk());

        for ($i = 0; $i < 21; $i++) {
            $this->deckFor($user, "More {$i}", 2);
        }
        [$many, $sql] = $this->queries(fn () => $this->actingAs($user)->getJson('/api/decks?per_page=24')->assertOk()->assertJsonCount(24, 'data'));

        // The first view of a card cuts it and keeps it on the deck row: two extra statements for the whole page, not two per deck.
        $this->assertLessThanOrEqual($few + 24 + 2, $many, implode("\n", $sql));
        [$again, $sql2] = $this->queries(fn () => $this->actingAs($user)->getJson('/api/decks?per_page=24')->assertOk());
        $this->assertLessThanOrEqual($few + 1, $again, 'the second view reads the kept cards: '.implode("\n", $sql2));
    }

    public function test_the_editor_page_asks_for_access_once_not_once_per_question(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user, 'Deck', 2);

        [$n, $sql] = $this->queries(fn () => $this->actingAs($user)->get("/decks/{$deck->id}")->assertOk());

        $grants = collect($sql)->filter(fn ($q) => str_contains($q, 'from "deck_shares" where "deck_id"'))->count();
        $this->assertLessThanOrEqual(2, $grants, implode("\n", $sql));
        $this->assertLessThanOrEqual(28, $n);
    }

    public function test_remembered_grants_last_one_request_and_a_share_clears_them(): void
    {
        $owner = $this->owner();
        $other = $this->teammate($owner, 'viewer');
        $deck = $this->deckFor($owner);

        // Outside a request nothing is remembered: a role changed between two calls is seen at once.
        $this->assertSame('viewer', DeckAccess::roleFor($other, $deck));
        DeckShare::create(['deck_id' => $deck->id, 'user_id' => $other->id, 'role' => 'editor']);
        $this->assertSame('editor', DeckAccess::roleFor($other, $deck));

        DeckAccess::remember(true);
        try {
            [$first] = $this->queries(fn () => DeckAccess::roleFor($other, $deck));
            [$second] = $this->queries(fn () => DeckAccess::roleFor($other, $deck));
            $this->assertGreaterThan(0, $first);
            $this->assertSame(0, $second, 'the second ask in the same request is answered from memory');

            DeckShare::where('deck_id', $deck->id)->where('user_id', $other->id)->first()->update(['role' => 'commenter']);
            $this->assertSame('commenter', DeckAccess::roleFor($other, $deck), 'changing the share cleared what was remembered: the stronger of viewer and commenter');
        } finally {
            DeckAccess::remember(false);
        }
    }
}
