<?php

namespace Tests\Feature\Press\Performance;

use App\Models\DeckVersion;
use App\Models\Project;
use App\Services\Press\Bench\DeckGenerator;
use App\Services\Press\VersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Press\MakesDecks;
use Tests\TestCase;

class VersionPackingTest extends TestCase
{
    use MakesDecks;
    use RefreshDatabase;

    private function bigDeck($user, int $slides = 12)
    {
        $project = Project::firstOrCreate(['team_id' => $user->currentTeam->id, 'name' => 'Board'], ['user_id' => $user->id, 'slug' => 'board-x']);

        return (new DeckGenerator([1]))->createDeck($project, $user, 'Big', $slides);
    }

    public function test_a_snapshot_is_stored_packed_and_read_back_as_the_same_json(): void
    {
        $user = $this->owner();
        $deck = $this->bigDeck($user);

        $version = app(VersionService::class)->create($deck, $user, 'manual', 'Checkpoint');

        $stored = (string) DB::table('deck_versions')->where('id', $version->id)->value('snapshot');
        $this->assertStringStartsWith(DeckVersion::PACKED, $stored);
        $this->assertLessThan($version->size * 0.4, strlen($stored), 'packing saves more than 60 percent');

        $json = DeckVersion::find($version->id)->snapshot;
        $this->assertStringStartsWith('{"deck":', $json);
        $this->assertSame($version->size, strlen($json), '`size` is the length of the JSON text, packed or not');
        $this->assertCount(12, json_decode($json, true)['slides']);
    }

    public function test_the_version_api_and_a_restore_work_on_packed_snapshots(): void
    {
        $user = $this->owner();
        $deck = $this->bigDeck($user, 6);
        $created = $this->actingAs($user)->postJson("/api/decks/{$deck->id}/versions", ['label' => 'Before'])->assertCreated();
        $id = $created->json('id') ?? $created->json('version.id');
        $this->ops($user, $deck, [['op' => 'deck.set', 'title' => 'Renamed']])->assertOk();

        $shown = $this->actingAs($user)->getJson("/api/decks/{$deck->id}/versions/{$id}")->assertOk();
        $this->assertSame('Big', $shown->json('snapshot.deck.title'));

        $this->actingAs($user)->postJson("/api/decks/{$deck->id}/versions/{$id}/restore")->assertOk();
        $this->assertSame('Big', $deck->fresh()->title);
        $this->assertSame(6, $deck->slides()->count());
    }

    public function test_plain_snapshots_written_before_packing_still_read_and_the_command_packs_them(): void
    {
        $user = $this->owner();
        $deck = $this->bigDeck($user, 8);
        $version = app(VersionService::class)->create($deck, $user, 'manual');
        $json = DeckVersion::find($version->id)->snapshot;
        DB::table('deck_versions')->where('id', $version->id)->update(['snapshot' => $json]); // an old row: plain JSON

        $this->assertSame($json, DeckVersion::find($version->id)->snapshot);

        $this->artisan('press:versions-compress')->assertSuccessful();

        $this->assertStringStartsWith(DeckVersion::PACKED, (string) DB::table('deck_versions')->where('id', $version->id)->value('snapshot'));
        $this->assertSame($json, DeckVersion::find($version->id)->snapshot);
        // Run again: nothing left to do, nothing broken.
        $this->artisan('press:versions-compress')->assertSuccessful();
        $this->assertSame($json, DeckVersion::find($version->id)->snapshot);
    }

    public function test_a_small_snapshot_stays_plain_and_packing_can_be_switched_off(): void
    {
        $this->assertSame('{"a":1}', DeckVersion::pack('{"a":1}'));
        config(['press_perf.version_compress' => false]);
        $big = json_encode(['k' => str_repeat('abc', 10000)]);
        $this->assertSame($big, DeckVersion::pack($big));
        config(['press_perf.version_compress' => true]);
        $this->assertStringStartsWith(DeckVersion::PACKED, (string) DeckVersion::pack($big));
        $this->assertSame($big, DeckVersion::unpack(DeckVersion::pack($big)));
        $this->assertNull(DeckVersion::unpack(null));
    }

    public function test_taking_a_snapshot_does_not_decode_the_slides(): void
    {
        $user = $this->owner();
        $deck = $this->bigDeck($user, 20);

        memory_reset_peak_usage();
        $before = memory_get_usage();
        app(VersionService::class)->create($deck, $user, 'manual');
        $used = (memory_get_peak_usage() - $before) / 1048576;

        // 20 dense slides are about 270 KB of JSON; as PHP arrays they were 3 to 4 MB, plus the models.
        $this->assertLessThan(3.0, $used, 'peak memory of a snapshot, MB');
    }
}
