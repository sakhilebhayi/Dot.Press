<?php

namespace App\Services\Press\Import;

use DOMDocument;
use DOMElement;

/** Reads a DrawingML chart part (the cached values, not the workbook) into a spec chart. */
final class ChartImporter
{
    private const LIMIT_CATS = 500;

    private const LIMIT_SERIES = 40;

    /** @param list<string> $notes filled with things that could not be carried over */
    public function __construct(private ColorResolver $colors, public array $notes = []) {}

    /**
     * @return array<string, mixed>|null the spec chart, or null with a reason in $notes when the type has no equivalent
     */
    public function parse(DOMDocument $dom): ?array
    {
        $space = $dom->documentElement;
        $chartEl = Xml::child($space, 'chart');
        $plot = Xml::child($chartEl, 'plotArea');
        if ($plot === null) {
            $this->notes[] = 'The chart has no plot area.';

            return null;
        }
        $groups = [];
        foreach (Xml::children($plot) as $g) {
            if (str_ends_with((string) $g->localName, 'Chart')) {
                $groups[] = $g;
            }
        }
        if ($groups === []) {
            $this->notes[] = 'The chart has no series group.';

            return null;
        }
        $supported = ['barChart', 'bar3DChart', 'lineChart', 'line3DChart', 'areaChart', 'area3DChart', 'pieChart', 'pie3DChart', 'doughnutChart', 'scatterChart', 'bubbleChart', 'radarChart'];
        $first = $groups[0];
        $name = (string) $first->localName;
        if (! in_array($name, $supported, true)) {
            $this->notes[] = 'The chart type "'.$name.'" has no equivalent and was not imported.';

            return null;
        }
        if (str_contains($name, '3D')) {
            $this->notes[] = '3D formatting of the chart was dropped.';
        }
        $kinds = array_map(fn ($g) => (string) $g->localName, $groups);
        $isCombo = count($groups) > 1 && in_array('barChart', $kinds, true) && in_array('lineChart', $kinds, true);
        if (count($groups) > 1 && ! $isCombo) {
            $this->notes[] = 'Only the first chart group was imported.';
        }

        $type = match ($name) {
            'barChart', 'bar3DChart' => Xml::attr(Xml::child($first, 'barDir'), 'val', 'col') === 'bar' ? 'bar' : 'column',
            'lineChart', 'line3DChart' => 'line',
            'areaChart', 'area3DChart' => 'area',
            'pieChart', 'pie3DChart' => 'pie',
            'doughnutChart' => 'doughnut',
            'scatterChart' => 'scatter',
            'bubbleChart' => 'bubble',
            default => 'radar',
        };
        if ($isCombo) {
            $type = 'combo';
        }

        $categories = [];
        $series = [];
        $smooth = false;
        $stacked = false;
        $labels = 'none';
        $pctScale = false;
        $nf = null;
        foreach (Xml::findAll($plot, 'valAx') as $ax) {
            $nf = Xml::attr(Xml::child($ax, 'numFmt'), 'formatCode');
        }
        if ($nf !== null && str_contains($nf, '%') && ! in_array($type, ['pie', 'doughnut'], true)) {
            $pctScale = true;
        }
        foreach ($isCombo ? $groups : [$first] as $g) {
            $gname = (string) $g->localName;
            $kind = $gname === 'lineChart' ? 'line' : 'bar';
            $grouping = Xml::attr(Xml::child($g, 'grouping'), 'val', 'standard');
            if (in_array($grouping, ['stacked', 'percentStacked'], true)) {
                $stacked = true;
                if ($grouping === 'percentStacked') {
                    $this->notes[] = 'A 100 percent stacked chart was imported as a plain stacked chart.';
                }
            }
            $dl = Xml::child($g, 'dLbls');
            foreach (Xml::findAll($g, 'dLbls', 50) as $dl) {
                if (Xml::attr(Xml::child($dl, 'showVal'), 'val') === '1') {
                    $labels = 'value';
                } elseif (Xml::attr(Xml::child($dl, 'showPercent'), 'val') === '1' && $labels === 'none') {
                    $labels = 'percent';
                }
            }
            foreach (Xml::children($g, 'ser') as $ser) {
                if (count($series) >= self::LIMIT_SERIES) {
                    $this->notes[] = 'Only the first '.self::LIMIT_SERIES.' series were imported.';
                    break;
                }
                $s = ['name' => $this->serName($ser, count($series)), 'values' => []];
                if ($isCombo) {
                    $s['kind'] = $kind;
                }
                if (Xml::attr(Xml::child($ser, 'smooth'), 'val') === '1') {
                    $smooth = true;
                }
                $color = $this->serColor($ser);
                if ($color !== null) {
                    $s['color'] = $color;
                }
                if ($type === 'scatter' || $type === 'bubble') {
                    $xs = $this->numPoints(Xml::child($ser, 'xVal'));
                    $ys = $this->numPoints(Xml::child($ser, 'yVal'));
                    $rs = $type === 'bubble' ? $this->numPoints(Xml::child($ser, 'bubbleSize')) : [];
                    $pts = [];
                    foreach ($ys as $i => $y) {
                        $x = $xs[$i] ?? null;
                        if ($y === null || $x === null) {
                            continue;
                        }
                        $p = ['x' => $x, 'y' => $y];
                        if (isset($rs[$i]) && $rs[$i] !== null) {
                            $p['r'] = abs($rs[$i]);
                        }
                        $pts[] = $p;
                    }
                    $s['points'] = array_slice($pts, 0, 2000);
                } else {
                    if ($categories === []) {
                        $categories = array_map(fn ($v) => (string) $v, $this->strPoints(Xml::child($ser, 'cat')));
                    }
                    $vals = $this->numPoints(Xml::child($ser, 'val'));
                    $s['values'] = array_map(fn ($v) => $v === null ? null : ($pctScale ? $v * 100 : $v), $vals);
                }
                $series[] = $s;
            }
        }
        if (count($categories) > self::LIMIT_CATS) {
            $categories = array_slice($categories, 0, self::LIMIT_CATS);
            $this->notes[] = 'Only the first '.self::LIMIT_CATS.' categories were imported.';
        }
        $n = count($categories);
        if ($type !== 'scatter' && $type !== 'bubble') {
            $max = 0;
            foreach ($series as $s) {
                $max = max($max, count($s['values']));
            }
            for ($i = $n; $i < $max; $i++) {
                $categories[] = (string) ($i + 1);
            }
            foreach ($series as &$s) {
                $s['values'] = array_slice(array_pad($s['values'], count($categories), null), 0, count($categories));
            }
            unset($s);
        }

        $opt = ['legend' => 'none', 'labels' => $labels, 'stacked' => $stacked, 'smooth' => $smooth, 'grid' => Xml::find($plot, 'majorGridlines') !== null, 'fmt' => $pctScale ? 'pct' : 'num'];
        $title = $this->titleText(Xml::child($chartEl, 'title'));
        if ($title !== '') {
            $opt['title'] = mb_substr($title, 0, 140);
        }
        $legend = Xml::child($chartEl, 'legend');
        if ($legend !== null) {
            $opt['legend'] = match (Xml::attr(Xml::child($legend, 'legendPos'), 'val', 'r')) {
                'b' => 'bottom',
                't' => 'top',
                default => 'right',
            };
        }
        $ax = ['x' => ['show' => true], 'y' => ['show' => true]];
        $cat = Xml::child($plot, 'catAx') ?? (count(Xml::children($plot, 'valAx')) > 1 ? Xml::children($plot, 'valAx')[0] : null);
        $val = Xml::child($plot, 'catAx') !== null ? Xml::child($plot, 'valAx') : (count(Xml::children($plot, 'valAx')) > 1 ? Xml::children($plot, 'valAx')[1] : Xml::child($plot, 'valAx'));
        if ($cat !== null) {
            $ax['x'] = $this->axis($cat, false);
        }
        if ($val !== null) {
            $ax['y'] = $this->axis($val, true);
        }
        $opt['ax'] = $ax;

        return ['type' => $type, 'data' => ['categories' => $categories, 'series' => $series], 'opt' => $opt, 'src' => ['kind' => 'manual']];
    }

    /** @return array<string, mixed> */
    private function axis(DOMElement $ax, bool $range): array
    {
        $r = ['show' => Xml::attr(Xml::child($ax, 'delete'), 'val', '0') !== '1'];
        $t = $this->titleText(Xml::child($ax, 'title'));
        if ($t !== '') {
            $r['title'] = mb_substr($t, 0, 80);
        }
        if ($range) {
            $sc = Xml::child($ax, 'scaling');
            foreach (['min', 'max'] as $k) {
                $v = Xml::attr(Xml::child($sc, $k), 'val');
                if ($v !== null && is_numeric($v)) {
                    $r[$k] = (float) $v;
                }
            }
        }

        return $r;
    }

    private function titleText(?DOMElement $title): string
    {
        if ($title === null) {
            return '';
        }
        $out = [];
        foreach (Xml::findAll($title, 'p', 20) as $p) {
            $line = '';
            foreach (Xml::findAll($p, 't', 50) as $t) {
                $line .= $t->textContent;
            }
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return trim(implode(' ', $out));
    }

    private function serName(DOMElement $ser, int $i): string
    {
        $tx = Xml::child($ser, 'tx');
        $v = Xml::find($tx, 'v');
        $name = $v !== null ? trim($v->textContent) : '';

        return $name !== '' ? mb_substr($name, 0, 80) : 'Series '.($i + 1);
    }

    private function serColor(DOMElement $ser): ?string
    {
        $sp = Xml::child($ser, 'spPr');
        $fill = Xml::child($sp, 'solidFill');
        if ($fill === null) {
            $ln = Xml::child($sp, 'ln');
            $fill = Xml::child($ln, 'solidFill');
        }

        return $fill !== null ? $this->colors->spec($fill) : null;
    }

    /** @return list<?float> */
    private function numPoints(?DOMElement $holder): array
    {
        $cache = Xml::find($holder, 'numCache') ?? Xml::find($holder, 'numLit');
        $count = Xml::int(Xml::child($cache, 'ptCount'), 'val', 0);
        $vals = [];
        foreach (Xml::children($cache, 'pt') as $pt) {
            $idx = Xml::int($pt, 'idx', -1);
            $v = Xml::child($pt, 'v');
            if ($idx >= 0 && $idx < 5000 && $v !== null && is_numeric(trim($v->textContent))) {
                $vals[$idx] = (float) $v->textContent;
            }
        }
        $n = max($count, $vals ? max(array_keys($vals)) + 1 : 0);
        $n = min($n, 5000);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $vals[$i] ?? null;
        }

        return $out;
    }

    /** @return list<string> */
    private function strPoints(?DOMElement $holder): array
    {
        $cache = Xml::find($holder, 'strCache') ?? Xml::find($holder, 'numCache') ?? Xml::find($holder, 'strLit') ?? Xml::find($holder, 'numLit');
        $count = Xml::int(Xml::child($cache, 'ptCount'), 'val', 0);
        $vals = [];
        foreach (Xml::children($cache, 'pt') as $pt) {
            $idx = Xml::int($pt, 'idx', -1);
            $v = Xml::child($pt, 'v');
            if ($idx >= 0 && $idx < 5000 && $v !== null) {
                $vals[$idx] = mb_substr(trim($v->textContent), 0, 80);
            }
        }
        $n = min(5000, max($count, $vals ? max(array_keys($vals)) + 1 : 0));
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $vals[$i] ?? '';
        }

        return $out;
    }
}
