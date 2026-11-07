<?php

namespace Tests\Feature\Press;

use App\Services\Press\Import\ImportException;
use App\Services\Press\Import\PptxImporter;
use App\Services\Press\Import\ZipGuard;
use Tests\TestCase;
use ZipArchive;

/** Hostile .pptx files: every one must be refused (or neutralised) with a readable message, never crash or read the host. */
class ImportSecurityTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    /** @param array<string, string> $parts name => bytes */
    private function zip(array $parts): string
    {
        $path = tempnam(sys_get_temp_dir(), 'evil').'.pptx';
        $this->files[] = $path;
        $z = new ZipArchive;
        $z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($parts as $name => $bytes) {
            $z->addFromString($name, $bytes);
        }
        $z->close();

        return $path;
    }

    /** A minimal valid presentation with $n slides all pointing at one slide part. */
    private function minimal(int $n = 1, array $extra = [], ?string $slideXml = null): array
    {
        $ids = '';
        $rels = '';
        for ($i = 1; $i <= $n; $i++) {
            $ids .= '<p:sldId id="'.(255 + $i).'" r:id="rId'.($i + 1).'"/>';
            $rels .= '<Relationship Id="rId'.($i + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide1.xml"/>';
        }
        $ns = 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"';

        return $extra + [
            '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
            '_rels/.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/></Relationships>',
            'ppt/presentation.xml' => '<p:presentation '.$ns.'><p:sldIdLst>'.$ids.'</p:sldIdLst><p:sldSz cx="12192000" cy="6858000"/></p:presentation>',
            'ppt/_rels/presentation.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>',
            'ppt/slides/slide1.xml' => $slideXml ?? '<p:sld '.$ns.'><p:cSld><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr/></p:spTree></p:cSld></p:sld>',
        ];
    }

    private function refuses(string $path, string $contains, array $options = []): void
    {
        try {
            PptxImporter::import($path, $options);
        } catch (ImportException $e) {
            $this->assertStringContainsString($contains, $e->getMessage());

            return;
        }
        $this->fail('The import should have been refused ('.$contains.').');
    }

    public function test_a_minimal_valid_file_imports(): void
    {
        $r = PptxImporter::import($this->zip($this->minimal()));

        $this->assertCount(1, $r->slides);
        $this->assertSame(1280, $r->deck['w']);
        $this->assertSame(720, $r->deck['h']);
    }

    public function test_it_is_not_a_zip(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'x');
        $this->files[] = $path;
        file_put_contents($path, "<?php echo 'not a presentation';");
        $this->refuses($path, 'not a PowerPoint');
        file_put_contents($path, '');
        $this->refuses($path, 'empty');
        file_put_contents($path, "PK\x03\x04".str_repeat("\0", 40)); // a zip header and then garbage
        $this->refuses($path, 'damaged');
    }

    public function test_path_traversal_entries_are_refused(): void
    {
        foreach (['../evil.xml', 'ppt/../../evil.xml', '/etc/passwd', 'C:/windows/evil.xml', 'ppt\\..\\evil.xml'] as $name) {
            $this->refuses($this->zip($this->minimal(1, [$name => 'x'])), 'unsafe path');
        }
        $this->assertFalse(ZipGuard::safeName('a/../b'));
        $this->assertTrue(ZipGuard::safeName('ppt/slides/slide1.xml'));
        $this->assertSame('ppt/media/a.png', ZipGuard::resolve('ppt/slides/slide1.xml', '../media/a.png'));
        $this->assertSame('etc/passwd', ZipGuard::resolve('ppt/slides/slide1.xml', '../../../etc/passwd'), 'a relationship target can never climb out of the package');
    }

    public function test_a_zip_bomb_is_refused_by_ratio_and_by_size(): void
    {
        $bomb = $this->zip($this->minimal(1, ['ppt/media/huge.png' => str_repeat("\0", 30 * 1048576)]));
        $this->refuses($bomb, 'compression bomb');

        $big = $this->zip($this->minimal(1, ['ppt/media/big.bin' => random_bytes(300000)]));
        $this->refuses($big, 'larger than', ['max_entry_bytes' => 100000]);
        $this->refuses($this->zip($this->minimal()), 'expands to more than', ['max_total_bytes' => 100]);
    }

    public function test_too_many_parts_too_large_a_file_and_too_many_slides_are_refused(): void
    {
        $many = [];
        for ($i = 0; $i < 60; $i++) {
            $many['ppt/media/m'.$i.'.txt'] = 'x';
        }
        $this->refuses($this->zip($this->minimal(1, $many)), 'too many parts', ['max_entries' => 50]);

        $path = $this->zip($this->minimal());
        $this->refuses($path, 'larger than', ['max_bytes' => 100]);

        $this->refuses($this->zip($this->minimal(301)), '301 slides');
        $this->assertCount(300, PptxImporter::import($this->zip($this->minimal(300)))->slides);
    }

    public function test_xml_with_a_doctype_or_entities_is_refused_and_nothing_on_the_host_is_read(): void
    {
        $secret = tempnam(sys_get_temp_dir(), 'secret');
        $this->files[] = $secret;
        file_put_contents($secret, 'TOP-SECRET-HOST-DATA');
        $xxe = '<?xml version="1.0"?><!DOCTYPE p [<!ENTITY x SYSTEM "file://'.$secret.'">]><p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><p:cSld><p:spTree><p:sp><p:txBody><a:p><a:r><a:t>&x;</a:t></a:r></a:p></p:txBody></p:sp></p:spTree></p:cSld></p:sld>';
        $this->refuses($this->zip($this->minimal(1, [], $xxe)), 'document type declaration');

        $laughs = '<?xml version="1.0"?><!DOCTYPE lolz [<!ENTITY lol "lol"><!ENTITY lol2 "&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;">]><lolz>&lol2;</lolz>';
        $this->refuses($this->zip($this->minimal(1, [], $laughs)), 'document type declaration');

        // a DOCTYPE hidden behind a very long comment is still found
        $hidden = '<?xml version="1.0"?><!--'.str_repeat('x', 9000).'--><!DOCTYPE p [<!ENTITY x "y">]><p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"/>';
        $this->refuses($this->zip($this->minimal(1, [], $hidden)), 'document type declaration');

        // the same attack in the presentation part itself, and through a parameter entity
        $pres = $this->minimal();
        $pres['ppt/presentation.xml'] = '<!DOCTYPE p [<!ENTITY % d SYSTEM "http://127.0.0.1:1/x">%d;]><p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"/>';
        $this->refuses($this->zip($pres), 'document type declaration');
    }

    public function test_a_missing_or_empty_presentation_is_refused(): void
    {
        $this->refuses($this->zip(['readme.txt' => 'hello']), 'main presentation part');
        $parts = $this->minimal(0);
        $this->refuses($this->zip($parts), 'no slides');
    }

    public function test_media_that_is_not_an_image_is_skipped_and_reported(): void
    {
        $ns = 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"';
        $slide = '<p:sld '.$ns.'><p:cSld><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr/>'
            .'<p:pic><p:nvPicPr><p:cNvPr id="2" name="P"/><p:cNvPicPr/><p:nvPr/></p:nvPicPr><p:blipFill><a:blip r:embed="rId9"/></p:blipFill><p:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="952500" cy="952500"/></a:xfrm></p:spPr></p:pic>'
            .'</p:spTree></p:cSld></p:sld>';
        $parts = $this->minimal(1, [
            'ppt/slides/_rels/slide1.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId9" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/image1.png"/></Relationships>',
            'ppt/media/image1.png' => '<?php system($_GET["c"]); ?>',
        ], $slide);
        $r = PptxImporter::import($this->zip($parts));

        $this->assertSame([], $r->media);
        $this->assertSame([], $r->slides[0]['els']);
        $this->assertNotEmpty($r->reportOf('unsupported'));
    }

    public function test_a_lying_declared_size_cannot_exceed_the_cap(): void
    {
        $path = $this->zip($this->minimal());
        $guard = new ZipGuard($path, ['max_bytes' => 1 << 20, 'max_entries' => 100, 'max_entry_bytes' => 1 << 20, 'max_xml_bytes' => 100, 'max_total_bytes' => 1 << 24, 'max_ratio' => 200]);
        $this->expectException(ImportException::class);
        $guard->read('ppt/presentation.xml', 10);
    }

    public function test_unsafe_hyperlinks_are_not_imported(): void
    {
        $ns = 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"';
        $slide = '<p:sld '.$ns.'><p:cSld><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr/>'
            .'<p:sp><p:nvSpPr><p:cNvPr id="2" name="T"><a:hlinkClick r:id="rId7"/></p:cNvPr><p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr><p:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="1905000" cy="952500"/></a:xfrm><a:prstGeom prst="rect"/></p:spPr>'
            .'<p:txBody><a:bodyPr/><a:p><a:r><a:rPr><a:hlinkClick r:id="rId8"/></a:rPr><a:t>click</a:t></a:r></a:p></p:txBody></p:sp>'
            .'</p:spTree></p:cSld></p:sld>';
        $rels = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId7" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="javascript:alert(1)" TargetMode="External"/>'
            .'<Relationship Id="rId8" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="http://insecure.example/" TargetMode="External"/></Relationships>';
        $r = PptxImporter::import($this->zip($this->minimal(1, ['ppt/slides/_rels/slide1.xml.rels' => $rels], $slide)));

        $el = $r->slides[0]['els'][0];
        $this->assertArrayNotHasKey('link', $el);
        $this->assertStringNotContainsString('javascript', (string) json_encode($el));
        $this->assertStringNotContainsString('insecure', (string) json_encode($el));
    }
}
