<?php

namespace App\Services\Press\Intel;

/**
 * An estimate of how much room text needs, without a browser or font files. Each character has an
 * average advance width (as a fraction of the font size) chosen by character class and adjusted by
 * font family class and weight. It is an estimate: within a few percent for common sans fonts, less
 * exact for decorative ones. Callers treat results as "very likely" and say "estimated".
 */
final class TextMetrics
{
    public const DEFAULT_LH = 1.2;

    public const DEFAULT_PS = 0.25;

    public static function familyFactor(string $family): float
    {
        $f = mb_strtolower($family);
        if (preg_match('/mono|courier|consolas|menlo|code/', $f)) {
            return 1.15;
        }
        if (preg_match('/condensed|narrow|oswald|bebas|impact|league gothic/', $f)) {
            return 0.82;
        }
        if (preg_match('/georgia|times|garamond|merriweather|playfair|lora|serif|palatino|baskerville|cambria/', $f) && ! str_contains($f, 'sans')) {
            return 0.97;
        }
        if (preg_match('/verdana|tahoma|poppins|montserrat|raleway|lato black|dm sans/', $f)) {
            return 1.06;
        }

        return 1.0;
    }

    public static function charWidth(string $ch): float
    {
        if ($ch === ' ') {
            return 0.28;
        }
        if (mb_strlen($ch) === 1 && mb_ord($ch) > 0x2E80) {
            return 1.0;
        }
        if (str_contains("iIl|!.,;:'\u{2019}`jtfr", $ch)) {
            return in_array($ch, ['t', 'f', 'r'], true) ? 0.36 : 0.28;
        }
        if (str_contains('mwMW@%&', $ch)) {
            return in_array($ch, ['M', 'W'], true) ? 0.92 : (in_array($ch, ['m', 'w'], true) ? 0.82 : 0.9);
        }
        if (ctype_digit($ch)) {
            return 0.56;
        }
        if (preg_match('/^\p{Lu}$/u', $ch)) {
            return 0.66;
        }

        return 0.52;
    }

    public static function width(string $text, float $size, float $familyFactor = 1.0, int $weight = 400, float $ls = 0.0): float
    {
        $w = 0.0;
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($chars as $ch) {
            $w += self::charWidth($ch) + $ls;
        }
        $weightFactor = $weight >= 800 ? 1.1 : ($weight >= 600 ? 1.06 : 1.0);

        return $w * $size * $familyFactor * $weightFactor;
    }

    /**
     * Break one paragraph into lines for a box width.
     *
     * @return list<string>
     */
    public static function wrap(string $text, float $boxWidth, float $size, float $familyFactor, int $weight, float $ls): array
    {
        $lines = [];
        $current = '';
        $space = self::width(' ', $size, $familyFactor, $weight, $ls);
        $currentW = 0.0;
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            if ($word === '') {
                continue;
            }
            $ww = self::width($word, $size, $familyFactor, $weight, $ls);
            // A single word longer than the line is broken by characters (one pass, no re-slicing).
            if ($ww > $boxWidth && $boxWidth > $size) {
                if ($current !== '') {
                    $lines[] = $current;
                    $current = '';
                    $currentW = 0.0;
                }
                $take = '';
                $takeW = 0.0;
                foreach (preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
                    $cw = self::width($ch, $size, $familyFactor, $weight, $ls);
                    if ($takeW + $cw > $boxWidth && $take !== '') {
                        $lines[] = $take;
                        $take = '';
                        $takeW = 0.0;
                    }
                    $take .= $ch;
                    $takeW += $cw;
                }
                $current = $take;
                $currentW = $takeW;

                continue;
            }
            if ($current === '') {
                $current = $word;
                $currentW = $ww;
            } elseif ($currentW + $space + $ww <= $boxWidth) {
                $current .= ' '.$word;
                $currentW += $space + $ww;
            } else {
                $lines[] = $current;
                $current = $word;
                $currentW = $ww;
            }
        }
        if ($current !== '' || $lines === []) {
            $lines[] = $current;
        }

        return $lines;
    }

    /**
     * Estimated height and line statistics of a text element's blocks at a given size.
     *
     * @param  list<array{text: string, list: 'bullet'|'ordered'|null, depth: int}>  $blocks
     * @return array{height: float, lines: int, max_chars: int, avg_chars: float, paragraphs: int}
     */
    public static function measure(array $blocks, float $boxWidth, float $size, string $family, int $weight, float $lh, float $ls, float $ps): array
    {
        $ff = self::familyFactor($family);
        // Text beyond this many characters is measured on its first part and scaled up: hostile input must stay cheap.
        $chars = 0;
        foreach ($blocks as $b) {
            $chars += mb_strlen($b['text']);
        }
        $scale = 1.0;
        if ($chars > 12000) {
            $scale = $chars / 12000;
            $kept = [];
            $budget = 12000;
            foreach ($blocks as $b) {
                if ($budget <= 0) {
                    break;
                }
                $b['text'] = mb_substr($b['text'], 0, $budget);
                $budget -= mb_strlen($b['text']);
                $kept[] = $b;
            }
            $blocks = $kept;
        }
        $height = 0.0;
        $lines = 0;
        $maxChars = 0;
        $totalChars = 0;
        $paras = 0;
        $count = count($blocks);
        foreach ($blocks as $i => $b) {
            $indent = $b['list'] !== null ? $size * (0.9 + 0.9 * $b['depth']) : 0.0;
            $wrapped = self::wrap($b['text'], max($boxWidth - $indent, $size), $size, $ff, $weight, $ls);
            foreach ($wrapped as $l) {
                $maxChars = max($maxChars, mb_strlen($l));
                $totalChars += mb_strlen($l);
            }
            $lines += count($wrapped);
            $height += count($wrapped) * $size * $lh;
            if ($i < $count - 1) {
                $height += $ps * $size;
            }
            $paras++;
        }

        return ['height' => $height * $scale, 'lines' => (int) round($lines * $scale), 'max_chars' => $maxChars, 'avg_chars' => $lines > 0 ? $totalChars / $lines : 0.0, 'paragraphs' => $paras];
    }

    /**
     * Largest font size (not above $size) at which the blocks fit the box, by bisection. Null if
     * even $minSize does not fit.
     *
     * @param  list<array{text: string, list: 'bullet'|'ordered'|null, depth: int}>  $blocks
     */
    public static function fitSize(array $blocks, float $boxW, float $boxH, float $size, float $minSize, string $family, int $weight, float $lh, float $ls, float $ps): ?float
    {
        if (self::measure($blocks, $boxW, $size, $family, $weight, $lh, $ls, $ps)['height'] <= $boxH) {
            return $size;
        }
        if (self::measure($blocks, $boxW, $minSize, $family, $weight, $lh, $ls, $ps)['height'] > $boxH) {
            return null;
        }
        $lo = $minSize;
        $hi = $size;
        for ($i = 0; $i < 18; $i++) {
            $mid = ($lo + $hi) / 2;
            if (self::measure($blocks, $boxW, $mid, $family, $weight, $lh, $ls, $ps)['height'] <= $boxH) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
        }

        return floor($lo * 2) / 2;
    }
}
