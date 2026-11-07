<?php

namespace App\Console\Commands;

use App\Models\DeckChange;
use App\Models\DeckPresence;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the operations log to a window. Rows older than the window are deleted except the newest of them per deck,
 * which becomes a `tombstone`: its only op is deck.reload and it marks how far back the log still reaches, so a client
 * whose last seen seq is older than a tombstone is answered `stale` and reloads the document. Also clears presence rows
 * that nobody refreshed.
 */
class PruneDeckChanges extends Command
{
    public const TOMBSTONE_KEY = 'tombstone';

    protected $signature = 'press:prune-changes {--days=14 : How many days of log to keep}';

    protected $description = 'Delete operations-log rows older than the retention window and stale presence rows.';

    public function handle(): int
    {
        $cutoff = now()->subDays(max(1, (int) $this->option('days')));
        $deleted = 0;

        $deckIds = DeckChange::query()->where('created_at', '<', $cutoff)->distinct()->pluck('deck_id');

        foreach ($deckIds as $deckId) {
            DB::transaction(function () use ($deckId, $cutoff, &$deleted): void {
                $newestOld = DeckChange::query()->where('deck_id', $deckId)->where('created_at', '<', $cutoff)->max('id');
                if ($newestOld === null) {
                    return;
                }

                // In slices: one DELETE of a million rows would hold the database write lock, and so block every
                // editor's save, for as long as it ran.
                do {
                    $slice = DeckChange::query()->where('deck_id', $deckId)->where('id', '<', $newestOld)->limit(5000)->delete();
                    $deleted += $slice;
                } while ($slice === 5000);

                DeckChange::query()->whereKey($newestOld)->update([
                    'key' => self::TOMBSTONE_KEY,
                    'client' => 'server',
                    'ops' => json_encode([['op' => 'deck.reload']]),
                    'result' => null,
                ]);
            });
        }

        DeckPresence::query()->where('seen_at', '<', now()->subMinutes(2))->delete();

        $this->info("Pruned {$deleted} change rows.");

        return self::SUCCESS;
    }
}
