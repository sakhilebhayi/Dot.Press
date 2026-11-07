<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AssetStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_upload_and_fetch_signed_delivery_url(): void
    {
        config()->set('filesystems.asset_upload_disk', 'local');
        Storage::fake('local');

        $user = User::factory()->withPersonalTeam()->create();
        $project = Project::create([
            'user_id' => $user->id,
            'team_id' => $user->currentTeam->id,
            'name' => 'DotPress',
            'slug' => 'dotpress',
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/assets', [
                'project_id' => $project->id,
                'file' => UploadedFile::fake()->image('slide.png', 64, 48),
            ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'asset' => ['id', 'project_id', 'kind', 'url', 'thumb', 'web'],
                'delivery_url',
                'expires_in_seconds',
            ]);

        $stored = Asset::findOrFail($response->json('asset.id'));
        Storage::disk('local')->assertExists($stored->path);
        $this->assertSame(64, $stored->width);
        $this->assertArrayNotHasKey('path', $response->json('asset'), 'storage paths are not exposed');

        $showResponse = $this->actingAs($user)
            ->getJson('/api/assets/'.$response->json('asset.id'));

        $showResponse->assertOk()->assertJsonStructure([
            'asset',
            'delivery_url',
            'expires_in_seconds',
        ]);

        $deliveryUrl = $showResponse->json('delivery_url');
        $path = parse_url($deliveryUrl, PHP_URL_PATH) ?? '';
        $query = parse_url($deliveryUrl, PHP_URL_QUERY);
        $relativeUrl = $path.($query ? '?'.$query : '');

        $downloadResponse = $this->actingAs($user)->get($relativeUrl);

        $downloadResponse->assertOk();
    }

    public function test_user_cannot_access_asset_from_another_users_project(): void
    {
        config()->set('filesystems.asset_upload_disk', 'local');
        Storage::fake('local');

        $owner = User::factory()->withPersonalTeam()->create();
        $intruder = User::factory()->withPersonalTeam()->create();

        $project = Project::create([
            'user_id' => $owner->id,
            'team_id' => $owner->currentTeam->id,
            'name' => 'Owner Project',
            'slug' => 'owner-project',
        ]);

        $upload = $this->actingAs($owner)
            ->postJson('/api/assets', [
                'project_id' => $project->id,
                'file' => UploadedFile::fake()->image('deck.png', 32, 32),
            ]);

        $assetId = $upload->json('asset.id');

        // Asset route-model binding now resolves through the
        // HasUserScopeThroughOwner global scope, so an intruder's query
        // finds no row at all -- Laravel throws a 404 before
        // AssetController::show's explicit authorize() call ever runs.
        // This is a real improvement over the old 403 (the route no
        // longer confirms the resource exists to non-owners).
        $this->actingAs($intruder)
            ->getJson('/api/assets/'.$assetId)
            ->assertNotFound();
    }
}
