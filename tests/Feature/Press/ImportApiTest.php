<?php

namespace Tests\Feature\Press;

use App\Models\Deck;
use App\Models\Project;
use App\Models\Slide;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['press_export.font_dir' => storage_path('framework/testing/press-fonts')]);
    }

    private function upload(string $fixture = 'python_pptx_basic.pptx', ?string $name = null): UploadedFile
    {
        $path = __DIR__.'/../../Fixtures/Press/'.$fixture;

        return UploadedFile::fake()->createWithContent($name ?? $fixture, (string) file_get_contents($path));
    }

    public function test_an_upload_creates_a_deck_and_slides_in_the_users_workspace(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $res = $this->actingAs($user)->post('/api/imports/pptx', ['file' => $this->upload('python_pptx_basic.pptx', 'Board pack.pptx')], ['Accept' => 'application/json'])->assertCreated();

        $deck = Deck::findOrFail($res->json('deck.id'));
        $this->assertSame('Board pack', $deck->title);
        $this->assertSame($user->id, $deck->user_id);
        $this->assertSame($user->currentTeam->id, $deck->project->team_id);
        $this->assertSame('Dot.Press Workspace', $deck->project->name);
        $this->assertSame(1280, $deck->w);
        $this->assertSame(720, $deck->h);
        $this->assertSame('draft', $deck->status);
        $this->assertSame('private', $deck->visibility);
        $this->assertSame(5, $res->json('deck.slides'));
        $slides = $deck->slides()->orderBy('sort_order')->get();
        $this->assertCount(5, $slides);
        $this->assertTrue($slides[4]->hidden);
        $this->assertSame('Speaker note for slide one', $slides[0]->notes);
        $this->assertSame('fade', $slides[0]->transition['type']);
        $this->assertSame('Placeholder inheritance', $slides[0]->title);
        $this->assertSame('title', $slides[0]->els[0]['role']);
        $this->assertSame('import', $slides[0]->els[0]['origin']);
        $this->assertNotEmpty($res->json('report'));
        $this->assertStringContainsString('/editor/decks/'.$deck->id.'/slides/'.$slides[0]->id, $res->json('deck.editor_url'));
        $this->assertDatabaseHas('deck_activity', ['deck_id' => $deck->id, 'event' => 'imported']);
    }

    public function test_slide_links_point_at_the_new_slide_ids(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $res = $this->actingAs($user)->post('/api/imports/pptx', ['file' => $this->upload()], ['Accept' => 'application/json'])->assertCreated();

        $slides = Deck::findOrFail($res->json('deck.id'))->slides()->orderBy('sort_order')->get();
        $oval = collect($slides[2]->els)->firstWhere('kind', 'ellipse');
        $this->assertSame('#slide:'.$slides[0]->id, $oval['link']);
    }

    public function test_pictures_are_stored_through_the_asset_pipeline_or_reported_as_skipped(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $res = $this->actingAs($user)->post('/api/imports/pptx', ['file' => $this->upload()], ['Accept' => 'application/json'])->assertCreated();

        $slide = Deck::findOrFail($res->json('deck.id'))->slides()->orderBy('sort_order')->get()[2];
        $images = array_values(array_filter($slide->els, fn ($e) => $e['type'] === 'image'));
        if (class_exists('App\\Services\\Press\\Assets\\AssetStore')) {
            $this->assertCount(1, $images);
            $this->assertIsInt($images[0]['src']['asset']);
        } else {
            $this->assertSame([], $images);
            $this->assertStringContainsString('asset storage is not available', json_encode($res->json('report')));
        }
    }

    public function test_rejected_files_get_a_readable_message(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)->postJson('/api/imports/pptx', ['file' => UploadedFile::fake()->createWithContent('x.pptx', 'not a zip at all')])
            ->assertStatus(422)->assertJsonPath('message', 'This is not a PowerPoint (.pptx) file.');
        $this->actingAs($user)->postJson('/api/imports/pptx', ['file' => UploadedFile::fake()->createWithContent('x.docx', 'PK')])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->actingAs($user)->postJson('/api/imports/pptx', [])->assertStatus(422);
        $this->actingAs($user)->postJson('/api/imports/pptx', ['file' => UploadedFile::fake()->create('big.pptx', 70000)])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->assertSame(0, Deck::withoutGlobalScopes()->count());
    }

    public function test_a_viewer_cannot_import(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $viewer = User::factory()->create();
        $owner->currentTeam->users()->attach($viewer, ['role' => 'viewer']);
        $viewer->forceFill(['current_team_id' => $owner->currentTeam->id])->save();

        $this->actingAs($viewer)->post('/api/imports/pptx', ['file' => $this->upload()], ['Accept' => 'application/json'])->assertForbidden();
        $this->assertSame(0, Deck::withoutGlobalScopes()->count());
    }

    public function test_importing_twice_reuses_the_workspace_project(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user)->post('/api/imports/pptx', ['file' => $this->upload()], ['Accept' => 'application/json'])->assertCreated();
        $this->actingAs($user)->post('/api/imports/pptx', ['file' => $this->upload()], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame(1, Project::withoutGlobalScopes()->where('team_id', $user->currentTeam->id)->count());
        $this->assertSame(2, Deck::count());
        $this->assertSame(10, Slide::count());
    }

    public function test_the_import_endpoint_is_rate_limited(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $codes = [];
        for ($i = 0; $i < 6; $i++) {
            $codes[] = $this->actingAs($user)->postJson('/api/imports/pptx', ['file' => UploadedFile::fake()->createWithContent('x.pptx', 'nope')])->getStatusCode();
        }

        $this->assertContains(429, $codes);
    }

    public function test_an_exported_deck_can_be_imported_back_as_a_new_deck(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $project = Project::create(['user_id' => $user->id, 'team_id' => $user->currentTeam->id, 'name' => 'P', 'slug' => 'p']);
        $deck = Deck::create(['project_id' => $project->id, 'user_id' => $user->id, 'title' => 'Round', 'theme' => null, 'sort_order' => 0, 'w' => 1920, 'h' => 1080]);
        $deck->slides()->create(['title' => 'One', 'layout' => 'blank', 'sort_order' => 0, 'els' => [['id' => 't', 'type' => 'text', 'x' => 10, 'y' => 10, 'w' => 600, 'h' => 100, 'size' => 40, 'doc' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello again']]]]]]]]);
        $export = $this->actingAs($user)->postJson("/api/decks/{$deck->id}/exports", ['format' => 'pptx'])->assertCreated();
        $file = $this->actingAs($user)->get($export->json('download_url'))->assertOk()->streamedContent();

        $res = $this->actingAs($user)->post('/api/imports/pptx', ['file' => UploadedFile::fake()->createWithContent('round.pptx', $file)], ['Accept' => 'application/json'])->assertCreated();

        $new = Deck::findOrFail($res->json('deck.id'));
        $this->assertNotSame($deck->id, $new->id);
        $this->assertSame('Round', $new->title);
        $this->assertSame(1920, $new->w);
        $this->assertSame('Hello again', $new->slides()->first()->els[0]['doc']['content'][0]['content'][0]['text']);
    }
}
