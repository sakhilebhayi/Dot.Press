<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('ai', function (Request $request): Limit {
            $maxAttempts = (int) config('ai.limits.per_minute', 20);
            $key = (string) optional($request->user())->id;

            return Limit::perMinute($maxAttempts)->by($key !== '' ? $key : $request->ip());
        });

        RateLimiter::for('export', function (Request $request): Limit {
            $key = (string) optional($request->user())->id;

            return Limit::perMinute(10)->by($key !== '' ? $key : $request->ip());
        });

        RateLimiter::for('collab', function (Request $request): Limit {
            $key = (string) optional($request->user())->id;

            return Limit::perMinute(120)->by($key !== '' ? $key : $request->ip());
        });
    }
}
