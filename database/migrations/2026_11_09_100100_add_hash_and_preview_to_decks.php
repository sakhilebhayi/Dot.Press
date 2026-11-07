<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-40 performance: two things the library and the document answer used to recompute on every request.
 *
 *  - content_hash / hash_rev: the deck's content fingerprint (DeckHash::current) and the `rev` it was computed at. The
 *    status of a deck that was ever approved needs it, and computing it reads every slide.
 *  - preview / preview_rev: the library card, the first slide's background and at most 60 KB of its elements, and the
 *    `rev` it was cut at (DeckPreview). The library used to read the first slide of each of the 24 decks it shows, one
 *    query each, with its whole element JSON.
 *
 * Both are caches: nothing depends on them being present, and a deck row with null columns is simply filled the next
 * time it is read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('decks', function (Blueprint $table) {
            $table->string('content_hash', 64)->nullable();
            $table->unsignedInteger('hash_rev')->nullable();
            $table->json('preview')->nullable();
            $table->unsignedInteger('preview_rev')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('decks', function (Blueprint $table) {
            $table->dropColumn(['content_hash', 'hash_rev', 'preview', 'preview_rev']);
        });
    }
};
