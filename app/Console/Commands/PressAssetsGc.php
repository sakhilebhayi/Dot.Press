<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Services\Press\Assets\AssetStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Keeps the asset library and its storage in step: files under projects/{id}/assets/ that no row points at are
 * removed, and rows whose original file is gone are removed (with their other variants and a video's poster).
 * Files younger than the grace period are left alone so an upload in progress is never collected.
 */
class PressAssetsGc extends Command
{
    protected $signature = 'press:assets-gc {--dry-run : Report what would be removed without removing anything} {--grace=60 : Minutes a file must be older than before it can be collected}';

    protected $description = 'Remove asset files that have no row and asset rows whose file is missing.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $grace = max(0, (int) $this->option('grace')) * 60;

        $disks = Asset::withoutGlobalScopes()->distinct()->pluck('disk')->push(AssetStore::uploadDisk())->unique()->values();

        $orphans = 0;
        $missing = 0;
        $damaged = 0;

        foreach ($disks as $name) {
            $storage = Storage::disk($name);

            $rows = Asset::withoutGlobalScopes()->where('disk', $name)->get();
            $known = [];
            foreach ($rows as $row) {
                foreach ([$row->path, $row->web_path, $row->thumb_path] as $path) {
                    if ($path !== null) {
                        $known[$path] = true;
                    }
                }
            }

            foreach ($storage->allFiles('projects') as $file) {
                if (preg_match('#^projects/\d+/assets/#', $file) !== 1 || isset($known[$file])) {
                    continue;
                }
                if ($grace > 0 && time() - $storage->lastModified($file) < $grace) {
                    continue;
                }

                $orphans++;
                $this->line(($dryRun ? 'would delete file ' : 'deleted file ').$name.':'.$file);
                if (! $dryRun) {
                    $storage->delete($file);
                }
            }

            foreach ($rows as $row) {
                if (! $storage->exists($row->path)) {
                    $missing++;
                    $this->line(($dryRun ? 'would delete row ' : 'deleted row ').$row->id.' ('.$row->original_name.'): file '.$row->path.' is missing');
                    if (! $dryRun) {
                        AssetStore::purge($row);
                    }

                    continue;
                }

                foreach ([$row->web_path, $row->thumb_path] as $variant) {
                    if ($variant !== null && ! $storage->exists($variant)) {
                        $damaged++;
                        $this->warn('asset '.$row->id.' is missing its variant '.$variant.' (the row is kept)');
                    }
                }
            }
        }

        $this->info(sprintf(
            '%s: %d orphan file(s), %d row(s) with a missing file, %d missing variant(s).',
            $dryRun ? 'Dry run' : 'Done',
            $orphans,
            $missing,
            $damaged,
        ));

        return self::SUCCESS;
    }
}
