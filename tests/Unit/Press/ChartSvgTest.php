<?php

namespace Tests\Unit\Press;

use App\Services\Press\Export\Pdf\ChartSvg;
use App\Services\Press\Export\Support\ChartData;
use App\Services\Press\Export\Support\Theme;
use DOMDocument;
use Tests\TestCase;

class ChartSvgTest extends TestCase
{
    private function data(): array
    {
        return ['categories' => ['North', 'South', 'East', 'West'], 'series' => [
            ['name' => 'A', 'values' => [4, 7, 3, 9], 'points' => [['x' => 1, 'y' => 2, 'r' => 3], ['x' => 2, 'y' => 5, 'r' => 6], ['x' => 4, 'y' => 1, 'r' => 2]]],
            ['name' => 'B', 'values' => [2, 5, 6, 1], 'kind' => 'line', 'points' => [['x' => 3, 'y' => 3, 'r' => 1]]],
        ], 'kpis' => [['label' => 'MRR', 'value' => 1200, 'delta' => 12, 'unit' => 'R'], ['label' => 'Churn', 'value' => 2.1, 'delta' => -0.3, 'good' => 'down']], 'totals' => [3]];
    }

    private function load(string $svg): DOMDocument
    {
        $d = new DOMDocument;
        $this->assertTrue(@$d->loadXML($svg), 'the chart is not well formed SVG');

        return $d;
    }

    public function test_every_chart_type_draws_marks_and_text_as_valid_svg(): void
    {
        $expect = [
            'bar' => ['path', 4], 'column' => ['path', 4], 'line' => ['path', 1], 'area' => ['path', 2], 'pie' => ['path', 4], 'doughnut' => ['path', 4],
            'scatter' => ['polygon|circle|rect', 3], 'bubble' => ['circle', 3], 'waterfall' => ['path', 3], 'funnel' => ['rect', 4], 'radar' => ['polygon', 3], 'combo' => ['path', 5], 'kpi' => ['rect', 2],
        ];
        foreach ($expect as $type => [$tags, $min]) {
            $chart = ['type' => $type, 'data' => $this->data(), 'opt' => ['title' => 'T '.$type, 'labels' => 'value']];
            $svg = ChartSvg::render($chart, 600, 360, null);
            $dom = $this->load($svg);
            $count = 0;
            foreach (explode('|', $tags) as $tag) {
                $count += $dom->getElementsByTagName($tag)->length;
            }
            $this->assertGreaterThanOrEqual($min, $count, $type.' draws too few marks');
            $this->assertGreaterThan(2, $dom->getElementsByTagName('text')->length, $type.' has no labels');
            $this->assertStringContainsString('T '.$type, $svg);
            $this->assertSame('svg', $dom->documentElement->localName);
            $this->assertSame('0 0 600 360', $dom->documentElement->getAttribute('viewBox'));
        }
    }

    public function test_layers_split_marks_from_text_for_the_pdf(): void
    {
        $theme = new Theme(null);
        $layers = ChartSvg::layers(['type' => 'column', 'data' => $this->data(), 'opt' => ['title' => 'Hello', 'labels' => 'value']], 600, 360, $theme);

        $this->assertStringNotContainsString('<text', $layers['svg']);
        $this->load($layers['svg']);
        $texts = array_column($layers['texts'], 'text');
        $this->assertContains('Hello', $texts);
        $this->assertContains('North', $texts);
        $this->assertContains('9', $texts);
        foreach ($layers['texts'] as $t) {
            $this->assertGreaterThan(0, $t['size']);
            $this->assertContains($t['anchor'], ['start', 'middle', 'end']);
        }
    }

    public function test_the_value_axis_ticks_match_the_editors_nice_ticks_and_bars_scale_with_values(): void
    {
        $layers = ChartSvg::layers(['type' => 'column', 'data' => ['categories' => ['a', 'b'], 'series' => [['name' => 's', 'values' => [100, 200]]]], 'opt' => ['legend' => 'none']], 400, 300, new Theme(null));
        $labels = array_column($layers['texts'], 'text');

        foreach (['0', '50', '100', '150', '200'] as $tick) {
            $this->assertContains($tick, $labels);
        }
        preg_match_all('#<path d="M([\d.]+) ([\d.]+)L([\d.]+) ([\d.]+)#', $layers['svg'], $m);
        // two bars: the second is twice as tall as the first
        $this->assertCount(2, $m[2]);
        $dom = $this->load($layers['svg']);
        $heights = [];
        foreach ($dom->getElementsByTagName('path') as $p) {
            $d = $p->getAttribute('d');
            preg_match_all('#(?:L|C)[^LCz]*?(\d+\.?\d*) (\d+\.?\d*)z?#', $d, $mm);
            $ys = array_map('floatval', $mm[2] ?: [0]);
            $heights[] = max($ys) - min($ys);
        }
        $this->assertGreaterThan(1.7, $heights[1] / max($heights[0], 0.001));
    }

    public function test_hostile_chart_text_cannot_break_the_svg(): void
    {
        $chart = ['type' => 'pie', 'data' => ['categories' => ['<script>alert(1)</script>', '"quote" & amp'], 'series' => [['name' => '</text><evil/>', 'values' => [1, 2]]]], 'opt' => ['title' => '<img src=x onerror=1>', 'colors' => ['"><evil>', '#fff"']]];
        $svg = ChartSvg::render($chart, 500, 300, ['c' => ['accent' => '"><x>']]);
        $dom = $this->load($svg);

        $this->assertStringNotContainsString('<script>', $svg);
        $this->assertStringNotContainsString('<evil', $svg);
        $this->assertSame(0, $dom->getElementsByTagName('evil')->length);
        $this->assertStringContainsString('&lt;script&gt;', $svg);
    }

    public function test_empty_and_broken_charts_say_so_instead_of_throwing(): void
    {
        foreach (['column', 'pie', 'scatter', 'radar', 'funnel', 'kpi', 'waterfall', 'combo', 'doughnut', 'bubble', 'area', 'line', 'bar'] as $type) {
            $svg = ChartSvg::render(['type' => $type, 'data' => ['categories' => [], 'series' => []], 'opt' => []], 400, 250, null);
            $this->load($svg);
            $this->assertStringContainsString('<text', $svg, $type);
        }
        $this->load(ChartSvg::render(['type' => 'column', 'data' => ['categories' => ['a'], 'series' => [['name' => 'x', 'values' => [null]]]], 'opt' => []], 10, 10, null));
        $this->load(ChartSvg::render([], 1, 1, null));
        $this->load(ChartSvg::render(['type' => 'column', 'data' => ['categories' => range(1, 500), 'series' => [['name' => 'x', 'values' => range(1, 500)]]], 'opt' => []], 800, 400, null));
    }

    public function test_the_chart_follows_the_deck_theme_colours(): void
    {
        $theme = ['c' => ['chart' => ['#ff0000', '#00ff00'], 'ink' => '#123456', 'bg' => '#000000', 'muted' => '#999999', 'line' => '#333333']];
        $svg = ChartSvg::render(['type' => 'column', 'data' => ['categories' => ['a'], 'series' => [['name' => 's1', 'values' => [3]], ['name' => 's2', 'values' => [4], 'color' => '@chart2']]], 'opt' => []], 400, 300, $theme);

        $this->assertStringContainsString('fill="#ff0000"', $svg);
        $this->assertStringContainsString('fill="#00ff00"', $svg);
        $this->assertStringContainsString('fill="#123456"', $svg);
        $this->assertEqualsCanonicalizing(['#ff0000', '#00ff00'], array_slice(ChartData::seriesColors(ChartData::normalize(['type' => 'column', 'data' => ['categories' => ['a'], 'series' => [['name' => 's1', 'values' => [3]], ['name' => 's2', 'values' => [4]]]]]), new Theme($theme)), 0, 2));
    }
}
