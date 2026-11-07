<?php

namespace App\Services\Press\Assets;

/**
 * Every size limit of the asset pipeline in one place.
 */
final class AssetLimits
{
    public const IMAGE_BYTES = 25 * 1024 * 1024;

    public const AUDIO_BYTES = 50 * 1024 * 1024;

    public const VIDEO_BYTES = 200 * 1024 * 1024;

    /** SVG is parsed into a DOM tree, so it gets a smaller ceiling than raster images. */
    public const SVG_BYTES = 5 * 1024 * 1024;

    /** A poster frame is one still captured in the browser. */
    public const POSTER_BYTES = 8 * 1024 * 1024;

    /** Decoded pixel budget, checked from the header before anything is decoded. */
    public const MAX_PIXELS = 60_000_000;

    /** Largest single side. Also keeps width and height inside their smallint columns. */
    public const MAX_SIDE = 16384;

    public const WEB_LONG_SIDE = 2560;

    public const THUMB_LONG_SIDE = 480;

    public const SVG_MAX_NODES = 20000;

    public static function bytesFor(string $kind): int
    {
        return match ($kind) {
            'video' => self::VIDEO_BYTES,
            'audio' => self::AUDIO_BYTES,
            'poster' => self::POSTER_BYTES,
            default => self::IMAGE_BYTES,
        };
    }

    /** PHP's own ceiling for one uploaded file: the smaller of upload_max_filesize and post_max_size (0 = unlimited). */
    public static function serverUploadBytes(): int
    {
        $upload = self::iniBytes((string) ini_get('upload_max_filesize'));
        $post = self::iniBytes((string) ini_get('post_max_size'));

        $limits = array_filter([$upload, $post], fn (int $bytes): bool => $bytes > 0);

        return $limits === [] ? 0 : min($limits);
    }

    public static function iniBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return 0;
        }

        $number = (float) $value;

        return (int) match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    public static function human(int $bytes): string
    {
        if ($bytes >= 1024 ** 3) {
            return round($bytes / 1024 ** 3, 1).' GB';
        }
        if ($bytes >= 1024 ** 2) {
            return round($bytes / 1024 ** 2, 1).' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024).' KB';
        }

        return $bytes.' bytes';
    }

    /** The sentence shown when PHP refused a file before the app saw it. */
    public static function serverLimitMessage(): string
    {
        $upload = ini_get('upload_max_filesize');
        $post = ini_get('post_max_size');

        return "The server refused this upload because it is larger than PHP allows (upload_max_filesize is {$upload}, post_max_size is {$post}). "
            .'Use a smaller file, or ask the administrator to raise both values.';
    }
}
