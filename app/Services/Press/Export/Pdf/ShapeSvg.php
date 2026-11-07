<?php

namespace App\Services\Press\Export\Pdf;

use App\Services\Press\Export\Support\Els;
use App\Services\Press\Export\Support\ShapeGeometry;
use App\Services\Press\Export\Support\Theme;

/**
 * Generates the SVG for a shape element. DomPDF paints solid fills, strokes, dashes and opacity from SVG but not
 * gradients, so a gradient fill is requested as `fill: none` here and drawn separately as a bitmap by the caller.
 */
final class ShapeSvg
{
    /**
     * @param  array<string, mixed>  $el
     * @param  array{fill?: bool, stroke?: bool, color?: string, extraStroke?: float}  $opt  fill=false skips the fill, color overrides all colours (shadows)
     */
    public static function render(array $el, float $w, float $h, Theme $theme, array $opt = []): string
    {
        $w = max(1.0, $w);
        $h = max(1.0, $h);
        $kind = in_array($el['kind'] ?? null, ShapeGeometry::KINDS, true) ? $el['kind'] : 'rect';
        $sw = Els::num($el['sw'] ?? ($el['stroke'] ?? null ? $theme->stroke : 0), 0, 0, 200);
        $strokeHex = $theme->hex($el['stroke'] ?? null);
        if ($strokeHex === null) {
            $sw = ($kind === 'line' || $kind === 'arrow') ? max($sw, $theme->stroke) : 0;
            if ($sw > 0 && $strokeHex === null) {
                $strokeHex = $theme->hex($el['fill'] ?? null) ?? $theme->hex('@ink');
            }
        }
        $override = $opt['color'] ?? null;
        $strokeAlpha = $theme->alpha($el['stroke'] ?? null);
        $sw += (float) ($opt['extraStroke'] ?? 0);
        $drawFill = ($opt['fill'] ?? true) && $kind !== 'line' && $kind !== 'arrow';
        $fillHex = null;
        $fillAlpha = 1.0;
        if ($drawFill) {
            $f = $el['fill'] ?? null;
            if (Theme::isGradient($f)) {
                $drawFill = false; // painted as a bitmap by the caller
            } else {
                $fillHex = $theme->hex($f);
                $fillAlpha = $theme->alpha($f);
                if ($fillHex === null) {
                    $drawFill = false;
                }
            }
        }
        if ($override !== null) {
            $fillHex = $override;
            $strokeHex = $override;
        }
        $inset = $sw / 2;
        $iw = max(1.0, $w - $sw);
        $ih = max(1.0, $h - $sw);
        $attrs = '';
        $fillAttr = $drawFill && $fillHex !== null ? ' fill="'.$fillHex.'"'.($fillAlpha < 1 ? ' fill-opacity="'.$fillAlpha.'"' : '') : ' fill="none"';
        $strokeAttr = '';
        if ($sw > 0 && $strokeHex !== null && ($opt['stroke'] ?? true)) {
            $strokeAttr = ' stroke="'.$strokeHex.'" stroke-width="'.self::f($sw).'"'.($strokeAlpha < 1 ? ' stroke-opacity="'.$strokeAlpha.'"' : '').' stroke-linejoin="'.($kind === 'line' || $kind === 'arrow' ? 'round' : 'miter').'"';
            $dash = $el['dash'] ?? 'solid';
            if ($dash === 'dash') {
                $strokeAttr .= ' stroke-dasharray="'.self::f(max(2, $sw * 4)).','.self::f(max(2, $sw * 3)).'"';
            } elseif ($dash === 'dot') {
                $strokeAttr .= ' stroke-dasharray="'.self::f(max(1, $sw)).','.self::f(max(2, $sw * 2)).'" stroke-linecap="round"';
            }
        }
        $body = '';
        $tx = 'translate('.self::f($inset).','.self::f($inset).')';
        switch ($kind) {
            case 'rect':
            case 'round':
                $r = Els::num($el['radius'] ?? ($kind === 'round' ? $theme->radius : 0), 0, 0, 5000);
                $r = min($r, $iw / 2, $ih / 2);
                $body = '<rect x="'.self::f($inset).'" y="'.self::f($inset).'" width="'.self::f($iw).'" height="'.self::f($ih).'"'.($r > 0 ? ' rx="'.self::f($r).'" ry="'.self::f($r).'"' : '').$fillAttr.$strokeAttr.'/>';
                break;
            case 'ellipse':
                $body = '<ellipse cx="'.self::f($w / 2).'" cy="'.self::f($h / 2).'" rx="'.self::f($iw / 2).'" ry="'.self::f($ih / 2).'"'.$fillAttr.$strokeAttr.'/>';
                break;
            case 'heart':
                $body = '<path transform="'.$tx.'" d="'.ShapeGeometry::heartPath($iw, $ih).'"'.$fillAttr.$strokeAttr.'/>';
                break;
            case 'line':
            case 'arrow':
                $ends = is_array($el['ends'] ?? null) ? $el['ends'] : [];
                $s = $ends['s'] ?? 'none';
                $e = $ends['e'] ?? ($kind === 'arrow' ? 'arrow' : 'none');
                $x1 = $inset;
                $y1 = $inset;
                $x2 = $w - $inset;
                $y2 = $h - $inset;
                if ($h < 2 && $w >= 2) { // a horizontal rule: centre it
                    $y1 = $y2 = $h / 2;
                } elseif ($w < 2 && $h >= 2) {
                    $x1 = $x2 = $w / 2;
                }
                $color = $strokeHex ?? '#000000';
                $lineStroke = $strokeAttr !== '' ? $strokeAttr : ' stroke="'.$color.'" stroke-width="'.self::f(max(1, $sw)).'"';
                $body = '<line x1="'.self::f($x1).'" y1="'.self::f($y1).'" x2="'.self::f($x2).'" y2="'.self::f($y2).'" fill="none"'.$lineStroke.($strokeAttr !== '' && ! str_contains($strokeAttr, 'linecap') ? '' : '').'/>';
                $body .= self::lineEnd($x2, $y2, $x1, $y1, $e, max(1.0, $sw), $color);
                $body .= self::lineEnd($x1, $y1, $x2, $y2, $s, max(1.0, $sw), $color);
                break;
            default:
                $pts = ShapeGeometry::polygon($kind, $iw, $ih) ?? [[0, 0], [$iw, 0], [$iw, $ih], [0, $ih]];
                $p = [];
                foreach ($pts as [$x, $y]) {
                    $p[] = self::f($x).','.self::f($y);
                }
                $body = '<polygon transform="'.$tx.'" points="'.implode(' ', $p).'"'.$fillAttr.$strokeAttr.'/>';
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="'.self::f($w).'" height="'.self::f($h).'" viewBox="0 0 '.self::f($w).' '.self::f($h).'">'.$body.'</svg>';
    }

    /** An arrow head or dot at (x, y) for a line coming from (fx, fy). */
    private static function lineEnd(float $x, float $y, float $fx, float $fy, string $kind, float $sw, string $color): string
    {
        if ($kind === 'dot') {
            return '<circle cx="'.self::f($x).'" cy="'.self::f($y).'" r="'.self::f(max(2, $sw * 1.8)).'" fill="'.$color.'"/>';
        }
        if ($kind !== 'arrow') {
            return '';
        }
        $dx = $x - $fx;
        $dy = $y - $fy;
        $len = max(hypot($dx, $dy), 1e-6);
        $ux = $dx / $len;
        $uy = $dy / $len;
        $size = max(8.0, $sw * 4);
        $bx = $x - $ux * $size;
        $by = $y - $uy * $size;
        $px = -$uy * $size * 0.45;
        $py = $ux * $size * 0.45;

        return '<polygon points="'.self::f($x).','.self::f($y).' '.self::f($bx + $px).','.self::f($by + $py).' '.self::f($bx - $px).','.self::f($by - $py).'" fill="'.$color.'"/>';
    }

    private static function f(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') ?: '0';
    }
}
