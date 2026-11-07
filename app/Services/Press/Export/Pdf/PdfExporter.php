<?php

namespace App\Services\Press\Export\Pdf;

use App\Services\Press\Export\AssetResolver;
use App\Services\Press\Export\Support\Els;
use App\Services\Press\Export\Support\IconLibrary;
use App\Services\Press\Export\Support\Memory;
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
        $tmp = (string) tempnam(sys_get_temp_dir(), 'pdfx');
        try {
            $this->renderToFile($doc, $tmp, $options);

            return (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Writes the PDF to $path and returns its size in bytes. The parts of a big deck are kept on disk and joined there,
     * so only the part being drawn is ever in memory.
     *
     * @param  array{deck: array<string, mixed>, slides: list<array<string, mixed>>}  $doc
     * @param  array{include_hidden?: bool, notes?: bool, slides?: list<string|int>}  $options
     */
    public function renderToFile(array $doc, string $path, array $options = []): int
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
        $title = Els::str($deck['title'] ?? null, 'Presentation');
        $parts = [];
        $partFiles = [];
        $scale = 1.0;
        $total = count($slides);
        $ids = [];
        foreach ($slides as $slide) {
            $ids[Els::str($slide['id'] ?? null)] = true;
        }

        // DomPDF keeps everything it draws until it writes the file, so a big deck is drawn a few slides at a time and the
        // parts are joined (PdfMerger). A deck that fits the memory budget is still drawn in one pass, as before.
        for ($from = 0; $from < $total;) {
            $to = $this->chunkEnd($slides, $from, $scale);
            $chunked = $from > 0 || $to < $total;
            $slice = array_slice($slides, $from, $to - $from, true);
            if ($chunked) {
                $this->noteCrossLinks($slice, $ids);
            }

            gc_collect_cycles();
            if (function_exists('memory_reset_peak_usage')) {
                memory_reset_peak_usage();
            }
            $before = memory_get_usage();
            $bytes = $this->renderPart($slice, $renderer, $options, $theme, $W, $H, $title);
            if ($chunked) {
                $partFiles[] = $file = (string) tempnam(sys_get_temp_dir(), 'pdfp');
                file_put_contents($file, $bytes);
            } else {
                $parts[] = $bytes;
            }
            unset($bytes);

            // The estimate is a model; if this part cost more than it said, the next ones are planned with that ratio.
            $estimated = array_sum(array_map(fn (array $s): float => $this->estimateMb($s), $slice));
            $used = (memory_get_peak_usage() - $before) / 1048576;
            if ($chunked && $estimated > 0) {
                $scale = max($scale, min(4.0, $used / $estimated));
            }
            if (getenv('PRESS_PDF_DEBUG')) {
                fwrite(STDERR, sprintf("part %d-%d est=%.1f used=%.1f usage=%.1f\n", $from, $to, $estimated, $used, memory_get_usage() / 1048576));
            }
            $from = $to;
        }
        $this->notes = $renderer->notes;

        if ($partFiles === []) {
            file_put_contents($path, $parts[0]);
        } else {
            try {
                PdfMerger::mergeFiles($partFiles, $path);
            } finally {
                foreach ($partFiles as $file) {
                    @unlink($file);
                }
            }
        }

        return (int) filesize($path);
    }

    /**
     * One PDF of the given slides (a whole deck or a part of it). Slide numbers stay the deck's own.
     *
     * @param  array<int, array<string, mixed>>  $slice  slides keyed by their position in the deck
     */
    private function renderPart(array $slice, SlideRenderer $renderer, array $options, Theme $theme, float $W, float $H, string $title): string
    {
        $pages = [];
        foreach ($slice as $i => $slide) {
            $parts = $renderer->render($slide, $i + 1, $W, $H);
            $pages[] = ['anchor' => preg_replace('/[^A-Za-z0-9_-]/', '', Els::str($slide['id'] ?? $i)), 'bg' => $parts['bg'], 'body' => $parts['body']];
            if (! empty($options['notes']) && trim(Els::str($slide['notes'] ?? '')) !== '') {
                $pages[] = ['anchor' => 'n'.$i, 'bg' => $this->notesBackground($theme, $W, $H), 'body' => $this->notesPage($slide, $i + 1, $theme, $W, $H)];
            }
        }

        $html = view('press.export.pdf', [
            'title' => $title,
            'pages' => $pages,
            'w' => $this->fmt($W),
            'h' => $this->fmt($H),
        ])->render();
        unset($pages);

        $dompdf = new Dompdf(FontRegistry::options());
        $dompdf->setPaper([0, 0, $W * 0.75, $H * 0.75]);
        $dompdf->loadHtml($html, 'UTF-8');
        unset($html);
        $dompdf->render();
        $dompdf->addInfo('Title', mb_substr($title, 0, 200));
        $dompdf->addInfo('Creator', 'Dot.Press');
        $out = $dompdf->output();
        unset($dompdf);
        if (! is_string($out) || ! str_starts_with($out, '%PDF')) {
            throw new RuntimeException('The PDF library produced no output.');
        }

        return $out;
    }

    /** Most PHP memory, in MB, one part may be planned to use however much the server allows. */
    private const PART_BUDGET_MB = 150.0;

    /**
     * The end (exclusive) of the part that starts at $from: slides are added while the estimated cost stays inside what
     * the memory limit leaves, never fewer than one. $scale is the ratio by which earlier parts cost more than estimated.
     *
     * @param  array<int, array<string, mixed>>  $slides
     */
    private function chunkEnd(array $slides, int $from, float $scale): int
    {
        $limit = Memory::limit();
        $room = $limit > 0 ? max(4.0, ($limit - memory_get_usage()) * 0.6 / 1048576 - 12.0) : self::PART_BUDGET_MB;
        $budget = min($room, self::PART_BUDGET_MB);

        $sum = 0.0;
        $to = $from;
        $total = count($slides);
        while ($to < $total) {
            $cost = $this->estimateMb($slides[$to]) * $scale;
            if ($to > $from && $sum + $cost > $budget) {
                break;
            }
            $sum += $cost;
            $to++;
        }

        return $to;
    }

    /**
     * Megabytes of PHP memory DomPDF needs for one slide, from a model fitted to measurements: about 0.012 per shape or
     * icon, 0.025 per text box, 0.03 per picture, 0.02 per table cell, 0.05 plus 0.012 per value for a chart, and 0.15
     * for the slide itself, times 3, because mixed real slides measured about 2.4 times what the single-type fit predicts (tables, charts and pictures together), plus a margin. The ratio is learned again while the export runs.
     *
     * @param  array<string, mixed>  $slide
     */
    private function estimateMb(array $slide): float
    {
        $mb = 0.15;
        foreach (Els::visible($slide) as $el) {
            $mb += match ($el['type'] ?? '') {
                'text' => 0.025,
                'image', 'video' => 0.03,
                'table' => 0.02 * max(1, array_sum(array_map(fn ($row) => is_array($row) ? count($row) : 0, (array) ($el['rows'] ?? [])))),
                'chart' => 0.05 + 0.012 * $this->chartValues($el),
                default => 0.012,
            };
        }

        return $mb * 3.0;
    }

    private function chartValues(array $el): int
    {
        $n = 0;
        foreach ((array) ($el['chart']['data']['series'] ?? []) as $series) {
            $n += count((array) ($series['values'] ?? $series['points'] ?? []));
        }

        return $n + count((array) ($el['chart']['data']['kpis'] ?? [])) * 4;
    }

    /**
     * A link to a slide in another part cannot be drawn (DomPDF resolves links inside one file), so say so.
     *
     * @param  array<int, array<string, mixed>>  $slice
     * @param  array<string, true>  $ids
     */
    private function noteCrossLinks(array $slice, array $ids): void
    {
        $local = [];
        foreach ($slice as $slide) {
            $local[Els::str($slide['id'] ?? null)] = true;
        }
        foreach ($slice as $i => $slide) {
            $json = (string) json_encode($slide['els'] ?? []);
            if (! str_contains($json, '#slide:')) {
                continue;
            }
            preg_match_all('/#slide:([A-Za-z0-9_-]+)/', $json, $m);
            foreach (array_unique($m[1]) as $target) {
                if (isset($ids[$target]) && ! isset($local[$target])) {
                    $this->notes[] = ['slide' => $i + 1, 'element' => null, 'note' => 'A link to another slide is not kept: this deck is too big for the server to draw in one piece, so links between its parts are dropped.'];
                    break;
                }
            }
        }
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
