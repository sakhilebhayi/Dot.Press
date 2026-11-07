<?php

namespace Tests\Feature\Press;

use App\Services\Press\Export\Support\TextDoc;
use App\Services\Press\Import\ImportResult;
use App\Services\Press\Import\PptxImporter;
use Tests\TestCase;

/** Imports files written by python-pptx, a writer that is independent of the Dot.Press exporter. */
class ImportPptxTest extends TestCase
{
    private function fixture(string $name): string
    {
        return __DIR__.'/../../Fixtures/Press/'.$name;
    }

    private function basic(): ImportResult
    {
        return PptxImporter::import($this->fixture('python_pptx_basic.pptx'));
    }

    /** @return array<string, mixed> */
    private function el(ImportResult $r, int $slide, string $type, int $nth = 0): array
    {
        $found = array_values(array_filter($r->slides[$slide]['els'], fn ($e) => $e['type'] === $type));

        return $found[$nth];
    }

    public function test_deck_size_slide_order_hidden_flag_notes_and_transitions(): void
    {
        $r = $this->basic();

        $this->assertSame(1280, $r->deck['w']);
        $this->assertSame(720, $r->deck['h']);
        $this->assertCount(5, $r->slides);
        $this->assertSame(['s1', 's2', 's3', 's4', 's5'], $r->deck['slide_order']);
        $this->assertTrue($r->slides[4]['hidden']);
        $this->assertFalse($r->slides[0]['hidden']);
        $this->assertSame('Speaker note for slide one', $r->slides[0]['notes']);
        $this->assertSame('fade', $r->slides[0]['transition']['type']);
        $this->assertSame('push', $r->slides[1]['transition']['type']);
        $this->assertSame('u', $r->slides[1]['transition']['dir']);
        $this->assertSame(1000, $r->slides[1]['transition']['dur']);
        $this->assertNull($r->slides[2]['transition']);
        $this->assertSame('Placeholder inheritance', $r->slides[0]['title']);
    }

    public function test_placeholders_inherit_position_size_colour_and_font_from_layout_and_master(): void
    {
        $r = $this->basic();
        $title = $this->el($r, 0, 'text', 0);

        $this->assertSame('title', $title['role']);
        $this->assertSame('Placeholder inheritance', TextDoc::plain($title['doc']));
        // python-pptx's template: title at 685800, 2130425 and 7772400 x 1470025 EMU, 44 pt
        $this->assertEqualsWithDelta(72.0, $title['x'], 0.01);
        $this->assertEqualsWithDelta(223.67, $title['y'], 0.01);
        $this->assertEqualsWithDelta(816.0, $title['w'], 0.01);
        $this->assertEqualsWithDelta(154.33, $title['h'], 0.01);
        $this->assertEqualsWithDelta(44 / 0.75, $title['size'], 0.01);
        $this->assertSame('@ink', $title['color']);
        $this->assertSame('center', $title['align']);
        $this->assertSame('middle', $title['valign']);
        $sub = $this->el($r, 0, 'text', 1);
        $this->assertSame('subtitle', $sub['role']);
        $this->assertSame('#404040', $sub['color'], 'the layout colour carries a tint modifier and so becomes a hex value');
    }

    public function test_bullets_levels_marks_colours_and_links_become_a_tiptap_document(): void
    {
        $r = $this->basic();
        $body = $this->el($r, 1, 'text', 1);

        $this->assertSame('body', $body['role']);
        $list = $body['doc']['content'][0];
        $this->assertSame('bulletList', $list['type']);
        $this->assertCount(3, $list['content']);
        $second = $list['content'][1]['content'];
        $this->assertSame('paragraph', $second[0]['type']);
        $texts = array_column($second[0]['content'], 'text');
        $this->assertSame(['Second point with ', 'bold', ' and red'], $texts);
        $this->assertSame([['type' => 'bold']], $second[0]['content'][1]['marks']);
        $this->assertSame('#c00000', $second[0]['content'][2]['marks'][0]['attrs']['color']);
        $this->assertSame('bulletList', $second[1]['type'], 'a level 2 paragraph nests a list inside the item');
        $this->assertSame('A nested point', $second[1]['content'][0]['content'][0]['content'][0]['text']);
        $link = $list['content'][2]['content'][0]['content'][0];
        $this->assertSame('link', $link['marks'][0]['type']);
        $this->assertSame('https://example.com/more', $link['marks'][0]['attrs']['href']);
    }

    public function test_shapes_fills_lines_rotation_groups_and_connectors(): void
    {
        $r = $this->basic();
        $s = $r->slides[2]['els'];

        $plain = $s[0];
        $this->assertSame('text', $plain['type']);
        $this->assertSame('A plain text box', TextDoc::plain($plain['doc']));
        $this->assertSame('Inter', $plain['font']);
        $this->assertEqualsWithDelta(28 / 0.75, $plain['size'], 0.01);

        $rotated = $s[1];
        $this->assertSame(15.0, (float) $rotated['rot']);
        $this->assertSame('@accent2', $rotated['fill'], 'a theme colour stays a theme token');
        $this->assertSame('Rotated', TextDoc::plain($rotated['doc']));
        $this->assertSame('@bg', $rotated['color'], 'shape text takes the style font colour (white on an accent fill)');

        $oval = $s[2];
        $this->assertSame('shape', $oval['type']);
        $this->assertSame('ellipse', $oval['kind']);
        $this->assertSame('#228844', $oval['fill']);
        $this->assertSame('#000000', $oval['stroke']);
        $this->assertEqualsWithDelta(3 / 0.75, $oval['sw'], 0.01);
        $this->assertStringStartsWith('#slide:', (string) ($oval['link'] ?? ''), 'a click action that jumps to a slide');

        $arrow = $s[3];
        $this->assertSame('arrow', $arrow['kind']);
        $this->assertSame(0, $arrow['h']);
        $this->assertSame('#111199', $arrow['stroke']);
        $this->assertSame('arrow', $arrow['ends']['e']);
        // the segment from (1in, 5in) to (5in, 4in): length 4.12in, rising
        $this->assertEqualsWithDelta(hypot(4, 1) * 96, $arrow['w'], 0.5);
        $this->assertEqualsWithDelta(360 - rad2deg(atan2(1, 4)), $arrow['rot'], 0.1);

        $g = array_values(array_filter($s, fn ($e) => isset($e['group'])));
        $this->assertCount(2, $g);
        $this->assertSame($g[0]['group'], $g[1]['group']);
        $this->assertSame('shape', $g[0]['type']);
        $this->assertSame('@accent', $g[0]['fill'], 'a shape with no fill of its own takes the style fill reference');
    }

    public function test_pictures_come_back_as_media_with_crop_and_are_stored_through_withassets(): void
    {
        $r = $this->basic();
        $pic = $this->el($r, 2, 'image');

        $this->assertSame(['import' => 'ppt/media/image1.png'], $pic['src']);
        $this->assertSame(['x' => 0.1, 'y' => 0.05, 'w' => 0.7, 'h' => 0.8], $pic['crop']);
        $this->assertSame('fill', $pic['fit']);
        $this->assertSame('image/png', $r->media['ppt/media/image1.png']['mime']);
        $this->assertStringStartsWith("\x89PNG", $r->media['ppt/media/image1.png']['bytes']);

        $stored = [];
        $r->withAssets(function (string $bytes, string $mime, string $name) use (&$stored): int {
            $stored[] = [strlen($bytes), $mime, $name];

            return 77;
        });
        $this->assertSame(['asset' => 77], $this->el($r, 2, 'image')['src']);
        $this->assertSame([[$stored[0][0], 'image/png', 'image1.png']], $stored);
        $this->assertSame('', $r->media['ppt/media/image1.png']['bytes'], 'the bytes are released once stored');
    }

    public function test_a_picture_the_store_refuses_is_left_out_and_reported(): void
    {
        $r = $this->basic();
        $r->withAssets(fn () => null);

        $this->assertSame([], array_filter($r->slides[2]['els'], fn ($e) => $e['type'] === 'image'));
        $this->assertNotEmpty(array_filter($r->report, fn ($x) => str_contains($x['message'], 'could not be stored')));
    }

    public function test_tables_keep_cells_spans_header_and_widths(): void
    {
        $r = $this->basic();
        $t = $this->el($r, 3, 'table');

        $this->assertTrue($t['header']);
        $this->assertCount(3, $t['rows']);
        $this->assertCount(3, $t['rows'][0]);
        $this->assertSame('R0C0', $t['rows'][0][0]['t']);
        $this->assertSame('Bold head', $t['rows'][0][1]['t']);
        $this->assertTrue($t['rows'][0][1]['b']);
        $this->assertSame([2, 1], $t['rows'][1][0]['span']);
        $this->assertEqualsWithDelta(1.0, array_sum($t['colw']), 0.001);
        $this->assertCount(3, $t['rowh']);
        $this->assertEqualsWithDelta(480.0, $t['w'], 0.01);
    }

    public function test_charts_become_spec_charts_with_their_data(): void
    {
        $r = $this->basic();
        $column = $this->el($r, 3, 'chart', 0)['chart'];
        $pie = $this->el($r, 3, 'chart', 1)['chart'];
        $scatter = $this->el($r, 3, 'chart', 2)['chart'];

        $this->assertSame('column', $column['type']);
        $this->assertSame(['Q1', 'Q2', 'Q3'], $column['data']['categories']);
        $this->assertSame([['name' => 'Revenue', 'values' => [10.0, 20.0, 15.0]], ['name' => 'Cost', 'values' => [5.0, 8.0, 9.0]]], $column['data']['series']);
        $this->assertSame('Revenue vs cost', $column['opt']['title']);
        $this->assertSame('bottom', $column['opt']['legend']);
        $this->assertSame('pie', $pie['type']);
        $this->assertSame([50.0, 30.0, 20.0], $pie['data']['series'][0]['values']);
        $this->assertSame('scatter', $scatter['type']);
        $this->assertSame([['x' => 1.0, 'y' => 2.0], ['x' => 2.0, 'y' => 4.0], ['x' => 3.0, 'y' => 3.0]], $scatter['data']['series'][0]['points']);
    }

    public function test_the_theme_colours_and_fonts_become_the_deck_theme(): void
    {
        $r = $this->basic();
        $t = $r->deck['theme'];

        $this->assertSame('#4f81bd', $t['c']['accent']);
        $this->assertSame('#c0504d', $t['c']['accent2']);
        $this->assertSame('#ffffff', $t['c']['bg']);
        $this->assertSame('#000000', $t['c']['ink']);
        $this->assertCount(8, $t['c']['chart']);
        $this->assertSame('Calibri', $t['t']['heading']);
        $this->assertSame('Calibri', $t['t']['body']);
    }

    public function test_everything_unsupported_is_reported_with_its_slide_never_dropped_silently(): void
    {
        $r = PptxImporter::import($this->fixture('python_pptx_unsupported.pptx'));
        $messages = array_map(fn ($x) => ($x['slide'] ?? 0).': '.$x['message'], $r->report);
        $joined = implode("\n", $messages);

        foreach (['equation', 'WordArt', 'lightningBolt', '3D formatting', 'SmartArt', 'embedded object', 'Animations', 'checker'] as $needle) {
            $this->assertStringContainsString($needle, $joined, $needle.' was not reported');
        }
        $this->assertSame(1, $r->report[0]['slide']);
        $this->assertSame(2, array_values(array_filter($r->report, fn ($x) => str_contains($x['message'], 'checker')))[0]['slide']);
        // the shape with an unknown preset is still there, as a rectangle
        $kinds = array_column($r->slides[0]['els'], 'kind');
        $this->assertContains('rect', $kinds);
    }

    public function test_every_imported_element_is_marked_and_has_a_valid_unique_id(): void
    {
        $r = $this->basic();
        foreach ($r->slides as $slide) {
            $ids = array_column($slide['els'], 'id');
            $this->assertSame(array_unique($ids), $ids);
            foreach ($slide['els'] as $el) {
                $this->assertSame('import', $el['origin']);
                $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,40}$/', $el['id']);
                $this->assertContains($el['type'], ['text', 'shape', 'image', 'video', 'audio', 'icon', 'chart', 'table']);
                foreach (['x', 'y', 'w', 'h'] as $k) {
                    $this->assertIsNumeric($el[$k]);
                }
            }
        }
    }
}
