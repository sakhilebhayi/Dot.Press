<?php

namespace Tests\Feature\Press;

use App\Services\Press\Export\Pptx\PptxExporter;
use App\Services\Press\Export\Support\TextDoc;
use App\Services\Press\Import\ImportResult;
use App\Services\Press\Import\PptxImporter;
use Tests\Support\PressSampleDeck;
use Tests\TestCase;

/** Spec document to PPTX and back: geometry, text and colours must survive within tolerance. */
class ExportRoundTripTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = tempnam(sys_get_temp_dir(), 'rt').'.pptx';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    private function roundTrip(?array $doc = null): ImportResult
    {
        (new PptxExporter(PressSampleDeck::assets()))->render($doc ?? PressSampleDeck::doc(), $this->file);

        return PptxImporter::import($this->file);
    }

    /** @return array<string, array<string, mixed>> elements by their original id (the importer numbers them in order) */
    private function byOrder(ImportResult $r, int $slide): array
    {
        return $r->slides[$slide]['els'];
    }

    public function test_deck_properties_survive(): void
    {
        $r = $this->roundTrip();

        $this->assertSame(1920, $r->deck['w']);
        $this->assertSame(1080, $r->deck['h']);
        $this->assertSame('Sample deck', $r->deck['title']);
        $this->assertSame([['id' => 'sec1', 'title' => 'Opening'], ['id' => 'sec2', 'title' => 'Detail']], $r->deck['sections']);
        $this->assertSame(['sec1', 'sec1', 'sec2', 'sec2', 'sec2'], array_column($r->slides, 'section_id'));
        $this->assertSame([false, false, false, false, true], array_column($r->slides, 'hidden'));
        $this->assertSame("Open warm.\nName the three asks.", $r->slides[0]['notes']);
        $this->assertSame(['fade', 'push', 'wipe'], array_column(array_slice(array_column($r->slides, 'transition'), 0, 3), 'type'));
        $this->assertSame([600, 500, 700], array_column(array_slice(array_column($r->slides, 'transition'), 0, 3), 'dur'));
        $this->assertSame('#2563eb', $r->deck['theme']['c']['accent']);
        $this->assertSame('Public Sans', $r->deck['theme']['t']['heading']);
    }

    public function test_text_boxes_keep_geometry_text_size_weight_and_colour(): void
    {
        $r = $this->roundTrip();
        [$title, $subtitle, $bar] = $this->byOrder($r, 0);

        $this->assertSame('text', $title['type']);
        $this->assertSame('Quarterly review, Q3', TextDoc::plain($title['doc']));
        foreach (['x' => 120, 'y' => 300, 'w' => 1500, 'h' => 240] as $k => $v) {
            $this->assertEqualsWithDelta($v, $title[$k], 0.6, $k);
        }
        $this->assertSame('title', $title['role']);
        $this->assertEqualsWithDelta(120, $title['size'], 0.1);
        $this->assertSame(700, $title['weight']);
        $this->assertSame('#ffffff', $title['color']);
        $this->assertSame('@body', $title['font'], 'heading and body are the same family in this theme');
        $this->assertEqualsWithDelta(1.05, $title['lh'], 0.03);
        $this->assertSame('What moved, what stalled, what we do next', TextDoc::plain($subtitle['doc']));
        $this->assertEqualsWithDelta(44, $subtitle['size'], 0.1);
        $this->assertSame('#dbe7ff', $subtitle['color']);
        $this->assertSame('shape', $bar['type']);
        $this->assertSame('rect', $bar['kind']);
        $this->assertSame('@accent2', $bar['fill']);
    }

    public function test_heading_and_body_fonts_stay_theme_references_when_the_theme_has_two_families(): void
    {
        $doc = PressSampleDeck::doc();
        $doc['deck']['theme'] = ['id' => 'x', 'name' => 'X', 't' => ['heading' => 'Space Grotesk', 'body' => 'Inter', 'hw' => 700, 'scale' => 1]];
        $doc['slides'][0]['els'][0]['font'] = '@heading';
        $r = $this->roundTrip($doc);

        $this->assertSame('@heading', $r->slides[0]['els'][0]['font']);
        $this->assertSame('@body', $r->slides[0]['els'][1]['font']);
        $this->assertSame('Space Grotesk', $r->deck['theme']['t']['heading']);
        $this->assertSame('Inter', $r->deck['theme']['t']['body']);
    }

    public function test_lists_marks_and_links_survive(): void
    {
        $r = $this->roundTrip();
        $body = $this->byOrder($r, 1)[1];
        $doc = $body['doc']['content'];

        $this->assertSame('bulletList', $doc[0]['type']);
        $this->assertSame('orderedList', $doc[1]['type']);
        $this->assertSame(['Ship the pricing page', 'Hire two engineers'], array_map(fn ($i) => $i['content'][0]['content'][0]['text'], $doc[1]['content']));
        $item2 = $doc[0]['content'][1]['content'];
        $this->assertSame('bulletList', $item2[1]['type'], 'the nested bullet stays nested');
        $this->assertContains(['type' => 'italic'], $item2[0]['content'][0]['marks']);
        $bold = $doc[0]['content'][0]['content'][0]['content'][1];
        $this->assertSame('18%', $bold['text']);
        $this->assertSame([['type' => 'bold']], $bold['marks']);
        $link = $doc[0]['content'][2]['content'][0]['content'][1];
        $this->assertSame('https://example.com/report', $link['marks'][0]['attrs']['href']);
        $this->assertSame('#b91c1c', $doc[1]['content'][1]['content'][0]['content'][0]['marks'][0]['attrs']['color']);
    }

    public function test_shapes_keep_kind_geometry_rotation_and_fills(): void
    {
        $r = $this->roundTrip();
        $els = $this->byOrder($r, 2);
        $kinds = array_column($els, 'kind');

        $this->assertSame(['round', 'ellipse', 'triangle', 'star', 'hexagon', 'chevron', 'arrow-r', 'callout', 'heart'], array_slice($kinds, 0, 9));
        $this->assertSame('@accent', $els[0]['fill']);
        $this->assertEqualsWithDelta(24, $els[0]['radius'], 0.2);
        foreach (['x' => 100, 'y' => 100, 'w' => 300, 'h' => 200] as $k => $v) {
            $this->assertEqualsWithDelta($v, $els[0][$k], 0.6, $k);
        }
        $this->assertEqualsWithDelta(12, $els[2]['rot'], 0.05);
        $this->assertSame('#15803d', $els[2]['fill'], 'a token with no scheme slot is written as its colour');
        $this->assertSame('linear', $els[1]['fill']['g']);
        $this->assertEqualsWithDelta(90, $els[1]['fill']['a'], 0.5);
        $this->assertSame([[0.0, '#f59e0b'], [1.0, '#db2777']], $els[1]['fill']['stops']);
        $this->assertSame('#f59e0bcc', $els[3]['fill'], 'opacity 0.8 on a token fill becomes alpha on the colour');
        // the callout keeps its body height and the text inside it
        $this->assertSame('Say this out loud', TextDoc::plain($els[7]['doc']));
        $this->assertEqualsWithDelta(200, $els[7]['h'], 1.0);
        // lines keep their direction and length
        $line = $els[9];
        $this->assertSame('line', $line['kind']);
        $this->assertSame('dash', $line['dash']);
        $arrow = $els[10];
        $this->assertSame('arrow', $arrow['kind']);
        $this->assertSame(['s' => 'dot', 'e' => 'arrow'], $arrow['ends']);
        $this->assertEqualsWithDelta(hypot(400, 100), $arrow['w'], 0.6);
        $this->assertEqualsWithDelta(rad2deg(atan2(100, 400)), $arrow['rot'], 0.1);
    }

    public function test_charts_and_tables_survive_with_their_data(): void
    {
        $r = $this->roundTrip();
        $charts = array_values(array_filter($this->byOrder($r, 3), fn ($e) => $e['type'] === 'chart'));

        $this->assertSame('column', $charts[0]['chart']['type']);
        $this->assertSame(['Q1', 'Q2', 'Q3', 'Q4'], $charts[0]['chart']['data']['categories']);
        $this->assertSame([120.0, 180.0, 150.0, 240.0], $charts[0]['chart']['data']['series'][0]['values']);
        $this->assertSame('Revenue and cost', $charts[0]['chart']['opt']['title']);
        $this->assertSame('value', $charts[0]['chart']['opt']['labels']);
        $this->assertSame('line', $charts[1]['chart']['type']);
        $this->assertSame('pie', $charts[2]['chart']['type']);
        $this->assertSame('doughnut', $charts[3]['chart']['type']);
        foreach (['x' => 80, 'y' => 80, 'w' => 880, 'h' => 460] as $k => $v) {
            $this->assertEqualsWithDelta($v, $this->byOrder($r, 3)[0][$k], 0.6);
        }

        $table = array_values(array_filter($this->byOrder($r, 4), fn ($e) => $e['type'] === 'table'))[0];
        $this->assertSame('Region', $table['rows'][0][0]['t']);
        $this->assertSame([2, 1], $table['rows'][3][0]['span']);
        $this->assertSame('#fff3cd', $table['rows'][2][0]['fill']);
        $this->assertSame('r', $table['rows'][1][1]['al']);
        $this->assertTrue($table['header']);
        $this->assertTrue($table['band']);
        $this->assertEqualsWithDelta(0.4, $table['colw'][0], 0.001);
    }

    public function test_pictures_keep_crop_mask_and_position(): void
    {
        $r = $this->roundTrip();
        $images = array_values(array_filter($this->byOrder($r, 1), fn ($e) => $e['type'] === 'image'));

        $this->assertCount(1, $images);
        $this->assertSame('circle', $images[0]['mask'], 'an ellipse mask on a square box reads back as a circle');
        $this->assertSame('A test picture', $images[0]['alt']);
        foreach (['x' => 1200, 'y' => 260, 'w' => 600, 'h' => 600] as $k => $v) {
            $this->assertEqualsWithDelta($v, $images[0][$k], 0.6);
        }
        $this->assertNotEmpty($r->media);
    }

    public function test_nothing_is_lost_in_a_second_round_trip(): void
    {
        // export the imported result again: the second import sees the same element counts and texts
        $first = $this->roundTrip();
        $doc = $first->toDocument();
        $doc['deck']['id'] = 1;
        foreach ($doc['slides'] as &$s) {
            $s['els'] = array_values(array_filter($s['els'], fn ($e) => ! in_array($e['type'], ['image', 'video', 'audio'], true)));
        }
        unset($s);
        (new PptxExporter(PressSampleDeck::assets()))->render($doc, $this->file);
        $second = PptxImporter::import($this->file);

        foreach ($doc['slides'] as $i => $slide) {
            $this->assertCount(count($slide['els']), $second->slides[$i]['els'], 'slide '.($i + 1));
        }
        $texts = fn (ImportResult $r) => array_map(fn ($e) => TextDoc::plain($e['doc']), array_values(array_filter($r->slides[1]['els'], fn ($e) => $e['type'] === 'text')));
        $this->assertSame($texts($first), $texts($second));
    }
}
