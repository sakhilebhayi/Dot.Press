<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
        // /up answered 200 whenever PHP booted, even with the database down. The framework dispatches DiagnosingHealth
        // before it renders the page: a failing query turns the answer into a 500 (the exception text is never shown).
        Event::listen(DiagnosingHealth::class, fn () => DB::select('select 1'));

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
