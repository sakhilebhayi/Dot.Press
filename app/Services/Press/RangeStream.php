<?php

namespace App\Services\Press;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a stored file with HTTP Range support (one range per request), which video and audio players need to seek.
 * Small and dependency free: it reads the file as a stream and never loads it into memory.
 */
class RangeStream
{
    private const CHUNK = 262144;

    /** @param  array<string, string>  $headers */
    public static function response(Request $request, Filesystem $disk, string $path, string $mime, string $name, array $headers = []): Response
    {
        $size = (int) $disk->size($path);
        $base = $headers + [
            'Content-Type' => $mime,
            'Accept-Ranges' => 'bytes',
            'Content-Disposition' => 'inline; filename="'.addcslashes(self::asciiName($name), '"\\').'"',
        ];

        $range = self::parseRange((string) $request->header('Range'), $size);

        if ($range === false) {
            return new Response('', 416, $base + ['Content-Range' => "bytes */{$size}"]);
        }

        [$start, $end] = $range ?? [0, max(0, $size - 1)];
        $length = $size === 0 ? 0 : $end - $start + 1;
        $status = $range === null ? 200 : 206;
        $responseHeaders = $base + ['Content-Length' => (string) $length];

        if ($status === 206) {
            $responseHeaders['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        if ($request->isMethod('HEAD') || $length === 0) {
            return new Response('', $status, $responseHeaders);
        }

        return new StreamedResponse(function () use ($disk, $path, $start, $length): void {
            $stream = $disk->readStream($path);

            if (! is_resource($stream)) {
                return;
            }

            if ($start > 0 && (stream_get_meta_data($stream)['seekable'] ?? false)) {
                fseek($stream, $start);
            } elseif ($start > 0) {
                $skipped = 0;
                while ($skipped < $start && ! feof($stream)) {
                    $skipped += strlen((string) fread($stream, min(self::CHUNK, $start - $skipped)));
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
        }, $status, $responseHeaders);
    }

    /**
     * null: no usable Range header (send everything). false: unsatisfiable (416). Otherwise [start, end] inclusive.
     * Multiple ranges are not supported and are answered with the whole file, which the standard allows.
     *
     * @return array{0: int, 1: int}|false|null
     */
    public static function parseRange(string $header, int $size): array|false|null
    {
        if ($header === '' || ! preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m)) {
            return null;
        }

        [$startRaw, $endRaw] = [$m[1], $m[2]];

        if ($startRaw === '' && $endRaw === '') {
            return null;
        }

        if ($size === 0) {
            return false;
        }

        if ($startRaw === '') {
            $suffix = (int) $endRaw;

            return $suffix === 0 ? false : [max(0, $size - $suffix), $size - 1];
        }

        $start = (int) $startRaw;
        $end = $endRaw === '' ? $size - 1 : min((int) $endRaw, $size - 1);

        return ($start >= $size || $start > $end) ? false : [$start, $end];
    }

    private static function asciiName(string $name): string
    {
        $clean = preg_replace('/[^\x20-\x7E]/', '_', $name) ?? 'file';

        return $clean === '' ? 'file' : $clean;
    }
}
