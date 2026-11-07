<?php

use App\Services\Press\Backfill;
use Illuminate\Database\Migrations\Migration;

/**
 * R-40 data step: decks, slides and elements made before the document model are converted in place. The old `elements`
 * table is left as it is. The conversion is idempotent (a slide whose `els` is already set is skipped), so running it
 * on an empty database or again later changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new Backfill)->run();
    }

    public function down(): void
    {
        // The converted data is additive; nothing to undo.
    }
};
