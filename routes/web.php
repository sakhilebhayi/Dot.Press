<?php

use App\Http\Controllers\Api\PresentSessionController;
use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\CspReportController;
use App\Http\Controllers\DeckPageController;
use App\Http\Controllers\DependencyPatchController;
use App\Http\Controllers\GuardianHealthController;
use App\Http\Controllers\PrintController;
use App\Http\Controllers\SchedulerProbeController;
use App\Http\Controllers\SharedDeckController;
use App\Http\Middleware\AuthenticateGuardian;
use App\Http\Middleware\PrivateShareResponse;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Jetstream\Jetstream;

Route::post('/auth/ecosystem', [EcosystemAuthController::class, 'handle'])
    ->withoutMiddleware(['Illuminate\Foundation\Http\Middleware\PreventRequestForgery', 'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken', 'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken'])
    ->name('ecosystem.auth');

// Content-Security-Policy violation reports. The browser posts these itself, so there is no form, no session and no
// CSRF token: the whole web group (session, cookies, CSRF, Inertia) is dropped and the route is throttled instead.
Route::post('/csp-report', CspReportController::class)
    ->withoutMiddleware('web')
    ->middleware('throttle:60,1,csp-report')
    ->name('csp.report');

// Links minted before the token moved into a POST body carried it in the URL. They are no longer honoured:
// the visitor is sent to the normal sign-in page and the token in the URL is never read.
Route::get('/auth/ecosystem', [EcosystemAuthController::class, 'legacy']);

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

// Cookie Policy — Jetstream's termsAndPrivacyPolicy feature covers terms.show/policy.show
// natively (registered at /terms-of-service and /privacy-policy, rendering the Inertia
// PrivacyPolicy/TermsOfService pages from resources/markdown/{terms,policy}.md). There's no
// Jetstream equivalent for a Cookie Policy, so this one is wired by hand, following the exact
// same Markdown-source + Inertia::render convention.
Route::get('/cookies', function () {
    return Inertia::render('CookiePolicy', [
        'cookies' => Str::markdown(file_get_contents(Jetstream::localizedMarkdownPath('cookies.md'))),
    ]);
})->name('cookies');

// The pages a search engine may list: the landing page and the three legal pages. Share links, the library and the
// editor are never listed (see public/robots.txt, and PrivateShareResponse for share links).
Route::get('/sitemap.xml', function () {
    $urls = array_filter([
        url('/'),
        Route::has('terms.show') ? route('terms.show') : null,
        Route::has('policy.show') ? route('policy.show') : null,
        route('cookies'),
    ]);
    $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
    foreach ($urls as $url) {
        $xml .= '  <url><loc>'.e($url).'</loc></url>'."\n";
    }

    return response($xml.'</urlset>'."\n", 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
})->name('sitemap');

// Public share links (no sign-in). The token is exactly 40 letters and digits, so anything else is a plain 404 before
// the database is touched. The pages and assets are never cached or indexed (PrivateShareResponse).
Route::middleware(PrivateShareResponse::class)
    ->prefix('s/{token}')
    ->where(['token' => '[A-Za-z0-9]{40}', 'asset' => '[0-9]+'])
    ->group(function (): void {
        Route::get('/', [SharedDeckController::class, 'show'])->name('shared.show');
        Route::post('unlock', [SharedDeckController::class, 'unlock'])->name('shared.unlock');
        Route::get('document', [SharedDeckController::class, 'document'])->name('shared.document');
        Route::get('asset/{asset}', [SharedDeckController::class, 'asset'])->middleware('throttle:600,1,press-share-asset')->name('shared.asset');
    });

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', [DeckPageController::class, 'library'])->name('dashboard');
    Route::get('/decks/{deck}', [DeckPageController::class, 'editor'])->whereNumber('deck')->name('press.editor');

    // Links from before R-40 (one page per slide) keep working.
    Route::get('/editor', [DeckPageController::class, 'legacyEditor'])->name('editor.start');
    Route::get('/editor/decks/{deck}/slides/{slide}', [DeckPageController::class, 'legacyEditor'])->name('editor.slides.show');
    Route::get('/present/decks/{deck}/slides/{slide}', [DeckPageController::class, 'legacyPresent'])->name('presentation.slides.show');

    // R-40 presenting (signed in): full screen player, presenter console, audience window.
    Route::get('/decks/{deck}/present', [PresentSessionController::class, 'player'])->name('press.player');
    Route::get('/decks/{deck}/presenter', [PresentSessionController::class, 'presenter'])->name('press.presenter');
    Route::get('/decks/{deck}/audience', [PresentSessionController::class, 'audienceWindow'])->name('press.audience');

    // R-40 print view: all slides, one per page, for the browser's Save as PDF.
    Route::get('/decks/{deck}/print', [PrintController::class, 'show'])->whereNumber('deck')->name('press.print');

    Route::middleware('operator')->prefix('operator')->name('operator.')->group(function () {
        Route::get('/dependency-patches', [DependencyPatchController::class, 'index'])->name('dependency-patches.index');
        Route::post('/dependency-patches/{proposal}/approve', [DependencyPatchController::class, 'approve'])->name('dependency-patches.approve');
        Route::post('/dependency-patches/{proposal}/reject', [DependencyPatchController::class, 'reject'])->name('dependency-patches.reject');
    });
});

// Public liveness probe for the cron scheduler and queue worker, watched from GitHub every
// 15 minutes by .github/workflows/scheduler-watchdog.yml.
Route::get('/up/scheduler', SchedulerProbeController::class)->name('up.scheduler');

// Guardian observability report for Dot.Brain (dot-guardian/v1): operational
// detail, so behind a bearer token (fails closed with 503 when none is
// configured) rather than public like /up and /up/scheduler.
Route::get('/guardian/health', GuardianHealthController::class)
    ->middleware(AuthenticateGuardian::class)
    ->name('guardian.health');

// Public sign-up is closed (accounts are created by InfoDot at first sign-in). Keep the named route so any link or
// invitation email that points at it lands on the login page instead of failing.
if (! Features::enabled(Features::registration())) {
    Route::redirect('/register', '/login')->name('register');
}

// R-40 live presenting (public). The 40 character session token is the permission; every route is rate limited.
// The pages keep the web group (Inertia). The polling, command and asset routes drop it: they carry no cookie, and a
// session record for every poll of every follower would be wasted writes. They send no CSRF token for the same reason.
Route::middleware(['throttle:present-public', PrivateShareResponse::class])->group(function () {
    Route::get('/live/{token}', [PresentSessionController::class, 'follow'])->where('token', '[A-Za-z0-9]{40}')->name('press.live');
    Route::get('/remote/{token}', [PresentSessionController::class, 'remote'])->where('token', '[A-Za-z0-9]{40}')->name('press.remote');
});
Route::withoutMiddleware('web')->group(function () {
    Route::get('/live/{token}/state', [PresentSessionController::class, 'state'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:present-state')->name('press.live.state');
    Route::post('/live/{token}/command', [PresentSessionController::class, 'command'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:present-command')->name('press.live.command');
    Route::get('/present-asset/{asset}', [PresentSessionController::class, 'asset'])->whereNumber('asset')->middleware(['signed:relative', 'throttle:present-asset'])->name('press.asset');
});
