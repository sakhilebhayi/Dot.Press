<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureCompanyEntitled;
use App\Services\InfoDot\CompanyEntitlement;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where an in-app (Inertia) visit lands when the current company no longer holds Dot.Press: the same plain 403 page
 * a full page load gets. A person whose company holds it (or whose answer is unknown) is sent on to the library, so
 * the address is never a dead end and never a loop.
 */
class EntitlementController extends Controller
{
    public function __invoke(Request $request, CompanyEntitlement $entitlement): Response
    {
        $refusal = EnsureCompanyEntitled::refusalFor($request->user(), $entitlement);

        return $refusal === null ? redirect()->route('dashboard') : EnsureCompanyEntitled::page($refusal, $request->user());
    }
}
