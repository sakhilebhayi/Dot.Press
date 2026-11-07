<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R-40: the presentation becomes a document. The deck carries its size, theme, status and sharing; the slide carries
 * its own elements as JSON (the old `elements` table is kept, unused, until a later release has proved the backfill);
 * every change is an operation in an append-only log; versions, comments, reviews, activity and presence get tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('decks', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('project_id')->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('w')->default(1920);
            $table->unsignedSmallInteger('h')->default(1080);
            $table->string('status', 16)->default('draft');
            $table->string('visibility', 12)->default('team');
            $table->string('share_token', 64)->nullable()->unique();
            $table->string('share_password')->nullable();
            $table->timestamp('share_expires_at')->nullable();
            $table->boolean('share_allow_download')->default(false);
            $table->string('approved_hash', 64)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedInteger('rev')->default(0);
            $table->json('sections')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->string('template_category', 40)->nullable();
            $table->string('template_blurb', 240)->nullable();
        });

        // Decks made before R-40 were drawn on a 1280 x 720 stage; keeping that size keeps every position meaningful.
        DB::table('decks')->update(['w' => 1280, 'h' => 720]);

        Schema::table('slides', function (Blueprint $table) {
            $table->json('els')->nullable();
            $table->json('bg')->nullable();
            $table->json('transition')->nullable();
            $table->string('section_id', 24)->nullable();
            $table->boolean('hidden')->default(false);
        });

        Schema::create('deck_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deck_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('client', 40);
            $table->string('key', 64);
            $table->json('ops');
            $table->json('result')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['deck_id', 'key']);
        });

        Schema::create('deck_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deck_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 16)->default('auto');
            $table->string('label', 120)->nullable();
            $table->longText('snapshot');
            $table->string('hash', 64);
            $table->unsignedSmallInteger('slide_count')->default(0);
            $table->unsignedInteger('size')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->index(['deck_id', 'created_at']);
        });

        Schema::create('deck_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deck_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 12)->default('viewer');
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['deck_id', 'user_id']);
        });

        Schema::create('deck_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deck_id')->constrained()->cascadeOnDelete();
            $table->foreignId('slide_id')->nullable()->constrained()->nullOnDelete();
            $table->string('element_id', 40)->nullable();
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->json('mentions')->nullable();
            $table->json('anchor')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['deck_id', 'resolved_at']);
        });

        Schema::create('deck_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deck_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 12)->default('pending');
            $table->text('note')->nullable();
            $table->text('decision_note')->nullable();
            $table->string('version_hash', 64)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['deck_id', 'status']);
        });

        Schema::create('deck_activity', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deck_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 40);
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['deck_id', 'id']);
        });

        Schema::create('deck_presence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deck_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('client', 40);
            $table->string('name');
            $table->string('color', 9);
            $table->unsignedBigInteger('slide_id')->nullable();
            $table->json('selection')->nullable();
            $table->json('cursor')->nullable();
            $table->timestamp('seen_at');
            $table->unique(['deck_id', 'client']);
        });

        Schema::create('deck_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deck_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('favourite')->default(false);
            $table->timestamp('last_opened_at')->nullable();
            $table->unique(['deck_id', 'user_id']);
        });

        Schema::create('themes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 80);
            $table->json('tokens');
            $table->timestamps();
        });

        Schema::create('brand_kits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->json('tokens');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('layouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 80);
            $table->string('kind', 24)->default('custom');
            $table->json('definition');
            $table->timestamps();
        });

        Schema::create('deck_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deck_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('format', 12);
            $table->string('status', 12)->default('queued');
            $table->json('options')->nullable();
            $table->string('disk', 24)->nullable();
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->text('error')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['deck_id', 'status']);
        });

        Schema::create('present_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deck_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 40)->unique();
            $table->unsignedSmallInteger('slide_index')->default(0);
            $table->unsignedSmallInteger('step')->default(0);
            $table->json('state')->nullable();
            $table->json('command')->nullable();
            $table->timestamp('last_seen')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->string('kind', 12)->default('file');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('thumb_path')->nullable();
            $table->string('web_path')->nullable();
            $table->string('sha256', 64)->nullable()->index();
            $table->string('alt', 240)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn(['kind', 'width', 'height', 'thumb_path', 'web_path', 'sha256', 'alt']);
        });
        foreach (['present_sessions', 'deck_exports', 'layouts', 'brand_kits', 'themes', 'deck_user', 'deck_presence', 'deck_activity', 'deck_reviews', 'deck_comments', 'deck_shares', 'deck_versions', 'deck_changes'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('slides', function (Blueprint $table) {
            $table->dropColumn(['els', 'bg', 'transition', 'section_id', 'hidden']);
        });
        Schema::table('decks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['w', 'h', 'status', 'visibility', 'share_token', 'share_password', 'share_expires_at', 'share_allow_download', 'approved_hash', 'approved_at', 'rev', 'sections', 'archived_at', 'template_category', 'template_blurb']);
        });
    }
};
