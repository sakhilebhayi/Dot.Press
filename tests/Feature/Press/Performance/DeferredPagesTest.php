<?php

namespace Tests\Feature\Press\Performance;

use App\Models\PresentSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Press\MakesDecks;
use Tests\TestCase;

/**
 * A deck too big to write into a page (press_perf.inline_bytes) is sent as a small page that names the real page and a
 * streaming URL. Small decks are untouched.
 */
class DeferredPagesTest extends TestCase
{
    use MakesDecks;
    use RefreshDatabase;

    private function deckWithWeight($user, int $slides = 3)
    {
        $deck = $this->deckFor($user, 'Deck', $slides);
        foreach ($deck->slides as $slide) {
            DB::table('slides')->where('id', $slide->id)->update(['els' => json_encode([$this->shape('s'.$slide->id, ['name' => str_repeat('n', 200)])]), 'notes' => 'Notes '.$slide->id]);
        }

        return $deck->fresh();
    }

    public function test_a_small_deck_still_carries_its_document_in_the_page(): void
    {
        $user = $this->owner();
        $deck = $this->deckWithWeight($user);

        $this->actingAs($user)->get("/decks/{$deck->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('Press/Editor')->has('document.slides', 3));
        $this->actingAs($user)->get("/decks/{$deck->id}/present")->assertOk()->assertInertia(fn (Assert $page) => $page->component('Press/Player')->has('slides', 3));
        $this->actingAs($user)->get("/decks/{$deck->id}/print")->assertOk()->assertInertia(fn (Assert $page) => $page->component('Press/Print')->has('slides', 3));
    }

    public function test_a_big_deck_is_fetched_by_the_editor_from_the_streaming_document(): void
    {
        config(['press_perf.inline_bytes' => 100]);
        $user = $this->owner();
        $deck = $this->deckWithWeight($user);

        $this->actingAs($user)->get("/decks/{$deck->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Press/Deferred')
            ->where('target', 'Editor')
            ->where('payloadKey', 'document')
            ->where('payloadUrl', "/api/decks/{$deck->id}/document")
            ->where('targetProps.me.id', $user->id)
            ->where('targetProps.can.update', true)
            ->missing('targetProps.document'));
    }

    public function test_the_player_pages_and_print_fetch_a_big_decks_slides_from_one_payload_route(): void
    {
        config(['press_perf.inline_bytes' => 100]);
        $user = $this->owner();
        $deck = $this->deckWithWeight($user, 4);
        $second = $deck->slides()->orderBy('sort_order')->skip(1)->first();

        foreach (['present' => 'Player', 'presenter' => 'Presenter', 'audience' => 'Audience'] as $path => $target) {
            $this->actingAs($user)->get("/decks/{$deck->id}/{$path}?slide={$second->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Press/Deferred')
                ->where('target', $target)
                ->where('targetProps.startIndex', 1)
                ->where('targetProps.deck.id', $deck->id)
                ->where('payloadKey', null)
                ->where('payloadUrl', fn ($url) => str_starts_with($url, "/decks/{$deck->id}/slides-payload?kind=")));
        }
        $this->actingAs($user)->get("/decks/{$deck->id}/print?notes=1")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Press/Deferred')->where('target', 'Print')->where('targetProps.options.notes', true)->where('payloadUrl', fn ($url) => str_contains($url, 'kind=print') && str_contains($url, 'notes=1')));
    }

    public function test_the_payload_route_streams_slides_and_assets_in_the_shape_the_pages_read(): void
    {
        $user = $this->owner();
        $deck = $this->deckWithWeight($user, 3);
        $first = $deck->slides()->orderBy('sort_order')->first();
        DB::table('slides')->where('id', $first->id)->update(['hidden' => 1]);

        $player = $this->actingAs($user)->get("/decks/{$deck->id}/slides-payload?kind=player")->assertOk();
        $body = json_decode($player->streamedContent(), true);
        $this->assertSame(['slides', 'assets'], array_keys($body));
        $this->assertCount(3, $body['slides']);
        $this->assertSame('Notes '.$first->id, $body['slides'][0]['notes'], 'the presenter gets speaker notes');
        $this->assertSame([], (array) $body['assets']);

        $audience = json_decode($this->actingAs($user)->get("/decks/{$deck->id}/slides-payload?kind=audience")->streamedContent(), true);
        $this->assertSame('', $audience['slides'][0]['notes'], 'the audience never does');

        $print = json_decode($this->actingAs($user)->get("/decks/{$deck->id}/slides-payload?kind=print")->streamedContent(), true);
        $this->assertCount(2, $print['slides'], 'print leaves hidden slides out unless asked');
        $printAll = json_decode($this->actingAs($user)->get("/decks/{$deck->id}/slides-payload?kind=print&hidden=1")->streamedContent(), true);
        $this->assertCount(3, $printAll['slides']);

        $this->actingAs($user)->get("/decks/{$deck->id}/slides-payload?kind=other")->assertStatus(422);
    }

    public function test_the_payload_route_is_not_open_to_strangers(): void
    {
        $owner = $this->owner();
        $deck = $this->deckWithWeight($owner);
        $stranger = $this->owner();

        $this->actingAs($stranger)->get("/decks/{$deck->id}/slides-payload?kind=player")->assertNotFound();
    }

    public function test_the_payload_route_needs_a_sign_in(): void
    {
        $deck = $this->deckWithWeight($this->owner());

        $this->get("/decks/{$deck->id}/slides-payload?kind=player")->assertRedirect('/login');
    }

    public function test_a_big_shared_deck_is_fetched_from_the_document_route_and_the_live_follow_link_from_its_payload_route(): void
    {
        config(['press_perf.inline_bytes' => 100]);
        $owner = $this->owner();
        $deck = $this->deckWithWeight($owner);
        $token = Str::random(40);
        $deck->forceFill(['visibility' => 'link', 'share_token' => $token])->save();

        $this->get("/s/{$token}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Press/Deferred')->where('target', 'Shared')->where('payloadKey', 'doc')->where('payloadUrl', "/s/{$token}/document")->where('targetProps.token', $token));
        $doc = json_decode($this->get("/s/{$token}/document")->streamedContent(), true);
        $this->assertNull($doc['slides'][0]['notes']);

        $live = Str::random(40);
        PresentSession::create(['deck_id' => $deck->id, 'user_id' => $owner->id, 'token' => $live, 'slide_index' => 0, 'step' => 0, 'last_seen' => now()]);
        $this->get("/live/{$live}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Press/Deferred')->where('target', 'Audience')->where('targetProps.follow.token', $live));
        $payload = json_decode($this->get("/live/{$live}/payload")->streamedContent(), true);
        $this->assertCount(3, $payload['slides']);
        $this->assertSame('', $payload['slides'][0]['notes']);
    }
}
