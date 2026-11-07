<?php

namespace App\Services\Press\Export\Support;

/**
 * Resolves the document's colour tokens, fonts and gradients (spec sections 2 and 3) to concrete values.
 */
final class Theme
{
    public const DEFAULT = [
        'id' => 'slate',
        'name' => 'Slate',
        'c' => [
            'bg' => '#ffffff', 'surface' => '#f4f5f7', 'ink' => '#111827', 'muted' => '#6b7280', 'line' => '#d1d5db',
            'accent' => '#2563eb', 'accent2' => '#f59e0b', 'good' => '#15803d', 'warn' => '#b45309', 'bad' => '#b91c1c',
            'chart' => ['#2563eb', '#f59e0b', '#15803d', '#7c3aed', '#db2777', '#0891b2', '#65a30d', '#475569'],
        ],
        't' => ['heading' => 'Public Sans', 'body' => 'Public Sans', 'hw' => 700, 'scale' => 1],
        's' => ['radius' => 12, 'stroke' => 2],
        'bg' => null,
    ];

    /** @var array<string, string> */
    private array $tokens = [];

    /** @var list<string> */
    private array $chart = [];

    public string $heading;

    public string $body;

    public int $headingWeight;

    public float $radius;

    public float $stroke;

    /** Default slide background: a colour, a gradient or an image background object. */
    public string|array|null $bg;

    public string $name;

    /** @param array<string, mixed>|null $theme */
    public function __construct(?array $theme = null)
    {
        $t = is_array($theme) ? $theme : [];
        $d = self::DEFAULT;
        $c = is_array($t['c'] ?? null) ? $t['c'] : [];
        foreach (['bg', 'surface', 'ink', 'muted', 'line', 'accent', 'accent2', 'good', 'warn', 'bad'] as $k) {
            $this->tokens[$k] = self::normalizeHex($c[$k] ?? null) ?? $d['c'][$k];
        }
        $chart = [];
        foreach ((array) ($c['chart'] ?? []) as $v) {
            $h = self::normalizeHex($v);
            if ($h !== null) {
                $chart[] = $h;
            }
        }
        $chart = array_slice($chart, 0, 8);
        // Fewer than 8 chart colours: pad from the default palette so @chart1..@chart8 always resolve.
        for ($i = count($chart); $i < 8; $i++) {
            $chart[] = $d['c']['chart'][$i];
        }
        $this->chart = $chart;
        foreach ($chart as $i => $v) {
            $this->tokens['chart'.($i + 1)] = $v;
        }
        $tt = is_array($t['t'] ?? null) ? $t['t'] : [];
        $this->heading = self::cleanFont($tt['heading'] ?? null) ?? $d['t']['heading'];
        $this->body = self::cleanFont($tt['body'] ?? null) ?? $d['t']['body'];
        $this->headingWeight = (int) ($tt['hw'] ?? 700) ?: 700;
        $s = is_array($t['s'] ?? null) ? $t['s'] : [];
        $this->radius = (float) ($s['radius'] ?? $d['s']['radius']);
        $this->stroke = (float) ($s['stroke'] ?? $d['s']['stroke']);
        $this->bg = $t['bg'] ?? null;
        $this->name = (string) ($t['name'] ?? $d['name']);
    }

    private static function cleanFont(mixed $v): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $v = trim(preg_replace('/[^A-Za-z0-9 \-_.]/', '', $v) ?? '');

        return $v === '' ? null : mb_substr($v, 0, 60);
    }

    public static function normalizeHex(mixed $v): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $v = strtolower(trim($v));
        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $v, $m)) {
            return '#'.$m[1].$m[1].$m[2].$m[2].$m[3].$m[3];
        }
        if (preg_match('/^#[0-9a-f]{6}$/', $v) || preg_match('/^#[0-9a-f]{8}$/', $v)) {
            return $v;
        }

        return null;
    }

    public function isToken(mixed $c): bool
    {
        return is_string($c) && str_starts_with($c, '@') && isset($this->tokens[substr($c, 1)]);
    }

    /** The chart palette (8 colours). @return list<string> */
    public function chart(): array
    {
        return $this->chart;
    }

    public function chartColor(int $i): string
    {
        return $this->chart[$i % 8];
    }

    /** A token such as "@accent" to its hex, a hex to itself (6 digits, alpha dropped), anything else to null. */
    public function hex(mixed $c): ?string
    {
        if (! is_string($c)) {
            return null;
        }
        if (str_starts_with($c, '@')) {
            return $this->tokens[substr($c, 1)] ?? null;
        }
        $h = self::normalizeHex($c);

        return $h === null ? null : substr($h, 0, 7);
    }

    /** Alpha 0..1 carried by an 8 digit hex, 1 otherwise. */
    public function alpha(mixed $c): float
    {
        $h = is_string($c) ? self::normalizeHex($c) : null;
        if ($h !== null && strlen($h) === 9) {
            return round(hexdec(substr($h, 7, 2)) / 255, 3);
        }

        return 1.0;
    }

    /** @return array{0: int, 1: int, 2: int}|null */
    public function rgb(mixed $c): ?array
    {
        $h = $this->hex($c);
        if ($h === null) {
            return null;
        }

        return [(int) hexdec(substr($h, 1, 2)), (int) hexdec(substr($h, 3, 2)), (int) hexdec(substr($h, 5, 2))];
    }

    /** CSS colour string for a spec colour; $extraAlpha multiplies the colour's own alpha. */
    public function css(mixed $c, float $extraAlpha = 1.0): ?string
    {
        $rgb = $this->rgb($c);
        if ($rgb === null) {
            return null;
        }
        $a = $this->alpha($c) * $extraAlpha;
        if ($a >= 0.999) {
            return sprintf('#%02x%02x%02x', ...$rgb);
        }

        return sprintf('rgba(%d,%d,%d,%s)', $rgb[0], $rgb[1], $rgb[2], rtrim(rtrim(number_format(max(0, $a), 3, '.', ''), '0'), '.') ?: '0');
    }

    /** Whether the value is a gradient fill object. */
    public static function isGradient(mixed $f): bool
    {
        return is_array($f) && isset($f['g'], $f['stops']) && is_array($f['stops']) && count($f['stops']) >= 2;
    }

    /**
     * Gradient stops with concrete colours.
     *
     * @return list<array{0: float, 1: string, 2: float}> offset 0..1, hex, alpha
     */
    public function stops(array $fill): array
    {
        $out = [];
        foreach ($fill['stops'] as $s) {
            if (! is_array($s) || ! isset($s[0], $s[1])) {
                continue;
            }
            $hex = $this->hex($s[1]);
            if ($hex === null) {
                continue;
            }
            $out[] = [max(0.0, min(1.0, (float) $s[0])), $hex, $this->alpha($s[1])];
        }
        usort($out, fn ($a, $b) => $a[0] <=> $b[0]);

        return $out;
    }

    /** Font family name for a spec `font` value ("@heading", "@body" or a family). */
    public function family(mixed $font, bool $heading = false): string
    {
        if ($font === '@heading') {
            return $this->heading;
        }
        if ($font === '@body' || $font === null || $font === '') {
            return $heading ? $this->heading : $this->body;
        }

        return self::cleanFont($font) ?? $this->body;
    }

    /** Average luminance 0..1 of a colour (for picking readable foregrounds). */
    public function luminance(mixed $c): float
    {
        $rgb = $this->rgb($c) ?? [0, 0, 0];

        return (0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2]) / 255;
    }
}
