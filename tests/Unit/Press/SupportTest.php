<?php

namespace Tests\Unit\Press;

use App\Services\Press\Export\Pdf\FontRegistry;
use App\Services\Press\Export\Support\ChartData;
use App\Services\Press\Export\Support\IconLibrary;
use App\Services\Press\Export\Support\ImageComposer;
use App\Services\Press\Export\Support\Raster;
use App\Services\Press\Export\Support\ShapeGeometry;
use App\Services\Press\Export\Support\SvgPath;
use App\Services\Press\Export\Support\TableGrid;
use App\Services\Press\Export\Support\TextDoc;
use App\Services\Press\Export\Support\TextMeasurer;
use App\Services\Press\Export\Support\TextStyle;
use App\Services\Press\Export\Support\Theme;
use Tests\TestCase;

class SupportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['press_export.font_dir' => storage_path('framework/testing/press-fonts')]);
        FontRegistry::reset();
    }

    // ------------------------------------------------------------------ theme

    public function test_theme_tokens_hex_alpha_and_defaults(): void
    {
        $t = new Theme(['c' => ['accent' => '#abc', 'chart' => ['#111111']], 't' => ['heading' => 'Inter', 'body' => "Evil'; }"]]);

        $this->assertSame('#aabbcc', $t->hex('@accent'));
        $this->assertSame('#111111', $t->hex('@chart1'));
        $this->assertSame('#f59e0b', $t->hex('@chart2'), 'missing chart colours come from the default palette');
        $this->assertSame('#ffffff', $t->hex('@bg'));
        $this->assertNull($t->hex('@nope'));
        $this->assertNull($t->hex('javascript:alert(1)'));
        $this->assertSame('#336699', $t->hex('#336699cc'));
        $this->assertEqualsWithDelta(0.8, $t->alpha('#336699cc'), 0.01);
        $this->assertSame(1.0, $t->alpha('#336699'));
        $this->assertSame('rgba(51,102,153,0.8)', $t->css('#336699cc'));
        $this->assertSame('#336699', $t->css('#336699'));
        $this->assertSame('Inter', $t->family('@heading'));
        $this->assertSame('Evil', $t->family('@body'), 'a font name cannot carry markup or CSS');
        $this->assertSame('Inter', $t->family('Inter'));
        $this->assertTrue(Theme::isGradient(['g' => 'linear', 'a' => 90, 'stops' => [[0, '#fff'], [1, '#000']]]));
        $this->assertFalse(Theme::isGradient('#fff'));
        $stops = $t->stops(['g' => 'linear', 'stops' => [[1, '#000000'], [0, '@accent'], [0.5, 'bad']]]);
        $this->assertSame([[0.0, '#aabbcc', 1.0], [1.0, '#000000', 1.0]], $stops);
    }

    // ------------------------------------------------------------------ text

    public function test_text_docs_flatten_to_paragraphs_runs_and_nested_lists(): void
    {
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'A', 'marks' => [['type' => 'bold'], ['type' => 'link', 'attrs' => ['href' => 'javascript:x']]]], ['type' => 'hardBreak'], ['type' => 'text', 'text' => 'B', 'marks' => [['type' => 'textStyle', 'attrs' => ['color' => '#f00', 'fontSize' => 20, 'fontFamily' => 'Inter']]]]]],
            ['type' => 'orderedList', 'content' => [
                ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'one']]], ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'nested']]]]]]]]],
                ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'two']]]]],
            ]],
            ['type' => 'script', 'content' => [['type' => 'text', 'text' => 'ignored']]],
        ]];
        $p = TextDoc::paragraphs($doc);

        $this->assertCount(4, $p);
        $this->assertTrue($p[0]['runs'][0]['b']);
        $this->assertNull($p[0]['runs'][0]['link'], 'only https and slide links survive');
        $this->assertTrue($p[0]['runs'][1]['br']);
        $this->assertSame(['#f00', 20.0, 'Inter'], [$p[0]['runs'][2]['color'], $p[0]['runs'][2]['size'], $p[0]['runs'][2]['font']]);
        $this->assertSame(['ordered', 0, 1], [$p[1]['list'], $p[1]['level'], $p[1]['n']]);
        $this->assertSame(['bullet', 1], [$p[2]['list'], $p[2]['level']]);
        $this->assertSame(['ordered', 0, 2], [$p[3]['list'], $p[3]['level'], $p[3]['n']]);
        $this->assertSame("A\nB\none\nnested\ntwo", TextDoc::plain($doc));
        $this->assertSame([['list' => null, 'level' => 0, 'n' => 0, 'runs' => []]], TextDoc::paragraphs(null));
        $this->assertSame('x', TextDoc::paragraphs(null, 'x')[0]['runs'][0]['t']);
    }

    public function test_text_style_defaults_markers_and_bounds(): void
    {
        $t = new Theme(null);
        $s = TextStyle::of(['size' => 99999, 'lh' => -3, 'align' => 'evil', 'weight' => 650, 'fit' => 'shrink'], $t);

        $this->assertSame(2000.0, $s['size']);
        $this->assertSame(0.5, $s['lh']);
        $this->assertSame('left', $s['align']);
        $this->assertTrue($s['bold']);
        $this->assertSame('shrink', $s['fit']);
        $this->assertSame(700, TextStyle::of(['font' => '@heading'], $t)['weight']);
        $this->assertSame('1.', TextStyle::marker('ordered', 1, 0));
        $this->assertSame('b.', TextStyle::marker('ordered', 2, 1));
        $this->assertSame('iv.', TextStyle::marker('ordered', 4, 2));
        $this->assertSame("\u{2022}", TextStyle::marker('bullet', 1, 0));
    }

    public function test_the_measurer_uses_real_font_metrics_and_the_fit_loop_shrinks(): void
    {
        $narrow = TextMeasurer::width('iiiiiiii', 'Inter', false, false, 100);
        $wide = TextMeasurer::width('WWWWWWWW', 'Inter', false, false, 100);
        $this->assertGreaterThan($narrow * 2, $wide);
        $this->assertGreaterThan(TextMeasurer::width('Hello', 'Inter', false, false, 100), TextMeasurer::width('Hello', 'Inter', true, false, 100));
        $this->assertNotEquals(TextMeasurer::width('Hello', 'Public Sans', false, false, 100), TextMeasurer::width('Hello', 'Playfair Display', false, false, 100));

        $style = TextStyle::of(['size' => 40, 'font' => 'Inter'], new Theme(null));
        $paras = TextDoc::paragraphs(null, str_repeat('word ', 80));
        $one = TextMeasurer::layout($paras, $style, 800);
        $half = TextMeasurer::layout($paras, $style, 400);
        $this->assertGreaterThan($one['lines'], $half['lines']);
        $scale = TextMeasurer::fitScale($paras, $style, 800, 300);
        $this->assertLessThan(1.0, $scale);
        $this->assertLessThanOrEqual(300.0, TextMeasurer::layout($paras, $style, 800, $scale)['height']);
        $this->assertSame(1.0, TextMeasurer::fitScale($paras, $style, 800, 5000));
    }

    // ------------------------------------------------------------------ tables, geometry, paths

    public function test_table_grids_read_full_and_compact_span_forms(): void
    {
        $full = TableGrid::build(['w' => 300, 'h' => 120, 'rows' => [
            [['t' => 'a', 'span' => [2, 1]], ['t' => 'b'], ['t' => 'c']],
            [['t' => ''], ['t' => 'd', 'span' => [1, 2]], ['t' => '']],
            [['t' => 'e'], ['t' => 'f'], ['t' => 'g']],
        ]]);
        $this->assertSame(3, $full['cols']);
        $this->assertSame(['a', 'b', 'c', 'd', 'e', 'f', 'g'], array_map(fn ($c) => $c['cell']['t'], $full['cells']));
        $this->assertSame([2, 1], [$full['cells'][0]['rs'], $full['cells'][0]['cs']]);
        $this->assertEqualsWithDelta(1.0, array_sum($full['colw']), 0.0001);
        $this->assertSame([40.0, 40.0, 40.0], $full['rowh']);

        $compact = TableGrid::build(['rows' => [
            [['t' => 'a', 'span' => [2, 1]], ['t' => 'b']],
            [['t' => 'c']],
        ]]);
        $this->assertSame([[0, 0], [0, 1], [1, 1]], array_map(fn ($c) => [$c['r'], $c['c']], $compact['cells']));
        $this->assertSame(2, $compact['cols']);

        $hostile = TableGrid::build(['rows' => array_fill(0, 500, array_fill(0, 100, ['t' => 'x', 'span' => [9999, 9999]]))]);
        $this->assertLessThanOrEqual(TableGrid::MAX_ROWS, $hostile['rows']);
        $this->assertLessThanOrEqual(TableGrid::MAX_COLS, $hostile['cols']);
    }

    public function test_shape_polygons_stay_inside_their_box(): void
    {
        foreach (ShapeGeometry::KINDS as $kind) {
            $pts = ShapeGeometry::polygon($kind, 200.0, 100.0);
            if ($pts === null) {
                continue;
            }
            $this->assertGreaterThanOrEqual(3, count($pts), $kind);
            foreach ($pts as [$x, $y]) {
                $this->assertGreaterThanOrEqual(-0.001, $x, $kind);
                $this->assertLessThanOrEqual(200.001, $x, $kind);
                $this->assertGreaterThanOrEqual(-0.001, $y, $kind);
                $this->assertLessThanOrEqual(100.001, $y, $kind);
            }
        }
        $star = ShapeGeometry::polygon('star', 100.0, 100.0);
        $this->assertCount(10, $star);
        $this->assertEqualsWithDelta(0.0, min(array_column($star, 1)), 0.001, 'the star touches the top of its box');
        $this->assertEqualsWithDelta(100.0, max(array_column($star, 1)), 0.001);
        $this->assertGreaterThan(50, count(ShapeGeometry::heartPolygon(100.0, 100.0)));
    }

    public function test_svg_paths_parse_relative_commands_and_convert_arcs_to_curves(): void
    {
        $this->assertSame([['M', 1.0, 2.0], ['L', 4.0, 2.0], ['L', 4.0, 6.0], ['Z']], SvgPath::parse('M1 2 h3 v4 z'));
        $this->assertSame([['M', 0.0, 0.0], ['L', 5.0, 5.0], ['L', 6.0, 6.0]], SvgPath::parse('m0 0 l5 5 1 1'), 'implicit lineto after a relative lineto');
        $q = SvgPath::parse('M0 0 Q10 0 10 10');
        $this->assertSame('C', $q[1][0]);
        $this->assertEqualsWithDelta(10.0, $q[1][5], 0.0001);
        $arc = SvgPath::parse('M0 0 a5 5 0 0 1 10 0');
        $this->assertGreaterThanOrEqual(2, count($arc), 'a half circle needs at least two cubic segments');
        $end = end($arc);
        $this->assertEqualsWithDelta(10.0, $end[5], 0.001);
        $this->assertEqualsWithDelta(0.0, $end[6], 0.001);
        $this->assertSame([], SvgPath::parse('not a path'));
    }

    // ------------------------------------------------------------------ icons

    public function test_the_icon_library_reads_paths_from_the_js_file_and_knows_what_it_does_not_have(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'icons').'.js';
        file_put_contents($file, "export const ICONS = {\n  check: 'M20 6 9 17l-5-5',\n  'arrow-right': \"M5 12h14 M12 5l7 7-7 7\",\n  box: `<rect x=\"3\" y=\"3\" width=\"18\" height=\"18\"/>`,\n  ring: '<circle cx=\"12\" cy=\"12\" r=\"9\"/>',\n  bad: 'hello',\n};\n");
        $lib = new IconLibrary($file);
        $this->assertSame(['check', 'arrow-right', 'box', 'ring'], $lib->names());
        @unlink($file);

        $this->assertTrue($lib->has('check'));
        $this->assertTrue($lib->has('box'));
        $this->assertTrue($lib->has('ring'));
        $this->assertFalse($lib->has('bad'));
        $this->assertFalse($lib->has('missing'));
        $this->assertStringContainsString('stroke="#ff0000"', (string) $lib->svg('check', '#ff0000', 2, 48, 48));
        $this->assertNull($lib->svg('missing', '#000000', 2, 10, 10));
        $this->assertSame([], (new IconLibrary('/no/such/file.js'))->names());
    }

    public function test_the_icon_library_reads_the_editors_i_rows_and_evaluates_circle_and_box(): void
    {
        $lib = new IconLibrary(__DIR__.'/../../Fixtures/Press/icons_sample.js');

        $this->assertSame(['check', 'target', 'card', 'grid', 'arrow-right'], $lib->names());
        $this->assertSame(['M4 12.5l5 5L20 6.5'], $lib->paths('check'));
        $this->assertSame([
            'M3 12a9 9 0 1 0 18 0a9 9 0 1 0 -18 0Z',
            'M7 12a5 5 0 1 0 10 0a5 5 0 1 0 -10 0Z',
            'M11 12a1 1 0 1 0 2 0a1 1 0 1 0 -2 0Z',
        ], $lib->paths('target'));
        $this->assertSame('M4.5 5h15a2 2 0 0 1 2 2v10a2 2 0 0 1 -2 2h-15a2 2 0 0 1 -2 -2v-10a2 2 0 0 1 2 -2Z', $lib->paths('card')[0]);
        $this->assertSame('M4 4h7v7h-7Z', $lib->paths('grid')[0]);
        $this->assertSame('M14 4h5a1 1 0 0 1 1 1v5a1 1 0 0 1 -1 1h-5a1 1 0 0 1 -1 -1v-5a1 1 0 0 1 1 -1Z', $lib->paths('grid')[1]);
        $svg = (string) $lib->svg('arrow-right', '#123456', 1.75, 48, 48);
        $this->assertSame(2, substr_count($svg, '<path'));
        $this->assertStringContainsString('viewBox="0 0 24 24"', $svg);
    }

    public function test_the_real_icon_file_parses_completely_when_it_is_there(): void
    {
        $file = resource_path('js/press/icons.js');
        if (! is_file($file)) {
            $this->markTestSkipped('resources/js/press/icons.js is not in this checkout.');
        }
        $lib = new IconLibrary($file);
        preg_match_all("/^\s*I\('([a-z0-9-]+)'/m", (string) file_get_contents($file), $m);

        $this->assertSame($m[1], $lib->names(), 'every I(...) row becomes an icon with at least one path');
        foreach ($lib->names() as $name) {
            foreach ($lib->paths($name) as $d) {
                $this->assertNotSame([], SvgPath::parse($d), $name.' has a path nobody can read');
            }
        }
    }

    // ------------------------------------------------------------------ images

    public function test_image_plans_follow_cover_contain_and_fill(): void
    {
        $cover = ImageComposer::plan(1000, 500, ['fit' => 'cover'], 100.0, 100.0);
        $this->assertEqualsWithDelta(0.25, $cover['win'][0], 0.0001);
        $this->assertEqualsWithDelta(0.5, $cover['win'][2], 0.0001);
        $this->assertSame([0.0, 0.0, 100.0, 100.0], $cover['dest']);

        $contain = ImageComposer::plan(1000, 500, ['fit' => 'contain'], 100.0, 100.0);
        $this->assertSame([0.0, 25.0, 100.0, 50.0], $contain['dest']);
        $this->assertSame([0.0, 0.0, 1.0, 1.0], $contain['win']);

        $fill = ImageComposer::plan(1000, 500, ['fit' => 'fill', 'crop' => ['x' => 0.1, 'y' => 0.2, 'w' => 0.5, 'h' => 0.5]], 100.0, 100.0);
        $this->assertSame([0.1, 0.2, 0.5, 0.5], $fill['win']);
        $this->assertSame([0.0, 0.0, 100.0, 100.0], $fill['dest']);

        $bad = ImageComposer::plan(100, 100, ['crop' => ['x' => 2, 'y' => -1, 'w' => 99, 'h' => 0]], 50.0, 50.0);
        foreach ($bad['win'] as $v) {
            $this->assertGreaterThanOrEqual(0.0, $v);
            $this->assertLessThanOrEqual(1.0, $v);
        }
        $this->assertTrue(ImageComposer::hasAdj(['br' => 5]));
        $this->assertFalse(ImageComposer::hasAdj(['br' => 0, 'hue' => '0']));
        $this->assertTrue(ImageComposer::adjIsNativeOnly(['br' => 10, 'ct' => 5, 'gray' => 100]));
        $this->assertFalse(ImageComposer::adjIsNativeOnly(['sepia' => 20]));
        $this->assertSame([200, 100], ImageComposer::svgDimensions('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 100"></svg>'));
    }

    public function test_composing_applies_masks_flips_and_adjustments_to_the_pixels(): void
    {
        $src = imagecreatetruecolor(40, 20);
        imagefill($src, 0, 0, imagecolorallocate($src, 255, 0, 0));
        imagefilledrectangle($src, 20, 0, 39, 19, imagecolorallocate($src, 0, 0, 255)); // left red, right blue
        ob_start();
        imagepng($src);
        $png = (string) ob_get_clean();
        $theme = new Theme(null);

        $plain = ImageComposer::compose($png, ['fit' => 'fill'], 40.0, 20.0, $theme);
        $im = imagecreatefromstring($plain['bytes']);
        $this->assertSame([255, 0, 0], $this->rgb($im, 2, 10));
        $this->assertSame([0, 0, 255], $this->rgb($im, imagesx($im) - 3, 10));

        $flipped = imagecreatefromstring(ImageComposer::compose($png, ['fit' => 'fill', 'flipH' => true], 40.0, 20.0, $theme)['bytes']);
        $this->assertSame([0, 0, 255], $this->rgb($flipped, 2, 10));

        $masked = ImageComposer::compose($png, ['fit' => 'fill', 'mask' => 'ellipse'], 40.0, 20.0, $theme);
        $this->assertSame('image/png', $masked['mime']);
        $mi = imagecreatefromstring($masked['bytes']);
        $this->assertSame(127, (imagecolorat($mi, 0, 0) >> 24) & 127, 'the corner is outside the ellipse');
        $this->assertSame(0, (imagecolorat($mi, (int) (imagesx($mi) / 2), (int) (imagesy($mi) / 2)) >> 24) & 127);

        $gray = imagecreatefromstring(ImageComposer::compose($png, ['fit' => 'fill', 'adj' => ['gray' => 100]], 40.0, 20.0, $theme)['bytes']);
        [$r, $g, $b] = $this->rgb($gray, 2, 10);
        $this->assertEqualsWithDelta($r, $g, 2);
        $this->assertEqualsWithDelta($g, $b, 2);

        $this->assertNull(ImageComposer::compose('not an image', [], 10.0, 10.0, $theme));
        $this->assertNull(ImageComposer::decode(''));
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function rgb(\GdImage $im, int $x, int $y): array
    {
        $c = imagecolorat($im, $x, $y);

        return [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
    }

    public function test_rasters_gradients_follow_the_css_angle(): void
    {
        $im = Raster::gradient(100, 50, [[0.0, '#000000', 1.0], [1.0, '#ffffff', 1.0]], 90);
        $left = $this->rgb($im, 1, 25)[0];
        $right = $this->rgb($im, 98, 25)[0];
        $this->assertLessThan(15, $left);
        $this->assertGreaterThan(240, $right);
        $down = Raster::gradient(50, 100, [[0.0, '#000000', 1.0], [1.0, '#ffffff', 1.0]], 180);
        $this->assertLessThan(15, $this->rgb($down, 25, 1)[0]);
        $this->assertGreaterThan(240, $this->rgb($down, 25, 98)[0]);
        $mid = Raster::sample([[0.0, '#000000', 1.0], [1.0, '#ffffff', 1.0]], 0.5);
        $this->assertEqualsWithDelta(127.5, $mid[0], 0.6);
    }

    // ------------------------------------------------------------------ charts

    public function test_chart_data_normalises_hostile_specs_and_formats_numbers_like_the_editor(): void
    {
        $c = ChartData::normalize(['type' => 'nope', 'data' => ['categories' => array_fill(0, 600, "<b>x\n"), 'series' => array_fill(0, 60, ['name' => str_repeat('n', 500), 'values' => ['12', '1,5', 'abc', null, INF]])], 'opt' => ['legend' => 'sideways', 'dec' => 99, 'colors' => ['red', str_repeat('x', 100)]]]);

        $this->assertSame('column', $c['type']);
        $this->assertCount(500, $c['data']['categories']);
        $this->assertCount(40, $c['data']['series']);
        $this->assertSame([12.0, 15.0, null, null, null], array_slice($c['data']['series'][0]['values'], 0, 5));
        $this->assertLessThanOrEqual(80, mb_strlen($c['data']['series'][0]['name']));
        $this->assertSame('bottom', $c['opt']['legend']);
        $this->assertArrayNotHasKey('dec', $c['opt']);
        $this->assertSame(['red'], $c['opt']['colors']);

        $f = ChartData::formatter(['fmt' => 'cur', 'cur' => 'R']);
        $this->assertSame('R1,234', $f(1234.0));
        $this->assertSame('-R5.50', $f(-5.5));
        $this->assertSame('12.5%', ChartData::formatter(['fmt' => 'pct'])(12.5));
        $this->assertSame('1.23M', ChartData::formatter(['fmt' => 'compact'])(1234567.0));
        $this->assertSame('1,000', ChartData::formatter([])(1000.0));
        $this->assertSame('<1%', ChartData::pctShare(0.004));
        $this->assertSame('42%', ChartData::pctShare(0.4246));
    }

    public function test_nice_ticks_cover_the_range_with_round_steps(): void
    {
        $t = ChartData::niceTicks(0, 240, 5);
        $this->assertSame([0.0, 100.0, 200.0, 300.0], $t['ticks']);
        $this->assertSame(100.0, $t['step']);
        $this->assertSame([0.0, 50.0, 100.0, 150.0, 200.0, 250.0], ChartData::niceTicks(0, 240, 6)['ticks']);
        $e = ChartData::niceTicks(3, 97, 5, true);
        $this->assertSame(3.0, $e['min']);
        $this->assertSame(97.0, $e['max']);
        $this->assertSame([0.0, 0.2, 0.4, 0.6, 0.8, 1.0], ChartData::niceTicks(0, 0, 6)['ticks']);
        $this->assertSame(3, ChartData::stepDecimals(0.125));
    }

    public function test_waterfall_steps_run_totals_and_roles(): void
    {
        $chart = ChartData::normalize(['type' => 'waterfall', 'data' => ['categories' => ['s', 'up', 'down', 'gap', 'end'], 'series' => [['name' => 'x', 'values' => [100, 50, -30, null, null]]], 'totals' => [0, 4]], 'opt' => []]);
        $steps = ChartData::waterfallSteps($chart);

        $this->assertSame([[0.0, 100.0], [100.0, 150.0], [150.0, 120.0]], array_map(fn ($s) => [$s['from'], $s['to']], array_slice($steps, 0, 3)));
        $this->assertTrue($steps[3]['skip']);
        $this->assertTrue($steps[4]['total']);
        $this->assertSame(120.0, $steps[4]['value']);
        $this->assertSame(['bar', 'line'], ChartData::roles(ChartData::normalize(['type' => 'combo', 'data' => ['categories' => ['a'], 'series' => [['name' => 'a', 'values' => [1]], ['name' => 'b', 'values' => [1], 'kind' => 'line']]], 'opt' => []])));
    }
}
