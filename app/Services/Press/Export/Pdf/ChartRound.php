<?php

namespace App\Services\Press\Export\Pdf;

use App\Services\Press\Export\Support\ChartData;

/** Pie, doughnut, scatter, bubble, radar, funnel and key figure tiles: ports of the editor's renderers. */
final class ChartRound
{
    private static function fx(float $v): string
    {
        return ChartCanvas::fx($v);
    }

    private static function clamp(float $v, float $lo, float $hi): float
    {
        return $v < $lo ? $lo : ($v > $hi ? $hi : $v);
    }

    // ------------------------------------------------------------------ pie and doughnut

    private static function arcPath(float $cx, float $cy, float $R, float $r, float $a0, float $a1): string
    {
        $sweep = $a1 - $a0;
        if ($sweep >= 359.99) {
            $full = fn (float $rr) => 'M'.self::fx($cx + $rr).' '.self::fx($cy).'A'.self::fx($rr).' '.self::fx($rr).' 0 1 1 '.self::fx($cx - $rr).' '.self::fx($cy).'A'.self::fx($rr).' '.self::fx($rr).' 0 1 1 '.self::fx($cx + $rr).' '.self::fx($cy).'z';

            return $r > 0 ? $full($R).$full($r) : $full($R);
        }
        $large = $sweep > 180 ? 1 : 0;
        $x0 = $cx + $R * cos(deg2rad($a0));
        $y0 = $cy + $R * sin(deg2rad($a0));
        $x1 = $cx + $R * cos(deg2rad($a1));
        $y1 = $cy + $R * sin(deg2rad($a1));
        if ($r <= 0) {
            return 'M'.self::fx($cx).' '.self::fx($cy).'L'.self::fx($x0).' '.self::fx($y0).'A'.self::fx($R).' '.self::fx($R).' 0 '.$large.' 1 '.self::fx($x1).' '.self::fx($y1).'z';
        }
        $xi1 = $cx + $r * cos(deg2rad($a1));
        $yi1 = $cy + $r * sin(deg2rad($a1));
        $xi0 = $cx + $r * cos(deg2rad($a0));
        $yi0 = $cy + $r * sin(deg2rad($a0));

        return 'M'.self::fx($x0).' '.self::fx($y0).'A'.self::fx($R).' '.self::fx($R).' 0 '.$large.' 1 '.self::fx($x1).' '.self::fx($y1).'L'.self::fx($xi1).' '.self::fx($yi1).'A'.self::fx($r).' '.self::fx($r).' 0 '.$large.' 0 '.self::fx($xi0).' '.self::fx($yi0).'z';
    }

    public static function pie(ChartCanvas $c): void
    {
        $chart = $c->chart;
        $opt = $chart['opt'];
        $fs = $c->fs;
        $k = $c->k;
        $donut = $chart['type'] === 'doughnut';
        $vals = $chart['data']['series'][0]['values'] ?? [];
        $slices = [];
        foreach ($chart['data']['categories'] as $i => $name) {
            $v = $vals[$i] ?? null;
            $slices[] = ['name' => $name !== '' ? $name : (string) ($i + 1), 'value' => $v !== null && $v > 0 ? $v : 0.0, 'index' => $i];
        }
        $total = array_sum(array_map(fn ($s) => $s['value'], $slices));
        $neg = count(array_filter($vals, fn ($v) => $v !== null && $v < 0));
        if ($neg) {
            $c->notes[] = $neg.' negative value'.($neg === 1 ? ' is' : 's are').' not shown in a pie.';
        }
        $palette = $c->theme->chart();
        $colors = [];
        foreach ($slices as $i => $s) {
            $custom = $opt['colors'][$i] ?? null;
            $hex = $custom !== null ? $c->theme->hex($custom) : null;
            $colors[] = $hex ?? $palette[$i % count($palette)];
        }
        $legend = [];
        foreach ($slices as $i => $s) {
            $legend[] = ['name' => $s['name'], 'color' => $colors[$i], 'kind' => 'swatch'];
        }
        $box = $c->planFrame($legend, $total <= 0);
        if (! $slices || $total <= 0) {
            $c->drawEmpty($box, ! $slices ? 'No data to chart yet.' : 'No values above zero to show.');
            $c->drawChrome();

            return;
        }
        $named = count($slices) <= 4 && $c->w >= 420;
        $labelsOn = $opt['labels'] !== 'none';
        $size = $fs * 0.9;
        $lines = [];
        foreach ($slices as $s) {
            $share = $s['value'] / $total;
            $lines[] = ['share' => $share, 'val' => $opt['labels'] === 'percent' ? ChartData::pctShare($share) : ($c->fmt)($s['value'])];
        }
        $place = array_fill(0, count($slices), 'none');
        $outsideW = 0.0;
        $R0 = min($box['w'], $box['h']) / 2 - $fs * 0.4;
        $innerFrac = $donut ? 0.6 : 0.0;
        foreach ($slices as $i => $s) {
            $share = $lines[$i]['share'];
            if ($share <= 0) {
                continue;
            }
            $wantName = $named;
            if (! $labelsOn && ! $wantName) {
                continue;
            }
            $theta = deg2rad($share * 360);
            $rMid = $R0 * ($donut ? (1 + $innerFrac) / 2 : 0.62);
            $chord = 2 * $rMid * sin(min($theta, M_PI) / 2);
            $thick = $donut ? $R0 * (1 - $innerFrac) : $R0 * 0.55;
            $tw = ChartCanvas::textWidth($lines[$i]['val'], $size);
            $fits = $labelsOn && ! $wantName && $share >= 0.02 && $tw * 1.3 + $size * 0.6 <= $chord && $size * 1.15 <= $thick && ($share > 0.06 || $chord > $tw * 1.4);
            if ($fits) {
                $place[$i] = 'inside';
            } else {
                $place[$i] = 'outside';
                $tiny = ! $wantName && $share < 0.02;
                $w = $tiny ? ChartCanvas::textWidth($s['name'].' '.$lines[$i]['val'], $size) : max($tw, $wantName ? ChartCanvas::textWidth($s['name'], $size, 600) : 0.0);
                $outsideW = max($outsideW, $w);
            }
        }
        $anyOutside = in_array('outside', $place, true);
        $maxOut = min($outsideW, $box['w'] * 0.28);
        $R = max(20.0, min($R0, $anyOutside ? min($R0 - $fs * 1.4, $box['w'] / 2 - $maxOut - $fs * 2.2) : $R0, $anyOutside ? $box['h'] / 2 - $fs * 1.4 : $R0));
        $cx = $box['x'] + $box['w'] / 2;
        $cy = $box['y'] + $box['h'] / 2;
        $r = $donut ? $R * $innerFrac : 0.0;
        $gap = max(2.0, $k * 2);
        $angles = [];
        $a = -90.0;
        $paths = '';
        foreach ($slices as $i => $s) {
            $sweep = $s['value'] / $total * 360;
            $angles[] = [$a, $a + $sweep];
            if ($sweep > 0) {
                $paths .= ChartCanvas::el('path', ['d' => self::arcPath($cx, $cy, $R, $r, $a, $a + $sweep), 'fill' => $colors[$i], 'stroke' => $c->surface, 'stroke-width' => $gap, 'stroke-linejoin' => 'round', 'fill-rule' => 'evenodd']);
            }
            $a += $sweep;
        }
        $c->add($paths);

        $sides = ['l' => [], 'r' => []];
        foreach ($slices as $i => $s) {
            if ($place[$i] === 'none') {
                continue;
            }
            [$a0, $a1] = $angles[$i];
            $mid = deg2rad(($a0 + $a1) / 2);
            if ($place[$i] === 'inside') {
                $rr = $donut ? ($R + $r) / 2 : $R * 0.64;
                $c->text($cx + $rr * cos($mid), $cy + $rr * sin($mid), $lines[$i]['val'], ['size' => $size, 'anchor' => 'middle', 'fill' => ChartCanvas::inkOn($colors[$i]), 'weight' => 600]);
            } else {
                $sides[cos($mid) >= 0 ? 'r' : 'l'][] = ['i' => $i, 'mid' => $mid, 'y' => $cy + ($R + $fs * 0.5) * sin($mid)];
            }
        }
        $lineH = $size * ($named && $labelsOn ? 2.3 : ($named ? 1.3 : 1.35));
        foreach (['l', 'r'] as $side) {
            $arr = $sides[$side];
            usort($arr, fn ($p, $q) => $p['y'] <=> $q['y']);
            $ys = array_map(fn ($e) => $e['y'], $arr);
            for ($it = 0; $it < 10; $it++) {
                for ($j = 1; $j < count($ys); $j++) {
                    if ($lineH > $ys[$j] - $ys[$j - 1]) {
                        $ys[$j] = $ys[$j - 1] + $lineH;
                    }
                }
                $over = $ys ? $ys[count($ys) - 1] - ($box['y'] + $box['h'] - $lineH / 2) : 0;
                if ($over > 0) {
                    for ($j = count($ys) - 1; $j >= 0; $j--) {
                        $ys[$j] -= $over;
                    }
                }
                $under = $ys ? $box['y'] + $lineH / 2 - $ys[0] : 0;
                if ($under > 0) {
                    for ($j = 0; $j < count($ys); $j++) {
                        $ys[$j] += $under;
                    }
                }
            }
            foreach ($arr as $j => $e) {
                $dir = $side === 'r' ? 1 : -1;
                $x0 = $cx + $R * cos($e['mid']);
                $y0 = $cy + $R * sin($e['mid']);
                $x1 = $cx + ($R + $fs * 0.45) * cos($e['mid']);
                $y1 = $cy + ($R + $fs * 0.45) * sin($e['mid']);
                $x2 = $cx + $dir * ($R + $fs * 0.9);
                $ty = $ys[$j];
                $c->add(ChartCanvas::el('polyline', ['points' => self::fx($x0).','.self::fx($y0).' '.self::fx($x1).','.self::fx($y1).' '.self::fx($x2).','.self::fx($ty).' '.self::fx($x2 + $dir * $fs * 0.3).','.self::fx($ty), 'fill' => 'none', 'stroke' => $c->muted, 'stroke-width' => $k, 'stroke-opacity' => 0.8]));
                $tx = $x2 + $dir * $fs * 0.45;
                $room = $side === 'r' ? $box['x'] + $box['w'] - $tx : $tx - $box['x'];
                $nm = $slices[$e['i']]['name'];
                $anchor = $dir > 0 ? 'start' : 'end';
                if ($named && $labelsOn) {
                    $c->text($tx, $ty - $size * 0.55, ChartCanvas::fitText($nm, $size, $room, 600), ['size' => $size, 'anchor' => $anchor, 'weight' => 600]);
                    $c->text($tx, $ty + $size * 0.6, $lines[$e['i']]['val'], ['size' => $size, 'anchor' => $anchor, 'fill' => $c->muted]);
                } elseif ($named) {
                    $c->text($tx, $ty, ChartCanvas::fitText($nm, $size, $room, 600), ['size' => $size, 'anchor' => $anchor, 'weight' => 600]);
                } else {
                    $tiny = $lines[$e['i']]['share'] < 0.02;
                    $str = $tiny ? $nm.' '.$lines[$e['i']]['val'] : $lines[$e['i']]['val'];
                    $c->text($tx, $ty, ChartCanvas::fitText($str, $size, $room), ['size' => $size, 'anchor' => $anchor]);
                }
            }
        }
        if ($donut) {
            $innerW = $r * 1.7;
            $big = $opt['center'] ?? ($c->fmt)($total);
            $bs = min($fs * 2.2, $r * 0.7);
            while ($bs > 8 && ChartCanvas::textWidth($big, $bs, 700) > $innerW) {
                $bs -= 1;
            }
            $hasSub = ! isset($opt['center']) && $r > $fs * 2.2;
            $c->text($cx, $cy - ($hasSub ? $bs * 0.18 : 0), ChartCanvas::fitText($big, $bs, $innerW, 700), ['size' => $bs, 'anchor' => 'middle', 'weight' => 700]);
            if ($hasSub) {
                $c->text($cx, $cy + $bs * 0.75, 'Total', ['size' => min($fs * 0.9, $bs * 0.6), 'anchor' => 'middle', 'fill' => $c->muted]);
            }
        }
        $c->drawChrome();
    }

    // ------------------------------------------------------------------ scatter and bubble

    public static function scatter(ChartCanvas $c): void
    {
        $chart = $c->chart;
        $bubble = $chart['type'] === 'bubble';
        $opt = $chart['opt'];
        $fs = $c->fs;
        $k = $c->k;
        $series = $chart['data']['series'];
        $all = [];
        foreach ($series as $si => $s) {
            foreach ($s['points'] ?? [] as $i => $p) {
                $all[] = $p + ['si' => $si, 'i' => $i];
            }
        }
        $legend = [];
        foreach ($series as $i => $s) {
            $legend[] = ['name' => $s['name'], 'color' => $c->colors[$i], 'kind' => 'marker', 'marker' => $c->markerShape($i), 'opacity' => $bubble ? 0.6 : 1.0];
        }
        if ($bubble) {
            $c->notes[] = 'Bubble area is proportional to size.';
        }
        $box = $c->planFrame($legend, ! $all);
        if (! $all) {
            $c->drawEmpty($box, 'No points to chart yet.');
            $c->drawChrome();

            return;
        }
        $xsAll = array_column($all, 'x');
        $ysAll = array_column($all, 'y');
        $pad = function (float $a, float $b): array {
            $d = ($b - $a) * 0.06;

            return $d > 0 ? [$a - $d, $b + $d] : [$a, $b];
        };
        [$xl, $xh] = $pad(min($xsAll), max($xsAll));
        [$yl, $yh] = $pad(min($ysAll), max($ysAll));
        if (min($ysAll) >= 0 && min($ysAll) < 0.4 * max($ysAll)) {
            $yl = 0.0;
        }
        $xfmtOpt = ['fmt' => in_array($opt['fmt'], ['pct', 'cur'], true) ? 'num' : $opt['fmt']] + (isset($opt['dec']) ? ['dec' => $opt['dec']] : []);
        $plot = ['x' => $box['x'], 'y' => $box['y'], 'w' => $box['w'], 'h' => $box['h']];
        $yMin = $opt['ax']['y']['min'] ?? null;
        $yMax = $opt['ax']['y']['max'] ?? null;
        $xt = $yt = $yFmt = $xFmt = null;
        for ($pass = 0; $pass < 3; $pass++) {
            $yt = ChartData::niceTicks($yMin ?? $yl, $yMax ?? $yh, (int) self::clamp(floor($plot['h'] / ($fs * 3.1)), 2, 8), $yMin !== null || $yMax !== null);
            $yFmt = ChartData::tickFormatter($opt, $yt['step']);
            $xt = ChartData::niceTicks($xl, $xh, (int) self::clamp(floor($plot['w'] / ($fs * 6)), 2, 9));
            $xFmt = ChartData::tickFormatter($xfmtOpt, $xt['step']);
            $yw = max(array_map(fn ($t) => ChartCanvas::textWidth($yFmt($t), $fs * 0.95), $yt['ticks']));
            $left = $box['x'] + (isset($opt['ax']['y']['title']) ? $fs * 1.6 : 0) + ($opt['ax']['y']['show'] ? $yw + $fs * 0.8 : $fs * 0.3);
            $right = $box['x'] + $box['w'] - max($fs * 1.0, ChartCanvas::textWidth($xFmt($xt['ticks'][count($xt['ticks']) - 1]), $fs * 0.95) / 2);
            $bottom = $box['y'] + $box['h'] - ($opt['ax']['x']['show'] ? $fs * 1.9 : $fs * 0.3) - (isset($opt['ax']['x']['title']) ? $fs * 1.5 : 0);
            $top = $box['y'] + $fs * 0.9;
            $plot = ['x' => $left, 'y' => $top, 'w' => max(20.0, $right - $left), 'h' => max(20.0, $bottom - $top)];
        }
        $xmin = $xt['min'];
        $xmax = $xt['max'] > $xt['min'] ? $xt['max'] : $xt['min'] + 1;
        $ymin = $yMin ?? $yt['min'];
        $ymax = $yMax ?? ($yt['max'] > $yt['min'] ? $yt['max'] : $yt['min'] + 1);
        $rMax = max($fs * 0.8, min($plot['w'], $plot['h']) / 9);
        $ins = $bubble ? $rMax * 0.9 : max(4.0, $fs * 0.3) + 1;
        $X = fn (float $v): float => $plot['x'] + $ins + (($v - $xmin) / ($xmax - $xmin)) * ($plot['w'] - 2 * $ins);
        $Y = fn (float $v): float => $plot['y'] + $plot['h'] - $ins - ((self::clamp($v, $ymin, $ymax) - $ymin) / ($ymax - $ymin)) * ($plot['h'] - 2 * $ins);
        foreach ($yt['ticks'] as $t) {
            if ($t < $ymin || $t > $ymax) {
                continue;
            }
            $y = $Y($t);
            if ($opt['grid']) {
                $c->add(ChartCanvas::el('line', ['x1' => $plot['x'], 'y1' => $y, 'x2' => $plot['x'] + $plot['w'], 'y2' => $y, 'stroke' => $c->line, 'stroke-width' => $k]));
            }
            if ($opt['ax']['y']['show']) {
                $c->text($plot['x'] - $fs * 0.6, $y, $yFmt($t), ['size' => $fs * 0.95, 'anchor' => 'end', 'fill' => $c->muted]);
            }
        }
        foreach ($xt['ticks'] as $t) {
            $x = $X($t);
            if ($opt['grid']) {
                $c->add(ChartCanvas::el('line', ['x1' => $x, 'y1' => $plot['y'], 'x2' => $x, 'y2' => $plot['y'] + $plot['h'], 'stroke' => $c->line, 'stroke-width' => $k]));
            }
            if ($opt['ax']['x']['show']) {
                $c->text($x, $plot['y'] + $plot['h'] + $fs * 1.15, $xFmt($t), ['size' => $fs * 0.95, 'anchor' => 'middle', 'fill' => $c->muted]);
            }
        }
        $c->add(ChartCanvas::el('line', ['x1' => $plot['x'], 'y1' => $plot['y'] + $plot['h'], 'x2' => $plot['x'] + $plot['w'], 'y2' => $plot['y'] + $plot['h'], 'stroke' => $c->muted, 'stroke-width' => $k]));
        $c->add(ChartCanvas::el('line', ['x1' => $plot['x'], 'y1' => $plot['y'], 'x2' => $plot['x'], 'y2' => $plot['y'] + $plot['h'], 'stroke' => $c->muted, 'stroke-width' => $k]));
        if (isset($opt['ax']['y']['title'])) {
            $c->text($box['x'] + $fs * 0.6, $plot['y'] + $plot['h'] / 2, ChartCanvas::fitText($opt['ax']['y']['title'], $fs * 0.9, $plot['h'] - 10), ['size' => $fs * 0.9, 'anchor' => 'middle', 'fill' => $c->muted, 'rotate' => -90]);
        }
        if (isset($opt['ax']['x']['title'])) {
            $c->text($plot['x'] + $plot['w'] / 2, $box['y'] + $box['h'] - $fs * 0.6, ChartCanvas::fitText($opt['ax']['x']['title'], $fs * 0.9, $plot['w'] - 10), ['size' => $fs * 0.9, 'anchor' => 'middle', 'fill' => $c->muted]);
        }
        $rVals = array_map(fn ($p) => $p['r'] ?? 0.0, $all);
        $rMaxVal = max(array_merge([0.0], $rVals));
        $radius = function (array $p) use ($bubble, $fs, $rMaxVal, $rMax): float {
            if (! $bubble) {
                return max(4.0, $fs * 0.3);
            }
            if (! $rMaxVal) {
                return max(5.0, $rMax * 0.4);
            }

            return max(3.0, $rMax * sqrt(($p['r'] ?? 0.0) / $rMaxVal));
        };
        $order = $all;
        if ($bubble) {
            usort($order, fn ($a, $b) => $radius($b) <=> $radius($a));
        }
        $boxes = [];
        $els = '';
        foreach ($order as $p) {
            $color = $c->colors[$p['si']];
            $r = $radius($p);
            $cx = $X($p['x']);
            $cy = $Y($p['y']);
            $boxes[] = ['p' => $p, 'cx' => $cx, 'cy' => $cy, 'r' => $r];
            $els .= $bubble
                ? $c->marker('circle', $cx, $cy, $r, $color, 0.6, $color, max(1.5, $k * 1.5))
                : $c->marker($c->markerShape($p['si']), $cx, $cy, $r, $color, 0.85, $c->surface, max(1.0, $k));
        }
        $c->add($els);
        if ($opt['labels'] !== 'none' && count($all) <= 40) {
            $size = $fs * 0.85;
            $placer = ChartCanvas::placer(['x' => $plot['x'] - 2, 'y' => $plot['y'] - $fs, 'w' => $plot['w'] + $fs * 2, 'h' => $plot['h'] + $fs * 1.2], array_map(fn ($b) => ['x' => $b['cx'] - $b['r'], 'y' => $b['cy'] - $b['r'], 'w' => $b['r'] * 2, 'h' => $b['r'] * 2], $boxes));
            $f = ChartData::formatter($opt);
            foreach ($boxes as $b) {
                $str = $opt['labels'] === 'percent' ? '' : $f($b['p']['y']);
                if ($str === '') {
                    continue;
                }
                $cands = [
                    ['x' => $b['cx'] + $b['r'] + 3, 'y' => $b['cy'], 'anchor' => 'start'],
                    ['x' => $b['cx'], 'y' => $b['cy'] - $b['r'] - $size * 0.7, 'anchor' => 'middle'],
                    ['x' => $b['cx'] - $b['r'] - 3, 'y' => $b['cy'], 'anchor' => 'end'],
                    ['x' => $b['cx'], 'y' => $b['cy'] + $b['r'] + $size * 0.7, 'anchor' => 'middle'],
                ];
                $own = ['x' => $b['cx'] - $b['r'], 'y' => $b['cy'] - $b['r'], 'w' => $b['r'] * 2, 'h' => $b['r'] * 2];
                foreach ($cands as $cd) {
                    $tb = ChartCanvas::textBox($cd['x'], $cd['y'], $str, $size, $cd['anchor']);
                    $saved = $placer->blocked;
                    $placer->blocked = array_values(array_filter($placer->blocked, fn ($q) => $q !== $own));
                    $ok = $placer->tryPlace($tb);
                    $placer->blocked = $saved;
                    if ($ok) {
                        $c->text($cd['x'], $cd['y'], $str, ['size' => $size, 'anchor' => $cd['anchor']]);
                        break;
                    }
                }
            }
        }
        $c->drawChrome();
    }

    // ------------------------------------------------------------------ radar

    public static function radar(ChartCanvas $c): void
    {
        $chart = $c->chart;
        $opt = $chart['opt'];
        $data = $chart['data'];
        $fs = $c->fs;
        $k = $c->k;
        $n = count($data['categories']);
        $series = $data['series'];
        $legend = $c->legendForSeries(array_map(fn () => 'line', $series));
        $hasData = $n >= 3;
        $any = false;
        foreach ($series as $s) {
            foreach ($s['values'] as $v) {
                if ($v !== null) {
                    $any = true;
                }
            }
        }
        $hasData = $hasData && $any;
        $negs = 0;
        foreach ($series as $s) {
            $negs += count(array_filter($s['values'], fn ($v) => $v !== null && $v < 0));
        }
        if ($negs && $hasData) {
            $c->notes[] = 'Negative values are drawn at the centre.';
        }
        $box = $c->planFrame($legend, ! $hasData);
        if (! $hasData) {
            $c->drawEmpty($box, $n < 3 ? 'A radar chart needs at least 3 categories.' : 'No values to chart yet.');
            $c->drawChrome();

            return;
        }
        $size = $fs * 0.95;
        $maxV = 0.0;
        foreach ($series as $s) {
            foreach ($s['values'] as $v) {
                if ($v !== null && $v > $maxV) {
                    $maxV = $v;
                }
            }
        }
        $nt = ChartData::niceTicks(0, $maxV ?: 1, 5);
        $top = $nt['max'];
        $rings = array_values(array_filter($nt['ticks'], fn ($t) => $t > 0));
        $fmt = ChartData::tickFormatter($opt, $nt['step']);
        $labelW = min($box['w'] * 0.24, max(array_map(fn ($cat) => ChartCanvas::textWidth($cat, $size), $data['categories'])) + 2);
        $R = max(6.0, min($box['w'] / 2 - $labelW - $fs * 0.8, $box['h'] / 2 - $fs * 2.0));
        $cx = $box['x'] + $box['w'] / 2;
        $cy = $box['y'] + $box['h'] / 2;
        $ang = fn (int $i): float => -M_PI / 2 + ($i * 2 * M_PI) / $n;
        $pt = function (int $i, float $v) use ($top, $R, $cx, $cy, $ang): array {
            $r = (self::clamp($v, 0, $top) / $top) * $R;

            return [$cx + $r * cos($ang($i)), $cy + $r * sin($ang($i))];
        };
        foreach ($rings as $t) {
            $pts = [];
            for ($i = 0; $i < $n; $i++) {
                $p = $pt($i, $t);
                $pts[] = self::fx($p[0]).','.self::fx($p[1]);
            }
            $c->add(ChartCanvas::el('polygon', ['points' => implode(' ', $pts), 'fill' => 'none', 'stroke' => $c->line, 'stroke-width' => $k]));
        }
        for ($i = 0; $i < $n; $i++) {
            [$x, $y] = $pt($i, $top);
            $c->add(ChartCanvas::el('line', ['x1' => $cx, 'y1' => $cy, 'x2' => $x, 'y2' => $y, 'stroke' => $c->line, 'stroke-width' => $k]));
        }
        foreach ($rings as $t) {
            [$x, $y] = $pt(0, $t);
            $c->text($x + $fs * 0.35, $y - $fs * 0.05, $fmt($t), ['size' => $fs * 0.75, 'fill' => $c->muted]);
        }
        $anchors = [];
        foreach ($series as $si => $s) {
            $color = $c->colors[$si];
            $pts = [];
            for ($i = 0; $i < $n; $i++) {
                $pts[] = $pt($i, $s['values'][$i] ?? 0.0);
            }
            $d = 'M'.implode('L', array_map(fn ($p) => self::fx($p[0]).' '.self::fx($p[1]), $pts)).'z';
            $c->add(ChartCanvas::el('path', ['d' => $d, 'fill' => $color, 'fill-opacity' => count($series) > 2 ? 0.1 : 0.16, 'stroke' => $color, 'stroke-width' => max(2.0, $k * 2), 'stroke-linejoin' => 'round', 'stroke-dasharray' => $c->dash($si)]));
            if ($n <= 16) {
                foreach ($pts as $i => $p) {
                    if (($s['values'][$i] ?? null) === null) {
                        continue;
                    }
                    $c->add($c->marker($c->markerShape($si), $p[0], $p[1], max(3.5, $fs * 0.24), $color, 1.0, $c->surface, max(1.5, $k * 1.5)));
                    $anchors[] = ['x' => $p[0], 'y' => $p[1], 'v' => $s['values'][$i]];
                }
            }
        }
        $blocked = [];
        foreach ($data['categories'] as $i => $cat) {
            $a = $ang($i);
            $x = $cx + ($R + $fs * 0.8) * cos($a);
            $y = $cy + ($R + $fs * 0.8) * sin($a);
            $cos = cos($a);
            $anchor = $cos > 0.3 ? 'start' : ($cos < -0.3 ? 'end' : 'middle');
            $room = $anchor === 'middle' ? min($box['w'] * 0.3, $labelW * 1.6) : ($anchor === 'start' ? $box['x'] + $box['w'] - $x : $x - $box['x']);
            $lines = ChartCanvas::wrapText($cat, $size, max(30.0, min($room, $labelW + $fs)), 2);
            $sin = sin($a);
            $baseY = $y + ($sin > 0.5 ? $size * 0.5 : ($sin < -0.5 ? -$size * 0.5 * (count($lines) - 1) - $size * 0.2 : 0)) - ((count($lines) - 1) * $size * 1.15) / 2 * (abs($sin) <= 0.5 ? 1 : 0);
            foreach ($lines as $li => $ln) {
                $ly = $baseY + $li * $size * 1.15;
                $c->text($x, $ly, $ln, ['size' => $size, 'anchor' => $anchor]);
                $blocked[] = ChartCanvas::textBox($x, $ly, $ln, $size, $anchor);
            }
        }
        if ($opt['labels'] !== 'none' && count($series) <= 2 && $n <= 10) {
            $placer = ChartCanvas::placer($box, $blocked);
            $ls = $fs * 0.8;
            foreach ($anchors as $a) {
                if ($opt['labels'] === 'percent') {
                    continue;
                }
                $str = ($c->fmt)($a['v']);
                $dx = $a['x'] - $cx;
                $dy = $a['y'] - $cy;
                $len = hypot($dx, $dy) ?: 1;
                $x = $a['x'] + ($dx / $len) * ($fs * 0.9);
                $y = $a['y'] + ($dy / $len) * ($fs * 0.9);
                $anchor = $dx > 4 ? 'start' : ($dx < -4 ? 'end' : 'middle');
                if ($placer->tryPlace(ChartCanvas::textBox($x, $y, $str, $ls, $anchor))) {
                    $c->text($x, $y, $str, ['size' => $ls, 'anchor' => $anchor]);
                }
            }
        }
        $c->drawChrome();
    }

    // ------------------------------------------------------------------ funnel

    public static function funnel(ChartCanvas $c): void
    {
        $chart = $c->chart;
        $opt = $chart['opt'];
        $fs = $c->fs;
        $cats = $chart['data']['categories'];
        $n = count($cats);
        $vals = array_map(fn ($v) => $v !== null && $v > 0 ? $v : 0.0, $chart['data']['series'][0]['values'] ?? []);
        $maxV = $vals ? max($vals) : 0.0;
        $box = $c->planFrame([], true);
        if (! $n || $maxV <= 0) {
            $c->drawEmpty($box, $n ? 'No values above zero to show.' : 'No data to chart yet.');
            $c->drawChrome();

            return;
        }
        $size = $fs * 0.95;
        $nameW = min($box['w'] * 0.3, max(array_map(fn ($cat) => ChartCanvas::textWidth($cat, $size), $cats)) + $fs * 0.5);
        $x0 = $box['x'] + $nameW + $fs * 0.8;
        $maxBar = max(30.0, $box['x'] + $box['w'] - $x0);
        $gapY = max(3.0, $fs * 0.35);
        $rowH = ($box['h'] - $gapY * ($n - 1)) / $n;
        $barH = max(4.0, min($rowH, $fs * 3.4));
        $solid = $c->colors[0] ?? $c->theme->chartColor(0);
        $first = $vals[0] ?: $maxV;
        $totalH = $n * $barH + ($n - 1) * $gapY;
        $y0 = $box['y'] + ($box['h'] - $totalH) / 2;
        foreach ($cats as $i => $cat) {
            $w = max(2.0, ($vals[$i] / $maxV) * $maxBar);
            $cx = $x0 + $maxBar / 2;
            $y = $y0 + $i * ($barH + $gapY);
            $c->add(ChartCanvas::el('rect', ['x' => $cx - $w / 2, 'y' => $y, 'width' => $w, 'height' => $barH, 'rx' => min(4.0, $barH / 2), 'fill' => $solid]));
            $c->text($box['x'], $y + $barH / 2, ChartCanvas::fitText($cat, $size, $nameW), ['size' => $size]);
            if ($opt['labels'] !== 'none') {
                $str = $opt['labels'] === 'percent' ? ChartData::pctShare($first ? $vals[$i] / $first : 0.0) : ($c->fmt)($vals[$i]);
                $tw = ChartCanvas::textWidth($str, $size, 600);
                if ($tw + $fs * 0.8 <= $w) {
                    $c->text($cx, $y + $barH / 2, $str, ['size' => $size, 'anchor' => 'middle', 'weight' => 600, 'fill' => ChartCanvas::inkOn($solid)]);
                } else {
                    $c->text($cx + $w / 2 + $fs * 0.4, $y + $barH / 2, $str, ['size' => $size, 'weight' => 600]);
                }
            }
        }
        $c->drawChrome();
    }

    // ------------------------------------------------------------------ kpi tiles

    public static function kpi(ChartCanvas $c): void
    {
        $chart = $c->chart;
        $fs = $c->fs;
        $items = ChartData::kpiItems($chart);
        $box = $c->planFrame([], true);
        if (! $items) {
            $c->drawEmpty($box, 'No figures to show yet.');
            $c->drawChrome();

            return;
        }
        $n = count($items);
        $aspect = $box['w'] / max(1.0, $box['h']);
        $cols = $n <= 3 ? $n : ($n === 4 ? ($aspect > 2.4 ? 4 : 2) : (int) ceil(sqrt($n * $aspect)));
        $cols = (int) self::clamp($cols, 1, 6);
        while ($cols > 1 && ($box['w'] - ($cols - 1) * $fs) / $cols < $fs * 7) {
            $cols--;
        }
        $rows = (int) ceil($n / $cols);
        $gap = $fs * 0.9;
        $tw = ($box['w'] - $gap * ($cols - 1)) / $cols;
        $th = min(($box['h'] - $gap * ($rows - 1)) / $rows, $fs * 11);
        $gridH = $rows * $th + ($rows - 1) * $gap;
        $gy = $box['y'] + max(0.0, ($box['h'] - $gridH) / 2);
        $pad = min($fs * 1.1, $tw * 0.1);
        $surface = (string) $c->theme->hex('@surface');
        $fmt = $c->fmt;
        foreach ($items as $i => $it) {
            $r = intdiv($i, $cols);
            $col = $i % $cols;
            $x = $box['x'] + $col * ($tw + $gap);
            $y = $gy + $r * ($th + $gap);
            $inner = $tw - 2 * $pad;
            $c->add(ChartCanvas::el('rect', ['x' => $x, 'y' => $y, 'width' => $tw, 'height' => $th, 'rx' => min($fs * 0.7, 14.0), 'fill' => $surface]));
            $labelSize = $fs * 0.95;
            $hasDelta = array_key_exists('delta', $it);
            $noteLines = ! empty($it['note']) ? ChartCanvas::wrapText($it['note'], $fs * 0.8, $inner, $th > $fs * 6 ? 2 : 1) : [];
            $deltaH = $hasDelta ? $fs * 1.45 : 0.0;
            $noteH = count($noteLines) * $fs * 0.8 * 1.25;
            $labelH = $labelSize * 1.3;
            $vs = self::clamp($th * 0.3, $fs * 1.3, $fs * 3.4);
            $unit = (string) ($it['unit'] ?? '');
            $vstr = is_float($it['value'] ?? null) || is_int($it['value'] ?? null) ? $fmt((float) $it['value']) : (string) ($it['value'] ?? '');
            $fitVal = function (string $str) use ($vs, $fs, $unit, $inner): float {
                $s = $vs;
                while ($s > $fs * 0.9 && ChartCanvas::textWidth($str.($unit !== '' ? ' '.$unit : ''), $s, 700) > $inner) {
                    $s -= 1;
                }

                return $s;
            };
            $size = $fitVal($vstr);
            $contentH = $labelH + $size * 1.15 + $deltaH + ($noteH ? $noteH + $fs * 0.2 : 0);
            $cy = $y + max($pad * 0.6, ($th - $contentH) / 2);
            $c->text($x + $pad, $cy + $labelH / 2, ChartCanvas::fitText((string) ($it['label'] ?? ''), $labelSize, $inner), ['size' => $labelSize, 'fill' => $c->muted]);
            $cy += $labelH;
            $c->text($x + $pad, $cy + $size * 0.58, ChartCanvas::fitText($vstr, $size, $inner, 700), ['size' => $size, 'weight' => 700]);
            if ($unit !== '') {
                $vw = ChartCanvas::textWidth($vstr, $size, 700);
                if ($vw + ChartCanvas::textWidth($unit, $size * 0.5) + 6 <= $inner) {
                    $c->text($x + $pad + $vw + $size * 0.15, $cy + $size * 0.58 + $size * 0.1, $unit, ['size' => $size * 0.5, 'fill' => $c->muted]);
                }
            }
            $cy += $size * 1.15;
            if ($hasDelta) {
                $dir = ChartData::deltaDirection($it['delta']);
                $good = $dir === 0 ? null : (($dir > 0) === (($it['good'] ?? 'up') !== 'down'));
                $color = $dir === 0 ? $c->muted : ($good ? (string) $c->theme->hex('@good') : (string) $c->theme->hex('@bad'));
                $ds = $fs * 0.95;
                $ty = $cy + $deltaH / 2;
                $tri = $ds * 0.34;
                if ($dir !== 0) {
                    $pts = $dir > 0
                        ? [[$x + $pad, $ty + $tri * 0.8], [$x + $pad + $tri * 2, $ty + $tri * 0.8], [$x + $pad + $tri, $ty - $tri * 0.9]]
                        : [[$x + $pad, $ty - $tri * 0.8], [$x + $pad + $tri * 2, $ty - $tri * 0.8], [$x + $pad + $tri, $ty + $tri * 0.9]];
                    $c->add(ChartCanvas::el('polygon', ['points' => implode(' ', array_map(fn ($p) => self::fx($p[0]).','.self::fx($p[1]), $pts)), 'fill' => $color]));
                } else {
                    $c->add(ChartCanvas::el('rect', ['x' => $x + $pad, 'y' => $ty - 1, 'width' => $tri * 2, 'height' => 2, 'fill' => $color]));
                }
                $c->text($x + $pad + $tri * 2 + $ds * 0.4, $ty, ChartCanvas::fitText(ChartData::deltaText($it['delta']), $ds, $inner - $tri * 2 - $ds * 0.4, 600), ['size' => $ds, 'fill' => $color, 'weight' => 600]);
                $cy += $deltaH;
            }
            foreach ($noteLines as $li => $ln) {
                $c->text($x + $pad, $cy + $fs * 0.2 + $li * $fs * 0.8 * 1.25 + $fs * 0.4, $ln, ['size' => $fs * 0.8, 'fill' => $c->muted]);
            }
        }
        $c->drawChrome();
    }
}
