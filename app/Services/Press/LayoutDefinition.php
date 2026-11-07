<?php

namespace App\Services\Press;

/**
 * Conservative validator for a saved layout: a list of placeholder-style elements in the spec's element shape.
 * Only known keys survive, every value is range checked, and the result is rebuilt rather than passed through.
 *
 * Geometry is either fractions of the slide (`units: "fraction"`, the default; x y w h in 0..1 and the box inside
 * the slide) or slide units (`units: "slide"`, 0..20000). Font sizes are in slide units of a 1920 x 1080 slide.
 */
final class LayoutDefinition
{
    public const MAX_ELEMENTS = 40;

    public const KINDS = ['custom', 'title', 'section', 'content', 'media', 'data', 'closing'];

    public const TYPES = ['text', 'shape', 'image', 'video', 'audio', 'icon', 'chart', 'table'];

    public const ROLES = ['title', 'subtitle', 'body', 'media', 'caption', 'footer', 'number', 'kpi'];

    public const SHAPES = ['rect', 'round', 'ellipse', 'triangle', 'diamond', 'pentagon', 'hexagon', 'star', 'chevron', 'arrow-r', 'arrow-l', 'callout', 'cross', 'heart', 'line', 'arrow'];

    private const ALLOWED = ['id', 'type', 'name', 'role', 'x', 'y', 'w', 'h', 'units', 'rot', 'op', 'hint', 'kind', 'fill', 'stroke', 'sw', 'radius', 'color', 'font', 'size', 'weight', 'align', 'valign', 'lh', 'fit', 'mask'];

    /**
     * @return array{errors: array<string, string>, definition: list<array<string, mixed>>}
     */
    public static function validate(mixed $definition): array
    {
        $errors = [];
        $clean = [];

        if (! is_array($definition) || ! array_is_list($definition) || $definition === []) {
            return ['errors' => ['definition' => 'A layout is a list of at least one placeholder element.'], 'definition' => []];
        }
        if (count($definition) > self::MAX_ELEMENTS) {
            return ['errors' => ['definition' => 'A layout holds at most '.self::MAX_ELEMENTS.' elements.'], 'definition' => []];
        }

        $seen = [];
        foreach ($definition as $index => $element) {
            $path = "definition.{$index}";

            if (! is_array($element) || array_is_list($element)) {
                $errors[$path] = 'Each element must be an object.';

                continue;
            }

            foreach (array_diff(array_keys($element), self::ALLOWED) as $unknown) {
                $errors["{$path}.".(is_string($unknown) ? mb_substr($unknown, 0, 40) : $unknown)] = 'Unknown key.';
            }

            $before = count($errors);
            $entry = self::element($element, $path, $index, $errors);

            if (count($errors) === $before) {
                if (isset($seen[$entry['id']])) {
                    $errors["{$path}.id"] = 'Element ids must be unique within a layout.';

                    continue;
                }
                $seen[$entry['id']] = true;
                $clean[] = $entry;
            }
        }

        return ['errors' => $errors, 'definition' => $errors === [] ? $clean : []];
    }

    /**
     * @param  array<string, mixed>  $element
     * @return array<string, mixed>
     */
    private static function element(array $element, string $path, int|string $index, array &$errors): array
    {
        $out = [];

        $id = $element['id'] ?? 'p'.($index + 1);
        if (! is_string($id) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id) !== 1) {
            $errors["{$path}.id"] = 'The id is 1 to 40 characters of letters, digits, underscore or hyphen.';
        }
        $out['id'] = $id;

        $type = $element['type'] ?? null;
        if (! is_string($type) || ! in_array($type, self::TYPES, true)) {
            $errors["{$path}.type"] = 'The type is one of '.implode(', ', self::TYPES).'.';
        }
        $out['type'] = $type;

        if (isset($element['role'])) {
            if (! is_string($element['role']) || ! in_array($element['role'], self::ROLES, true)) {
                $errors["{$path}.role"] = 'The role is one of '.implode(', ', self::ROLES).'.';
            } else {
                $out['role'] = $element['role'];
            }
        }

        foreach (['name' => 80, 'hint' => 120] as $key => $max) {
            if (isset($element[$key])) {
                if (! is_string($element[$key]) || mb_strlen($element[$key]) > $max) {
                    $errors["{$path}.{$key}"] = "The {$key} is text of up to {$max} characters.";
                } else {
                    $out[$key] = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $element[$key]));
                }
            }
        }

        $units = $element['units'] ?? 'fraction';
        if (! in_array($units, ['fraction', 'slide'], true)) {
            $errors["{$path}.units"] = 'Units are "fraction" or "slide".';
            $units = 'fraction';
        }
        $out['units'] = $units;

        self::geometry($element, $units, $path, $out, $errors);

        self::number($element, 'rot', -360, 360, $path, $out, $errors);
        self::number($element, 'op', 0, 1, $path, $out, $errors);
        self::number($element, 'sw', 0, 100, $path, $out, $errors);
        self::number($element, 'radius', 0, 500, $path, $out, $errors);
        self::number($element, 'size', 1, 1000, $path, $out, $errors);
        self::number($element, 'lh', 0.5, 3, $path, $out, $errors);

        if (isset($element['weight'])) {
            if (! is_int($element['weight']) || $element['weight'] < 100 || $element['weight'] > 900) {
                $errors["{$path}.weight"] = 'The weight is a whole number from 100 to 900.';
            } else {
                $out['weight'] = $element['weight'];
            }
        }

        foreach (['fill', 'stroke', 'color'] as $key) {
            if (isset($element[$key])) {
                if (! ThemeTokens::isColour($element[$key])) {
                    $errors["{$path}.{$key}"] = 'A colour is a hex value or a theme token such as @accent.';
                } else {
                    $out[$key] = is_string($element[$key]) && ThemeTokens::isHex($element[$key]) ? ThemeTokens::canonicalHex($element[$key]) : $element[$key];
                }
            }
        }

        if (isset($element['font'])) {
            if (! is_string($element['font']) || ! (in_array($element['font'], ['@heading', '@body'], true) || ThemeTokens::isFont($element['font']))) {
                $errors["{$path}.font"] = 'The font is @heading, @body or a family name.';
            } else {
                $out['font'] = trim($element['font']);
            }
        }

        foreach ([
            'align' => ['left', 'center', 'right', 'justify'],
            'valign' => ['top', 'middle', 'bottom'],
            'fit' => ['none', 'shrink', 'cover', 'contain', 'fill'],
            'mask' => ['none', 'round', 'circle', 'ellipse', 'triangle', 'star', 'hexagon'],
            'kind' => self::SHAPES,
        ] as $key => $allowed) {
            if (isset($element[$key])) {
                if (! is_string($element[$key]) || ! in_array($element[$key], $allowed, true)) {
                    $errors["{$path}.{$key}"] = "The {$key} is one of ".implode(', ', $allowed).'.';
                } else {
                    $out[$key] = $element[$key];
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $element
     * @param  array<string, mixed>  $out
     */
    private static function geometry(array $element, string $units, string $path, array &$out, array &$errors): void
    {
        $values = [];

        foreach (['x', 'y', 'w', 'h'] as $key) {
            $value = $element[$key] ?? null;
            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
                $errors["{$path}.{$key}"] = "The {$key} is a number.";

                continue;
            }
            $values[$key] = (float) $value;
        }

        if (count($values) < 4) {
            return;
        }

        $max = $units === 'fraction' ? 1.0 : 20000.0;

        foreach ($values as $key => $value) {
            if ($value < 0 || $value > $max) {
                $errors["{$path}.{$key}"] = $units === 'fraction' ? 'Fractions of the slide are from 0 to 1.' : 'Slide units are from 0 to 20000.';
            }
        }
        if ($values['w'] <= 0 || $values['h'] <= 0) {
            $errors["{$path}.w"] = 'Width and height must be above zero.';
        }
        if ($units === 'fraction' && ($values['x'] + $values['w'] > 1.0001 || $values['y'] + $values['h'] > 1.0001)) {
            $errors["{$path}.x"] = 'The element must sit inside the slide.';
        }

        foreach ($values as $key => $value) {
            $out[$key] = round($value, 5);
        }
    }

    /**
     * @param  array<string, mixed>  $element
     * @param  array<string, mixed>  $out
     */
    private static function number(array $element, string $key, float $min, float $max, string $path, array &$out, array &$errors): void
    {
        if (! isset($element[$key])) {
            return;
        }

        $value = $element[$key];
        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value < $min || $value > $max) {
            $errors["{$path}.{$key}"] = "The {$key} is a number from {$min} to {$max}.";

            return;
        }

        $out[$key] = round((float) $value, 5);
    }
}
