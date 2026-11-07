<?php

namespace App\Services\Press\Assets;

use App\Models\Asset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a stored file from whatever disk it lives on: Content-Length, ETag, conditional requests and
 * single-range byte requests (206), which browsers need to seek in video and audio.
 */
class AssetStreamer
{
    private const CHUNK = 1024 * 1024;

    private const MIME_BY_EXTENSION = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'avif' => 'image/avif', 'svg' => 'image/svg+xml',
    ];

    public function respond(Request $request, Asset $asset, string $variant = 'orig', bool $attachment = false): Response
    {
        [$source, $path, $mime, $key] = $this->resolve($asset, $variant);

        $disk = Storage::disk($source->disk);
        abort_unless($disk->exists($path), 404);

        $size = (int) $disk->size($path);
        $etag = '"'.($source->sha256 ?: md5($source->path.$source->size)).'-'.$key.'"';

        $headers = [
            'Content-Type' => $mime,
            'Accept-Ranges' => 'bytes',
            'ETag' => $etag,
            'Cache-Control' => 'private, max-age=86400, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => $this->disposition($asset, $mime, $attachment),
        ];

        if ($mime === 'image/svg+xml') {
            $headers['Content-Security-Policy'] = "sandbox; default-src 'none'; style-src 'unsafe-inline'";
        }

        if ($this->matchesEtag((string) $request->headers->get('If-None-Match'), $etag)) {
            return new Response('', 304, $headers);
        }

        $start = 0;
        $end = $size - 1;
        $status = 200;

        $range = $request->headers->get('Range');
        if ($range !== null && str_starts_with(strtolower(trim($range)), 'bytes=') && $this->rangeApplies($request, $etag)) {
            $parsed = $this->parseRange(trim($range), $size);

            if ($parsed === null) {
                return new Response('', 416, ['Content-Range' => "bytes */{$size}", 'Accept-Ranges' => 'bytes', 'X-Content-Type-Options' => 'nosniff']);
            }

            if ($parsed !== false) {
                [$start, $end] = $parsed;
                $status = 206;
                $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
            }
        }

        $length = $size === 0 ? 0 : $end - $start + 1;
        $headers['Content-Length'] = (string) $length;

        return new StreamedResponse(function () use ($disk, $path, $start, $length): void {
            if ($length === 0) {
                return;
            }
            $this->copy($disk->readStream($path), $start, $length);
        }, $status, $headers);
    }

    /**
     * Which row, path, mime type and cache key a variant maps to. A missing variant falls back to the next best
     * file instead of failing, so an old client asking for "thumb" of a video without a poster gets a clean 404 only
     * when there is truly nothing to show.
     *
     * @return array{Asset, string, string, string}
     */
    private function resolve(Asset $asset, string $variant): array
    {
        if ($variant === 'thumb') {
            if ($asset->thumb_path !== null) {
                return [$asset, $asset->thumb_path, $this->mimeOf($asset->thumb_path), 'thumb'];
            }
            if ($asset->kind === 'image') {
                return [$asset, $asset->path, $asset->mime_type, 'orig'];
            }
            $poster = AssetUrls::posterOf($asset);
            abort_if($poster === null || $poster->thumb_path === null, 404);

            return [$poster, $poster->thumb_path, $this->mimeOf($poster->thumb_path), 'thumb'];
        }

        if ($variant === 'web' && $asset->web_path !== null) {
            return [$asset, $asset->web_path, $this->mimeOf($asset->web_path), 'web'];
        }

        return [$asset, $asset->path, $asset->mime_type, 'orig'];
    }

    private function mimeOf(string $path): string
    {
        return self::MIME_BY_EXTENSION[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    }

    private function disposition(Asset $asset, string $mime, bool $attachment): string
    {
        $extension = array_search($mime, self::MIME_BY_EXTENSION, true);
        $extension = $extension === false ? strtolower(pathinfo($asset->path, PATHINFO_EXTENSION)) : ($extension === 'jpeg' ? 'jpg' : $extension);

        $name = pathinfo($asset->original_name, PATHINFO_FILENAME);
        $name = trim((string) preg_replace('/[^\p{L}\p{N} ._()\-]+/u', '_', $name)) ?: 'asset';
        $ascii = (string) preg_replace('/[^A-Za-z0-9 ._()\-]+/', '_', $name);

        return ($attachment ? 'attachment' : 'inline').'; filename="'.$ascii.'.'.$extension.'"; filename*=UTF-8\'\''.rawurlencode($name.'.'.$extension);
    }

    private function matchesEtag(string $header, string $etag): bool
    {
        if ($header === '') {
            return false;
        }

        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*' || $candidate === $etag || $candidate === 'W/'.$etag) {
                return true;
            }
        }

        return false;
    }

    /** If-Range: the range only applies when the validator still matches. */
    private function rangeApplies(Request $request, string $etag): bool
    {
        $ifRange = $request->headers->get('If-Range');

        return $ifRange === null || trim($ifRange) === $etag;
    }

    /**
     * @return array{int, int}|false|null [start, end], false when the header asks for several ranges (served whole), null when unsatisfiable
     */
    private function parseRange(string $header, int $size): array|false|null
    {
        $spec = trim(substr($header, 6));

        if (str_contains($spec, ',')) {
            return false;
        }

        if (preg_match('/^(\d*)-(\d*)$/', $spec, $match) !== 1 || ($match[1] === '' && $match[2] === '')) {
            return null;
        }

        if ($size === 0) {
            return null;
        }

        if ($match[1] === '') {
            $suffix = (int) $match[2];
            if ($suffix === 0) {
                return null;
            }

            return [max(0, $size - $suffix), $size - 1];
        }

        $start = (int) $match[1];
        $end = $match[2] === '' ? $size - 1 : min((int) $match[2], $size - 1);

        if ($start >= $size || $start > $end) {
            return null;
        }

        return [$start, $end];
    }

    /**
     * @param  resource|null  $stream
     */
    private function copy($stream, int $start, int $length): void
    {
        if (! is_resource($stream)) {
            return;
        }

        $seekable = (bool) (stream_get_meta_data($stream)['seekable'] ?? false);
        if ($start > 0) {
            if ($seekable) {
                fseek($stream, $start);
            } else {
                $skip = $start;
                while ($skip > 0 && ! feof($stream)) {
                    $skipped = fread($stream, min(self::CHUNK, $skip));
                    if ($skipped === false || $skipped === '') {
                        break;
                    }
                    $skip -= strlen($skipped);
                }
            }
        }

        $remaining = $length;
        while ($remaining > 0 && ! feof($stream)) {
            $chunk = fread($stream, min(self::CHUNK, $remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $remaining -= strlen($chunk);
            flush();
        }

        fclose($stream);
    }
}
