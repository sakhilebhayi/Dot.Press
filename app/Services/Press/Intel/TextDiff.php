<?php

namespace App\Services\Press\Intel;

/**
 * A plain text diff the UI can show as is. Word level, inline: removed words appear as [-word-]
 * and added words as {+word+}; unchanged text is left alone.
 */
final class TextDiff
{
    private const MAX_TOKENS = 600;

    public static function words(string $before, string $after): string
    {
        $a = self::tokens($before);
        $b = self::tokens($after);
        if (count($a) > self::MAX_TOKENS || count($b) > self::MAX_TOKENS) {
            return "[-{$before}-]{+{$after}+}";
        }

        $n = count($a);
        $m = count($b);
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $out = [];
        $i = $j = 0;
        $del = [];
        $add = [];
        $flush = function () use (&$out, &$del, &$add) {
            if ($del !== []) {
                $out[] = '[-'.trim(implode('', $del)).'-]';
            }
            if ($add !== []) {
                $out[] = '{+'.trim(implode('', $add)).'+}';
            }
            $del = $add = [];
        };
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $flush();
                $out[] = trim($a[$i]);
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $del[] = $a[$i++];
            } else {
                $add[] = $b[$j++];
            }
        }
        while ($i < $n) {
            $del[] = $a[$i++];
        }
        while ($j < $m) {
            $add[] = $b[$j++];
        }
        $flush();

        return trim(implode(' ', array_filter($out, fn ($x) => $x !== '')));
    }

    /** @return list<string> */
    private static function tokens(string $s): array
    {
        preg_match_all('/\S+\s*/u', Text::clean($s), $m);

        return $m[0];
    }
}
