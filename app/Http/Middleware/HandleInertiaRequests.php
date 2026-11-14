<?php

namespace App\Http\Middleware;

use App\Services\InfoDot\DotSwitcherProps;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            // The header's "Dot Apps" switcher (R-51). Deferred: the page renders first and the model follows, so a slow
            // or dead InfoDot never holds a page up. The package caches the answer and degrades on any failure.
            'dotSwitcher' => Inertia::defer(fn () => app(DotSwitcherProps::class)->for($request->user()), 'dotui'),
        ];
    }
}
