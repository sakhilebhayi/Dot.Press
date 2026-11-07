<?php

namespace App\Console\Commands;

use App\Models\DeckExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Deletes export files once they are older than their expiry (24 hours by default), marks the rows expired, fails rows
 * stuck in the queue for more than an hour and removes old history. Runs from the scheduler.
 */
class PruneDeckExports extends Command
{
    protected $signature = 'press:exports-prune';

    protected $description = 'Delete expired Dot.Press export files and tidy the export history';

    public function handle(): int
    {
        $files = 0;
        // Files are removed one by one (they are files), but the rows are marked in one statement per batch: marking each
        // row on its own was 50,000 UPDATE statements and 6.6 seconds for 100,000 rows.
        DeckExport::query()->where('status', 'done')->where('expires_at', '<', now())->orderBy('id')->chunkById(500, function ($rows) use (&$files): void {
            foreach ($rows as $row) {
                $files += $this->deleteFile($row) ? 1 : 0;
            }
            DeckExport::query()->whereIn('id', $rows->modelKeys())->update(['status' => 'expired', 'path' => null, 'updated_at' => now()]);
        });

        $stuck = DeckExport::query()->whereIn('status', ['queued', 'running'])->where('updated_at', '<', now()->subHour())->update([
            'status' => 'failed', 'error' => 'The export did not finish in time. Try again.', 'finished_at' => now(),
        ]);

        $old = DeckExport::query()->whereIn('status', ['expired', 'failed'])->where('updated_at', '<', now()->subDays(7))->delete();

        // Files with no row (a crash between writing and recording) after two days.
        $orphans = 0;
        $disk = Storage::disk((string) config('press_export.export_disk', 'local'));
        $dir = trim((string) config('press_export.export_dir', 'press/exports'), '/');
        try {
            $known = DeckExport::query()->whereNotNull('path')->pluck('path')->flip();
            foreach ($disk->files($dir) as $f) {
                if (! isset($known[$f]) && $disk->lastModified($f) < now()->subDays(2)->getTimestamp()) {
                    $disk->delete($f);
                    $orphans++;
                }
            }
        } catch (Throwable) {
            // an unreadable directory is not a reason to fail the run
        }

        $this->info("Deleted {$files} expired export files, failed {$stuck} stuck exports, removed {$old} old rows and {$orphans} stray files.");

        return self::SUCCESS;
    }

    private function deleteFile(DeckExport $row): bool
    {
        if ($row->path === null || $row->path === '') {
            return false;
        }
        try {
            return Storage::disk($row->disk ?: (string) config('press_export.export_disk', 'local'))->delete($row->path);
        } catch (Throwable) {
            return false;
        }
    }
}
