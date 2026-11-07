<?php

namespace App\Services\Press\Export\Support;

/**
 * Reads the common element fields with defaults and bounds so the exporters never trip over a missing or hostile value.
 */
final class Els
{
    public const TYPES = ['text', 'shape', 'image', 'video', 'audio', 'icon', 'chart', 'table'];

    public static function num(mixed $v, float $default = 0.0, float $min = -100000.0, float $max = 100000.0): float
    {
        if (! is_numeric($v)) {
            return $default;
        }
        $f = (float) $v;
        if (is_nan($f) || is_infinite($f)) {
            return $default;
        }

        return max($min, min($max, $f));
    }

    /** @param array<string, mixed> $el @return array{x: float, y: float, w: float, h: float, rot: float, op: float} */
    public static function box(array $el): array
    {
        return [
            'x' => self::num($el['x'] ?? 0),
            'y' => self::num($el['y'] ?? 0),
            'w' => self::num($el['w'] ?? 0, 0, 0),
            'h' => self::num($el['h'] ?? 0, 0, 0),
            'rot' => self::num($el['rot'] ?? 0, 0, -3600, 3600),
            'op' => self::num($el['op'] ?? 1, 1, 0, 1),
        ];
    }

    /** @param array<string, mixed> $slide @return list<array<string, mixed>> visible, well formed elements in z order */
    public static function visible(array $slide): array
    {
        $out = [];
        foreach ((array) ($slide['els'] ?? []) as $el) {
            if (! is_array($el) || ! in_array($el['type'] ?? null, self::TYPES, true)) {
                continue;
            }
            if (! empty($el['hide'])) {
                continue;
            }
            $out[] = $el;
        }

        return $out;
    }

    /** Safe https or in-deck link, or null. */
    public static function link(mixed $l): ?string
    {
        if (! is_string($l)) {
            return null;
        }
        $l = trim($l);
        if (preg_match('#^https://[^\s<>"\']{1,2000}$#i', $l) || preg_match('/^#slide:[A-Za-z0-9_-]{1,40}$/', $l)) {
            return $l;
        }

        return null;
    }

    public static function shortAssetId(mixed $src): ?int
    {
        if (is_array($src) && isset($src['asset']) && is_numeric($src['asset'])) {
            return (int) $src['asset'];
        }

        return null;
    }

    public static function url(mixed $src): ?string
    {
        if (is_array($src) && isset($src['url']) && is_string($src['url'])) {
            return self::link($src['url']);
        }

        return null;
    }
}
