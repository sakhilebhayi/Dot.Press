<?php

namespace Tests\Feature\Press;

use App\Models\Asset;
use App\Models\Deck;
use App\Models\Project;
use App\Models\Slide;
use App\Models\User;
use App\Services\Press\PresentPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * The offline present package: one HTML file that runs a presentation with no network.
 */
class PresentSessionPackageTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private User $owner;

    private Deck $deck;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->dir = sys_get_temp_dir().'/press-bundle-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        file_put_contents($this->dir.'/press-player.iife.js', 'window.__press = "player"; var s = "</script>"; var c = "<!--";');
        file_put_contents($this->dir.'/press-player.css', '.pp-root{position:fixed} /* </style> */');
        $this->app->bind(PresentPackage::class, fn () => new PresentPackage($this->dir));

        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->project = Project::create(['user_id' => $this->owner->id, 'team_id' => $this->owner->currentTeam->id, 'name' => 'P', 'slug' => 'p']);
        $this->deck = Deck::create([
            'project_id' => $this->project->id,
            'user_id' => $this->owner->id,
            'title' => 'Offline </script><script>alert(1)</script> deck',
            'sort_order' => 0,
            'theme' => ['t' => ['heading' => 'Literata', 'body' => 'Inter']],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function text(string $id, string $text, array $extra = []): array
    {
        return array_merge(['id' => $id, 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 400, 'h' => 80, 'doc' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]]], $extra);
    }

    private function slide(array $els, array $attrs = []): Slide
    {
        return Slide::create(array_merge(['deck_id' => $this->deck->id, 'title' => 'S', 'notes' => 'private speaker note', 'sort_order' => Slide::where('deck_id', $this->deck->id)->count(), 'canvas_state' => ['meta' => ['version' => 1]], 'els' => $els], $attrs));
    }

    /** @return array{0: array<string, mixed>, 1: string} the decoded document and the whole html */
    private function built(?PresentPackage $package = null, bool $notes = true): array
    {
        $out = ($package ?? app(PresentPackage::class))->build($this->deck, $notes);
        $this->assertTrue((bool) preg_match('#<script type="application/json" id="press-data">(.*?)</script>#s', $out['html'], $m), 'the document script is present and closed');

        return [json_decode($m[1], true, 512, JSON_THROW_ON_ERROR), $out['html']];
    }

    public function test_the_file_is_self_contained_and_holds_the_deck(): void
    {
        $this->slide([$this->text('a', 'Hello offline')]);

        [$doc, $html] = $this->built();

        $this->assertStringStartsWith('<!doctype html>', $html);
        $this->assertSame('Hello offline', $doc['slides'][0]['els'][0]['doc']['content'][0]['content'][0]['text']);
        $this->assertSame(1920, $doc['deck']['w']);
        $this->assertStringContainsString('window.__press = "player"', $html);
        $this->assertStringContainsString('.pp-root{position:fixed}', $html);
        $this->assertStringContainsString('<div id="press-app"></div>', $html);
    }

    public function test_nothing_in_the_file_can_close_a_script_early_or_inject_markup(): void
    {
        $this->slide([$this->text('a', '</script><script>alert(1)</script>')]);

        [$doc, $html] = $this->built();

        $this->assertSame(1, substr_count($html, '<script type="application/json"'));
        $this->assertSame(2, substr_count($html, '<script'), 'only the data script and the player script exist');
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString('&lt;/script&gt;&lt;script&gt;alert(1)', $html, 'the title is escaped');
        $this->assertStringContainsString('<\\/script', $html, 'the bundle is made safe too');
        $this->assertSame('</script><script>alert(1)</script>', $doc['slides'][0]['els'][0]['doc']['content'][0]['content'][0]['text'], 'the text itself survives');
    }

    public function test_assets_are_inlined_as_data_uris_and_the_web_variant_is_used(): void
    {
        Storage::disk('local')->put('orig.png', 'ORIGINAL-BYTES');
        Storage::disk('local')->put('web.jpg', 'WEB-VARIANT');
        $asset = Asset::create(['project_id' => $this->project->id, 'uploaded_by' => $this->owner->id, 'disk' => 'local', 'path' => 'orig.png', 'web_path' => 'web.jpg', 'original_name' => 'big.png', 'mime_type' => 'image/png', 'size' => 14, 'width' => 4, 'height' => 3]);
        Storage::disk('local')->put('clip.mp4', 'VIDEOBYTES');
        $clip = Asset::create(['project_id' => $this->project->id, 'uploaded_by' => $this->owner->id, 'disk' => 'local', 'path' => 'clip.mp4', 'original_name' => 'clip.mp4', 'mime_type' => 'video/mp4', 'size' => 10]);
        $this->slide([
            ['id' => 'img', 'type' => 'image', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'src' => ['asset' => $asset->id]],
            ['id' => 'vid', 'type' => 'video', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'src' => ['asset' => $clip->id], 'poster' => ['asset' => $asset->id]],
        ]);

        [$doc] = $this->built();

        $img = $doc['assets'][(string) $asset->id];
        $this->assertStringStartsWith('data:image/', $img['url']);
        $this->assertSame('WEB-VARIANT', base64_decode(explode('base64,', $img['url'])[1]), 'the web variant is inlined, not the original');
        $this->assertSame(4, $img['w']);
        $this->assertSame('VIDEOBYTES', base64_decode(explode('base64,', $doc['assets'][(string) $clip->id]['url'])[1]));
        $this->assertSame('data:video/mp4;base64,'.base64_encode('VIDEOBYTES'), $doc['assets'][(string) $clip->id]['url']);
    }

    public function test_an_asset_of_another_project_is_never_inlined(): void
    {
        $stranger = User::factory()->withPersonalTeam()->create();
        $theirs = Project::create(['user_id' => $stranger->id, 'team_id' => $stranger->currentTeam->id, 'name' => 'T', 'slug' => 't']);
        Storage::disk('local')->put('secret.png', 'TOP-SECRET');
        $secret = Asset::create(['project_id' => $theirs->id, 'uploaded_by' => $stranger->id, 'disk' => 'local', 'path' => 'secret.png', 'original_name' => 's.png', 'mime_type' => 'image/png', 'size' => 10]);
        $this->slide([['id' => 'img', 'type' => 'image', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'src' => ['asset' => $secret->id]]]);

        [$doc, $html] = $this->built();

        $this->assertSame([], $doc['assets']);
        $this->assertStringNotContainsString(base64_encode('TOP-SECRET'), $html);
    }

    public function test_no_network_reference_survives_and_embeds_become_plain_links(): void
    {
        $this->slide([
            ['id' => 'yt', 'type' => 'video', 'x' => 10, 'y' => 20, 'w' => 800, 'h' => 450, 'embed' => ['provider' => 'youtube', 'id' => 'dQw4w9WgXcQ'], 'autoplay' => true],
            ['id' => 'vm', 'type' => 'video', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'embed' => ['provider' => 'vimeo', 'id' => '123456789']],
            ['id' => 'ext', 'type' => 'image', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'src' => ['url' => 'https://tracker.example/pixel.png']],
            ['id' => 'vurl', 'type' => 'video', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'src' => ['url' => 'https://cdn.example/clip.mp4'], 'poster' => ['url' => 'https://cdn.example/poster.jpg']],
            ['id' => 'bad', 'type' => 'video', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'embed' => ['provider' => 'youtube', 'id' => '"><img src=x onerror=alert(1)>']],
            $this->text('mine', 'My site', ['doc' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'My site', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://mine.example/about']]]]]]]]]),
        ], ['bg' => ['img' => ['url' => 'https://cdn.example/bg.jpg'], 'fit' => 'cover']]);

        [$doc, $html] = $this->built();

        $els = collect($doc['slides'][0]['els'])->keyBy('id');
        $this->assertSame(['yt', 'vm', 'ext', 'vurl', 'mine'], $els->keys()->all(), 'the malformed embed is dropped, the rest keep their place');
        foreach (['yt', 'vm', 'ext', 'vurl'] as $id) {
            $this->assertSame('text', $els[$id]['type']);
            $this->assertArrayNotHasKey('embed', $els[$id]);
            $this->assertArrayNotHasKey('src', $els[$id]);
        }
        $this->assertSame(10, $els['yt']['x']);
        $this->assertSame(800, $els['yt']['w']);
        $this->assertNull($doc['slides'][0]['bg'], 'an address as a background is dropped');

        // Every address in the file is a link the author or this builder wrote, and none is something the page loads.
        preg_match_all('#https?://[^\s"\'<>\\\\)]+#', $html, $m);
        $allowed = ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://vimeo.com/123456789', 'https://tracker.example/pixel.png', 'https://cdn.example/clip.mp4', 'https://mine.example/about'];
        foreach (array_unique($m[0]) as $url) {
            $this->assertContains($url, $allowed, "unexpected address in the package: $url");
        }
        $this->assertDoesNotMatchRegularExpression('#(src|href|action|data|poster)\s*=\s*["\']?https?:#i', $html);
        $this->assertDoesNotMatchRegularExpression('#url\(\s*["\']?https?:#i', $html);
        $this->assertStringNotContainsString('@import', $html);
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringNotContainsString('<link', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('cdn.example/poster.jpg', $html);
        $this->assertStringNotContainsString('cdn.example/bg.jpg', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringContainsString("default-src 'none'", $html, 'the page also forbids network use itself');
    }

    public function test_only_the_fonts_the_deck_uses_are_inlined(): void
    {
        $this->slide([$this->text('a', 'x', ['font' => 'Caveat']), $this->text('b', 'y', ['doc' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'y', 'marks' => [['type' => 'textStyle', 'attrs' => ['fontFamily' => 'Playfair Display']]]]]]]]])]);

        [, $html] = $this->built();

        foreach (['Literata', 'Inter', 'Caveat', 'Playfair Display', 'Public Sans'] as $family) {
            $this->assertStringContainsString("font-family:'$family'", $html, "$family is used");
        }
        foreach (['Space Grotesk', 'Manrope', 'IBM Plex Mono', 'Source Serif 4', 'DM Sans'] as $family) {
            $this->assertStringNotContainsString("font-family:'$family'", $html, "$family is not used");
        }
        $this->assertStringContainsString('url(data:font/woff2;base64,', $html);
        $this->assertDoesNotMatchRegularExpression('#url\((?!data:)[^)]*woff2#', $html, 'no font is loaded by file name');
        $this->assertStringContainsString('font-display:swap', $html);
    }

    public function test_families_used_reads_theme_tokens_element_fonts_and_marks(): void
    {
        $pkg = new PresentPackage($this->dir);
        $slides = [['els' => [['id' => 'a', 'type' => 'text', 'font' => '@heading'], ['id' => 'b', 'type' => 'table', 'font' => 'DM Sans'], ['id' => 'c', 'type' => 'text', 'font' => 'Not A Font']]]];
        $used = $pkg->familiesUsed(['theme' => ['t' => ['heading' => 'Manrope', 'body' => 'Source Serif 4']]], $slides);
        sort($used);
        $this->assertSame(['DM Sans', 'Manrope', 'Public Sans', 'Source Serif 4'], $used);
    }

    public function test_speaker_notes_can_be_left_out(): void
    {
        $this->slide([$this->text('a', 'x')]);

        [$with] = $this->built();
        [$without] = $this->built(notes: false);

        $this->assertSame('private speaker note', $with['slides'][0]['notes']);
        $this->assertSame('', $without['slides'][0]['notes']);
    }

    public function test_a_missing_bundle_is_a_clear_error(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/npm run build/');
        (new PresentPackage($this->dir.'/nowhere'))->build($this->deck);
    }

    public function test_big_rasters_are_scaled_down_when_gd_is_available(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is not installed');
        }
        $im = imagecreatetruecolor(3000, 300);
        mt_srand(7);
        for ($i = 0; $i < 60000; $i++) {
            imagesetpixel($im, mt_rand(0, 2999), mt_rand(0, 299), mt_rand(0, 0xFFFFFF));
        }
        ob_start();
        imagepng($im, null, 0);
        $png = (string) ob_get_clean();
        $this->assertGreaterThan(400 * 1024, strlen($png));

        [$bytes, $mime] = (new PresentPackage($this->dir))->downscale($png, 'image/png');

        $this->assertSame('image/png', $mime);
        $size = getimagesizefromstring($bytes);
        $this->assertSame(PresentPackage::MAX_IMAGE_PX, $size[0]);
        $this->assertLessThan(strlen($png), strlen($bytes));
        // small files and other types are untouched
        $this->assertSame(['tiny', 'image/png'], (new PresentPackage($this->dir))->downscale('tiny', 'image/png'));
        $this->assertSame([$png, 'image/gif'], (new PresentPackage($this->dir))->downscale($png, 'image/gif'));
    }

    public function test_the_endpoint_streams_the_file_for_people_who_can_view_the_deck(): void
    {
        $this->slide([$this->text('a', 'x')]);

        $res = $this->actingAs($this->owner)->get('/api/decks/'.$this->deck->id.'/present-package');
        $res->assertOk();
        $this->assertStringContainsString('text/html', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $res->headers->get('Content-Disposition'));
        $this->assertStringContainsString('.html', $res->headers->get('Content-Disposition'));
        $this->assertStringContainsString('<div id="press-app">', $res->streamedContent());
    }

    public function test_the_endpoint_refuses_outsiders_and_guests(): void
    {
        $this->get('/api/decks/'.$this->deck->id.'/present-package', ['Accept' => 'application/json'])->assertUnauthorized();
        $this->actingAs(User::factory()->withPersonalTeam()->create())->getJson('/api/decks/'.$this->deck->id.'/present-package')->assertNotFound();
    }

    public function test_the_endpoint_says_so_when_the_player_is_not_built(): void
    {
        $this->app->bind(PresentPackage::class, fn () => new PresentPackage($this->dir.'/missing'));
        $this->actingAs($this->owner)->getJson('/api/decks/'.$this->deck->id.'/present-package')->assertStatus(503)->assertJsonPath('message', fn ($m) => str_contains($m, 'npm run build'));
    }
}
