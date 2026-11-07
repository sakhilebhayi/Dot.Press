<?php

namespace App\Services\Press\Intel;

/**
 * Colour maths for the analyzer: parsing the spec colour forms, theme tokens, WCAG contrast
 * and a perceptual distance (CIE76 in Lab) to find the nearest theme token.
 */
final class Colour
{
    public const TOKENS = ['@bg', '@surface', '@ink', '@muted', '@line', '@accent', '@accent2', '@good', '@warn', '@bad',
        '@chart1', '@chart2', '@chart3', '@chart4', '@chart5', '@chart6', '@chart7', '@chart8'];

    /** The Slate theme from the document spec, used when a deck carries no theme. */
    public static function defaultTheme(): array
    {
        return [
            'id' => 'slate', 'name' => 'Slate',
            'c' => ['bg' => '#ffffff', 'surface' => '#f4f5f7', 'ink' => '#111827', 'muted' => '#6b7280', 'line' => '#d1d5db',
                'accent' => '#2563eb', 'accent2' => '#f59e0b', 'good' => '#15803d', 'warn' => '#b45309', 'bad' => '#b91c1c',
                'chart' => ['#2563eb', '#f59e0b', '#15803d', '#7c3aed', '#db2777', '#0891b2', '#65a30d', '#475569']],
            't' => ['heading' => 'Public Sans', 'body' => 'Public Sans', 'hw' => 700, 'scale' => 1],
            's' => ['radius' => 12, 'stroke' => 2],
            'bg' => null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $theme
     * @return array<string, string> token (with @) to #rrggbb
     */
    public static function palette(?array $theme): array
    {
        $default = self::defaultTheme();
        $c = is_array($theme['c'] ?? null) ? $theme['c'] : [];
        $out = [];
        foreach (['bg', 'surface', 'ink', 'muted', 'line', 'accent', 'accent2', 'good', 'warn', 'bad'] as $k) {
            $v = $c[$k] ?? $default['c'][$k];
            $out['@'.$k] = self::parse(is_string($v) ? $v : null) !== null ? self::hex(self::parse($v)) : $default['c'][$k];
        }
        $chart = is_array($c['chart'] ?? null) ? array_values($c['chart']) : $default['c']['chart'];
        for ($i = 1; $i <= 8; $i++) {
            $v = $chart[$i - 1] ?? $default['c']['chart'][$i - 1];
            $out['@chart'.$i] = self::parse(is_string($v) ? $v : null) !== null ? self::hex(self::parse($v)) : $default['c']['chart'][$i - 1];
        }

        return $out;
    }

    /** @return array{0: int, 1: int, 2: int, 3: float}|null */
    public static function parse(?string $c): ?array
    {
        if ($c === null) {
            return null;
        }
        $c = trim($c);
        if (! preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $c, $m)) {
            return null;
        }
        $h = $m[1];
        if (strlen($h) === 3) {
            $h = $h[0].$h[0].$h[1].$h[1].$h[2].$h[2];
        }
        $a = strlen($h) === 8 ? hexdec(substr($h, 6, 2)) / 255 : 1.0;

        return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2)), $a];
    }

    /** @param  array{0: int, 1: int, 2: int, 3?: float}  $rgb */
    public static function hex(array $rgb): string
    {
        return sprintf('#%02x%02x%02x', max(0, min(255, (int) round($rgb[0]))), max(0, min(255, (int) round($rgb[1]))), max(0, min(255, (int) round($rgb[2]))));
    }

    /**
     * Resolve a colour string (hex or theme token) to rgb, or null when it is not a plain colour.
     *
     * @param  array<string, string>  $palette
     * @return array{0: int, 1: int, 2: int, 3: float}|null
     */
    public static function resolve(mixed $c, array $palette): ?array
    {
        if (! is_string($c)) {
            return null;
        }
        if (str_starts_with($c, '@')) {
            return isset($palette[$c]) ? self::parse($palette[$c]) : null;
        }

        return self::parse($c);
    }

    /**
     * The colours a fill can show: one for a flat colour, the stops for a gradient, none for anything else.
     *
     * @param  array<string, string>  $palette
     * @return list<array{0: int, 1: int, 2: int, 3: float}>
     */
    public static function fillColours(mixed $fill, array $palette): array
    {
        if (is_string($fill)) {
            $rgb = self::resolve($fill, $palette);

            return $rgb ? [$rgb] : [];
        }
        if (is_array($fill) && isset($fill['g']) && is_array($fill['stops'] ?? null)) {
            $out = [];
            foreach ($fill['stops'] as $stop) {
                $rgb = is_array($stop) ? self::resolve($stop[1] ?? null, $palette) : null;
                if ($rgb) {
                    $out[] = $rgb;
                }
            }

            return $out;
        }

        return [];
    }

    /**
     * Composite $top (with alpha) over an opaque $base.
     *
     * @param  array{0: int, 1: int, 2: int, 3?: float}  $top
     * @param  array{0: int, 1: int, 2: int, 3?: float}  $base
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public static function over(array $top, array $base): array
    {
        $a = $top[3] ?? 1.0;

        return [$top[0] * $a + $base[0] * (1 - $a), $top[1] * $a + $base[1] * (1 - $a), $top[2] * $a + $base[2] * (1 - $a), 1.0];
    }

    /** @param  array{0: int|float, 1: int|float, 2: int|float}  $rgb */
    public static function luminance(array $rgb): float
    {
        $f = function ($v) {
            $v /= 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $f($rgb[0]) + 0.7152 * $f($rgb[1]) + 0.0722 * $f($rgb[2]);
    }

    /**
     * @param  array{0: int|float, 1: int|float, 2: int|float}  $a
     * @param  array{0: int|float, 1: int|float, 2: int|float}  $b
     */
    public static function contrast(array $a, array $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * @param  array{0: int|float, 1: int|float, 2: int|float}  $rgb
     * @return array{0: float, 1: float, 2: float}
     */
    public static function lab(array $rgb): array
    {
        $lin = function ($v) {
            $v /= 255;

            return $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        };
        [$r, $g, $b] = [$lin($rgb[0]), $lin($rgb[1]), $lin($rgb[2])];
        $x = ($r * 0.4124 + $g * 0.3576 + $b * 0.1805) / 0.95047;
        $y = ($r * 0.2126 + $g * 0.7152 + $b * 0.0722);
        $z = ($r * 0.0193 + $g * 0.1192 + $b * 0.9505) / 1.08883;
        $f = fn ($t) => $t > 0.008856 ? $t ** (1 / 3) : 7.787 * $t + 16 / 116;

        return [116 * $f($y) - 16, 500 * ($f($x) - $f($y)), 200 * ($f($y) - $f($z))];
    }

    /**
     * @param  array{0: int|float, 1: int|float, 2: int|float}  $a
     * @param  array{0: int|float, 1: int|float, 2: int|float}  $b
     */
    public static function distance(array $a, array $b): float
    {
        $la = self::lab($a);
        $lb = self::lab($b);

        return sqrt(($la[0] - $lb[0]) ** 2 + ($la[1] - $lb[1]) ** 2 + ($la[2] - $lb[2]) ** 2);
    }

    /**
     * Nearest theme token for a hex colour.
     *
     * @param  array<string, string>  $palette
     * @return array{token: string, distance: float}
     */
    public static function nearestToken(array $rgb, array $palette): array
    {
        $best = ['token' => '@ink', 'distance' => INF];
        foreach ($palette as $token => $hex) {
            $p = self::parse($hex);
            if (! $p) {
                continue;
            }
            $d = self::distance($rgb, $p);
            if ($d < $best['distance']) {
                $best = ['token' => $token, 'distance' => $d];
            }
        }

        return $best;
    }

    /** Is this colour on the theme (a token, white, black, or within a small distance of a token)? */
    public static function isOnTheme(string $c, array $palette, float $tolerance = 6.0): bool
    {
        if (str_starts_with($c, '@')) {
            return true;
        }
        $rgb = self::parse($c);
        if (! $rgb) {
            return true;
        }
        $extras = [[255, 255, 255], [0, 0, 0]];
        foreach ($extras as $e) {
            if (self::distance($rgb, $e) <= $tolerance) {
                return true;
            }
        }

        return self::nearestToken($rgb, $palette)['distance'] <= $tolerance;
    }
}
