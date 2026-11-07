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
            // Further built-in themes are added here, after slate. Keep slate first: it supplies the defaults.
        ];
    }
}
