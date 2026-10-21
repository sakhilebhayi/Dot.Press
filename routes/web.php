<?php

use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\DependencyPatchController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\GuardianHealthController;
use App\Http\Controllers\SchedulerProbeController;
use App\Http\Controllers\SlideEditorController;
use App\Http\Middleware\AuthenticateGuardian;
use App\Models\Project;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Jetstream\Jetstream;

Route::match(['get', 'post'], '/auth/ecosystem', [EcosystemAuthController::class, 'handle'])
    ->withoutMiddleware(['Illuminate\Foundation\Http\Middleware\PreventRequestForgery', 'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken', 'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken'])
    ->name('ecosystem.auth');

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

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', function () {
        $projects = Project::where('user_id', auth()->id())
            ->with(['decks' => fn ($query) => $query->withCount('slides')->orderByDesc('updated_at')])
            ->latest('id')
            ->get();

        // Courtesy for the UI only: the API enforces this on every write.
        $projects->each(fn (Project $project) => $project->setAttribute('can_edit', auth()->user()->can('update', $project)));

        return Inertia::render('Dashboard', [
            'projects' => $projects,
        ]);
    })->name('dashboard');

    Route::get('/editor', [SlideEditorController::class, 'start'])->name('editor.start');
    Route::get('/editor/decks/{deck}/slides/{slide}', [SlideEditorController::class, 'show'])
        ->name('editor.slides.show');
    Route::get('/present/decks/{deck}/slides/{slide}', [SlideEditorController::class, 'present'])
        ->name('presentation.slides.show');
    Route::get('/export/decks/{deck}/pdf', [ExportController::class, 'pdf'])
        ->middleware('throttle:export')
        ->name('export.decks.pdf');
    Route::get('/export/decks/{deck}/pptx', [ExportController::class, 'pptx'])
        ->middleware('throttle:export')
        ->name('export.decks.pptx');

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
