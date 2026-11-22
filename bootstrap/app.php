<?php

use App\Http\Middleware\CompressResponse;
use App\Http\Middleware\EnsureCompanyEntitled;
use App\Http\Middleware\EnsurePlatformOperator;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\MemoizeDeckAccess;
use App\Http\Middleware\OwnHostIsStateful;
use App\Http\Middleware\SecurityHeaders;
use App\Services\Guardian\ErrorCounter;
use App\Support\CentralSignIn;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;

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
        // R-50: the browser's own calls stop when the current company no longer holds Dot.Press (a bearer token passes).
        $middleware->api(append: [EnsureCompanyEntitled::class]);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            // R-50: signed-in pages only; public and shared links are untouched (see the middleware).
            EnsureCompanyEntitled::class,
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
        // R-54: with central sign-in on, a signed-out visit made inside the app (an Inertia link, the session having
        // expired) must leave the page for /sso/login as a full page load: a redirect that an XHR follows would cross
        // origin to InfoDot and fail. The intended URL is kept (GET only) so the person comes back to the same page.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->header('X-Inertia') && CentralSignIn::active()) {
                if ($request->isMethod('GET') && $request->hasSession()) {
                    $request->session()->put('url.intended', $request->fullUrl());
                }

                return Inertia::location(route('dotsso.login'));
            }

            return null;
        });

        // Feeds the guardian's hourly error counter (ErrorRateCheck). The
        // counter swallows its own failures, so this cannot break reporting.
        $exceptions->report(function (Throwable $e): void {
            app(ErrorCounter::class)->record($e);
        });
    })->create();
