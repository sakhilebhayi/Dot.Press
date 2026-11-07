<?php

namespace App\Services\Press\Export\Support;

use GdImage;

/**
 * GD helpers: gradients and shape masks as pixels. DomPDF's SVG reader cannot paint gradients, so a gradient fill
 * is drawn here and embedded as a picture; image masks and adjustments use the same helpers.
 */
final class Raster
{
    /** Longest side in pixels of any generated bitmap. */
    public const MAX_SIDE = 2000;

    /** A transparent 1 x 1 PNG, for places that need a picture when no image library is available. */
    public static function blankPng(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    }

    /** Whether the GD extension (and with it gradients, masks and picture processing) is there. */
    public static function available(): bool
    {
        return self::$forced ?? (extension_loaded('gd') && function_exists('imagecreatetruecolor'));
    }

    private static ?bool $forced = null;

    /** Test hook: pretend the image library is (not) there; null goes back to the truth. */
    public static function forceAvailability(?bool $available): void
    {
        self::$forced = $available;
    }

    public static function canvas(int $w, int $h, bool $transparent = true): GdImage
    {
        $im = imagecreatetruecolor(max(1, $w), max(1, $h));
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 255, 255, 255, $transparent ? 127 : 0));

        return $im;
    }

    /**
     * Linear gradient, CSS angle (0 = to the top, 90 = to the right).
     *
     * @param  list<array{0: float, 1: string, 2: float}>  $stops  offset, hex, alpha
     */
    public static function gradient(int $w, int $h, array $stops, float $angle): GdImage
    {
        $im = self::canvas($w, $h);
        if ($stops === []) {
            return $im;
        }
        $th = deg2rad($angle);
        $dx = sin($th);
        $dy = -cos($th);
        $len = abs($w * $dx) + abs($h * $dy);
        $len = max($len, 1.0);
        $cx = $w / 2;
        $cy = $h / 2;
        $n = (int) max(2, min(300, ceil($len)));
        $big = ($w + $h) * 2;
        $nx = -$dy;
        $ny = $dx;
        for ($k = 0; $k < $n; $k++) {
            $t = $k / $n;
            [$r, $g, $b, $a] = self::sample($stops, $t + 0.5 / $n);
            $col = imagecolorallocatealpha($im, $r, $g, $b, (int) round(127 * (1 - $a)));
            $px = $cx + $dx * $len * ($t - 0.5);
            $py = $cy + $dy * $len * ($t - 0.5);
            // Each strip paints everything from its line forward; later strips overwrite, so there are no seams.
            imagefilledpolygon($im, [
                (int) round($px + $nx * $big), (int) round($py + $ny * $big),
                (int) round($px - $nx * $big), (int) round($py - $ny * $big),
                (int) round($px - $nx * $big + $dx * $big), (int) round($py - $ny * $big + $dy * $big),
                (int) round($px + $nx * $big + $dx * $big), (int) round($py + $ny * $big + $dy * $big),
            ], $col);
        }

        return $im;
    }

    /**
     * @param  list<array{0: float, 1: string, 2: float}>  $stops
     * @return array{0: int, 1: int, 2: int, 3: float}
     */
    public static function sample(array $stops, float $t): array
    {
        $t = max(0.0, min(1.0, $t));
        $prev = $stops[0];
        if ($t <= $prev[0]) {
            return [...self::rgb($prev[1]), $prev[2]];
        }
        foreach ($stops as $s) {
            if ($t <= $s[0]) {
                $span = max(1e-9, $s[0] - $prev[0]);
                $f = ($t - $prev[0]) / $span;
                $a = self::rgb($prev[1]);
                $b = self::rgb($s[1]);

                return [
                    (int) round($a[0] + ($b[0] - $a[0]) * $f),
                    (int) round($a[1] + ($b[1] - $a[1]) * $f),
                    (int) round($a[2] + ($b[2] - $a[2]) * $f),
                    $prev[2] + ($s[2] - $prev[2]) * $f,
                ];
            }
            $prev = $s;
        }

        return [...self::rgb($prev[1]), $prev[2]];
    }

    /** @return array{0: int, 1: int, 2: int} */
    public static function rgb(string $hex): array
    {
        return [(int) hexdec(substr($hex, 1, 2)), (int) hexdec(substr($hex, 3, 2)), (int) hexdec(substr($hex, 5, 2))];
    }

    /**
     * A white shape on a transparent canvas, drawn at 3x and scaled down so the edge is anti-aliased.
     *
     * @param  string  $kind  rect|round|ellipse|circle|triangle|star|hexagon|diamond|pentagon|chevron|arrow-r|arrow-l|cross|heart|callout
     */
    public static function shapeMask(string $kind, int $w, int $h, float $radiusPx = 0.0): GdImage
    {
        $ss = 3;
        $W = $w * $ss;
        $H = $h * $ss;
        $im = self::canvas($W, $H);
        $white = imagecolorallocatealpha($im, 255, 255, 255, 0);
        switch ($kind) {
            case 'ellipse':
            case 'circle':
                imagefilledellipse($im, (int) ($W / 2), (int) ($H / 2), $W - 1, $H - 1, $white);
                break;
            case 'round':
            case 'rect':
                $r = (int) min($radiusPx * $ss, $W / 2, $H / 2);
                if ($r <= 0) {
                    imagefilledrectangle($im, 0, 0, $W - 1, $H - 1, $white);
                } else {
                    imagefilledrectangle($im, $r, 0, $W - 1 - $r, $H - 1, $white);
                    imagefilledrectangle($im, 0, $r, $W - 1, $H - 1 - $r, $white);
                    imagefilledellipse($im, $r, $r, 2 * $r, 2 * $r, $white);
                    imagefilledellipse($im, $W - 1 - $r, $r, 2 * $r, 2 * $r, $white);
                    imagefilledellipse($im, $r, $H - 1 - $r, 2 * $r, 2 * $r, $white);
                    imagefilledellipse($im, $W - 1 - $r, $H - 1 - $r, 2 * $r, 2 * $r, $white);
                }
                break;
            case 'heart':
                self::poly($im, ShapeGeometry::heartPolygon($W, $H), $white);
                break;
            default:
                $pts = ShapeGeometry::polygon($kind, (float) $W, (float) $H);
                if ($pts === null) {
                    imagefilledrectangle($im, 0, 0, $W - 1, $H - 1, $white);
                } else {
                    self::poly($im, $pts, $white);
                }
        }
        $out = imagescale($im, $w, $h, IMG_BILINEAR_FIXED);
        imagealphablending($out, false);
        imagesavealpha($out, true);

        return $out;
    }

    /** @param list<array{0: float, 1: float}> $pts */
    private static function poly(GdImage $im, array $pts, int $col): void
    {
        $flat = [];
        foreach ($pts as [$x, $y]) {
            $flat[] = (int) round($x);
            $flat[] = (int) round($y);
        }
        if (count($flat) >= 6) {
            imagefilledpolygon($im, $flat, $col);
        }
    }

    /** Multiply the alpha of $img by the alpha of $mask (same size). */
    public static function applyMask(GdImage $img, GdImage $mask): void
    {
        $w = imagesx($img);
        $h = imagesy($img);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $m = (imagecolorat($mask, $x, $y) >> 24) & 0x7F;
                if ($m === 0) {
                    continue;
                }
                $c = imagecolorat($img, $x, $y);
                $a = ($c >> 24) & 0x7F;
                $na = 127 - (int) round((127 - $a) * (127 - $m) / 127);
                imagesetpixel($img, $x, $y, ($c & 0x00FFFFFF) | ($na << 24));
            }
        }
    }

    public static function png(GdImage $im): string
    {
        imagesavealpha($im, true);
        ob_start();
        imagepng($im, null, 6);

        return (string) ob_get_clean();
    }

    public static function jpeg(GdImage $im, int $quality = 85): string
    {
        // JPEG has no alpha: flatten onto white.
        $flat = imagecreatetruecolor(imagesx($im), imagesy($im));
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagealphablending($flat, true);
        imagecopy($flat, $im, 0, 0, 0, 0, imagesx($im), imagesy($im));
        ob_start();
        imagejpeg($flat, null, $quality);

        return (string) ob_get_clean();
    }

    /** Pixel dimensions for a box of $w x $h units: about 2 px per unit, never above MAX_SIDE. */
    public static function pixelSize(float $w, float $h, float $density = 2.0, int $cap = self::MAX_SIDE): array
    {
        $d = min($density, $cap / max($w, $h, 1.0));

        return [max(1, (int) round($w * $d)), max(1, (int) round($h * $d)), $d];
    }
}
