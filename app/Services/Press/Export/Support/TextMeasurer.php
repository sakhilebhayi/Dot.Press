<?php

namespace App\Services\Press\Export\Support;

use App\Services\Press\Export\Pdf\FontRegistry;

/**
 * Estimates how tall a text box's content is, using DomPDF's own font metrics with the bundled fonts. Used for the
 * shrink-to-fit loop in the PDF and for the cached fontScale written into PPTX autofit. It is an estimate: the real
 * renderers (DomPDF, PowerPoint) wrap with their own rules.
 */
final class TextMeasurer
{
    /** @var array<string, float> */
    private static array $widths = [];

    /** Width of $text in units at $size units. */
    public static function width(string $text, string $family, bool $bold, bool $italic, float $size, float $ls = 0.0): float
    {
        if ($text === '') {
            return 0.0;
        }
        $key = $family.'|'.(int) $bold.(int) $italic.'|'.round($size, 2).'|'.round($ls, 3).'|'.$text;
        if (isset(self::$widths[$key])) {
            return self::$widths[$key];
        }
        $canon = FontRegistry::canonical($family);
        $w = null;
        if ($canon !== null) {
            FontRegistry::ensure([$canon]);
            $metrics = FontRegistry::metrics();
            $sub = ($bold ? 'bold' : 'normal').($italic ? '_italic' : '');
            $sub = match ($sub) {
                'bold_italic' => 'bold_italic',
                'normal_italic' => 'italic',
                default => $sub,
            };
            $font = $metrics->getFont(strtolower($canon), $sub);
            if ($font) {
                // DomPDF measures in points: 1 unit is 0.75 pt.
                $w = $metrics->getTextWidth($text, $font, $size * 0.75, 0.0, 0.0) / 0.75;
            }
        }
        $w ??= mb_strlen($text) * $size * ($bold ? 0.58 : 0.54);
        $w += mb_strlen($text) * $ls * $size;
        if (count(self::$widths) > 20000) {
            self::$widths = [];
        }

        return self::$widths[$key] = $w;
    }

    /**
     * @param  list<array<string, mixed>>  $paras  from TextDoc::paragraphs
     * @param  array<string, mixed>  $style  from TextStyle::of
     * @return array{height: float, lines: int}
     */
    public static function layout(array $paras, array $style, float $boxWidth, float $scale = 1.0, ?Theme $theme = null): array
    {
        $total = 0.0;
        $lines = 0;
        $count = count($paras);
        foreach ($paras as $pi => $p) {
            $baseSize = $style['size'] * $scale;
            $indent = 0.0;
            if ($p['list'] !== null) {
                $indent = TextStyle::listIndent($p['level'], $baseSize)['left'];
            }
            $avail = max(1.0, $boxWidth - $indent);
            [$n, $maxSize] = self::wrap($p['runs'], $style, $avail, $scale);
            $maxSize = max($maxSize, $baseSize);
            $n = max(1, $n);
            $lines += $n;
            $total += $n * $maxSize * $style['lh'];
            if ($pi < $count - 1) {
                $total += $style['ps'] * $baseSize;
            }
        }

        return ['height' => $total, 'lines' => $lines];
    }

    /**
     * Greedy word wrap. Returns the line count and the largest font size seen.
     *
     * @param  list<array<string, mixed>>  $runs
     * @return array{0: int, 1: float}
     */
    private static function wrap(array $runs, array $style, float $avail, float $scale): array
    {
        $lines = 1;
        $cur = 0.0;
        $max = 0.0;
        $any = false;
        foreach ($runs as $r) {
            $size = ($r['size'] ?? $style['size']) * $scale;
            $max = max($max, $size);
            if (! empty($r['br'])) {
                $lines++;
                $cur = 0.0;

                continue;
            }
            $family = $r['font'] ? (string) $r['font'] : $style['font'];
            $bold = $r['b'] || $style['bold'];
            $tokens = preg_split('/(\s+)/u', (string) $r['t'], -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($tokens as $tok) {
                $any = true;
                $w = self::width($tok, $family, $bold, $r['i'], $size, $style['ls']);
                $isSpace = trim($tok) === '';
                if (! $isSpace && $cur > 0 && $cur + $w > $avail) {
                    $lines++;
                    $cur = 0.0;
                }
                // A single word wider than the line breaks across lines.
                while (! $isSpace && $w > $avail && $avail > $size) {
                    $lines++;
                    $w -= $avail;
                }
                $cur += $isSpace && $cur === 0.0 ? 0.0 : $w;
            }
        }

        return [$any ? $lines : 1, $max];
    }

    /**
     * The largest scale in (0.25..1] at which the content fits, searched in 3 percent steps.
     *
     * @param  list<array<string, mixed>>  $paras
     */
    public static function fitScale(array $paras, array $style, float $boxWidth, float $boxHeight): float
    {
        if (self::layout($paras, $style, $boxWidth)['height'] <= $boxHeight) {
            return 1.0;
        }
        $lo = 0.25;
        $hi = 1.0;
        for ($i = 0; $i < 12; $i++) {
            $mid = ($lo + $hi) / 2;
            if (self::layout($paras, $style, $boxWidth, $mid)['height'] <= $boxHeight) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
        }

        // Round down to 1 percent so the result never overshoots the box.
        return max(0.25, floor($lo * 100) / 100);
    }
}
