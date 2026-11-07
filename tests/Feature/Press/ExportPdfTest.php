<?php

namespace Tests\Feature\Press;

use App\Services\Press\Export\ArrayAssetResolver;
use App\Services\Press\Export\Pdf\FontRegistry;
use App\Services\Press\Export\Pdf\PdfExporter;
use RuntimeException;
use Tests\Support\PressPython;
use Tests\Support\PressSampleDeck;
use Tests\TestCase;

class ExportPdfTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['press_export.font_dir' => storage_path('framework/testing/press-fonts')]);
        FontRegistry::reset();
    }

    private function render(?array $doc = null, array $options = [], ?ArrayAssetResolver $assets = null): string
    {
        return (new PdfExporter($assets ?? PressSampleDeck::assets()))->render($doc ?? PressSampleDeck::doc(), $options);
    }

    /** Number of page objects and the first page's MediaBox, read straight from the file. @return array{0: int, 1: list<float>} */
    private function pages(string $pdf): array
    {
        preg_match_all('#/Type\s*/Page\b(?!s)#', $pdf, $m);
        preg_match('#/MediaBox\s*\[\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s*\]#', $pdf, $b);

        return [count($m[0]), array_map('floatval', array_slice($b, 1))];
    }

    public function test_one_page_per_visible_slide_sized_exactly_to_the_slide(): void
    {
        $pdf = $this->render();

        $this->assertStringStartsWith('%PDF-', $pdf);
        [$count, $box] = $this->pages($pdf);
        $this->assertSame(4, $count, 'the hidden slide is skipped');
        $this->assertSame([0.0, 0.0, 1920 * 0.75, 1080 * 0.75], $box);
    }

    public function test_hidden_slides_and_speaker_notes_pages_are_optional(): void
    {
        $this->assertSame(5, $this->pages($this->render(null, ['include_hidden' => true]))[0]);
        // slides 1 and 3 have notes (slide 5 too, but it is hidden and not requested)
        $this->assertSame(6, $this->pages($this->render(null, ['notes' => true]))[0]);
        $this->assertSame(8, $this->pages($this->render(null, ['notes' => true, 'include_hidden' => true]))[0]);
    }

    public function test_a_legacy_deck_size_keeps_its_own_page_size(): void
    {
        $doc = PressSampleDeck::doc(1280, 720);
        [$count, $box] = $this->pages($this->render($doc));

        $this->assertSame(4, $count);
        $this->assertSame([0.0, 0.0, 960.0, 540.0], $box);
    }

    public function test_a_selection_of_slides_can_be_exported(): void
    {
        $this->assertSame(2, $this->pages($this->render(null, ['slides' => ['s1', 's3']]))[0]);
    }

    public function test_a_deck_with_nothing_to_export_fails_with_a_readable_message(): void
    {
        $doc = PressSampleDeck::doc();
        $doc['slides'] = [];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no slides');
        $this->render($doc);
    }

    public function test_python_reads_page_sizes_text_and_embedded_subset_fonts(): void
    {
        if (PressPython::bin() === null) {
            $this->markTestSkipped('pypdf is not installed (see tests/Support/PressPython.php).');
        }
        $file = tempnam(sys_get_temp_dir(), 'pdf').'.pdf';
        file_put_contents($file, $this->render(null, ['notes' => true]));
        $info = PressPython::inspect('inspect_pdf.py', $file);
        @unlink($file);

        $this->assertCount(6, $info['pages']);
        foreach ($info['pages'] as $p) {
            $this->assertEqualsWithDelta(1440.0, $p['w'], 0.01);
            $this->assertEqualsWithDelta(810.0, $p['h'], 0.01);
        }
        $this->assertStringContainsString('Quarterly review, Q3', $info['pages'][0]['text']);
        $this->assertStringContainsString('What moved, what stalled', $info['pages'][0]['text']);
        $this->assertStringContainsString('Open warm.', $info['pages'][1]['text']); // the notes page
        $this->assertStringContainsString('Revenue grew', $info['pages'][2]['text']);
        $this->assertStringContainsString('18%', $info['pages'][2]['text']);
        $this->assertStringContainsString('Say this out loud', $info['pages'][3]['text']);
        $this->assertStringContainsString('Speaker notes for slide 3', $info['pages'][4]['text']);
        $this->assertStringContainsString('Revenue and cost', $info['pages'][5]['text']);
        $this->assertStringContainsString('48,200', $info['pages'][5]['text']);
        $joined = implode(' ', $info['fonts']);
        $this->assertStringContainsString('PublicSans', $joined);
        $this->assertLessThan(1_500_000, $info['size'], 'font subsetting keeps the file small');
    }

    public function test_text_is_escaped_unsafe_links_and_fonts_cannot_inject_markup(): void
    {
        $doc = PressSampleDeck::doc();
        $evil = '<img src=x onerror=alert(1)></div><style>*{display:none}</style>';
        $doc['slides'] = [['id' => 'e', 'title' => 'x', 'notes' => $evil, 'els' => [
            ['id' => 'a', 'type' => 'text', 'x' => 10, 'y' => 10, 'w' => 800, 'h' => 200, 'font' => "Inter'; } body { display:none } /*", 'link' => 'javascript:alert(1)',
                'doc' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
                    ['type' => 'text', 'text' => $evil, 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']], ['type' => 'textStyle', 'attrs' => ['color' => 'red;position:fixed', 'fontFamily' => "x'</style><script>"]]]],
                ]]]]],
        ]]];
        $exporter = new PdfExporter(new ArrayAssetResolver);
        $pdf = $exporter->render($doc, ['notes' => true]);

        $this->assertSame(2, $this->pages($pdf)[0]);
        $this->assertStringNotContainsString('javascript:', $pdf);
        $this->assertStringNotContainsString('onerror', $pdf);
    }

    public function test_images_with_masks_adjustments_and_crops_survive_and_missing_ones_become_a_labelled_box(): void
    {
        $exporter = new PdfExporter(new ArrayAssetResolver);
        $pdf = $exporter->render(PressSampleDeck::doc());

        $this->assertSame(4, $this->pages($pdf)[0]);
        $this->assertNotEmpty(array_filter($exporter->notes, fn ($n) => $n['element'] === 'b3' && str_contains($n['note'], 'could not be read')));
        $with = new PdfExporter(PressSampleDeck::assets());
        $with->render(PressSampleDeck::doc());
        $this->assertEmpty(array_filter($with->notes, fn ($n) => $n['element'] === 'b3'));
    }

    public function test_every_chart_type_in_the_spec_draws(): void
    {
        $types = ['bar', 'column', 'line', 'area', 'pie', 'doughnut', 'scatter', 'bubble', 'waterfall', 'funnel', 'radar', 'combo', 'kpi', 'map'];
        $els = [];
        foreach ($types as $i => $t) {
            $els[] = ['id' => 'c'.$i, 'type' => 'chart', 'x' => 20 + ($i % 4) * 470, 'y' => 20 + intdiv($i, 4) * 300, 'w' => 450, 'h' => 280, 'chart' => [
                'type' => $t,
                'data' => ['categories' => ['A', 'B', 'C', 'D'], 'series' => [['name' => 'S1', 'values' => [4, 7, 3, 9], 'points' => [['x' => 1, 'y' => 2, 'r' => 3], ['x' => 2, 'y' => 5, 'r' => 6]]], ['name' => 'S2', 'values' => [2, 5, 6, 1], 'kind' => 'line']],
                    'kpis' => [['label' => 'MRR', 'value' => 1200]], 'regions' => [['code' => 'ZA', 'value' => 5]], 'totals' => [3]],
                'opt' => ['title' => ucfirst($t), 'labels' => 'value']]];
        }
        $doc = ['deck' => ['id' => 1, 'title' => 't', 'w' => 1920, 'h' => 1080, 'theme' => null], 'slides' => [['id' => 's', 'title' => 'c', 'els' => $els]]];
        $exporter = new PdfExporter(new ArrayAssetResolver);
        $pdf = $exporter->render($doc);

        $this->assertSame(1, $this->pages($pdf)[0]);
        $this->assertSame(['map'], array_values(array_map(fn ($n) => str_contains($n['note'], 'map') ? 'map' : 'other', $exporter->notes)));
    }
}
