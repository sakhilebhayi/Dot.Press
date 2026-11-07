<?php

namespace Tests\Feature\Press;

use App\Services\Press\Export\Pptx\PptxExporter;
use DOMDocument;
use Tests\Support\PressPython;
use Tests\Support\PressSampleDeck;
use Tests\TestCase;
use ZipArchive;

class ExportPptxTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = tempnam(sys_get_temp_dir(), 'pptx').'.pptx';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    private function export(?array $doc = null, array $options = []): PptxExporter
    {
        $x = new PptxExporter(PressSampleDeck::assets());
        $x->render($doc ?? PressSampleDeck::doc(), $this->file, $options);

        return $x;
    }

    public function test_every_part_is_well_formed_xml_and_every_relationship_target_exists(): void
    {
        $this->export();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($this->file));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $this->assertSame('[Content_Types].xml', $names[0]);
        $types = new DOMDocument;
        $types->loadXML($zip->getFromName('[Content_Types].xml'));
        $defaults = [];
        $overrides = [];
        foreach ($types->documentElement->childNodes as $n) {
            if ($n->localName === 'Default') {
                $defaults[] = $n->getAttribute('Extension');
            } else {
                $overrides[] = ltrim($n->getAttribute('PartName'), '/');
            }
        }
        foreach ($names as $name) {
            if (str_ends_with($name, '.xml') || str_ends_with($name, '.rels')) {
                $dom = new DOMDocument;
                $this->assertTrue(@$dom->loadXML($zip->getFromName($name)), $name.' is not well formed XML');
            }
            if ($name === '[Content_Types].xml') {
                continue;
            }
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $this->assertTrue(in_array($name, $overrides, true) || in_array($ext, $defaults, true), $name.' has no content type');
        }
        foreach ($overrides as $part) {
            $this->assertContains($part, $names, 'Content type override for a missing part '.$part);
        }
        foreach ($names as $name) {
            if (! str_ends_with($name, '.rels')) {
                continue;
            }
            $owner = $name === '_rels/.rels' ? '' : preg_replace('#(^|/)_rels/([^/]+)\.rels$#', '$1$2', $name);
            $dir = ($owner === '' || dirname($owner) === '.') ? '' : dirname($owner).'/';
            $rels = new DOMDocument;
            $rels->loadXML($zip->getFromName($name));
            foreach ($rels->documentElement->childNodes as $r) {
                if ($r->getAttribute('TargetMode') === 'External') {
                    $this->assertMatchesRegularExpression('#^https://#', $r->getAttribute('Target'));

                    continue;
                }
                $target = $r->getAttribute('Target');
                $resolved = $this->resolve($dir, $target);
                $this->assertContains($resolved, $names, $name.' points at a missing part '.$target);
            }
        }
        $zip->close();
    }

    private function resolve(string $dir, string $target): string
    {
        if (str_starts_with($target, '/')) {
            return ltrim($target, '/');
        }
        $parts = $dir === '' ? [] : explode('/', rtrim($dir, '/'));
        foreach (explode('/', $target) as $seg) {
            if ($seg === '..') {
                array_pop($parts);
            } elseif ($seg !== '.') {
                $parts[] = $seg;
            }
        }

        return implode('/', $parts);
    }

    public function test_slide_size_sections_hidden_flags_transitions_and_notes_are_written(): void
    {
        $this->export();
        $zip = new ZipArchive;
        $zip->open($this->file);
        $pres = $zip->getFromName('ppt/presentation.xml');
        $this->assertStringContainsString('<p:sldSz cx="'.(1920 * 9525).'" cy="'.(1080 * 9525).'"/>', $pres);
        $this->assertStringContainsString('<p14:section name="Opening"', $pres);
        $this->assertStringContainsString('<p14:section name="Detail"', $pres);
        $this->assertSame(5, substr_count($pres, '<p:sldId '));
        $this->assertSame(5, substr_count($pres, '<p14:sldId '));
        $this->assertStringContainsString('show="0"', $zip->getFromName('ppt/slides/slide5.xml'));
        $this->assertStringNotContainsString('show="0"', $zip->getFromName('ppt/slides/slide4.xml'));
        $this->assertStringContainsString('<p:fade/>', $zip->getFromName('ppt/slides/slide1.xml'));
        $this->assertStringContainsString('<p:push dir="l"/>', $zip->getFromName('ppt/slides/slide2.xml'));
        $this->assertStringContainsString('<p:wipe dir="r"/>', $zip->getFromName('ppt/slides/slide3.xml'));
        $this->assertStringContainsString('Open warm.', $zip->getFromName('ppt/notesSlides/notesSlide1.xml'));
        $this->assertFalse($zip->locateName('ppt/notesSlides/notesSlide2.xml'));
        $this->assertStringContainsString('<a:latin typeface="Public Sans"/>', $zip->getFromName('ppt/theme/theme1.xml'));
        $zip->close();
    }

    public function test_charts_are_native_with_an_embedded_workbook(): void
    {
        $this->export();
        $zip = new ZipArchive;
        $zip->open($this->file);
        $chart = $zip->getFromName('ppt/charts/chart1.xml');
        $this->assertStringContainsString('<c:barChart>', $chart);
        $this->assertStringContainsString('<c:v>Revenue</c:v>', $chart);
        $this->assertStringContainsString('<c:externalData r:id="rId1">', $chart);
        $this->assertStringContainsString('<c:lineChart>', $zip->getFromName('ppt/charts/chart2.xml'));
        $this->assertStringContainsString('<c:pieChart>', $zip->getFromName('ppt/charts/chart3.xml'));
        $this->assertStringContainsString('<c:doughnutChart>', $zip->getFromName('ppt/charts/chart4.xml'));
        $xlsx = $zip->getFromName('ppt/embeddings/Microsoft_Excel_Sheet1.xlsx');
        $tmp = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        file_put_contents($tmp, $xlsx);
        $wb = new ZipArchive;
        $this->assertTrue($wb->open($tmp));
        $sheet = $wb->getFromName('xl/worksheets/sheet1.xml');
        $strings = $wb->getFromName('xl/sharedStrings.xml');
        $this->assertStringContainsString('Revenue', $strings);
        $this->assertStringContainsString('<v>120</v>', $sheet);
        $this->assertTrue((bool) @simplexml_load_string($sheet));
        $wb->close();
        @unlink($tmp);
        $zip->close();
    }

    public function test_python_pptx_opens_the_file_and_sees_the_shapes_text_and_charts(): void
    {
        if (PressPython::bin() === null) {
            $this->markTestSkipped('python-pptx is not installed (see tests/Support/PressPython.php).');
        }
        $this->export();
        $info = PressPython::inspect('inspect_pptx.py', $this->file);

        $this->assertSame(1920 * 9525, $info['slide_width']);
        $this->assertSame(1080 * 9525, $info['slide_height']);
        $this->assertCount(5, $info['slides']);
        $s1 = $info['slides'][0];
        $this->assertSame('Title and content', $s1['layout']);
        $this->assertSame("Open warm.\nName the three asks.", $s1['notes']);
        $this->assertCount(3, $s1['shapes']);
        $this->assertSame('Quarterly review, Q3', $s1['shapes'][0]['text']);
        $this->assertSame(120 * 9525, $s1['shapes'][0]['left']);
        $this->assertSame(300 * 9525, $s1['shapes'][0]['top']);
        $this->assertSame(1500 * 9525, $s1['shapes'][0]['width']);
        $this->assertEqualsWithDelta(90.0, $s1['shapes'][0]['runs'][0]['size'], 0.01);
        $s2 = $info['slides'][1];
        $this->assertStringContainsString("Revenue grew 18% quarter on quarter\nChurn fell to 2.1%", $s2['shapes'][1]['text']);
        $bold = array_values(array_filter($s2['shapes'][1]['runs'], fn ($r) => $r['bold']));
        $this->assertSame('18%', $bold[0]['text']);
        $this->assertSame(12.0, $info['slides'][2]['shapes'][2]['rotation']);
        $charts = array_values(array_filter($info['slides'][3]['shapes'], fn ($s) => isset($s['chart'])));
        $this->assertCount(4, $charts);
        $this->assertSame(['Revenue', 'Cost'], array_column($charts[0]['chart']['series'], 'name'));
        $this->assertSame([120.0, 180.0, 150.0, 240.0], $charts[0]['chart']['series'][0]['values']);
        $this->assertSame(['Q1', 'Q2', 'Q3', 'Q4'], $charts[0]['chart']['categories']);
        $table = array_values(array_filter($info['slides'][4]['shapes'], fn ($s) => isset($s['table'])))[0];
        $this->assertSame([5, 3], [$table['table']['rows'], $table['table']['cols']]);
        $this->assertSame('Region', $table['table']['cells'][0][0]);
        $this->assertTrue($info['slides'][4]['hidden']);
    }

    public function test_every_element_type_becomes_something_in_the_file(): void
    {
        $x = $this->export();
        $zip = new ZipArchive;
        $zip->open($this->file);
        $slide3 = $zip->getFromName('ppt/slides/slide3.xml');
        foreach (['rect', 'roundRect', 'ellipse', 'triangle', 'star5', 'hexagon', 'chevron', 'rightArrow', 'leftArrow', 'wedgeRectCallout', 'plus', 'heart', 'diamond', 'pentagon'] as $prst) {
            $this->assertStringContainsString('prst="'.$prst.'"', $slide3, $prst.' is missing');
        }
        $this->assertStringContainsString('prst="line"', $slide3);
        $this->assertStringContainsString('<a:tailEnd type="triangle"', $slide3);
        $this->assertStringContainsString('<a:headEnd type="oval"', $slide3);
        $this->assertStringContainsString('<a:gradFill', $slide3);
        $this->assertStringContainsString('<a:schemeClr val="accent1"/>', $slide3);
        $slide2 = $zip->getFromName('ppt/slides/slide2.xml');
        $this->assertStringContainsString('<p:pic>', $slide2);
        $this->assertStringContainsString('prst="ellipse"', $slide2);
        $this->assertStringContainsString('<a:buChar', $slide2);
        $this->assertStringContainsString('<a:buAutoNum type="arabicPeriod"', $slide2);
        $this->assertStringContainsString('<a:hlinkClick', $slide2);
        $this->assertStringContainsString('<p:ph type="title"/>', $slide2);
        $slide5 = $zip->getFromName('ppt/slides/slide5.xml');
        $this->assertStringContainsString('<a:tbl>', $slide5);
        $this->assertStringContainsString('gridSpan="1"', $slide5.' gridSpan="1"');
        $this->assertStringContainsString('rowSpan="2"', $slide5);
        $this->assertStringContainsString('vMerge="1"', $slide5);
        $this->assertStringContainsString('https://www.youtube.com/watch?v=dQw4w9WgXcQ', (string) $zip->getFromName('ppt/slides/_rels/slide5.xml.rels'));
        $zip->close();
        // every approximation the exporter made is a non-native entry of the preflight
        $pre = \App\Services\Press\Export\ExportPreflight::analyze(PressSampleDeck::doc(), 'pptx', [], PressSampleDeck::assets());
        $levels = [];
        foreach ($pre['slides'] as $s) {
            foreach ($s['elements'] as $e) {
                $levels[$s['index'].'/'.$e['id']] = $e['level'];
            }
        }
        foreach ($x->notes as $n) {
            if ($n['element'] !== null) {
                $this->assertNotSame('native', $levels[$n['slide'].'/'.$n['element']] ?? null, 'Exporter degraded '.$n['element'].' but the preflight calls it native');
            }
        }
    }

    public function test_hidden_slides_can_be_left_out(): void
    {
        $this->export(null, ['include_hidden' => false]);
        $zip = new ZipArchive;
        $zip->open($this->file);
        $this->assertFalse($zip->locateName('ppt/slides/slide5.xml'));
        $this->assertNotFalse($zip->locateName('ppt/slides/slide4.xml'));
    }

    public function test_hostile_text_is_escaped_and_unsafe_links_are_dropped(): void
    {
        $doc = PressSampleDeck::doc();
        $run = ['type' => 'text', 'text' => '<script>alert(1)</script> & ]]> '."\x01\x02", 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]];
        $doc['slides'] = [[
            'id' => 'h', 'title' => 'x', 'notes' => '</a:t><evil/>', 'els' => [
                ['id' => 'a', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 500, 'h' => 100, 'name' => '"><script>', 'alt' => '<b>"', 'link' => 'javascript:alert(1)',
                    'doc' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [$run]]]]],
            ],
        ]];
        $this->export($doc);
        $zip = new ZipArchive;
        $zip->open($this->file);
        $xml = $zip->getFromName('ppt/slides/slide1.xml');
        $dom = new DOMDocument;
        $this->assertTrue(@$dom->loadXML($xml));
        $this->assertStringNotContainsString('<script>', $xml);
        $this->assertStringNotContainsString('javascript:', $xml.(string) $zip->getFromName('ppt/slides/_rels/slide1.xml.rels'));
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp;', $xml);
        $this->assertStringNotContainsString("\x01", $xml);
        $this->assertStringNotContainsString('<evil/>', $zip->getFromName('ppt/notesSlides/notesSlide1.xml'));
    }

    public function test_a_hundred_slide_deck_exports_in_reasonable_time(): void
    {
        $doc = PressSampleDeck::doc();
        $base = $doc['slides'][1];
        $slides = [];
        for ($i = 1; $i <= 100; $i++) {
            $s = $base;
            $s['id'] = 'x'.$i;
            $slides[] = $s;
        }
        $doc['slides'] = $slides;
        $t = microtime(true);
        $this->export($doc);
        $this->assertLessThan(20, microtime(true) - $t);
        $zip = new ZipArchive;
        $zip->open($this->file);
        $this->assertNotFalse($zip->locateName('ppt/slides/slide100.xml'));
    }

    public function test_missing_pictures_are_reported_not_fatal(): void
    {
        $x = new PptxExporter(new \App\Services\Press\Export\ArrayAssetResolver);
        $x->render(PressSampleDeck::doc(), $this->file);

        $messages = array_column($x->notes, 'note');
        $this->assertNotEmpty(array_filter($messages, fn ($m) => str_contains($m, 'could not be read')));
        $this->assertFileExists($this->file);
    }
}
