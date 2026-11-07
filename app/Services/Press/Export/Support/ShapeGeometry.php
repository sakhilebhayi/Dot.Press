<?php

namespace App\Services\Press\Export\Support;

/** Outline geometry shared by the PDF shapes (SVG) and the GD image masks. All coordinates are in the element box. */
final class ShapeGeometry
{
    public const KINDS = ['rect', 'round', 'ellipse', 'triangle', 'diamond', 'pentagon', 'hexagon', 'star', 'chevron', 'arrow-r', 'arrow-l', 'callout', 'cross', 'heart', 'line', 'arrow'];

    public const MASKS = ['none', 'round', 'circle', 'ellipse', 'triangle', 'star', 'hexagon'];

    /** Block arrow head length (PowerPoint rightArrow: adj2 50000 of the shorter side). */
    public static function arrowHead(float $w, float $h): float
    {
        return min($w * 0.8, min($w, $h) * 0.5);
    }

    /** Callout body takes the top share of the box, the tail the rest. */
    public const CALLOUT_BODY = 0.82;

    /** Chevron notch depth. */
    public static function chevronDepth(float $w, float $h): float
    {
        return min($w * 0.4, $h * 0.5);
    }

    /**
     * Polygon for the straight edged kinds, or null when the kind is curved (rect, round, ellipse, heart, line).
     *
     * @return list<array{0: float, 1: float}>|null
     */
    public static function polygon(string $kind, float $w, float $h): ?array
    {
        switch ($kind) {
            case 'triangle':
                return [[$w / 2, 0], [$w, $h], [0, $h]];
            case 'diamond':
                return [[$w / 2, 0], [$w, $h / 2], [$w / 2, $h], [0, $h / 2]];
            case 'pentagon':
                return [[$w / 2, 0], [$w, 0.382 * $h], [0.809 * $w, $h], [0.191 * $w, $h], [0, 0.382 * $h]];
            case 'hexagon':
                return [[0.25 * $w, 0], [0.75 * $w, 0], [$w, $h / 2], [0.75 * $w, $h], [0.25 * $w, $h], [0, $h / 2]];
            case 'star':
                return self::star($w, $h);
            case 'chevron':
                $d = self::chevronDepth($w, $h);

                return [[0, 0], [$w - $d, 0], [$w, $h / 2], [$w - $d, $h], [0, $h], [$d, $h / 2]];
            case 'arrow-r':
                $hl = self::arrowHead($w, $h);

                return [[0, 0.25 * $h], [$w - $hl, 0.25 * $h], [$w - $hl, 0], [$w, $h / 2], [$w - $hl, $h], [$w - $hl, 0.75 * $h], [0, 0.75 * $h]];
            case 'arrow-l':
                $hl = self::arrowHead($w, $h);

                return [[$w, 0.25 * $h], [$hl, 0.25 * $h], [$hl, 0], [0, $h / 2], [$hl, $h], [$hl, 0.75 * $h], [$w, 0.75 * $h]];
            case 'cross':
                $a = $w / 3;
                $b = $h / 3;

                return [[$a, 0], [2 * $a, 0], [2 * $a, $b], [$w, $b], [$w, 2 * $b], [2 * $a, 2 * $b], [2 * $a, $h], [$a, $h], [$a, 2 * $b], [0, 2 * $b], [0, $b], [$a, $b]];
            case 'callout':
                $bh = $h * self::CALLOUT_BODY;

                return [[0, 0], [$w, 0], [$w, $bh], [$w * 5 / 12, $bh], [$w * 0.25, $h], [$w / 6, $bh], [0, $bh]];
            default:
                return null;
        }
    }

    /** Five point star scaled to fill the box. @return list<array{0: float, 1: float}> */
    private static function star(float $w, float $h): array
    {
        $pts = [];
        $inner = 0.382;
        for ($k = 0; $k < 10; $k++) {
            $r = $k % 2 === 0 ? 1.0 : $inner;
            $a = deg2rad(-90 + 36 * $k);
            $pts[] = [$r * cos($a), $r * sin($a)];
        }
        $minX = min(array_column($pts, 0));
        $maxX = max(array_column($pts, 0));
        $minY = min(array_column($pts, 1));
        $maxY = max(array_column($pts, 1));
        $out = [];
        foreach ($pts as [$x, $y]) {
            $out[] = [($x - $minX) / ($maxX - $minX) * $w, ($y - $minY) / ($maxY - $minY) * $h];
        }

        return $out;
    }

    /** SVG path data for a heart filling the box. */
    public static function heartPath(float $w, float $h): string
    {
        $f = fn (float $x, float $y): string => round($x * $w, 2).' '.round($y * $h, 2);

        return 'M '.$f(0.5, 0.95).' C '.$f(0.1, 0.62).' '.$f(0.0, 0.42).' '.$f(0.0, 0.28)
            .' C '.$f(0.0, 0.1).' '.$f(0.14, 0.0).' '.$f(0.28, 0.0)
            .' C '.$f(0.4, 0.0).' '.$f(0.47, 0.07).' '.$f(0.5, 0.17)
            .' C '.$f(0.53, 0.07).' '.$f(0.6, 0.0).' '.$f(0.72, 0.0)
            .' C '.$f(0.86, 0.0).' '.$f(1.0, 0.1).' '.$f(1.0, 0.28)
            .' C '.$f(1.0, 0.42).' '.$f(0.9, 0.62).' '.$f(0.5, 0.95).' Z';
    }

    /** Polygon points as a flat list of points sampled from a heart, for GD masks. @return list<array{0: float, 1: float}> */
    public static function heartPolygon(float $w, float $h, int $steps = 16): array
    {
        $segs = [
            [[0.5, 0.95], [0.1, 0.62], [0.0, 0.42], [0.0, 0.28]],
            [[0.0, 0.28], [0.0, 0.1], [0.14, 0.0], [0.28, 0.0]],
            [[0.28, 0.0], [0.4, 0.0], [0.47, 0.07], [0.5, 0.17]],
            [[0.5, 0.17], [0.53, 0.07], [0.6, 0.0], [0.72, 0.0]],
            [[0.72, 0.0], [0.86, 0.0], [1.0, 0.1], [1.0, 0.28]],
            [[1.0, 0.28], [1.0, 0.42], [0.9, 0.62], [0.5, 0.95]],
        ];
        $out = [];
        foreach ($segs as [$p0, $p1, $p2, $p3]) {
            for ($i = 0; $i < $steps; $i++) {
                $t = $i / $steps;
                $mt = 1 - $t;
                $x = $mt ** 3 * $p0[0] + 3 * $mt * $mt * $t * $p1[0] + 3 * $mt * $t * $t * $p2[0] + $t ** 3 * $p3[0];
                $y = $mt ** 3 * $p0[1] + 3 * $mt * $mt * $t * $p1[1] + 3 * $mt * $t * $t * $p2[1] + $t ** 3 * $p3[1];
                $out[] = [$x * $w, $y * $h];
            }
        }

        return $out;
    }

    /** Mask kinds that map to a shape polygon. */
    public static function maskPolygon(string $mask, float $w, float $h): ?array
    {
        return match ($mask) {
            'triangle' => self::polygon('triangle', $w, $h),
            'star' => self::polygon('star', $w, $h),
            'hexagon' => self::polygon('hexagon', $w, $h),
            default => null,
        };
    }
}
