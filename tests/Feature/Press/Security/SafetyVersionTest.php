<?php

namespace Tests\Feature\Press\Security;

use App\Models\DeckVersion;
use App\Models\Slide;
use Illuminate\Support\Str;
use Tests\Feature\Press\AccessTestCase;

/**
 * No silent data loss: the automatic history snapshots the deck AFTER a batch, so a batch that removes slides used to
 * leave no copy of what it removed unless an older snapshot happened to be close. A batch that removes slides or
 * resizes the deck is now preceded by a snapshot of the deck as it was.
 */
class SafetyVersionTest extends AccessTestCase
{
    private function ops(array $ops, ?string $key = null)
    {
        return $this->actingAs($this->editor)->postJson("/api/decks/{$this->deck->id}/ops", ['client' => 't', 'key' => $key ?? 'k-'.Str::uuid(), 'ops' => $ops]);
    }

    public function test_removing_slides_keeps_a_copy_of_the_deck_as_it_was(): void
    {
        $a = Slide::create(['deck_id' => $this->deck->id, 'title' => 'Budget', 'sort_order' => 1, 'els' => [], 'revision' => 0]);
        $b = Slide::create(['deck_id' => $this->deck->id, 'title' => 'Plan', 'sort_order' => 2, 'els' => [], 'revision' => 0]);
        DeckVersion::query()->delete();
        $this->assertSame(0, DeckVersion::count());

        $this->ops([['op' => 'slide.remove', 'id' => $a->id], ['op' => 'slide.remove', 'id' => $b->id]])->assertOk()->assertJsonPath('skipped', []);

        $before = DeckVersion::query()->orderBy('id')->first();
        $this->assertNotNull($before, 'a snapshot exists');
        $this->assertSame(3, $before->slide_count, 'and it holds the three slides that were there before the removal');
        $this->assertStringContainsString('Budget', $before->snapshot);
        $this->assertSame(1, Slide::withoutGlobalScopes()->where('deck_id', $this->deck->id)->count());
    }

    public function test_a_resize_keeps_a_copy_of_the_deck_as_it_was(): void
    {
        DeckVersion::query()->delete();
        $this->ops([['op' => 'deck.resize', 'w' => 1440, 'h' => 1080, 'mode' => 'scale']])->assertOk();

        $before = DeckVersion::query()->orderBy('id')->first();
        $this->assertNotNull($before);
        $this->assertSame(1920, (int) json_decode($before->snapshot, true)['deck']['w'] ?: 1920);
    }

    public function test_ordinary_edits_do_not_snapshot_before_they_run(): void
    {
        DeckVersion::query()->delete();
        $this->ops([['op' => 'slide.set', 'id' => $this->slide->id, 'title' => 'Plain edit']])->assertOk();

        // only the usual after-the-batch automatic version, taken because there was none yet
        $this->assertSame(1, DeckVersion::count());
        $this->assertStringContainsString('Plain edit', DeckVersion::first()->snapshot);
    }

    public function test_a_spree_of_removals_does_not_fill_the_history(): void
    {
        for ($i = 0; $i < 6; $i++) {
            Slide::create(['deck_id' => $this->deck->id, 'title' => "S{$i}", 'sort_order' => $i + 1, 'els' => [], 'revision' => 0]);
        }
        DeckVersion::query()->delete();
        $ids = Slide::withoutGlobalScopes()->where('deck_id', $this->deck->id)->where('id', '!=', $this->slide->id)->pluck('id');
        foreach ($ids as $id) {
            $this->ops([['op' => 'slide.remove', 'id' => $id]])->assertOk();
        }

        $this->assertLessThanOrEqual(2, DeckVersion::count(), 'one snapshot per couple of minutes, not one per removal');
    }
}
