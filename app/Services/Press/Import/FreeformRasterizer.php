<?php

namespace App\Services\Press\Import;

use App\Services\Press\Export\Support\Raster;
use DOMElement;
use GdImage;

/**
 * The document model has no freeform shape, so a DrawingML custom geometry (a:custGeom) is drawn as a picture of its own
 * outline: curves are flattened, the path is filled and stroked with the shape's colours, and the picture replaces the
 * shape. Holes are filled (GD has no even-odd fill) and gradients use their first colour.
 */
final class FreeformRasterizer
{
    private const MAX_POINTS = 20000;

    /**
     * @return string|null PNG bytes, or null when the geometry has nothing to draw
     */
    public static function render(DOMElement $custGeom, float $w, float $h, ?string $fillHex, float $fillAlpha, ?string $strokeHex, float $strokeAlpha, float $strokeWidth): ?string
    {
        $paths = [];
        foreach (Xml::children(Xml::child($custGeom, 'pathLst'), 'path') as $p) {
            $paths[] = $p;
        }
        if ($paths === [] || $w <= 0 || $h <= 0) {
            return null;
        }
        [$pw, $ph, $d] = Raster::pixelSize($w, $h, 2.0, 1200);
        $ss = 2;
        $W = $pw * $ss;
        $H = $ph * $ss;
        $im = Raster::canvas($W, $H);
        $any = false;
        foreach ($paths as $p) {
            $cw = Xml::int($p, 'w', 0);
            $ch = Xml::int($p, 'h', 0);
            $cw = $cw > 0 ? $cw : (int) round($w * 9525);
            $ch = $ch > 0 ? $ch : (int) round($h * 9525);
            $sx = $W / $cw;
            $sy = $H / $ch;
            $fillIt = Xml::attr($p, 'fill', 'norm') !== 'none' && $fillHex !== null;
            $strokeIt = Xml::attr($p, 'stroke', '1') !== '0' && $strokeHex !== null && $strokeWidth > 0;
            foreach (self::subpaths($p, $sx, $sy) as [$pts, $closed]) {
                if (count($pts) < 2) {
                    continue;
                }
                $flat = [];
                foreach ($pts as [$x, $y]) {
                    $flat[] = (int) round($x);
                    $flat[] = (int) round($y);
                }
                if ($fillIt && count($pts) >= 3) {
                    imagefilledpolygon($im, $flat, self::color($im, $fillHex, $fillAlpha));
                    $any = true;
                }
                if ($strokeIt) {
                    imagesetthickness($im, max(1, (int) round($strokeWidth * $d * $ss)));
                    $col = self::color($im, $strokeHex, $strokeAlpha);
                    $n = count($pts);
                    for ($i = 0; $i < $n - 1; $i++) {
                        imageline($im, $flat[2 * $i], $flat[2 * $i + 1], $flat[2 * $i + 2], $flat[2 * $i + 3], $col);
                    }
                    if ($closed && $n > 2) {
                        imageline($im, $flat[2 * $n - 2], $flat[2 * $n - 1], $flat[0], $flat[1], $col);
                    }
                    imagesetthickness($im, 1);
                    $any = true;
                }
            }
        }
        if (! $any) {
            return null;
        }
        $out = imagescale($im, $pw, $ph, IMG_BILINEAR_FIXED);
        if (! $out instanceof GdImage) {
            return null;
        }
        imagealphablending($out, false);
        imagesavealpha($out, true);

        return Raster::png($out);
    }

    private static function color(GdImage $im, string $hex, float $alpha): int
    {
        [$r, $g, $b] = Raster::rgb($hex);

        return (int) imagecolorallocatealpha($im, $r, $g, $b, (int) round(127 * (1 - max(0.0, min(1.0, $alpha)))));
    }

    /**
     * Flatten one a:path into polylines.
     *
     * @return list<array{0: list<array{0: float, 1: float}>, 1: bool}>
     */
    private static function subpaths(DOMElement $path, float $sx, float $sy): array
    {
        $out = [];
        $cur = [];
        $pos = [0.0, 0.0];
        $start = [0.0, 0.0];
        $count = 0;
        $pt = fn (?DOMElement $e): array => [Xml::int($e, 'x') * $sx, Xml::int($e, 'y') * $sy];
        $flush = function (bool $closed) use (&$out, &$cur): void {
            if (count($cur) > 1) {
                $out[] = [$cur, $closed];
            }
            $cur = [];
        };
        foreach (Xml::children($path) as $c) {
            if (++$count > self::MAX_POINTS) {
                break;
            }
            switch ($c->localName) {
                case 'moveTo':
                    $flush(false);
                    $pos = $pt(Xml::child($c, 'pt'));
                    $start = $pos;
                    $cur = [$pos];
                    break;
                case 'lnTo':
                    $pos = $pt(Xml::child($c, 'pt'));
                    $cur[] = $pos;
                    break;
                case 'cubicBezTo':
                    $p = Xml::children($c, 'pt');
                    if (count($p) === 3) {
                        $a = $pt($p[0]);
                        $b = $pt($p[1]);
                        $e = $pt($p[2]);
                        for ($i = 1; $i <= 16; $i++) {
                            $t = $i / 16;
                            $m = 1 - $t;
                            $cur[] = [
                                $m ** 3 * $pos[0] + 3 * $m * $m * $t * $a[0] + 3 * $m * $t * $t * $b[0] + $t ** 3 * $e[0],
                                $m ** 3 * $pos[1] + 3 * $m * $m * $t * $a[1] + 3 * $m * $t * $t * $b[1] + $t ** 3 * $e[1],
                            ];
                        }
                        $pos = $e;
                    }
                    break;
                case 'quadBezTo':
                    $p = Xml::children($c, 'pt');
                    if (count($p) === 2) {
                        $a = $pt($p[0]);
                        $e = $pt($p[1]);
                        for ($i = 1; $i <= 12; $i++) {
                            $t = $i / 12;
                            $m = 1 - $t;
                            $cur[] = [$m * $m * $pos[0] + 2 * $m * $t * $a[0] + $t * $t * $e[0], $m * $m * $pos[1] + 2 * $m * $t * $a[1] + $t * $t * $e[1]];
                        }
                        $pos = $e;
                    }
                    break;
                case 'arcTo':
                    $wR = Xml::int($c, 'wR') * $sx;
                    $hR = Xml::int($c, 'hR') * $sy;
                    $st = deg2rad(Xml::int($c, 'stAng') / 60000);
                    $sw = deg2rad(Xml::int($c, 'swAng') / 60000);
                    if ($wR > 0 && $hR > 0) {
                        $cx = $pos[0] - $wR * cos($st);
                        $cy = $pos[1] - $hR * sin($st);
                        $steps = max(4, (int) ceil(abs($sw) / (M_PI / 18)));
                        for ($i = 1; $i <= $steps; $i++) {
                            $a = $st + $sw * $i / $steps;
                            $cur[] = [$cx + $wR * cos($a), $cy + $hR * sin($a)];
                        }
                        $pos = $cur[count($cur) - 1];
                    }
                    break;
                case 'close':
                    if ($cur !== []) {
                        $cur[] = $start;
                    }
                    $flush(true);
                    $pos = $start;
                    break;
            }
        }
        $flush(false);

        return $out;
    }
}
