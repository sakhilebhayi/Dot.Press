<?php

namespace Tests\Feature\Press;

use App\Models\DeckChange;
use App\Models\DeckPresence;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ChangesFeedTest extends TestCase
{
    use MakesDecks;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_changes_after_a_seq_come_back_in_order_with_client_user_and_ops(): void
    {
        $user = $this->owner();
        $other = $this->teammate($user);
        $deck = $this->deckFor($user);
        $slide = $deck->slides()->first();

        $s1 = $this->ops($user, $deck, [['op' => 'el.add', 'slide' => $slide->id, 'el' => $this->shape('a')]], 'k1', 'tab-1')->json('seq');
        $s2 = $this->ops($other, $deck, [['op' => 'el.set', 'slide' => $slide->id, 'id' => 'a', 'p' => ['x' => 5]]], 'k2', 'tab-2')->json('seq');

        $all = $this->actingAs($user)->getJson("/api/decks/{$deck->id}/changes?after=0")->assertOk()->json();
        $this->assertSame($s2, $all['seq']);
        $this->assertFalse($all['more']);
        $this->assertSame([$s1, $s2], array_column($all['changes'], 'seq'));
        $this->assertSame('tab-2', $all['changes'][1]['client']);
        $this->assertSame($other->id, $all['changes'][1]['user']['id']);
        $this->assertSame('el.set', $all['changes'][1]['ops'][0]['op']);

        $tail = $this->actingAs($user)->getJson("/api/decks/{$deck->id}/changes?after={$s1}")->json();
        $this->assertSame([$s2], array_column($tail['changes'], 'seq'));

        $none = $this->actingAs($user)->getJson("/api/decks/{$deck->id}/changes?after={$s2}")->json();
        $this->assertSame([], $none['changes']);
        $this->assertSame($s2, $none['seq']);
    }

    public function test_an_empty_log_answers_seq_zero_and_the_document_seq_matches(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user);

        $this->actingAs($user)->getJson("/api/decks/{$deck->id}/changes")->assertOk()->assertJsonPath('seq', 0)->assertJsonPath('changes', []);
        $this->assertSame(0, $this->actingAs($user)->getJson("/api/decks/{$deck->id}/document")->json('seq'));

        $seq = $this->ops($user, $deck, [['op' => 'deck.set', 'title' => 'T']])->json('seq');
        $this->assertSame($seq, $this->actingAs($user)->getJson("/api/decks/{$deck->id}/document")->json('seq'));
    }

    public function test_seqs_are_per_deck_even_though_ids_are_global(): void
    {
        $user = $this->owner();
        $one = $this->deckFor($user, 'One');
        $two = $this->deckFor($user, 'Two');
        $this->ops($user, $one, [['op' => 'deck.set', 'title' => 'A']]);
        $seqTwo = $this->ops($user, $two, [['op' => 'deck.set', 'title' => 'B']])->json('seq');

        $feed = $this->actingAs($user)->getJson("/api/decks/{$one->id}/changes?after=0")->json();
        $this->assertCount(1, $feed['changes']);
        $this->assertNotContains($seqTwo, array_column($feed['changes'], 'seq'));
    }

    public function test_a_seq_from_the_future_is_stale(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user);
        $this->ops($user, $deck, [['op' => 'deck.set', 'title' => 'A']]);

        $this->actingAs($user)->getJson("/api/decks/{$deck->id}/changes?after=99999")->assertOk()->assertJsonPath('stale', true);
    }

    public function test_pruning_collapses_old_rows_into_a_tombstone_and_old_clients_get_stale(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user);
        $slide = $deck->slides()->first();

        Carbon::setTestNow(now()->subDays(20));
        $old1 = $this->ops($user, $deck, [['op' => 'el.add', 'slide' => $slide->id, 'el' => $this->shape('a')]], 'o1')->json('seq');
        $old2 = $this->ops($user, $deck, [['op' => 'el.add', 'slide' => $slide->id, 'el' => $this->shape('b')]], 'o2')->json('seq');
        Carbon::setTestNow();
        $recent = $this->ops($user, $deck, [['op' => 'el.add', 'slide' => $slide->id, 'el' => $this->shape('c')]], 'r1')->json('seq');

        $this->artisan('press:prune-changes')->assertSuccessful();

        $this->assertNull(DeckChange::find($old1));
        $tomb = DeckChange::find($old2);
        $this->assertSame('tombstone', $tomb->key);
        $this->assertSame([['op' => 'deck.reload']], $tomb->ops);
        $this->assertNotNull(DeckChange::find($recent));

        // A client at the pruned seq cannot be caught up; one past the tombstone can.
        $this->actingAs($user)->getJson("/api/decks/{$deck->id}/changes?after={$old1}")->assertJsonPath('stale', true);
        $this->actingAs($user)->getJson("/api/decks/{$deck->id}/changes?after=0")->assertJsonPath('stale', true);
        $ok = $this->actingAs($user)->getJson("/api/decks/{$deck->id}/changes?after={$old2}")->json();
        $this->assertArrayNotHasKey('stale', $ok);
        $this->assertSame([$recent], array_column($ok['changes'], 'seq'));

        // Running it again changes nothing.
        $this->artisan('press:prune-changes')->assertSuccessful();
        $this->assertSame(2, DeckChange::where('deck_id', $deck->id)->count());
    }

    public function test_pruning_also_clears_stale_presence_and_is_scheduled_daily(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user);
        DeckPresence::create(['deck_id' => $deck->id, 'user_id' => $user->id, 'client' => 'old', 'name' => 'x', 'color' => '#000000', 'seen_at' => now()->subMinutes(5)]);
        DeckPresence::create(['deck_id' => $deck->id, 'user_id' => $user->id, 'client' => 'new', 'name' => 'x', 'color' => '#000000', 'seen_at' => now()]);

        $this->artisan('press:prune-changes')->assertSuccessful();

        $this->assertSame(['new'], DeckPresence::pluck('client')->all());
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'press:prune-changes'));
        $this->assertNotNull($event);
        $this->assertSame('20 3 * * *', $event->expression);
    }
}
