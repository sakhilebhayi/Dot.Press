<?php

namespace App\Services\Press;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sends JSON that was produced in pieces (DeckDocument::chunks) without ever holding it in memory, gzipped on the fly
 * when the browser accepts it. The memory used is one buffer of `BUFFER` bytes plus the zlib window, whatever the size
 * of the document.
 */
final class JsonStream
{
    private const BUFFER = 65536;

    /**
     * @param  iterable<int, string>  $chunks
     * @param  array<string, string>  $headers
     */
    public static function response(Request $request, iterable $chunks, array $headers = [], int $status = 200): StreamedResponse
    {
        $gzip = self::acceptsGzip($request);
        $headers += ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store, private', 'X-Accel-Buffering' => 'no', 'Vary' => 'Accept-Encoding'];
        if ($gzip) {
            $headers['Content-Encoding'] = 'gzip';
        }

        return new StreamedResponse(function () use ($chunks, $gzip): void {
            $zlib = $gzip ? deflate_init(ZLIB_ENCODING_GZIP, ['level' => (int) config('press_perf.compress_level', 5)]) : null;
            $buffer = '';
            foreach ($chunks as $piece) {
                $buffer .= $piece;
                if (strlen($buffer) >= self::BUFFER) {
                    echo $zlib ? deflate_add($zlib, $buffer, ZLIB_NO_FLUSH) : $buffer;
                    $buffer = '';
                }
            }
            echo $zlib ? deflate_add($zlib, $buffer, ZLIB_FINISH) : $buffer;
        }, $status, $headers);
    }

    public static function acceptsGzip(Request $request): bool
    {
        return (bool) config('press_perf.compress', true)
            && function_exists('deflate_init')
            && str_contains(strtolower((string) $request->header('Accept-Encoding', '')), 'gzip');
    }
}
