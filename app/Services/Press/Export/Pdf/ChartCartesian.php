<?php

namespace App\Services\Press\Export\Pdf;

use App\Services\Press\Export\Support\ChartData;

/** Column, bar (horizontal), line, area, combo and waterfall charts: a port of the editor's cartesian renderer. */
final class ChartCartesian
{
    private static function fx(float $v): string
    {
        return ChartCanvas::fx($v);
    }

    private static function clamp(float $v, float $lo, float $hi): float
    {
        return $v < $lo ? $lo : ($v > $hi ? $hi : $v);
    }

    /** @param list<array{0: float, 1: float}> $pts */
    private static function monotonePath(array $pts, bool $move = true): string
    {
        $n = count($pts);
        if ($n === 0) {
            return '';
        }
        $d = ($move ? 'M' : 'L').self::fx($pts[0][0]).' '.self::fx($pts[0][1]);
        if ($n === 1) {
            return $d;
        }
        if ($n === 2) {
            return $d.'L'.self::fx($pts[1][0]).' '.self::fx($pts[1][1]);
        }
        $dx = [];
        $m = [];
        for ($i = 0; $i < $n - 1; $i++) {
            $dx[] = ($pts[$i + 1][0] - $pts[$i][0]) ?: 1e-6;
            $m[] = ($pts[$i + 1][1] - $pts[$i][1]) / $dx[$i];
        }
        $t = [$m[0]];
        for ($i = 1; $i < $n - 1; $i++) {
            $t[] = $m[$i - 1] * $m[$i] <= 0 ? 0.0 : ($m[$i - 1] + $m[$i]) / 2;
        }
        $t[] = $m[$n - 2];
        for ($i = 0; $i < $n - 1; $i++) {
            if ($m[$i] == 0) {
                $t[$i] = 0.0;
                $t[$i + 1] = 0.0;
            } else {
                $a = $t[$i] / $m[$i];
                $b = $t[$i + 1] / $m[$i];
                $s = $a * $a + $b * $b;
                if ($s > 9) {
                    $k = 3 / sqrt($s);
                    $t[$i] = $k * $a * $m[$i];
                    $t[$i + 1] = $k * $b * $m[$i];
                }
            }
        }
        for ($i = 0; $i < $n - 1; $i++) {
            $h = $dx[$i];
            $d .= 'C'.self::fx($pts[$i][0] + $h / 3).' '.self::fx($pts[$i][1] + ($t[$i] * $h) / 3).' '.self::fx($pts[$i + 1][0] - $h / 3).' '.self::fx($pts[$i + 1][1] - ($t[$i + 1] * $h) / 3).' '.self::fx($pts[$i + 1][0]).' '.self::fx($pts[$i + 1][1]);
        }

        return $d;
    }

    /** @param list<array{0: float, 1: float}> $pts */
    private static function linearPath(array $pts, bool $move = true): string
    {
        $d = '';
        foreach ($pts as $i => $p) {
            $d .= ($i === 0 ? ($move ? 'M' : 'L') : 'L').self::fx($p[0]).' '.self::fx($p[1]);
        }

        return $d;
    }

    /** Consecutive non-null runs. @param list<array{0: float, 1: ?float, 2: int, 3: ?float}> $arr */
    private static function runs(array $arr): array
    {
        $out = [];
        $cur = [];
        foreach ($arr as $p) {
            if ($p[1] === null) {
                if ($cur) {
                    $out[] = $cur;
                }
                $cur = [];
            } else {
                $cur[] = $p;
            }
        }
        if ($cur) {
            $out[] = $cur;
        }

        return $out;
    }

    /** A rectangle with only the data end rounded (absolute cubic curves: the SVG reader mis-draws relative arcs). */
    private static function roundedRect(float $x, float $y, float $w, float $h, float $r, string $edge): string
    {
        $rr = max(0.0, min($r, $w / 2, $h / 2));
        if ($rr < 0.5 || $edge === 'none') {
            return 'M'.self::fx($x).' '.self::fx($y).'L'.self::fx($x + $w).' '.self::fx($y).'L'.self::fx($x + $w).' '.self::fx($y + $h).'L'.self::fx($x).' '.self::fx($y + $h).'z';
        }
        [$tl, $tr, $br, $bl] = match ($edge) {
            'top' => [$rr, $rr, 0.0, 0.0],
            'bottom' => [0.0, 0.0, $rr, $rr],
            'right' => [0.0, $rr, $rr, 0.0],
            'left' => [$rr, 0.0, 0.0, $rr],
            default => [0.0, 0.0, 0.0, 0.0],
        };
        $q = 0.5523;
        $d = 'M'.self::fx($x + $tl).' '.self::fx($y);
        $d .= 'L'.self::fx($x + $w - $tr).' '.self::fx($y);
        if ($tr > 0) {
            $d .= 'C'.self::fx($x + $w - $tr + $tr * $q).' '.self::fx($y).' '.self::fx($x + $w).' '.self::fx($y + $tr - $tr * $q).' '.self::fx($x + $w).' '.self::fx($y + $tr);
        }
        $d .= 'L'.self::fx($x + $w).' '.self::fx($y + $h - $br);
        if ($br > 0) {
            $d .= 'C'.self::fx($x + $w).' '.self::fx($y + $h - $br + $br * $q).' '.self::fx($x + $w - $br + $br * $q).' '.self::fx($y + $h).' '.self::fx($x + $w - $br).' '.self::fx($y + $h);
        }
        $d .= 'L'.self::fx($x + $bl).' '.self::fx($y + $h);
        if ($bl > 0) {
            $d .= 'C'.self::fx($x + $bl - $bl * $q).' '.self::fx($y + $h).' '.self::fx($x).' '.self::fx($y + $h - $bl + $bl * $q).' '.self::fx($x).' '.self::fx($y + $h - $bl);
        }
        $d .= 'L'.self::fx($x).' '.self::fx($y + $tl);
        if ($tl > 0) {
            $d .= 'C'.self::fx($x).' '.self::fx($y + $tl - $tl * $q).' '.self::fx($x + $tl - $tl * $q).' '.self::fx($y).' '.self::fx($x + $tl).' '.self::fx($y);
        }

        return $d.'z';
    }

    /** @return array{mode: string, step: int, height: float, lines: list<list<string>>, rot?: float} */
    private static function planCategoryLabels(array $labels, float $avail, float $fs, float $maxRotW): array
    {
        $widths = array_map(fn ($l) => ChartCanvas::textWidth($l, $fs), $labels);
        $maxW = $widths ? max($widths) : 0.0;
        $lh = $fs * 1.2;
        if (! $labels) {
            return ['mode' => 'flat', 'step' => 1, 'height' => 0.0, 'lines' => []];
        }
        if ($maxW <= $avail * 0.9) {
            return ['mode' => 'flat', 'step' => 1, 'height' => $lh + $fs * 0.4, 'lines' => array_map(fn ($l) => [$l], $labels)];
        }
        $wrapped = array_map(fn ($l) => ChartCanvas::wrapText($l, $fs, $avail * 0.96, 2), $labels);
        $wrapOk = true;
        foreach ($wrapped as $i => $ls) {
            foreach ($ls as $ln) {
                if (str_ends_with($ln, '…') && $ln !== $labels[$i]) {
                    $wrapOk = false;
                }
            }
            if (implode(' ', $ls) !== preg_replace('/\s+/u', ' ', $labels[$i])) {
                $wrapOk = false;
            }
        }
        if ($wrapOk && $avail >= $fs * 2.2) {
            return ['mode' => 'wrap', 'step' => 1, 'height' => max(array_map('count', $wrapped)) * $lh + $fs * 0.4, 'lines' => $wrapped];
        }
        if ($avail >= $fs * 1.45) {
            $rw = min($maxW, $maxRotW);

            return ['mode' => 'rotate', 'step' => 1, 'height' => $rw * M_SQRT1_2 + $fs * 0.9, 'rot' => -45.0, 'lines' => array_map(fn ($l) => [ChartCanvas::fitText($l, $fs, $rw)], $labels)];
        }
        $cap = max($fs * 4, $avail * count($labels) * 0.22);
        $step = max(2, (int) ceil((min($maxW, $cap) + $fs * 0.8) / max($avail, 1.0)));

        return ['mode' => 'skip', 'step' => $step, 'height' => $lh + $fs * 0.4, 'lines' => array_map(fn ($l) => [ChartCanvas::fitText($l, $fs, max($avail * $step - $fs * 0.4, $fs * 2))], $labels)];
    }

    /** @return array{lo: float, hi: float, zero: bool} */
    private static function dataRange(ChartCanvas $c, array $roles): array
    {
        $chart = $c->chart;
        $data = $chart['data'];
        $lo = INF;
        $hi = -INF;
        $see = function (?float $v) use (&$lo, &$hi): void {
            if ($v !== null && is_finite($v)) {
                $lo = min($lo, $v);
                $hi = max($hi, $v);
            }
        };
        $n = count($data['categories']);
        if ($chart['type'] === 'waterfall') {
            foreach (ChartData::waterfallSteps($chart) as $s) {
                if (empty($s['skip'])) {
                    $see($s['from']);
                    $see($s['to']);
                }
            }
            $see(0.0);

            return ['lo' => $lo, 'hi' => $hi, 'zero' => true];
        }
        $stacked = $chart['opt']['stacked'];
        $needZero = false;
        for ($ci = 0; $ci < $n; $ci++) {
            $pos = 0.0;
            $neg = 0.0;
            $acc = 0.0;
            foreach ($data['series'] as $si => $s) {
                $v = $s['values'][$ci] ?? null;
                if ($v === null) {
                    continue;
                }
                $role = $roles[$si];
                if ($role === 'bar') {
                    $needZero = true;
                    if ($stacked) {
                        $v >= 0 ? $pos += $v : $neg += $v;
                    } else {
                        $see($v);
                    }
                } elseif ($role === 'area') {
                    $needZero = true;
                    if ($stacked) {
                        $acc += $v;
                    } else {
                        $see($v);
                    }
                } else {
                    $see($v);
                }
            }
            if ($stacked && ($pos != 0 || $neg != 0)) {
                $see($pos);
                $see($neg);
            }
            if ($stacked && $acc != 0) {
                $see($acc);
            }
        }
        if ($needZero) {
            $see(0.0);
        }

        return ['lo' => $lo, 'hi' => $hi, 'zero' => $needZero];
    }

    /** @return array{min: float, max: float, ticks: list<float>, step: float, truncated: bool} */
    private static function pickDomain(ChartCanvas $c, array $roles, int $count): array
    {
        $opt = $c->chart['opt'];
        $r = self::dataRange($c, $roles);
        $lo = $r['lo'];
        $hi = $r['hi'];
        $zero = $r['zero'];
        if (! is_finite($lo) || ! is_finite($hi)) {
            $lo = 0.0;
            $hi = 1.0;
            $zero = true;
        }
        if (! $zero) {
            if ($lo >= 0 && $lo < 0.5 * $hi) {
                $lo = 0.0;
            } elseif ($hi <= 0 && $hi > 0.5 * $lo) {
                $hi = 0.0;
            } else {
                $pad = ($hi - $lo) * 0.06 ?: (abs($hi) * 0.05 ?: 1);
                $lo -= $pad;
                $hi += $pad;
            }
        }
        $yMin = $opt['ax']['y']['min'] ?? null;
        $yMax = $opt['ax']['y']['max'] ?? null;
        $nt = ChartData::niceTicks($lo, $hi, $count);
        $min = $nt['min'];
        $max = $nt['max'];
        $exact = false;
        if ($yMin !== null) {
            $min = (float) $yMin;
            $exact = true;
        }
        if ($yMax !== null) {
            $max = (float) $yMax;
            $exact = true;
        }
        if ($max <= $min) {
            $max = $min + (abs($min) ?: 1);
            $exact = true;
        }
        $res = $exact ? ChartData::niceTicks($min, $max, $count, true) : $nt;
        $ticks = $res['ticks'] ?: [$min, $max];
        $rmin = $exact ? $min : $res['min'];

        return ['min' => $rmin, 'max' => $exact ? $max : $res['max'], 'ticks' => $ticks, 'step' => $res['step'], 'truncated' => $rmin > 0];
    }

    public static function render(ChartCanvas $c): void
    {
        $chart = $c->chart;
        $type = $chart['type'];
        $data = $chart['data'];
        $opt = $chart['opt'];
        $fs = $c->fs;
        $k = $c->k;
        $horizontal = $type === 'bar';
        $wf = $type === 'waterfall';
        $n = count($data['categories']);
        $series = $data['series'];
        $roles = $wf ? ['bar'] : ChartData::roles($chart);
        $hasData = false;
        foreach ($series as $s) {
            foreach ($s['values'] as $v) {
                if ($v !== null) {
                    $hasData = true;
                }
            }
        }
        $hasData = $hasData && $n > 0;
        $fmt = $c->fmt;

        if ($wf) {
            $steps = ChartData::waterfallSteps($chart);
            $legend = [];
            $any = fn (callable $f) => count(array_filter($steps, $f)) > 0;
            if ($any(fn ($s) => empty($s['total']) && empty($s['skip']) && $s['delta'] >= 0)) {
                $legend[] = ['name' => 'Increase', 'color' => (string) $c->theme->hex('@good'), 'kind' => 'swatch'];
            }
            if ($any(fn ($s) => empty($s['total']) && empty($s['skip']) && $s['delta'] < 0)) {
                $legend[] = ['name' => 'Decrease', 'color' => (string) $c->theme->hex('@bad'), 'kind' => 'swatch'];
            }
            if ($any(fn ($s) => ! empty($s['total']))) {
                $legend[] = ['name' => 'Total', 'color' => $c->colors[0] ?? $c->theme->chartColor(0), 'kind' => 'swatch'];
            }
        } else {
            $legend = $c->legendForSeries(array_map(fn ($r) => $r === 'line' ? 'line' : 'swatch', $roles));
        }

        $provisional = $hasData ? self::pickDomain($c, $roles, 5) : ['truncated' => false];
        if ($provisional['truncated']) {
            $c->notes[] = 'Value axis does not start at zero.';
        }
        $allZero = true;
        foreach ($series as $s) {
            foreach ($s['values'] as $v) {
                if ($v !== null && $v != 0) {
                    $allZero = false;
                }
            }
        }
        if ($hasData && ! $wf && $allZero) {
            $c->notes[] = 'All values are zero.';
        }
        if ($wf && count($series) > 1) {
            $c->notes[] = 'Only the first series is used for a waterfall.';
        }
        $dropped = 0;
        foreach (($wf ? array_slice($series, 0, 1) : $series) as $s) {
            foreach ($s['values'] as $i => $v) {
                if ($v === null && ! ($wf && in_array($i, $data['totals'] ?? [], true))) {
                    $dropped++;
                }
            }
        }
        if ($dropped > 0 && $hasData) {
            $c->notes[] = $dropped.' missing value'.($dropped === 1 ? '' : 's').' not drawn.';
        }

        $box = $c->planFrame($legend, ! $hasData);
        if (! $hasData) {
            $c->drawEmpty($box, $n === 0 ? 'No data to chart yet.' : 'All values are empty.');
            $c->drawChrome();

            return;
        }

        $showCat = $opt['ax']['x']['show'];
        $showVal = $opt['ax']['y']['show'];
        $yTitle = $opt['ax']['y']['title'] ?? null;
        $xTitle = $opt['ax']['x']['title'] ?? null;
        $valTitleSize = $fs * 0.9;
        $lineSeries = array_keys(array_filter($roles, fn ($r) => $r !== 'bar'));
        $direct = ! $wf && count($series) >= 2 && count($series) <= 4 && count($lineSeries) > 0 && $opt['legend'] !== 'right';
        $endLabelW = 0.0;
        if ($direct) {
            $lw = max(array_map(fn ($i) => ChartCanvas::textWidth($series[$i]['name'], $fs * 0.9), $lineSeries));
            $endLabelW = min($lw + $fs * 0.9, $box['w'] * 0.24);
        }
        $labelsOn = $opt['labels'] !== 'none';
        $pointCount = max(1, $n);
        $lineEdge = ($type === 'line' || $type === 'area') && $n > 1;
        $maxCatW = max(array_map(fn ($cat) => ChartCanvas::textWidth($cat, $fs), $data['categories']) ?: [0.0]);
        $plot = ['x' => $box['x'], 'y' => $box['y'], 'w' => $box['w'], 'h' => $box['h']];
        $dom = null;
        $catPlan = ['mode' => 'flat', 'step' => 1, 'height' => 0.0, 'lines' => []];
        $valFmt = null;

        for ($pass = 0; $pass < 3; $pass++) {
            $hAvail = $plot['h'];
            if ($horizontal) {
                $probe = self::pickDomain($c, $roles, 5);
                $probeFmt = ChartData::tickFormatter($opt, $probe['step']);
                $tw = max(array_map(fn ($t) => ChartCanvas::textWidth($probeFmt($t), $fs * 0.95), $probe['ticks']));
                $count = (int) self::clamp(floor($plot['w'] / ($tw + $fs * 1.8)), 2, 8);
                $dom = self::pickDomain($c, $roles, $count);
                $valFmt = ChartData::tickFormatter($opt, $dom['step']);
                $vLabelW = max(array_map(fn ($t) => ChartCanvas::textWidth($valFmt($t), $fs * 0.95), $dom['ticks']));
                $catW = $showCat ? min($box['w'] * 0.36, $maxCatW + $fs * 0.4) : 0.0;
                $left = $box['x'] + ($xTitle ? $fs * 1.6 : 0) + ($showCat ? $catW + $fs * 0.7 : $fs * 0.3);
                $right = $box['x'] + $box['w'] - $endLabelW - max($fs * 0.9, $vLabelW / 2);
                $bottom = $box['y'] + $box['h'] - ($showVal ? $fs * 1.9 : $fs * 0.3) - ($yTitle ? $fs * 1.5 : 0);
                $plot = ['x' => $left, 'y' => $box['y'] + $fs * 0.3, 'w' => max(20.0, $right - $left), 'h' => max(20.0, $bottom - ($box['y'] + $fs * 0.3))];
                $catPlan = ['catW' => $catW] + $catPlan;
            } else {
                $count = (int) self::clamp(floor($hAvail / ($fs * 3.1)), 2, 8);
                $dom = self::pickDomain($c, $roles, $count);
                $valFmt = ChartData::tickFormatter($opt, $dom['step']);
                $vLabelW = max(array_map(fn ($t) => ChartCanvas::textWidth($valFmt($t), $fs * 0.95), $dom['ticks']));
                $left = $box['x'] + ($yTitle ? $fs * 1.6 : 0) + ($showVal ? $vLabelW + $fs * 0.8 : $fs * 0.3);
                $lastOverhang = min($maxCatW / 2, $fs * 3);
                $right = $box['x'] + $box['w'] - $endLabelW - ($lineEdge ? $fs * 0.6 : min($fs * 0.9, $lastOverhang));
                $w = max(20.0, $right - $left);
                $inset = $lineEdge ? max($fs * 0.8, 10.0) : 0.0;
                $avail = $lineEdge ? ($w - 2 * $inset) / max(1, $n - 1) : $w / $pointCount;
                $catPlan = $showCat ? self::planCategoryLabels($data['categories'], $avail, $fs, max($fs * 4, $box['h'] * 0.3)) : ['mode' => 'flat', 'step' => 1, 'height' => 0.0, 'lines' => []];
                $catH = $showCat ? $catPlan['height'] : $fs * 0.3;
                $top = $box['y'] + (($labelsOn || $type !== 'bar') ? $fs * 1.1 : $fs * 0.4);
                $bottom = $box['y'] + $box['h'] - $catH - ($xTitle ? $fs * 1.5 : 0);
                $plot = ['x' => $left, 'y' => $top, 'w' => $w, 'h' => max(20.0, $bottom - $top)];
            }
        }

        $V = fn (float $v): float => $horizontal ? $plot['x'] + (($v - $dom['min']) / ($dom['max'] - $dom['min'])) * $plot['w'] : $plot['y'] + $plot['h'] - (($v - $dom['min']) / ($dom['max'] - $dom['min'])) * $plot['h'];
        $vClamp = fn (float $v): float => $V(self::clamp($v, $dom['min'], $dom['max']));
        $base = $V(self::clamp(0.0, $dom['min'], $dom['max']));
        $inset = $lineEdge ? max($fs * 0.8, 10.0) : 0.0;
        $band = $horizontal ? $plot['h'] / $pointCount : ($lineEdge ? ($plot['w'] - 2 * $inset) / max(1, $n - 1) : $plot['w'] / $pointCount);
        $C = fn (int $i): float => $horizontal ? $plot['y'] + ($i + 0.5) * $band : ($lineEdge ? $plot['x'] + $inset + $i * $band : ($n === 1 && ! $wf ? $plot['x'] + $plot['w'] / 2 : $plot['x'] + ($i + 0.5) * $band));

        // ----- grid and axes
        $grid = '';
        $zeroInside = $dom['min'] < 0 && $dom['max'] > 0;
        foreach ($dom['ticks'] as $t) {
            $p = $V($t);
            if ($horizontal) {
                if ($opt['grid']) {
                    $grid .= ChartCanvas::el('line', ['x1' => $p, 'y1' => $plot['y'], 'x2' => $p, 'y2' => $plot['y'] + $plot['h'], 'stroke' => $c->line, 'stroke-width' => $k]);
                }
                if ($showVal) {
                    $c->text($p, $plot['y'] + $plot['h'] + $fs * 1.15, $valFmt($t), ['size' => $fs * 0.95, 'anchor' => 'middle', 'fill' => $c->muted]);
                }
            } else {
                if ($opt['grid']) {
                    $grid .= ChartCanvas::el('line', ['x1' => $plot['x'], 'y1' => $p, 'x2' => $plot['x'] + $plot['w'], 'y2' => $p, 'stroke' => $c->line, 'stroke-width' => $k]);
                }
                if ($showVal) {
                    $c->text($plot['x'] - $fs * 0.6, $p, $valFmt($t), ['size' => $fs * 0.95, 'anchor' => 'end', 'fill' => $c->muted]);
                }
            }
        }
        $c->add($grid);
        if ($horizontal) {
            $c->add(ChartCanvas::el('line', ['x1' => $base, 'y1' => $plot['y'], 'x2' => $base, 'y2' => $plot['y'] + $plot['h'], 'stroke' => $c->muted, 'stroke-width' => $k * ($zeroInside ? 1.4 : 1)]));
        } else {
            $c->add(ChartCanvas::el('line', ['x1' => $plot['x'], 'y1' => $base, 'x2' => $plot['x'] + $plot['w'], 'y2' => $base, 'stroke' => $c->muted, 'stroke-width' => $k * ($zeroInside ? 1.4 : 1)]));
            if ($zeroInside) {
                $c->add(ChartCanvas::el('line', ['x1' => $plot['x'], 'y1' => $plot['y'] + $plot['h'], 'x2' => $plot['x'] + $plot['w'], 'y2' => $plot['y'] + $plot['h'], 'stroke' => $c->line, 'stroke-width' => $k]));
            }
        }
        if ($dom['truncated']) {
            $bx = $plot['x'];
            $by = $plot['y'] + $plot['h'];
            $sz = $fs * 0.55;
            $stroke = ['stroke' => $c->muted, 'stroke-width' => $k * 1.4, 'fill' => 'none', 'stroke-linecap' => 'round'];
            if ($horizontal) {
                $c->add(ChartCanvas::el('rect', ['x' => $bx - $sz * 0.8, 'y' => $by - $sz * 1.1, 'width' => $sz * 1.6, 'height' => $sz * 0.9, 'fill' => $c->surface]));
                $c->add(ChartCanvas::el('path', ['d' => 'M'.self::fx($bx - $sz * 0.8).' '.self::fx($by - $sz * 0.75).'l'.self::fx($sz * 1.6).' '.self::fx(-$sz * 0.45).'M'.self::fx($bx - $sz * 0.8).' '.self::fx($by - $sz * 0.15).'l'.self::fx($sz * 1.6).' '.self::fx(-$sz * 0.45)] + $stroke));
            } else {
                $c->add(ChartCanvas::el('rect', ['x' => $bx + $sz * 0.1, 'y' => $by - $sz * 0.8, 'width' => $sz * 0.9, 'height' => $sz * 1.6, 'fill' => $c->surface]));
                $c->add(ChartCanvas::el('path', ['d' => 'M'.self::fx($bx + $sz * 0.05).' '.self::fx($by + $sz * 0.8).'l'.self::fx($sz * 0.45).' '.self::fx(-$sz * 1.6).'M'.self::fx($bx + $sz * 0.65).' '.self::fx($by + $sz * 0.8).'l'.self::fx($sz * 0.45).' '.self::fx(-$sz * 1.6)] + $stroke));
            }
        }

        // ----- category labels
        if ($showCat) {
            if ($horizontal) {
                $lh = $fs * 1.15;
                $stepSkip = $band >= $lh ? 1 : (int) ceil($lh / $band);
                foreach ($data['categories'] as $i => $cat) {
                    if ($i % $stepSkip) {
                        continue;
                    }
                    $lines = $band >= $fs * 2.4 ? ChartCanvas::wrapText($cat, $fs, $catPlan['catW'], 2) : [ChartCanvas::fitText($cat, $fs, $catPlan['catW'])];
                    foreach ($lines as $li => $ln) {
                        $c->text($plot['x'] - $fs * 0.7, $C($i) + ($li - (count($lines) - 1) / 2) * $lh, $ln, ['size' => $fs, 'anchor' => 'end']);
                    }
                }
            } else {
                $y0 = $plot['y'] + $plot['h'] + $fs * 0.9;
                foreach ($data['categories'] as $i => $cat) {
                    if ($catPlan['mode'] === 'skip' && $i % $catPlan['step']) {
                        continue;
                    }
                    $lines = $catPlan['lines'][$i] ?? [$cat];
                    if ($catPlan['mode'] === 'rotate') {
                        $c->text($C($i) + $fs * 0.3, $y0 - $fs * 0.25, $lines[0], ['size' => $fs, 'anchor' => 'end', 'rotate' => $catPlan['rot']]);
                    } else {
                        foreach ($lines as $li => $ln) {
                            $c->text($C($i), $y0 + $li * $fs * 1.2, $ln, ['size' => $fs, 'anchor' => 'middle']);
                        }
                    }
                }
            }
        }
        if ($yTitle) {
            $cx = $horizontal ? $plot['x'] + $plot['w'] / 2 : $box['x'] + $fs * 0.6;
            $cy = $horizontal ? $plot['y'] + $plot['h'] + ($showVal ? $fs * 2.9 : $fs * 1.1) : $plot['y'] + $plot['h'] / 2;
            $c->text($cx, $cy, ChartCanvas::fitText($yTitle, $valTitleSize, ($horizontal ? $plot['w'] : $plot['h']) - 10), ['size' => $valTitleSize, 'anchor' => 'middle', 'fill' => $c->muted, 'rotate' => $horizontal ? 0 : -90]);
        }
        if ($xTitle) {
            $cx = $horizontal ? $box['x'] + $fs * 0.6 : $plot['x'] + $plot['w'] / 2;
            $cy = $horizontal ? $plot['y'] + $plot['h'] / 2 : $box['y'] + $box['h'] - $fs * 0.6;
            $c->text($cx, $cy, ChartCanvas::fitText($xTitle, $valTitleSize, ($horizontal ? $plot['h'] : $plot['w']) - 10), ['size' => $valTitleSize, 'anchor' => 'middle', 'fill' => $c->muted, 'rotate' => $horizontal ? -90 : 0]);
        }

        // ----- marks
        $labelSize = $fs * 0.9;
        $labelQueue = [];
        $gap = max(2.0, $k * 2);
        $radius = max(3.0, min($fs * 0.3, 6.0));
        $barIdx = array_keys(array_filter($roles, fn ($r) => $r === 'bar'));
        $nBars = $wf ? 1 : count($barIdx);
        $cap = $fs * 3.2;
        $stacked = $opt['stacked'] && ! $wf;
        $slots = $stacked ? 1 : $nBars;
        $thickness = max(2.0, min($cap, ($band * 0.74 - $gap * ($slots - 1)) / max(1, $slots)));
        $groupW = $slots * $thickness + ($slots - 1) * $gap;

        $share = function (int $si, int $ci, float $v) use ($stacked, $barIdx, $series): float {
            $tot = 0.0;
            if ($stacked) {
                foreach ($barIdx as $j) {
                    $x = $series[$j]['values'][$ci] ?? null;
                    if ($x !== null && $x > 0) {
                        $tot += $x;
                    }
                }
            } else {
                foreach ($series[$si]['values'] as $x) {
                    if ($x !== null && $x > 0) {
                        $tot += $x;
                    }
                }
            }

            return $tot ? $v / $tot : 0.0;
        };
        $labelText = fn (int $si, int $ci, float $v): string => $opt['labels'] === 'percent' ? ChartData::pctShare($share($si, $ci, $v)) : $fmt($v);
        $rectPath = function (float $cat, float $off, float $thick, float $a, float $b, string $edge) use ($horizontal, $groupW, $radius): string {
            $lo = min($a, $b);
            $len = abs($b - $a);
            $c0 = $cat - $groupW / 2 + $off;
            [$x, $y, $w, $h] = $horizontal ? [$lo, $c0, $len, $thick] : [$c0, $lo, $thick, $len];

            return self::roundedRect($x, $y, $w, $h, $radius, $edge);
        };

        $bars = '';
        if ($wf) {
            $good = (string) $c->theme->hex('@good');
            $bad = (string) $c->theme->hex('@bad');
            $total = $c->colors[0] ?? $c->theme->chartColor(0);
            $conns = '';
            $prevC = null;
            foreach (ChartData::waterfallSteps($chart) as $s) {
                if (! empty($s['skip'])) {
                    continue;
                }
                $fill = ! empty($s['total']) ? $total : ($s['delta'] >= 0 ? $good : $bad);
                $a = $V(self::clamp($s['from'], $dom['min'], $dom['max']));
                $b = $V(self::clamp($s['to'], $dom['min'], $dom['max']));
                $up = $s['to'] >= $s['from'];
                $edge = $horizontal ? ($up ? 'right' : 'left') : ($up ? 'top' : 'bottom');
                $len = abs($b - $a);
                if ($len < 1) {
                    $bars .= ChartCanvas::el('rect', $horizontal ? ['x' => $a - 1, 'y' => $C($s['i']) - $thickness / 2, 'width' => 2, 'height' => $thickness, 'fill' => $fill] : ['x' => $C($s['i']) - $thickness / 2, 'y' => $a - 1, 'width' => $thickness, 'height' => 2, 'fill' => $fill]);
                } else {
                    $bars .= ChartCanvas::el('path', ['d' => $rectPath($C($s['i']), 0, $thickness, $a, $b, $edge), 'fill' => $fill]);
                }
                if ($prevC !== null) {
                    $cp = $V(self::clamp($s['from'], $dom['min'], $dom['max']));
                    $conns .= $horizontal
                        ? ChartCanvas::el('line', ['x1' => $cp, 'y1' => $prevC + $thickness / 2, 'x2' => $cp, 'y2' => $C($s['i']) - $thickness / 2, 'stroke' => $c->muted, 'stroke-width' => $k, 'stroke-opacity' => 0.7])
                        : ChartCanvas::el('line', ['x1' => $prevC + $thickness / 2, 'y1' => $cp, 'x2' => $C($s['i']) - $thickness / 2, 'y2' => $cp, 'stroke' => $c->muted, 'stroke-width' => $k, 'stroke-opacity' => 0.7]);
                }
                $prevC = $C($s['i']);
                if ($labelsOn) {
                    $lab = ! empty($s['total']) ? $fmt($s['value']) : ($s['delta'] >= 0 ? '+' : '').$fmt($s['delta']);
                    $labelQueue[] = ['text' => $lab, 'cat' => $C($s['i']), 'end' => $horizontal ? ($up ? max($a, $b) : min($a, $b)) : ($up ? min($a, $b) : max($a, $b)), 'up' => $up, 'a' => $a, 'b' => $b, 'fill' => $fill, 'si' => 0, 'total' => false, 'stacked' => false, 'line' => false];
                }
            }
            $c->add($conns);
        } else {
            foreach ($barIdx as $bi => $si) {
                $s = $series[$si];
                $fill = $c->colors[$si];
                foreach ($s['values'] as $ci => $v) {
                    if ($v === null) {
                        continue;
                    }
                    $edge = 'none';
                    $off = $stacked ? 0.0 : $bi * ($thickness + $gap);
                    if ($stacked) {
                        $pos = 0.0;
                        $neg = 0.0;
                        foreach ($barIdx as $j) {
                            if ($j === $si) {
                                break;
                            }
                            $x = $series[$j]['values'][$ci] ?? null;
                            if ($x === null) {
                                continue;
                            }
                            $x >= 0 ? $pos += $x : $neg += $x;
                        }
                        $from = $v >= 0 ? $pos : $neg;
                        $to = $from + $v;
                        $a = $vClamp($from);
                        $b = $vClamp($to);
                        if (abs($b - $a) > $gap * 2.5 && $from != 0) {
                            $dir = $b <=> $a;
                            $a += $dir * ($gap / 2);
                        }
                        $outer = true;
                        for ($j = $si + 1; $j < count($series); $j++) {
                            if ($roles[$j] !== 'bar') {
                                continue;
                            }
                            $x = $series[$j]['values'][$ci] ?? null;
                            if ($x !== null && ($x >= 0) === ($v >= 0) && $x != 0) {
                                $outer = false;
                                break;
                            }
                        }
                        if ($outer && $v != 0) {
                            $edge = $horizontal ? ($v >= 0 ? 'right' : 'left') : ($v >= 0 ? 'top' : 'bottom');
                        }
                    } else {
                        $a = $base;
                        $b = $vClamp($v);
                        if ($v != 0) {
                            $edge = $horizontal ? ($v >= 0 ? 'right' : 'left') : ($v >= 0 ? 'top' : 'bottom');
                        }
                    }
                    if (abs($b - $a) < 0.6) {
                        if ($v == 0) {
                            continue;
                        }
                        $b = $a + ($v > 0 ? ($horizontal ? 1 : -1) : ($horizontal ? -1 : 1));
                    }
                    $bars .= ChartCanvas::el('path', ['d' => $rectPath($C($ci), $off, $thickness, $a, $b, $edge), 'fill' => $fill]);
                    if ($labelsOn) {
                        $labelQueue[] = ['text' => $labelText($si, $ci, $v), 'cat' => $C($ci) - $groupW / 2 + $off + $thickness / 2, 'end' => $b, 'a' => $a, 'b' => $b, 'up' => $v >= 0, 'fill' => $fill, 'si' => $si, 'stacked' => $stacked, 'total' => false, 'line' => false];
                    }
                }
            }
            if ($stacked && $labelsOn && $opt['labels'] === 'value' && count($barIdx) > 1) {
                for ($ci = 0; $ci < $n; $ci++) {
                    $pos = 0.0;
                    $neg = 0.0;
                    foreach ($barIdx as $j) {
                        $x = $series[$j]['values'][$ci] ?? null;
                        if ($x === null) {
                            continue;
                        }
                        $x >= 0 ? $pos += $x : $neg += $x;
                    }
                    if ($pos == 0 && $neg == 0) {
                        continue;
                    }
                    $useNeg = $pos == 0;
                    $t = $useNeg ? $neg : $pos;
                    $labelQueue[] = ['text' => $fmt($t), 'cat' => $C($ci), 'end' => $vClamp($t), 'up' => ! $useNeg, 'total' => true, 'si' => -1, 'fill' => '#000000', 'a' => 0.0, 'b' => 0.0, 'stacked' => false, 'line' => false];
                }
            }
        }
        $c->add($bars);

        // ----- lines and areas
        $markerR = max(3.5, $fs * 0.26);
        $dotBoxes = [];
        $endLabels = [];
        $lineEls = '';
        $areaEls = '';
        $dotEls = '';
        $areaStack = function (int $ci, int $si) use ($roles, $series): float {
            $lo = 0.0;
            for ($j = 0; $j < $si; $j++) {
                if ($roles[$j] !== 'area') {
                    continue;
                }
                $x = $series[$j]['values'][$ci] ?? null;
                if ($x !== null) {
                    $lo += $x;
                }
            }

            return $lo;
        };
        foreach ($lineSeries as $si) {
            $s = $series[$si];
            $color = $c->colors[$si];
            $role = $roles[$si];
            $stackedArea = $role === 'area' && $opt['stacked'];
            $pts = [];
            foreach ($s['values'] as $ci => $v) {
                if ($v === null) {
                    $pts[] = [$C($ci), null, $ci, null];
                } else {
                    $val = $stackedArea ? $areaStack($ci, $si) + $v : $v;
                    $pts[] = [$C($ci), $vClamp($val), $ci, $v];
                }
            }
            $rs = self::runs($pts);
            $pathOf = fn (array $seg): string => $opt['smooth'] ? self::monotonePath(array_map(fn ($p) => [$p[0], $p[1]], $seg)) : self::linearPath(array_map(fn ($p) => [$p[0], $p[1]], $seg));
            if ($role === 'area') {
                $areaCount = count(array_filter($roles, fn ($r) => $r === 'area'));
                foreach ($rs as $seg) {
                    if (count($seg) < 2) {
                        continue;
                    }
                    if ($stackedArea) {
                        $lower = array_reverse(array_map(fn ($p) => [$p[0], $vClamp($areaStack($p[2], $si))], $seg));
                        $d = $pathOf($seg).($opt['smooth'] ? self::monotonePath($lower, false) : self::linearPath($lower, false)).'z';
                    } else {
                        $last = $seg[count($seg) - 1];
                        $d = $pathOf($seg).'L'.self::fx($last[0]).' '.self::fx($base).'L'.self::fx($seg[0][0]).' '.self::fx($base).'z';
                    }
                    $op = $stackedArea ? 0.82 : ($areaCount > 1 ? 0.2 : 0.16);
                    $areaEls .= ChartCanvas::el('path', ['d' => $d, 'fill' => $color, 'fill-opacity' => $op]);
                }
            }
            $strokeW = max(2.0, $k * 2);
            $dpath = '';
            foreach ($rs as $seg) {
                if (count($seg) > 1) {
                    $dpath .= $pathOf($seg);
                }
            }
            if ($dpath !== '') {
                $lineEls .= ChartCanvas::el('path', ['d' => $dpath, 'fill' => 'none', 'stroke' => $color, 'stroke-width' => $stackedArea ? max(1.5, $k * 1.5) : $strokeW, 'stroke-linejoin' => 'round', 'stroke-linecap' => 'round', 'stroke-dasharray' => $c->dash($si)]);
            }
            $showDots = count(array_filter($s['values'], fn ($v) => $v !== null)) <= 30 && $band >= $markerR * 1.8;
            $lastIdx = -1;
            for ($ci = count($pts) - 1; $ci >= 0; $ci--) {
                if ($pts[$ci][1] !== null) {
                    $lastIdx = $ci;
                    break;
                }
            }
            foreach ($pts as $ci => $p) {
                if ($p[1] === null) {
                    continue;
                }
                $isolated = ($ci === 0 || $pts[$ci - 1][1] === null) && ($ci === count($pts) - 1 || $pts[$ci + 1][1] === null);
                if (! ($showDots || $isolated || $ci === $lastIdx) || $stackedArea) {
                    continue;
                }
                $dotBoxes[] = ['x' => $p[0] - $markerR - 1, 'y' => $p[1] - $markerR - 1, 'w' => $markerR * 2 + 2, 'h' => $markerR * 2 + 2];
                $dotEls .= $c->marker($c->markerShape($si), $p[0], $p[1], $markerR, $color, 1.0, $c->surface, max(1.5, $k * 1.5));
            }
            if ($direct && $lastIdx >= 0 && $lastIdx >= count($pts) - 1) {
                $lp = $pts[$lastIdx];
                $yy = $lp[1];
                if ($stackedArea) {
                    $yy = ($vClamp($areaStack($lp[2], $si) + $lp[3]) + $vClamp($areaStack($lp[2], $si))) / 2;
                }
                $endLabels[] = ['si' => $si, 'x' => $lp[0], 'y' => $yy, 'text' => $s['name']];
            }
            if ($labelsOn && ! $stackedArea) {
                $valid = array_values(array_filter(array_keys($pts), fn ($ci) => $pts[$ci][1] !== null));
                if (count($valid) <= 12) {
                    $idxs = $valid;
                } else {
                    $mx = $mn = $valid[0];
                    foreach ($valid as $ci) {
                        if ($pts[$ci][3] > $pts[$mx][3]) {
                            $mx = $ci;
                        }
                        if ($pts[$ci][3] < $pts[$mn][3]) {
                            $mn = $ci;
                        }
                    }
                    $idxs = array_values(array_unique([$valid[count($valid) - 1], $mx, $mn]));
                }
                foreach ($idxs as $ci) {
                    $p = $pts[$ci];
                    $labelQueue[] = ['text' => $opt['labels'] === 'percent' ? ChartData::pctShare($share($si, $ci, $p[3])) : $fmt($p[3]), 'cat' => $p[0], 'end' => $p[1], 'up' => true, 'line' => true, 'fill' => $color, 'si' => $si, 'r' => $markerR, 'a' => 0.0, 'b' => 0.0, 'stacked' => false, 'total' => false];
                }
            }
        }
        $c->add($areaEls.$lineEls.$dotEls);

        // ----- data labels, two attempts at decreasing size
        if ($labelQueue) {
            $bounds = ['x' => $box['x'], 'y' => $box['y'] - $fs * 0.2, 'w' => $box['w'], 'h' => $box['h'] + $fs * 0.2];
            $attempt = function (float $size) use ($labelQueue, $bounds, $dotBoxes, $horizontal, $thickness, $c): array {
                $placer = ChartCanvas::placer($bounds, $dotBoxes);
                $out = [];
                $missed = 0;
                foreach ($labelQueue as $q) {
                    $tw = ChartCanvas::textWidth($q['text'], $size);
                    $th = $size * 1.15;
                    $cands = [];
                    $m = $size * 0.35;
                    if ($horizontal) {
                        $dirRight = $q['up'];
                        $room = abs($q['b'] - $q['a']);
                        if ($q['line']) {
                            $cands[] = ['x' => $q['end'] + ($q['r'] ?? 0) + $m, 'y' => $q['cat'], 'anchor' => 'start', 'fill' => $c->ink];
                        } elseif ($q['stacked'] && ! $q['total']) {
                            if ($room >= $tw + $m * 2) {
                                $cands[] = ['x' => ($q['a'] + $q['b']) / 2, 'y' => $q['cat'], 'anchor' => 'middle', 'fill' => ChartCanvas::inkOn($c->colors[$q['si']])];
                            }
                        } else {
                            $cands[] = ['x' => $dirRight ? $q['end'] + $m : $q['end'] - $m, 'y' => $q['cat'], 'anchor' => $dirRight ? 'start' : 'end', 'fill' => $c->ink];
                            if (! $q['total'] && $room >= $tw + $m * 2) {
                                $cands[] = ['x' => $dirRight ? $q['end'] - $m : $q['end'] + $m, 'y' => $q['cat'], 'anchor' => $dirRight ? 'end' : 'start', 'fill' => ChartCanvas::inkOn($q['fill'])];
                            }
                        }
                    } elseif ($q['stacked'] && ! $q['total']) {
                        $room = abs($q['b'] - $q['a']);
                        if ($room >= $th + $m && $thickness >= $tw + $m) {
                            $cands[] = ['x' => $q['cat'], 'y' => ($q['a'] + $q['b']) / 2, 'anchor' => 'middle', 'fill' => ChartCanvas::inkOn($c->colors[$q['si']])];
                        }
                    } elseif ($q['line']) {
                        $r = $q['r'] ?? 0;
                        $cands[] = ['x' => $q['cat'], 'y' => $q['end'] - $r - $th / 2 - 2, 'anchor' => 'middle', 'fill' => $c->ink];
                        $cands[] = ['x' => $q['cat'], 'y' => $q['end'] + $r + $th / 2 + 2, 'anchor' => 'middle', 'fill' => $c->ink];
                    } else {
                        $above = $q['up'];
                        $cands[] = ['x' => $q['cat'], 'y' => $above ? $q['end'] - $th / 2 - $m * 0.6 : $q['end'] + $th / 2 + $m * 0.6, 'anchor' => 'middle', 'fill' => $c->ink];
                        if (! $q['total']) {
                            $room = abs($q['b'] - $q['a']);
                            if ($room >= $th * 1.5 && $thickness >= $tw * 0.9) {
                                $cands[] = ['x' => $q['cat'], 'y' => $above ? $q['end'] + $th / 2 + $m : $q['end'] - $th / 2 - $m, 'anchor' => 'middle', 'fill' => ChartCanvas::inkOn($q['si'] >= 0 ? $c->colors[$q['si']] : $q['fill'])];
                            }
                        }
                    }
                    $ok = false;
                    foreach ($cands as $cnd) {
                        if ($placer->tryPlace(ChartCanvas::textBox($cnd['x'], $cnd['y'], $q['text'], $size, $cnd['anchor']))) {
                            $out[] = [$cnd['x'], $cnd['y'], $q['text'], ['size' => $size, 'anchor' => $cnd['anchor'], 'fill' => $cnd['fill'], 'weight' => $q['total'] ? 600 : 400]];
                            $ok = true;
                            break;
                        }
                    }
                    if (! $ok) {
                        $missed++;
                    }
                }

                return ['out' => $out, 'missed' => $missed];
            };
            $res = $attempt($labelSize);
            if ($res['missed']) {
                $r2 = $attempt($labelSize * 0.8);
                if ($r2['missed'] < $res['missed']) {
                    $res = $r2;
                }
            }
            foreach ($res['out'] as $o) {
                $c->text($o[0], $o[1], $o[2], $o[3]);
            }
        }

        // ----- direct end labels with leader lines when nudged
        if ($endLabels) {
            $size = $fs * 0.9;
            $lhh = $size * 1.25;
            usort($endLabels, fn ($a, $b) => $a['y'] <=> $b['y']);
            $ys = array_map(fn ($e) => $e['y'], $endLabels);
            for ($it = 0; $it < 12; $it++) {
                for ($i = 1; $i < count($ys); $i++) {
                    if ($lhh > $ys[$i] - $ys[$i - 1]) {
                        $ys[$i] = $ys[$i - 1] + $lhh;
                    }
                }
                $over = $ys[count($ys) - 1] - ($plot['y'] + $plot['h']);
                if ($over > 0) {
                    for ($i = count($ys) - 1; $i >= 0; $i--) {
                        $ys[$i] -= $over * ($i === count($ys) - 1 ? 1 : 0.5);
                    }
                }
            }
            $ex = $plot['x'] + $plot['w'] + ($lineEdge ? $fs * 0.6 : 0);
            foreach ($endLabels as $i => $e) {
                $nudged = abs($ys[$i] - $e['y']) > $fs * 0.3;
                $lx = $e['x'] + $markerR + $fs * 0.3;
                $tx = max($ex, $lx) + $fs * 0.35;
                if ($nudged) {
                    $c->add(ChartCanvas::el('polyline', ['points' => self::fx($e['x'] + $markerR + 1).','.self::fx($e['y']).' '.self::fx($tx - $fs * 0.3).','.self::fx($ys[$i]), 'fill' => 'none', 'stroke' => $c->muted, 'stroke-width' => $k, 'stroke-opacity' => 0.7]));
                }
                $room = $box['x'] + $box['w'] - $tx;
                $c->text($tx, $ys[$i], ChartCanvas::fitText($e['text'], $size, $room), ['size' => $size, 'fill' => $c->ink, 'weight' => 600]);
            }
        }
        $c->drawChrome();
    }
}
