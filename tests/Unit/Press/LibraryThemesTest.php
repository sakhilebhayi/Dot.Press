<?php

namespace Tests\Unit\Press;

use App\Services\Press\ThemeLibrary;
use App\Services\Press\ThemeTokens;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The built-in theme library: token validity, readable contrast and colour-blind safe chart palettes.
 */
class LibraryThemesTest extends TestCase
{
    /** The twelve self hosted slide fonts (resources/js/press/fonts.js). */
    private const FONTS = ['Public Sans', 'Inter', 'IBM Plex Sans', 'IBM Plex Serif', 'IBM Plex Mono', 'Literata', 'DM Sans', 'Space Grotesk', 'Playfair Display', 'Manrope', 'Source Serif 4', 'Caveat'];

    /** @return array<string, array{array<string, mixed>}> */
    public static function themes(): array
    {
        $out = [];
        foreach (ThemeLibrary::all() as $theme) {
            $out[$theme['id']] = [$theme];
        }

        return $out;
    }

    public function test_the_library_has_twelve_themes_with_slate_first_and_unique_ids(): void
    {
        $ids = ThemeLibrary::ids();

        $this->assertCount(12, $ids);
        $this->assertSame('slate', $ids[0]);
        $this->assertSame($ids, array_values(array_unique($ids)));
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', implode('', $ids));
    }

    #[DataProvider('themes')]
    public function test_every_theme_is_valid_tokens_without_warnings(array $theme): void
    {
        $result = ThemeTokens::validate($theme);

        $this->assertSame([], $result['errors']);
        $this->assertSame([], $result['warnings']);
        $this->assertCount(8, $theme['c']['chart']);
        $this->assertSame(array_unique($theme['c']['chart']), $theme['c']['chart'], 'chart colours are distinct');
    }

    #[DataProvider('themes')]
    public function test_every_theme_meets_the_contrast_rules(array $theme): void
    {
        $c = $theme['c'];

        $this->assertGreaterThanOrEqual(7.0, ThemeTokens::contrast($c['ink'], $c['bg']), 'ink on bg');
        $this->assertGreaterThanOrEqual(7.0, ThemeTokens::contrast($c['ink'], $c['surface']), 'ink on surface');
        $this->assertGreaterThanOrEqual(4.5, ThemeTokens::contrast($c['muted'], $c['bg']), 'muted on bg');
        $this->assertGreaterThanOrEqual(3.0, ThemeTokens::contrast($c['accent'], $c['bg']), 'accent on bg');

        if ($theme['id'] === 'slate') {
            // slate is the application default and keeps its original values; the stricter rules apply to every other theme
            return;
        }

        $this->assertGreaterThanOrEqual(4.5, ThemeTokens::contrast($c['muted'], $c['surface']), 'muted on surface');
        $this->assertGreaterThanOrEqual(3.0, ThemeTokens::contrast($c['accent2'], $c['bg']), 'accent2 on bg');
        // text drawn on an accent ground (section slides) is the page colour
        $this->assertGreaterThanOrEqual(4.5, ThemeTokens::contrast($c['bg'], $c['accent']), 'bg text on an accent slide');
        foreach (['good', 'warn', 'bad'] as $status) {
            $this->assertGreaterThanOrEqual(3.0, ThemeTokens::contrast($c[$status], $c['bg']), $status.' on bg');
        }
    }

    #[DataProvider('themes')]
    public function test_every_theme_uses_fonts_from_the_slide_font_list(array $theme): void
    {
        $this->assertContains($theme['t']['heading'], self::FONTS);
        $this->assertContains($theme['t']['body'], self::FONTS);
        $this->assertGreaterThanOrEqual(100, $theme['t']['hw']);
        $this->assertLessThanOrEqual(900, $theme['t']['hw']);
        $this->assertGreaterThanOrEqual(0.5, $theme['t']['scale']);
        $this->assertLessThanOrEqual(2.0, $theme['t']['scale']);
    }

    #[DataProvider('themes')]
    public function test_every_chart_palette_separates_neighbours_for_colour_blind_readers(array $theme): void
    {
        $palette = $theme['c']['chart'];
        $dark = ThemeTokens::luminance($theme['c']['bg']) < 0.2;

        $worstCvd = INF;
        $worstNormal = INF;
        for ($i = 0; $i < 7; $i++) {
            foreach (['protan', 'deutan'] as $kind) {
                $worstCvd = min($worstCvd, self::deltaE($palette[$i], $palette[$i + 1], $kind));
            }
            $worstNormal = min($worstNormal, self::deltaE($palette[$i], $palette[$i + 1], null));
        }

        // the dataviz skill's gates: adjacent colour-blind separation at least 6 (8 is the target), normal vision at least 15
        $this->assertGreaterThanOrEqual(6.0, $worstCvd, 'adjacent separation under protan and deutan simulation');
        $this->assertGreaterThanOrEqual(15.0, $worstNormal, 'adjacent separation for full colour vision');

        if ($theme['id'] !== 'slate') {
            foreach ($palette as $colour) {
                [$lightness, $chroma] = self::oklch($colour);
                $this->assertGreaterThanOrEqual(0.10, $chroma, "{$colour} must not read as grey");
                $this->assertGreaterThanOrEqual($dark ? 0.48 : 0.43, $lightness, $colour.' lightness');
                $this->assertLessThanOrEqual($dark ? 0.67 : 0.77, $lightness, $colour.' lightness');
            }
        }
    }

    public function test_the_library_has_the_kinds_of_theme_a_presenter_needs(): void
    {
        $themes = array_column(ThemeLibrary::all(), null, 'id');
        $dark = array_filter($themes, fn (array $t): bool => ThemeTokens::luminance($t['c']['bg']) < 0.1);

        $this->assertGreaterThanOrEqual(2, count($dark), 'at least two dark themes');
        $this->assertGreaterThanOrEqual(19.0, ThemeTokens::contrast($themes['projector']['c']['ink'], $themes['projector']['c']['bg']), 'a high contrast theme for projectors');
        $this->assertGreaterThanOrEqual(10.0, ThemeTokens::contrast($themes['projector']['c']['muted'], $themes['projector']['c']['bg']));

        // the warm theme: a sand ground (red above blue)
        [$r, , $b] = ThemeTokens::rgb($themes['field-report']['c']['bg']);
        $this->assertGreaterThan($b + 20, $r);

        $headings = array_unique(array_column(array_column($themes, 't'), 'heading'));
        $this->assertGreaterThanOrEqual(7, count($headings), 'the themes use a spread of heading faces');
    }

    public function test_a_default_background_is_restrained(): void
    {
        foreach (ThemeLibrary::all() as $theme) {
            if ($theme['bg'] === null) {
                continue;
            }
            $this->assertIsArray($theme['bg'], 'a gradient');
            $stops = $theme['bg']['stops'];
            $this->assertLessThan(0.08, abs(ThemeTokens::luminance($stops[0][1]) - ThemeTokens::luminance($stops[1][1])), 'the gradient stays close to one tone');
            $this->assertGreaterThanOrEqual(7.0, ThemeTokens::contrast($theme['c']['ink'], $stops[1][1]));
        }
    }

    // ------------------------------------------------------------------------------------------ colour maths

    private const MACHADO = [
        'protan' => [[0.152286, 1.052583, -0.204868], [0.114503, 0.786281, 0.099216], [-0.003882, -0.048116, 1.051998]],
        'deutan' => [[0.367322, 0.860646, -0.227968], [0.280085, 0.672501, 0.047413], [-0.011820, 0.042940, 0.968881]],
    ];

    /** @return array{float, float, float} linear sRGB */
    private static function linear(string $hex): array
    {
        return array_map(function (int $v): float {
            $c = $v / 255;

            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, ThemeTokens::rgb($hex));
    }

    /** @return array{float, float, float} */
    private static function oklab(array $lin): array
    {
        [$r, $g, $b] = $lin;
        $l = ($r * 0.4122214708 + $g * 0.5363325363 + $b * 0.0514459929) ** (1 / 3);
        $m = ($r * 0.2119034982 + $g * 0.6806995451 + $b * 0.1073969566) ** (1 / 3);
        $s = ($r * 0.0883024619 + $g * 0.2817188376 + $b * 0.6299787005) ** (1 / 3);

        return [
            0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s,
            1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s,
            0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s,
        ];
    }

    /** @return array{float, float} lightness and chroma */
    private static function oklch(string $hex): array
    {
        [$l, $a, $b] = self::oklab(self::linear($hex));

        return [$l, hypot($a, $b)];
    }

    private static function deltaE(string $a, string $b, ?string $kind): float
    {
        $simulate = function (array $lin) use ($kind): array {
            if ($kind === null) {
                return $lin;
            }
            $m = self::MACHADO[$kind];

            return array_map(fn (array $row): float => max(0.0, min(1.0, $row[0] * $lin[0] + $row[1] * $lin[1] + $row[2] * $lin[2])), $m);
        };
        $x = self::oklab($simulate(self::linear($a)));
        $y = self::oklab($simulate(self::linear($b)));

        return 100 * sqrt(($x[0] - $y[0]) ** 2 + ($x[1] - $y[1]) ** 2 + ($x[2] - $y[2]) ** 2);
    }
}
