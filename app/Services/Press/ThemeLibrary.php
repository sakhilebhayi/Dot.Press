<?php

namespace App\Services\Press;

/**
 * The registry of built-in themes. Each entry is a plain theme definition in the shape of spec section 3
 * (id, name, c, t, s, bg). To add a theme, append a definition to definitions(): ids are lower case
 * slugs, `c` is complete and every colour is a hex value. The tests check each definition against ThemeTokens.
 */
final class ThemeLibrary
{
    /** @var list<array<string, mixed>>|null */
    private static ?array $cache = null;

    /**
     * Every built-in theme, normalised, in display order.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return self::$cache ??= array_map(
            fn (array $definition): array => ThemeTokens::toDeckTheme($definition['id'], $definition['name'], $definition),
            self::definitions(),
        );
    }

    /** @return ?array<string, mixed> */
    public static function find(string $id): ?array
    {
        foreach (self::all() as $theme) {
            if ($theme['id'] === $id) {
                return $theme;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_column(self::all(), 'id');
    }

    /**
     * The values missing parts of a theme are filled from: the first theme in the library.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return self::definitions()[0];
    }

    /**
     * @param  array<string, mixed>  $c
     * @param  array<string, mixed>  $t
     * @param  array<string, mixed>  $s
     * @return array<string, mixed>
     */
    private static function theme(string $id, string $name, array $c, array $t, array $s, mixed $bg = null): array
    {
        return ['id' => $id, 'name' => $name, 'c' => $c, 't' => $t, 's' => $s, 'bg' => $bg];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function definitions(): array
    {
        return [
            [
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
            ],
            // Further built-in themes follow slate. Each one is built for a kind of room or a kind of document.
            // Rules every theme satisfies (tests/Unit/Press/LibraryThemesTest enforces them): ink on bg and on surface
            // at least 7:1, muted at least 4.5:1, accent and accent2 on bg at least 3:1, the page colour readable on an
            // accent ground (section slides), and eight chart colours whose neighbours stay apart under protan and
            // deutan simulation (the dataviz validator's gates). Chart palettes are the validated eight-hue ring,
            // turned a few degrees per theme so each deck has its own cast without losing separation.
            //
            // boardroom       board and executive decks: ivory page, navy ink, a serif heading (Source Serif 4)
            // field-report    warm sand page for site and research reports: Literata with IBM Plex Sans
            // workshop        rounded and friendly, thick strokes, DM Sans throughout, for facilitated sessions
            // annual-report   white page, claret accent, Playfair Display as an editorial heading for formal documents
            // night-briefing  dark navy for darkened rooms, Space Grotesk headings, a gradient so subtle it reads as flat
            // projector       high contrast (21:1 ink), heavy Inter, thick strokes: survives a washed-out projector
            // engineering     cool grey, squared corners, IBM Plex Sans: technical proposals and plant reports
            // site-operations charcoal and safety yellow, Manrope: mine and plant operations, readable in a control room
            // ledger          green on near white, Source Serif 4 headings with Inter figures: finance and budgets
            // lecture         paper white, indigo, Literata headings and Public Sans: teaching and research talks
            // launch          white, flat, vermilion: Space Grotesk and DM Sans for announcements and pitches
            self::theme('boardroom', 'Boardroom',
                ['bg' => '#fafaf7', 'surface' => '#eeede7', 'ink' => '#14213d', 'muted' => '#4b5870', 'line' => '#cfcdc4', 'accent' => '#1f4e8c', 'accent2' => '#b4690e', 'good' => '#1f7a4d', 'warn' => '#9a5a06', 'bad' => '#b3261e',
                    'chart' => ['#2a78d6', '#eb6834', '#1baf7a', '#eaa016', '#e87ba4', '#008300', '#6250d6', '#e34948']],
                ['heading' => 'Source Serif 4', 'body' => 'Public Sans', 'hw' => 600, 'scale' => 1], ['radius' => 4, 'stroke' => 2]),
            self::theme('field-report', 'Field report',
                ['bg' => '#f5eee0', 'surface' => '#eadfca', 'ink' => '#2a2017', 'muted' => '#5e4f3e', 'line' => '#cdbfa5', 'accent' => '#a24a1c', 'accent2' => '#3d6b4a', 'good' => '#2f6b3f', 'warn' => '#8f5a00', 'bad' => '#a8281c',
                    'chart' => ['#087bd4', '#ec6542', '#33ae71', '#f19c12', '#e57cac', '#367f04', '#5754d8', '#e34755']],
                ['heading' => 'Literata', 'body' => 'IBM Plex Sans', 'hw' => 600, 'scale' => 1], ['radius' => 6, 'stroke' => 2]),
            self::theme('workshop', 'Workshop',
                ['bg' => '#fffdf7', 'surface' => '#eef4f1', 'ink' => '#1c2b33', 'muted' => '#4d6068', 'line' => '#cdd8d4', 'accent' => '#0b7a75', 'accent2' => '#d6336c', 'good' => '#237a3b', 'warn' => '#a35a00', 'bad' => '#b42318',
                    'chart' => ['#4f71d8', '#e37104', '#0aad8f', '#daa917', '#ed7b90', '#078048', '#7847cd', '#e24f20']],
                ['heading' => 'DM Sans', 'body' => 'DM Sans', 'hw' => 700, 'scale' => 1], ['radius' => 20, 'stroke' => 3]),
            self::theme('annual-report', 'Annual report',
                ['bg' => '#ffffff', 'surface' => '#f4f1ee', 'ink' => '#1a1a1a', 'muted' => '#55504b', 'line' => '#d8d2cb', 'accent' => '#8c1c2f', 'accent2' => '#1f5f5b', 'good' => '#1f6f43', 'warn' => '#9a5a06', 'bad' => '#b3261e',
                    'chart' => ['#c64655', '#07b07a', '#8b87e8', '#1ac9cc', '#9aaf3c', '#3462d1', '#ac4807', '#02a339']],
                ['heading' => 'Playfair Display', 'body' => 'DM Sans', 'hw' => 700, 'scale' => 1], ['radius' => 0, 'stroke' => 2]),
            self::theme('night-briefing', 'Night briefing',
                ['bg' => '#0d1320', 'surface' => '#17213a', 'ink' => '#eef2fa', 'muted' => '#a2b1cc', 'line' => '#2d3b5c', 'accent' => '#6cb4ff', 'accent2' => '#ffb454', 'good' => '#4cc38a', 'warn' => '#f2b441', 'bad' => '#ff7a7a',
                    'chart' => ['#3987e5', '#d95926', '#199e70', '#c4830d', '#d55181', '#008300', '#8d82e6', '#e36464']],
                ['heading' => 'Space Grotesk', 'body' => 'IBM Plex Sans', 'hw' => 600, 'scale' => 1], ['radius' => 10, 'stroke' => 2],
                ['g' => 'linear', 'a' => 165, 'stops' => [[0, '#0d1320'], [1, '#121b30']]]),
            self::theme('projector', 'Projector',
                ['bg' => '#ffffff', 'surface' => '#eceff3', 'ink' => '#000000', 'muted' => '#333333', 'line' => '#555555', 'accent' => '#0b3fcc', 'accent2' => '#a35200', 'good' => '#0a6b2d', 'warn' => '#7a4a00', 'bad' => '#a40000',
                    'chart' => ['#3976d7', '#e96a26', '#11af82', '#e5a30b', '#ea7b9d', '#00822a', '#6a4dd4', '#e34b3d']],
                ['heading' => 'Inter', 'body' => 'Inter', 'hw' => 800, 'scale' => 1.05], ['radius' => 8, 'stroke' => 4]),
            self::theme('engineering', 'Engineering',
                ['bg' => '#f3f5f6', 'surface' => '#e4e9ec', 'ink' => '#0e1a21', 'muted' => '#46555e', 'line' => '#c3ccd2', 'accent' => '#00657d', 'accent2' => '#c2410c', 'good' => '#17703a', 'warn' => '#9a5a06', 'bad' => '#b3261e',
                    'chart' => ['#4772d8', '#e76e0c', '#00ae8a', '#dfa700', '#ec7b95', '#02813f', '#7349d0', '#e24d2c']],
                ['heading' => 'IBM Plex Sans', 'body' => 'IBM Plex Sans', 'hw' => 600, 'scale' => 1], ['radius' => 2, 'stroke' => 2]),
            self::theme('site-operations', 'Site operations',
                ['bg' => '#1a1917', 'surface' => '#272521', 'ink' => '#f4f1ea', 'muted' => '#bdb7aa', 'line' => '#3b3833', 'accent' => '#f2b705', 'accent2' => '#ff7a45', 'good' => '#6cc08b', 'warn' => '#f2b705', 'bad' => '#ff6b5e',
                    'chart' => ['#5980e7', '#d06300', '#009d81', '#b88a0e', '#d9516b', '#008144', '#9d7cdf', '#e2674e']],
                ['heading' => 'Manrope', 'body' => 'Manrope', 'hw' => 800, 'scale' => 1], ['radius' => 4, 'stroke' => 3]),
            self::theme('ledger', 'Ledger',
                ['bg' => '#fbfcfa', 'surface' => '#edf2ee', 'ink' => '#0f2419', 'muted' => '#43584c', 'line' => '#cad6ce', 'accent' => '#0b6b4a', 'accent2' => '#8a5a00', 'good' => '#16803c', 'warn' => '#8a5a00', 'bad' => '#b3261e',
                    'chart' => ['#0a7cd1', '#ed6545', '#36ae70', '#f29b16', '#e57cae', '#397f00', '#5555d9', '#e34757']],
                ['heading' => 'Source Serif 4', 'body' => 'Inter', 'hw' => 600, 'scale' => 1], ['radius' => 6, 'stroke' => 2]),
            self::theme('lecture', 'Lecture',
                ['bg' => '#fffdf8', 'surface' => '#f3efe4', 'ink' => '#1d1b2f', 'muted' => '#54516a', 'line' => '#d9d3c3', 'accent' => '#3347b3', 'accent2' => '#b5470b', 'good' => '#1f7a45', 'warn' => '#8f5a00', 'bad' => '#b3261e',
                    'chart' => ['#137ad4', '#ec663f', '#2fae73', '#f09d0e', '#e67baa', '#2f8000', '#5a53d8', '#e34852']],
                ['heading' => 'Literata', 'body' => 'Public Sans', 'hw' => 700, 'scale' => 1], ['radius' => 14, 'stroke' => 2]),
            self::theme('launch', 'Launch',
                ['bg' => '#ffffff', 'surface' => '#f5f3ef', 'ink' => '#121212', 'muted' => '#4a4a4a', 'line' => '#d6d3cd', 'accent' => '#d9381e', 'accent2' => '#1d4ed8', 'good' => '#1b7f3b', 'warn' => '#a35a00', 'bad' => '#b3261e',
                    'chart' => ['#c64a35', '#1aad94', '#a180df', '#0ac6e1', '#7eb656', '#5958cf', '#9d5706', '#0c9f6c']],
                ['heading' => 'Space Grotesk', 'body' => 'DM Sans', 'hw' => 700, 'scale' => 1], ['radius' => 0, 'stroke' => 3]),
        ];
    }
}
