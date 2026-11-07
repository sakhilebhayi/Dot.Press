<?php

namespace App\Services\Press\Export\Support;

/** The text properties of an element (text, or a shape or table that holds text) with defaults applied. */
final class TextStyle
{
    /**
     * @param  array<string, mixed>  $el
     * @param  array<string, mixed>  $defaults  overrides for the element defaults (for example a table's size)
     * @return array{font: string, size: float, weight: int, bold: bool, color: string, align: string, valign: string, lh: float, ls: float, ps: float, fit: string, pad: float, fx: string, radius: float}
     */
    public static function of(array $el, Theme $theme, array $defaults = []): array
    {
        $font = $el['font'] ?? ($defaults['font'] ?? '@body');
        $isHeading = $font === '@heading';
        $weight = (int) Els::num($el['weight'] ?? ($defaults['weight'] ?? ($isHeading ? $theme->headingWeight : 400)), 400, 100, 900);
        $align = in_array($el['align'] ?? null, ['left', 'center', 'right', 'justify'], true) ? $el['align'] : ($defaults['align'] ?? 'left');
        $valign = in_array($el['valign'] ?? null, ['top', 'middle', 'bottom'], true) ? $el['valign'] : ($defaults['valign'] ?? 'top');

        return [
            'font' => $theme->family($font),
            'size' => Els::num($el['size'] ?? ($defaults['size'] ?? 32), 32, 1, 2000),
            'weight' => $weight,
            'bold' => $weight >= 600,
            'color' => is_string($el['color'] ?? null) ? $el['color'] : ($defaults['color'] ?? '@ink'),
            'align' => $align,
            'valign' => $valign,
            'lh' => Els::num($el['lh'] ?? ($defaults['lh'] ?? 1.25), 1.25, 0.5, 4),
            'ls' => Els::num($el['ls'] ?? 0, 0, -1, 2),
            'ps' => Els::num($el['ps'] ?? ($defaults['ps'] ?? 0.3), 0.3, 0, 5),
            'fit' => ($el['fit'] ?? 'none') === 'shrink' ? 'shrink' : 'none',
            'pad' => Els::num($el['pad'] ?? ($defaults['pad'] ?? 0), 0, 0, 1000),
            'fx' => in_array($el['fx'] ?? null, ['outline', 'highlight'], true) ? $el['fx'] : 'none',
            'radius' => Els::num($el['radius'] ?? 0, 0, 0, 5000),
        ];
    }

    /** Hanging indent for a list paragraph in units: where the text starts and the width reserved for the marker. */
    public static function listIndent(int $level, float $size): array
    {
        $marker = $size * 1.15;

        return ['left' => $marker + $level * $size * 1.2, 'marker' => $marker];
    }

    /** Marker text for a list paragraph. */
    public static function marker(string $list, int $n, int $level): string
    {
        if ($list === 'ordered') {
            return match ($level % 3) {
                1 => self::alpha($n).'.',
                2 => self::roman($n).'.',
                default => $n.'.',
            };
        }

        return match ($level % 3) {
            1 => "\u{2013}",
            2 => "\u{25AA}",
            default => "\u{2022}",
        };
    }

    private static function alpha(int $n): string
    {
        $s = '';
        $n = max(1, $n);
        while ($n > 0) {
            $n--;
            $s = chr(97 + $n % 26).$s;
            $n = intdiv($n, 26);
        }

        return $s;
    }

    private static function roman(int $n): string
    {
        $map = ['m' => 1000, 'cm' => 900, 'd' => 500, 'cd' => 400, 'c' => 100, 'xc' => 90, 'l' => 50, 'xl' => 40, 'x' => 10, 'ix' => 9, 'v' => 5, 'iv' => 4, 'i' => 1];
        $s = '';
        $n = max(1, min(3999, $n));
        foreach ($map as $r => $v) {
            while ($n >= $v) {
                $s .= $r;
                $n -= $v;
            }
        }

        return $s;
    }
}
