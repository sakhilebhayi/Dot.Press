<?php

namespace Tests\Feature\Press;

use App\Models\Asset;
use App\Models\Project;
use App\Services\Press\Assets\AssetRejected;
use App\Services\Press\Assets\AssetStore;
use App\Services\Press\Assets\GifInspector;
use App\Services\Press\Assets\MediaSniffer;
use App\Services\Press\Assets\SvgSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AssetsUploadTest extends TestCase
{
    use AssetsFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssets();
    }

    private function stored(Asset $asset, string $which = 'path'): string
    {
        return Storage::disk('local')->get($asset->{$which});
    }

    // ---- raster images -----------------------------------------------------------------------------------

    public function test_a_jpeg_is_stored_with_a_web_variant_and_a_thumbnail(): void
    {
        $response = $this->upload($this->owner, $this->jpegBytes(640, 480), 'photo.jpg')->assertCreated();

        $response->assertJsonPath('asset.kind', 'image')
            ->assertJsonPath('asset.mime', 'image/jpeg')
            ->assertJsonPath('asset.width', 640)
            ->assertJsonPath('asset.height', 480)
            ->assertJsonPath('asset.name', 'photo.jpg')
            ->assertJsonPath('deduplicated', false)
            ->assertJsonStructure(['asset' => ['url', 'thumb', 'web', 'used_in', 'created_at'], 'delivery_url', 'expires_in_seconds']);

        $asset = Asset::findOrFail($response->json('asset.id'));
        $this->assertNotNull($asset->web_path);
        $this->assertNotNull($asset->thumb_path);
        $this->assertSame(64, strlen($asset->sha256));

        $this->assertSame([640, 480], array_slice(getimagesizefromstring($this->stored($asset)), 0, 2));
        $this->assertSame([640, 480], array_slice(getimagesizefromstring($this->stored($asset, 'web_path')), 0, 2));
        $thumb = getimagesizefromstring($this->stored($asset, 'thumb_path'));
        $this->assertSame(IMAGETYPE_WEBP, $thumb[2]);
        $this->assertSame(480, max($thumb[0], $thumb[1]));
    }

    public function test_the_web_variant_is_capped_at_2560_on_the_longest_side(): void
    {
        $asset = Asset::findOrFail($this->upload($this->owner, $this->jpegBytes(3200, 1600, 60), 'wide.jpg')->assertCreated()->json('asset.id'));

        $this->assertSame([3200, 1600], [$asset->width, $asset->height]);
        $this->assertSame([3200, 1600], array_slice(getimagesizefromstring($this->stored($asset)), 0, 2));
        $this->assertSame([2560, 1280], array_slice(getimagesizefromstring($this->stored($asset, 'web_path')), 0, 2));
        $this->assertSame([480, 240], array_slice(getimagesizefromstring($this->stored($asset, 'thumb_path')), 0, 2));
    }

    public function test_exif_orientation_is_applied_and_metadata_is_stripped(): void
    {
        $source = $this->jpegWithOrientation(40, 20, 6);
        $this->assertStringContainsString('Exif', $source);

        $asset = Asset::findOrFail($this->upload($this->owner, $source, 'phone.jpg')->assertCreated()->json('asset.id'));

        $this->assertSame([20, 40], [$asset->width, $asset->height]);
        $stored = $this->stored($asset);
        $this->assertSame([20, 40], array_slice(getimagesizefromstring($stored), 0, 2));
        $this->assertStringNotContainsString('Exif', $stored);
    }

    /**
     * @return array<string, array{int, string}> orientation => where the blue top-left quadrant ends up
     */
    public static function orientations(): array
    {
        return [
            '1 as shot' => [1, 'tl'], '2 mirrored' => [2, 'tr'], '3 upside down' => [3, 'br'], '4 flipped' => [4, 'bl'],
            '5 transposed' => [5, 'tl'], '6 turn clockwise' => [6, 'tr'], '7 transversed' => [7, 'br'], '8 turn counter-clockwise' => [8, 'bl'],
        ];
    }

    #[DataProvider('orientations')]
    public function test_every_exif_orientation_ends_up_upright(int $orientation, string $blueAt): void
    {
        $asset = Asset::findOrFail($this->upload($this->owner, $this->jpegWithOrientation(80, 40, $orientation), 'o.jpg')->assertCreated()->json('asset.id'));
        $image = imagecreatefromstring($this->stored($asset));
        [$w, $h] = [imagesx($image), imagesy($image)];
        $this->assertSame($orientation >= 5 ? [40, 80] : [80, 40], [$w, $h]);

        $isBlue = function (int $x, int $y) use ($image): bool {
            $c = imagecolorsforindex($image, imagecolorat($image, $x, $y));

            return $c['blue'] > 150 && $c['red'] < 100;
        };
        $corners = ['tl' => [intdiv($w, 5), intdiv($h, 5)], 'tr' => [$w - 1 - intdiv($w, 5), intdiv($h, 5)], 'bl' => [intdiv($w, 5), $h - 1 - intdiv($h, 5)], 'br' => [$w - 1 - intdiv($w, 5), $h - 1 - intdiv($h, 5)]];

        foreach ($corners as $name => [$x, $y]) {
            $this->assertSame($name === $blueAt, $isBlue($x, $y), "orientation {$orientation}: corner {$name}");
        }
    }

    public function test_a_jpeg_with_a_php_payload_appended_is_cleaned_not_trusted(): void
    {
        $payload = "<?php system(\$_GET['c']); ?>";
        $asset = Asset::findOrFail($this->upload($this->owner, $this->jpegBytes().$payload, 'shell.jpg')->assertCreated()->json('asset.id'));

        foreach (['path', 'web_path', 'thumb_path'] as $column) {
            $this->assertStringNotContainsString('<?php', $this->stored($asset, $column));
            $this->assertStringNotContainsString('system(', $this->stored($asset, $column));
        }
        $this->assertSame('image/jpeg', $asset->mime_type);
    }

    public function test_a_png_keeps_its_transparency_in_every_variant(): void
    {
        $asset = Asset::findOrFail($this->upload($this->owner, $this->pngBytes(60, 40, true), 'logo.png')->assertCreated()->json('asset.id'));

        $this->assertSame('image/png', $asset->mime_type);

        foreach (['path', 'web_path', 'thumb_path'] as $column) {
            $image = imagecreatefromstring($this->stored($asset, $column));
            $corner = imagecolorsforindex($image, imagecolorat($image, 0, 0));
            $this->assertGreaterThan(100, $corner['alpha'], "{$column} lost its transparency");
        }
    }

    public function test_webp_and_static_gif_are_accepted(): void
    {
        $webp = Asset::findOrFail($this->upload($this->owner, $this->webpBytes(), 'a.webp')->assertCreated()->json('asset.id'));
        $this->assertSame('image/webp', $webp->mime_type);
        $this->assertNull($webp->web_path, 'a small webp is already its own web variant');

        $gif = Asset::findOrFail($this->upload($this->owner, $this->staticGifBytes(), 'a.gif')->assertCreated()->json('asset.id'));
        $this->assertSame('image/gif', $gif->mime_type);
        $this->assertFalse((bool) ($gif->metadata['animated'] ?? false));
        $this->assertNotNull($gif->thumb_path);
    }

    public function test_an_animated_gif_is_stored_as_is_with_a_still_thumbnail(): void
    {
        $bytes = $this->animatedGifBytes();
        $response = $this->upload($this->owner, $bytes.'TRAILING-JUNK', 'spin.gif')->assertCreated();
        $asset = Asset::findOrFail($response->json('asset.id'));

        $response->assertJsonPath('asset.animated', true);
        $this->assertSame($bytes, $this->stored($asset), 'frames are kept untouched, data after the trailer is cut');
        $this->assertNull($asset->web_path);
        $this->assertNotNull($asset->thumb_path);
    }

    public function test_avif_is_accepted_where_gd_can_write_it(): void
    {
        if (! function_exists('imageavif') || ! (imagetypes() & IMG_AVIF)) {
            $this->markTestSkipped('This GD build has no AVIF support.');
        }

        $image = imagecreatetruecolor(32, 32);
        ob_start();
        imageavif($image);
        $avif = (string) ob_get_clean();

        $this->upload($this->owner, $avif, 'a.avif')->assertCreated()->assertJsonPath('asset.mime', 'image/avif');
    }

    // ---- svg ---------------------------------------------------------------------------------------------

    public function test_a_clean_svg_is_stored_with_its_size(): void
    {
        $response = $this->upload($this->owner, $this->cleanSvg(), 'brand.svg')->assertCreated();
        $asset = Asset::findOrFail($response->json('asset.id'));

        $response->assertJsonPath('asset.mime', 'image/svg+xml')->assertJsonPath('asset.width', 200)->assertJsonPath('asset.height', 100);
        $this->assertNull($asset->thumb_path);
        $stored = $this->stored($asset);
        $this->assertStringContainsString('linearGradient', $stored);
        $this->assertStringContainsString('url(#g)', $stored);
        $this->assertStringContainsString('Hello', $stored);
    }

    public function test_a_hostile_svg_is_rebuilt_without_anything_dangerous(): void
    {
        $asset = Asset::findOrFail($this->upload($this->owner, $this->hostileSvg(), 'evil.svg')->assertCreated()->json('asset.id'));
        $stored = strtolower($this->stored($asset));

        foreach (['script', 'onload', 'onclick', 'foreignobject', 'iframe', 'javascript', 'evil.example', 'animate', 'data:', 'xlink:href', '<image'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $stored, "{$forbidden} survived sanitising");
        }
        $this->assertStringContainsString('<circle', $stored);
        $this->assertStringContainsString('#00ff00', $stored);
    }

    public function test_svg_entities_and_dtds_are_refused(): void
    {
        $bomb = '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;">]><svg xmlns="http://www.w3.org/2000/svg"><text>&b;</text></svg>';
        $this->upload($this->owner, $bomb, 'bomb.svg')->assertUnprocessable()->assertJsonValidationErrors('file');

        $xxe = '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>';
        $this->upload($this->owner, $xxe, 'xxe.svg')->assertUnprocessable();
    }

    public function test_a_plain_public_doctype_line_is_dropped_not_refused(): void
    {
        $svg = '<?xml version="1.0"?><!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd"><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>';
        $asset = Asset::findOrFail($this->upload($this->owner, $svg, 'old.svg')->assertCreated()->json('asset.id'));

        $this->assertStringNotContainsString('DOCTYPE', $this->stored($asset));
    }

    public function test_html_and_broken_xml_do_not_pass_as_svg(): void
    {
        $this->upload($this->owner, '<svg><rect></svg>', 'broken.svg')->assertUnprocessable();
        $this->upload($this->owner, '<?xml version="1.0"?><html xmlns="http://www.w3.org/1999/xhtml"><body><svg></svg></body></html>', 'page.svg')->assertUnprocessable();
    }

    public function test_the_sanitiser_blocks_style_tricks_and_keeps_internal_references(): void
    {
        $result = (new SvgSanitizer)->sanitise('<svg xmlns="http://www.w3.org/2000/svg"><style>@import url(https://evil.example/x.css); rect{fill:red}</style><style>rect{fill:blue}</style>'
            .'<defs><circle id="c" r="3"/></defs><use href="#c"/><use href="https://evil.example/s.svg#c"/>'
            .'<rect width="4" height="4" style="background:url(https://evil.example/a.png)"/><rect width="5" height="5" style="fill:#fff"/></svg>');

        $this->assertStringNotContainsString('evil.example', $result['svg']);
        $this->assertStringNotContainsString('@import', $result['svg']);
        $this->assertStringContainsString('rect{fill:blue}', $result['svg']);
        $this->assertStringContainsString('<use href="#c"/>', $result['svg']);
        $this->assertSame(1, substr_count($result['svg'], '<use '));
        $this->assertStringContainsString('style="fill:#fff"', $result['svg']);
        $this->assertSame([300, 150], [$result['width'], $result['height']]);
    }

    // ---- video and audio ---------------------------------------------------------------------------------

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function mediaCases(): array
    {
        return [
            'mp4' => ['mp4Bytes', 'clip.mp4', 'video', 'video/mp4'],
            'mov' => ['movBytes', 'clip.mov', 'video', 'video/quicktime'],
            'webm' => ['webmBytes', 'clip.webm', 'video', 'video/webm'],
            'mp3' => ['mp3Bytes', 'song.mp3', 'audio', 'audio/mpeg'],
            'wav' => ['wavBytes', 'beep.wav', 'audio', 'audio/wav'],
            'ogg' => ['oggBytes', 'tone.ogg', 'audio', 'audio/ogg'],
            'm4a' => ['m4aBytes', 'voice.m4a', 'audio', 'audio/mp4'],
        ];
    }

    #[DataProvider('mediaCases')]
    public function test_video_and_audio_are_detected_by_content_and_stored_as_is(string $builder, string $name, string $kind, string $mime): void
    {
        $bytes = $this->{$builder}();
        $response = $this->upload($this->owner, $bytes, $name)->assertCreated();
        $asset = Asset::findOrFail($response->json('asset.id'));

        $response->assertJsonPath('asset.kind', $kind)->assertJsonPath('asset.mime', $mime);
        $this->assertSame($bytes, $this->stored($asset));
        $this->assertNull($asset->thumb_path);
        $this->assertSame(strlen($bytes), (int) $asset->size);
    }

    public function test_the_extension_and_client_mime_type_decide_nothing(): void
    {
        $file = UploadedFile::fake()->createWithContent('holiday.png', $this->mp4Bytes());
        $response = $this->actingAs($this->owner)->post('/api/assets', ['project_id' => $this->project->id, 'file' => $file], ['Accept' => 'application/json'])->assertCreated();

        $response->assertJsonPath('asset.kind', 'video')->assertJsonPath('asset.mime', 'video/mp4');
    }

    // ---- refused files -----------------------------------------------------------------------------------

    /**
     * @return array<string, array{callable(self): string, string}>
     */
    public static function refusedFiles(): array
    {
        return [
            'windows exe renamed to png' => [fn (self $t) => "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF\x00\x00".str_repeat("\x00", 200), 'cat.png'],
            'php file renamed to jpg' => [fn (self $t) => "<?php echo 'x'; ?>", 'cat.jpg'],
            'plain text renamed to mp4' => [fn (self $t) => str_repeat('hello world ', 50), 'clip.mp4'],
            'zip archive' => [fn (self $t) => "PK\x03\x04".str_repeat("\x00", 100), 'deck.zip'],
            'html page' => [fn (self $t) => '<html><body>hi</body></html>', 'page.svg'],
            'empty file' => [fn (self $t) => '', 'empty.png'],
            'heic photo' => [fn (self $t) => pack('N', 24).'ftyp'.'heic'.pack('N', 0).'heic'.'mif1', 'iphone.heic'],
            'matroska video' => [fn (self $t) => "\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01".'matroska'.str_repeat("\x00", 50), 'film.mkv'],
            'ogg video (theora)' => [fn (self $t) => "OggS\x00\x02".str_repeat("\x00", 20)."\x80theora".str_repeat("\x00", 40), 'film.ogv'],
        ];
    }

    #[DataProvider('refusedFiles')]
    public function test_unsupported_and_disguised_files_get_a_422(callable $build, string $name): void
    {
        $this->upload($this->owner, $build($this), $name)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('assets', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_pdf_is_refused_with_a_clear_message(): void
    {
        $response = $this->upload($this->owner, "%PDF-1.7\n1 0 obj\n<<>>\nendobj\n%%EOF", 'deck.pdf')->assertUnprocessable();

        $this->assertStringContainsString('PDF', $response->json('errors.file.0'));
        $this->assertStringContainsString('JPEG', MediaSnifferMessage::accepted());
    }

    public function test_truncated_files_are_refused(): void
    {
        $jpeg = $this->jpegBytes(300, 300, 95);
        $this->upload($this->owner, substr($jpeg, 0, intdiv(strlen($jpeg), 2)), 'cut.jpg')->assertUnprocessable();

        $png = $this->pngBytes(200, 200);
        $this->upload($this->owner, substr($png, 0, intdiv(strlen($png), 2)), 'cut.png')->assertUnprocessable();

        $gif = $this->staticGifBytes(40, 40);
        $this->upload($this->owner, substr($gif, 0, strlen($gif) - 6), 'cut.gif')->assertUnprocessable();

        $mp4 = $this->mp4Bytes();
        $this->upload($this->owner, substr($mp4, 0, strlen($mp4) - 30), 'cut.mp4')->assertUnprocessable();
        $this->upload($this->owner, pack('N', 24).'ftyp'.'isom'.pack('N', 0).'isom'.'mp41'.pack('N', 8 + 16).'mdat'.str_repeat('x', 16), 'noindex.mp4')->assertUnprocessable();

        $this->assertDatabaseCount('assets', 0);
    }

    public function test_images_that_would_decode_to_enormous_bitmaps_are_refused_before_decoding(): void
    {
        $response = $this->upload($this->owner, $this->pngBombBytes(12000, 12000), 'bomb.png')->assertUnprocessable();
        $this->assertStringContainsString('megapixels', $response->json('errors.file.0'));

        $response = $this->upload($this->owner, $this->pngBombBytes(30000, 8), 'strip.png')->assertUnprocessable();
        $this->assertStringContainsString('longest side', $response->json('errors.file.0'));

        // Under the pixel budget the same crafted header is read as a damaged image, never a crash.
        $this->upload($this->owner, $this->pngBombBytes(3000, 3000), 'liar.png')->assertUnprocessable();
    }

    public function test_size_limits_per_kind_are_enforced_with_the_real_numbers(): void
    {
        $sparse = function (string $header, int $bytes, string $name): UploadedFile {
            $path = tempnam(sys_get_temp_dir(), 'asset-big-');
            $handle = fopen($path, 'wb');
            fwrite($handle, $header);
            ftruncate($handle, $bytes);
            fclose($handle);

            return new UploadedFile($path, $name, null, null, true);
        };

        $post = fn (UploadedFile $file) => $this->actingAs($this->owner)->post('/api/assets', ['project_id' => $this->project->id, 'file' => $file], ['Accept' => 'application/json']);

        $message = $post($sparse("\xFF\xD8\xFF\xE0", 25 * 1024 * 1024 + 10, 'big.jpg'))->assertUnprocessable()->json('errors.file.0');
        $this->assertStringContainsString('Images can be up to 25 MB', $message);

        $message = $post($sparse('RIFF'.pack('V', 100).'WAVEfmt ', 50 * 1024 * 1024 + 10, 'long.wav'))->assertUnprocessable()->json('errors.file.0');
        $this->assertStringContainsString('Audio files can be up to 50 MB', $message);

        $message = $post($sparse(pack('N', 24).'ftyp'.'isom'.pack('N', 0).'isom'.'mp41', 200 * 1024 * 1024 + 10, 'film.mp4'))->assertUnprocessable()->json('errors.file.0');
        $this->assertStringContainsString('Video files can be up to 200 MB', $message);

        $message = $post($sparse('<svg xmlns="http://www.w3.org/2000/svg"/>', 5 * 1024 * 1024 + 10, 'huge.svg'))->assertUnprocessable()->json('errors.file.0');
        $this->assertStringContainsString('SVG files can be up to 5 MB', $message);

        $this->assertDatabaseCount('assets', 0);
    }

    public function test_a_file_php_refused_explains_the_server_limits(): void
    {
        $file = new UploadedFile($this->createTempFile(), 'huge.mp4', 'video/mp4', UPLOAD_ERR_INI_SIZE, true);

        $response = $this->actingAs($this->owner)->post('/api/assets', ['project_id' => $this->project->id, 'file' => $file], ['Accept' => 'application/json'])->assertUnprocessable();

        $message = $response->json('errors.file.0');
        $this->assertStringContainsString('upload_max_filesize', $message);
        $this->assertStringContainsString('post_max_size', $message);
    }

    public function test_a_missing_file_is_a_validation_error(): void
    {
        $this->actingAs($this->owner)->postJson('/api/assets', ['project_id' => $this->project->id])->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    private function createTempFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'asset-test-');
        file_put_contents($path, 'x');

        return $path;
    }

    // ---- dedupe and the service API ---------------------------------------------------------------------

    public function test_the_same_bytes_in_a_project_return_the_existing_asset(): void
    {
        $bytes = $this->jpegBytes(50, 50);
        $first = $this->upload($this->owner, $bytes, 'one.jpg')->assertCreated();
        $second = $this->upload($this->owner, $bytes, 'renamed.jpg')->assertOk();

        $second->assertJsonPath('deduplicated', true);
        $this->assertSame($first->json('asset.id'), $second->json('asset.id'));
        $this->assertDatabaseCount('assets', 1);
        $this->assertCount(3, Storage::disk('local')->allFiles());
    }

    public function test_dedupe_is_per_project(): void
    {
        $other = Project::create(['user_id' => $this->owner->id, 'team_id' => $this->owner->currentTeam->id, 'name' => 'Second', 'slug' => 'second']);
        $bytes = $this->jpegBytes(50, 50);

        $a = $this->upload($this->owner, $bytes, 'one.jpg')->assertCreated();
        $b = $this->upload($this->owner, $bytes, 'one.jpg', ['project_id' => $other->id])->assertCreated();

        $this->assertNotSame($a->json('asset.id'), $b->json('asset.id'));
    }

    public function test_a_row_whose_file_vanished_is_replaced_instead_of_returned(): void
    {
        $bytes = $this->jpegBytes(50, 50);
        $first = Asset::findOrFail($this->upload($this->owner, $bytes, 'one.jpg')->json('asset.id'));
        Storage::disk('local')->delete($first->path);

        $second = $this->upload($this->owner, $bytes, 'one.jpg')->assertCreated();

        $this->assertNotSame($first->id, $second->json('asset.id'));
        $this->assertDatabaseMissing('assets', ['id' => $first->id]);
    }

    public function test_storebytes_is_callable_from_other_code_and_ignores_the_given_mime(): void
    {
        $asset = AssetStore::storeBytes($this->project, $this->owner, $this->pngBytes(), 'import/../evil.pdf', 'application/pdf');

        $this->assertTrue($asset->wasRecentlyCreated);
        $this->assertSame('image/png', $asset->mime_type);
        $this->assertSame('evil.pdf', $asset->original_name, 'path parts are stripped from names');
        $this->assertSame($this->project->id, $asset->project_id);
        $this->assertSame($this->owner->id, $asset->uploaded_by);

        $again = AssetStore::storeBytes($this->project, $this->owner, $this->pngBytes(), 'second.png');
        $this->assertSame($asset->id, $again->id);
        $this->assertFalse($again->wasRecentlyCreated);
    }

    public function test_storebytes_rejects_with_an_exception_the_caller_can_show(): void
    {
        $this->expectException(AssetRejected::class);
        $this->expectExceptionMessage('not supported');

        AssetStore::storeBytes($this->project, $this->owner, 'just some text, nothing else here', 'notes.txt');
    }

    public function test_the_gif_inspector_counts_frames_and_finds_the_end(): void
    {
        $gif = $this->animatedGifBytes();
        $this->assertSame(['frames' => 2, 'end' => strlen($gif)], GifInspector::inspect($gif.'xx'));
    }

    public function test_filenames_are_cleaned_of_paths_and_control_characters(): void
    {
        $asset = Asset::findOrFail($this->upload($this->owner, $this->pngBytes(), "..\\..\\etc/pass\x00wd.png")->assertCreated()->json('asset.id'));

        $this->assertStringNotContainsString('/', $asset->original_name);
        $this->assertStringNotContainsString('\\', $asset->original_name);
        $this->assertStringNotContainsString("\0", $asset->original_name);
    }
}

/** Keeps the accepted-types sentence in one assertion without importing the class name twice. */
final class MediaSnifferMessage
{
    public static function accepted(): string
    {
        return MediaSniffer::ACCEPTED;
    }
}
