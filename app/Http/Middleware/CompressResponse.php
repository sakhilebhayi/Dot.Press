<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Gzips JSON and HTML answers over 4 KB when the browser accepts it. Many cPanel accounts have mod_deflate switched
 * off, and a 1.3 MB document or a 330 KB library page compresses to 4 percent of its size. Responses that are already
 * encoded, streamed (DeckDocument streams its own gzip) or binary are left alone, so a web server that does compress is
 * never asked to do it twice.
 */
class CompressResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! config('press_perf.compress', true)
            || $response instanceof StreamedResponse
            || $response instanceof BinaryFileResponse
            || $response->headers->has('Content-Encoding')
            || ! in_array($response->getStatusCode(), [200, 201, 202, 422], true)
            || ! str_contains(strtolower((string) $request->header('Accept-Encoding', '')), 'gzip')
            || ! function_exists('gzencode')) {
            return $response;
        }

        $type = strtolower((string) $response->headers->get('Content-Type', ''));
        if (! str_contains($type, 'json') && ! str_contains($type, 'text/html')) {
            return $response;
        }

        $content = $response->getContent();
        if (! is_string($content) || strlen($content) < (int) config('press_perf.compress_min_bytes', 4096)) {
            return $response;
        }

        $encoded = gzencode($content, (int) config('press_perf.compress_level', 5));
        if ($encoded === false) {
            return $response;
        }

        $response->setContent($encoded);
        $response->headers->set('Content-Encoding', 'gzip');
        $response->headers->set('Content-Length', (string) strlen($encoded));
        $response->headers->set('Vary', trim($response->headers->get('Vary', '').', Accept-Encoding', ', '));

        return $response;
    }
}
