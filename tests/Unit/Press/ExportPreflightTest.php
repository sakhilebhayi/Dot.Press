<?php

namespace Tests\Unit\Press;

use App\Services\Press\Export\ArrayAssetResolver;
use App\Services\Press\Export\ExportPreflight;
use App\Services\Press\Export\Support\IconLibrary;
use Tests\Support\PressSampleDeck;
use Tests\TestCase;

class ExportPreflightTest extends TestCase
{
    /** @return array<string, string> "slide/element" => level */
    private function levels(array $result): array
    {
        $out = [];
        foreach ($result['slides'] as $s) {
            foreach ($s['elements'] as $e) {
                $out[$s['id'].'/'.$e['id']] = $e['level'];
            }
        }

        return $out;
    }

    private function icons(): IconLibrary
    {
        $file = tempnam(sys_get_temp_dir(), 'ic').'.js';
        file_put_contents($file, "export const I = { check: 'M20 6 9 17l-5-5' };");
        $lib = new IconLibrary($file);
        $lib->names();
        @unlink($file);

        return $lib;
    }

    public function test_pptx_classifications(): void
    {
        $r = ExportPreflight::analyze(PressSampleDeck::doc(), 'pptx', [], PressSampleDeck::assets(), $this->icons());
        $l = $this->levels($r);

        $this->assertSame('native', $l['s1/e1']);
        $this->assertSame('native', $l['s1/e3']);
        $this->assertSame('native', $l['s2/b2']);
        $this->assertSame('native', $l['s2/b3'], 'crop, mask and native adjustments are all native picture properties');
        $this->assertSame('native', $l['s3/h13'], 'an icon in the set is a vector shape');
        $this->assertSame('native', $l['s4/c1']);
        $this->assertSame('native', $l['s4/c3']);
        $this->assertSame('approximated', $l['s4/c5'], 'kpi tiles become text boxes');
        $this->assertSame('native', $l['s5/t1']);
        $this->assertSame('approximated', $l['s5/v1']);
        $this->assertSame('approximated', $l['s5/a1']);
        $this->assertFalse($r['slides'][4]['skipped'], 'PowerPoint keeps hidden slides hidden');
        $this->assertSame(5, $r['summary']['slides']);
        $this->assertSame('minor', $r['summary']['level']);
        $this->assertTrue($r['summary']['has_video']);
        $this->assertSame(array_sum(array_intersect_key($r['summary'], array_flip(['native', 'approximated', 'flattened', 'dropped']))), $r['summary']['total']);
        $this->assertStringContainsString('animations', strtolower(implode(' ', $r['summary']['general'])));
    }

    public function test_pdf_classifications(): void
    {
        $r = ExportPreflight::analyze(PressSampleDeck::doc(), 'pdf', [], PressSampleDeck::assets(), $this->icons());
        $l = $this->levels($r);

        $this->assertSame('flattened', $l['s2/b3'], 'the mask is applied to the pixels');
        $this->assertSame('flattened', $l['s3/h2'], 'gradients are pictures in the PDF');
        $this->assertSame('native', $l['s3/h13']);
        $this->assertSame('native', $l['s4/c5'], 'the PDF draws the key figures');
        $this->assertSame('native', $l['s4/c1']);
        $this->assertTrue($r['slides'][4]['skipped'], 'hidden slides are left out of a PDF');
        $this->assertSame([], $r['slides'][4]['elements']);
        $this->assertSame(1, $r['summary']['skipped_slides']);
        $this->assertSame(4, $r['summary']['slides']);
        $this->assertSame('background', $r['slides'][0]['elements'][0]['type']);
        $this->assertSame('flattened', $r['slides'][0]['elements'][0]['level']);

        $withHidden = ExportPreflight::analyze(PressSampleDeck::doc(), 'pdf', ['include_hidden' => true], PressSampleDeck::assets());
        $this->assertFalse($withHidden['slides'][4]['skipped']);
        $this->assertSame('approximated', $this->levels($withHidden)['s5/v1']);
    }

    public function test_unknown_icons_missing_pictures_and_external_images_are_called_out(): void
    {
        $doc = PressSampleDeck::doc();
        $doc['slides'] = [['id' => 'x', 'title' => 'x', 'bg' => ['img' => ['asset' => 404]], 'transition' => ['type' => 'zoom'], 'els' => [
            ['id' => 'i', 'type' => 'icon', 'name' => 'sparkle', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10],
            ['id' => 'm', 'type' => 'image', 'src' => ['asset' => 404], 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10],
            ['id' => 'u', 'type' => 'image', 'src' => ['url' => 'https://example.com/a.png'], 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10],
            ['id' => 'a', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'shadow' => ['x' => 1, 'y' => 1, 'blur' => 1, 'c' => '#000'], 'anim' => [['id' => 'z']]],
            ['id' => 'k', 'type' => 'shape', 'kind' => 'blob', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10],
            ['id' => 'h', 'type' => 'text', 'hide' => true, 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10],
        ]]];
        $assets = new ArrayAssetResolver;
        $pptx = ExportPreflight::analyze($doc, 'pptx', [], $assets, $this->icons());
        $l = $this->levels($pptx);

        $this->assertSame('approximated', $l['x/i']);
        $this->assertSame('dropped', $l['x/m']);
        $this->assertSame('dropped', $l['x/u']);
        $this->assertSame('dropped', $l['x/a'], 'a shadow on text without a box is not exported to PPTX');
        $this->assertSame('approximated', $l['x/k']);
        $this->assertArrayNotHasKey('x/h', $l, 'hidden elements are not exported');
        $this->assertSame('dropped', $l['x/background']);
        $this->assertSame('major', $pptx['summary']['level']);
        $this->assertContains('Zoom transition is exported as a fade.', $pptx['slides'][0]['notes']);
        $this->assertContains('Animations on 1 element are not exported.', $pptx['slides'][0]['notes']);
        foreach ($pptx['summary']['warnings'] as $w) {
            $this->assertContains($w['level'], ['approximated', 'flattened', 'dropped']);
            $this->assertNotSame('', $w['text']);
            $this->assertStringNotContainsString("\u{2014}", $w['text']);
        }
    }

    public function test_every_element_has_a_level_and_a_reason_unless_native(): void
    {
        foreach (['pdf', 'pptx'] as $format) {
            $r = ExportPreflight::analyze(PressSampleDeck::doc(), $format, ['include_hidden' => true], PressSampleDeck::assets());
            foreach ($r['slides'] as $s) {
                foreach ($s['elements'] as $e) {
                    $this->assertContains($e['level'], ExportPreflight::LEVELS);
                    if ($e['level'] === 'native') {
                        $this->assertNull($e['reason']);
                    } else {
                        $this->assertNotEmpty($e['reason'], $format.' '.$e['id']);
                    }
                }
            }
        }
    }

    public function test_it_copes_with_garbage(): void
    {
        $r = ExportPreflight::analyze(['deck' => 5, 'slides' => [null, 'x', ['els' => [null, ['type' => 'wat'], ['type' => 'text']]]]], 'bogus');

        $this->assertSame('pdf', $r['format']);
        $this->assertCount(1, $r['slides']);
        $this->assertCount(1, $r['slides'][0]['elements'], 'only the well formed text element is listed');
    }
}
