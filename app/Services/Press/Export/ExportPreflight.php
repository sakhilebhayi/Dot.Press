<?php

namespace App\Services\Press\Export;

use App\Services\Press\Export\Pdf\FontRegistry;
use App\Services\Press\Export\Pptx\PptxChart;
use App\Services\Press\Export\Support\ChartData;
use App\Services\Press\Export\Support\Els;
use App\Services\Press\Export\Support\IconLibrary;
use App\Services\Press\Export\Support\ImageComposer;
use App\Services\Press\Export\Support\Raster;
use App\Services\Press\Export\Support\ShapeGeometry;
use App\Services\Press\Export\Support\TextDoc;
use App\Services\Press\Export\Support\Theme;

/**
 * Says, before anything is written, what each element of each slide becomes in the export:
 *  native        carried over exactly, still editable in the target (PPTX) or drawn as the editor draws it (PDF)
 *  approximated  carried over close to the original, not exactly
 *  flattened     turned into a picture or pixels
 *  dropped       not in the file
 * Every entry has a plain language reason.
 */
final class ExportPreflight
{
    public const LEVELS = ['native', 'approximated', 'flattened', 'dropped'];

    /**
     * @param  array{deck: array<string, mixed>, slides: list<array<string, mixed>>}  $doc
     * @param  array{include_hidden?: bool}  $options
     * @return array{format: string, slides: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public static function analyze(array $doc, string $format, array $options = [], ?AssetResolver $assets = null, ?IconLibrary $icons = null): array
    {
        $format = $format === 'pptx' ? 'pptx' : 'pdf';
        $icons ??= new IconLibrary;
        $deck = (array) ($doc['deck'] ?? []);
        $theme = new Theme(is_array($deck['theme'] ?? null) ? $deck['theme'] : null);
        $includeHidden = $format === 'pptx' ? true : ! empty($options['include_hidden']);
        $counts = array_fill_keys(self::LEVELS, 0);
        $warnings = [];
        $slidesOut = [];
        $fonts = [$theme->heading => true, $theme->body => true];
        $skipped = 0;
        $kept = 0;
        $animatedTotal = 0;

        foreach (array_values((array) ($doc['slides'] ?? [])) as $i => $slide) {
            if (! is_array($slide)) {
                continue;
            }
            $hidden = ! empty($slide['hidden']);
            $row = ['index' => $i + 1, 'id' => (string) ($slide['id'] ?? $i), 'title' => (string) ($slide['title'] ?? ''), 'hidden' => $hidden, 'skipped' => false, 'elements' => [], 'notes' => []];
            if ($hidden && ! $includeHidden) {
                $row['skipped'] = true;
                $skipped++;
                $slidesOut[] = $row;

                continue;
            }
            $kept++;
            $bg = $slide['bg'] ?? null;
            if (is_array($bg) && Theme::isGradient($bg) && $format === 'pdf') {
                $row['elements'][] = self::entry('background', 'background', 'flattened', 'The slide background gradient is drawn as a picture in the PDF.');
            }
            if (is_array($bg) && isset($bg['img'])) {
                $a = Els::shortAssetId($bg['img']);
                if ($a === null || ($assets !== null && $assets->find($a) === null)) {
                    $row['elements'][] = self::entry('background', 'background', 'dropped', 'The background image could not be read, so the theme background is used.');
                }
            }
            $t = $slide['transition'] ?? null;
            if (is_array($t) && ! empty($t['type'])) {
                if ($format === 'pdf') {
                    $row['notes'][] = 'A PDF has no slide transitions.';
                } elseif (in_array($t['type'], ['zoom', 'morph'], true)) {
                    $row['notes'][] = ucfirst((string) $t['type']).' transition is exported as a fade.';
                }
            }
            $animated = 0;
            foreach (Els::visible($slide) as $el) {
                if (! empty($el['anim']) && is_array($el['anim'])) {
                    $animated++;
                }
                [$level, $reason] = self::classify($el, $format, $theme, $assets, $icons);
                $row['elements'][] = self::entry((string) ($el['id'] ?? ''), (string) $el['type'], $level, $reason);
                if (isset($el['font']) && is_string($el['font'])) {
                    $fonts[$theme->family($el['font'])] = true;
                }
                foreach (TextDoc::paragraphs(is_array($el['doc'] ?? null) ? $el['doc'] : null) as $p) {
                    foreach ($p['runs'] as $r) {
                        if (! empty($r['font'])) {
                            $fonts[$theme->family($r['font'])] = true;
                        }
                    }
                }
            }
            if ($animated > 0 && $format === 'pptx') {
                $row['notes'][] = 'Animations on '.$animated.' '.($animated === 1 ? 'element are' : 'elements are').' not exported.';
                $animatedTotal += $animated;
            }
            foreach ($row['elements'] as $e) {
                $counts[$e['level']]++;
                if ($e['level'] !== 'native' && $e['reason'] !== null) {
                    $warnings[$e['level'].'|'.$e['reason']] = ($warnings[$e['level'].'|'.$e['reason']] ?? 0) + 1;
                }
            }
            $slidesOut[] = $row;
        }

        $out = [];
        foreach ($warnings as $k => $n) {
            [$level, $reason] = explode('|', $k, 2);
            $out[] = ['level' => $level, 'text' => $reason, 'count' => $n];
        }
        $general = [];
        if ($format === 'pptx') {
            $general[] = $animatedTotal > 0 ? 'Element animations ('.$animatedTotal.' in this deck) are not exported; slide transitions (fade, push, wipe, reveal) are.' : 'Element animations are not exported; slide transitions (fade, push, wipe, reveal) are.';
            $general[] = 'Fonts are not embedded in the file. Machines without '.implode(', ', array_slice(array_keys($fonts), 0, 4)).(count($fonts) > 4 ? ' and others' : '').' show a substitute font.';
        } else {
            $general[] = 'A PDF is a still document: animations, transitions, video and audio do not play.';
        }
        if ($skipped > 0) {
            $general[] = $skipped.' hidden '.($skipped === 1 ? 'slide is' : 'slides are').' left out of the PDF. Turn on "Include hidden slides" to add them.';
        }
        $level = ($counts['flattened'] + $counts['dropped']) > 0 ? 'major' : ($counts['approximated'] > 0 ? 'minor' : 'clean');
        if ($format === 'pdf') {
            // Flattened gradients and masked pictures are normal in a PDF; only dropped content is a real loss.
            $level = $counts['dropped'] > 0 ? 'major' : (($counts['flattened'] + $counts['approximated']) > 0 ? 'minor' : 'clean');
        }

        return [
            'format' => $format,
            'slides' => $slidesOut,
            'summary' => $counts + ['total' => array_sum($counts), 'slides' => $kept, 'skipped_slides' => $skipped, 'level' => $level, 'warnings' => $out, 'general' => $general, 'has_video' => self::hasVideo($doc)],
        ];
    }

    /** @return array{id: string, type: string, level: string, reason: ?string} */
    private static function entry(string $id, string $type, string $level, ?string $reason): array
    {
        return ['id' => $id, 'type' => $type, 'level' => $level, 'reason' => $reason];
    }

    /** Whether the text of an element holds scripts that none of the PDF fonts can draw. */
    private static function hasUnprintableScript(array $el): bool
    {
        $text = TextDoc::plain(is_array($el['doc'] ?? null) ? $el['doc'] : null);
        if (($el['type'] ?? null) === 'table') {
            $text = '';
            foreach ((array) ($el['rows'] ?? []) as $row) {
                foreach ((array) $row as $cell) {
                    $text .= is_array($cell) ? Els::str($cell['t'] ?? null) : '';
                }
            }
        }

        return $text !== '' && preg_match('/[\x{0900}-\x{0DFF}\x{0E00}-\x{0EFF}\x{1100}-\x{11FF}\x{2E80}-\x{9FFF}\x{AC00}-\x{D7AF}\x{F900}-\x{FAFF}\x{1F000}-\x{1FAFF}]/u', $text) === 1;
    }

    /** The first font family of an element that the PDF export does not bundle, or null. */
    private static function unknownFont(array $el, Theme $theme): ?string
    {
        $families = [];
        if (isset($el['font']) && is_string($el['font'])) {
            $families[] = $theme->family($el['font']);
        }
        foreach (TextDoc::paragraphs(is_array($el['doc'] ?? null) ? $el['doc'] : null) as $p) {
            foreach ($p['runs'] as $r) {
                if (! empty($r['font'])) {
                    $families[] = $theme->family($r['font']);
                }
            }
        }
        foreach ($families as $f) {
            if (FontRegistry::canonical($f) === null) {
                return $f;
            }
        }

        return null;
    }

    /** True when the deck holds video (such decks skip the in-request fast path). */
    public static function hasVideo(array $doc): bool
    {
        foreach ((array) ($doc['slides'] ?? []) as $s) {
            foreach (Els::visible(is_array($s) ? $s : []) as $el) {
                if ($el['type'] === 'video') {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array{0: string, 1: ?string} */
    private static function classify(array $el, string $format, Theme $theme, ?AssetResolver $assets, IconLibrary $icons): array
    {
        $type = $el['type'];
        $pdf = $format === 'pdf';
        $shadow = is_array($el['shadow'] ?? null);
        $b = Els::box($el);
        $rotated = abs(fmod($b['rot'], 360)) > 0.001;
        $hasBox = isset($el['fill']) || isset($el['stroke']);

        if ($pdf && in_array($type, ['text', 'shape', 'table'], true)) {
            $unknown = self::unknownFont($el, $theme);
            if ($unknown !== null) {
                return ['approximated', 'The font "'.$unknown.'" is not one of the fonts bundled for PDF export, so Public Sans is used instead.'];
            }
            if (self::hasUnprintableScript($el)) {
                return ['approximated', 'Some characters (for example Chinese, Japanese, Korean, Indic scripts or emoji) are not in the fonts bundled for PDF export and will not print.'];
            }
        }
        switch ($type) {
            case 'text':
                if (($el['fx'] ?? 'none') === 'outline' && $pdf) {
                    return ['approximated', 'Outlined text is drawn as plain text in the PDF.'];
                }
                if ($shadow && ! $hasBox) {
                    return [$pdf ? 'approximated' : 'dropped', 'A shadow on text without a box is not exported.'];
                }
                if ($shadow && $pdf) {
                    return ['approximated', 'Shadows in the PDF have a stepped blur.'];
                }
                if (($el['fit'] ?? 'none') === 'shrink' && $pdf) {
                    return ['approximated', 'Shrink to fit is estimated from the font metrics, so a line may break differently from the player.'];
                }
                if (Theme::isGradient($el['fill'] ?? null) && $pdf) {
                    return ['flattened', 'The gradient behind the text is drawn as a picture in the PDF.'];
                }

                return ['native', null];

            case 'shape':
                $kind = $el['kind'] ?? 'rect';
                if (! in_array($kind, ShapeGeometry::KINDS, true)) {
                    return ['approximated', 'The shape "'.mb_substr((string) $kind, 0, 30).'" is not known and is drawn as a rectangle.'];
                }
                if ($pdf && Theme::isGradient($el['fill'] ?? null) && ! in_array($kind, ['line', 'arrow'], true) && ! Raster::available()) {
                    return ['approximated', 'The server has no image library (GD), so the gradient is drawn as its first colour.'];
                }
                if ($pdf && Theme::isGradient($el['fill'] ?? null) && ! in_array($kind, ['line', 'arrow'], true)) {
                    return ['flattened', 'The gradient fill is drawn as a picture in the PDF.'];
                }
                if ($shadow && $pdf) {
                    return ['approximated', 'Shadows in the PDF have a stepped blur.'];
                }

                return ['native', null];

            case 'image':
                if (! Raster::available()) {
                    return ['dropped', 'The server has no image library (GD), so pictures cannot be processed for this export.'];
                }
                $src = $el['src'] ?? null;
                $id = Els::shortAssetId($src);
                if ($id === null) {
                    return ['dropped', 'The picture points at an external address, which the export does not download.'];
                }
                $asset = $assets?->find($id);
                if ($assets !== null && $asset === null) {
                    return ['dropped', 'The picture file could not be read.'];
                }
                $isSvg = $asset !== null && ($asset['mime'] === 'image/svg+xml' || str_contains(substr($asset['bytes'], 0, 2048), '<svg'));
                $adj = is_array($el['adj'] ?? null) ? $el['adj'] : null;
                $mask = $el['mask'] ?? 'none';
                if ($isSvg) {
                    if ($pdf && ($mask !== 'none' || ImageComposer::hasAdj($adj) || isset($el['crop']))) {
                        return ['approximated', 'Crop, mask and adjustments are not applied to an SVG picture in the PDF.'];
                    }

                    return $pdf ? ['native', null] : ['approximated', 'SVG pictures show in PowerPoint 2016 or later; older versions show nothing.'];
                }
                if ($pdf) {
                    if ($mask !== 'none' || ImageComposer::hasAdj($adj)) {
                        return ['flattened', 'The mask and adjustments are applied to the pixels of the picture.'];
                    }

                    return ['native', null];
                }
                if (ImageComposer::hasAdj($adj) && ! ImageComposer::adjIsNativeOnly($adj)) {
                    return ['flattened', 'Saturation, blur, sepia, hue and partial greyscale are baked into the picture pixels.'];
                }

                return ['native', null];

            case 'video':
                $why = $pdf ? 'Video cannot play in a PDF: the page shows the poster frame or a labelled box.' : 'PowerPoint cannot take this video from a link: the slide gets its poster picture (with a link when the video is online) or a labelled picture.';

                return ['approximated', $why];

            case 'audio':
                return ['approximated', $pdf ? 'Audio cannot play in a PDF: the page shows a labelled box.' : 'Audio files are not embedded: a labelled picture stands in.'];

            case 'icon':
                $name = is_string($el['name'] ?? null) ? $el['name'] : '';
                if (! $icons->has($name)) {
                    return ['approximated', 'The icon "'.mb_substr($name, 0, 40).'" is not in the icon set the exporter reads, so a placeholder square is drawn.'];
                }

                return ['native', null];

            case 'chart':
                $chart = ChartData::normalize($el['chart'] ?? null);
                $t = $chart['type'];
                if ($t === 'map') {
                    return ['approximated', 'A map chart is exported as a table of region values.'];
                }
                if ($pdf) {
                    return ['native', null];
                }
                if ($t === 'kpi') {
                    return ['approximated', 'Key figure tiles are exported as editable text boxes, not a chart.'];
                }
                if ($t === 'waterfall') {
                    return ['approximated', 'The waterfall is a stacked column chart with a hidden base series.'];
                }
                if ($t === 'funnel') {
                    return ['approximated', 'The funnel is a stacked bar chart with a hidden spacer series.'];
                }
                if (! in_array($t, PptxChart::NATIVE, true)) {
                    return ['dropped', 'This chart type has no PowerPoint equivalent.'];
                }
                if ($rotated) {
                    return ['approximated', 'Charts cannot be rotated in PowerPoint, so the chart is exported upright.'];
                }

                return ['native', null];

            case 'table':
                if (! $pdf && $rotated) {
                    return ['approximated', 'Tables cannot be rotated in PowerPoint, so the table is exported upright.'];
                }

                return ['native', null];
        }

        return ['dropped', 'This element type is not exported.'];
    }
}
