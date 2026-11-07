<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `updated_at` moves on every reply-driven touch, resolve and reopen, so it cannot say "edited". `edited_at` is set only
 * when the author changes the words of a comment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deck_comments', function (Blueprint $table) {
            $table->timestamp('edited_at')->nullable()->after('resolved_by');
        });
    }

    public function down(): void
    {
        Schema::table('deck_comments', function (Blueprint $table) {
            $table->dropColumn('edited_at');
        });
    }
};
