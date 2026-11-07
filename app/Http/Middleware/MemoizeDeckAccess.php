<?php

namespace App\Http\Middleware;

use App\Services\Press\DeckAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets DeckAccess remember each grant it works out for the length of one HTTP request (see DeckAccess::remember).
 * Route binding, the policy, the controller and the page's ability list all ask the same question about the same deck.
 */
class MemoizeDeckAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        DeckAccess::remember(true);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        DeckAccess::remember(false);
    }
}
