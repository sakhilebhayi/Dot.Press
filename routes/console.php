<?php

use App\Console\Commands\BackupSqliteDatabase;
use App\Console\Commands\CompressDeckVersions;
use App\Console\Commands\PressAssetsGc;
use App\Console\Commands\PruneDeckChanges;
use App\Console\Commands\PruneDeckExports;
use App\Console\Commands\ScanDependencyAdvisories;
use App\Http\Controllers\SchedulerProbeController;
use App\Jobs\QueueHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(ScanDependencyAdvisories::class)->weekly();

// Liveness of the platform itself. /up/scheduler reports this heartbeat's
// age and answers 503 once it is older than
// SchedulerProbeController::SCHEDULER_STALE_SECONDS; the Scheduler Watchdog
// workflow turns that into a failed (emailed) run every 15 minutes, and the
// Guardian's scheduler check reads the same key.
Schedule::call(fn () => Cache::forever(SchedulerProbeController::SCHEDULER_KEY, now()->toIso8601String()))
    ->everyMinute()
    ->name('scheduler-heartbeat');

// Proves the cron-driven queue worker actually drains jobs, not just that
// the scheduler fires: only a worker that runs this can refresh the key.
Schedule::job(new QueueHeartbeat)->everyFiveMinutes()->name('queue-heartbeat');

// Nightly copy of the SQLite file, which holds the only copy of this app's
// data. The SQLite-backed Dot apps share one host, so each one backs up at its
// own minute. No-op on other database drivers.
Schedule::command(BackupSqliteDatabase::class)->dailyAt('02:55')->onOneServer();

// Orphan asset files and rows whose file is gone (R-40). Runs after the backup, at its own minute.
Schedule::command(PressAssetsGc::class)->dailyAt('03:25')->onOneServer();
// The operations log keeps 14 days; older rows collapse into one tombstone per deck (see PruneDeckChanges).
Schedule::command(PruneDeckChanges::class)->dailyAt('03:20')->name('press-prune-changes');

// Export files live for 24 hours; this removes them and tidies the history.
Schedule::command(PruneDeckExports::class)->hourly()->name('press-exports-prune')->onOneServer();

// Old plain-JSON version snapshots are packed a few at a time (new ones are packed when they are written).
Schedule::command(CompressDeckVersions::class)->dailyAt('03:40')->name('press-versions-compress')->onOneServer();

// Expired sessions are removed here, in slices, instead of by the 2 percent of requests that Laravel picks to do it
// (one of them used to pay for the whole backlog). Only when sessions live in the database.
Schedule::call(function (): void {
    if (config('session.driver') !== 'database') {
        return;
    }
    $cutoff = time() - (int) config('session.lifetime', 120) * 60;
    do {
        $n = DB::table(config('session.table', 'sessions'))->where('last_activity', '<=', $cutoff)->limit(5000)->delete();
    } while ($n === 5000);
})->hourly()->name('press-sessions-prune');
