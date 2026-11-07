<?php

namespace App\Services\Press\Intel;

/**
 * A small validator for the JSON the model returns. It understands the subset of JSON Schema the
 * Intel prompts use: type (object, array, string, number, integer, boolean, null; or a list of
 * them), required, properties, items, enum, maxItems, minItems, maxLength, minimum, maximum.
 * Unknown properties are allowed here and dropped by the callers that read the result.
 */
final class JsonShape
{
    /** @return list<string> human readable problems, empty when the value fits */
    public static function validate(mixed $value, array $schema, string $path = '$'): array
    {
        $errors = [];
        $types = isset($schema['type']) ? (array) $schema['type'] : [];

        if ($types !== [] && ! self::matchesAny($value, $types)) {
            return ["{$path}: expected ".implode('|', $types).', got '.get_debug_type($value)];
        }
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = "{$path}: not one of the allowed values";
        }
        if (is_string($value) && isset($schema['maxLength']) && mb_strlen($value) > $schema['maxLength']) {
            $errors[] = "{$path}: longer than {$schema['maxLength']} characters";
        }
        if ((is_int($value) || is_float($value))) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $errors[] = "{$path}: below {$schema['minimum']}";
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $errors[] = "{$path}: above {$schema['maximum']}";
            }
        }
        $objectOnly = in_array('object', $types, true) && ! in_array('array', $types, true);
        if (is_array($value) && array_is_list($value) && ! $objectOnly) {
            if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
                $errors[] = "{$path}: more than {$schema['maxItems']} items";
            }
            if (isset($schema['minItems']) && count($value) < $schema['minItems']) {
                $errors[] = "{$path}: fewer than {$schema['minItems']} items";
            }
            if (isset($schema['items']) && is_array($schema['items'])) {
                foreach ($value as $i => $item) {
                    array_push($errors, ...self::validate($item, $schema['items'], "{$path}[{$i}]"));
                }
            }
        } elseif (is_array($value)) {
            foreach ($schema['required'] ?? [] as $key) {
                if (! array_key_exists($key, $value)) {
                    $errors[] = "{$path}: missing {$key}";
                }
            }
            foreach ($schema['properties'] ?? [] as $key => $sub) {
                if (array_key_exists($key, $value) && is_array($sub)) {
                    array_push($errors, ...self::validate($value[$key], $sub, "{$path}.{$key}"));
                }
            }
        }

        return $errors;
    }

    /** @param  list<string>  $types */
    private static function matchesAny(mixed $v, array $types): bool
    {
        foreach ($types as $t) {
            $ok = match ($t) {
                'object' => is_array($v) && ($v === [] || ! array_is_list($v)),
                'array' => is_array($v) && ($v === [] || array_is_list($v)),
                'string' => is_string($v),
                'number' => is_int($v) || is_float($v),
                'integer' => is_int($v) || (is_float($v) && floor($v) === $v),
                'boolean' => is_bool($v),
                'null' => $v === null,
                default => false,
            };
            if ($ok) {
                return true;
            }
        }

        return false;
    }
}
