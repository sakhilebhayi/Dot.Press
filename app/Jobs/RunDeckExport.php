<?php

namespace App\Jobs;

use App\Models\DeckExport;
use App\Services\Press\Export\ExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Throwable;

/**
 * Renders one deck export on the database queue. It retries once, and when it finally fails the export row says why in
 * plain words. The deck is only ever read.
 */
class RunDeckExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 280;

    public function __construct(public int $exportId)
    {
        $this->tries = (int) config('press_export.job_attempts', 2);
        $this->timeout = (int) config('press_export.job_timeout', 280);
        $this->onQueue('default');
    }

    public function backoff(): array
    {
        return [30];
    }

    public function handle(ExportService $service): void
    {
        $export = DeckExport::find($this->exportId);
        if ($export === null || in_array($export->status, ['done', 'expired'], true)) {
            return;
        }
        $service->run($export);
        $export->refresh();
        $retryable = $this->job !== null && ! $this->job instanceof SyncJob && $this->attempts() < $this->tries;
        if ($export->status === 'failed' && $retryable) {
            // Try once more: put the row back in the waiting state first so the page shows it is not over.
            $export->forceFill(['status' => 'queued'])->save();
            $this->release(30);
        }
    }

    public function failed(Throwable $e): void
    {
        $export = DeckExport::find($this->exportId);
        if ($export !== null && ! in_array($export->status, ['done', 'failed'], true)) {
            app(ExportService::class)->fail($export, $e);
        }
    }
}
