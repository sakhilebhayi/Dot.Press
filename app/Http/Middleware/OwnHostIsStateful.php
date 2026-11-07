<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The editor and the library call /api/* from the signed in browser, so a request whose Origin or Referer is this app's
 * own host must get the session (Sanctum's "stateful" check). The list normally holds APP_URL's host, but a
 * SANCTUM_STATEFUL_DOMAINS left in the host's .env from an older setup replaces it, and then every call answers 401
 * "Unauthenticated" right after a good sign-in. This adds the host the request was actually made to, plus APP_URL's.
 * A request can only name its own host here: a victim's browser always sends this app's real host.
 */
class OwnHostIsStateful
{
    public function handle(Request $request, Closure $next): Response
    {
        $hosts = array_filter([$request->getHttpHost(), parse_url((string) config('app.url'), PHP_URL_HOST)]);

        config(['sanctum.stateful' => array_values(array_unique([...(array) config('sanctum.stateful', []), ...$hosts]))]);

        return $next($request);
    }
}
