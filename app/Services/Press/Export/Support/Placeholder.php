<?php

namespace App\Services\Press\Export\Support;

use App\Services\Press\Export\Pdf\FontRegistry;

/** Labelled stand-in pictures for media a PPTX or PDF cannot carry (video and audio files, missing images). */
final class Placeholder
{
    /** @return string PNG bytes */
    public static function png(string $label, float $w, float $h, string $kind, Theme $theme): string
    {
        if (! Raster::available()) {
            return Raster::blankPng();
        }
        [$pw, $ph, $d] = Raster::pixelSize($w, $h, 1.0);
        $pw = min($pw, 1280);
        $ph = min($ph, 1280);
        $im = Raster::canvas($pw, $ph, false);
        imagealphablending($im, true);
        $dark = $kind === 'video';
        [$r, $g, $b] = $theme->rgb($dark ? '@ink' : '@surface') ?? [30, 30, 30];
        imagefilledrectangle($im, 0, 0, $pw - 1, $ph - 1, imagecolorallocate($im, $r, $g, $b));
        $fg = $dark ? [255, 255, 255] : ($theme->rgb('@ink') ?? [20, 20, 20]);
        $col = imagecolorallocate($im, ...$fg);
        $cx = (int) ($pw / 2);
        $cy = (int) ($ph * 0.45);
        $rad = (int) max(8, min($pw, $ph) * 0.14);
        if ($kind === 'video') {
            imagefilledellipse($im, $cx, $cy, $rad * 2, $rad * 2, imagecolorallocatealpha($im, 255, 255, 255, 90));
            imagefilledpolygon($im, [$cx - (int) ($rad * 0.35), $cy - (int) ($rad * 0.55), $cx + (int) ($rad * 0.6), $cy, $cx - (int) ($rad * 0.35), $cy + (int) ($rad * 0.55)], $col);
        } elseif ($kind === 'audio') {
            imagefilledrectangle($im, $cx - $rad, $cy - (int) ($rad * 0.4), $cx - (int) ($rad * 0.4), $cy + (int) ($rad * 0.4), $col);
            imagefilledpolygon($im, [$cx - (int) ($rad * 0.4), $cy - (int) ($rad * 0.4), $cx + (int) ($rad * 0.4), $cy - (int) ($rad * 1.0), $cx + (int) ($rad * 0.4), $cy + (int) ($rad * 1.0), $cx - (int) ($rad * 0.4), $cy + (int) ($rad * 0.4)], $col);
        } else {
            imagerectangle($im, $cx - $rad, $cy - $rad, $cx + $rad, $cy + $rad, $col);
            imageline($im, $cx - $rad, $cy - $rad, $cx + $rad, $cy + $rad, $col);
            imageline($im, $cx + $rad, $cy - $rad, $cx - $rad, $cy + $rad, $col);
        }
        $font = FontRegistry::ttfDir().'/Inter-Bold.ttf';
        $size = max(9.0, min($pw, $ph) * 0.055);
        if (is_file($font) && function_exists('imagettftext')) {
            $text = mb_substr($label, 0, 80);
            $box = imagettfbbox($size, 0, $font, $text);
            $tw = $box ? abs($box[2] - $box[0]) : 0;
            while ($tw > $pw * 0.92 && $size > 7) {
                $size -= 1;
                $box = imagettfbbox($size, 0, $font, $text);
                $tw = $box ? abs($box[2] - $box[0]) : 0;
            }
            imagettftext($im, $size, 0, (int) max(4, ($pw - $tw) / 2), (int) ($cy + $rad + $size * 2.2), $col, $font, $text);
        }
        imagealphablending($im, false);

        return Raster::png($im);
    }
}
