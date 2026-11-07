<?php

namespace Tests\Feature\Press;

use App\Models\DeckActivity;
use App\Models\DeckChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResizeOpTest extends TestCase
{
    use MakesDecks;
    use RefreshDatabase;

    public function test_deck_resize_transforms_every_slide_and_tells_clients_to_reload(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user, 'D', 2);
        [$one, $two] = $deck->slides()->get()->all();
        $this->ops($user, $deck, [
            ['op' => 'el.add', 'slide' => $one->id, 'el' => $this->shape('a', ['x' => 960, 'y' => 540, 'w' => 480, 'h' => 270, 'size' => 40])],
            ['op' => 'el.add', 'slide' => $two->id, 'el' => $this->shape('b', ['x' => 0, 'y' => 0, 'w' => 1920, 'h' => 1080])],
        ]);

        $response = $this->ops($user, $deck, [['op' => 'deck.resize', 'w' => 960, 'h' => 540, 'mode' => 'scale']])->assertOk();

        $fresh = $deck->fresh();
        $this->assertSame([960, 540], [$fresh->w, $fresh->h]);
        $a = $this->els($one)[0];
        $this->assertSame([480, 270, 240, 135, 20], [$a['x'], $a['y'], $a['w'], $a['h'], $a['size']]);
        $b = $this->els($two)[0];
        $this->assertSame([0, 0, 960, 540], [$b['x'], $b['y'], $b['w'], $b['h']]);
        $this->assertArrayHasKey($one->id, $response->json('slides'));
        $this->assertArrayHasKey($two->id, $response->json('slides'));

        $change = DeckChange::orderByDesc('id')->first();
        $this->assertSame('deck.resize', $change->ops[0]['op']);
        $this->assertSame('deck.reload', $change->ops[1]['op']);
        $this->assertTrue(DeckActivity::where('deck_id', $deck->id)->where('event', 'resized')->exists());
    }

    public function test_resize_modes_and_limits(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user);
        $slide = $deck->slides()->first();
        $this->ops($user, $deck, [['op' => 'el.add', 'slide' => $slide->id, 'el' => $this->shape('a', ['x' => 0, 'y' => 0, 'w' => 100, 'h' => 100])]]);

        $this->ops($user, $deck, [['op' => 'deck.resize', 'w' => 1080, 'h' => 1920, 'mode' => 'fit']])->assertOk()->assertJsonPath('skipped', []);
        $fit = $this->els($slide)[0];
        $this->assertEqualsWithDelta(56.25, $fit['w'], 0.01);
        $this->assertEqualsWithDelta(0, $fit['x'], 0.01);
        $this->assertEqualsWithDelta(656.25, $fit['y'], 0.01);

        $this->ops($user, $deck, [['op' => 'deck.resize', 'w' => 1080, 'h' => 1920, 'mode' => 'anchor']])
            ->assertJsonPath('skipped.0.reason', 'size unchanged');
        $this->ops($user, $deck, [['op' => 'deck.resize', 'w' => 100, 'h' => 1920, 'mode' => 'anchor']])
            ->assertJsonPath('skipped.0.reason', 'invalid w');
        $this->ops($user, $deck, [['op' => 'deck.resize', 'w' => 1920, 'h' => 1080, 'mode' => 'stretch']])
            ->assertJsonPath('skipped.0.reason', 'invalid mode');

        $this->ops($user, $deck, [['op' => 'deck.resize', 'w' => 1920, 'h' => 1080, 'mode' => 'anchor']])->assertOk()->assertJsonPath('skipped', []);
        $anchored = $this->els($slide)[0];
        $this->assertEqualsWithDelta(56.25, $anchored['w'], 0.01);
        $this->assertSame([1920, 1080], [$deck->fresh()->w, $deck->fresh()->h]);
    }
}
