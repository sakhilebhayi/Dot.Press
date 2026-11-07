<?php

namespace App\Services\Press;

use App\Models\Asset;

/**
 * Validation of a brand kit's tokens:
 *
 *   palette: up to 12 of {name, hex, role?}   role is one of the theme colour keys, used once at most
 *   fonts:   up to 3 of {family, role}        role is heading, body or accent, used once at most
 *   logos:   up to 6 asset ids                images that belong to the kit's team
 */
final class BrandKitTokens
{
    public const MAX_PALETTE = 12;

    public const MAX_FONTS = 3;

    public const MAX_LOGOS = 6;

    public const FONT_ROLES = ['heading', 'body', 'accent'];

    /**
     * @return array{errors: array<string, string>, tokens: array{palette: list<array<string, string>>, fonts: list<array<string, string>>, logos: list<int>}}
     */
    public static function validate(mixed $input, int $teamId): array
    {
        $errors = [];
        $tokens = ['palette' => [], 'fonts' => [], 'logos' => []];

        if (! is_array($input) || ($input !== [] && array_is_list($input))) {
            return ['errors' => ['tokens' => 'Brand kit tokens must be an object with palette, fonts and logos.'], 'tokens' => $tokens];
        }

        foreach (array_diff(array_keys($input), ['palette', 'fonts', 'logos']) as $unknown) {
            $errors['tokens.'.(is_string($unknown) ? mb_substr($unknown, 0, 40) : $unknown)] = 'Unknown key.';
        }

        self::palette($input['palette'] ?? [], $tokens, $errors);
        self::fonts($input['fonts'] ?? [], $tokens, $errors);
        self::logos($input['logos'] ?? [], $teamId, $tokens, $errors);

        return ['errors' => $errors, 'tokens' => $tokens];
    }

    private static function palette(mixed $palette, array &$tokens, array &$errors): void
    {
        if (! is_array($palette) || ! array_is_list($palette)) {
            $errors['tokens.palette'] = 'The palette is a list of colours.';

            return;
        }
        if (count($palette) > self::MAX_PALETTE) {
            $errors['tokens.palette'] = 'A palette holds at most '.self::MAX_PALETTE.' colours.';

            return;
        }

        $roles = [];
        foreach ($palette as $index => $colour) {
            $before = count($errors);
            $name = is_array($colour) ? ($colour['name'] ?? null) : null;
            $hex = is_array($colour) ? ($colour['hex'] ?? null) : null;
            $role = is_array($colour) ? ($colour['role'] ?? null) : null;

            if (! is_string($name) || trim($name) === '' || mb_strlen(trim($name)) > 40 || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
                $errors["tokens.palette.{$index}.name"] = 'Every colour needs a name of up to 40 characters.';
            }
            if (! ThemeTokens::isHex($hex)) {
                $errors["tokens.palette.{$index}.hex"] = 'The colour must be a hex value such as #2563eb.';
            }
            if ($role !== null && (! is_string($role) || ! in_array($role, ThemeTokens::COLOUR_KEYS, true))) {
                $errors["tokens.palette.{$index}.role"] = 'The role must be one of '.implode(', ', ThemeTokens::COLOUR_KEYS).'.';
            } elseif ($role !== null) {
                if (isset($roles[$role])) {
                    $errors["tokens.palette.{$index}.role"] = "Only one colour can be the {$role}.";
                }
                $roles[$role] = true;
            }

            if (count($errors) === $before) {
                $entry = ['name' => trim((string) $name), 'hex' => ThemeTokens::isHex($hex) ? ThemeTokens::canonicalHex($hex) : ''];
                if (is_string($role)) {
                    $entry['role'] = $role;
                }
                $tokens['palette'][] = $entry;
            }
        }
    }

    private static function fonts(mixed $fonts, array &$tokens, array &$errors): void
    {
        if (! is_array($fonts) || ! array_is_list($fonts)) {
            $errors['tokens.fonts'] = 'Fonts are a list of families with roles.';

            return;
        }
        if (count($fonts) > self::MAX_FONTS) {
            $errors['tokens.fonts'] = 'A brand kit holds at most '.self::MAX_FONTS.' font families.';

            return;
        }

        $roles = [];
        foreach ($fonts as $index => $font) {
            $family = is_array($font) ? ($font['family'] ?? null) : null;
            $role = is_array($font) ? ($font['role'] ?? null) : null;

            if (! ThemeTokens::isFont($family)) {
                $errors["tokens.fonts.{$index}.family"] = 'The family is a font name of up to 80 letters, digits, spaces, hyphens or dots.';
            }
            if (! is_string($role) || ! in_array($role, self::FONT_ROLES, true)) {
                $errors["tokens.fonts.{$index}.role"] = 'The role is heading, body or accent.';
            } elseif (isset($roles[$role])) {
                $errors["tokens.fonts.{$index}.role"] = "Only one font can be the {$role} font.";
            } else {
                $roles[$role] = true;
            }

            if (! isset($errors["tokens.fonts.{$index}.family"]) && ! isset($errors["tokens.fonts.{$index}.role"])) {
                $tokens['fonts'][] = ['family' => trim($family), 'role' => $role];
            }
        }
    }

    private static function logos(mixed $logos, int $teamId, array &$tokens, array &$errors): void
    {
        if (! is_array($logos) || ! array_is_list($logos)) {
            $errors['tokens.logos'] = 'Logos are a list of asset ids.';

            return;
        }
        if (count($logos) > self::MAX_LOGOS) {
            $errors['tokens.logos'] = 'A brand kit holds at most '.self::MAX_LOGOS.' logos.';

            return;
        }

        $ids = [];
        foreach ($logos as $index => $id) {
            if (! is_int($id) || $id < 1) {
                $errors["tokens.logos.{$index}"] = 'A logo is an asset id.';

                continue;
            }
            $ids[$id] = true;
        }

        if ($ids === [] || isset($errors['tokens.logos'])) {
            $tokens['logos'] = [];

            return;
        }

        $found = Asset::withoutGlobalScopes()
            ->whereIn('id', array_keys($ids))
            ->whereIn('kind', ['image'])
            ->whereHas('project', fn ($query) => $query->withoutGlobalScopes()->where('team_id', $teamId))
            ->pluck('id')
            ->all();

        foreach ($logos as $index => $id) {
            if (is_int($id) && $id > 0 && ! in_array($id, $found, true)) {
                $errors["tokens.logos.{$index}"] = 'That image does not exist in this team\'s asset library.';
            }
        }

        $tokens['logos'] = array_values(array_intersect(array_keys($ids), $found));
    }
}
