<?php

namespace Tests\Unit\Press;

use App\Services\Press\Export\Pdf\FontRegistry;
use App\Services\Press\Export\Pdf\RichTextHtml;
use App\Services\Press\Export\Support\TextDoc;
use App\Services\Press\Export\Support\TextStyle;
use App\Services\Press\Export\Support\Theme;
use Tests\TestCase;

class RichTextHtmlTest extends TestCase
{
    private function html(array $doc, array $el = [], float $scale = 1.0): string
    {
        $theme = new Theme(null);

        return RichTextHtml::render(TextDoc::paragraphs($doc), TextStyle::of($el, $theme), $theme, $scale);
    }

    private function p(array $content): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => $content]]];
    }

    public function test_user_text_is_escaped_everywhere(): void
    {
        $evil = '<script>alert(1)</script><img src=x onerror=alert(2)>"\' &';
        $html = $this->html($this->p([['type' => 'text', 'text' => $evil, 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com/?a="><script>']]]]]));

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&amp;', $html);
        $this->assertStringNotContainsString('<a ', $html, 'a link with quotes or angle brackets in it is not a link');

        $ok = $this->html($this->p([['type' => 'text', 'text' => 'go', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com/?a=1&b=2']]]]]));
        $this->assertStringContainsString('href="https://example.com/?a=1&amp;b=2"', $ok);
    }

    public function test_only_safe_links_become_anchors_and_slide_links_point_at_the_page_anchor(): void
    {
        $js = $this->html($this->p([['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]]]));
        $data = $this->html($this->p([['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'data:text/html,hi']]]]]));
        $slide = $this->html($this->p([['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => '#slide:abc_1']]]]]));

        $this->assertStringNotContainsString('<a ', $js);
        $this->assertStringNotContainsString('<a ', $data);
        $this->assertStringContainsString('href="#sabc_1"', $slide);
    }

    public function test_colours_sizes_and_fonts_are_rebuilt_from_validated_values(): void
    {
        $html = $this->html($this->p([['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'textStyle', 'attrs' => ['color' => 'red;background:url(http://evil)', 'fontSize' => '20px;x', 'fontFamily' => "Inter';}</style>"]]]]]));

        $this->assertStringNotContainsString('evil', $html);
        $this->assertStringNotContainsString('</style>', $html);
        $this->assertStringNotContainsString('url(', $html);
        $this->assertStringContainsString("font-family:'Interstyle','Public Sans','DejaVu Sans',sans-serif", $html, 'the family name is stripped down to letters, digits and spaces');
    }

    public function test_marks_lists_alignment_spacing_and_scale(): void
    {
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'B', 'marks' => [['type' => 'bold'], ['type' => 'italic'], ['type' => 'underline'], ['type' => 'strike']]], ['type' => 'hardBreak'], ['type' => 'text', 'text' => 'next']]],
            ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'item']]]]]]],
        ]];
        $html = $this->html($doc, ['size' => 40, 'align' => 'center', 'ls' => 0.1, 'ps' => 0.5, 'lh' => 1.5], 0.5);

        $this->assertStringContainsString('font-weight:bold', $html);
        $this->assertStringContainsString('font-style:italic', $html);
        $this->assertStringContainsString('text-decoration:underline line-through', $html);
        $this->assertStringContainsString('<br/>', $html);
        $this->assertStringContainsString('text-align:center', $html);
        $this->assertStringContainsString('letter-spacing:2px', $html, 'em of 40 x 0.5 scale x 0.1');
        $this->assertStringContainsString('font-size:20px', $html);
        $this->assertStringContainsString('margin-top:10px', $html, 'paragraph spacing scales with the text');
        $this->assertStringContainsString("\u{2022}", $html);
        $this->assertStringContainsString('padding-left:', $html);
    }

    public function test_line_heights_are_corrected_for_the_pdf_library(): void
    {
        $html = $this->html($this->p([['type' => 'text', 'text' => 'x']]), ['size' => 100, 'lh' => 1.25, 'font' => 'Inter']);
        $m = FontRegistry::vmetrics('Inter');
        $expected = 125 / (1.1 * ($m['asc'] + $m['desc']));

        $this->assertStringContainsString('line-height:'.rtrim(rtrim(number_format($expected, 3, '.', ''), '0'), '.').'px', $html);
        // Public Sans: ascent 0.95, descent 0.225. A browser puts the first baseline at (1 - 1.175) / 2 + 0.95 em, DomPDF at 0.8 em.
        $this->assertEqualsWithDelta(0.0625, FontRegistry::baselineShift('Public Sans', 1.0), 0.0001);
    }

    public function test_characters_the_bundled_fonts_lack_are_set_in_a_fallback_face(): void
    {
        $html = $this->html($this->p([['type' => 'text', 'text' => 'Plain and 中文 and ✓']]));

        $this->assertStringContainsString("<span style=\"font-family:'DejaVu Sans',sans-serif;\">", $html);
        $this->assertStringContainsString('Plain and ', $html);
    }

    public function test_the_bundled_fonts_are_the_twelve_families_with_files_and_a_licence(): void
    {
        $families = ['Public Sans', 'Inter', 'IBM Plex Sans', 'IBM Plex Serif', 'IBM Plex Mono', 'Literata', 'DM Sans', 'Space Grotesk', 'Playfair Display', 'Manrope', 'Source Serif 4', 'Caveat'];
        $manifest = FontRegistry::manifest();

        $this->assertEqualsCanonicalizing($families, array_keys($manifest));
        foreach ($manifest as $family => $styles) {
            $this->assertEqualsCanonicalizing(['Regular', 'Bold', 'Italic', 'BoldItalic'], array_keys($styles), $family);
            foreach ($styles as $file) {
                $this->assertFileExists(FontRegistry::ttfDir().'/'.$file);
                $this->assertSame("\x00\x01\x00\x00", substr((string) file_get_contents(FontRegistry::ttfDir().'/'.$file, false, null, 0, 4), 0, 4), $family.' is not a TrueType file');
            }
            $m = FontRegistry::vmetrics($family);
            $this->assertGreaterThan(0.5, $m['asc']);
            $this->assertGreaterThan(0.05, $m['desc']);
        }
        $licence = (string) file_get_contents(FontRegistry::ttfDir().'/LICENSE-NOTE.txt');
        $this->assertStringContainsString('SIL OPEN FONT LICENSE Version 1.1', $licence);
        foreach (['Inter Project Authors', 'IBM Corp.', 'Playfair Display Project Authors'] as $holder) {
            $this->assertStringContainsString($holder, $licence);
        }
        $this->assertSame('Inter', FontRegistry::canonical('inter'));
        $this->assertNull(FontRegistry::canonical('Comic Sans'));
    }
}
