<?php

namespace Tests\Feature\Press\Performance;

use App\Models\Project;
use App\Services\Press\Bench\DeckGenerator;
use App\Services\Press\Export\ExportService;
use App\Services\Press\Export\Pdf\FontRegistry;
use App\Services\Press\Export\Pdf\PdfExporter;
use App\Services\Press\Export\Pdf\PdfMerger;
use App\Services\Press\Export\Pptx\Package;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Feature\Press\MakesDecks;
use Tests\Support\PressPython;
use Tests\Support\PressSampleDeck;
use Tests\TestCase;
use ZipArchive;

/**
 * Exports that stay inside the memory limit: a deck is drawn in parts and joined, pictures go to disk while the PPTX is
 * assembled, and what a limit cannot hold is refused in words before anything is read.
 */
class ExportMemoryTest extends TestCase
{
    use MakesDecks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['press_export.font_dir' => storage_path('framework/testing/press-fonts')]);
        FontRegistry::reset();
    }

    private function pages(string $pdf): int
    {
        preg_match_all('#/Type\s*/Page\b(?!s)#', $pdf, $m);

        return count($m[0]);
    }

    /** @return array<string, mixed> */
    private function document(int $slides): array
    {
        $gen = new DeckGenerator([1, 2]);
        $out = [];
        for ($i = 1; $i <= $slides; $i++) {
            $s = $gen->slide($i);
            $s['id'] = 's'.$i;
            $s['hidden'] = false;
            $s['els'] = array_slice($s['els'], 0, 14);
            $out[] = $s;
        }

        return ['deck' => ['title' => 'Parts', 'w' => 1920, 'h' => 1080], 'slides' => $out];
    }

    public function test_a_deck_over_the_part_budget_is_drawn_in_parts_and_has_every_page_and_all_its_text(): void
    {
        $doc = $this->document(9);
        $resolver = PressSampleDeck::assets();

        config(['press_export.pdf_part_budget_mb' => 150]);
        $whole = (new PdfExporter($resolver))->render($doc);

        config(['press_export.pdf_part_budget_mb' => 4]); // a few slides at a time
        $parts = (new PdfExporter($resolver))->render($doc);

        $this->assertStringStartsWith('%PDF-', $parts);
        $this->assertSame(9, $this->pages($whole));
        $this->assertSame(9, $this->pages($parts));
        $this->assertNotSame($whole, $parts, 'it really was drawn in more than one part');

        if (PressPython::bin() !== null) {
            $dir = storage_path('framework/testing');
            @mkdir($dir, 0775, true);
            file_put_contents($file = $dir.'/parts.pdf', $parts);
            file_put_contents($ref = $dir.'/whole.pdf', $whole);
            $a = PressPython::inspect('inspect_pdf.py', $file);
            $b = PressPython::inspect('inspect_pdf.py', $ref);
            $this->assertCount(9, $a['pages']);
            $this->assertSame(array_column($b['pages'], 'text'), array_column($a['pages'], 'text'), 'the same text on the same pages');
            $this->assertSame(array_column($b['pages'], 'w'), array_column($a['pages'], 'w'));
            @unlink($file);
            @unlink($ref);
        }
    }

    public function test_parts_are_joined_in_order_with_their_links_and_resources_and_the_file_is_well_formed(): void
    {
        $a = (new PdfExporter(PressSampleDeck::assets()))->render(['deck' => ['title' => 'A', 'w' => 1920, 'h' => 1080], 'slides' => [
            ['id' => 's1', 'title' => 'a', 'els' => [['id' => 'e1', 'type' => 'text', 'x' => 100, 'y' => 100, 'w' => 600, 'h' => 100, 'link' => '#slide:s2', 'doc' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'First']]]]]]]],
            ['id' => 's2', 'title' => 'b', 'els' => [['id' => 'e2', 'type' => 'text', 'x' => 100, 'y' => 100, 'w' => 600, 'h' => 100, 'doc' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Second']]]]]]]],
        ]]);
        $b = (new PdfExporter(PressSampleDeck::assets()))->render(['deck' => ['title' => 'B', 'w' => 1920, 'h' => 1080], 'slides' => [
            ['id' => 's3', 'title' => 'c', 'els' => [['id' => 'e3', 'type' => 'text', 'x' => 100, 'y' => 100, 'w' => 600, 'h' => 100, 'doc' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Third']]]]]]]],
        ]]);

        $merged = PdfMerger::merge([$a, $b]);

        $this->assertSame(3, $this->pages($merged));
        $this->assertMatchesRegularExpression('/startxref\s+\d+\s+%%EOF\s*$/', $merged);
        // the cross reference table points at real objects
        preg_match('/startxref\s+(\d+)/', $merged, $x);
        $this->assertStringStartsWith('xref', substr($merged, (int) $x[1], 4));

        if (PressPython::bin() !== null) {
            file_put_contents($file = storage_path('framework/testing/merged.pdf'), $merged);
            $info = PressPython::inspect('inspect_pdf.py', $file);
            @unlink($file);
            $this->assertCount(3, $info['pages']);
            $this->assertStringContainsString('First', $info['pages'][0]['text']);
            $this->assertStringContainsString('Second', $info['pages'][1]['text']);
            $this->assertStringContainsString('Third', $info['pages'][2]['text']);
        }
        $this->assertSame($a, PdfMerger::merge([$a]), 'one part is returned as it is');
    }

    public function test_the_merger_refuses_what_it_does_not_understand(): void
    {
        $this->expectException(RuntimeException::class);
        PdfMerger::merge(['%PDF-1.4 not really', '%PDF-1.4 not either']);
    }

    public function test_a_link_between_parts_is_reported_not_silently_lost(): void
    {
        $doc = $this->document(6);
        $doc['slides'][0]['els'][0]['link'] = '#slide:s6';
        config(['press_export.pdf_part_budget_mb' => 2]);

        $exporter = new PdfExporter(PressSampleDeck::assets());
        $exporter->render($doc);

        $notes = collect($exporter->notes)->filter(fn ($n) => str_contains($n['note'], 'link to another slide'));
        $this->assertCount(1, $notes);
        $this->assertSame(1, $notes->first()['slide']);
    }

    public function test_a_deck_the_memory_limit_cannot_hold_is_refused_in_words_before_it_is_read(): void
    {
        $user = $this->owner();
        $project = Project::firstOrCreate(['team_id' => $user->currentTeam->id, 'name' => 'W'], ['user_id' => $user->id, 'slug' => 'w']);
        $deck = (new DeckGenerator([1]))->createDeck($project, $user, 'Dense', 60);

        try {
            ExportService::assertFits($deck, 'pdf', null, 64 * 1048576);
            $this->fail('A 64 MB limit cannot hold a dense 60 slide deck.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('too large to export as PDF', $e->getMessage());
            $this->assertMatchesRegularExpression('/About \d+ of its 60 slides fit/', $e->getMessage());
            $this->assertStringContainsString('memory limit', $e->getMessage());
        }

        ExportService::assertFits($deck, 'pdf', null, 256 * 1048576);
        ExportService::assertFits($deck, 'pdf', null, -1);
        $this->addToAssertionCount(2);
    }

    public function test_the_pptx_package_keeps_big_parts_on_disk_until_the_zip_is_written(): void
    {
        $before = glob(sys_get_temp_dir().'/pxp*') ?: [];
        $pkg = new Package;
        $big = str_repeat('x', Package::SPILL_BYTES + 100);
        $pkg->add('ppt/media/big.png', $big, 'image/png');
        $pkg->add('ppt/slides/slide1.xml', '<a/>', 'application/xml');

        $this->assertTrue($pkg->has('ppt/media/big.png'));
        $during = array_diff(glob(sys_get_temp_dir().'/pxp*') ?: [], $before);
        $this->assertCount(1, $during, 'the big part is a file, the small one stays in memory');

        $file = storage_path('framework/testing/spill.pptx');
        @mkdir(dirname($file), 0775, true);
        $pkg->save($file);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($file));
        $this->assertSame($big, $zip->getFromName('ppt/media/big.png'));
        $this->assertSame('<a/>', $zip->getFromName('ppt/slides/slide1.xml'));
        $zip->close();
        @unlink($file);
        $this->assertSame([], array_diff(glob(sys_get_temp_dir().'/pxp*') ?: [], $before), 'the temporary files are removed once the zip is written');
    }

    public function test_the_exports_api_does_not_load_the_slides_to_decide_how_to_run(): void
    {
        Storage::fake('local');
        $user = $this->owner();
        $deck = $this->deckFor($user, 'Small', 2);

        $this->assertTrue(ExportService::isSmallDeck($deck));
        DB::table('slides')->where('deck_id', $deck->id)->limit(1)->update(['els' => '[{"id":"v","type":"video","x":0,"y":0,"w":1,"h":1,"src":{"url":"https://example.com/v.mp4"}}]']);
        $this->assertFalse(ExportService::isSmallDeck($deck), 'a deck with video goes to the queue');
        $this->assertSame(2, ExportService::slideCount($deck));
    }
}
