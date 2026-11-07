<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
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
        // Every link the app writes into mail (password reset, verification, invitations, deck links) and every redirect
        // comes from APP_URL, never from the Host header of the request, so a request sent with a Host of someone
        // else's choosing cannot put their address into a message from this platform.
        if (config('security.force_root_url')) {
            URL::forceRootUrl((string) config('app.url'));
            // Only ever upgrade to https: a wrong http APP_URL must not downgrade a site that is served securely.
            if (parse_url((string) config('app.url'), PHP_URL_SCHEME) === 'https') {
                URL::forceScheme('https');
            }
        }

        RateLimiter::for('ai', function (Request $request): Limit {
            $maxAttempts = (int) config('ai.limits.per_minute', 20);
            $key = (string) optional($request->user())->id;

            return Limit::perMinute($maxAttempts)->by($key !== '' ? $key : $request->ip());
        });

        RateLimiter::for('export', function (Request $request): Limit {
            $key = (string) optional($request->user())->id;

            return Limit::perMinute(10)->by($key !== '' ? $key : $request->ip());
        });

        // R-40 presenting. Followers poll once a second and a lecture hall is usually one network address, so the
        // limits count in rooms, not in persons: an address may make 1200 polls a minute for one session (20 people
        // polling every second), the session as a whole 6000 (100 people), and an address 1200 page loads a minute.
        // They stay finite because the answer costs a database read; the 40 character token cannot be guessed anyway.
        RateLimiter::for('present-public', fn (Request $request): Limit => Limit::perMinute(1200)->by($request->ip()));
        RateLimiter::for('present-state', fn (Request $request): array => [
            Limit::perMinute(1200)->by('state|'.$request->route('token').'|'.$request->ip()),
            Limit::perMinute(6000)->by('state|'.$request->route('token')),
        ]);
        RateLimiter::for('present-command', fn (Request $request): Limit => Limit::perMinute(90)->by('cmd|'.$request->route('token').'|'.$request->ip()));
        RateLimiter::for('present-asset', fn (Request $request): Limit => Limit::perMinute(6000)->by('asset|'.$request->ip()));
        RateLimiter::for('present-session', fn (Request $request): Limit => Limit::perMinute(20)->by('sess|'.(optional($request->user())->id ?? $request->ip())));
        RateLimiter::for('present-push', fn (Request $request): Limit => Limit::perMinute(240)->by('push|'.(optional($request->user())->id ?? $request->ip())));

        // R-40 performance. Routes that had no limit at all. The library lists decks and projects (about three requests a
        // page view); assets are listed by the picker and uploaded one decode at a time (a 12 megapixel photograph is half a
        // second of CPU and 40 MB of memory); an export is polled while it renders.
        RateLimiter::for('press-library', fn (Request $request): Limit => Limit::perMinute(180)->by('lib|'.(optional($request->user())->id ?? $request->ip())));
        RateLimiter::for('press-assets', fn (Request $request): Limit => Limit::perMinute(300)->by('assets|'.(optional($request->user())->id ?? $request->ip())));
        RateLimiter::for('press-upload', fn (Request $request): array => [
            Limit::perMinute(30)->by('up-m|'.(optional($request->user())->id ?? $request->ip())),
            Limit::perHour(400)->by('up-h|'.(optional($request->user())->id ?? $request->ip())),
        ]);
        RateLimiter::for('press-export-status', fn (Request $request): Limit => Limit::perMinute(150)->by('xs|'.(optional($request->user())->id ?? $request->ip())));

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
