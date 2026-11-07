<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/** Named rate limiters for the Dot.Press export and import endpoints. */
class PressExportServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('press-export', function (Request $request): array {
            $key = (string) optional($request->user())->id ?: $request->ip();

            return [Limit::perMinute(10)->by('pe-m:'.$key), Limit::perHour(60)->by('pe-h:'.$key)];
        });
        RateLimiter::for('press-import', function (Request $request): array {
            $key = (string) optional($request->user())->id ?: $request->ip();

            return [Limit::perMinute(4)->by('pi-m:'.$key), Limit::perHour(20)->by('pi-h:'.$key)];
        });
    }
}
