<?php

namespace Tests\Feature\Press;

use App\Models\DeckActivity;
use App\Models\DeckChange;
use App\Models\DeckVersion;
use App\Models\Slide;
use App\Services\Press\DeckHash;
use App\Services\Press\VersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class VersionsTest extends TestCase
{
    use MakesDecks;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function edit($user, $deck, string $id, string $key): void
    {
        $this->ops($user, $deck, [['op' => 'el.add', 'slide' => $deck->slides()->first()->id, 'el' => $this->shape($id)]], $key)->assertOk();
    }

    public function test_an_auto_version_is_stored_after_a_change_when_none_is_newer_than_20_minutes(): void
    {
        Carbon::setTestNow('2026-11-08 10:00:00');
        $user = $this->owner();
        $deck = $this->deckFor($user);

        $this->edit($user, $deck, 'a', 'k1');
        $this->assertSame(1, DeckVersion::where('deck_id', $deck->id)->count());
        $first = DeckVersion::first();
        $this->assertSame('auto', $first->kind);
        $this->assertSame($user->id, $first->user_id);
        $this->assertSame(1, json_decode($first->snapshot, true)['slides'][0]['els'][0] ? 1 : 0);

        Carbon::setTestNow('2026-11-08 10:19:00');
        $this->edit($user, $deck, 'b', 'k2');
        $this->assertSame(1, DeckVersion::where('deck_id', $deck->id)->count());

        Carbon::setTestNow('2026-11-08 10:21:00');
        $this->edit($user, $deck, 'c', 'k3');
        $this->assertSame(2, DeckVersion::where('deck_id', $deck->id)->count());
        $latest = DeckVersion::orderByDesc('id')->first();
        $this->assertCount(3, json_decode($latest->snapshot, true)['slides'][0]['els']);
    }

    public function test_no_auto_version_when_nothing_differs_from_the_last_one(): void
    {
        Carbon::setTestNow('2026-11-08 10:00:00');
        $user = $this->owner();
        $deck = $this->deckFor($user);
        $this->edit($user, $deck, 'a', 'k1');

        Carbon::setTestNow('2026-11-08 12:00:00');
        // A batch that changes nothing (the only op is skipped) stores nothing.
        $this->ops($user, $deck, [['op' => 'el.remove', 'slide' => $deck->slides()->first()->id, 'id' => 'ghost']]);
        $this->assertSame(1, DeckVersion::where('deck_id', $deck->id)->count());
    }

    public function test_manual_versions_are_listed_newest_first_with_author_counts_and_size(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user, 'D', 3);

        $a = $this->actingAs($user)->postJson("/api/decks/{$deck->id}/versions", ['label' => 'Before the board meeting'])->assertCreated()
            ->assertJsonPath('kind', 'manual')->assertJsonPath('slide_count', 3)->assertJsonPath('author.id', $user->id)->json();
        $this->actingAs($user)->postJson("/api/decks/{$deck->id}/versions", ['label' => 'Second'])->assertCreated();

        $list = $this->actingAs($user)->getJson("/api/decks/{$deck->id}/versions")->assertOk()->json('versions');
        $this->assertSame(['Second', 'Before the board meeting'], array_column($list, 'label'));
        $this->assertGreaterThan(100, $list[0]['size']);
        $this->assertArrayNotHasKey('snapshot', $list[0]);

        $shown = $this->actingAs($user)->getJson("/api/decks/{$deck->id}/versions/{$a['id']}")->assertOk()->json();
        $this->assertCount(3, $shown['snapshot']['slides']);
        $this->assertTrue(DeckActivity::where('deck_id', $deck->id)->where('event', 'version.saved')->exists());
    }

    public function test_restore_brings_back_deleted_slides_under_their_ids_and_removes_new_ones(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user, 'Original', 3);
        [$one, $two, $three] = $deck->slides()->get()->all();
        $this->ops($user, $deck, [['op' => 'el.add', 'slide' => $two->id, 'el' => $this->shape('keep')]]);
        $version = $this->actingAs($user)->postJson("/api/decks/{$deck->id}/versions", ['label' => 'Good'])->json('id');

        $this->ops($user, $deck, [
            ['op' => 'slide.remove', 'id' => $two->id],
            ['op' => 'slide.add', 'tid' => 'n', 'after' => $one->id, 'slide' => ['title' => 'Newer']],
            ['op' => 'el.add', 'slide' => $one->id, 'el' => $this->shape('late')],
            ['op' => 'deck.set', 'title' => 'Renamed'],
        ])->assertOk();
        $this->assertSame(3, $deck->slides()->count());

        $result = $this->actingAs($user)->postJson("/api/decks/{$deck->id}/versions/{$version}/restore")->assertOk()->json();

        $this->assertSame([$one->id, $two->id, $three->id], $deck->slides()->pluck('id')->all());
        $this->assertSame(['keep'], array_column($this->els(Slide::find($two->id)), 'id'));
        $this->assertSame([], $this->els($one));
        $this->assertSame('Original', $deck->fresh()->title);
        $this->assertDatabaseMissing('slides', ['title' => 'Newer']);

        // A safety copy of the state before the restore exists and can itself be restored.
        $safety = DeckVersion::find($result['safety_version']);
        $this->assertSame('restore', $safety->kind);
        $this->assertSame('Renamed', json_decode($safety->snapshot, true)['deck']['title']);

        $change = DeckChange::find($result['seq']);
        $this->assertSame([['op' => 'deck.reload']], $change->ops);
        $this->assertSame($result['deck_rev'], $deck->fresh()->rev);
        $this->assertTrue(DeckActivity::where('deck_id', $deck->id)->where('event', 'version.restored')->exists());

        $this->actingAs($user)->postJson("/api/decks/{$deck->id}/versions/{$safety->id}/restore")->assertOk();
        $this->assertSame('Renamed', $deck->fresh()->title);
        $this->assertSame(3, $deck->slides()->count());
    }

    public function test_pruning_keeps_manual_and_approved_versions_and_the_40_newest_autos(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user);
        $versions = app(VersionService::class);

        $versions->create($deck, $user, 'manual', 'keep me');
        $versions->create($deck, $user, 'approved', 'Approved');
        for ($i = 0; $i < 45; $i++) {
            $versions->create($deck, $user, 'auto');
        }

        $this->assertSame(40, DeckVersion::where('deck_id', $deck->id)->where('kind', 'auto')->count());
        $this->assertSame(1, DeckVersion::where('deck_id', $deck->id)->where('kind', 'manual')->count());
        $this->assertSame(1, DeckVersion::where('deck_id', $deck->id)->where('kind', 'approved')->count());
        $oldestKept = DeckVersion::where('kind', 'auto')->min('id');
        $this->assertSame(DeckVersion::where('kind', 'auto')->max('id') - 39, $oldestKept);
    }

    public function test_the_static_snapshot_entry_point_other_services_use_works(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user);

        $version = VersionService::snapshot($deck, $user, 'approved', 'Approved');

        $this->assertSame('approved', $version->kind);
        $this->assertSame(DeckHash::compute($deck), $version->hash);
    }

    public function test_versions_belong_to_their_deck_and_team(): void
    {
        $user = $this->owner();
        $stranger = $this->owner();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor($user, 'Other');
        $strangerDeck = $this->deckFor($stranger);
        $version = VersionService::snapshot($deck, $user, 'manual', 'x');

        // A version id never opens through a different deck.
        $this->actingAs($user)->getJson("/api/decks/{$otherDeck->id}/versions/{$version->id}")->assertNotFound();
        $this->actingAs($user)->postJson("/api/decks/{$otherDeck->id}/versions/{$version->id}/restore")->assertNotFound();
        // Another team sees none of it.
        $this->actingAs($stranger)->getJson("/api/decks/{$deck->id}/versions")->assertNotFound();
        $this->actingAs($stranger)->getJson("/api/decks/{$deck->id}/versions/{$version->id}")->assertNotFound();
        $this->actingAs($stranger)->postJson("/api/decks/{$deck->id}/versions", ['label' => 'x'])->assertNotFound();
        $this->actingAs($stranger)->postJson("/api/decks/{$strangerDeck->id}/versions/{$version->id}/restore")->assertNotFound();
    }

    public function test_a_viewer_can_read_versions_but_not_create_or_restore(): void
    {
        $owner = $this->owner();
        $viewer = $this->teammate($owner, 'viewer');
        $deck = $this->deckFor($owner);
        $version = VersionService::snapshot($deck, $owner, 'manual', 'x');

        $this->actingAs($viewer)->getJson("/api/decks/{$deck->id}/versions")->assertOk();
        $this->actingAs($viewer)->getJson("/api/decks/{$deck->id}/versions/{$version->id}")->assertOk();
        $this->actingAs($viewer)->postJson("/api/decks/{$deck->id}/versions", ['label' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->postJson("/api/decks/{$deck->id}/versions/{$version->id}/restore")->assertForbidden();
    }
}
