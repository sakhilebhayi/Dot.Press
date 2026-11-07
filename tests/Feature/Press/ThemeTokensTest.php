<?php

namespace Tests\Feature\Press;

use App\Models\BrandKit;
use App\Services\Press\LayoutDefinition;
use App\Services\Press\ThemeLibrary;
use App\Services\Press\ThemeTokens;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ThemeTokensTest extends TestCase
{
    public function test_the_library_ships_slate_first_and_every_theme_is_valid_and_complete(): void
    {
        $this->assertSame('slate', ThemeLibrary::all()[0]['id']);
        $this->assertSame(array_unique(ThemeLibrary::ids()), ThemeLibrary::ids(), 'theme ids are unique');

        foreach (ThemeLibrary::all() as $theme) {
            $result = ThemeTokens::validate($theme);
            $this->assertSame([], $result['errors'], $theme['id'].' has errors');
            $this->assertSame([], $result['warnings'], $theme['id'].' has contrast warnings');
            $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $theme['id']);
            $this->assertSame(ThemeTokens::CHART_COLOURS, count($theme['c']['chart']));
            $this->assertEquals($theme, ThemeTokens::toDeckTheme($theme['id'], $theme['name'], $theme), 'normalising a built-in changes nothing');
        }
    }

    public function test_slate_matches_the_spec_example(): void
    {
        $slate = ThemeLibrary::find('slate');

        $this->assertSame('Slate', $slate['name']);
        $this->assertSame('#ffffff', $slate['c']['bg']);
        $this->assertSame('#2563eb', $slate['c']['accent']);
        $this->assertSame(['#2563eb', '#f59e0b', '#15803d', '#7c3aed', '#db2777', '#0891b2', '#65a30d', '#475569'], $slate['c']['chart']);
        $this->assertSame(['heading' => 'Public Sans', 'body' => 'Public Sans', 'hw' => 700, 'scale' => 1], $slate['t']);
        $this->assertSame(['radius' => 12, 'stroke' => 2], $slate['s']);
        $this->assertNull($slate['bg']);
        $this->assertNull(ThemeLibrary::find('missing'));
    }

    public function test_normalise_fills_defaults_expands_and_lowercases_hex_and_pads_the_chart(): void
    {
        $tokens = ThemeTokens::normalise(['c' => ['bg' => '#FFF', 'accent' => '#FF0000AA', 'chart' => ['#ABC', '#123456']], 't' => ['heading' => ' Inter ', 'hw' => 600]]);

        $this->assertSame('#ffffff', $tokens['c']['bg']);
        $this->assertSame('#ff0000aa', $tokens['c']['accent']);
        $this->assertSame('#111827', $tokens['c']['ink'], 'missing keys come from slate');
        $this->assertSame(['#aabbcc', '#123456', '#15803d', '#7c3aed', '#db2777', '#0891b2', '#65a30d', '#475569'], $tokens['c']['chart']);
        $this->assertSame('Inter', $tokens['t']['heading']);
        $this->assertSame('Public Sans', $tokens['t']['body']);
        $this->assertSame(600, $tokens['t']['hw']);
        $this->assertSame(['radius' => 12, 'stroke' => 2], $tokens['s']);
        $this->assertNull($tokens['bg']);
        $this->assertSame(['c', 't', 's', 'bg'], array_keys($tokens));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidThemes(): array
    {
        return [
            'named colour' => [['c' => ['bg' => 'white']], 'c.bg'],
            'token inside a theme' => [['c' => ['accent' => '@accent2']], 'c.accent'],
            'short hex with junk' => [['c' => ['ink' => '#12']], 'c.ink'],
            'unknown colour key' => [['c' => ['primary' => '#000000']], 'c.primary'],
            'colours as list' => [['c' => ['#000000']], 'c'],
            'chart too long' => [['c' => ['chart' => array_fill(0, 9, '#000000')]], 'c.chart'],
            'chart empty' => [['c' => ['chart' => []]], 'c.chart'],
            'chart entry bad' => [['c' => ['chart' => ['#000000', 'red']]], 'c.chart.1'],
            'font with markup' => [['t' => ['heading' => '<script>']], 't.heading'],
            'font too long' => [['t' => ['body' => str_repeat('a', 81)]], 't.body'],
            'weight low' => [['t' => ['hw' => 50]], 't.hw'],
            'weight string' => [['t' => ['hw' => '700']], 't.hw'],
            'scale high' => [['t' => ['scale' => 3]], 't.scale'],
            'unknown type key' => [['t' => ['mono' => 'Menlo']], 't.mono'],
            'radius negative' => [['s' => ['radius' => -1]], 's.radius'],
            'stroke huge' => [['s' => ['stroke' => 500]], 's.stroke'],
            'bg garbage' => [['bg' => 'not a colour'], 'bg'],
            'bg gradient one stop' => [['bg' => ['g' => 'linear', 'a' => 90, 'stops' => [[0, '#000000']]]], 'bg'],
            'bg gradient bad angle' => [['bg' => ['g' => 'linear', 'a' => 400, 'stops' => [[0, '#000000'], [1, '#ffffff']]]], 'bg'],
            'bg image string asset' => [['bg' => ['img' => ['asset' => '7'], 'fit' => 'cover']], 'bg'],
            'bg image bad fit' => [['bg' => ['img' => ['asset' => 7], 'fit' => 'stretch']], 'bg'],
            'bg image dim too high' => [['bg' => ['img' => ['asset' => 7], 'dim' => 2]], 'bg'],
            'not an object' => [[], 'theme'],
        ];
    }

    #[DataProvider('invalidThemes')]
    public function test_invalid_values_are_errors_with_a_path(array $theme, string $path): void
    {
        $result = $path === 'theme' ? ThemeTokens::validate('nope') : ThemeTokens::validate($theme);

        $this->assertArrayHasKey($path, $result['errors']);
    }

    public function test_valid_backgrounds_pass_and_are_canonicalised(): void
    {
        foreach ([null, '#FFF', '@bg', ['g' => 'linear', 'a' => 90, 'stops' => [[0, '#112233'], [1, '@accent']]], ['img' => ['asset' => 7], 'fit' => 'cover', 'dim' => 0.3]] as $bg) {
            $this->assertSame([], ThemeTokens::validate(['bg' => $bg])['errors']);
        }

        $this->assertSame('#ffffff', ThemeTokens::normalise(['bg' => '#FFF'])['bg']);
        $this->assertSame(['img' => ['asset' => 7], 'fit' => 'cover', 'dim' => 0.3], ThemeTokens::normalise(['bg' => ['img' => ['asset' => 7], 'dim' => 0.3]])['bg']);
    }

    public function test_low_contrast_warns_but_never_rejects(): void
    {
        $result = ThemeTokens::validate(['c' => ['bg' => '#ffffff', 'ink' => '#cccccc', 'muted' => '#eeeeee', 'accent' => '#ffff00']]);

        $this->assertSame([], $result['errors']);
        $text = implode(' ', $result['warnings']);
        $this->assertStringContainsString('Body text', $text);
        $this->assertStringContainsString('Secondary text', $text);
        $this->assertStringContainsString('accent', $text);
        $this->assertGreaterThanOrEqual(3, count($result['warnings']));
    }

    public function test_unknown_top_level_keys_warn(): void
    {
        $result = ThemeTokens::validate(['name' => 'x', 'extra' => 1]);

        $this->assertSame([], $result['errors']);
        $this->assertCount(1, $result['warnings']);
    }

    public function test_contrast_ratio_matches_wcag(): void
    {
        $this->assertEqualsWithDelta(21.0, ThemeTokens::contrast('#000000', '#ffffff'), 0.01);
        $this->assertEqualsWithDelta(1.0, ThemeTokens::contrast('#336699', '#336699'), 0.001);
        $this->assertEqualsWithDelta(4.83, ThemeTokens::contrast('#6b7280', '#ffffff'), 0.02);
    }

    public function test_colour_helpers(): void
    {
        $this->assertTrue(ThemeTokens::isColour('@chart8'));
        $this->assertFalse(ThemeTokens::isColour('@chart9'));
        $this->assertFalse(ThemeTokens::isColour('@nope'));
        $this->assertTrue(ThemeTokens::isHex('#aBc'));
        $this->assertFalse(ThemeTokens::isHex('#abcd'));
        $this->assertSame('#808080', ThemeTokens::mix('#000000', '#ffffff', 0.5));
    }

    public function test_brand_kit_converts_to_a_valid_theme_with_roles_fonts_and_chart(): void
    {
        $kit = new BrandKit(['name' => 'Acme', 'tokens' => [
            'palette' => [
                ['name' => 'Acme red', 'hex' => '#d71920', 'role' => 'accent'],
                ['name' => 'Charcoal', 'hex' => '#222222', 'role' => 'ink'],
                ['name' => 'Sand', 'hex' => '#f2e8d5'],
                ['name' => 'Teal', 'hex' => '#0f766e'],
            ],
            'fonts' => [['family' => 'Playfair Display', 'role' => 'heading'], ['family' => 'Source Sans 3', 'role' => 'body']],
            'logos' => [],
        ]]);
        $kit->id = 5;

        $theme = $kit->toTheme();

        $this->assertSame('brand-kit:5', $theme['id']);
        $this->assertSame('Acme', $theme['name']);
        $this->assertSame('#d71920', $theme['c']['accent']);
        $this->assertSame('#222222', $theme['c']['ink']);
        $this->assertSame('#f2e8d5', $theme['c']['accent2'], 'the first colour without a role becomes the second accent');
        $this->assertSame(['#d71920', '#222222', '#f2e8d5', '#0f766e'], array_slice($theme['c']['chart'], 0, 4));
        $this->assertSame(8, count($theme['c']['chart']));
        $this->assertSame('Playfair Display', $theme['t']['heading']);
        $this->assertSame('Source Sans 3', $theme['t']['body']);
        $this->assertSame([], ThemeTokens::validate($theme)['errors']);
    }

    public function test_a_dark_brand_background_gets_legible_derived_neutrals(): void
    {
        $kit = new BrandKit(['name' => 'Night', 'tokens' => ['palette' => [['name' => 'Ink blue', 'hex' => '#0b1020', 'role' => 'bg'], ['name' => 'Lime', 'hex' => '#a3e635']], 'fonts' => [['family' => 'Inter', 'role' => 'body']], 'logos' => []]]);
        $kit->id = 1;

        $theme = $kit->toTheme();

        $this->assertSame('#0b1020', $theme['c']['bg']);
        $this->assertSame('#f9fafb', $theme['c']['ink']);
        $this->assertSame('#a3e635', $theme['c']['accent']);
        $this->assertSame('Inter', $theme['t']['heading'], 'a lone font serves both roles');
        $this->assertSame([], ThemeTokens::validate($theme)['warnings']);
    }

    public function test_an_empty_kit_converts_to_slate_colours(): void
    {
        $kit = new BrandKit(['name' => 'Empty', 'tokens' => ['palette' => [], 'fonts' => [], 'logos' => []]]);
        $kit->id = 2;

        $this->assertSame(ThemeLibrary::find('slate')['c'], $kit->toTheme()['c']);
    }

    public function test_layout_validator_accepts_the_spec_shape_and_drops_nothing_valid(): void
    {
        $result = LayoutDefinition::validate([
            ['id' => 'title', 'type' => 'text', 'role' => 'title', 'x' => 0.08, 'y' => 0.3, 'w' => 0.84, 'h' => 0.2, 'size' => 96, 'weight' => 700, 'font' => '@heading', 'color' => '@ink', 'align' => 'left', 'valign' => 'middle', 'hint' => 'Click to add a title'],
            ['type' => 'image', 'role' => 'media', 'x' => 960, 'y' => 0, 'w' => 960, 'h' => 1080, 'units' => 'slide', 'fit' => 'cover', 'mask' => 'none'],
            ['id' => 'bar', 'type' => 'shape', 'kind' => 'rect', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 0.02, 'fill' => '#2563EB', 'radius' => 0, 'op' => 0.9],
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertCount(3, $result['definition']);
        $this->assertSame('p2', $result['definition'][1]['id'], 'a missing id is generated');
        $this->assertSame('#2563eb', $result['definition'][2]['fill']);
        $this->assertSame('fraction', $result['definition'][0]['units']);
    }
}
