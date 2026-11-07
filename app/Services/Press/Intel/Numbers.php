<?php

namespace App\Services\Press\Intel;

/**
 * Finds numeric tokens in text and audits generated text against a fact base.
 * A number is "supported" only if the same value occurs in the source text or the brief.
 */
final class Numbers
{
    private const PATTERN = '/(?<![\p{L}\d.,])(?<cur>(?:R|\$|€|£|¥|USD|ZAR|EUR|GBP)\s?)?(?<sign>[-+−])?(?<num>\d{1,3}(?:[ \x{00A0},]\d{3})+(?:\.\d+)?|\d+(?:[.,]\d+)?)(?<suf>\s?(?:%|percent\b|per cent\b|million\b|billion\b|trillion\b|thousand\b)|(?:bn|mn|k|m|b)\b)?/ui';

    /**
     * @return list<array{raw: string, value: float, pct: bool, cur: bool, mag: bool, key: string, pos: int, alts: list<string>}>
     */
    public static function find(string $text): array
    {
        $text = Text::clean($text);
        if (! preg_match_all(self::PATTERN, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $out = [];
        foreach ($matches as $m) {
            $raw = $m[0][0];
            $pos = (int) $m[0][1];

            // A number glued to a trailing letter run ("12abc") is an identifier, not a figure.
            $after = substr($text, $pos + strlen($raw), 1);
            if (($m['suf'][0] ?? '') === '' && $after !== '' && preg_match('/\p{L}/u', $after) === 1) {
                continue;
            }

            $numText = $m['num'][0];
            $value = self::parseNumber($numText);
            $suffix = mb_strtolower(trim($m['suf'][0] ?? ''));
            $pct = in_array($suffix, ['%', 'percent', 'per cent'], true);
            $mult = match ($suffix) {
                'k', 'thousand' => 1e3,
                'm', 'mn', 'million' => 1e6,
                'b', 'bn', 'billion' => 1e9,
                'trillion' => 1e12,
                default => 1.0,
            };
            $mag = $mult !== 1.0;
            $value *= $mult;
            $cur = trim($m['cur'][0] ?? '') !== '';

            // Ordinals and small counts ("3 steps") are not figures to audit.
            // "450 470 480" may be one number written with spaces or three numbers: keep both readings.
            $alts = [];
            if (preg_match('/[ \x{00A0}]/u', $numText) && ! str_contains($numText, ',')) {
                foreach (preg_split('/[ \x{00A0}]+/u', $numText) ?: [] as $part) {
                    $alts[] = self::keyFor((float) $part, false);
                }
            }
            $out[] = [
                'alts' => $alts,
                'raw' => trim($raw),
                'value' => $value,
                'pct' => $pct,
                'cur' => $cur,
                'mag' => $mag,
                'key' => self::keyFor($value, $pct),
                'pos' => $pos,
            ];
        }

        return $out;
    }

    public static function parseNumber(string $s): float
    {
        $s = str_replace(["\u{00A0}", ' '], '', $s);
        if (preg_match('/^\d+,\d{1,2}$/', $s)) {
            // 12,5 is a decimal comma; 1,200 is a thousands separator.
            $s = str_replace(',', '.', $s);
        } else {
            $s = str_replace(',', '', $s);
        }

        return (float) $s;
    }

    public static function keyFor(float $value, bool $pct): string
    {
        $formatted = sprintf('%.6g', $value);
        if (str_contains($formatted, '.') && ! str_contains($formatted, 'e')) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }

        return ($pct ? 'p:' : 'n:').$formatted;
    }

    /**
     * Figures in $output that appear in none of $sources. Years and trivial counts are handled as the
     * header of this class says: small bare integers (0 to 20) are ignored, everything else must match.
     *
     * @return list<string> the raw tokens, deduplicated
     */
    public static function unsupported(string $output, string ...$sources): array
    {
        $known = [];
        foreach ($sources as $source) {
            foreach (self::find($source) as $n) {
                $known[$n['key']] = true;
                foreach ($n['alts'] as $alt) {
                    $known[$alt] = true;
                }
                // "12 percent" in the source also backs a bare "12" in the output.
                if ($n['pct']) {
                    $known[self::keyFor($n['value'], false)] = true;
                }
            }
        }

        $bad = [];
        foreach (self::find($output) as $n) {
            if (! $n['pct'] && ! $n['cur'] && ! $n['mag'] && $n['value'] >= 0 && $n['value'] <= 20 && floor($n['value']) === $n['value']) {
                continue;
            }
            if (isset($known[$n['key']])) {
                continue;
            }
            if ($n['alts'] !== [] && count(array_filter($n['alts'], fn ($k) => isset($known[$k]))) === count($n['alts'])) {
                continue;
            }
            $bad[$n['raw']] = true;
        }

        return array_map('strval', array_keys($bad));
    }

    /** Does a plain number text such as "12.5" appear (by value) in $source? */
    public static function occursIn(string $number, string $source): bool
    {
        return self::unsupported($number, $source) === [];
    }
}
