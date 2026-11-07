<?php

namespace Tests\Feature\Press;

use App\Models\Deck;
use App\Models\DeckChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiPressTest extends TestCase
{
    use MakesDecks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ai.provider', 'mock');
        config()->set('ai.limits.daily_quota', 50);
    }

    public function test_generated_slides_use_the_new_element_shape_sized_from_the_deck(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user, 'Portrait', 1);
        $deck->update(['w' => 1080, 'h' => 1920]);

        $response = $this->actingAs($user)->postJson("/api/ai/decks/{$deck->id}/generate-slide", ['prompt' => "Launch plan\nWhy now\nWhat we ship\nWho owns it"])->assertCreated();

        $slide = $response->json('slide');
        $this->assertSame('bullets', $slide['layout']);
        $this->assertSame('Launch plan', $slide['title']);
        $this->assertNotEmpty($slide['notes']);
        $this->assertCount(2, $slide['els']);
        foreach ($slide['els'] as $el) {
            $this->assertSame('text', $el['type']);
            $this->assertSame('ai', $el['origin']);
            $this->assertSame('doc', $el['doc']['type']);
            $this->assertArrayNotHasKey('richContent', $el);
            $this->assertLessThanOrEqual(1080, $el['x'] + $el['w']);
            $this->assertLessThanOrEqual(1920, $el['y'] + $el['h']);
        }
        $this->assertSame('bulletList', $slide['els'][1]['doc']['content'][0]['type']);
        $this->assertCount(3, $slide['els'][1]['doc']['content'][0]['content']);

        // It sits last in the deck and went through the operations log.
        $this->assertSame($slide['id'], $deck->slides()->get()->last()->id);
        $change = DeckChange::where('deck_id', $deck->id)->first();
        $this->assertSame('ai', $change->client);
        $this->assertSame('slide.add', $change->ops[0]['op']);
        $this->assertSame($response->json('seq'), $change->id);
    }

    public function test_a_single_line_prompt_makes_a_title_slide(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user);

        $slide = $this->actingAs($user)->postJson("/api/ai/decks/{$deck->id}/generate-slide", ['prompt' => 'Roadmap'])->assertCreated()->json('slide');

        $this->assertSame('title', $slide['layout']);
        $this->assertSame('title', $slide['els'][0]['role']);
    }

    public function test_a_generated_deck_appends_title_and_body_slides_in_order(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user, 'D', 1);
        $existing = $deck->slides()->first();

        $response = $this->actingAs($user)->postJson("/api/ai/decks/{$deck->id}/generate-deck", ['prompt' => "Pitch\nProblem\nSolution", 'slide_count' => 3])->assertCreated();

        $this->assertSame(['Pitch', 'Problem', 'Solution'], array_column($response->json('slides'), 'title'));
        $this->assertSame('title', $response->json('slides.0.layout'));
        $ids = array_column($response->json('slides'), 'id');
        $this->assertSame([$existing->id, ...$ids], $deck->slides()->pluck('id')->all());
        $this->assertSame(1, DeckChange::where('deck_id', $deck->id)->count());
    }

    public function test_rewrite_proposes_a_change_for_a_text_element_and_applies_nothing(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user);
        $doc = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'We should improve delivery speed while reducing support tickets and keep everyone informed along the way, every single week of the quarter, without exception or delay at all, ever, in any team.']]]]];
        $slide = $this->slideFor($deck, 'S', 1, [['id' => 't1', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 500, 'h' => 100, 'doc' => $doc], $this->shape('r')]);

        $response = $this->actingAs($user)->postJson("/api/ai/slides/{$slide->id}/rewrite-text", ['element' => 't1', 'mode' => 'expand'])->assertOk();

        $this->assertNotEmpty($response->json('text'));
        $this->assertSame($slide->id, $response->json('proposal.slide'));
        $this->assertSame('t1', $response->json('proposal.element'));
        $op = $response->json('proposal.op');
        $this->assertSame(['op' => 'el.set', 'slide' => $slide->id, 'id' => 't1', 'p' => ['doc' => $response->json('proposal.doc')]], $op);

        // Nothing was applied; the client sends the op if the person accepts, and the server takes it as is.
        $this->assertSame($doc, $this->els($slide)[0]['doc']);
        $this->assertSame(0, DeckChange::count());
        $this->ops($user, $deck, [$op])->assertOk()->assertJsonPath('skipped', []);
        $this->assertNotSame($doc, $this->els($slide)[0]['doc']);
    }

    public function test_rewrite_of_a_selection_returns_text_only_and_bad_elements_are_refused(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user);
        $slide = $this->slideFor($deck, 'S', 1, [$this->shape('r')]);

        // A shape without text is not a rewrite target.
        $this->actingAs($user)->postJson("/api/ai/slides/{$slide->id}/rewrite-text", ['text' => 'Short bit', 'element' => 'r', 'mode' => 'rephrase'])->assertStatus(422);

        $this->actingAs($user)->postJson("/api/ai/slides/{$slide->id}/rewrite-text", ['element' => 'ghost', 'mode' => 'shorten'])->assertStatus(422);
        $this->actingAs($user)->postJson("/api/ai/slides/{$slide->id}/rewrite-text", ['mode' => 'shorten'])->assertStatus(422);

        $textSlide = $this->slideFor($deck, 'T', 2, [['id' => 't', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 5, 'h' => 5, 'doc' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]]]]);
        $ok = $this->actingAs($user)->postJson("/api/ai/slides/{$textSlide->id}/rewrite-text", ['text' => 'Short bit', 'element' => 't', 'mode' => 'rephrase'])->assertOk();
        $this->assertSame('In other words: Short bit', $ok->json('text'));
        $this->assertNull($ok->json('proposal.doc'));
        $this->assertSame('In other words: Short bit', $ok->json('proposal.text'));
    }

    public function test_a_viewer_cannot_generate_or_rewrite(): void
    {
        $owner = $this->owner();
        $viewer = $this->teammate($owner, 'viewer');
        $deck = $this->deckFor($owner);
        $slide = $deck->slides()->first();

        $this->actingAs($viewer)->postJson("/api/ai/decks/{$deck->id}/generate-slide", ['prompt' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->postJson("/api/ai/slides/{$slide->id}/rewrite-text", ['text' => 'x', 'mode' => 'shorten'])->assertForbidden();
        $this->assertSame(1, Deck::find($deck->id)->slides()->count());
    }
}
