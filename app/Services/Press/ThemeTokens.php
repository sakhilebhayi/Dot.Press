<?php

namespace App\Services\Press;

/**
 * Validation and normalisation of theme tokens, spec section 3:
 *
 *   { c: {bg surface ink muted line accent accent2 good warn bad chart[]}, t: {heading body hw scale}, s: {radius stroke}, bg }
 *
 * validate() reports hard errors (a value that cannot be used) and soft warnings (a legible-looking value that
 * will read badly, such as low contrast): warnings never reject a theme. normalise() returns the complete,
 * canonical form with defaults from the built-in `slate` theme, so every stored theme has every key.
 */
final class ThemeTokens
{
    public const COLOUR_KEYS = ['bg', 'surface', 'ink', 'muted', 'line', 'accent', 'accent2', 'good', 'warn', 'bad'];

    public const CHART_COLOURS = 8;

    public const MAX_STOPS = 8;

    public const FONT_PATTERN = '/^[\p{L}\p{N}][\p{L}\p{N} \-_.]{0,79}$/u';

    /** @return list<string> */
    public static function colourTokens(): array
    {
        $tokens = array_map(fn (string $key): string => '@'.$key, self::COLOUR_KEYS);
        for ($i = 1; $i <= self::CHART_COLOURS; $i++) {
            $tokens[] = '@chart'.$i;
        }

        return $tokens;
    }

    public static function isHex(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value) === 1;
    }

    /** A hex colour or a theme token such as "@accent". */
    public static function isColour(mixed $value): bool
    {
        return self::isHex($value) || (is_string($value) && in_array($value, self::colourTokens(), true));
    }

    public static function isFill(mixed $value): bool
    {
        if (self::isColour($value)) {
            return true;
        }

        if (! is_array($value) || ($value['g'] ?? null) !== 'linear' || ! isset($value['a'], $value['stops'])) {
            return false;
        }

        if (! is_numeric($value['a']) || $value['a'] < 0 || $value['a'] > 360 || ! is_array($value['stops']) || ! array_is_list($value['stops'])) {
            return false;
        }

        $count = count($value['stops']);
        if ($count < 2 || $count > self::MAX_STOPS) {
            return false;
        }

        foreach ($value['stops'] as $stop) {
            if (! is_array($stop) || count($stop) !== 2 || ! array_is_list($stop) || ! is_numeric($stop[0]) || $stop[0] < 0 || $stop[0] > 1 || ! self::isColour($stop[1])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{errors: array<string, string>, warnings: list<string>}
     */
    public static function validate(mixed $theme): array
    {
        $errors = [];
        $warnings = [];

        if (! is_array($theme)) {
            return ['errors' => ['theme' => 'A theme must be an object.'], 'warnings' => []];
        }

        foreach (array_diff(array_keys($theme), ['id', 'name', 'c', 't', 's', 'bg']) as $unknown) {
            $warnings[] = 'The key "'.(is_string($unknown) ? mb_substr($unknown, 0, 40) : $unknown).'" is not part of a theme and was ignored.';
        }

        if (array_key_exists('c', $theme)) {
            self::validateColours($theme['c'], $errors);
        }
        if (array_key_exists('t', $theme)) {
            self::validateType($theme['t'], $errors);
        }
        if (array_key_exists('s', $theme)) {
            self::validateShape($theme['s'], $errors);
        }
        if (array_key_exists('bg', $theme) && $theme['bg'] !== null && ! self::isBackground($theme['bg'])) {
            $errors['bg'] = 'The background must be a colour, a linear gradient, an image ({"img":{"asset":id},"fit","dim"}) or null.';
        }

        if ($errors === []) {
            $warnings = array_merge($warnings, self::contrastWarnings(self::normalise($theme)['c']));
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * The complete canonical tokens (`c`, `t`, `s`, `bg`). Anything missing comes from the slate defaults.
     * Call validate() first: values that are invalid fall back to the default instead of failing here.
     *
     * @return array{c: array<string, mixed>, t: array<string, mixed>, s: array<string, mixed>, bg: mixed}
     */
    public static function normalise(array $theme): array
    {
        $base = ThemeLibrary::defaults();

        $colours = [];
        $given = is_array($theme['c'] ?? null) ? $theme['c'] : [];
        foreach (self::COLOUR_KEYS as $key) {
            $colours[$key] = self::isHex($given[$key] ?? null) ? self::canonicalHex($given[$key]) : $base['c'][$key];
        }

        $chart = [];
        if (is_array($given['chart'] ?? null) && array_is_list($given['chart'])) {
            foreach (array_slice($given['chart'], 0, self::CHART_COLOURS) as $colour) {
                if (self::isHex($colour)) {
                    $chart[] = self::canonicalHex($colour);
                }
            }
        }
        foreach ($base['c']['chart'] as $index => $fallback) {
            if (count($chart) >= self::CHART_COLOURS) {
                break;
            }
            if ($index >= count($chart)) {
                $chart[] = $fallback;
            }
        }
        $colours['chart'] = array_slice($chart, 0, self::CHART_COLOURS);

        $type = is_array($theme['t'] ?? null) ? $theme['t'] : [];
        $typography = [
            'heading' => self::isFont($type['heading'] ?? null) ? trim($type['heading']) : $base['t']['heading'],
            'body' => self::isFont($type['body'] ?? null) ? trim($type['body']) : $base['t']['body'],
            'hw' => self::numberIn($type['hw'] ?? null, 100, 900) !== null ? (int) round($type['hw']) : $base['t']['hw'],
            'scale' => self::numberIn($type['scale'] ?? null, 0.5, 2) !== null ? self::tidy($type['scale']) : $base['t']['scale'],
        ];

        $shape = is_array($theme['s'] ?? null) ? $theme['s'] : [];
        $shapes = [
            'radius' => self::numberIn($shape['radius'] ?? null, 0, 200) !== null ? self::tidy($shape['radius']) : $base['s']['radius'],
            'stroke' => self::numberIn($shape['stroke'] ?? null, 0, 40) !== null ? self::tidy($shape['stroke']) : $base['s']['stroke'],
        ];

        return [
            'c' => $colours,
            't' => $typography,
            's' => $shapes,
            'bg' => isset($theme['bg']) && self::isBackground($theme['bg']) ? self::canonicalBackground($theme['bg']) : null,
        ];
    }

    /** The full document-shaped theme (spec section 3) as stored in `deck.theme`. */
    public static function toDeckTheme(string $id, string $name, array $tokens): array
    {
        return ['id' => $id, 'name' => $name] + self::normalise($tokens);
    }

    public static function isFont(mixed $value): bool
    {
        return is_string($value) && preg_match(self::FONT_PATTERN, trim($value)) === 1;
    }

    /** WCAG 2.x contrast ratio of two hex colours (alpha is ignored). */
    public static function contrast(string $a, string $b): float
    {
        $lighter = max(self::luminance($a), self::luminance($b));
        $darker = min(self::luminance($a), self::luminance($b));

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = self::rgb($hex);

        $channel = function (int $value): float {
            $v = $value / 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);
    }

    /** Linear mix of two hex colours: 0 gives $a, 1 gives $b. */
    public static function mix(string $a, string $b, float $amount): string
    {
        $from = self::rgb($a);
        $to = self::rgb($b);

        return sprintf('#%02x%02x%02x', ...array_map(fn (int $x, int $y): int => (int) round($x + ($y - $x) * $amount), $from, $to));
    }

    /** @return array{int, int, int} */
    public static function rgb(string $hex): array
    {
        $hex = ltrim(self::canonicalHex($hex), '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    /** Lower case, with the three digit form expanded to six. */
    public static function canonicalHex(string $hex): string
    {
        $hex = strtolower($hex);

        if (strlen($hex) === 4) {
            return '#'.$hex[1].$hex[1].$hex[2].$hex[2].$hex[3].$hex[3];
        }

        return $hex;
    }

    private static function validateColours(mixed $colours, array &$errors): void
    {
        if (! is_array($colours) || ($colours !== [] && array_is_list($colours))) {
            $errors['c'] = 'Colours must be an object keyed bg, surface, ink, muted, line, accent, accent2, good, warn, bad and chart.';

            return;
        }

        foreach ($colours as $key => $value) {
            if ($key === 'chart') {
                if (! is_array($value) || ! array_is_list($value) || $value === [] || count($value) > self::CHART_COLOURS) {
                    $errors['c.chart'] = 'The chart palette is a list of 1 to '.self::CHART_COLOURS.' hex colours.';

                    continue;
                }
                foreach ($value as $index => $colour) {
                    if (! self::isHex($colour)) {
                        $errors["c.chart.{$index}"] = 'Chart colours must be hex values such as #2563eb.';
                    }
                }

                continue;
            }

            if (! in_array($key, self::COLOUR_KEYS, true)) {
                $errors['c.'.(is_string($key) ? mb_substr($key, 0, 40) : $key)] = 'Unknown colour key.';

                continue;
            }

            if (! self::isHex($value)) {
                $errors["c.{$key}"] = 'Must be a hex colour such as #2563eb (theme tokens are not allowed inside a theme).';
            }
        }
    }

    private static function validateType(mixed $type, array &$errors): void
    {
        if (! is_array($type) || ($type !== [] && array_is_list($type))) {
            $errors['t'] = 'Typography must be an object with heading, body, hw and scale.';

            return;
        }

        foreach ($type as $key => $value) {
            match ($key) {
                'heading', 'body' => self::isFont($value) ?: $errors["t.{$key}"] = 'Must be a font family name of up to 80 letters, digits, spaces, hyphens or dots.',
                'hw' => self::numberIn($value, 100, 900) !== null ?: $errors['t.hw'] = 'The heading weight is a number from 100 to 900.',
                'scale' => self::numberIn($value, 0.5, 2) !== null ?: $errors['t.scale'] = 'The type scale is a number from 0.5 to 2.',
                default => $errors['t.'.(is_string($key) ? mb_substr($key, 0, 40) : $key)] = 'Unknown typography key.',
            };
        }
    }

    private static function validateShape(mixed $shape, array &$errors): void
    {
        if (! is_array($shape) || ($shape !== [] && array_is_list($shape))) {
            $errors['s'] = 'Shape defaults must be an object with radius and stroke.';

            return;
        }

        foreach ($shape as $key => $value) {
            match ($key) {
                'radius' => self::numberIn($value, 0, 200) !== null ?: $errors['s.radius'] = 'The corner radius is a number from 0 to 200.',
                'stroke' => self::numberIn($value, 0, 40) !== null ?: $errors['s.stroke'] = 'The stroke width is a number from 0 to 40.',
                default => $errors['s.'.(is_string($key) ? mb_substr($key, 0, 40) : $key)] = 'Unknown shape key.',
            };
        }
    }

    private static function isBackground(mixed $bg): bool
    {
        if (self::isFill($bg)) {
            return true;
        }

        if (! is_array($bg) || ! isset($bg['img']) || ! is_array($bg['img'])) {
            return false;
        }

        $asset = $bg['img']['asset'] ?? null;
        $fit = $bg['fit'] ?? 'cover';
        $dim = $bg['dim'] ?? 0;

        return is_int($asset) && $asset > 0
            && in_array($fit, ['cover', 'contain', 'fill'], true)
            && self::numberIn($dim, 0, 1) !== null
            && array_diff(array_keys($bg), ['img', 'fit', 'dim']) === [];
    }

    private static function canonicalBackground(mixed $bg): mixed
    {
        if (is_string($bg)) {
            return self::isHex($bg) ? self::canonicalHex($bg) : $bg;
        }

        if (isset($bg['g'])) {
            return [
                'g' => 'linear',
                'a' => round((float) $bg['a'], 2),
                'stops' => array_map(fn (array $stop): array => [round((float) $stop[0], 4), is_string($stop[1]) && self::isHex($stop[1]) ? self::canonicalHex($stop[1]) : $stop[1]], $bg['stops']),
            ];
        }

        return ['img' => ['asset' => $bg['img']['asset']], 'fit' => $bg['fit'] ?? 'cover', 'dim' => round((float) ($bg['dim'] ?? 0), 3)];
    }

    /**
     * @param  array<string, mixed>  $colours  canonical colours
     * @return list<string>
     */
    private static function contrastWarnings(array $colours): array
    {
        $warnings = [];

        $check = function (string $label, string $foreground, string $background, float $minimum) use ($colours, &$warnings): void {
            $ratio = self::contrast($colours[$foreground], $colours[$background]);
            if ($ratio < $minimum) {
                $warnings[] = sprintf('%s has a contrast of %.1f:1 (%s on %s); at least %s:1 reads comfortably.', $label, $ratio, $foreground, $background, rtrim(rtrim(number_format($minimum, 1), '0'), '.'));
            }
        };

        $check('Body text', 'ink', 'bg', 4.5);
        $check('Body text on panels', 'ink', 'surface', 4.5);
        $check('Secondary text', 'muted', 'bg', 3.0);
        $check('The accent colour', 'accent', 'bg', 3.0);

        return $warnings;
    }

    /** Whole numbers stay integers so 12 does not come back as 12.0. */
    private static function tidy(int|float $value): int|float
    {
        $rounded = round((float) $value, 3);

        return floor($rounded) === $rounded ? (int) $rounded : $rounded;
    }

    private static function numberIn(mixed $value, float $min, float $max): ?float
    {
        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        return $value >= $min && $value <= $max && is_finite((float) $value) ? (float) $value : null;
    }
}
