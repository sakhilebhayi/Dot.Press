<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-40 performance: SQLite (unlike MySQL) does not index a foreign key by itself, so every lookup by deck, project or
 * team was a scan of the whole table, and so was every cascade (deleting a slide scanned the comments, deleting a deck
 * scanned the slides). Measured on a 5,000 row change log, `select max(id) ... where deck_id` went from a scan of the
 * deck's index entries to one B-tree probe. Each index is created only when it is missing, so the migration can be run
 * on a database that already got one by hand.
 *
 * @see docs/programme/R40_PERFORMANCE.md
 */
return new class extends Migration
{
    /** @var array<string, list<array{name: string, columns: list<string>}>> */
    private function indexes(): array
    {
        return [
            // The document: every read and write of a deck lists its slides in order.
            'slides' => [['name' => 'slides_deck_order_index', 'columns' => ['deck_id', 'sort_order', 'id']]],
            // The changes feed, presence's seq and the tombstone look-ups all ask "the newest id of this deck".
            'deck_changes' => [['name' => 'deck_changes_deck_id_id_index', 'columns' => ['deck_id', 'id']]],
            // Library and access scope: decks of a project, decks of a person.
            'decks' => [
                ['name' => 'decks_project_archived_index', 'columns' => ['project_id', 'archived_at']],
                ['name' => 'decks_user_id_index', 'columns' => ['user_id']],
            ],
            'projects' => [['name' => 'projects_team_id_index', 'columns' => ['team_id']]],
            'assets' => [['name' => 'assets_project_kind_id_index', 'columns' => ['project_id', 'kind', 'id']]],
            // The 40 newest automatic versions are kept: the prune walks (deck, kind) by id.
            'deck_versions' => [['name' => 'deck_versions_deck_kind_id_index', 'columns' => ['deck_id', 'kind', 'id']]],
            'deck_comments' => [
                ['name' => 'deck_comments_slide_id_index', 'columns' => ['slide_id']],
                ['name' => 'deck_comments_deck_id_id_index', 'columns' => ['deck_id', 'id']],
            ],
            'deck_exports' => [
                ['name' => 'deck_exports_deck_id_id_index', 'columns' => ['deck_id', 'id']],
                ['name' => 'deck_exports_status_expires_index', 'columns' => ['status', 'expires_at']],
                ['name' => 'deck_exports_user_status_index', 'columns' => ['user_id', 'status']],
            ],
            'present_sessions' => [['name' => 'present_sessions_deck_id_index', 'columns' => ['deck_id']]],
            // "Decks shared with me" runs on every library and every scoped query.
            'deck_shares' => [['name' => 'deck_shares_user_id_deck_id_index', 'columns' => ['user_id', 'deck_id']]],
            'team_user' => [['name' => 'team_user_user_id_index', 'columns' => ['user_id']]],
            // Cascades and "set null" actions when a slide or deck goes away.
            'ai_usage_logs' => [
                ['name' => 'ai_usage_logs_slide_id_index', 'columns' => ['slide_id']],
                ['name' => 'ai_usage_logs_deck_id_index', 'columns' => ['deck_id']],
            ],
            'themes' => [['name' => 'themes_team_id_index', 'columns' => ['team_id']]],
            'brand_kits' => [['name' => 'brand_kits_team_id_index', 'columns' => ['team_id']]],
            'layouts' => [['name' => 'layouts_team_id_index', 'columns' => ['team_id']]],
        ];
    }

    public function up(): void
    {
        foreach ($this->indexes() as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($indexes as $index) {
                if (Schema::hasIndex($table, $index['name']) || ! Schema::hasColumns($table, $index['columns'])) {
                    continue;
                }
                Schema::table($table, function (Blueprint $blueprint) use ($index): void {
                    $blueprint->index($index['columns'], $index['name']);
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes() as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($indexes as $index) {
                if (Schema::hasIndex($table, $index['name'])) {
                    Schema::table($table, function (Blueprint $blueprint) use ($index): void {
                        $blueprint->dropIndex($index['name']);
                    });
                }
            }
        }
    }
};
