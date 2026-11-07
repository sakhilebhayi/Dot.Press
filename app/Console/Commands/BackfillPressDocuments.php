<?php

namespace App\Console\Commands;

use App\Services\Press\Backfill;
use Illuminate\Console\Command;

class BackfillPressDocuments extends Command
{
    protected $signature = 'press:backfill';

    protected $description = 'Convert decks made before R-40 into the document model (safe to run again).';

    public function handle(Backfill $backfill): int
    {
        $stats = $backfill->run();

        $this->info(sprintf(
            'Owners %d, themes %d, slides %d, elements %d, dropped %d, failed %d.',
            $stats['owners'], $stats['themes'], $stats['slides'], $stats['elements'], $stats['dropped'], $stats['failed'],
        ));

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
