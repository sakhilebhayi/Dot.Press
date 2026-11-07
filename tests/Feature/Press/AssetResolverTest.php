<?php

namespace Tests\Feature\Press;

use App\Models\Asset;
use App\Models\User;
use App\Services\Press\Export\DatabaseAssetResolver;
use App\Services\Press\Export\ExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsPressDecks;
use Tests\TestCase;

class AssetResolverTest extends TestCase
{
    use BuildsPressDecks;
    use RefreshDatabase;

    private function asset(User $user, array $over = []): Asset
    {
        $project = $this->pressProject($user);
        Storage::disk('local')->put('a/orig.png', 'ORIGINAL');
        Storage::disk('local')->put('a/web.png', "\x89PNG\r\n\x1a\nWEB");

        return Asset::create($over + ['project_id' => $project->id, 'uploaded_by' => $user->id, 'disk' => 'local', 'path' => 'a/orig.png', 'original_name' => 'x.png', 'mime_type' => 'image/png', 'size' => 8, 'width' => 10, 'height' => 20]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_it_reads_bytes_mime_and_size_through_the_asset_disk(): void
    {
        $user = $this->pressUser();
        $a = $this->asset($user);

        $found = (new DatabaseAssetResolver)->find($a->id);

        $this->assertSame('ORIGINAL', $found['bytes']);
        $this->assertSame('image/png', $found['mime']);
        $this->assertSame([10, 20], [$found['width'], $found['height']]);
        $this->assertSame('x.png', $found['name']);
    }

    public function test_it_prefers_the_web_variant_when_there_is_one(): void
    {
        $user = $this->pressUser();
        $a = $this->asset($user, ['web_path' => 'a/web.png']);

        $found = (new DatabaseAssetResolver)->find($a->id);

        $this->assertStringEndsWith('WEB', $found['bytes']);
        $this->assertSame('image/png', $found['mime']);
    }

    public function test_it_falls_back_to_the_original_when_the_web_file_is_missing(): void
    {
        $user = $this->pressUser();
        $a = $this->asset($user, ['web_path' => 'a/gone.png']);

        $this->assertSame('ORIGINAL', (new DatabaseAssetResolver)->find($a->id)['bytes']);
    }

    public function test_assets_of_other_teams_do_not_resolve_even_when_the_document_names_them(): void
    {
        $mine = $this->pressUser();
        $theirs = $this->pressUser();
        $foreign = $this->asset($theirs);
        $deck = $this->pressDeck($mine, $this->plainDoc(1), false);

        $resolver = ExportService::resolverFor($deck);

        $this->assertNull($resolver->find($foreign->id));
        $this->assertNull((new DatabaseAssetResolver([$deck->project_id]))->find($foreign->id));
        $this->assertNotNull((new DatabaseAssetResolver)->find($foreign->id), 'the unrestricted resolver is only for trusted callers');
    }

    public function test_missing_rows_and_files_give_null_not_errors(): void
    {
        $user = $this->pressUser();
        $a = $this->asset($user, ['path' => 'a/nope.png']);

        $this->assertNull((new DatabaseAssetResolver)->find(99999));
        $this->assertNull((new DatabaseAssetResolver)->find($a->id));
    }

    public function test_a_document_pointing_at_a_foreign_picture_exports_without_it(): void
    {
        $mine = $this->pressUser();
        $theirs = $this->pressUser();
        $foreign = $this->asset($theirs);
        $doc = $this->plainDoc(1);
        $doc['slides'][0]['els'][] = ['id' => 'img', 'type' => 'image', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'src' => ['asset' => $foreign->id]];
        $deck = $this->pressDeck($mine, $doc, false);

        $res = $this->actingAs($mine)->postJson("/api/decks/{$deck->id}/exports/preflight", ['format' => 'pdf'])->assertOk();

        $levels = collect($res->json('slides.0.elements'))->pluck('level', 'id');
        $this->assertSame('dropped', $levels['img']);
    }
}
