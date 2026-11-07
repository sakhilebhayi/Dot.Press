<?php

namespace Tests\Feature\Press\Performance;

use App\Console\Commands\PruneDeckChanges;
use App\Models\DeckChange;
use App\Models\DeckExport;
use App\Services\Press\DocumentSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Press\MakesDecks;
use Tests\TestCase;

class ResponseAndStorageTest extends TestCase
{
    use MakesDecks;
    use RefreshDatabase;

    public function test_big_json_answers_are_gzipped_for_browsers_that_accept_it_and_small_ones_are_not(): void
    {
        $user = $this->owner();
        $big = '/api/layout-library';

        $plain = $this->actingAs($user)->getJson($big)->assertOk();
        $this->assertNull($plain->headers->get('Content-Encoding'));
        $this->assertGreaterThan(8000, strlen($plain->getContent()), 'the layout library is a large answer');

        $gz = $this->actingAs($user)->withHeaders(['Accept-Encoding' => 'gzip'])->getJson($big);
        $this->assertSame('gzip', $gz->headers->get('Content-Encoding'));
        $this->assertStringContainsString('Accept-Encoding', (string) $gz->headers->get('Vary'));
        $this->assertSame($plain->getContent(), gzdecode($gz->getContent()));
        $this->assertLessThan(strlen($plain->getContent()) / 3, strlen($gz->getContent()));
        $this->assertSame((string) strlen($gz->getContent()), $gz->headers->get('Content-Length'));

        $deck = $this->deckFor($user);
        $small = $this->actingAs($user)->withHeaders(['Accept-Encoding' => 'gzip'])->getJson("/api/decks/{$deck->id}");
        $this->assertNull($small->headers->get('Content-Encoding'), 'a short answer is not worth compressing');

        config(['press_perf.compress' => false]);
        $this->assertNull($this->actingAs($user)->withHeaders(['Accept-Encoding' => 'gzip'])->getJson($big)->headers->get('Content-Encoding'));
    }

    public function test_the_indexes_that_sqlite_does_not_make_for_foreign_keys_exist(): void
    {
        foreach ([
            'slides' => 'slides_deck_order_index', 'deck_changes' => 'deck_changes_deck_id_id_index', 'decks' => 'decks_project_archived_index',
            'assets' => 'assets_project_kind_id_index', 'deck_versions' => 'deck_versions_deck_kind_id_index', 'deck_comments' => 'deck_comments_slide_id_index',
            'projects' => 'projects_team_id_index', 'deck_shares' => 'deck_shares_user_id_deck_id_index', 'team_user' => 'team_user_user_id_index',
        ] as $table => $index) {
            $this->assertTrue(Schema::hasIndex($table, $index), "{$table}.{$index}");
        }
        // And the planner uses them for the two lookups that ran on every request.
        $deck = $this->deckFor($this->owner());
        $plan = collect(DB::select('explain query plan select * from slides where deck_id = ? order by sort_order, id', [$deck->id]))->pluck('detail')->implode(' ');
        $this->assertStringContainsString('slides_deck_order_index', $plan);
        $this->assertStringNotContainsString('USE TEMP B-TREE', $plan);
        $plan = collect(DB::select('explain query plan select max(id) from deck_changes where deck_id = ?', [$deck->id]))->pluck('detail')->implode(' ');
        $this->assertStringContainsString('deck_changes_deck_id_id_index', $plan);
    }

    public function test_the_indexes_migration_can_be_undone_and_run_again(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 2])->assertSuccessful();
        $this->assertFalse(Schema::hasIndex('slides', 'slides_deck_order_index'));
        $this->assertFalse(Schema::hasColumn('decks', 'content_hash'));
        $this->artisan('migrate')->assertSuccessful();
        $this->assertTrue(Schema::hasIndex('slides', 'slides_deck_order_index'));
        $this->assertTrue(Schema::hasColumn('decks', 'preview_rev'));
    }

    public function test_pruning_the_change_log_deletes_in_slices_and_leaves_a_tombstone(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user);
        $old = now()->subDays(30);
        $rows = [];
        for ($i = 0; $i < 5300; $i++) {
            $rows[] = ['deck_id' => $deck->id, 'user_id' => $user->id, 'client' => 'c', 'key' => "k{$i}", 'ops' => '[{"op":"deck.set","title":"t"}]', 'created_at' => $i < 5200 ? $old : now()];
            if (count($rows) === 500) {
                DB::table('deck_changes')->insert($rows);
                $rows = [];
            }
        }
        DB::table('deck_changes')->insert($rows);

        $this->artisan('press:prune-changes')->assertSuccessful();

        $this->assertSame(101, DeckChange::where('deck_id', $deck->id)->count(), '100 recent rows and the tombstone');
        $this->assertSame(1, DeckChange::where('key', PruneDeckChanges::TOMBSTONE_KEY)->count());
    }

    public function test_pruning_exports_marks_expired_rows_in_bulk_and_removes_their_files(): void
    {
        Storage::fake('local');
        $user = $this->owner();
        $deck = $this->deckFor($user);
        $ids = [];
        for ($i = 0; $i < 30; $i++) {
            Storage::disk('local')->put("press/exports/x{$i}.pdf", 'pdf');
            $ids[] = DeckExport::create(['deck_id' => $deck->id, 'user_id' => $user->id, 'format' => 'pdf', 'status' => 'done', 'disk' => 'local', 'path' => "press/exports/x{$i}.pdf", 'size' => 3, 'expires_at' => now()->subHour()])->id;
        }
        $keep = DeckExport::create(['deck_id' => $deck->id, 'user_id' => $user->id, 'format' => 'pdf', 'status' => 'done', 'disk' => 'local', 'path' => 'press/exports/keep.pdf', 'size' => 3, 'expires_at' => now()->addHour()]);
        Storage::disk('local')->put('press/exports/keep.pdf', 'pdf');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->artisan('press:exports-prune')->assertSuccessful();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(30, DeckExport::whereIn('id', $ids)->where('status', 'expired')->whereNull('path')->count());
        Storage::disk('local')->assertMissing('press/exports/x0.pdf');
        Storage::disk('local')->assertExists('press/exports/keep.pdf');
        $this->assertSame('done', $keep->fresh()->status);
        $this->assertLessThan(20, $queries, 'one update for the batch, not one per row');
    }

    public function test_uploads_and_the_other_routes_that_had_no_limit_now_have_one(): void
    {
        $user = $this->owner();

        // An upload is half a second of CPU for a big photograph: 30 a minute, the 31st is refused. (An empty request
        // is refused by validation but still counts against the limit.)
        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($user)->postJson('/api/assets', [])->assertStatus(422);
        }
        $this->actingAs($user)->postJson('/api/assets', [])->assertStatus(429);

        // The library and the picker have generous allowances, and report the limit in the usual headers.
        $response = $this->actingAs($user)->getJson('/api/decks')->assertOk();
        $this->assertSame('180', $response->headers->get('X-RateLimit-Limit'));
        $this->assertSame('300', $this->actingAs($user)->getJson('/api/assets')->headers->get('X-RateLimit-Limit'));
    }

    public function test_the_operations_request_limit_follows_the_memory_limit(): void
    {
        $mb = 1048576;
        $this->assertSame(8 * $mb, DocumentSanitizer::requestBudget(-1), 'no limit: the 8 MB of the spec');
        $this->assertSame(8 * $mb, DocumentSanitizer::requestBudget(2048 * $mb));
        $this->assertSame(2 * $mb, DocumentSanitizer::requestBudget(128 * $mb), 'the usual shared host: 2 MB, above the 1.5 MB the editor sends');
        $this->assertSame(512 * 1024, DocumentSanitizer::requestBudget(8 * $mb), 'never below 512 KB');
    }

    public function test_a_version_with_its_snapshot_is_streamed_as_the_stored_json(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user, 'Deck', 3);
        $created = $this->actingAs($user)->postJson("/api/decks/{$deck->id}/versions", ['label' => 'Keep'])->assertCreated();
        $id = $created->json('id');

        $shown = $this->actingAs($user)->get("/api/decks/{$deck->id}/versions/{$id}", ['Accept' => 'application/json'])->assertOk();
        $body = json_decode($shown->streamedContent(), true);

        $this->assertSame('Keep', $body['label']);
        $this->assertSame(3, $body['slide_count']);
        $this->assertCount(3, $body['snapshot']['slides']);
        $this->assertSame($deck->title, $body['snapshot']['deck']['title']);
    }
}
