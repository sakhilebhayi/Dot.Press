<?php

namespace Tests\Feature\Press;

use App\Services\Press\Export\Pdf\ChartSvg;
use App\Services\Press\Export\Support\Theme;
use Tests\TestCase;

/**
 * The PDF chart renderer is a port of the editor's JS chart engine. When Node and the engine are both there, this runs
 * the engine on the same specs and checks that every text line (labels, ticks, legend, title) lands in the same place.
 */
class ChartParityTest extends TestCase
{
    private function node(): ?string
    {
        foreach (['/usr/local/bin/node', '/opt/homebrew/bin/node', '/usr/bin/node'] as $n) {
            if (is_executable($n)) {
                return $n;
            }
        }

        return null;
    }

    public function test_labels_ticks_and_legends_land_where_the_editor_puts_them(): void
    {
        if ($this->node() === null || ! is_file(resource_path('js/press/charts/index.js'))) {
            $this->markTestSkipped('Node or the chart engine is not available in this checkout.');
        }
        $data = ['categories' => ['North', 'South', 'East', 'West'], 'series' => [
            ['name' => 'A', 'values' => [4, 7, 3, 9], 'points' => [['x' => 1, 'y' => 2, 'r' => 3], ['x' => 2, 'y' => 5, 'r' => 6], ['x' => 4, 'y' => 1, 'r' => 2]]],
            ['name' => 'B', 'values' => [2, 5, 6, 1], 'kind' => 'line', 'points' => [['x' => 3, 'y' => 3, 'r' => 1]]],
        ], 'kpis' => [['label' => 'MRR', 'value' => 1200, 'delta' => 12, 'unit' => 'R'], ['label' => 'Churn', 'value' => 2.1, 'delta' => -0.3, 'good' => 'down']], 'totals' => [3]];
        $cases = [];
        foreach (['bar', 'column', 'line', 'area', 'pie', 'doughnut', 'scatter', 'bubble', 'funnel', 'radar', 'combo', 'kpi'] as $type) {
            foreach ([[600, 360], [320, 200]] as [$w, $h]) {
                $cases[] = ['chart' => ['type' => $type, 'data' => $data, 'opt' => ['title' => 'Title '.$type, 'labels' => 'value']], 'w' => $w, 'h' => $h];
            }
        }
        $stacked = ['chart' => ['type' => 'column', 'data' => ['categories' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'], 'series' => [['name' => 'A', 'values' => [10, 20, 15, 25, 30, 22]], ['name' => 'B', 'values' => [5, 8, 12, 9, 14, 10]], ['name' => 'C', 'values' => [3, 4, 6, 5, 7, 8]]]], 'opt' => ['stacked' => true, 'labels' => 'value', 'legend' => 'right', 'title' => 'Stacked']], 'w' => 600, 'h' => 320];
        $cases[] = $stacked;

        $cmd = escapeshellarg((string) $this->node()).' '.escapeshellarg(base_path('tests/Fixtures/Press/js_chart_texts.mjs')).' '.escapeshellarg((string) json_encode($cases)).' 2>&1';
        $js = json_decode((string) shell_exec($cmd), true);
        $this->assertIsArray($js, 'the chart engine did not run');

        foreach ($cases as $i => $case) {
            $mine = ChartSvg::layers($case['chart'], $case['w'], $case['h'], new Theme(null))['texts'];
            $label = $case['chart']['type'].' '.$case['w'].'x'.$case['h'];
            $this->assertEqualsCanonicalizing(array_column($js[$i], 'text'), array_column($mine, 'text'), $label.': the same labels are drawn');
            foreach ($mine as $t) {
                $best = INF;
                foreach ($js[$i] as $j) {
                    if ($j['text'] === $t['text']) {
                        $best = min($best, abs($j['x'] - $t['x']) + abs($j['y'] - $t['y']));
                    }
                }
                $this->assertLessThan(2.5, $best, $label.': "'.$t['text'].'" is not where the editor puts it');
            }
        }
    }
}
