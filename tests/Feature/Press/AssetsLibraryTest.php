<?php

namespace Tests\Feature\Press;

use App\Models\Asset;
use App\Models\BrandKit;
use App\Models\Deck;
use App\Models\Project;
use App\Models\Slide;
use App\Models\User;
use App\Services\Press\Assets\AssetUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AssetsLibraryTest extends TestCase
{
    use AssetsFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssets();
    }

    private function add(string $bytes, string $name): Asset
    {
        return Asset::findOrFail($this->upload($this->owner, $bytes, $name)->assertSuccessful()->json('asset.id'));
    }

    private function deckWith(string $title, array $els, mixed $bg = null, ?array $theme = null): array
    {
        $deck = Deck::create(['project_id' => $this->project->id, 'user_id' => $this->owner->id, 'title' => $title, 'sort_order' => 0, 'theme' => $theme]);
        $slide = Slide::create(['deck_id' => $deck->id, 'title' => 'One', 'sort_order' => 0, 'els' => $els, 'bg' => $bg]);

        return [$deck, $slide];
    }

    private function imageEl(int $assetId, string $id = 'e1'): array
    {
        return ['id' => $id, 'type' => 'image', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'src' => ['asset' => $assetId]];
    }

    // ---- listing -------------------------------------------------------------------------------------------

    public function test_the_library_lists_the_current_teams_workspace_by_default_with_the_full_payload(): void
    {
        $asset = $this->add($this->jpegBytes(80, 60), 'cover.jpg');

        $response = $this->actingAs($this->owner)->getJson('/api/assets')->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $asset->id)
            ->assertJsonPath('data.0.name', 'cover.jpg')
            ->assertJsonPath('data.0.kind', 'image')
            ->assertJsonPath('data.0.mime', 'image/jpeg')
            ->assertJsonPath('data.0.width', 80)
            ->assertJsonPath('data.0.height', 60)
            ->assertJsonPath('data.0.used_in', ['decks' => 0, 'slides' => 0, 'brand_kits' => 0])
            ->assertJsonPath('has_more', false)
            ->assertJsonStructure(['data' => [['id', 'url', 'thumb', 'web', 'size', 'alt', 'created_at']], 'next_cursor']);
        $this->assertStringContainsString('signature=', $response->json('data.0.url'));
    }

    public function test_an_empty_team_gets_an_empty_library_not_an_error(): void
    {
        $other = $this->outsider();

        $this->actingAs($other)->getJson('/api/assets')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_filtering_by_kind_search_and_sorting(): void
    {
        $this->add($this->jpegBytes(10, 10, 80), 'Beach photo.jpg');
        $this->add($this->pngBytes(11, 11), '100% logo_final.png');
        $this->add($this->mp4Bytes(), 'intro.mp4');
        $this->add($this->mp3Bytes(), 'theme.mp3');

        $names = fn (string $query) => array_column($this->actingAs($this->owner)->getJson('/api/assets?'.$query)->assertOk()->json('data'), 'name');

        $this->assertSame(['theme.mp3', 'intro.mp4', '100% logo_final.png', 'Beach photo.jpg'], $names(''));
        $this->assertSame(['100% logo_final.png', 'Beach photo.jpg'], $names('kind=image&sort=name'));
        $this->assertSame(['intro.mp4'], $names('kind=video'));
        $this->assertSame(['theme.mp3'], $names('kind=audio'));
        $this->assertSame(['Beach photo.jpg', '100% logo_final.png', 'intro.mp4', 'theme.mp3'], $names('sort=oldest'));
        $this->assertSame(['beach photo.jpg'], array_map('strtolower', $names('q=beach')));
        $this->assertSame(['100% logo_final.png'], $names('q='.urlencode('100%')), 'percent is a character, not a wildcard');
        $this->assertSame(['100% logo_final.png'], $names('q='.urlencode('logo_final')));
        $this->assertSame(['100% logo_final.png'], $names('q='.urlencode('%')), 'a lone percent sign matches only names that contain one');
        $this->assertSame([], $names('q='.urlencode('_')) === [] ? [] : array_diff($names('q='.urlencode('_')), ['100% logo_final.png']), 'an underscore is not a wildcard');
        $this->assertSame('mp4', pathinfo($names('sort=size&kind=video')[0], PATHINFO_EXTENSION));

        $this->actingAs($this->owner)->getJson('/api/assets?kind=pdf')->assertUnprocessable();
        $this->actingAs($this->owner)->getJson('/api/assets?sort=random')->assertUnprocessable();
        $this->actingAs($this->owner)->getJson('/api/assets?limit=500')->assertUnprocessable();
    }

    public function test_cursor_pagination_walks_every_asset_once(): void
    {
        foreach (range(1, 5) as $n) {
            $this->add($this->jpegBytes(10 + $n, 10), "img{$n}.jpg");
        }

        $seen = [];
        $url = '/api/assets?limit=2&sort=name';
        for ($page = 0; $page < 5 && $url; $page++) {
            $json = $this->actingAs($this->owner)->getJson($url)->assertOk()->json();
            $seen = array_merge($seen, array_column($json['data'], 'name'));
            $url = $json['next_cursor'] ? '/api/assets?limit=2&sort=name&cursor='.urlencode($json['next_cursor']) : null;
        }

        $this->assertSame(['img1.jpg', 'img2.jpg', 'img3.jpg', 'img4.jpg', 'img5.jpg'], $seen);
    }

    public function test_listing_another_project_needs_membership(): void
    {
        $this->add($this->jpegBytes(), 'a.jpg');
        $stranger = $this->outsider();

        $this->actingAs($stranger)->getJson('/api/assets?project_id='.$this->project->id)->assertNotFound();
        $this->actingAs($this->memberWithRole('viewer'))->getJson('/api/assets?project_id='.$this->project->id)->assertOk()->assertJsonCount(1, 'data');
    }

    // ---- editing -------------------------------------------------------------------------------------------

    public function test_name_and_alt_can_be_changed_by_an_editor_and_are_cleaned(): void
    {
        $asset = $this->add($this->pngBytes(), 'raw.png');
        $editor = $this->memberWithRole('editor');

        $this->actingAs($editor)->patchJson("/api/assets/{$asset->id}", ['name' => " Team/photo\x01.png ", 'alt' => 'Group photo on the stairs'])
            ->assertOk()
            ->assertJsonPath('asset.name', 'Teamphoto.png')
            ->assertJsonPath('asset.alt', 'Group photo on the stairs');

        $this->actingAs($editor)->patchJson("/api/assets/{$asset->id}", ['alt' => ''])->assertOk()->assertJsonPath('asset.alt', null);
        $this->actingAs($editor)->patchJson("/api/assets/{$asset->id}", ['name' => ''])->assertUnprocessable();
        $this->actingAs($editor)->patchJson("/api/assets/{$asset->id}", ['name' => '///'])->assertUnprocessable();
        $this->actingAs($editor)->patchJson("/api/assets/{$asset->id}", ['alt' => str_repeat('a', 241)])->assertUnprocessable();

        $this->assertSame('Teamphoto.png', $asset->fresh()->original_name);
    }

    public function test_editing_cannot_touch_storage_columns(): void
    {
        $asset = $this->add($this->pngBytes(), 'raw.png');
        $path = $asset->path;

        $this->actingAs($this->owner)->patchJson("/api/assets/{$asset->id}", ['name' => 'ok.png', 'path' => '../../.env', 'project_id' => 999, 'disk' => 's3', 'sha256' => 'x'])->assertOk();

        $fresh = $asset->fresh();
        $this->assertSame($path, $fresh->path);
        $this->assertSame($this->project->id, $fresh->project_id);
        $this->assertSame('local', $fresh->disk);
    }

    // ---- usage and deletion --------------------------------------------------------------------------------

    public function test_usage_finds_assets_in_elements_backgrounds_and_deck_themes(): void
    {
        $inEls = $this->add($this->jpegBytes(10, 10, 70), 'in-els.jpg');
        $inBg = $this->add($this->pngBytes(12, 12), 'in-bg.png');
        $inTheme = $this->add($this->pngBytes(13, 13), 'in-theme.png');
        $unused = $this->add($this->pngBytes(14, 14), 'unused.png');

        [$deckA] = $this->deckWith('Annual review', [$this->imageEl($inEls->id)], ['img' => ['asset' => $inBg->id], 'fit' => 'cover', 'dim' => 0.2]);
        $this->deckWith('Pitch', [$this->imageEl($inEls->id, 'a'), $this->imageEl($inEls->id, 'b')], null, ['id' => 'custom:1', 'bg' => ['img' => ['asset' => $inTheme->id]]]);

        $usage = $this->actingAs($this->owner)->getJson("/api/assets/{$inEls->id}/usage")->assertOk();
        $usage->assertJsonPath('in_use', true)->assertJsonPath('deck_count', 2)->assertJsonPath('slide_count', 2);
        $this->assertSame(['Annual review', 'Pitch'], array_column($usage->json('decks'), 'title'));

        $this->actingAs($this->owner)->getJson("/api/assets/{$inBg->id}/usage")->assertJsonPath('decks.0.id', $deckA->id);
        $this->actingAs($this->owner)->getJson("/api/assets/{$inTheme->id}/usage")->assertJsonPath('decks.0.in_theme', true);
        $this->actingAs($this->owner)->getJson("/api/assets/{$unused->id}/usage")->assertOk()->assertJsonPath('in_use', false)->assertJsonCount(0, 'decks');

        $listed = collect($this->actingAs($this->owner)->getJson('/api/assets')->json('data'))->keyBy('id');
        $this->assertSame(['decks' => 2, 'slides' => 2, 'brand_kits' => 0], $listed[$inEls->id]['used_in']);
        $this->assertSame(['decks' => 1, 'slides' => 1, 'brand_kits' => 0], $listed[$inBg->id]['used_in']);
        $this->assertSame(['decks' => 0, 'slides' => 0, 'brand_kits' => 0], $listed[$unused->id]['used_in']);
    }

    public function test_the_reference_scan_matches_whole_ids_only(): void
    {
        $this->assertSame([12], AssetUsage::idsIn('{"src":{"asset":12}}'));
        $this->assertSame([12, 123], AssetUsage::idsIn('[{"src":{"asset":12}},{"poster":{"asset": 123}}]'));
        $this->assertSame([7], AssetUsage::idsIn('{"asset":"7"}'));
        $this->assertSame([], AssetUsage::idsIn('{"assets":12,"asset_id":12,"note":"asset 12"}'));
        $this->assertSame([], AssetUsage::idsIn('{"src":{"url":"https://example.com/asset/12"}}'));
    }

    public function test_an_asset_with_a_similar_id_is_not_reported_as_used(): void
    {
        $a = $this->add($this->pngBytes(10, 10), 'a.png');
        $this->deckWith('Deck', [$this->imageEl($a->id * 10)]);

        $this->actingAs($this->owner)->getJson("/api/assets/{$a->id}/usage")->assertJsonPath('in_use', false);
    }

    public function test_deleting_a_used_asset_is_refused_with_the_decks_that_use_it(): void
    {
        $asset = $this->add($this->jpegBytes(), 'hero.jpg');
        $this->deckWith('Board pack', [$this->imageEl($asset->id)]);

        $response = $this->actingAs($this->owner)->deleteJson("/api/assets/{$asset->id}")->assertStatus(409);

        $response->assertJsonPath('decks.0.title', 'Board pack')->assertJsonPath('decks.0.slide_count', 1);
        $this->assertStringContainsString('1 deck', $response->json('message'));
        $this->assertDatabaseHas('assets', ['id' => $asset->id]);
        Storage::disk('local')->assertExists($asset->path);
    }

    public function test_only_a_team_admin_can_force_delete_a_used_asset(): void
    {
        $asset = $this->add($this->jpegBytes(), 'hero.jpg');
        $this->deckWith('Board pack', [$this->imageEl($asset->id)]);
        $editor = $this->memberWithRole('editor');
        $admin = $this->memberWithRole('admin');

        $this->actingAs($editor)->deleteJson("/api/assets/{$asset->id}?force=1")->assertForbidden();
        $this->assertDatabaseHas('assets', ['id' => $asset->id]);

        $this->actingAs($admin)->deleteJson("/api/assets/{$asset->id}?force=1")->assertNoContent();
        $this->assertDatabaseMissing('assets', ['id' => $asset->id]);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_deleting_an_unused_asset_removes_the_row_and_every_variant(): void
    {
        $asset = $this->add($this->jpegBytes(900, 700), 'old.jpg');
        $this->assertCount(3, Storage::disk('local')->allFiles());

        $this->actingAs($this->memberWithRole('editor'))->deleteJson("/api/assets/{$asset->id}")->assertNoContent();

        $this->assertDatabaseCount('assets', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_video_poster_counts_as_use_of_the_video(): void
    {
        $video = $this->add($this->mp4Bytes(), 'clip.mp4');
        $poster = Asset::findOrFail($this->actingAs($this->owner)->post("/api/assets/{$video->id}/poster", [
            'file' => UploadedFile::fake()->createWithContent('f.jpg', $this->jpegBytes(64, 36)),
        ], ['Accept' => 'application/json'])->assertCreated()->json('poster.id'));

        $this->deckWith('Reel', [['id' => 'v', 'type' => 'video', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'src' => ['url' => 'https://x.test/a.mp4'], 'poster' => ['asset' => $poster->id]]]);

        $this->actingAs($this->owner)->deleteJson("/api/assets/{$video->id}")->assertStatus(409);
        $this->actingAs($this->owner)->deleteJson("/api/assets/{$poster->id}")->assertStatus(409);
    }

    public function test_a_brand_kit_logo_blocks_deletion(): void
    {
        $logo = $this->add($this->pngBytes(30, 30, true), 'logo.png');
        $kit = BrandKit::create(['team_id' => $this->owner->currentTeam->id, 'name' => 'Acme', 'tokens' => ['palette' => [], 'fonts' => [], 'logos' => [$logo->id]], 'is_default' => true]);

        $response = $this->actingAs($this->owner)->deleteJson("/api/assets/{$logo->id}")->assertStatus(409);

        $response->assertJsonPath('brand_kits.0.id', $kit->id);
        $this->actingAs($this->owner)->getJson("/api/assets/{$logo->id}")->assertJsonPath('asset.used_in.brand_kits', 1);
    }

    public function test_deck_titles_the_viewer_cannot_open_are_hidden(): void
    {
        $asset = $this->add($this->jpegBytes(), 'hero.jpg');
        $this->deckWith('Visible', [$this->imageEl($asset->id)]);

        $blind = new class extends User
        {
            public function can($abilities, $arguments = []): bool
            {
                return false;
            }
        };

        $usage = app(AssetUsage::class)->forAsset($asset, $blind);

        $this->assertSame('A deck you cannot open', $usage['decks'][0]['title']);
        $this->assertTrue($usage['in_use']);
        $this->assertSame('Visible', app(AssetUsage::class)->forAsset($asset, $this->owner)['decks'][0]['title']);
    }

    // ---- garbage collection --------------------------------------------------------------------------------

    public function test_gc_reports_with_dry_run_and_removes_orphans_and_dead_rows_otherwise(): void
    {
        $kept = $this->add($this->jpegBytes(300, 200), 'kept.jpg');
        $dead = $this->add($this->pngBytes(20, 20), 'dead.png');
        $deadVideo = $this->add($this->mp4Bytes(), 'dead.mp4');

        Storage::disk('local')->delete($dead->path);
        Storage::disk('local')->delete($deadVideo->path);
        Storage::disk('local')->put("projects/{$this->project->id}/assets/orphan.jpg", 'left behind');
        Storage::disk('local')->put('projects/99/assets/orphan2.png', 'left behind');
        Storage::disk('local')->put('other/folder/keep.txt', 'not ours');

        $this->artisan('press:assets-gc', ['--dry-run' => true, '--grace' => 0])
            ->expectsOutputToContain('2 orphan file(s), 2 row(s) with a missing file')
            ->assertSuccessful();

        $this->assertDatabaseCount('assets', 3);
        Storage::disk('local')->assertExists("projects/{$this->project->id}/assets/orphan.jpg");

        $this->artisan('press:assets-gc', ['--grace' => 0])->assertSuccessful();

        $this->assertDatabaseCount('assets', 1);
        $this->assertDatabaseHas('assets', ['id' => $kept->id]);
        Storage::disk('local')->assertMissing("projects/{$this->project->id}/assets/orphan.jpg");
        Storage::disk('local')->assertMissing('projects/99/assets/orphan2.png');
        Storage::disk('local')->assertExists('other/folder/keep.txt');
        Storage::disk('local')->assertExists($kept->path);
        Storage::disk('local')->assertExists($kept->thumb_path);
        $this->assertDatabaseMissing('assets', ['id' => $dead->id]);
    }

    public function test_gc_leaves_recent_files_alone_by_default(): void
    {
        Storage::disk('local')->put("projects/{$this->project->id}/assets/fresh.jpg", 'just written');

        Artisan::call('press:assets-gc');

        Storage::disk('local')->assertExists("projects/{$this->project->id}/assets/fresh.jpg");
    }

    public function test_gc_is_scheduled_daily(): void
    {
        $events = collect(Schedule::events())->filter(fn ($event) => str_contains((string) $event->command, 'press:assets-gc'));

        $this->assertCount(1, $events);
        $this->assertSame('25 3 * * *', $events->first()->expression);
    }

    public function test_a_project_without_a_workspace_name_falls_back_to_the_teams_first_project(): void
    {
        $this->project->update(['name' => 'Renamed']);
        $this->add($this->pngBytes(), 'a.png');

        $this->assertDatabaseHas('assets', ['project_id' => $this->project->id]);
        $this->actingAs($this->owner)->getJson('/api/assets')->assertJsonCount(1, 'data');
    }

    public function test_uploading_without_a_project_creates_the_workspace_for_a_member_who_can(): void
    {
        $user = $this->outsider();

        $response = $this->actingAs($user)->post('/api/assets', ['file' => UploadedFile::fake()->createWithContent('a.png', $this->pngBytes())], ['Accept' => 'application/json'])->assertCreated();

        $project = Project::findOrFail($response->json('asset.project_id'));
        $this->assertSame($user->currentTeam->id, $project->team_id);
        $this->assertSame('Dot.Press Workspace', $project->name);
    }
}
