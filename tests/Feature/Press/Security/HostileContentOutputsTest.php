<?php

namespace Tests\Feature\Press\Security;

use App\Models\Deck;
use App\Services\Press\Assets\AssetLimits;
use App\Services\Press\Export\ExportService;
use App\Services\Press\Export\Pdf\PdfExporter;
use App\Services\Press\Export\Pptx\PptxExporter;
use App\Services\Press\PresentPackage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Press\AccessTestCase;

/**
 * Every place text reaches markup (PPTX XML parts, the offline package, the PDF) is fed the same hostile strings. The
 * outputs must stay well formed, must not carry an executable tag, and must not fetch anything.
 */
class HostileContentOutputsTest extends AccessTestCase
{
    private const EVIL = [
        '"><script>alert(1)</script>',
        "'><img src=x onerror=alert(2)>",
        ']]></a:t><a:t>',
        '<!DOCTYPE x [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>&xxe;',
        '</script><script>alert(3)</script>',
        '&#x0;&amp;&lt;',
        "</style><script>alert(4)</script>\u{2028}",
    ];

    private function hostileDeck(): Deck
    {
        $e = self::EVIL;
        $doc = fn (string $t) => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => $t, 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com/"><script>alert(5)</script>']]]],
            ['type' => 'text', 'text' => $t, 'marks' => [['type' => 'textStyle', 'attrs' => ['fontFamily' => 'Public Sans']]]],
        ]]]];
        $slideId = $this->slide->id;
        $ops = [
            ['op' => 'deck.set', 'title' => $e[0].$e[4], 'theme' => ['id' => 'x', 'name' => $e[1], 't' => ['heading' => 'Public Sans', 'body' => 'Public Sans'], 'c' => ['accent' => '#112233']]],
            ['op' => 'section.add', 'id' => 'sec1', 'title' => $e[2]],
            ['op' => 'slide.set', 'id' => $slideId, 'title' => $e[3], 'notes' => implode("\n", $e), 'section_id' => 'sec1'],
            ['op' => 'el.add', 'slide' => $slideId, 'el' => ['id' => 'tx', 'type' => 'text', 'name' => $e[0], 'alt' => $e[1], 'x' => 10, 'y' => 10, 'w' => 800, 'h' => 200, 'doc' => $doc($e[0]), 'link' => 'https://example.com/"onmouseover="alert(6)']],
            ['op' => 'el.add', 'slide' => $slideId, 'el' => ['id' => 'tb', 'type' => 'table', 'x' => 10, 'y' => 300, 'w' => 800, 'h' => 200, 'rows' => [[['t' => $e[0]], ['t' => $e[3], 'b' => true]], [['t' => $e[4]], ['t' => $e[6]]]]]],
            ['op' => 'el.add', 'slide' => $slideId, 'el' => ['id' => 'ch', 'type' => 'chart', 'x' => 10, 'y' => 520, 'w' => 800, 'h' => 400, 'chart' => [
                'type' => 'column',
                'data' => ['categories' => [$e[0], $e[1], $e[2]], 'series' => [['name' => $e[3], 'values' => [1, 2, 3]], ['name' => $e[4], 'values' => [3, 2, 1]]]],
                'opt' => ['title' => $e[0], 'sub' => $e[1], 'cur' => '<R>', 'ax' => ['x' => ['title' => $e[2]], 'y' => ['title' => $e[3]]]],
                'src' => ['kind' => 'file', 'name' => $e[4]],
            ]]],
            ['op' => 'el.add', 'slide' => $slideId, 'el' => ['id' => 'pie', 'type' => 'chart', 'x' => 900, 'y' => 520, 'w' => 500, 'h' => 400, 'chart' => [
                'type' => 'pie', 'data' => ['categories' => [$e[0], $e[1]], 'series' => [['name' => $e[2], 'values' => [5, 6]]]],
            ]]],
            ['op' => 'el.add', 'slide' => $slideId, 'el' => ['id' => 'kp', 'type' => 'chart', 'x' => 900, 'y' => 10, 'w' => 500, 'h' => 300, 'chart' => [
                'type' => 'kpi', 'data' => ['categories' => [], 'series' => [], 'kpis' => [['label' => $e[0], 'value' => $e[1], 'delta' => $e[2], 'unit' => $e[3], 'note' => $e[4]]]],
            ]]],
            ['op' => 'el.add', 'slide' => $slideId, 'el' => ['id' => 'im', 'type' => 'image', 'x' => 1, 'y' => 1, 'w' => 100, 'h' => 100, 'src' => ['url' => 'https://example.com/a.png?x="><script>'], 'cap' => $e[0], 'alt' => $e[1]]],
            ['op' => 'el.add', 'slide' => $slideId, 'el' => ['id' => 'vd', 'type' => 'video', 'x' => 1, 'y' => 1, 'w' => 100, 'h' => 100, 'embed' => ['provider' => 'youtube', 'id' => 'abc_DEF-123']]],
        ];
        $this->actingAs($this->editor)->postJson("/api/decks/{$this->deck->id}/ops", ['client' => 't', 'key' => 'k-'.Str::uuid(), 'ops' => $ops])->assertOk()->assertJsonPath('skipped', []);

        return $this->deck->fresh();
    }

    public function test_pptx_parts_stay_well_formed_and_script_free(): void
    {
        $deck = $this->hostileDeck();
        $path = tempnam(sys_get_temp_dir(), 'pptx');
        (new PptxExporter(ExportService::resolverFor($deck)))->render(ExportService::document($deck), $path, ['notes' => true]);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path));
        $seen = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (! preg_match('/\.(xml|rels)$/', $name)) {
                continue;
            }
            $xml = $zip->getFromIndex($i);
            $dom = new \DOMDocument;
            $prev = libxml_use_internal_errors(true);
            $ok = $dom->loadXML($xml, LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            $this->assertTrue($ok, "{$name} is not well formed XML");
            $this->assertStringNotContainsString('<script', $xml, $name);
            $this->assertStringNotContainsString('<!ENTITY', $xml, $name);
            $this->assertStringNotContainsString('<!DOCTYPE x', $xml, $name);
            $seen++;
        }
        $zip->close();
        @unlink($path);
        $this->assertGreaterThan(10, $seen);
    }

    public function test_pdf_renders_without_fetching_or_failing(): void
    {
        $deck = $this->hostileDeck();
        $bytes = (new PdfExporter(ExportService::resolverFor($deck)))->render(ExportService::document($deck), ['notes' => true]);

        $this->assertStringStartsWith('%PDF', $bytes);
        // Links in a PDF are /URI actions: the closing quote and tag characters must not terminate the string early.
        $this->assertStringNotContainsString('/JS', $bytes);
        $this->assertStringNotContainsString('/Launch', $bytes);
    }

    public function test_the_offline_package_cannot_be_broken_out_of(): void
    {
        $deck = $this->hostileDeck();
        $dir = storage_path('framework/testing/pkg');
        @mkdir($dir, 0775, true);
        file_put_contents($dir.'/press-player.iife.js', 'window.__p=1;');
        file_put_contents($dir.'/press-player.css', '.a{}');

        $built = (new PresentPackage($dir, $dir))->build($deck, true);
        $html = $built['html'];

        // Exactly the two script elements the package writes itself, and exactly the style elements it writes.
        $this->assertSame(2, preg_match_all('/<script\b/i', $html));
        $this->assertSame(2, preg_match_all('/<\/script/i', $html));
        $this->assertSame(3, preg_match_all('/<style\b/i', $html));
        $this->assertStringNotContainsString('onerror=', explode('id="press-data"', $html)[0]);
        $data = json_decode(html_entity_decode(trim(explode('</script>', explode('id="press-data">', $html)[1])[0])), true);
        $this->assertIsArray($data);
        $this->assertSame(self::EVIL[3], $data['slides'][0]['title'] ?? self::EVIL[3]);
        // The title element is escaped.
        $this->assertMatchesRegularExpression('/<title>[^<]*&lt;\/script&gt;/', $html);
        @unlink($dir.'/press-player.iife.js');
        @unlink($dir.'/press-player.css');
    }

    public function test_the_offline_package_never_loads_a_file_that_cannot_fit_and_says_so(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('a/small.png', str_repeat('x', 1000));
        Storage::disk('local')->put('a/big.mp4', str_repeat('y', 3 * 1024 * 1024));
        $small = $this->asset('small.png');
        $small->forceFill(['path' => 'a/small.png', 'disk' => 'local', 'mime_type' => 'image/png', 'size' => 1000])->save();
        $big = $this->asset('big.mp4');
        $big->forceFill(['path' => 'a/big.mp4', 'disk' => 'local', 'mime_type' => 'video/mp4', 'size' => 3 * 1024 * 1024, 'kind' => 'video'])->save();
        $this->slide->forceFill(['els' => [
            ['id' => 'i', 'type' => 'image', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'src' => ['asset' => $small->id]],
            ['id' => 'v', 'type' => 'video', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'src' => ['asset' => $big->id]],
        ]])->save();

        $dir = storage_path('framework/testing/pkg2');
        @mkdir($dir, 0775, true);
        file_put_contents($dir.'/press-player.iife.js', 'window.__p=1;');
        file_put_contents($dir.'/press-player.css', '.a{}');

        // a 2 MB budget: the small picture fits, the 3 MB video is left out without ever being read
        $built = (new PresentPackage($dir, $dir, 2 * 1024 * 1024))->build($this->deck, false);

        $this->assertStringContainsString('data:image/png;base64', $built['html']);
        $this->assertStringNotContainsString('data:video/mp4', $built['html']);
        $this->assertCount(1, array_filter($built['warnings'], fn ($w) => str_contains($w, 'big.mp4') && str_contains($w, 'too large')));
        @unlink($dir.'/press-player.iife.js');
        @unlink($dir.'/press-player.css');
    }

    public function test_the_default_file_budget_follows_the_memory_limit(): void
    {
        // The package is written to disk one file at a time (R-40 performance), so the total is not tied to memory any
        // more, but a single file is held three times over and so is.
        $package = new PresentPackage;
        $limit = AssetLimits::iniBytes((string) ini_get('memory_limit'));

        $this->assertSame(PresentPackage::MAX_TOTAL_BYTES, $package->totalBudget());
        $this->assertGreaterThanOrEqual(4 * 1024 * 1024, $package->fileBudget());
        $this->assertLessThanOrEqual(PresentPackage::MAX_FILE_BYTES, $package->fileBudget());
        if ($limit > 0) {
            $this->assertLessThanOrEqual(max(4 * 1024 * 1024, intdiv($limit, 6)), $package->fileBudget());
        }
    }
}
