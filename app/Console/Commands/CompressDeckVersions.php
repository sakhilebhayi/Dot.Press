<?php

namespace App\Console\Commands;

use App\Models\DeckVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Packs version snapshots that were stored as plain JSON before snapshots were gzipped (DeckVersion::pack). Safe to run
 * any time and again: a packed row is skipped, a row that does not shrink enough stays as it is, and reads work on both.
 * Twenty rows at a time keeps memory flat; a time budget keeps one run short on a shared host.
 */
class CompressDeckVersions extends Command
{
    protected $signature = 'press:versions-compress {--seconds=20 : Stop after this many seconds}';

    protected $description = 'Gzip version snapshots that are still stored as plain JSON.';

    public function handle(): int
    {
        $deadline = microtime(true) + max(1, (int) $this->option('seconds'));
        $packed = 0;
        $saved = 0;

        DB::table('deck_versions')->where('snapshot', 'not like', DeckVersion::PACKED.'%')->orderBy('id')->chunkById(20, function ($rows) use (&$packed, &$saved, $deadline): bool {
            foreach ($rows as $row) {
                $new = DeckVersion::pack($row->snapshot);
                if ($new !== null && $new !== $row->snapshot) {
                    DB::table('deck_versions')->where('id', $row->id)->update(['snapshot' => $new]);
                    $packed++;
                    $saved += strlen($row->snapshot) - strlen($new);
                }
            }

            return microtime(true) < $deadline;
        });

        $this->info("Packed {$packed} snapshots, saving ".round($saved / 1048576, 1).' MB.');

        return self::SUCCESS;
    }
}
