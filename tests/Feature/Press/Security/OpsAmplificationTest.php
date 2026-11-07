<?php

namespace Tests\Feature\Press\Security;

use App\Models\Slide;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Press\AccessTestCase;

/**
 * A small request must not be able to write gigabytes: 200 duplications of one fat slide in a single batch used to
 * create 200 copies of it (738 MB of memory, 360 MB of rows). Growth is budgeted per request and per deck.
 */
class OpsAmplificationTest extends AccessTestCase
{
    /** @return list<array<string, mixed>> about $kb kilobytes of elements */
    private function fatElements(int $kb): array
    {
        $text = str_repeat('word ', 20000); // about 100 KB once stored
        $els = [];
        for ($i = 0; $i < max(1, intdiv($kb, 100)); $i++) {
            $els[] = ['id' => "t{$i}", 'type' => 'text', 'x' => 1, 'y' => 1, 'w' => 10, 'h' => 10, 'doc' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]]];
        }

        return $els;
    }

    private function ops(array $ops, string $key)
    {
        return $this->actingAs($this->editor)->postJson("/api/decks/{$this->deck->id}/ops", ['client' => 't', 'key' => $key, 'ops' => $ops]);
    }

    public function test_duplicating_a_fat_slide_many_times_in_one_batch_stops_at_the_budget(): void
    {
        config(['security.press_batch_growth_bytes' => 1024 * 1024]);
        $big = $this->ops([['op' => 'slide.add', 'tid' => 'big', 'slide' => ['els' => $this->fatElements(300)]]], 'k0')->assertOk()->json('ids.big');

        $ops = [];
        for ($i = 0; $i < 60; $i++) {
            $ops[] = ['op' => 'slide.dup', 'id' => $big, 'tid' => "d{$i}"];
        }
        $response = $this->ops($ops, 'k1')->assertOk();

        $made = count($response->json('ids'));
        $this->assertGreaterThan(0, $made);
        $this->assertLessThan(10, $made, 'a 1 MB budget allows only a few 300 KB copies');
        $skipped = collect($response->json('skipped'));
        $this->assertSame(60 - $made, $skipped->count());
        $this->assertSame('batch too large', $skipped->first()['reason']);
        $this->assertSame(1 + 1 + $made, Slide::withoutGlobalScopes()->where('deck_id', $this->deck->id)->count());
    }

    public function test_one_deck_cannot_grow_past_its_size_cap(): void
    {
        config(['security.press_deck_bytes' => 800 * 1024]);
        $this->ops([['op' => 'slide.add', 'tid' => 'a', 'slide' => ['els' => $this->fatElements(300)]]], 'k0')->assertOk()->assertJsonPath('skipped', []);
        $this->ops([['op' => 'slide.add', 'tid' => 'b', 'slide' => ['els' => $this->fatElements(300)]]], 'k1')->assertOk()->assertJsonPath('skipped', []);

        $third = $this->ops([['op' => 'slide.add', 'tid' => 'c', 'slide' => ['els' => $this->fatElements(300)]]], 'k2')->assertOk();
        $this->assertSame('deck too large', $third->json('skipped.0.reason'));
        $this->assertLessThan(900 * 1024, (int) DB::table('slides')->where('deck_id', $this->deck->id)->sum(DB::raw('length(els)')));

        // Shrinking is always allowed: remove a slide and edit small things.
        $a = $this->ops([['op' => 'slide.remove', 'id' => (int) Slide::withoutGlobalScopes()->where('deck_id', $this->deck->id)->orderByDesc('id')->value('id')]], 'k3')->assertOk();
        $this->assertSame([], $a->json('skipped'));
        $this->ops([['op' => 'deck.set', 'title' => 'Still editable']], 'k4')->assertOk()->assertJsonPath('skipped', []);
    }

    public function test_normal_editing_is_untouched_by_the_budgets(): void
    {
        $ops = [];
        for ($i = 0; $i < 150; $i++) {
            $ops[] = ['op' => 'el.add', 'slide' => $this->slide->id, 'el' => ['id' => "e{$i}", 'type' => 'shape', 'kind' => 'rect', 'x' => $i, 'y' => 1, 'w' => 10, 'h' => 10]];
        }
        $ops[] = ['op' => 'slide.add', 'tid' => 'n', 'slide' => ['title' => 'Fine']];
        $this->ops($ops, 'k0')->assertOk()->assertJsonPath('skipped', []);
    }
}
