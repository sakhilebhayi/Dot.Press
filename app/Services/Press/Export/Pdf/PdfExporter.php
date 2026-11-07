<?php

namespace App\Services\Press\Export\Pdf;

use App\Services\Press\Export\AssetResolver;
use App\Services\Press\Export\Support\Els;
use App\Services\Press\Export\Support\IconLibrary;
use App\Services\Press\Export\Support\TextDoc;
use App\Services\Press\Export\Support\Theme;
use Dompdf\Dompdf;
use RuntimeException;

/**
 * Renders a presentation document (spec sections 4 to 6) to a PDF with one page per slide, sized exactly to the
 * slide (1 unit = 0.75 pt). Text stays real text, shapes and charts are vector, pictures are embedded as prepared.
 */
final class PdfExporter
{
    /** @var list<array{slide: int, element: ?string, note: string}> */
    public array $notes = [];

    public function __construct(private AssetResolver $assets, private ?IconLibrary $icons = null)
    {
        $this->icons ??= new IconLibrary;
    }

    /**
     * @param  array{deck: array<string, mixed>, slides: list<array<string, mixed>>}  $doc
     * @param  array{include_hidden?: bool, notes?: bool, slides?: list<string|int>}  $options
     */
    public function render(array $doc, array $options = []): string
    {
        $deck = (array) ($doc['deck'] ?? []);
        $theme = new Theme(is_array($deck['theme'] ?? null) ? $deck['theme'] : null);
        $W = Els::num($deck['w'] ?? 1920, 1920, 100, 8000);
        $H = Els::num($deck['h'] ?? 1080, 1080, 100, 8000);
        $slides = $this->selectSlides((array) ($doc['slides'] ?? []), $options);
        if ($slides === []) {
            throw new RuntimeException('There are no slides to export.');
        }

        FontRegistry::ensure($this->families($slides, $theme));

        $renderer = new SlideRenderer($theme, $this->assets, $this->icons);
        $pages = [];
        foreach ($slides as $i => $slide) {
            $parts = $renderer->render($slide, $i + 1, $W, $H);
            $pages[] = ['anchor' => preg_replace('/[^A-Za-z0-9_-]/', '', Els::str($slide['id'] ?? $i)), 'bg' => $parts['bg'], 'body' => $parts['body']];
            if (! empty($options['notes']) && trim(Els::str($slide['notes'] ?? '')) !== '') {
                $pages[] = ['anchor' => 'n'.$i, 'bg' => $this->notesBackground($theme, $W, $H), 'body' => $this->notesPage($slide, $i + 1, $theme, $W, $H)];
            }
        }
        $this->notes = $renderer->notes;

        $html = view('press.export.pdf', [
            'title' => Els::str($deck['title'] ?? null, 'Presentation'),
            'pages' => $pages,
            'w' => $this->fmt($W),
            'h' => $this->fmt($H),
        ])->render();

        $dompdf = new Dompdf(FontRegistry::options());
        $dompdf->setPaper([0, 0, $W * 0.75, $H * 0.75]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();
        $dompdf->addInfo('Title', mb_substr(Els::str($deck['title'] ?? null, 'Presentation'), 0, 200));
        $dompdf->addInfo('Creator', 'Dot.Press');
        $out = $dompdf->output();
        if (! is_string($out) || ! str_starts_with($out, '%PDF')) {
            throw new RuntimeException('The PDF library produced no output.');
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function selectSlides(array $slides, array $options): array
    {
        $only = isset($options['slides']) && is_array($options['slides']) ? array_map('strval', $options['slides']) : null;
        $out = [];
        foreach ($slides as $s) {
            if (! is_array($s)) {
                continue;
            }
            if (! empty($s['hidden']) && empty($options['include_hidden'])) {
                continue;
            }
            if ($only !== null && ! in_array(Els::str($s['id'] ?? null), $only, true)) {
                continue;
            }
            $out[] = $s;
        }

        return $out;
    }

    /** Every font family the deck names, so only those are installed. @return list<string> */
    private function families(array $slides, Theme $theme): array
    {
        $fam = [$theme->heading, $theme->body, 'Public Sans'];
        foreach ($slides as $s) {
            foreach (Els::visible($s) as $el) {
                if (isset($el['font']) && is_string($el['font'])) {
                    $fam[] = $theme->family($el['font']);
                }
                foreach (TextDoc::paragraphs(is_array($el['doc'] ?? null) ? $el['doc'] : null) as $p) {
                    foreach ($p['runs'] as $r) {
                        if (! empty($r['font'])) {
                            $fam[] = $theme->family($r['font']);
                        }
                    }
                }
            }
        }

        return array_values(array_unique($fam));
    }

    private function notesBackground(Theme $theme, float $W, float $H): string
    {
        return '<div style="position:absolute;left:0;top:0;width:'.$this->fmt($W).'px;height:'.$this->fmt($H).'px;background-color:'.$theme->css('@bg').';"></div>';
    }

    private function notesPage(array $slide, int $n, Theme $theme, float $W, float $H): string
    {
        $size = max(14.0, $H * 0.032);
        $pad = $W * 0.06;
        $title = htmlspecialchars(Els::str($slide['title'] ?? null), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $notes = nl2br(htmlspecialchars(Els::str($slide['notes'] ?? null), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $css = 'position:absolute;left:'.$this->fmt($pad).'px;top:'.$this->fmt($pad).'px;width:'.$this->fmt($W - 2 * $pad).'px;font-family:'.RichTextHtml::family($theme->body).';color:'.$theme->css('@ink').';';

        return '<div style="'.$css.'"><div style="font-size:'.$this->fmt($size * 0.8).'px;color:'.$theme->css('@muted').';">Speaker notes for slide '.$n.'</div>'
            .'<div style="font-size:'.$this->fmt($size * 1.4).'px;font-weight:bold;margin:'.$this->fmt($size * 0.4).'px 0 '.$this->fmt($size * 0.8).'px;">'.$title.'</div>'
            .'<div style="font-size:'.$this->fmt($size).'px;line-height:1.45;">'.$notes.'</div></div>';
    }

    private function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
}
