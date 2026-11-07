<?php

namespace Tests\Feature\Press\Performance;

use App\Models\Deck;
use App\Services\Press\DeckDocument;
use App\Services\Press\DeckHash;
use App\Services\Press\DocumentAssets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Press\MakesDecks;
use Tests\Support\BuildsPressDecks;
use Tests\Support\PressSampleDeck;
use Tests\TestCase;

class StreamedDocumentTest extends TestCase
{
    use BuildsPressDecks;
    use MakesDecks;
    use RefreshDatabase;

    public function test_the_streamed_document_is_the_same_document_as_the_array_one(): void
    {
        $user = $this->pressUser();
        $deck = $this->pressDeck($user);

        $expected = DeckDocument::toArray($deck->fresh());
        $expected['assets'] = (object) app(DocumentAssets::class)->forDocument($expected);

        $streamed = $this->actingAs($user)->getJson("/api/decks/{$deck->id}/document")->assertOk();
        $this->assertStringContainsString('application/json', (string) $streamed->headers->get('Content-Type'));
        $actual = json_decode($streamed->streamedContent(), true);

        $this->assertSame(array_keys($expected), array_keys($actual), 'same top level keys in the same order');
        $this->assertEquals(json_decode(json_encode($expected), true), $actual);
        $this->assertNotEmpty($actual['assets'], 'the pictures of the sample deck are listed with their signed urls');
    }

    public function test_the_stream_is_gzipped_for_a_browser_that_accepts_it_and_plain_otherwise(): void
    {
        $user = $this->pressUser();
        $deck = $this->pressDeck($user);

        $plain = $this->actingAs($user)->getJson("/api/decks/{$deck->id}/document")->streamedContent();
        $gz = $this->actingAs($user)->withHeaders(['Accept-Encoding' => 'gzip, deflate'])->getJson("/api/decks/{$deck->id}/document");

        $this->assertSame('gzip', $gz->headers->get('Content-Encoding'));
        $this->assertSame($plain, gzdecode($gz->streamedContent()));
        $this->assertNull($this->withoutHeader('Accept-Encoding')->actingAs($user)->getJson("/api/decks/{$deck->id}/document")->headers->get('Content-Encoding'));
    }

    public function test_stored_json_is_passed_through_untouched_and_a_damaged_column_cannot_break_the_answer(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user, 'Deck', 2);
        [$a, $b] = $deck->slides()->orderBy('sort_order')->get()->all();
        // An empty object stays an object (decoding it into an array turned it into []), and a damaged value reads as empty.
        DB::table('slides')->where('id', $a->id)->update(['els' => '[{"id":"e","type":"shape","kind":"rect","x":1,"y":2,"w":3,"h":4,"adj":{}}]', 'bg' => '{"g":"linear","a":90,"stops":[[0,"#000"],[1,"#fff"]]}']);
        DB::table('slides')->where('id', $b->id)->update(['els' => '[{"id":"broken"', 'bg' => 'nope', 'transition' => null]);

        $body = $this->actingAs($user)->getJson("/api/decks/{$deck->id}/document")->streamedContent();

        $this->assertStringContainsString('"adj":{}', $body);
        $doc = json_decode($body, true);
        $this->assertSame([], $doc['slides'][1]['els']);
        $this->assertNull($doc['slides'][1]['bg']);
        $this->assertSame('linear', $doc['slides'][0]['bg']['g']);
    }

    public function test_a_hidden_slide_can_be_sent_blank_and_notes_removed(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user, 'Deck', 2);
        $slide = $deck->slides()->orderBy('sort_order')->first();
        DB::table('slides')->where('id', $slide->id)->update(['hidden' => 1, 'title' => 'Secret', 'notes' => 'Private', 'els' => '[{"id":"s","type":"shape","kind":"rect","x":0,"y":0,"w":1,"h":1,"src":{"asset":77}}]']);

        $doc = json_decode(DeckDocument::json($deck->fresh(), fn (array $ids) => array_fill_keys($ids, 'listed'), ['notes' => false, 'blank_hidden' => true, 'seq' => 0, 'deck_keys' => ['id', 'title']]), true);

        $this->assertSame(['id', 'title'], array_keys($doc['deck']));
        $this->assertNull($doc['slides'][0]['title']);
        $this->assertNull($doc['slides'][0]['notes']);
        $this->assertSame([], $doc['slides'][0]['els']);
        $this->assertSame(0, $doc['seq']);
        $this->assertSame([], (array) $doc['assets'], 'a blanked slide lists none of its pictures');
    }

    public function test_the_deck_hash_is_what_it_always_was(): void
    {
        $user = $this->pressUser();
        $deck = $this->pressDeck($user);

        $this->assertSame($this->referenceHash($deck), DeckHash::compute($deck));
        $this->assertSame($this->referenceHash($deck), DeckHash::compute($deck), 'stable');

        $bare = $this->deckFor($this->owner(), 'Plain', 3);
        $this->assertSame($this->referenceHash($bare), DeckHash::compute($bare));
    }

    public function test_the_current_hash_is_kept_until_the_deck_changes(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user, 'Deck', 2);

        $first = DeckHash::current($deck);
        $this->assertSame($first, Deck::find($deck->id)->content_hash);

        // Remembered: with the stored value altered, the same rev reads the stored value back.
        DB::table('decks')->where('id', $deck->id)->update(['content_hash' => str_repeat('c', 64)]);
        $this->assertSame(str_repeat('c', 64), DeckHash::current(Deck::find($deck->id)));

        // Any change through the operations API bumps rev and the value is worked out again.
        $this->ops($user, $deck, [['op' => 'deck.set', 'title' => 'New title']])->assertOk();
        $after = DeckHash::current(Deck::find($deck->id));
        $this->assertNotSame(str_repeat('c', 64), $after);
        $this->assertSame(DeckHash::compute(Deck::find($deck->id)), $after);

        // A slide written directly (no rev bump) forgets it too.
        $slide = $deck->slides()->first();
        $slide->forceFill(['title' => 'Edited behind the back'])->save();
        $this->assertNull(DB::table('decks')->where('id', $deck->id)->value('hash_rev'));
        $this->assertNotSame($after, DeckHash::current(Deck::find($deck->id)));
    }

    /** The first implementation, kept here as the definition: the whole deck as arrays, one canonical JSON, one hash. */
    private function referenceHash(Deck $deck): string
    {
        $canonical = function (mixed $value) use (&$canonical) {
            if (! is_array($value)) {
                return $value;
            }
            $value = array_map($canonical, $value);
            if (! array_is_list($value)) {
                ksort($value);
            }

            return $value;
        };
        $slides = $deck->slides()->orderBy('sort_order')->orderBy('id')->get()->map(fn ($slide) => [
            'title' => (string) $slide->title, 'notes' => (string) $slide->notes, 'hidden' => (bool) $slide->hidden, 'section' => $slide->section_id,
            'layout' => $slide->layout, 'bg' => $slide->bg, 'transition' => $slide->transition, 'els' => $slide->els ?? [],
        ])->all();

        return hash('sha256', json_encode($canonical([
            'title' => $deck->title, 'w' => (int) $deck->w, 'h' => (int) $deck->h, 'theme' => $deck->theme, 'sections' => $deck->sections ?? [], 'slides' => $slides,
        ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    public function test_the_sample_document_has_every_element_type(): void
    {
        // The equality test above is only as good as its data: the sample deck holds text, shapes, images, charts, tables and video.
        $types = collect(PressSampleDeck::doc()['slides'])->flatMap(fn ($s) => array_column($s['els'], 'type'))->unique()->sort()->values()->all();
        $this->assertContains('chart', $types);
        $this->assertContains('table', $types);
        $this->assertContains('image', $types);
    }
}
