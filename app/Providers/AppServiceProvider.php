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

        // R-40 presenting. Followers poll once a second, so the limits sit well above one person's rate and are
        // keyed by session token and address: a lecture hall behind one network address shares a budget per session.
        RateLimiter::for('present-public', fn (Request $request): Limit => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('present-state', fn (Request $request): Limit => Limit::perMinute(180)->by('state|'.$request->route('token').'|'.$request->ip()));
        RateLimiter::for('present-command', fn (Request $request): Limit => Limit::perMinute(90)->by('cmd|'.$request->route('token').'|'.$request->ip()));
        RateLimiter::for('present-asset', fn (Request $request): Limit => Limit::perMinute(600)->by('asset|'.$request->ip()));
        RateLimiter::for('present-session', fn (Request $request): Limit => Limit::perMinute(20)->by('sess|'.(optional($request->user())->id ?? $request->ip())));
        RateLimiter::for('present-push', fn (Request $request): Limit => Limit::perMinute(240)->by('push|'.(optional($request->user())->id ?? $request->ip())));

        RateLimiter::for('collab', function (Request $request): Limit {
            $key = (string) optional($request->user())->id;

            return Limit::perMinute(120)->by($key !== '' ? $key : $request->ip());
        });
    }
}
