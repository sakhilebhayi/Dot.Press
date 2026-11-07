<?php

namespace App\Services\Press\Intel;

/** Deterministic ids that satisfy the spec pattern (1 to 40 chars of A-Za-z0-9_-). */
final class Ids
{
    public static function make(string $prefix, string ...$seed): string
    {
        $prefix = preg_replace('/[^A-Za-z0-9_-]/', '', $prefix) ?: 'x';

        return substr($prefix, 0, 12).'_'.substr(sha1(implode('|', $seed)), 0, 12);
    }

    public static function valid(string $id): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id);
    }
}
