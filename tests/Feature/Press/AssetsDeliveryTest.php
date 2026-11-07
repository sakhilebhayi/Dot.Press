<?php

namespace Tests\Feature\Press;

use App\Models\Asset;
use App\Services\Press\Assets\AssetUrls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AssetsDeliveryTest extends TestCase
{
    use AssetsFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssets();
    }

    private function make(string $bytes, string $name): array
    {
        return $this->upload($this->owner, $bytes, $name)->assertSuccessful()->json('asset');
    }

    // ---- headers and variants ------------------------------------------------------------------------------

    public function test_the_file_route_streams_the_right_variant_with_cache_and_safety_headers(): void
    {
        $asset = $this->make($this->jpegBytes(900, 600), 'photo.jpg');
        $row = Asset::findOrFail($asset['id']);

        $orig = $this->actingAs($this->owner)->get($asset['url'])->assertOk();
        $orig->assertHeader('Content-Type', 'image/jpeg');
        $orig->assertHeader('X-Content-Type-Options', 'nosniff');
        $orig->assertHeader('Accept-Ranges', 'bytes');
        $orig->assertHeader('Content-Length', (string) $row->size);
        $orig->assertHeader('ETag', '"'.$row->sha256.'-orig"');
        $this->assertSame(Storage::disk('local')->get($row->path), $orig->streamedContent());

        $cache = $orig->headers->get('Cache-Control');
        foreach (['private', 'max-age=86400', 'immutable'] as $directive) {
            $this->assertStringContainsString($directive, $cache);
        }
        $this->assertStringContainsString('inline', $orig->headers->get('Content-Disposition'));

        $thumb = $this->actingAs($this->owner)->get($asset['thumb'])->assertOk();
        $thumb->assertHeader('Content-Type', 'image/webp');
        $thumb->assertHeader('ETag', '"'.$row->sha256.'-thumb"');
        $this->assertSame(IMAGETYPE_WEBP, getimagesizefromstring($thumb->streamedContent())[2]);

        $web = $this->actingAs($this->owner)->get($asset['web'])->assertOk();
        $web->assertHeader('Content-Type', 'image/webp');
        $web->assertHeader('ETag', '"'.$row->sha256.'-web"');
    }

    public function test_a_matching_etag_gets_304_without_a_body(): void
    {
        $asset = $this->make($this->jpegBytes(), 'photo.jpg');
        $etag = $this->actingAs($this->owner)->get($asset['url'])->headers->get('ETag');

        $response = $this->actingAs($this->owner)->get($asset['url'], ['If-None-Match' => $etag]);

        $response->assertStatus(304);
        $this->assertSame('', $response->getContent());
        $response->assertHeader('ETag', $etag);
    }

    public function test_svg_is_served_sandboxed(): void
    {
        $asset = $this->make($this->cleanSvg(), 'brand.svg');

        $response = $this->actingAs($this->owner)->get($asset['url'])->assertOk();

        $response->assertHeader('Content-Type', 'image/svg+xml');
        $response->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'; style-src 'unsafe-inline'");
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('<svg', $response->streamedContent());

        // No raster thumbnail exists, so the thumbnail url is the svg itself.
        $this->assertSame('image/svg+xml', $this->actingAs($this->owner)->get($asset['thumb'])->assertOk()->headers->get('Content-Type'));
    }

    public function test_the_download_route_keeps_its_name_and_sends_an_attachment(): void
    {
        $asset = $this->make($this->pngBytes(), 'logo final.png');
        $row = Asset::findOrFail($asset['id']);
        $url = AssetUrls::download($row);

        $this->assertSame(route('assets.download', ['asset' => $row->id], false), parse_url(route('assets.download', ['asset' => $row->id]), PHP_URL_PATH));

        $response = $this->actingAs($this->owner)->get($url)->assertOk();
        $this->assertStringContainsString('attachment; filename="logo final.png"', $response->headers->get('Content-Disposition'));
    }

    public function test_hostile_file_names_cannot_inject_headers(): void
    {
        $asset = $this->make($this->pngBytes(), "a\"b;\r\nX-Evil: 1 \u{202E}.png");

        $header = $this->actingAs($this->owner)->get($asset['url'])->assertOk()->headers->get('Content-Disposition');

        $this->assertStringNotContainsString("\r", $header);
        $this->assertStringNotContainsString("\n", $header);
        $this->assertSame(1, preg_match('/^inline; filename="[A-Za-z0-9 ._()\-]+\.png"; filename\*=UTF-8\'\'[A-Za-z0-9%._()\-]+$/', $header), $header);
    }

    public function test_a_variant_that_does_not_exist_falls_back_or_404s_honestly(): void
    {
        $audio = $this->make($this->mp3Bytes(), 'song.mp3');
        $this->actingAs($this->owner)->get($audio['url'])->assertOk();
        $this->assertNull($audio['thumb']);

        $video = Asset::findOrFail($this->make($this->mp4Bytes(), 'clip.mp4')['id']);
        $this->actingAs($this->owner)->get(AssetUrls::signed($video, 'thumb'))->assertNotFound();
        $this->actingAs($this->owner)->get(AssetUrls::signed($video, 'web'))->assertOk()->assertHeader('Content-Type', 'video/mp4');
    }

    // ---- range requests ------------------------------------------------------------------------------------

    public function test_range_requests_return_206_with_exact_bytes_and_headers(): void
    {
        $bytes = $this->mp4Bytes();
        $asset = $this->make($bytes, 'clip.mp4');
        $size = strlen($bytes);

        $response = $this->actingAs($this->owner)->get($asset['url'], ['Range' => 'bytes=10-39'])->assertStatus(206);
        $response->assertHeader('Content-Range', "bytes 10-39/{$size}");
        $response->assertHeader('Content-Length', '30');
        $response->assertHeader('Content-Type', 'video/mp4');
        $response->assertHeader('Accept-Ranges', 'bytes');
        $this->assertSame(substr($bytes, 10, 30), $response->streamedContent());

        $open = $this->actingAs($this->owner)->get($asset['url'], ['Range' => 'bytes=50-'])->assertStatus(206);
        $open->assertHeader('Content-Range', 'bytes 50-'.($size - 1)."/{$size}");
        $this->assertSame(substr($bytes, 50), $open->streamedContent());

        $suffix = $this->actingAs($this->owner)->get($asset['url'], ['Range' => 'bytes=-20'])->assertStatus(206);
        $suffix->assertHeader('Content-Range', 'bytes '.($size - 20).'-'.($size - 1)."/{$size}");
        $this->assertSame(substr($bytes, -20), $suffix->streamedContent());

        $clamped = $this->actingAs($this->owner)->get($asset['url'], ['Range' => 'bytes=0-999999'])->assertStatus(206);
        $clamped->assertHeader('Content-Range', 'bytes 0-'.($size - 1)."/{$size}");
        $this->assertSame($bytes, $clamped->streamedContent());
    }

    public function test_unsatisfiable_ranges_get_416_with_the_size(): void
    {
        $bytes = $this->mp3Bytes();
        $asset = $this->make($bytes, 'song.mp3');
        $size = strlen($bytes);

        foreach (['bytes='.$size.'-', 'bytes=9999-10000', 'bytes=20-10', 'bytes=-0', 'bytes=abc', 'bytes=-'] as $range) {
            $response = $this->actingAs($this->owner)->get($asset['url'], ['Range' => $range]);
            $response->assertStatus(416);
            $response->assertHeader('Content-Range', "bytes */{$size}");
        }
    }

    public function test_other_range_forms_are_served_whole(): void
    {
        $bytes = $this->wavBytes();
        $asset = $this->make($bytes, 'beep.wav');

        $multi = $this->actingAs($this->owner)->get($asset['url'], ['Range' => 'bytes=0-3,8-11'])->assertOk();
        $this->assertSame($bytes, $multi->streamedContent());

        $unit = $this->actingAs($this->owner)->get($asset['url'], ['Range' => 'items=0-3'])->assertOk();
        $this->assertSame($bytes, $unit->streamedContent());

        $stale = $this->actingAs($this->owner)->get($asset['url'], ['Range' => 'bytes=0-3', 'If-Range' => '"something-else"'])->assertOk();
        $this->assertSame($bytes, $stale->streamedContent());
    }

    public function test_head_requests_report_length_without_a_body(): void
    {
        $bytes = $this->mp4Bytes();
        $asset = $this->make($bytes, 'clip.mp4');

        $response = $this->actingAs($this->owner)->call('HEAD', $asset['url']);

        $response->assertOk();
        $response->assertHeader('Content-Length', (string) strlen($bytes));
        $this->assertSame('', (string) $response->getContent());
    }

    // ---- signed urls ---------------------------------------------------------------------------------------

    public function test_a_tampered_signed_url_is_refused(): void
    {
        $asset = $this->make($this->jpegBytes(), 'photo.jpg');

        $this->actingAs($this->owner)->get(str_replace('v=orig', 'v=web', $asset['url']))->assertForbidden();
        $this->actingAs($this->owner)->get(preg_replace('/signature=[0-9a-f]+/', 'signature=deadbeef', $asset['url']))->assertForbidden();

        $path = parse_url($asset['url'], PHP_URL_PATH);
        $this->actingAs($this->owner)->get($path.'?v=orig')->assertForbidden();
    }

    public function test_a_signed_url_expires_after_the_configured_time(): void
    {
        config()->set('filesystems.asset_signed_url_ttl', 5);
        $asset = $this->make($this->jpegBytes(), 'photo.jpg');

        $this->assertSame(300, $this->actingAs($this->owner)->getJson('/api/assets/'.$asset['id'])->json('expires_in_seconds'));

        $this->travel(4)->minutes();
        $this->actingAs($this->owner)->get($asset['url'])->assertOk();

        $this->travel(2)->minutes();
        $this->actingAs($this->owner)->get($asset['url'])->assertForbidden();
    }

    public function test_a_signed_url_is_not_enough_without_being_signed_in(): void
    {
        $asset = $this->make($this->jpegBytes(), 'photo.jpg');
        $this->app['auth']->forgetGuards();

        $this->getJson($asset['url'])->assertUnauthorized();
    }

    // ---- teams ---------------------------------------------------------------------------------------------

    public function test_another_teams_member_cannot_reach_an_asset_by_any_route(): void
    {
        $asset = $this->make($this->jpegBytes(), 'photo.jpg');
        $stranger = $this->outsider();
        $id = $asset['id'];

        // Even holding a valid signed url, the file is a 404 for someone outside the team.
        $this->actingAs($stranger)->get($asset['url'])->assertNotFound();
        $this->actingAs($stranger)->get($asset['thumb'])->assertNotFound();
        $this->actingAs($stranger)->get(AssetUrls::download(Asset::withoutGlobalScopes()->findOrFail($id)))->assertNotFound();

        $this->actingAs($stranger)->getJson("/api/assets/{$id}")->assertNotFound();
        $this->actingAs($stranger)->getJson("/api/assets/{$id}/usage")->assertNotFound();
        $this->actingAs($stranger)->patchJson("/api/assets/{$id}", ['name' => 'mine now'])->assertNotFound();
        $this->actingAs($stranger)->deleteJson("/api/assets/{$id}")->assertNotFound();
        $this->actingAs($stranger)->deleteJson("/api/assets/{$id}?force=1")->assertNotFound();
        $this->actingAs($stranger)->post("/api/assets/{$id}/poster", ['file' => UploadedFile::fake()->createWithContent('f.jpg', $this->jpegBytes())], ['Accept' => 'application/json'])->assertNotFound();

        $this->actingAs($stranger)->getJson('/api/assets?project_id='.$this->project->id)->assertNotFound();
        $this->upload($stranger, $this->jpegBytes(), 'injected.jpg')->assertNotFound();

        // Their own (empty) library is not polluted either.
        $this->actingAs($stranger)->getJson('/api/assets')->assertOk()->assertJsonCount(0, 'data');

        $this->assertSame('photo.jpg', Asset::withoutGlobalScopes()->findOrFail($id)->original_name);
        $this->assertDatabaseCount('assets', 1);
    }

    public function test_a_viewer_can_read_and_download_but_not_change(): void
    {
        $asset = $this->make($this->jpegBytes(), 'photo.jpg');
        $viewer = $this->memberWithRole('viewer');
        $id = $asset['id'];

        $this->actingAs($viewer)->get($asset['url'])->assertOk();
        $this->actingAs($viewer)->getJson("/api/assets/{$id}")->assertOk();
        $this->actingAs($viewer)->getJson("/api/assets/{$id}/usage")->assertOk();
        $this->actingAs($viewer)->getJson('/api/assets')->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs($viewer)->patchJson("/api/assets/{$id}", ['name' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->deleteJson("/api/assets/{$id}")->assertForbidden();
        $this->upload($viewer, $this->pngBytes(), 'new.png')->assertForbidden();
    }

    // ---- video posters -------------------------------------------------------------------------------------

    public function test_a_captured_frame_becomes_the_posters_of_a_video(): void
    {
        $video = $this->make($this->mp4Bytes(), 'clip.mp4');
        $this->assertNull($video['thumb']);
        $this->assertNull($video['poster']);

        $response = $this->actingAs($this->owner)->post("/api/assets/{$video['id']}/poster", [
            'file' => UploadedFile::fake()->createWithContent('frame.jpg', $this->jpegBytes(320, 180)),
        ], ['Accept' => 'application/json'])->assertCreated();

        $response->assertJsonPath('poster.kind', 'poster')->assertJsonPath('poster.width', 320);
        $posterId = $response->json('poster.id');
        $response->assertJsonPath('asset.poster.id', $posterId);

        $this->assertSame($posterId, Asset::findOrFail($video['id'])->metadata['poster_asset_id']);

        // The video now has a thumbnail: its poster's.
        $shown = $this->actingAs($this->owner)->getJson("/api/assets/{$video['id']}")->assertOk();
        $this->assertNotNull($shown->json('asset.thumb'));
        $this->actingAs($this->owner)->get($shown->json('asset.thumb'))->assertOk()->assertHeader('Content-Type', 'image/webp');

        // Posters are derivatives: the default listing shows the video only, kind=poster shows the frame.
        $this->assertSame([$video['id']], array_column($this->actingAs($this->owner)->getJson('/api/assets')->json('data'), 'id'));
        $this->assertSame([$posterId], array_column($this->actingAs($this->owner)->getJson('/api/assets?kind=poster')->json('data'), 'id'));
    }

    public function test_replacing_a_poster_removes_the_old_one_and_deleting_the_video_removes_the_poster(): void
    {
        $video = $this->make($this->mp4Bytes(), 'clip.mp4');
        $send = fn (string $bytes, string $name) => $this->actingAs($this->owner)->post("/api/assets/{$video['id']}/poster", [
            'file' => UploadedFile::fake()->createWithContent($name, $bytes),
        ], ['Accept' => 'application/json'])->assertCreated()->json('poster.id');

        $first = $send($this->jpegBytes(64, 36), 'a.jpg');
        $firstPath = Asset::findOrFail($first)->path;
        $second = $send($this->pngBytes(64, 36), 'b.png');

        $this->assertNotSame($first, $second);
        $this->assertDatabaseMissing('assets', ['id' => $first]);
        Storage::disk('local')->assertMissing($firstPath);

        $this->actingAs($this->owner)->deleteJson("/api/assets/{$video['id']}")->assertNoContent();
        $this->assertDatabaseMissing('assets', ['id' => $second]);
        $this->assertDatabaseCount('assets', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_only_jpeg_or_png_frames_on_video_assets_are_accepted_as_posters(): void
    {
        $video = $this->make($this->mp4Bytes(), 'clip.mp4');
        $image = $this->make($this->jpegBytes(), 'photo.jpg');
        $post = fn (int $id, string $bytes, string $name) => $this->actingAs($this->owner)->post("/api/assets/{$id}/poster", [
            'file' => UploadedFile::fake()->createWithContent($name, $bytes),
        ], ['Accept' => 'application/json']);

        $post($video['id'], $this->cleanSvg(), 'f.svg')->assertUnprocessable();
        $post($video['id'], $this->webpBytes(), 'f.webp')->assertUnprocessable();
        $post($video['id'], $this->mp4Bytes(), 'f.png')->assertUnprocessable();
        $post($image['id'], $this->jpegBytes(), 'f.jpg')->assertUnprocessable();
        $this->actingAs($this->owner)->postJson("/api/assets/{$video['id']}/poster", [])->assertUnprocessable();
    }
}
