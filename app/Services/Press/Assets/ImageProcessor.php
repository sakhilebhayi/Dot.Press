<?php

namespace App\Services\Press\Assets;

use ErrorException;
use GdImage;
use RuntimeException;
use Throwable;

/**
 * Raster image pipeline: validate from the header, refuse oversized bitmaps before decoding, decode strictly
 * (a warning from libjpeg or libpng means the file is damaged), apply the EXIF orientation, then re-encode so
 * metadata and any appended payload are gone. Produces the re-encoded original, a web variant and a thumbnail.
 */
class ImageProcessor
{
    private const TYPES = [
        'jpeg' => IMAGETYPE_JPEG,
        'png' => IMAGETYPE_PNG,
        'gif' => IMAGETYPE_GIF,
        'webp' => IMAGETYPE_WEBP,
        'avif' => IMAGETYPE_AVIF,
    ];

    private const MIME = ['jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif'];

    private const EXT = ['jpeg' => 'jpg', 'png' => 'png', 'gif' => 'gif', 'webp' => 'webp', 'avif' => 'avif'];

    /**
     * @return array{width: int, height: int, animated: bool, orig: array{bytes: string, ext: string, mime: string}, web: ?array{bytes: string, ext: string, mime: string}, thumb: ?array{bytes: string, ext: string, mime: string}}
     */
    public function process(string $path, string $format): array
    {
        if (! isset(self::TYPES[$format])) {
            throw new AssetRejected('This image format is not supported.');
        }

        $info = @getimagesize($path);
        if ($info === false || ($info[2] ?? null) !== self::TYPES[$format]) {
            throw new AssetRejected('This file is not a valid '.strtoupper($format).' image.');
        }

        [$sourceWidth, $sourceHeight] = $info;
        $this->assertDimensions((int) $sourceWidth, (int) $sourceHeight);

        $data = (string) file_get_contents($path);
        $animated = false;

        if ($format === 'gif') {
            $gif = GifInspector::inspect($data);
            $data = substr($data, 0, $gif['end']);
            $animated = $gif['frames'] > 1;
        }

        if ($format === 'jpeg' && strrpos($data, "\xFF\xD9") === false) {
            throw new AssetRejected('This JPEG is incomplete: the file ends before the image does.');
        }

        $previousLimit = $this->reserveMemory((int) $sourceWidth, (int) $sourceHeight);

        try {
            $image = $this->decode($data);
            unset($data);

            if ($format === 'jpeg') {
                $image = $this->orient($image, $this->orientation($path));
            } else {
                $image = $this->keepAlpha($image);
            }

            $width = imagesx($image);
            $height = imagesy($image);

            $original = $animated
                ? ['bytes' => substr((string) file_get_contents($path), 0, $gif['end']), 'ext' => 'gif', 'mime' => 'image/gif']
                : $this->encodeOriginal($image, $format);

            $web = null;
            if (! $animated) {
                $resized = max($width, $height) > AssetLimits::WEB_LONG_SIDE;
                if ($resized || $format !== 'webp') {
                    $web = $this->encodeVariant($this->scaled($image, AssetLimits::WEB_LONG_SIDE), $format === 'jpeg' ? 'jpeg' : 'png', 82);
                }
            }

            $thumb = $this->encodeVariant($this->scaled($image, AssetLimits::THUMB_LONG_SIDE), $format === 'jpeg' ? 'jpeg' : 'png', 76);
        } finally {
            if ($previousLimit !== null) {
                @ini_set('memory_limit', $previousLimit);
            }
        }

        return [
            'width' => $width,
            'height' => $height,
            'animated' => $animated,
            'orig' => $original,
            'web' => $web,
            'thumb' => $thumb,
        ];
    }

    /** Header-only check used for the poster endpoint as well. */
    public function assertDimensions(int $width, int $height): void
    {
        if ($width < 1 || $height < 1) {
            throw new AssetRejected('This image has no pixels.');
        }

        if ($width > AssetLimits::MAX_SIDE || $height > AssetLimits::MAX_SIDE) {
            throw new AssetRejected("This image is {$width} x {$height} pixels. The longest side can be at most ".AssetLimits::MAX_SIDE.' pixels.');
        }

        if ($width * $height > AssetLimits::MAX_PIXELS) {
            $megapixels = round($width * $height / 1_000_000, 1);

            throw new AssetRejected("This image is {$megapixels} megapixels. The limit is ".(AssetLimits::MAX_PIXELS / 1_000_000).' megapixels. Resize it and upload again.');
        }
    }

    /**
     * Decoding needs roughly the bitmap twice over (the source and one transformed copy). When the PHP memory
     * limit is lower than that, try to lift it for this request; when that is not possible, say so plainly.
     */
    private function reserveMemory(int $width, int $height): ?string
    {
        $limit = AssetLimits::iniBytes((string) ini_get('memory_limit'));
        if ($limit === 0) {
            return null;
        }

        $needed = memory_get_usage(true) + $width * $height * 8 + 24 * 1024 * 1024;
        if ($needed <= $limit) {
            return null;
        }

        $ceiling = 1024 * 1024 * 1024;
        $previous = (string) ini_get('memory_limit');

        if ($needed <= $ceiling && @ini_set('memory_limit', (string) $needed) !== false) {
            return $previous;
        }

        $affordable = max(1, (int) floor(($limit - memory_get_usage(true) - 24 * 1024 * 1024) / 8 / 1_000_000));

        throw new AssetRejected("This image is {$width} x {$height} pixels and the server does not have enough memory to process it. Resize it to about {$affordable} megapixels or less and upload again.");
    }

    private function decode(string $data): GdImage
    {
        $this->strict();

        try {
            $image = imagecreatefromstring($data);
        } catch (ErrorException) {
            $image = false;
        } finally {
            restore_error_handler();
        }

        if (! $image instanceof GdImage) {
            throw new AssetRejected('This image is damaged or incomplete and could not be read.');
        }

        return $image;
    }

    private function strict(): void
    {
        set_error_handler(function (int $level, string $message): never {
            throw new ErrorException($message, 0, $level);
        });
    }

    private function orientation(string $path): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }

        set_error_handler(fn (): bool => true);

        try {
            $exif = exif_read_data($path, 'IFD0');
        } catch (Throwable) {
            $exif = false;
        } finally {
            restore_error_handler();
        }

        $value = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        return $value >= 1 && $value <= 8 ? $value : 1;
    }

    private function keepAlpha(GdImage $image): GdImage
    {
        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $image;
    }

    private function orient(GdImage $image, int $orientation): GdImage
    {
        if ($orientation === 1) {
            return $image;
        }

        $background = 0;

        return match ($orientation) {
            2 => $this->flip($image, IMG_FLIP_HORIZONTAL),
            3 => imagerotate($image, 180, $background) ?: $image,
            4 => $this->flip($image, IMG_FLIP_VERTICAL),
            5 => $this->flip(imagerotate($image, 90, $background) ?: $image, IMG_FLIP_VERTICAL),
            6 => imagerotate($image, -90, $background) ?: $image,
            7 => $this->flip(imagerotate($image, -90, $background) ?: $image, IMG_FLIP_VERTICAL),
            8 => imagerotate($image, 90, $background) ?: $image,
            default => $image,
        };
    }

    private function flip(GdImage $image, int $mode): GdImage
    {
        imageflip($image, $mode);

        return $image;
    }

    private function scaled(GdImage $image, int $longSide): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = $longSide / max($width, $height);

        if ($scale >= 1) {
            return $image;
        }

        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $target;
    }

    /**
     * @return array{bytes: string, ext: string, mime: string}
     */
    private function encodeOriginal(GdImage $image, string $format): array
    {
        $bytes = match ($format) {
            'jpeg' => $this->capture(fn () => imagejpeg($image, null, 92)),
            'png' => $this->capture(fn () => imagepng($image, null, 6)),
            'gif' => $this->capture(fn () => imagegif($image)),
            'webp' => $this->capture(fn () => imagewebp($image, null, 95)),
            'avif' => $this->capture(fn () => imageavif($image, null, 85, 6)),
        };

        return ['bytes' => $bytes, 'ext' => self::EXT[$format], 'mime' => self::MIME[$format]];
    }

    /**
     * WebP where GD can write it (it keeps transparency and is small), else JPEG for photographs and PNG for the rest.
     *
     * @param  'jpeg'|'png'  $fallback
     * @return array{bytes: string, ext: string, mime: string}
     */
    private function encodeVariant(GdImage $image, string $fallback, int $quality): array
    {
        if (function_exists('imagewebp') && (imagetypes() & IMG_WEBP)) {
            return ['bytes' => $this->capture(fn () => imagewebp($image, null, $quality)), 'ext' => 'webp', 'mime' => 'image/webp'];
        }

        if ($fallback === 'jpeg') {
            return ['bytes' => $this->capture(fn () => imagejpeg($image, null, $quality + 8)), 'ext' => 'jpg', 'mime' => 'image/jpeg'];
        }

        return ['bytes' => $this->capture(fn () => imagepng($image, null, 6)), 'ext' => 'png', 'mime' => 'image/png'];
    }

    private function capture(callable $encode): string
    {
        ob_start();

        try {
            $ok = $encode();
        } finally {
            $bytes = (string) ob_get_clean();
        }

        if (! $ok || $bytes === '') {
            throw new RuntimeException('The image could not be re-encoded.');
        }

        return $bytes;
    }
}
