<?php

namespace Tests\Feature\Press;

use App\Models\Slide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpsLimitsAndSanitisingTest extends TestCase
{
    use MakesDecks;
    use RefreshDatabase;

    private function add(array $el): array
    {
        return ['op' => 'el.add', 'slide' => $this->slideId, 'el' => $el];
    }

    private int $slideId = 0;

    private function setUpDeck(): array
    {
        $user = $this->owner();
        $deck = $this->deckFor($user);
        $this->slideId = $deck->slides()->first()->id;

        return [$user, $deck, $deck->slides()->first()];
    }

    public function test_more_than_200_ops_or_none_are_rejected(): void
    {
        [$user, $deck] = $this->setUpDeck();

        $this->ops($user, $deck, array_fill(0, 201, ['op' => 'deck.set', 'title' => 'x']))->assertStatus(422);
        $this->ops($user, $deck, [])->assertStatus(422);
        $this->actingAs($user)->postJson('/api/decks/'.$deck->id.'/ops', ['ops' => [['op' => 'deck.set', 'title' => 'x']]])->assertStatus(422);
        $this->ops($user, $deck, array_fill(0, 200, ['op' => 'deck.set', 'title' => 'x']))->assertOk();
    }

    public function test_a_request_over_8_mb_is_refused(): void
    {
        [$user, $deck] = $this->setUpDeck();

        $this->ops($user, $deck, [['op' => 'deck.set', 'title' => str_repeat('x', 8 * 1024 * 1024 + 10)]])->assertStatus(413);
    }

    public function test_element_ids_must_match_the_pattern_and_be_unique_on_the_slide(): void
    {
        [$user, $deck, $slide] = $this->setUpDeck();

        $response = $this->ops($user, $deck, [
            $this->add($this->shape('has space')),
            $this->add($this->shape('<script>')),
            $this->add($this->shape(str_repeat('a', 41))),
            $this->add($this->shape('')),
            $this->add(['type' => 'shape', 'x' => 1]),
            $this->add($this->shape('good-id_1')),
            $this->add($this->shape('good-id_1')),
        ])->assertOk();

        $this->assertSame(['good-id_1'], array_column($this->els($slide), 'id'));
        $this->assertCount(6, $response->json('skipped'));
        $this->assertSame('duplicate element id', $response->json('skipped.5.reason'));
    }

    public function test_numbers_are_coerced_and_clamped_and_junk_is_refused(): void
    {
        [$user, $deck, $slide] = $this->setUpDeck();

        $response = $this->ops($user, $deck, [
            $this->add($this->shape('a', ['x' => '12.5', 'y' => 9e9, 'w' => -50, 'h' => '80', 'rot' => 99999, 'op' => 7, 'size' => 0])),
            $this->add($this->shape('b', ['x' => 'abc'])),
            $this->add($this->shape('c', ['x' => true])),
            $this->add($this->shape('d', ['x' => ['1']])),
        ])->assertOk();

        $a = $this->els($slide)[0];
        $this->assertEquals(12.5, $a['x']);
        $this->assertSame(100000, $a['y']);
        $this->assertSame(0, $a['w']);
        $this->assertSame(80, $a['h']);
        $this->assertSame(3600, $a['rot']);
        $this->assertSame(1, $a['op']);
        $this->assertCount(3, $response->json('skipped'));
        $this->assertCount(1, $this->els($slide));
    }

    public function test_colours_links_and_sources_must_be_safe(): void
    {
        [$user, $deck, $slide] = $this->setUpDeck();

        $response = $this->ops($user, $deck, [
            $this->add($this->shape('c1', ['fill' => 'red'])),
            $this->add($this->shape('c2', ['fill' => 'url(javascript:alert(1))'])),
            $this->add($this->shape('c3', ['fill' => '#ABC', 'stroke' => '@chart3'])),
            $this->add($this->shape('l1', ['link' => 'javascript:alert(1)'])),
            $this->add($this->shape('l2', ['link' => 'http://example.com'])),
            $this->add($this->shape('l3', ['link' => 'https://user:pw@example.com/'])),
            $this->add($this->shape('l4', ['link' => 'https://example.com/a b'])),
            $this->add($this->shape('l5', ['link' => 'https://example.com/deck', 'alt' => 'ok'])),
            $this->add($this->shape('l6', ['link' => '#slide:42'])),
            $this->add(['id' => 'i1', 'type' => 'image', 'x' => 0, 'y' => 0, 'w' => 5, 'h' => 5, 'src' => ['url' => 'data:image/png;base64,AAAA']]),
            $this->add(['id' => 'i2', 'type' => 'image', 'x' => 0, 'y' => 0, 'w' => 5, 'h' => 5, 'src' => ['url' => 'http://x.test/a.png']]),
            $this->add(['id' => 'i3', 'type' => 'image', 'x' => 0, 'y' => 0, 'w' => 5, 'h' => 5, 'src' => ['url' => 'https://x.test/a.png']]),
            $this->add(['id' => 'v1', 'type' => 'video', 'x' => 0, 'y' => 0, 'w' => 5, 'h' => 5]),
        ])->assertOk();

        $this->assertSame(['c3', 'l5', 'l6', 'i3'], array_column($this->els($slide), 'id'));
        $this->assertSame('#aabbcc', $this->els($slide)[0]['fill']);
        $this->assertCount(9, $response->json('skipped'));
    }

    public function test_unknown_keys_are_dropped_and_never_stored(): void
    {
        [$user, $deck, $slide] = $this->setUpDeck();

        $this->ops($user, $deck, [$this->add($this->shape('a', ['evil' => '<script>alert(1)</script>', 'onclick' => 'x()', 'src' => ['url' => 'https://x.test/a.png'], 'doc' => ['type' => 'doc']]))])->assertOk();
        $el = $this->els($slide)[0];
        $this->assertArrayNotHasKey('evil', $el);
        $this->assertArrayNotHasKey('onclick', $el);

        $this->ops($user, $deck, [['op' => 'el.set', 'slide' => $slide->id, 'id' => 'a', 'p' => ['nonsense' => 1, 'type' => 'text', 'id' => 'other', 'x' => 3]]])->assertOk();
        $el = $this->els($slide)[0];
        $this->assertSame('shape', $el['type']);
        $this->assertSame('a', $el['id']);
        $this->assertSame(3, $el['x']);
        $this->assertArrayNotHasKey('nonsense', $el);

        $this->ops($user, $deck, [['op' => 'el.set', 'slide' => $slide->id, 'id' => 'a', 'p' => ['only_unknown' => 1]]])
            ->assertJsonPath('skipped.0.reason', 'empty patch');
        $this->ops($user, $deck, [['op' => 'el.set', 'slide' => $slide->id, 'id' => 'a', 'p' => ['x' => null]]])
            ->assertJsonPath('skipped.0.reason', 'x cannot be removed');
    }

    public function test_text_documents_are_pruned_to_the_allowed_nodes_and_marks(): void
    {
        [$user, $deck, $slide] = $this->setUpDeck();

        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'Title']]],
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Hi ', 'marks' => [['type' => 'bold'], ['type' => 'bold'], ['type' => 'code'], ['type' => 'highlight']]],
                ['type' => 'text', 'text' => 'bad', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]],
                ['type' => 'text', 'text' => 'good', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com', 'target' => '_blank', 'onclick' => 'x']]]],
                ['type' => 'text', 'text' => 'big', 'marks' => [['type' => 'textStyle', 'attrs' => ['color' => '#f00', 'fontSize' => '24px', 'fontFamily' => 'Arial; x', 'background' => 'red']]]],
                ['type' => 'hardBreak'],
                ['type' => 'image', 'attrs' => ['src' => 'javascript:1']],
                ['type' => 'text', 'text' => ''],
            ]],
            ['type' => 'script', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'kept text']]]]],
            ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'one']]]]], ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'two']]]]],
        ]];

        $this->ops($user, $deck, [$this->add(['id' => 't', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 300, 'h' => 100, 'doc' => $doc])])->assertOk()->assertJsonPath('skipped', []);

        $stored = $this->els($slide)[0]['doc'];
        $json = json_encode($stored);
        $this->assertStringNotContainsString('javascript', $json);
        $this->assertStringNotContainsString('highlight', $json);
        $this->assertStringNotContainsString('onclick', $json);
        $this->assertStringNotContainsString('image', $json);

        $this->assertSame('paragraph', $stored['content'][0]['type']);
        $this->assertSame('Title', $stored['content'][0]['content'][0]['text']);
        $inline = $stored['content'][1]['content'];
        $this->assertSame([['type' => 'bold']], $inline[0]['marks']);
        $this->assertArrayNotHasKey('marks', $inline[1]);
        $this->assertSame(['type' => 'link', 'attrs' => ['href' => 'https://example.com']], $inline[2]['marks'][0]);
        $this->assertSame(['color' => '#ff0000', 'fontSize' => 24], $inline[3]['marks'][0]['attrs']);
        $this->assertSame('hardBreak', $inline[4]['type']);
        $this->assertCount(5, $inline);
        $this->assertSame('kept text', $stored['content'][2]['content'][0]['text']);
        $this->assertCount(2, $stored['content'][3]['content']);
        $this->assertSame('listItem', $stored['content'][3]['content'][1]['type']);
    }

    public function test_oversized_text_and_elements_are_refused(): void
    {
        [$user, $deck, $slide] = $this->setUpDeck();

        $huge = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => str_repeat('a', 100001)]]]]];
        $many = ['type' => 'doc', 'content' => array_fill(0, 5001, ['type' => 'paragraph'])];

        $response = $this->ops($user, $deck, [
            $this->add(['id' => 't1', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'doc' => $huge]),
            $this->add(['id' => 't2', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'doc' => $many]),
            $this->add(['id' => 't3', 'type' => 'table', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'rows' => [[['t' => str_repeat('b', 600)]]]]),
        ])->assertOk();

        $this->assertSame('text too large', $response->json('skipped.0.reason'));
        $this->assertSame('text too large', $response->json('skipped.1.reason'));
        $this->assertCount(1, $this->els($slide));
    }

    public function test_a_slide_holds_at_most_400_elements_and_2_mb(): void
    {
        [$user, $deck, $slide] = $this->setUpDeck();

        $els = [];
        for ($i = 0; $i < 400; $i++) {
            $els[] = $this->shape("e{$i}");
        }
        $slide->update(['els' => $els]);

        $this->ops($user, $deck, [$this->add($this->shape('one-too-many'))])->assertJsonPath('skipped.0.reason', 'slide is full');
        $this->assertCount(400, $this->els($slide));

        $this->ops($user, $deck, [['op' => 'slide.add', 'tid' => 't', 'slide' => ['els' => [...$els, $this->shape('over')]]]])
            ->assertJsonPath('skipped.0.reason', 'too many elements on one slide');

        // Four 480 KB tables fit under 2 MB only three times.
        $slide->update(['els' => []]);
        $table = fn (string $id) => ['id' => $id, 'type' => 'table', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100,
            'rows' => array_fill(0, 100, array_map(fn () => ['t' => str_repeat('z', 960)], range(1, 5)))];
        $response = $this->ops($user, $deck, array_map(fn ($i) => $this->add($table("t{$i}")), range(1, 5)))->assertOk();
        $this->assertGreaterThan(0, count($response->json('skipped')));
        $this->assertLessThanOrEqual(2 * 1024 * 1024, strlen(json_encode($this->els($slide))));
    }

    public function test_a_deck_holds_at_most_600_slides(): void
    {
        [$user, $deck] = $this->setUpDeck();
        $rows = [];
        for ($i = 1; $i < 600; $i++) {
            $rows[] = ['deck_id' => $deck->id, 'title' => "s{$i}", 'layout' => 'blank', 'sort_order' => $i, 'revision' => 0, 'hidden' => 0, 'els' => '[]'];
        }
        Slide::insert($rows);

        $this->ops($user, $deck, [['op' => 'slide.add', 'tid' => 'x', 'slide' => []]])->assertJsonPath('skipped.0.reason', 'deck is full');
        $this->assertSame(600, $deck->slides()->count());
    }

    public function test_charts_and_tables_are_normalised(): void
    {
        [$user, $deck, $slide] = $this->setUpDeck();

        $this->ops($user, $deck, [
            $this->add(['id' => 'ch', 'type' => 'chart', 'x' => 0, 'y' => 0, 'w' => 400, 'h' => 300, 'chart' => [
                'type' => 'bar', 'extra' => 1,
                'data' => ['categories' => ['A', 'B'], 'series' => [['name' => 'S', 'values' => [1, '2', null], 'junk' => 1]]],
                'opt' => ['legend' => 'bottom', 'stacked' => 'true', 'hax' => 1],
            ]]),
            $this->add(['id' => 'tb', 'type' => 'table', 'x' => 0, 'y' => 0, 'w' => 400, 'h' => 300, 'header' => true,
                'rows' => [[['t' => 'a', 'b' => true], 'b'], [['t' => 'c']]], 'colw' => [3, 1]]),
            $this->add(['id' => 'bad', 'type' => 'chart', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'chart' => ['type' => 'nope']]),
        ])->assertOk()->assertJsonCount(1, 'skipped');

        [$chart, $table] = $this->els($slide);
        $this->assertSame([1, 2, null], $chart['chart']['data']['series'][0]['values']);
        $this->assertTrue($chart['chart']['opt']['stacked']);
        $this->assertArrayNotHasKey('hax', $chart['chart']['opt']);
        $this->assertArrayNotHasKey('junk', $chart['chart']['data']['series'][0]);
        $this->assertCount(2, $table['rows'][1]);
        $this->assertSame([0.75, 0.25], $table['colw']);
    }
}
