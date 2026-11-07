<?php

namespace Tests\Feature\Press;

use App\Jobs\RunDeckExport;
use App\Models\DeckExport;
use App\Models\User;
use App\Services\Press\Export\ExportService;
use App\Services\Press\Export\Pdf\FontRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsPressDecks;
use Tests\Support\PressSampleDeck;
use Tests\TestCase;

class ExportApiTest extends TestCase
{
    use BuildsPressDecks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['press_export.font_dir' => storage_path('framework/testing/press-fonts')]);
        FontRegistry::reset();
    }

    public function test_a_small_deck_exports_to_pdf_inside_the_request(): void
    {
        $user = $this->pressUser();
        $deck = $this->pressDeck($user, PressSampleDeck::docWithoutVideo());
        Queue::fake();

        $res = $this->actingAs($user)->postJson("/api/decks/{$deck->id}/exports", ['format' => 'pdf'])->assertCreated();
        $res->assertJsonPath('status', 'done')->assertJsonPath('format', 'pdf');
        Queue::assertNothingPushed();
        $export = DeckExport::findOrFail($res->json('id'));
        $this->assertNotNull($export->expires_at);
        $this->assertTrue($export->expires_at->between(now()->addHours(23), now()->addHours(25)));
        $bytes = Storage::disk('local')->get($export->path);
        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertSame(strlen($bytes), (int) $export->size);

        $dl = $this->actingAs($user)->get($res->json('download_url'))->assertOk();
        $this->assertSame('application/pdf', $dl->headers->get('content-type'));
        $this->assertStringContainsString('sample-deck.pdf', (string) $dl->headers->get('content-disposition'));
    }

    public function test_a_deck_with_video_goes_to_the_queue_even_when_it_is_small(): void
    {
        $user = $this->pressUser();
        $deck = $this->pressDeck($user);
        Queue::fake();

        $this->actingAs($user)->postJson("/api/decks/{$deck->id}/exports", ['format' => 'pdf'])->assertStatus(202)->assertJsonPath('status', 'queued');
        Queue::assertPushed(RunDeckExport::class);
    }

    public function test_a_small_deck_exports_to_pptx(): void
    {
        $user = $this->pressUser();
        $deck = $this->pressDeck($user, PressSampleDeck::docWithoutVideo());

        $res = $this->actingAs($user)->postJson("/api/decks/{$deck->id}/exports", ['format' => 'pptx', 'options' => ['include_hidden' => true]])->assertCreated();
        $export = DeckExport::findOrFail($res->json('id'));
        $this->assertSame('done', $export->status);
        $this->assertStringEndsWith('.pptx', $export->path);
        $bytes = Storage::disk('local')->get($export->path);
        $this->assertStringStartsWith("PK\x03\x04", $bytes);
        $dl = $this->actingAs($user)->get($res->json('download_url'))->assertOk();
        $this->assertSame('application/vnd.openxmlformats-officedocument.presentationml.presentation', $dl->headers->get('content-type'));
    }

    public function test_a_big_deck_goes_to_the_database_queue(): void
    {
        $user = $this->pressUser();
        $deck = $this->pressDeck($user, $this->plainDoc(14), false);
        Queue::fake();

        $res = $this->actingAs($user)->postJson("/api/decks/{$deck->id}/exports", ['format' => 'pdf'])->assertStatus(202);
        $res->assertJsonPath('status', 'queued')->assertJsonPath('download_url', null);
        Queue::assertPushed(RunDeckExport::class, fn ($job) => $job->exportId === $res->json('id'));
    }

    public function test_the_queued_job_renders_and_stores_the_file(): void
    {
        $user = $this->pressUser();
        $deck = $this->pressDeck($user, $this->plainDoc(14), false);
        Storage::fake('local');
        $export = DeckExport::create(['deck_id' => $deck->id, 'user_id' => $user->id, 'format' => 'pdf', 'status' => 'queued', 'options' => []]);

        (new RunDeckExport($export->id))->handle(app(ExportService::class));

        $export->refresh();
        $this->assertSame('done', $export->status);
        $this->assertSame(1, $export->attempts);
        $this->assertTrue(Storage::disk('local')->exists($export->path));
        $this->assertFalse(Storage::disk('local')->exists($export->path.'.part'));
    }

    public function test_a_failed_export_says_why_leaves_no_file_and_does_not_touch_the_deck(): void
    {
        $user = $this->pressUser();
        $deck = $this->pressDeck($user, $this->plainDoc(1), false);
        $deck->slides()->delete(); // nothing to export
        $before = $deck->fresh()->updated_at;
        Storage::fake('local');

        $res = $this->actingAs($user)->postJson("/api/decks/{$deck->id}/exports", ['format' => 'pdf'])->assertOk();
        $res->assertJsonPath('status', 'failed');
        $this->assertStringContainsString('no slides', (string) $res->json('error'));
        $export = DeckExport::findOrFail($res->json('id'));
        $this->assertNull($export->path);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertEquals($before, $deck->fresh()->updated_at);
        $this->actingAs($user)->get("/api/exports/{$export->id}/download")->assertStatus(409);
    }

    public function test_an_expired_export_cannot_be_downloaded_and_the_prune_command_removes_it(): void
    {
        $user = $this->pressUser();
        $deck = $this->pressDeck($user, $this->plainDoc(2), false);
        $res = $this->actingAs($user)->postJson("/api/decks/{$deck->id}/exports", ['format' => 'pdf'])->assertCreated();
        $export = DeckExport::findOrFail($res->json('id'));
        $path = $export->path;
        $this->assertTrue(Storage::disk('local')->exists($path));

        $export->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->actingAs($user)->get("/api/exports/{$export->id}/download")->assertStatus(410);

        $this->artisan('press:exports-prune')->assertSuccessful();
        $this->assertFalse(Storage::disk('local')->exists($path));
        $this->assertSame('expired', $export->fresh()->status);
        $this->assertNull($export->fresh()->path);
    }

    public function test_the_prune_command_is_on_the_schedule(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('press:exports-prune')->assertSuccessful();
    }

    public function test_prune_fails_exports_stuck_in_the_queue(): void
    {
        $user = $this->pressUser();
        $deck = $this->pressDeck($user, $this->plainDoc(1), false);
        $stuck = DeckExport::create(['deck_id' => $deck->id, 'user_id' => $user->id, 'format' => 'pdf', 'status' => 'running', 'options' => []]);
        DeckExport::whereKey($stuck->id)->update(['updated_at' => now()->subHours(3)]);

        $this->artisan('press:exports-prune')->assertSuccessful();

        $this->assertSame('failed', $stuck->fresh()->status);
        $this->assertNotEmpty($stuck->fresh()->error);
    }

    public function test_exports_are_only_visible_to_people_who_can_view_the_deck(): void
    {
        $owner = $this->pressUser();
        $deck = $this->pressDeck($owner, $this->plainDoc(2), false);
        $res = $this->actingAs($owner)->postJson("/api/decks/{$deck->id}/exports", ['format' => 'pdf'])->assertCreated();
        $id = $res->json('id');
        $stranger = User::factory()->withPersonalTeam()->create();

        $this->actingAs($stranger)->getJson("/api/exports/{$id}")->assertNotFound();
        $this->actingAs($stranger)->get("/api/exports/{$id}/download")->assertNotFound();
        $this->actingAs($stranger)->postJson("/api/decks/{$deck->id}/exports", ['format' => 'pdf'])->assertNotFound();
        $this->actingAs($stranger)->postJson("/api/decks/{$deck->id}/exports/preflight", ['format' => 'pdf'])->assertNotFound();
        $this->actingAs($stranger)->getJson("/api/decks/{$deck->id}/exports")->assertNotFound();
    }

    public function test_exports_need_a_signed_in_user(): void
    {
        $this->getJson('/api/exports/1')->assertUnauthorized();
        $this->postJson('/api/decks/1/exports', ['format' => 'pdf'])->assertUnauthorized();
        $this->postJson('/api/imports/pptx')->assertUnauthorized();
    }

    public function test_listing_shows_recent_exports_newest_first(): void
    {
        $user = $this->pressUser();
        $deck = $this->pressDeck($user, $this->plainDoc(1), false);
        $a = $this->actingAs($user)->postJson("/api/decks/{$deck->id}/exports", ['format' => 'pdf'])->json('id');
        $b = $this->actingAs($user)->postJson("/api/decks/{$deck->id}/exports", ['format' => 'pptx'])->json('id');

        $res = $this->actingAs($user)->getJson("/api/decks/{$deck->id}/exports")->assertOk();
        $this->assertSame([$b, $a], array_column($res->json('data'), 'id'));
    }

    public function test_a_selection_of_slides_by_database_id_exports_only_those(): void
    {
        $user = $this->pressUser();
        $deck = $this->pressDeck($user, $this->plainDoc(5), false);
        $ids = $deck->slides()->orderBy('sort_order')->pluck('id')->all();

        $res = $this->actingAs($user)->postJson("/api/decks/{$deck->id}/exports", ['format' => 'pdf', 'options' => ['slides' => [$ids[1], $ids[3]]]])->assertCreated();

        $bytes = Storage::disk('local')->get(DeckExport::find($res->json('id'))->path);
        preg_match_all('#/Type\s*/Page\b(?!s)#', $bytes, $m);
        $this->assertCount(2, $m[0]);
    }

    public function test_input_is_validated(): void
    {
        $user = $this->pressUser();
        $deck = $this->pressDeck($user, $this->plainDoc(1), false);

        $this->actingAs($user)->postJson("/api/decks/{$deck->id}/exports", ['format' => 'docx'])->assertStatus(422);
        $this->actingAs($user)->postJson("/api/decks/{$deck->id}/exports", [])->assertStatus(422);
        $this->actingAs($user)->postJson("/api/decks/{$deck->id}/exports", ['format' => 'pdf', 'options' => ['slides' => ['../etc']]])->assertStatus(422);
    }

    public function test_too_many_exports_in_progress_are_refused(): void
    {
        $user = $this->pressUser();
        $deck = $this->pressDeck($user, $this->plainDoc(1), false);
        for ($i = 0; $i < 3; $i++) {
            DeckExport::create(['deck_id' => $deck->id, 'user_id' => $user->id, 'format' => 'pdf', 'status' => 'queued', 'options' => []]);
        }

        $this->actingAs($user)->postJson("/api/decks/{$deck->id}/exports", ['format' => 'pdf'])->assertStatus(429);
    }

    public function test_the_named_limiter_throttles_exports(): void
    {
        $user = $this->pressUser();
        $deck = $this->pressDeck($user, $this->plainDoc(1), false);
        Queue::fake();
        $codes = [];
        for ($i = 0; $i < 12; $i++) {
            $codes[] = $this->actingAs($user)->postJson("/api/decks/{$deck->id}/exports/preflight", ['format' => 'pdf'])->getStatusCode();
        }

        $this->assertContains(429, $codes);
        $this->assertSame(200, $codes[0]);
    }

    public function test_preflight_endpoint_classifies_the_deck(): void
    {
        $user = $this->pressUser();
        $deck = $this->pressDeck($user);

        $res = $this->actingAs($user)->postJson("/api/decks/{$deck->id}/exports/preflight", ['format' => 'pptx'])->assertOk();
        $res->assertJsonPath('format', 'pptx');
        $this->assertGreaterThan(0, $res->json('summary.native'));
        $this->assertGreaterThan(0, $res->json('summary.approximated'));
        $levels = collect($res->json('slides'))->pluck('elements')->flatten(1)->pluck('level')->unique()->all();
        foreach ($levels as $l) {
            $this->assertContains($l, ['native', 'approximated', 'flattened', 'dropped']);
        }
    }

    public function test_exports_can_use_the_notes_option_and_skip_hidden_slides(): void
    {
        $user = $this->pressUser();
        $deck = $this->pressDeck($user, PressSampleDeck::docWithoutVideo());
        Sanctum::actingAs($user);

        $with = $this->postJson("/api/decks/{$deck->id}/exports", ['format' => 'pdf', 'options' => ['notes' => true, 'include_hidden' => true]])->assertCreated();
        $without = $this->postJson("/api/decks/{$deck->id}/exports", ['format' => 'pdf'])->assertCreated();

        $this->assertGreaterThan(Storage::disk('local')->size(DeckExport::find($without->json('id'))->path), Storage::disk('local')->size(DeckExport::find($with->json('id'))->path));
    }
}
