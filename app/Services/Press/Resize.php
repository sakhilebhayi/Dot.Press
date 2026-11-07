<?php

namespace App\Services\Press;

/**
 * The geometry of `deck.resize` (spec section 8). Pure: arrays in, arrays out.
 *
 * Elements rotate around their own centre, so a uniform scale of the slide maps a rotated element to a rotated element
 * of the same angle: only x, y, w and h change. The three modes:
 *
 *  - scale   uniform factor max(nw/ow, nh/oh): the content fills the new slide and is centred; an edge may be cropped.
 *  - fit     uniform factor min(nw/ow, nh/oh): the content is fully visible and centred; the spare space is letterboxed.
 *  - anchor  sizes and type stay as they are; each element (a group counts as one block, so its members keep their
 *            relative layout) moves by the change of slide size times the block's centre fraction, so an element near
 *            the left edge keeps its left margin, one near the right edge keeps its right margin and a centred one
 *            stays centred.
 */
final class Resize
{
    public const MODES = ['scale', 'anchor', 'fit'];

    /**
     * @return array{s: float, ox: float, oy: float}
     */
    public static function factor(string $mode, float $ow, float $oh, float $nw, float $nh): array
    {
        $sx = $nw / $ow;
        $sy = $nh / $oh;
        $s = match ($mode) {
            'fit' => min($sx, $sy),
            'scale' => max($sx, $sy),
            default => 1.0,
        };

        return ['s' => $s, 'ox' => ($nw - $ow * $s) / 2, 'oy' => ($nh - $oh * $s) / 2];
    }

    /**
     * @param  list<array<string, mixed>>  $els
     * @return list<array<string, mixed>>
     */
    public static function elements(array $els, float $ow, float $oh, float $nw, float $nh, string $mode): array
    {
        if ($ow <= 0 || $oh <= 0 || $nw <= 0 || $nh <= 0) {
            return $els;
        }

        if ($mode === 'anchor') {
            return self::anchor($els, $ow, $oh, $nw, $nh);
        }

        ['s' => $s, 'ox' => $ox, 'oy' => $oy] = self::factor($mode, $ow, $oh, $nw, $nh);

        return array_map(function (array $el) use ($s, $ox, $oy): array {
            $el['x'] = self::r(($el['x'] ?? 0) * $s + $ox);
            $el['y'] = self::r(($el['y'] ?? 0) * $s + $oy);
            $el['w'] = self::r(($el['w'] ?? 0) * $s);
            $el['h'] = self::r(($el['h'] ?? 0) * $s);

            return self::scaleMeasures($el, $s);
        }, $els);
    }

    /**
     * The slide-unit measures inside a theme (`s.radius`, `s.stroke`) follow a scaling resize.
     *
     * @param  array<string, mixed>|null  $theme
     * @return array<string, mixed>|null
     */
    public static function theme(?array $theme, string $mode, float $ow, float $oh, float $nw, float $nh): ?array
    {
        if ($theme === null || $mode === 'anchor' || $ow <= 0 || $oh <= 0) {
            return $theme;
        }

        $s = self::factor($mode, $ow, $oh, $nw, $nh)['s'];
        foreach (['radius', 'stroke'] as $key) {
            if (isset($theme['s'][$key]) && is_numeric($theme['s'][$key])) {
                $theme['s'][$key] = self::r($theme['s'][$key] * $s);
            }
        }

        return $theme;
    }

    /**
     * @param  list<array<string, mixed>>  $els
     * @return list<array<string, mixed>>
     */
    private static function anchor(array $els, float $ow, float $oh, float $nw, float $nh): array
    {
        $dw = $nw - $ow;
        $dh = $nh - $oh;

        // Each block is one element, or all elements that share a group id.
        $blocks = [];
        foreach ($els as $i => $el) {
            $key = isset($el['group']) ? 'g:'.$el['group'] : 'e:'.$i;
            $box = self::bounds($el);
            if (! isset($blocks[$key])) {
                $blocks[$key] = $box;

                continue;
            }
            $blocks[$key] = [
                min($blocks[$key][0], $box[0]), min($blocks[$key][1], $box[1]),
                max($blocks[$key][2], $box[2]), max($blocks[$key][3], $box[3]),
            ];
        }

        $out = [];
        foreach ($els as $i => $el) {
            $box = $blocks[isset($el['group']) ? 'g:'.$el['group'] : 'e:'.$i];
            $fx = max(0.0, min(1.0, (($box[0] + $box[2]) / 2) / $ow));
            $fy = max(0.0, min(1.0, (($box[1] + $box[3]) / 2) / $oh));
            $el['x'] = self::r(($el['x'] ?? 0) + $dw * $fx);
            $el['y'] = self::r(($el['y'] ?? 0) + $dh * $fy);
            $out[] = $el;
        }

        return $out;
    }

    /**
     * The axis-aligned box of an element after its rotation: [x0, y0, x1, y1].
     *
     * @param  array<string, mixed>  $el
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public static function bounds(array $el): array
    {
        $x = (float) ($el['x'] ?? 0);
        $y = (float) ($el['y'] ?? 0);
        $w = (float) ($el['w'] ?? 0);
        $h = (float) ($el['h'] ?? 0);
        $rot = fmod((float) ($el['rot'] ?? 0), 360.0);

        if ($rot == 0.0) {
            return [$x, $y, $x + $w, $y + $h];
        }

        $cx = $x + $w / 2;
        $cy = $y + $h / 2;
        $a = deg2rad($rot);
        $cos = cos($a);
        $sin = sin($a);
        $xs = [];
        $ys = [];
        foreach ([[-$w / 2, -$h / 2], [$w / 2, -$h / 2], [$w / 2, $h / 2], [-$w / 2, $h / 2]] as [$dx, $dy]) {
            $xs[] = $cx + $dx * $cos - $dy * $sin;
            $ys[] = $cy + $dx * $sin + $dy * $cos;
        }

        return [min($xs), min($ys), max($xs), max($ys)];
    }

    /**
     * Type, strokes, padding, shadows and table rows grow and shrink with the slide in the scaling modes.
     *
     * @param  array<string, mixed>  $el
     * @return array<string, mixed>
     */
    private static function scaleMeasures(array $el, float $s): array
    {
        if ($s == 1.0) {
            return $el;
        }

        foreach (['size', 'sw', 'radius', 'pad'] as $key) {
            if (isset($el[$key]) && is_numeric($el[$key])) {
                $el[$key] = self::r($el[$key] * $s);
            }
        }
        if (isset($el['shadow']) && is_array($el['shadow'])) {
            foreach (['x', 'y', 'blur'] as $key) {
                if (isset($el['shadow'][$key]) && is_numeric($el['shadow'][$key])) {
                    $el['shadow'][$key] = self::r($el['shadow'][$key] * $s);
                }
            }
        }
        if (isset($el['rowh']) && is_array($el['rowh'])) {
            $el['rowh'] = array_map(fn ($n) => is_numeric($n) ? self::r($n * $s) : $n, $el['rowh']);
        }
        if (isset($el['adj']['blur']) && is_numeric($el['adj']['blur'])) {
            $el['adj']['blur'] = self::r($el['adj']['blur'] * $s);
        }
        if (isset($el['doc']) && is_array($el['doc'])) {
            $el['doc'] = self::scaleDoc($el['doc'], $s);
        }

        return $el;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private static function scaleDoc(array $node, float $s): array
    {
        if (isset($node['marks']) && is_array($node['marks'])) {
            foreach ($node['marks'] as $i => $mark) {
                if (($mark['type'] ?? null) !== 'textStyle' || ! isset($mark['attrs']['fontSize'])) {
                    continue;
                }
                $size = $mark['attrs']['fontSize'];
                if (is_numeric($size)) {
                    $node['marks'][$i]['attrs']['fontSize'] = self::r($size * $s);
                } elseif (is_string($size) && preg_match('/^(\d+(?:\.\d+)?)px$/i', trim($size), $m)) {
                    $node['marks'][$i]['attrs']['fontSize'] = self::r((float) $m[1] * $s).'px';
                }
            }
        }
        if (isset($node['content']) && is_array($node['content'])) {
            $node['content'] = array_map(fn ($child) => is_array($child) ? self::scaleDoc($child, $s) : $child, $node['content']);
        }

        return $node;
    }

    private static function r(float|int $n): int|float
    {
        $f = round((float) $n, 2);

        return floor($f) == $f ? (int) $f : $f;
    }
}
