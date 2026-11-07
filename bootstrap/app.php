<?php

use App\Http\Middleware\CompressResponse;
use App\Http\Middleware\EnsurePlatformOperator;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\MemoizeDeckAccess;
use App\Http\Middleware\OwnHostIsStateful;
use App\Http\Middleware\SecurityHeaders;
use App\Services\Guardian\ErrorCounter;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Every response, including /up, the API and error pages: baseline security headers.
        $middleware->append(SecurityHeaders::class);

        // Gzip big JSON and HTML answers (R-40 performance): the outermost layer, so it sees the finished response.
        $middleware->prepend(CompressResponse::class);

        // Deck grants are worked out once per request instead of once per question (R-40 performance).
        $middleware->append(MemoizeDeckAccess::class);

        // The editor and the library call /api/* from the signed-in browser with the session cookie and the XSRF header.
        // Requests whose Origin or Referer is one of SANCTUM_STATEFUL_DOMAINS (the app's own host by default) get the
        // session and CSRF middleware; everything else still needs a bearer token.
        $middleware->statefulApi();
        // ...and never depends on the host's .env listing this host (see the middleware).
        $middleware->api(prepend: [OwnHostIsStateful::class]);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // An empty string is a value in the document: alt="" marks a picture as decorative (spec section 6, and the review
        // rule that asks for it). Laravel would turn it into null, which el.set reads as "delete the key".
        $middleware->convertEmptyStringsToNull(except: [
            fn ($request) => $request->is('api/decks/*/ops'),
        ]);

        // The same goes for spaces: a rich text run that ends in a space ("This is ", then a bold word) is a string whose
        // edges matter. The document sanitiser trims the fields it means to trim (titles, section names) itself.
        $middleware->trimStrings(except: [
            fn ($request) => $request->is('api/decks/*/ops') || $request->is('api/layouts', 'api/layouts/*'),
        ]);

        $middleware->alias([
            'operator' => EnsurePlatformOperator::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Feeds the guardian's hourly error counter (ErrorRateCheck). The
        // counter swallows its own failures, so this cannot break reporting.
        $exceptions->report(function (Throwable $e): void {
            app(ErrorCounter::class)->record($e);
        });
    })->create();
