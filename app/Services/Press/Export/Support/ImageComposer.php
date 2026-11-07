<?php

namespace App\Services\Press\Export\Support;

use GdImage;

/**
 * Turns an image element (crop, fit, flips, mask, adjustments) into the pixels the page shows. The PDF export embeds
 * the result; the PPTX export uses plan() for native crop and placement and bakes only what PowerPoint cannot express.
 */
final class ImageComposer
{
    /** Largest decoded source in pixels. A bigger file is skipped (shared hosts run out of memory decoding it). */
    public const MAX_SOURCE_PIXELS = 24_000_000;

    /** @return array{0: int, 1: int}|null pixel width and height */
    public static function dimensions(string $bytes): ?array
    {
        $head = substr($bytes, 0, 2048);
        if (str_contains($head, '<svg')) {
            return self::svgDimensions($bytes);
        }
        $i = @getimagesizefromstring($bytes);

        return $i ? [(int) $i[0], (int) $i[1]] : null;
    }

    /** @return array{0: int, 1: int}|null */
    public static function svgDimensions(string $svg): ?array
    {
        if (preg_match('/<svg[^>]*\sviewBox\s*=\s*"([\d.\-eE+]+)[\s,]+([\d.\-eE+]+)[\s,]+([\d.\-eE+]+)[\s,]+([\d.\-eE+]+)"/i', $svg, $m)) {
            return [max(1, (int) round((float) $m[3])), max(1, (int) round((float) $m[4]))];
        }
        if (preg_match('/<svg[^>]*\swidth\s*=\s*"([\d.]+)(?:px)?"[^>]*\sheight\s*=\s*"([\d.]+)(?:px)?"/i', $svg, $m)) {
            return [max(1, (int) round((float) $m[1])), max(1, (int) round((float) $m[2]))];
        }

        return null;
    }

    /**
     * Where the picture sits in its box.
     *
     * @param  array<string, mixed>  $el
     * @return array{win: array{0: float, 1: float, 2: float, 3: float}, dest: array{0: float, 1: float, 2: float, 3: float}}
     *                                                                                                                        win is the visible source window as fractions (x, y, w, h), dest the drawn rectangle in box units (x, y, w, h)
     */
    public static function plan(int $sw, int $sh, array $el, float $w, float $h): array
    {
        $crop = is_array($el['crop'] ?? null) ? $el['crop'] : [];
        $cx = Els::num($crop['x'] ?? 0, 0, 0, 1);
        $cy = Els::num($crop['y'] ?? 0, 0, 0, 1);
        $cw = Els::num($crop['w'] ?? 1, 1, 0.001, 1);
        $ch = Els::num($crop['h'] ?? 1, 1, 0.001, 1);
        $cw = min($cw, 1 - $cx);
        $ch = min($ch, 1 - $cy);
        $cw = max($cw, 0.001);
        $ch = max($ch, 0.001);
        $fit = in_array($el['fit'] ?? null, ['cover', 'contain', 'fill'], true) ? $el['fit'] : 'cover';
        $winW = $cw * $sw;
        $winH = $ch * $sh;
        $boxAr = $h > 0 ? $w / $h : 1.0;
        $winAr = $winH > 0 ? $winW / $winH : 1.0;
        $dest = [0.0, 0.0, $w, $h];
        if ($fit === 'cover') {
            if ($winAr > $boxAr) {
                $nw = $winH * $boxAr;
                $cx += (($winW - $nw) / 2) / $sw;
                $cw = $nw / $sw;
            } else {
                $nh = $winW / max($boxAr, 1e-9);
                $cy += (($winH - $nh) / 2) / $sh;
                $ch = $nh / $sh;
            }
        } elseif ($fit === 'contain') {
            $scale = min($w / max($winW, 1e-9), $h / max($winH, 1e-9));
            $dw = $winW * $scale;
            $dh = $winH * $scale;
            $dest = [($w - $dw) / 2, ($h - $dh) / 2, $dw, $dh];
        }

        return ['win' => [$cx, $cy, $cw, $ch], 'dest' => $dest];
    }

    /** Whether the adjustments change any pixel. @param array<string, mixed>|null $adj */
    public static function hasAdj(?array $adj): bool
    {
        foreach (['br', 'ct', 'sat', 'blur', 'gray', 'sepia', 'hue'] as $k) {
            if (isset($adj[$k]) && is_numeric($adj[$k]) && (float) $adj[$k] != 0.0) {
                return true;
            }
        }

        return false;
    }

    /** Adjustments PowerPoint can hold natively (brightness, contrast, full greyscale). @param array<string, mixed>|null $adj */
    public static function adjIsNativeOnly(?array $adj): bool
    {
        foreach (['sat', 'blur', 'sepia', 'hue'] as $k) {
            if (isset($adj[$k]) && is_numeric($adj[$k]) && (float) $adj[$k] != 0.0) {
                return false;
            }
        }
        $gray = (float) ($adj['gray'] ?? 0);

        return $gray == 0.0 || $gray >= 100.0;
    }

    public static function decode(string $bytes): ?GdImage
    {
        if (! Raster::available()) {
            return null;
        }
        $dim = @getimagesizefromstring($bytes);
        if (! $dim || $dim[0] * $dim[1] > self::MAX_SOURCE_PIXELS || ! Memory::fits((int) ($dim[0] * $dim[1] * 5))) {
            return null;
        }
        $im = @imagecreatefromstring($bytes);

        return $im instanceof GdImage ? $im : null;
    }

    /**
     * The finished picture for a box of $w x $h units. Returns null when the image cannot be decoded.
     *
     * @param  array<string, mixed>  $el
     * @return array{bytes: string, mime: string, ext: string, alpha: bool}|null
     */
    public static function compose(string $bytes, array $el, float $w, float $h, Theme $theme): ?array
    {
        $src = self::decode($bytes);
        if ($src === null) {
            return null;
        }
        imagealphablending($src, false);
        $sw = imagesx($src);
        $sh = imagesy($src);
        $plan = self::plan($sw, $sh, $el, $w, $h);
        [$wx, $wy, $ww, $wh] = $plan['win'];
        [$dx, $dy, $dw, $dh] = $plan['dest'];

        [$pw, $ph, $density] = Raster::pixelSize($w, $h);
        // No point sampling above the source resolution.
        $maxDensity = min($sw * $ww / max($dw, 1e-6), $sh * $wh / max($dh, 1e-6));
        if ($maxDensity > 0 && $density > $maxDensity) {
            $f = $maxDensity / $density;
            $pw = max(1, (int) round($pw * $f));
            $ph = max(1, (int) round($ph * $f));
            $density *= $f;
        }
        $out = Raster::canvas($pw, $ph);
        $fillHex = $theme->hex($el['fill'] ?? null);
        $hasBoxFill = $fillHex !== null && (($el['fit'] ?? 'cover') === 'contain');
        if ($hasBoxFill) {
            [$r, $g, $b] = Raster::rgb($fillHex);
            imagefill($out, 0, 0, imagecolorallocatealpha($out, $r, $g, $b, 0));
        }
        imagealphablending($out, true);
        imagecopyresampled(
            $out, $src,
            (int) round($dx * $density), (int) round($dy * $density),
            (int) round($wx * $sw), (int) round($wy * $sh),
            max(1, (int) round($dw * $density)), max(1, (int) round($dh * $density)),
            max(1, (int) round($ww * $sw)), max(1, (int) round($wh * $sh)),
        );
        unset($src);
        imagealphablending($out, false);
        imagesavealpha($out, true);

        if (! empty($el['flipH'])) {
            imageflip($out, IMG_FLIP_HORIZONTAL);
        }
        if (! empty($el['flipV'])) {
            imageflip($out, IMG_FLIP_VERTICAL);
        }
        $adj = is_array($el['adj'] ?? null) ? $el['adj'] : null;
        if (self::hasAdj($adj)) {
            self::adjust($out, $adj, $density);
        }
        $mask = in_array($el['mask'] ?? null, ShapeGeometry::MASKS, true) ? $el['mask'] : 'none';
        $alpha = $hasBoxFill ? false : ($dx > 0.5 || $dy > 0.5 || self::hasTransparency($bytes));
        if ($mask !== 'none') {
            $radius = $mask === 'round' ? min($w, $h) * 0.12 : 0;
            Raster::applyMask($out, Raster::shapeMask($mask === 'circle' ? 'ellipse' : $mask, $pw, $ph, $radius * $density));
            $alpha = true;
        }
        if ($alpha) {
            return ['bytes' => Raster::png($out), 'mime' => 'image/png', 'ext' => 'png', 'alpha' => true];
        }

        return ['bytes' => Raster::jpeg($out, 86), 'mime' => 'image/jpeg', 'ext' => 'jpg', 'alpha' => false];
    }

    private static function hasTransparency(string $bytes): bool
    {
        return str_starts_with($bytes, "\x89PNG") || str_starts_with($bytes, 'GIF8') || (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP');
    }

    /**
     * Brightness, contrast, saturation, grayscale, sepia and hue in one pass, then blur. Same meaning as the CSS
     * filter functions: br, ct, sat are percentages around 0, gray and sepia 0..100, hue in degrees, blur in units.
     *
     * @param  array<string, mixed>  $adj
     */
    public static function adjust(GdImage $im, array $adj, float $density = 1.0): void
    {
        $br = 1 + Els::num($adj['br'] ?? 0, 0, -100, 100) / 100;
        $ct = 1 + Els::num($adj['ct'] ?? 0, 0, -100, 100) / 100;
        $sat = max(0, 1 + Els::num($adj['sat'] ?? 0, 0, -100, 100) / 100);
        $gray = Els::num($adj['gray'] ?? 0, 0, 0, 100) / 100;
        $sepia = Els::num($adj['sepia'] ?? 0, 0, 0, 100) / 100;
        $hue = Els::num($adj['hue'] ?? 0, 0, -360, 360);
        $blur = Els::num($adj['blur'] ?? 0, 0, 0, 100);
        $w = imagesx($im);
        $h = imagesy($im);
        $pixelwise = $br != 1 || $ct != 1 || $sat != 1 || $gray > 0 || $sepia > 0 || $hue != 0;
        if ($pixelwise) {
            $cosH = cos(deg2rad($hue));
            $sinH = sin(deg2rad($hue));
            // Hue rotation matrix (CSS filter spec).
            $hm = [
                0.213 + $cosH * 0.787 - $sinH * 0.213, 0.715 - $cosH * 0.715 - $sinH * 0.715, 0.072 - $cosH * 0.072 + $sinH * 0.928,
                0.213 - $cosH * 0.213 + $sinH * 0.143, 0.715 + $cosH * 0.285 + $sinH * 0.140, 0.072 - $cosH * 0.072 - $sinH * 0.283,
                0.213 - $cosH * 0.213 - $sinH * 0.787, 0.715 - $cosH * 0.715 + $sinH * 0.715, 0.072 + $cosH * 0.928 + $sinH * 0.072,
            ];
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $c = imagecolorat($im, $x, $y);
                    $a = ($c >> 24) & 0x7F;
                    if ($a === 127) {
                        continue;
                    }
                    $r = (($c >> 16) & 255) / 255;
                    $g = (($c >> 8) & 255) / 255;
                    $b = ($c & 255) / 255;
                    if ($hue != 0) {
                        [$r, $g, $b] = [
                            $hm[0] * $r + $hm[1] * $g + $hm[2] * $b,
                            $hm[3] * $r + $hm[4] * $g + $hm[5] * $b,
                            $hm[6] * $r + $hm[7] * $g + $hm[8] * $b,
                        ];
                    }
                    if ($sat != 1) {
                        $l = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
                        $r = $l + ($r - $l) * $sat;
                        $g = $l + ($g - $l) * $sat;
                        $b = $l + ($b - $l) * $sat;
                    }
                    if ($gray > 0) {
                        $l = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
                        $r += ($l - $r) * $gray;
                        $g += ($l - $g) * $gray;
                        $b += ($l - $b) * $gray;
                    }
                    if ($sepia > 0) {
                        $sr = 0.393 * $r + 0.769 * $g + 0.189 * $b;
                        $sg = 0.349 * $r + 0.686 * $g + 0.168 * $b;
                        $sb = 0.272 * $r + 0.534 * $g + 0.131 * $b;
                        $r += ($sr - $r) * $sepia;
                        $g += ($sg - $g) * $sepia;
                        $b += ($sb - $b) * $sepia;
                    }
                    if ($br != 1) {
                        $r *= $br;
                        $g *= $br;
                        $b *= $br;
                    }
                    if ($ct != 1) {
                        $r = ($r - 0.5) * $ct + 0.5;
                        $g = ($g - 0.5) * $ct + 0.5;
                        $b = ($b - 0.5) * $ct + 0.5;
                    }
                    $ri = (int) round(max(0, min(1, $r)) * 255);
                    $gi = (int) round(max(0, min(1, $g)) * 255);
                    $bi = (int) round(max(0, min(1, $b)) * 255);
                    imagesetpixel($im, $x, $y, ($a << 24) | ($ri << 16) | ($gi << 8) | $bi);
                }
            }
        }
        if ($blur > 0) {
            $passes = (int) min(12, max(1, round($blur * $density / 2)));
            for ($i = 0; $i < $passes; $i++) {
                imagefilter($im, IMG_FILTER_GAUSSIAN_BLUR);
            }
        }
    }

    /**
     * The image with every adjustment baked in, longest side capped at $maxSide. Used by the PPTX export for the
     * adjustments PowerPoint has no native equivalent for.
     *
     * @param  array<string, mixed>  $adj
     * @return array{bytes: string, mime: string, ext: string}|null
     */
    public static function bakeAdjustments(string $bytes, array $adj, int $maxSide = 2400): ?array
    {
        $im = self::decode($bytes);
        if ($im === null) {
            return null;
        }
        $w = imagesx($im);
        $h = imagesy($im);
        $s = min(1.0, $maxSide / max($w, $h));
        if ($s < 1.0) {
            $scaled = imagescale($im, max(1, (int) round($w * $s)), max(1, (int) round($h * $s)), IMG_BILINEAR_FIXED);
            if ($scaled) {
                $im = $scaled;
            }
        }
        imagealphablending($im, false);
        imagesavealpha($im, true);
        self::adjust($im, $adj, $s);
        $alpha = self::hasTransparency($bytes);

        return $alpha
            ? ['bytes' => Raster::png($im), 'mime' => 'image/png', 'ext' => 'png']
            : ['bytes' => Raster::jpeg($im, 88), 'mime' => 'image/jpeg', 'ext' => 'jpg'];
    }
}
