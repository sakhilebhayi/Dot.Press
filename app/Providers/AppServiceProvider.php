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

        // Dot.Press document traffic is limited per person and deck, so one busy deck cannot starve another.
        foreach (['press-ops' => 300, 'press-changes' => 240, 'press-presence' => 150, 'press-read' => 120] as $name => $perMinute) {
            RateLimiter::for($name, function (Request $request) use ($perMinute): Limit {
                $deck = $request->route('deck');
                $deckKey = is_object($deck) ? $deck->getKey() : (string) $deck;
                $key = (string) optional($request->user())->id;

                return Limit::perMinute($perMinute)->by(($key !== '' ? $key : $request->ip()).'|'.$deckKey);
            });
        }
    }
}
