<?php

namespace App\Services\Press\Intel;

/**
 * Plain text helpers shared by every Intel service: sentence splitting that knows
 * abbreviations and decimals, word lists, stop words, shingles and similarity.
 * Everything is pure and works on UTF-8 strings.
 */
final class Text
{
    /** Words that carry no topic on their own (English). */
    public const STOP = [
        'a', 'an', 'the', 'and', 'or', 'but', 'if', 'then', 'else', 'of', 'to', 'in', 'on', 'at', 'by', 'for', 'with',
        'from', 'as', 'is', 'are', 'was', 'were', 'be', 'been', 'being', 'it', 'its', 'this', 'that', 'these', 'those',
        'we', 'our', 'ours', 'you', 'your', 'they', 'their', 'them', 'he', 'she', 'his', 'her', 'i', 'my', 'me', 'us',
        'will', 'would', 'can', 'could', 'should', 'may', 'might', 'must', 'shall', 'do', 'does', 'did', 'done', 'have',
        'has', 'had', 'not', 'no', 'so', 'than', 'too', 'very', 'also', 'into', 'over', 'out', 'up', 'down', 'about',
        'per', 'via', 'more', 'most', 'some', 'any', 'all', 'each', 'both', 'such', 'what', 'which', 'who', 'whom',
        'when', 'where', 'why', 'how', 'there', 'here', 'just', 'only', 'one', 'two', 'new', 'now', 'while', 'after',
        'before', 'between', 'during', 'under', 'against', 'through', 'within', 'without', 'because', 'since', 'until',
    ];

    private const ABBREVIATIONS = [
        'e.g', 'i.e', 'etc', 'vs', 'mr', 'mrs', 'ms', 'dr', 'prof', 'inc', 'ltd', 'co', 'approx', 'no', 'fig', 'st',
        'jr', 'sr', 'cf', 'al', 'est', 'dept', 'corp', 'pty', 'ca', 'viz', 'mt', 'u.s', 'u.k',
    ];

    public static function clean(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\u{00A0}", "\u{200B}"], ["\n", "\n", ' ', ''], $text);
        // Control characters other than tab and newline never belong in slide text.
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        return $text;
    }

    public static function collapse(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** @return list<string> */
    public static function sentences(string $text): array
    {
        $text = self::clean($text);
        $out = [];

        foreach (preg_split('/\n+/u', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = preg_split('/(?<=[.!?])["”)\]]*\s+(?=["“(\[]?[\p{Lu}\d])/u', $line) ?: [$line];
            $buffer = '';

            foreach ($parts as $part) {
                $buffer = $buffer === '' ? $part : $buffer.' '.$part;

                if (self::endsWithAbbreviation($buffer)) {
                    continue;
                }

                $out[] = trim($buffer);
                $buffer = '';
            }

            if ($buffer !== '') {
                $out[] = trim($buffer);
            }
        }

        return array_values(array_filter($out, fn ($s) => $s !== ''));
    }

    private static function endsWithAbbreviation(string $s): bool
    {
        if (! preg_match('/([\p{L}.]+)\.["”)\]]*$/u', $s, $m)) {
            return false;
        }

        $word = mb_strtolower(rtrim($m[1], '.'));
        $word = ltrim($word, '(["“');

        if (in_array($word, self::ABBREVIATIONS, true)) {
            return true;
        }

        // A single capital letter is an initial ("J. Smith").
        return (bool) preg_match('/(^|\s)\p{Lu}\.$/u', $s);
    }

    /** @return list<string> lowercase word tokens (letters, digits, inner apostrophes and hyphens) */
    public static function words(string $text): array
    {
        preg_match_all("/[\p{L}\p{N}]+(?:['’\-][\p{L}\p{N}]+)*/u", mb_strtolower($text), $m);

        return $m[0];
    }

    public static function wordCount(string $text): int
    {
        return count(self::words($text));
    }

    /** @return list<string> lowercase content words (no stop words, at least 3 characters) */
    public static function contentWords(string $text): array
    {
        $out = [];
        foreach (self::words($text) as $w) {
            if (mb_strlen($w) < 3 || in_array($w, self::STOP, true) || ctype_digit($w)) {
                continue;
            }
            $out[] = self::stem($w);
        }

        return $out;
    }

    /** A deliberately light stemmer: enough to match plural and tense forms of the same word. */
    public static function stem(string $w): string
    {
        $len = mb_strlen($w);
        if ($len > 5 && str_ends_with($w, 'ies')) {
            return mb_substr($w, 0, -3).'y';
        }
        foreach (['ations', 'ation', 'ments', 'ment', 'ings', 'ing', 'ness', 'ed', 'es', 's'] as $suffix) {
            if ($len > mb_strlen($suffix) + 3 && str_ends_with($w, $suffix)) {
                return mb_substr($w, 0, -mb_strlen($suffix));
            }
        }

        return $w;
    }

    /** @return array<string, true> set of word n-gram hashes */
    public static function shingles(string $text, int $n = 3): array
    {
        $words = self::words($text);
        $set = [];
        if (count($words) < $n) {
            if ($words !== []) {
                $set[crc32(implode(' ', $words))] = true;
            }

            return $set;
        }
        for ($i = 0; $i <= count($words) - $n; $i++) {
            $set[crc32(implode(' ', array_slice($words, $i, $n)))] = true;
        }

        return $set;
    }

    /**
     * @param  array<array-key, mixed>  $a
     * @param  array<array-key, mixed>  $b
     */
    public static function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $inter = count(array_intersect_key($a, $b));
        $union = count($a) + count($b) - $inter;

        return $union === 0 ? 0.0 : $inter / $union;
    }

    /** Normalised comparison key for a line of text (lowercase, no punctuation, no stop words). */
    public static function key(string $text): string
    {
        $w = array_filter(self::words($text), fn ($x) => ! in_array($x, self::STOP, true));

        return implode(' ', $w);
    }

    public static function upperFirst(string $s): string
    {
        return mb_strtoupper(mb_substr($s, 0, 1)).mb_substr($s, 1);
    }

    public static function lowerFirst(string $s): string
    {
        return mb_strtolower(mb_substr($s, 0, 1)).mb_substr($s, 1);
    }

    /** Cut at a word boundary without adding an ellipsis; never mid-word. */
    public static function clip(string $s, int $max): string
    {
        $s = self::collapse($s);
        if (mb_strlen($s) <= $max) {
            return $s;
        }
        $cut = mb_substr($s, 0, $max);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space === false ? $cut : mb_substr($cut, 0, $space), ' ,;:-');
    }

    public static function isAllCaps(string $s): bool
    {
        $letters = preg_replace('/[^\p{L}]/u', '', $s) ?? '';

        return mb_strlen($letters) >= 3 && $letters === mb_strtoupper($letters);
    }
}
