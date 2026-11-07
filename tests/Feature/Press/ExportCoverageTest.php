<?php

namespace Tests\Feature\Press;

use App\Services\Press\Export\ArrayAssetResolver;
use App\Services\Press\Export\ExportPreflight;
use App\Services\Press\Export\Pdf\FontRegistry;
use App\Services\Press\Export\Pdf\PdfExporter;
use App\Services\Press\Export\Pptx\PptxExporter;
use App\Services\Press\Export\Support\ChartData;
use App\Services\Press\Export\Support\Raster;
use App\Services\Press\Export\Support\ShapeGeometry;
use App\Services\Press\Import\PptxImporter;
use DOMDocument;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\Support\PressSampleDeck;
use Tests\TestCase;
use ZipArchive;

/**
 * Every shape kind, mask, chart type and element type goes through both exporters, and hostile or nonsensical values never
 * crash them: whatever the document says, the export either works or fails with a message.
 */
class ExportCoverageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['press_export.font_dir' => storage_path('framework/testing/press-fonts')]);
        FontRegistry::reset();
    }

    private function doc(array $els, ?array $deck = null): array
    {
        return ['deck' => $deck ?? ['id' => 1, 'title' => 'Coverage', 'w' => 1920, 'h' => 1080, 'theme' => null, 'sections' => []], 'slides' => [['id' => 's1', 'title' => 't', 'notes' => '', 'hidden' => false, 'bg' => null, 'transition' => null, 'els' => $els]]];
    }

    /** @return array{0: string, 1: ZipArchive} */
    private function pptx(array $doc): array
    {
        $file = tempnam(sys_get_temp_dir(), 'cov').'.pptx';
        (new PptxExporter(PressSampleDeck::assets()))->render($doc, $file);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($file));
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_ends_with($name, '.xml') || str_ends_with($name, '.rels')) {
                $this->assertTrue(@(new DOMDocument)->loadXML((string) $zip->getFromName($name)), $name.' is not well formed');
            }
        }

        return [$file, $zip];
    }

    private function pdfPages(string $pdf): int
    {
        preg_match_all('#/Type\s*/Page\b(?!s)#', $pdf, $m);

        return count($m[0]);
    }

    public function test_every_shape_kind_is_drawn_by_both_exporters(): void
    {
        $els = [];
        foreach (ShapeGeometry::KINDS as $i => $kind) {
            $els[] = ['id' => 'k'.$i, 'type' => 'shape', 'kind' => $kind, 'x' => 20 + ($i % 8) * 220, 'y' => 20 + intdiv($i, 8) * 200, 'w' => 200, 'h' => 150, 'fill' => '@accent', 'stroke' => '@ink', 'sw' => 3, 'radius' => 16, 'dash' => 'dash', 'ends' => ['s' => 'dot', 'e' => 'arrow'],
                'doc' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $kind]]]]]];
        }
        $doc = $this->doc($els);

        $pdf = (new PdfExporter(new ArrayAssetResolver))->render($doc);
        $this->assertSame(1, $this->pdfPages($pdf));
        [$file, $zip] = $this->pptx($doc);
        $xml = (string) $zip->getFromName('ppt/slides/slide1.xml');
        $this->assertSame(count(ShapeGeometry::KINDS) - 2, substr_count($xml, '<p:sp>'), 'one shape per kind besides the two connectors');
        $this->assertSame(2, substr_count($xml, '<p:cxnSp>'));
        @unlink($file);
    }

    public function test_every_image_mask_fit_and_flip_is_drawn_by_both_exporters(): void
    {
        $els = [];
        $i = 0;
        foreach (ShapeGeometry::MASKS as $mask) {
            foreach (['cover', 'contain', 'fill'] as $fit) {
                $els[] = ['id' => 'm'.$i, 'type' => 'image', 'src' => ['asset' => 1], 'mask' => $mask, 'fit' => $fit, 'flipH' => $i % 2 === 0, 'flipV' => $i % 3 === 0, 'crop' => ['x' => 0.1, 'y' => 0.1, 'w' => 0.7, 'h' => 0.7], 'adj' => ['br' => 10, 'ct' => 10, 'sat' => ($i % 2 ? 30 : 0)], 'x' => 10 + ($i % 7) * 260, 'y' => 10 + intdiv($i, 7) * 260, 'w' => 240, 'h' => 200, 'op' => 0.9];
                $i++;
            }
        }
        $doc = $this->doc($els);

        $this->assertSame(1, $this->pdfPages((new PdfExporter(PressSampleDeck::assets()))->render($doc)));
        [$file, $zip] = $this->pptx($doc);
        $this->assertSame(count($els), substr_count((string) $zip->getFromName('ppt/slides/slide1.xml'), '<p:pic>'));
        $this->assertSame(1, preg_match('/prst="triangle"/', (string) $zip->getFromName('ppt/slides/slide1.xml')));
        @unlink($file);
    }

    public function test_every_chart_type_is_written_by_both_exporters(): void
    {
        $els = [];
        foreach (ChartData::TYPES as $i => $type) {
            $els[] = ['id' => 'c'.$i, 'type' => 'chart', 'x' => 10 + ($i % 4) * 470, 'y' => 10 + intdiv($i, 4) * 300, 'w' => 450, 'h' => 280, 'chart' => ['type' => $type, 'data' => ['categories' => ['A', 'B', 'C', 'D'], 'series' => [['name' => 'S1', 'values' => [4, 7, 3, 9]], ['name' => 'S2', 'values' => [2, 5, 6, 1]]], 'kpis' => [['label' => 'MRR', 'value' => 12]], 'regions' => [['code' => 'ZA', 'value' => 3]], 'totals' => [3]], 'opt' => ['title' => $type]]];
        }
        $doc = $this->doc($els);

        $this->assertSame(1, $this->pdfPages((new PdfExporter(new ArrayAssetResolver))->render($doc)));
        [$file, $zip] = $this->pptx($doc);
        $charts = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (preg_match('#^ppt/charts/chart\d+\.xml$#', (string) $zip->getNameIndex($i))) {
                $charts++;
            }
        }
        // 14 types: kpi becomes text boxes and map a table, the other twelve are native charts
        $this->assertSame(12, $charts);
        $xml = (string) $zip->getFromName('ppt/slides/slide1.xml');
        $this->assertStringContainsString('name="KPI 1"', $xml);
        $this->assertStringContainsString('<a:tbl>', $xml);
        @unlink($file);
        // and the native charts read back as charts
        $back = PptxImporter::import($this->roundTripFile($doc));
        $types = array_map(fn ($e) => $e['chart']['type'] ?? $e['type'], $back->slides[0]['els']);
        foreach (['column', 'bar', 'line', 'area', 'pie', 'doughnut', 'scatter', 'bubble', 'radar'] as $t) {
            $this->assertContains($t, $types, $t.' did not survive the round trip');
        }
    }

    private function roundTripFile(array $doc): string
    {
        $file = tempnam(sys_get_temp_dir(), 'rt').'.pptx';
        (new PptxExporter(PressSampleDeck::assets()))->render($doc, $file);

        return $file;
    }

    public function test_text_variants_are_written_by_both_exporters(): void
    {
        $els = [];
        $i = 0;
        foreach (['top', 'middle', 'bottom'] as $valign) {
            foreach (['left', 'center', 'right', 'justify'] as $align) {
                foreach (['none', 'shrink'] as $fit) {
                    foreach (['none', 'outline', 'highlight'] as $fx) {
                        $els[] = ['id' => 't'.$i, 'type' => 'text', 'x' => 10 + ($i % 6) * 310, 'y' => 10 + intdiv($i, 6) * 140, 'w' => 300, 'h' => 130, 'valign' => $valign, 'align' => $align, 'fit' => $fit, 'fx' => $fx, 'size' => 22, 'ls' => 0.05, 'lh' => 1.4, 'ps' => 0.5, 'pad' => 6, 'fill' => $i % 2 ? '@surface' : null, 'stroke' => $i % 3 ? null : '@line', 'radius' => 8,
                            'doc' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => "$valign $align $fit $fx text that wraps over several lines in this box"]]]]]];
                        $i++;
                    }
                }
            }
        }
        $doc = $this->doc($els);

        $this->assertSame(1, $this->pdfPages((new PdfExporter(new ArrayAssetResolver))->render($doc)));
        [$file, $zip] = $this->pptx($doc);
        $xml = (string) $zip->getFromName('ppt/slides/slide1.xml');
        foreach (['anchor="t"', 'anchor="ctr"', 'anchor="b"', 'algn="just"', 'algn="ctr"', 'algn="r"', '<a:normAutofit', '<a:noAutofit/>', '<a:highlight>', 'spc="'] as $needle) {
            $this->assertStringContainsString($needle, $xml);
        }
        @unlink($file);
    }

    public function test_a_server_without_an_image_library_still_exports_with_flat_colours_and_no_pictures(): void
    {
        Raster::forceAvailability(false);
        try {
            $doc = PressSampleDeck::doc();
            $pdf = new PdfExporter(PressSampleDeck::assets());
            $bytes = $pdf->render($doc);
            $this->assertSame(4, $this->pdfPages($bytes));
            $this->assertNotEmpty(array_filter($pdf->notes, fn ($n) => str_contains($n['note'], 'could not be read')), 'pictures are reported as unreadable');
            [$file, $zip] = $this->pptx($doc);
            $this->assertNotFalse($zip->locateName('ppt/slides/slide1.xml'));
            @unlink($file);
            $pre = ExportPreflight::analyze($doc, 'pdf', [], PressSampleDeck::assets());
            $levels = [];
            foreach ($pre['slides'] as $s) {
                foreach ($s['elements'] as $e) {
                    $levels[$s['id'].'/'.$e['id']] = $e['level'];
                }
            }
            $this->assertSame('dropped', $levels['s2/b3']);
            $this->assertSame('approximated', $levels['s3/h2'], 'the gradient is its first colour');
        } finally {
            Raster::forceAvailability(null);
        }
    }

    /** @return array<int|string, mixed> */
    private function garbage(Randomizer $r, int $depth = 0): mixed
    {
        $pool = [null, true, false, 0, -1, 1.5, 99999999, 'x', '', '@accent', '#12', '#zzzzzz', 'javascript:alert(1)', "\0", '<b>', str_repeat('y', 300), 1e300, -1e300, [], [[]], ['a' => 1], ['type' => 'text'], ['asset' => 'no'], ['g' => 'linear', 'stops' => 'bad']];
        if ($depth < 2 && $r->getInt(0, 4) === 0) {
            return [$this->garbage($r, $depth + 1), 'k' => $this->garbage($r, $depth + 1)];
        }

        return $pool[$r->getInt(0, count($pool) - 1)];
    }

    public function test_nonsense_values_never_crash_either_exporter(): void
    {
        $r = new Randomizer(new Mt19937(12345));
        $keys = ['x', 'y', 'w', 'h', 'rot', 'op', 'name', 'alt', 'link', 'font', 'size', 'weight', 'color', 'align', 'valign', 'lh', 'ls', 'ps', 'fit', 'pad', 'fx', 'fill', 'stroke', 'sw', 'radius', 'kind', 'dash', 'ends', 'doc', 'src', 'crop', 'mask', 'adj', 'flipH', 'flipV', 'cap', 'embed', 'poster', 'chart', 'rows', 'colw', 'rowh', 'header', 'band', 'lines', 'shadow', 'group', 'role', 'name', 'anim'];
        $types = ['text', 'shape', 'image', 'video', 'audio', 'icon', 'chart', 'table', 'nope', 7, null];
        $failures = [];
        for ($round = 0; $round < 40; $round++) {
            $els = [];
            for ($n = 0; $n < 12; $n++) {
                $el = ['id' => 'e'.$n, 'type' => $types[$r->getInt(0, count($types) - 1)]];
                for ($k = 0; $k < $r->getInt(1, 9); $k++) {
                    $el[$keys[$r->getInt(0, count($keys) - 1)]] = $this->garbage($r);
                }
                $els[] = $el;
            }
            $doc = $this->doc($els, ['id' => 1, 'title' => $this->garbage($r), 'w' => $this->garbage($r), 'h' => $this->garbage($r), 'theme' => $this->garbage($r), 'sections' => $this->garbage($r)]);
            $doc['slides'][0]['bg'] = $this->garbage($r);
            $doc['slides'][0]['transition'] = $this->garbage($r);
            $doc['slides'][0]['notes'] = $this->garbage($r);
            try {
                (new PdfExporter(PressSampleDeck::assets()))->render($doc);
            } catch (\RuntimeException) {
                // a readable refusal is allowed
            } catch (\Throwable $e) {
                $failures[] = "pdf round $round: ".$e::class.': '.$e->getMessage().' at '.basename($e->getFile()).':'.$e->getLine();
            }
            $file = tempnam(sys_get_temp_dir(), 'fz').'.pptx';
            try {
                (new PptxExporter(PressSampleDeck::assets()))->render($doc, $file);
                $zip = new ZipArchive;
                $zip->open($file);
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = (string) $zip->getNameIndex($i);
                    if (str_ends_with($name, '.xml') || str_ends_with($name, '.rels')) {
                        if (! @(new DOMDocument)->loadXML((string) $zip->getFromName($name))) {
                            $failures[] = "pptx round $round: $name is not well formed";
                        }
                    }
                }
            } catch (\RuntimeException) {
            } catch (\Throwable $e) {
                $failures[] = "pptx round $round: ".$e::class.': '.$e->getMessage().' at '.basename($e->getFile()).':'.$e->getLine();
            }
            @unlink($file);
        }

        $this->assertSame([], $failures);
    }
}
