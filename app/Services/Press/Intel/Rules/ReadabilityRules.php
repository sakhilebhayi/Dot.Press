<?php

namespace App\Services\Press\Intel\Rules;

use App\Services\Press\Intel\Colour;
use App\Services\Press\Intel\DeckContext;
use App\Services\Press\Intel\Ops;
use App\Services\Press\Intel\TextMetrics;

/** Minimum type size, WCAG contrast against the real background (gradients by their stops), long lines. */
final class ReadabilityRules extends Rule
{
    public function run(DeckContext $ctx): array
    {
        $out = [];
        foreach ($ctx->slides as $s) {
            foreach ($ctx->textEls($s) as $e) {
                array_push($out, ...$this->size($ctx, $s, $e));
                array_push($out, ...$this->contrast($ctx, $s, $e));
                array_push($out, ...$this->lines($ctx, $s, $e));
            }
        }

        return $out;
    }

    private function size(DeckContext $ctx, array $s, array $e): array
    {
        $small = in_array($e['role'], ['footer', 'number', 'caption'], true);
        $size = $e['size'];
        if ($e['fit'] === 'shrink') {
            $fit = TextFitRules::check($ctx, $e);
            if ($fit !== null && $fit['shrink_size'] !== null) {
                $size = min($size, $fit['shrink_size']);
            }
        }
        $ratio = $size / $ctx->h;
        $warnBelow = $small ? 0.0165 : 0.022;
        $issueBelow = $small ? 0.012 : 0.017;
        if ($ratio >= $warnBelow) {
            return [];
        }
        $target = $this->round($ctx->h * ($small ? 0.0185 : 0.025));

        return [$this->issue('min-type-size', 'readability', $ratio < $issueBelow ? 'issue' : 'warn', $s, $e,
            ucfirst($this->label($e)).' is '.round($size).' units high, '.round($ratio * 100, 1).'% of the slide height.',
            'Text this small is hard to read from the back of a room or on a phone. Body text should be at least about 2.5% of the slide height.',
            $e['lock'] ? null : Ops::fix('Raise the size to '.$target, [Ops::elSet($s['id'], $e['id'], ['size' => $target])]))];
    }

    /**
     * The background colours behind an element: its own fill, else the topmost element below it that
     * covers it, else the slide background. Null when it cannot be known (an image).
     *
     * @return list<array{0: float, 1: float, 2: float, 3?: float}>|null
     */
    private function backgrounds(DeckContext $ctx, array $s, array $e): ?array
    {
        $base = $this->slideBackground($ctx, $s);
        $own = Colour::fillColours($e['fill'], $ctx->palette);
        $cx = $e['x'] + $e['w'] / 2;
        $cy = $e['y'] + $e['h'] / 2;
        $under = null;
        if ($own === []) {
            foreach ($s['els'] as $o) {
                if ($o['z'] >= $e['z']) {
                    break;
                }
                if ($o['hide'] || $o['id'] === $e['id']) {
                    continue;
                }
                $covers = $o['x'] <= $cx && $cx <= $o['x'] + $o['w'] && $o['y'] <= $cy && $cy <= $o['y'] + $o['h'];
                if (! $covers) {
                    continue;
                }
                if ($o['type'] === 'image' || $o['type'] === 'video') {
                    $under = 'unknown';

                    continue;
                }
                $f = Colour::fillColours($o['fill'], $ctx->palette);
                if ($f !== [] && ($o['type'] === 'shape' || $o['type'] === 'text' || $o['type'] === 'table')) {
                    $under = $f;
                }
            }
        }
        if ($own !== []) {
            $layer = $own;
        } elseif ($under === 'unknown') {
            return null;
        } elseif (is_array($under)) {
            $layer = $under;
        } else {
            return $base;
        }
        if ($base === null) {
            return array_map(fn ($c) => ($c[3] ?? 1.0) >= 0.999 ? $c : [$c[0], $c[1], $c[2], 1.0], $layer);
        }

        $out = [];
        foreach ($layer as $c) {
            foreach ($base as $b) {
                $out[] = Colour::over($c, $b);
            }
        }

        return $out;
    }

    /** @return list<array{0: float|int, 1: float|int, 2: float|int}>|null */
    private function slideBackground(DeckContext $ctx, array $s): ?array
    {
        $bg = $s['bg'];
        if (is_array($bg) && isset($bg['img'])) {
            return null;
        }
        if ($bg === null && is_array($ctx->theme['bg'] ?? null) && isset($ctx->theme['bg']['img'])) {
            return null;
        }
        $fill = $bg ?? ($ctx->theme['bg'] ?? null);
        $colours = Colour::fillColours($fill, $ctx->palette);
        if ($colours === []) {
            $colours = [Colour::parse($ctx->palette['@bg'])];
        }

        return $colours;
    }

    private function contrast(DeckContext $ctx, array $s, array $e): array
    {
        $fg = Colour::resolve($e['color'] ?? '@ink', $ctx->palette);
        if ($fg === null) {
            return [];
        }
        $bgs = $this->backgrounds($ctx, $s, $e);
        if ($bgs === null || $bgs === []) {
            return [];
        }
        $worst = INF;
        foreach ($bgs as $bg) {
            $text = ($fg[3] ?? 1.0) < 0.999 ? Colour::over($fg, $bg) : $fg;
            $worst = min($worst, Colour::contrast($text, $bg));
        }
        $large = $e['size'] >= $ctx->h * 0.0333 || ($e['weight'] >= 700 && $e['size'] >= $ctx->h * 0.026);
        $need = $large ? 3.0 : 4.5;
        if ($worst >= $need) {
            return [];
        }

        $fix = null;
        if (! $e['lock']) {
            foreach (['@ink', '@bg', '#ffffff', '#000000'] as $cand) {
                $c = Colour::resolve($cand, $ctx->palette);
                $min = INF;
                foreach ($bgs as $bg) {
                    $min = min($min, Colour::contrast($c, $bg));
                }
                if ($min >= $need) {
                    $fix = Ops::fix('Use '.$cand.' for this text (contrast '.round($min, 1).':1)', [Ops::elSet($s['id'], $e['id'], ['color' => $cand])]);
                    break;
                }
            }
        }

        return [$this->issue('contrast', 'readability', $worst < $need * 0.67 ? 'issue' : 'warn', $s, $e,
            ucfirst($this->label($e)).' has a contrast of '.round($worst, 1).':1 against its background; '.($large ? 'large' : 'normal').' text needs '.$need.':1.',
            'Low contrast text is hard to read for everyone and fails accessibility guidance (WCAG AA). Gradients are checked at their worst stop.',
            $fix)];
    }

    private function lines(DeckContext $ctx, array $s, array $e): array
    {
        if (in_array($e['role'], ['title', 'footer', 'number', 'caption'], true) || $e['w'] <= 0) {
            return [];
        }
        $m = TextMetrics::measure($e['blocks'], max($e['w'] - 2 * $e['pad'], 1.0), $e['size'], $e['family'], $e['weight'], $e['lh'], $e['ls'], $e['ps']);
        if ($m['lines'] < 2 || $m['avg_chars'] <= 80) {
            return [];
        }

        return [$this->issue('long-lines', 'readability', 'info', $s, $e,
            ucfirst($this->label($e)).' runs about '.round($m['avg_chars']).' characters per line (estimated).',
            'Lines longer than 70 to 80 characters are tiring to follow. Narrow the box or use columns.')];
    }
}
