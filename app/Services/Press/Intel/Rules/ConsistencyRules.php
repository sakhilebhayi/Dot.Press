<?php

namespace App\Services\Press\Intel\Rules;

use App\Services\Press\Intel\Colour;
use App\Services\Press\Intel\DeckContext;
use App\Services\Press\Intel\Ops;

/** Fonts, type scale, colours, alignment, margins, spacing, bounds and overlaps. */
final class ConsistencyRules extends Rule
{
    private const NEAR_MIN = 0.5;

    private const NEAR_MAX = 6.0;

    public function run(DeckContext $ctx): array
    {
        return [
            ...$this->fonts($ctx),
            ...$this->sizes($ctx),
            ...$this->roleSizes($ctx),
            ...$this->colours($ctx),
            ...$this->alignment($ctx),
            ...$this->margins($ctx),
            ...$this->spacing($ctx),
            ...$this->bounds($ctx),
            ...$this->overlaps($ctx),
        ];
    }

    /** Text elements that count for type checks. @return list<array{0: array<string, mixed>, 1: array<string, mixed>}> pairs of [slide, el] */
    private function texts(DeckContext $ctx): array
    {
        $out = [];
        foreach ($ctx->slides as $s) {
            foreach ($ctx->textEls($s) as $e) {
                $out[] = [$s, $e];
            }
        }

        return $out;
    }

    private function fonts(DeckContext $ctx): array
    {
        $count = [];
        foreach ($this->texts($ctx) as [$s, $e]) {
            $fam = mb_strtolower(trim($e['family']));
            $count[$fam] = ($count[$fam] ?? 0) + 1;
        }
        if (count($count) <= 3) {
            return [];
        }
        arsort($count);
        $keep = array_slice(array_keys($count), 0, 3);
        // Rare families become the most used one, written the way its elements write it (token or name).
        $target = '@body';
        foreach ($this->texts($ctx) as [$s, $e]) {
            if (mb_strtolower(trim($e['family'])) === $keep[0]) {
                $target = $e['font_raw'];
                break;
            }
        }
        $ops = [];
        $slides = [];
        foreach ($this->texts($ctx) as [$s, $e]) {
            if (! in_array(mb_strtolower(trim($e['family'])), $keep, true) && ! $e['lock']) {
                $ops[] = Ops::elSet($s['id'], $e['id'], ['font' => $target]);
                $slides[$s['id']] = true;
            }
        }
        $list = implode(', ', array_map(fn ($f, $n) => "{$f} ({$n})", array_keys($count), $count));

        return [$this->issue('font-families', 'consistency', 'warn', null, null,
            count($count).' font families are used in this deck: '.$list.'.',
            'More than three families makes a deck look assembled from different sources. Two (one for headings, one for body) is usual.',
            Ops::fix('Use the main font for the '.count($ops).' elements in the rarest families', $ops),
            ['slides' => array_keys($slides)])];
    }

    private function sizes(DeckContext $ctx): array
    {
        $count = [];
        foreach ($this->texts($ctx) as [$s, $e]) {
            if ($e['size_set']) {
                $k = (string) round($e['size']);
                $count[$k] = ($count[$k] ?? 0) + 1;
            }
        }
        if (count($count) <= 6) {
            return [];
        }
        arsort($count);
        $keep = array_map('floatval', array_slice(array_keys($count), 0, 6));
        $ops = [];
        foreach ($this->texts($ctx) as [$s, $e]) {
            if (! $e['size_set'] || $e['lock'] || in_array((float) round($e['size']), $keep, true)) {
                continue;
            }
            $nearest = $keep[0];
            foreach ($keep as $k) {
                if (abs($k - $e['size']) < abs($nearest - $e['size'])) {
                    $nearest = $k;
                }
            }
            $ops[] = Ops::elSet($s['id'], $e['id'], ['size' => $nearest]);
        }

        return [$this->issue('type-scale', 'consistency', 'warn', null, null,
            count($count).' different text sizes are used in this deck (sizes: '.implode(', ', array_keys($count)).').',
            'A type scale of six sizes or fewer keeps hierarchy readable. Every extra size weakens the contrast between levels.',
            Ops::fix('Snap '.count($ops).' elements to the six most used sizes', $ops))];
    }

    private function roleSizes(DeckContext $ctx): array
    {
        $groups = [];
        foreach ($ctx->slides as $s) {
            foreach ($ctx->textEls($s) as $e) {
                $role = $e['role'];
                if ($s['title_el'] !== null && $s['title_el']['id'] === $e['id']) {
                    $role = 'title';
                }
                if (in_array($role, ['title', 'body', 'subtitle'], true) && $e['size_set']) {
                    $groups[$role][] = [$s, $e];
                }
            }
        }
        $out = [];
        foreach ($groups as $role => $items) {
            if (count($items) < 3) {
                continue;
            }
            $freq = [];
            foreach ($items as [$s, $e]) {
                $freq[(string) round($e['size'])] = ($freq[(string) round($e['size'])] ?? 0) + 1;
            }
            arsort($freq);
            $mode = (float) array_key_first($freq);
            if ($freq[(string) $mode] / count($items) < 0.5) {
                continue;
            }
            foreach ($items as [$s, $e]) {
                if (abs($e['size'] - $mode) / $mode > 0.04 && $e['fit'] !== 'shrink') {
                    $out[] = $this->issue('role-size', 'consistency', 'warn', $s, $e,
                        ucfirst($role)." text is {$e['size']} here but {$mode} on most slides.",
                        'The same kind of text should keep one size from slide to slide, or the deck looks uneven when you page through it.',
                        $e['lock'] ? null : Ops::fix("Set this {$role} to {$mode}", [Ops::elSet($s['id'], $e['id'], ['size' => $mode])]));
                }
            }
        }

        return $out;
    }

    private function colours(DeckContext $ctx): array
    {
        $out = [];
        foreach ($ctx->slides as $s) {
            foreach ($ctx->visible($s) as $e) {
                $patch = [];
                $found = [];
                foreach (['color' => 'text colour', 'fill' => 'fill', 'stroke' => 'outline'] as $prop => $name) {
                    $v = $e[$prop];
                    if (! is_string($v) || $v === '' || str_starts_with($v, '@')) {
                        continue;
                    }
                    if ($prop === 'color' && ! $e['has_text']) {
                        continue;
                    }
                    $rgb = Colour::parse($v);
                    if ($rgb === null || Colour::isOnTheme($v, $ctx->palette)) {
                        continue;
                    }
                    $near = Colour::nearestToken($rgb, $ctx->palette);
                    $patch[$prop] = $near['token'];
                    $found[] = "{$name} {$v} (nearest theme colour {$near['token']})";
                }
                if ($found !== []) {
                    $out[] = $this->issue('off-theme-colour', 'consistency', 'info', $s, $e,
                        ucfirst($this->label($e)).' uses '.implode(' and ', $found).', which is not in the theme.',
                        'Hex colours keep their value when the theme changes, so off-theme colours stay behind when you restyle the deck. Ignore this if the colour is deliberate (a logo colour, say).',
                        $e['lock'] ? null : Ops::fix('Switch to the nearest theme colour', [Ops::elSet($s['id'], $e['id'], $patch)]));
                }
            }
        }

        return $out;
    }

    /** Edge definitions: name => [axis, value fn, set fn]. */
    private function edges(): array
    {
        return [
            'left' => ['x', fn ($e) => $e['x'], fn ($e, $t) => ['x' => $t]],
            'top' => ['y', fn ($e) => $e['y'], fn ($e, $t) => ['y' => $t]],
            'right' => ['x', fn ($e) => $e['x'] + $e['w'], fn ($e, $t) => ['x' => $t - $e['w']]],
            'bottom' => ['y', fn ($e) => $e['y'] + $e['h'], fn ($e, $t) => ['y' => $t - $e['h']]],
            'centre' => ['x', fn ($e) => $e['x'] + $e['w'] / 2, fn ($e, $t) => ['x' => $t - $e['w'] / 2]],
            'middle' => ['y', fn ($e) => $e['y'] + $e['h'] / 2, fn ($e, $t) => ['y' => $t - $e['h'] / 2]],
        ];
    }

    /** Elements worth aligning on a slide. @return list<array<string, mixed>> */
    private function layoutEls(DeckContext $ctx, array $s): array
    {
        return array_values(array_filter($ctx->visible($s), fn ($e) => ! $ctx->isFullBleed($e) && ! $ctx->isLine($e)
            && abs(fmod($e['rot'], 360)) < 0.01 && $e['w'] >= 8 && $e['h'] >= 8
            && in_array($e['type'], ['text', 'image', 'shape', 'chart', 'table', 'video', 'icon'], true)));
    }

    private function alignment(DeckContext $ctx): array
    {
        $out = [];
        foreach ($ctx->slides as $s) {
            $els = $this->layoutEls($ctx, $s);
            if (count($els) < 2) {
                continue;
            }
            $moved = [];
            foreach ($this->edges() as $edge => [$axis, $value, $set]) {
                $vals = [];
                foreach ($els as $e) {
                    $vals[] = [$value($e), $e];
                }
                usort($vals, fn ($a, $b) => $a[0] <=> $b[0]);
                $cluster = [];
                $clusters = [];
                foreach ($vals as $v) {
                    if ($cluster !== [] && $v[0] - end($cluster)[0] > self::NEAR_MAX) {
                        $clusters[] = $cluster;
                        $cluster = [];
                    }
                    $cluster[] = $v;
                }
                if ($cluster !== []) {
                    $clusters[] = $cluster;
                }
                foreach ($clusters as $c) {
                    if (count($c) < 2) {
                        continue;
                    }
                    $distinct = array_unique(array_map(fn ($v) => (string) $this->round($v[0]), $c));
                    $spread = $c[count($c) - 1][0] - $c[0][0];
                    if (count($distinct) < 2 || $spread < self::NEAR_MIN || $spread > self::NEAR_MAX) {
                        continue;
                    }
                    $freq = array_count_values(array_map(fn ($v) => (string) $this->round($v[0]), $c));
                    arsort($freq);
                    $target = (float) array_key_first($freq);
                    $ops = [];
                    $names = [];
                    foreach ($c as [$val, $e]) {
                        $names[] = $this->label($e);
                        if (abs($this->round($val) - $target) < 0.01 || $e['lock'] || isset($moved[$e['id']][$axis])) {
                            continue;
                        }
                        $moved[$e['id']][$axis] = true;
                        $ops[] = Ops::elSet($s['id'], $e['id'], array_map(fn ($x) => $this->round($x), $set($e, $target)));
                    }
                    if ($ops === []) {
                        continue;
                    }
                    $first = $c[0][1];
                    $out[] = $this->issue('alignment', 'consistency', 'info', $s, $first,
                        ucfirst($edge).' edges are '.round($spread, 1).' units apart: '.implode(', ', array_slice($names, 0, 4)).'.',
                        'Edges that almost line up look like a mistake. Within a few units they should match exactly.',
                        Ops::fix('Snap '.count($ops).' element'.(count($ops) === 1 ? '' : 's').' to the '.$edge.' edge at '.$target, $ops),
                        ['elements' => array_map(fn ($v) => $v[1]['id'], $c)]);
                }
            }
        }

        return $out;
    }

    private function margins(DeckContext $ctx): array
    {
        $rows = [];
        foreach ($ctx->slides as $s) {
            if (str_starts_with($s['layout'], 'image-') || in_array($s['layout'], ['title', 'section', 'blank', 'quote', 'closing'], true)) {
                continue;
            }
            $els = array_values(array_filter($this->layoutEls($ctx, $s), fn ($e) => ! in_array($e['role'], ['footer', 'number', 'caption'], true)));
            if (count($els) < 2) {
                continue;
            }
            $minX = min(array_map(fn ($e) => $e['x'], $els));
            $maxR = max(array_map(fn ($e) => $e['x'] + $e['w'], $els));
            $rows[] = ['slide' => $s, 'els' => $els, 'L' => $minX, 'R' => $ctx->w - $maxR];
        }
        if (count($rows) < 2) {
            return [];
        }
        $hist = [];
        foreach ($rows as $r) {
            $k = (string) (round($r['L'] / 4) * 4);
            $hist[$k] = ($hist[$k] ?? 0) + 1;
        }
        arsort($hist);
        $modeKey = array_key_first($hist);
        $modal = $hist[$modeKey] >= 3 && $hist[$modeKey] / count($rows) >= 0.3 ? (float) $modeKey : null;

        $out = [];
        foreach ($rows as $r) {
            $s = $r['slide'];
            if ($modal !== null) {
                $dx = $modal - $r['L'];
                if (abs($dx) < 1 || abs($dx) > 60) {
                    continue;
                }
                $msg = 'The left margin here is '.round($r['L'], 1).' but most slides use '.$modal.'.';
            } else {
                $dx = ($r['R'] - $r['L']) / 2;
                if (abs($r['R'] - $r['L']) < 8 || abs($r['R'] - $r['L']) > 60) {
                    continue;
                }
                $msg = 'The left margin is '.round($r['L'], 1).' and the right margin '.round($r['R'], 1).': the content sits off centre.';
            }
            $maxRight = max(array_map(fn ($e) => $e['x'] + $e['w'], $r['els']));
            $ops = [];
            if ($maxRight + $dx <= $ctx->w && min(array_map(fn ($e) => $e['x'], $r['els'])) + $dx >= 0) {
                foreach ($r['els'] as $e) {
                    if (! $e['lock']) {
                        $ops[] = Ops::elSet($s['id'], $e['id'], ['x' => $this->round($e['x'] + $dx)]);
                    }
                }
            }
            $out[] = $this->issue('margins', 'consistency', 'info', $s, null, $msg,
                'Content that starts at a different place on each slide makes the deck jump when you page through it.',
                Ops::fix('Shift the content by '.round($dx, 1).' units', $ops));
        }

        return $out;
    }

    private function spacing(DeckContext $ctx): array
    {
        $out = [];
        foreach ($ctx->slides as $s) {
            $els = $this->layoutEls($ctx, $s);
            foreach ([['x', 'y', 'h', 'vertical'], ['y', 'x', 'w', 'horizontal']] as [$alignAxis, $flowAxis, $size, $dir]) {
                // group by a shared edge on the alignment axis
                usort($els, fn ($a, $b) => $a[$alignAxis] <=> $b[$alignAxis]);
                $groups = [];
                $cur = [];
                foreach ($els as $e) {
                    if ($cur !== [] && $e[$alignAxis] - $cur[0][$alignAxis] > self::NEAR_MAX) {
                        $groups[] = $cur;
                        $cur = [];
                    }
                    $cur[] = $e;
                }
                if ($cur !== []) {
                    $groups[] = $cur;
                }
                foreach ($groups as $g) {
                    if (count($g) < 3) {
                        continue;
                    }
                    usort($g, fn ($a, $b) => $a[$flowAxis] <=> $b[$flowAxis]);
                    $gaps = [];
                    $ok = true;
                    for ($i = 1; $i < count($g); $i++) {
                        $gap = $g[$i][$flowAxis] - ($g[$i - 1][$flowAxis] + $g[$i - 1][$size]);
                        if ($gap < -1) {
                            $ok = false;
                            break;
                        }
                        $gaps[] = $gap;
                    }
                    if (! $ok) {
                        continue;
                    }
                    $sorted = $gaps;
                    sort($sorted);
                    $median = $sorted[(int) floor((count($sorted) - 1) / 2)];
                    $spread = max($gaps) - min($gaps);
                    if ($median <= 0 || $spread <= self::NEAR_MAX || $spread > max(12.0, 0.5 * $median)) {
                        continue;
                    }
                    $ops = [];
                    $pos = $g[0][$flowAxis] + $g[0][$size];
                    for ($i = 1; $i < count($g); $i++) {
                        $newPos = $pos + $median;
                        if (abs($newPos - $g[$i][$flowAxis]) >= 0.5 && ! $g[$i]['lock']) {
                            $ops[] = Ops::elSet($s['id'], $g[$i]['id'], [$flowAxis => $this->round($newPos)]);
                        }
                        $pos = $newPos + $g[$i][$size];
                    }
                    $limit = $flowAxis === 'y' ? $ctx->h : $ctx->w;
                    $out[] = $this->issue('spacing', 'consistency', 'info', $s, $g[0],
                        'The gaps between these '.count($g).' stacked items range from '.round(min($gaps), 1).' to '.round(max($gaps), 1).' units.',
                        'Items in a column or row read as a set when the space between them is even.',
                        $pos <= $limit ? Ops::fix('Make every gap '.round($median, 1).' units', $ops) : null,
                        ['elements' => array_map(fn ($e) => $e['id'], $g), 'direction' => $dir]);
                }
            }
        }

        return $out;
    }

    private function bounds(DeckContext $ctx): array
    {
        $out = [];
        foreach ($ctx->slides as $s) {
            foreach ($ctx->visible($s) as $e) {
                if ($e['w'] <= 0 || $e['h'] <= 0) {
                    continue;
                }
                [$l, $t, $r, $b] = $ctx->bbox($e);
                $inter = max(0.0, min($r, $ctx->w) - max($l, 0)) * max(0.0, min($b, $ctx->h) - max($t, 0));
                $area = ($r - $l) * ($b - $t);
                $outside = $area > 0 ? 1 - $inter / $area : 0;
                if ($outside <= 0.005 && $l >= -1 && $t >= -1 && $r <= $ctx->w + 1 && $b <= $ctx->h + 1) {
                    continue;
                }
                $decorative = ! $e['has_text'] && in_array($e['type'], ['image', 'shape', 'video'], true);
                if ($decorative && $area >= 0.5 * $ctx->w * $ctx->h) {
                    continue;
                }
                if ($outside < 0.02 && $l >= -2 && $t >= -2 && $r <= $ctx->w + 2 && $b <= $ctx->h + 2) {
                    continue;
                }
                $severity = $e['has_text'] ? ($outside > 0.25 ? 'issue' : 'warn') : ($outside > 0.5 ? 'warn' : 'info');
                $nx = $e['w'] > $ctx->w ? 0.0 : max(0.0, min($e['x'], $ctx->w - $e['w']));
                $ny = $e['h'] > $ctx->h ? 0.0 : max(0.0, min($e['y'], $ctx->h - $e['h']));
                $patch = ['x' => $this->round($nx), 'y' => $this->round($ny)];
                if ($e['w'] > $ctx->w) {
                    $patch['w'] = $ctx->w;
                }
                if ($e['h'] > $ctx->h) {
                    $patch['h'] = $ctx->h;
                }
                $out[] = $this->issue('out-of-bounds', 'consistency', $severity, $s, $e,
                    ucfirst($this->label($e)).' extends outside the slide ('.round($outside * 100).'% of it is off the edge).',
                    'Anything beyond the slide edge is cut off when presenting and exporting.',
                    ($e['lock'] || abs(fmod($e['rot'], 360)) > 0.01) ? null : Ops::fix('Move it inside the slide', [Ops::elSet($s['id'], $e['id'], $patch)]));
            }
        }

        return $out;
    }

    private function overlaps(DeckContext $ctx): array
    {
        $out = [];
        foreach ($ctx->slides as $s) {
            $texts = array_values(array_filter($ctx->textEls($s), fn ($e) => $e['type'] === 'text' && $e['w'] > 0 && $e['h'] > 0));
            $n = count($texts);
            if ($n > 120) {
                continue;
            }
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $texts[$i];
                    $b = $texts[$j];
                    if ($a['group'] !== null && $a['group'] === $b['group']) {
                        continue;
                    }
                    [$al, $at, $ar, $ab] = $ctx->bbox($a);
                    [$bl, $bt, $br, $bb] = $ctx->bbox($b);
                    $w = min($ar, $br) - max($al, $bl);
                    $h = min($ab, $bb) - max($at, $bt);
                    if ($w <= 0 || $h <= 0) {
                        continue;
                    }
                    $min = min($a['w'] * $a['h'], $b['w'] * $b['h']);
                    if ($min <= 0 || ($w * $h) / $min < 0.2) {
                        continue;
                    }
                    $out[] = $this->issue('overlap', 'consistency', 'warn', $s, $a,
                        ucfirst($this->label($a)).' and '.$this->label($b).' overlap by '.round(($w * $h) / $min * 100).'% of the smaller box.',
                        'Overlapping text boxes hide each other or collide when the text is longer than planned.');
                }
            }
        }

        return $out;
    }
}
