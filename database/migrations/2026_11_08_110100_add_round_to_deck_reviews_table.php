<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A review round is one request-to-verdict cycle. Only the latest round of a deck counts towards its approval, and
 * inside it a reviewer who decided on an older version of the content has to look again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deck_reviews', function (Blueprint $table) {
            $table->unsignedInteger('round')->default(1)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('deck_reviews', function (Blueprint $table) {
            $table->dropColumn('round');
        });
    }
};
