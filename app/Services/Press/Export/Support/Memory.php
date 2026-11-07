<?php

namespace App\Services\Press\Export\Support;

/** Checks whether a big allocation (decoding a large picture) fits in the PHP memory limit, so a fatal error is avoided. */
final class Memory
{
    public static function limit(): int
    {
        $v = ini_get('memory_limit');
        if ($v === false || $v === '' || $v === '-1') {
            return -1;
        }
        $n = (int) $v;

        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1073741824,
            'm' => $n * 1048576,
            'k' => $n * 1024,
            default => $n,
        };
    }

    /** Whether $bytes more can be allocated with a margin left for the work that follows. */
    public static function fits(int $bytes): bool
    {
        $limit = self::limit();
        if ($limit < 0) {
            return true;
        }

        return memory_get_usage() + $bytes * 1.5 < $limit;
    }
}
