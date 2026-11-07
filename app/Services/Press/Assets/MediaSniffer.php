<?php

namespace App\Services\Press\Assets;

/**
 * Decides what a file really is from its bytes. The file name and the client supplied mime type are never
 * looked at. Anything that is not on the allow-list is refused with a message that says what is accepted.
 */
final class MediaSniffer
{
    public const ACCEPTED = 'JPEG, PNG, GIF, WebP, AVIF and SVG images, MP4, WebM and MOV video, and MP3, WAV, OGG and M4A audio';

    /** @var array<string, array{kind: string, mime: string, ext: string}> */
    private const FORMATS = [
        'jpeg' => ['kind' => 'image', 'mime' => 'image/jpeg', 'ext' => 'jpg'],
        'png' => ['kind' => 'image', 'mime' => 'image/png', 'ext' => 'png'],
        'gif' => ['kind' => 'image', 'mime' => 'image/gif', 'ext' => 'gif'],
        'webp' => ['kind' => 'image', 'mime' => 'image/webp', 'ext' => 'webp'],
        'avif' => ['kind' => 'image', 'mime' => 'image/avif', 'ext' => 'avif'],
        'svg' => ['kind' => 'image', 'mime' => 'image/svg+xml', 'ext' => 'svg'],
        'mp4' => ['kind' => 'video', 'mime' => 'video/mp4', 'ext' => 'mp4'],
        'mov' => ['kind' => 'video', 'mime' => 'video/quicktime', 'ext' => 'mov'],
        'webm' => ['kind' => 'video', 'mime' => 'video/webm', 'ext' => 'webm'],
        'mp3' => ['kind' => 'audio', 'mime' => 'audio/mpeg', 'ext' => 'mp3'],
        'wav' => ['kind' => 'audio', 'mime' => 'audio/wav', 'ext' => 'wav'],
        'ogg' => ['kind' => 'audio', 'mime' => 'audio/ogg', 'ext' => 'ogg'],
        'm4a' => ['kind' => 'audio', 'mime' => 'audio/mp4', 'ext' => 'm4a'],
    ];

    /** Major brands of the ISO base media family that are plain MP4 video. */
    private const MP4_BRAND_PREFIXES = ['isom', 'iso2', 'iso3', 'iso4', 'iso5', 'iso6', 'mp41', 'mp42', 'mp71', 'avc1', 'dash', 'msnv', 'M4V', 'f4v', 'cmf', '3gp', '3g2', 'NDAS', 'XAVC'];

    /** Same container, but the contents are a still image or something we do not handle. */
    private const IMAGE_BRANDS = ['heic', 'heix', 'hevc', 'hevx', 'mif1', 'msf1', 'heim', 'heis'];

    /**
     * @return array{kind: string, format: string, mime: string, ext: string}
     */
    public static function detect(string $path): array
    {
        $size = @filesize($path);
        if ($size === false || $size === 0) {
            throw new AssetRejected('This file is empty.');
        }

        $head = (string) file_get_contents($path, false, null, 0, 16384);
        if ($size < 12 && ! self::looksLikeSvg($head)) {
            throw new AssetRejected('This file is too short to be a valid image, video or audio file.');
        }

        $format = self::formatOf($path, $head, $size);

        if ($format === null) {
            if (str_contains(substr($head, 0, 1024), '%PDF-')) {
                throw new AssetRejected('PDF files are not accepted. Export the pages as images and upload those instead.');
            }

            throw new AssetRejected('This file type is not supported. Accepted: '.self::ACCEPTED.'.');
        }

        if ($format === 'avif' && ! (function_exists('imageavif') && (imagetypes() & IMG_AVIF))) {
            throw new AssetRejected('AVIF images are not supported by this server. Upload a JPEG, PNG or WebP instead.');
        }

        if (in_array($format, ['jpeg', 'png', 'gif', 'webp'], true)) {
            $libmagic = (new \finfo(FILEINFO_MIME_TYPE))->buffer($head);
            if (is_string($libmagic) && $libmagic !== 'application/octet-stream' && ! str_starts_with($libmagic, 'image/')) {
                throw new AssetRejected('The content of this file does not match an image.');
            }
        }

        return ['format' => $format] + self::FORMATS[$format];
    }

    /**
     * The deeper check that a container is whole, run after the size limits so a huge file is refused cheaply.
     */
    public static function assertComplete(string $path, string $format): void
    {
        if (in_array($format, ['mp4', 'mov', 'm4a'], true)) {
            self::assertBoxesComplete($path, (int) filesize($path));
        }
    }

    private static function formatOf(string $path, string $head, int $size): ?string
    {
        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return 'jpeg';
        }
        if (str_starts_with($head, "\x89PNG\r\n\x1a\n")) {
            return 'png';
        }
        if (str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')) {
            return 'gif';
        }
        if (str_starts_with($head, 'RIFF') && strlen($head) >= 12) {
            return match (substr($head, 8, 4)) {
                'WEBP' => 'webp',
                'WAVE' => str_contains(substr($head, 12, 64), 'fmt ') ? 'wav' : null,
                default => null,
            };
        }
        if (strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp') {
            return self::isoFormat(substr($head, 8, 4));
        }
        if (strlen($head) >= 12 && in_array(substr($head, 4, 4), ['moov', 'mdat', 'wide', 'free', 'skip', 'pnot'], true)) {
            return 'mov';
        }
        if (str_starts_with($head, "\x1A\x45\xDF\xA3")) {
            return str_contains(substr($head, 0, 128), 'webm') ? 'webm' : null;
        }
        if (str_starts_with($head, 'OggS')) {
            $first = substr($head, 0, 4096);
            if (str_contains($first, 'theora')) {
                return null;
            }

            return (str_contains($first, 'vorbis') || str_contains($first, 'OpusHead') || str_contains($first, "\x7FFLAC") || str_contains($first, 'Speex')) ? 'ogg' : null;
        }
        if (self::isMp3($head)) {
            return 'mp3';
        }
        if (self::looksLikeSvg($head)) {
            return 'svg';
        }

        return null;
    }

    private static function isoFormat(string $brand): ?string
    {
        if ($brand === 'avif' || $brand === 'avis') {
            return 'avif';
        }
        if (in_array($brand, self::IMAGE_BRANDS, true)) {
            return null;
        }
        if ($brand === 'M4A ' || $brand === 'M4B ' || $brand === 'M4P ') {
            return 'm4a';
        }
        if ($brand === 'qt  ') {
            return 'mov';
        }
        foreach (self::MP4_BRAND_PREFIXES as $prefix) {
            if (str_starts_with($brand, $prefix)) {
                return 'mp4';
            }
        }

        return null;
    }

    private static function isMp3(string $head): bool
    {
        if (str_starts_with($head, 'ID3') && strlen($head) >= 10) {
            return ord($head[3]) <= 4;
        }
        if (strlen($head) < 4 || ord($head[0]) !== 0xFF || (ord($head[1]) & 0xE0) !== 0xE0) {
            return false;
        }

        $version = (ord($head[1]) >> 3) & 3;
        $layer = (ord($head[1]) >> 1) & 3;
        $bitrate = (ord($head[2]) >> 4) & 0xF;
        $rate = (ord($head[2]) >> 2) & 3;

        return $version !== 1 && $layer !== 0 && $bitrate !== 0xF && $rate !== 3;
    }

    private static function looksLikeSvg(string $head): bool
    {
        $text = ltrim(str_starts_with($head, "\xEF\xBB\xBF") ? substr($head, 3) : $head);

        if (! (str_starts_with($text, '<?xml') || str_starts_with($text, '<svg') || str_starts_with($text, '<!--') || stripos($text, '<!DOCTYPE svg') === 0)) {
            return false;
        }

        return stripos($head, '<svg') !== false;
    }

    /**
     * Walks the top level boxes of an MP4, MOV or M4A file. A box that runs past the end of the file means the
     * upload was cut short, and a file without a moov box has no index to play from.
     */
    private static function assertBoxesComplete(string $path, int $size): void
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new AssetRejected('The file could not be read.');
        }

        $offset = 0;
        $sawMoov = false;

        try {
            for ($i = 0; $i < 20000 && $offset < $size; $i++) {
                fseek($handle, $offset);
                $header = fread($handle, 16);
                if ($header === false || strlen($header) < 8) {
                    throw new AssetRejected('This media file is damaged or was cut short.');
                }

                $length = unpack('N', substr($header, 0, 4))[1];
                $type = substr($header, 4, 4);

                if ($length === 1) {
                    if (strlen($header) < 16) {
                        throw new AssetRejected('This media file is damaged or was cut short.');
                    }
                    $length = unpack('J', substr($header, 8, 8))[1];
                } elseif ($length === 0) {
                    $length = $size - $offset;
                }

                if ($length < 8 || $offset + $length > $size) {
                    throw new AssetRejected('This media file is damaged or was cut short.');
                }

                $sawMoov = $sawMoov || $type === 'moov';
                $offset += $length;
            }
        } finally {
            fclose($handle);
        }

        if (! $sawMoov) {
            throw new AssetRejected('This media file has no index (moov box) and cannot be played. Re-export it and try again.');
        }
    }
}
