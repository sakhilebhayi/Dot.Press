<?php

namespace App\Services\Press;

/**
 * The built-in slide layouts of Dot.Press: title, section, bullets, two, image-left, image-right, image-full,
 * compare, timeline, quote, data, kpi, closing and blank.
 *
 * A layout is a list of placeholders. Each placeholder is a box (geometry as fractions 0..1 of the slide), a role
 * from the spec (title, subtitle, body, media, caption, footer, number, kpi) and the default type that goes in it.
 *
 * GEOMETRY RULES (documented here because they are the contract between the layouts, the templates and the editor)
 *
 * 1. A deck belongs to one of three aspect families, chosen from w / h:
 *      wide      w/h >= 1.55   (16:9, 16:10, 21:9)   reference frame 1920 x 1080
 *      standard  1.15 <= w/h < 1.55   (4:3, 3:2)     reference frame 1440 x 1080
 *      portrait  w/h < 1.15   (9:16, 3:4, square)    reference frame 1080 x 1920
 * 2. Every layout is designed once per family on its reference frame, in reference pixels, on a fixed grid: side
 *    margin 120 / 96 / 84, a title band at the top, a body area under it and a footer line at the bottom.
 *    The pixels are then divided by the reference frame to give fractions, so a deck of any size inside the family
 *    (a legacy 1280 x 720 deck, a 2560 x 1440 deck) gets the same composition.
 * 3. Type sizes are given in reference pixels (a 1920 x 1080 slide for the wide family; the standard family is the same
 *    height at 0.92 of the size, the portrait family 0.9) and are scaled by the deck's height over the reference
 *    height (width over width for portrait). `sizeF` is the size as a fraction of the deck height, which is what the
 *    editor's applyLayout() reads.
 * 4. Colours are theme tokens and fonts are @heading and @body, so a layout follows any theme.
 */
final class LayoutLibrary
{
    public const FAMILIES = ['wide', 'standard', 'portrait'];

    /** Reference frames per family: width, height. */
    private const FRAMES = ['wide' => [1920, 1080], 'standard' => [1440, 1080], 'portrait' => [1080, 1920]];

    /** Type scale per family against the wide frame. */
    private const TYPE_SCALE = ['wide' => 1.0, 'standard' => 0.92, 'portrait' => 0.9];

    /** Side margin per family in reference pixels. */
    private const MARGIN = ['wide' => 120, 'standard' => 96, 'portrait' => 84];

    /**
     * Every layout: id => [label, description, evidence, kind]. `evidence` says what the layout expects:
     * none, image, data, figures, quote, steps or comparison.
     */
    private const META = [
        'title' => ['Title', 'Opens the deck: the title, one line of context and who is presenting.', 'none', 'title'],
        'section' => ['Section', 'A full colour divider that names the next part of the story.', 'none', 'section'],
        'bullets' => ['Statement and points', 'The title on the left, up to five short points on the right and one line that says why it matters.', 'none', 'content'],
        'two' => ['Two columns', 'Two blocks of equal weight side by side: before and after, text and notes, two lists.', 'none', 'content'],
        'image-left' => ['Image left', 'A photograph or diagram takes the left third, the argument sits beside it.', 'image', 'media'],
        'image-right' => ['Image right', 'The argument first, the photograph or diagram on the right third.', 'image', 'media'],
        'image-full' => ['Full image', 'One strong picture edge to edge with a short statement over a solid panel.', 'image', 'media'],
        'compare' => ['Compare', 'Two options, before and after, or us and them, each with a heading and points.', 'comparison', 'content'],
        'timeline' => ['Timeline', 'Four dated steps in order: a roadmap, a process or a history.', 'steps', 'content'],
        'quote' => ['Quote', 'One quotation or one statement set large, with who said it.', 'quote', 'content'],
        'data' => ['Chart and insight', 'A chart or table takes two thirds, the insight and the source sit beside it.', 'data', 'data'],
        'kpi' => ['Key figures', 'Up to four headline numbers with a label each and a sentence that says what they mean.', 'figures', 'data'],
        'closing' => ['Closing', 'Ends the deck: the ask or the next step, and how to reach you.', 'none', 'closing'],
        'blank' => ['Blank', 'An empty slide for anything the other layouts do not cover.', 'none', 'custom'],
    ];

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::META);
    }

    public static function family(int|float $w, int|float $h): string
    {
        $ratio = $h > 0 ? $w / $h : 16 / 9;

        return $ratio >= 1.55 ? 'wide' : ($ratio >= 1.15 ? 'standard' : 'portrait');
    }

    /**
     * Metadata for every layout, without geometry.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        $out = [];
        foreach (self::META as $id => [$label, $description, $evidence, $kind]) {
            $out[] = ['id' => $id, 'name' => $label, 'description' => $description, 'evidence' => $evidence, 'kind' => $kind];
        }

        return $out;
    }

    public static function exists(string $id): bool
    {
        return isset(self::META[$id]);
    }

    /**
     * One layout for a deck size: its metadata plus `ph` (the editor's placeholder list), `definition` (the same
     * boxes in the team layout format of App\Services\Press\LayoutDefinition) and the family it was built for.
     *
     * @return array<string, mixed>|null
     */
    public static function layout(string $id, int $w = 1920, int $h = 1080): ?array
    {
        if (! isset(self::META[$id])) {
            return null;
        }
        [$label, $description, $evidence, $kind] = self::META[$id];
        $family = self::family($w, $h);
        $ph = self::placeholders($id, $w, $h);

        return [
            'id' => $id,
            'name' => $label,
            'description' => $description,
            'evidence' => $evidence,
            'kind' => $kind,
            'family' => $family,
            'ph' => $ph,
            'definition' => self::definition($ph, $h, $w),
            'preview' => array_map(fn (array $p): array => ['role' => $p['role'], 'type' => $p['type'], 'x' => $p['x'], 'y' => $p['y'], 'w' => $p['w'], 'h' => $p['h']], $ph),
        ];
    }

    /**
     * Every layout for a deck size.
     *
     * @return list<array<string, mixed>>
     */
    public static function forSize(int $w = 1920, int $h = 1080): array
    {
        return array_map(fn (string $id): array => self::layout($id, $w, $h), self::ids());
    }

    /**
     * The placeholders of a layout for a deck size, in the format of resources/js/press/editor/core/layouts.js:
     * {role, type: text|media, x, y, w, h (fractions), sizeF, text, name, style} plus `key`, the stable name templates
     * fill (`body2`, `kpi3`), and `id`. `size` is the type size in slide units of this deck.
     *
     * @return list<array<string, mixed>>
     */
    public static function placeholders(string $id, int $w = 1920, int $h = 1080): array
    {
        if (! isset(self::META[$id])) {
            return [];
        }

        $family = self::family($w, $h);
        [$fw, $fh] = self::FRAMES[$family];
        $unit = $family === 'portrait' ? $w / $fw : $h / $fh;
        $k = self::TYPE_SCALE[$family];
        $raw = self::build($id, $family, self::MARGIN[$family], $fw, $fh);

        $out = [];
        foreach ($raw as $p) {
            $size = isset($p['size']) ? (int) round($p['size'] * $k) : null;
            $entry = [
                'id' => $p['key'],
                'key' => $p['key'],
                'role' => $p['role'],
                'type' => $p['type'],
                'name' => $p['name'],
                'x' => round($p['x'] / $fw, 6),
                'y' => round($p['y'] / $fh, 6),
                'w' => round($p['w'] / $fw, 6),
                'h' => round($p['h'] / $fh, 6),
            ];
            if ($size !== null) {
                $entry['size'] = round($size * $unit, 2);
                $entry['sizeF'] = round($size * $unit / $h, 5);
                $entry['text'] = $p['text'];
                $style = $p['style'];
                if (! empty($style['card'])) {
                    // a card is a filled box: its corners follow the theme radius, 12 units until a theme says otherwise
                    unset($style['card']);
                    $style['radius'] = round(12 * $unit, 2);
                }
                $entry['style'] = $style;
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * The same boxes in the team layout format (LayoutDefinition): elements with `units: fraction`.
     *
     * @param  list<array<string, mixed>>  $ph
     * @return list<array<string, mixed>>
     */
    private static function definition(array $ph, int $h, int $w): array
    {
        $out = [];
        foreach ($ph as $p) {
            $el = [
                'id' => $p['key'],
                'type' => $p['type'] === 'media' ? 'image' : 'text',
                'role' => $p['role'],
                'name' => $p['name'],
                'units' => 'fraction',
                'x' => $p['x'], 'y' => $p['y'], 'w' => $p['w'], 'h' => $p['h'],
            ];
            if (isset($p['style'])) {
                $s = $p['style'];
                $el['size'] = max(1, min(1000, (int) round($p['size'])));
                foreach (['font', 'color', 'weight', 'align', 'valign', 'lh', 'fit', 'fill'] as $key) {
                    if (isset($s[$key])) {
                        $el[$key] = $s[$key];
                    }
                }
                $el['hint'] = mb_substr((string) $p['text'], 0, 120);
            }
            $out[] = $el;
        }

        return $out;
    }

    // ------------------------------------------------------------------------------------------------ the grid

    /**
     * @return list<array<string, mixed>> placeholders in reference pixels
     */
    private static function build(string $id, string $family, int $mx, int $fw, int $fh): array
    {
        $cw = $fw - 2 * $mx;
        $portrait = $family === 'portrait';
        $wide = $family === 'wide';

        // The shared bands. title: the heading of a content slide. body: the working area. foot: the footer line.
        $titleY = $portrait ? 120 : 90;
        $titleH = $portrait ? 300 : 170;
        $titleSize = $portrait ? 68 : 60;
        $bodyY = $portrait ? 470 : 300;
        $bodyBottom = $portrait ? 1740 : 960;
        $bodyH = $bodyBottom - $bodyY;
        $footY = $portrait ? 1812 : 996;

        $ph = [];
        $add = function (string $key, string $role, string $type, string $name, float $x, float $y, float $w, float $h, ?int $size = null, string $text = '', array $style = []) use (&$ph): void {
            $ph[] = compact('key', 'role', 'type', 'name', 'x', 'y', 'w', 'h', 'size', 'text', 'style');
        };

        $heading = ['font' => '@heading', 'color' => '@ink', 'align' => 'left', 'valign' => 'top', 'fit' => 'shrink', 'lh' => 1.1, 'ls' => -0.01];
        $text = ['font' => '@body', 'color' => '@ink', 'align' => 'left', 'valign' => 'top', 'fit' => 'shrink', 'lh' => 1.3];
        $muted = ['color' => '@muted'] + $text;
        $footer = ['font' => '@body', 'color' => '@muted', 'align' => 'left', 'valign' => 'middle', 'fit' => 'shrink', 'lh' => 1.2, 'ls' => 0.02];
        $topTitle = fn (string $key = 'title', float $x = 0, float $w = 0) => $add($key, 'title', 'text', 'Title', $x ?: $mx, $titleY, $w ?: $cw, $titleH, $titleSize, 'Title', $heading);
        $addFooter = fn (float $x = 0, float $w = 0) => $add('footer', 'footer', 'text', 'Footer', $x ?: $mx, $footY, $w ?: round($cw * 0.55), 36, 22, 'Section', $footer);

        switch ($id) {
            case 'title':
                if ($portrait) {
                    $add('title', 'title', 'text', 'Title', $mx, 480, $cw, 620, 104, 'Presentation title', ['valign' => 'bottom', 'lh' => 1.05, 'ls' => -0.02, 'fit' => 'none'] + $heading);
                    $add('subtitle', 'subtitle', 'text', 'Subtitle', $mx, 1150, $cw, 260, 44, 'One line that says what this is for', $muted);
                    $add('caption', 'caption', 'text', 'Presenter and date', $mx, 1700, $cw, 80, 28, 'Presenter, role, date', $muted);
                } else {
                    $tw = $wide ? 1320 : 1010;
                    $add('title', 'title', 'text', 'Title', $mx, 280, $tw, 400, $wide ? 112 : 104, 'Presentation title', ['valign' => 'bottom', 'lh' => 1.05, 'ls' => -0.02, 'fit' => 'none'] + $heading);
                    $add('subtitle', 'subtitle', 'text', 'Subtitle', $mx, 724, $wide ? 1100 : 900, 140, 40, 'One line that says what this is for', $muted);
                    $add('caption', 'caption', 'text', 'Presenter and date', $mx, 930, $wide ? 1100 : 900, 56, 26, 'Presenter, role, date', $muted);
                }
                break;

            case 'section':
                if ($portrait) {
                    $add('number', 'number', 'text', 'Section number', $mx, 420, $cw, 400, 300, '01', ['valign' => 'bottom', 'lh' => 0.9, 'ls' => -0.04] + $heading);
                    $add('title', 'title', 'text', 'Section title', $mx, 880, $cw, 420, 100, 'Section title', ['lh' => 1.05, 'ls' => -0.02, 'fit' => 'none'] + $heading);
                    $add('subtitle', 'subtitle', 'text', 'Section summary', $mx, 1340, $cw, 260, 42, 'One line on what this part covers', $muted);
                } else {
                    $add('number', 'number', 'text', 'Section number', $mx, 150, 900, 330, 270, '01', ['valign' => 'bottom', 'lh' => 0.9, 'ls' => -0.04] + $heading);
                    $add('title', 'title', 'text', 'Section title', $mx, 520, $wide ? 1500 : 1180, 220, $wide ? 96 : 90, 'Section title', ['lh' => 1.05, 'ls' => -0.02, 'fit' => 'none'] + $heading);
                    $add('subtitle', 'subtitle', 'text', 'Section summary', $mx, 780, $wide ? 1200 : 1000, 170, 40, 'One line on what this part covers', $muted);
                }
                break;

            case 'bullets':
                if ($portrait) {
                    $topTitle();
                    $add('body', 'body', 'text', 'Points', $mx, $bodyY, $cw, 900, 48, 'Point one', ['ps' => 0.8, 'lh' => 1.32] + $text);
                    $add('subtitle', 'subtitle', 'text', 'Why it matters', $mx, 1440, $cw, 300, 36, 'Why this matters', ['valign' => 'bottom', 'weight' => 600, 'color' => '@accent'] + $text);
                } else {
                    $lw = $wide ? 680 : 500;
                    $gx = $mx + $lw + ($wide ? 80 : 56);
                    $add('title', 'title', 'text', 'Title', $mx, 96, $lw, 580, $wide ? 64 : 58, 'Title', $heading);
                    $add('subtitle', 'subtitle', 'text', 'Why it matters', $mx, 720, $lw, 230, 34, 'Why this matters', ['valign' => 'bottom', 'weight' => 600, 'color' => '@accent'] + $text);
                    $add('body', 'body', 'text', 'Points', $gx, 104, $fw - $mx - $gx, 840, $wide ? 48 : 44, 'Point one', ['ps' => 0.9, 'lh' => 1.32, 'valign' => 'middle'] + $text);
                }
                $addFooter();
                break;

            case 'two':
                $topTitle();
                if ($portrait) {
                    $half = ($bodyH - 40) / 2;
                    $add('body', 'body', 'text', 'Left column', $mx, $bodyY, $cw, min($half, 560), 44, 'First block', ['fill' => '@surface', 'pad' => 36, 'card' => true, 'ps' => 0.6] + $text);
                    $add('body2', 'body', 'text', 'Right column', $mx, $bodyY + $half + 40, $cw, min($half, 560), 44, 'Second block', ['fill' => '@surface', 'pad' => 36, 'card' => true, 'ps' => 0.6] + $text);
                } else {
                    $col = ($cw - 48) / 2;
                    $add('body', 'body', 'text', 'Left column', $mx, $bodyY, $col, min($bodyH - 40, 560), 42, 'First block', ['fill' => '@surface', 'pad' => 36, 'card' => true, 'ps' => 0.6] + $text);
                    $add('body2', 'body', 'text', 'Right column', $mx + $col + 48, $bodyY, $col, min($bodyH - 40, 560), 42, 'Second block', ['fill' => '@surface', 'pad' => 36, 'card' => true, 'ps' => 0.6] + $text);
                }
                $addFooter();
                break;

            case 'image-left':
            case 'image-right':
                $left = $id === 'image-left';
                if ($portrait) {
                    $add('media', 'media', 'media', 'Image', 0, 0, $fw, 800);
                    $add('title', 'title', 'text', 'Title', $mx, 880, $cw, 280, 64, 'Title', $heading);
                    $add('body', 'body', 'text', 'Text', $mx, 1190, $cw, 560, 42, 'Supporting text', $text);
                    $addFooter();
                    break;
                }
                $mw = $wide ? 800 : 560;
                $gap = $wide ? 96 : 64;
                $tx = $left ? $mw + $gap : $mx;
                $tw = $fw - $mw - $gap - $mx;
                $add('media', 'media', 'media', 'Image', $left ? 0 : $fw - $mw, 0, $mw, $fh);
                $add('title', 'title', 'text', 'Title', $tx, 96, $tw, $wide ? 240 : 230, $wide ? 60 : 54, 'Title', $heading);
                $add('body', 'body', 'text', 'Text', $tx, $wide ? 340 : 330, $tw, $wide ? 620 : 630, $wide ? 44 : 38, 'Supporting text', ['ps' => 0.7] + $text);
                $addFooter($tx, $tw);
                break;

            case 'image-full':
                $add('media', 'media', 'media', 'Image', 0, 0, $fw, $fh);
                if ($portrait) {
                    $add('title', 'title', 'text', 'Statement', $mx, 1250, $cw, 360, 76, 'One statement', ['valign' => 'bottom', 'fill' => '@bg', 'pad' => 44] + $heading);
                    $add('caption', 'caption', 'text', 'Caption', $mx, 1640, $cw, 100, 28, 'Where this was taken, or what it shows', ['fill' => '@bg', 'pad' => 28, 'valign' => 'middle'] + $muted);
                } else {
                    $tw = $wide ? 1100 : 900;
                    $add('title', 'title', 'text', 'Statement', $mx, 640, $tw, 240, 72, 'One statement', ['valign' => 'bottom', 'fill' => '@bg', 'pad' => 44] + $heading);
                    $add('caption', 'caption', 'text', 'Caption', $mx, 880, $tw, 90, 26, 'Where this was taken, or what it shows', ['fill' => '@bg', 'pad' => 28, 'valign' => 'middle'] + $muted);
                }
                break;

            case 'compare':
                $topTitle();
                $headH = $portrait ? 90 : 84;
                if ($portrait) {
                    $half = ($bodyH - 40) / 2;
                    foreach ([['subtitle', 'body', 0], ['subtitle2', 'body2', 1]] as [$sk, $bk, $i]) {
                        $y = $bodyY + $i * ($half + 40);
                        $add($sk, 'subtitle', 'text', $i ? 'Right heading' : 'Left heading', $mx, $y, $cw, $headH, 40, $i ? 'Option B' : 'Option A', ['weight' => 700, 'color' => '@accent', 'valign' => 'middle'] + $text);
                        $add($bk, 'body', 'text', $i ? 'Right points' : 'Left points', $mx, $y + $headH + 8, $cw, min($half - $headH - 8, 440), 42, 'Point', ['fill' => '@surface', 'pad' => 36, 'card' => true, 'ps' => 0.6] + $text);
                    }
                } else {
                    $col = ($cw - 64) / 2;
                    foreach ([['subtitle', 'body', 0], ['subtitle2', 'body2', 1]] as [$sk, $bk, $i]) {
                        $x = $mx + $i * ($col + 64);
                        $add($sk, 'subtitle', 'text', $i ? 'Right heading' : 'Left heading', $x, $bodyY, $col, $headH, 40, $i ? 'Option B' : 'Option A', ['weight' => 700, 'color' => '@accent', 'valign' => 'middle'] + $text);
                        $add($bk, 'body', 'text', $i ? 'Right points' : 'Left points', $x, $bodyY + $headH + 28, $col, min($bodyH - $headH - 68, 450), 44, 'Point', ['fill' => '@surface', 'pad' => 36, 'card' => true, 'ps' => 0.6] + $text);
                    }
                }
                $addFooter();
                break;

            case 'timeline':
                $topTitle();
                if ($portrait) {
                    $rowH = ($bodyH - 3 * 24) / 4;
                    for ($i = 0; $i < 4; $i++) {
                        $y = $bodyY + $i * ($rowH + 24);
                        $add('subtitle'.($i ? $i + 1 : ''), 'subtitle', 'text', 'Step '.($i + 1).' label', $mx, $y, 270, 90, 38, 'Step '.($i + 1), ['weight' => 700, 'color' => '@accent'] + $text);
                        $add('body'.($i ? $i + 1 : ''), 'body', 'text', 'Step '.($i + 1).' text', $mx + 310, $y, $cw - 310, $rowH, 36, 'What happens', $text);
                    }
                } else {
                    $gap = $wide ? 48 : 32;
                    $col = ($cw - 3 * $gap) / 4;
                    for ($i = 0; $i < 4; $i++) {
                        $x = $mx + $i * ($col + $gap);
                        $add('subtitle'.($i ? $i + 1 : ''), 'subtitle', 'text', 'Step '.($i + 1).' label', $x, $bodyY + 90, $col, 80, $wide ? 38 : 32, 'Step '.($i + 1), ['weight' => 700, 'color' => '@accent'] + $text);
                        $add('body'.($i ? $i + 1 : ''), 'body', 'text', 'Step '.($i + 1).' text', $x, $bodyY + 190, $col, $bodyH - 190, $wide ? 34 : 30, 'What happens', ['lh' => 1.32] + $text);
                    }
                }
                $addFooter();
                break;

            case 'quote':
                if ($portrait) {
                    $add('body', 'body', 'text', 'Quote', $mx, 640, $cw, 760, 76, 'The quotation', ['valign' => 'top', 'font' => '@heading', 'lh' => 1.15] + $text);
                    $add('caption', 'caption', 'text', 'Who said it', $mx, 1480, $cw, 180, 32, 'Name, role', $muted);
                } else {
                    $qx = $wide ? 200 : 160;
                    $add('body', 'body', 'text', 'Quote', $qx, 340, $fw - 2 * $qx, 420, $wide ? 68 : 62, 'The quotation', ['valign' => 'top', 'font' => '@heading', 'lh' => 1.15, 'ls' => -0.01] + $text);
                    $add('caption', 'caption', 'text', 'Who said it', $qx, 800, $fw - 2 * $qx, 100, 30, 'Name, role', $muted);
                }
                $addFooter();
                break;

            case 'data':
                $topTitle();
                if ($wide) {
                    $mw = 1100;
                    $sx = $mx + $mw + 70;
                    $add('media', 'media', 'media', 'Chart or table', $mx, $bodyY, $mw, $bodyH);
                    $add('body', 'body', 'text', 'Insight', $sx, $bodyY, $fw - $mx - $sx, 520, 36, 'What the data shows', ['ps' => 0.7] + $text);
                    $add('caption', 'caption', 'text', 'Source', $sx, 830, $fw - $mx - $sx, 130, 22, 'Source and date', ['valign' => 'bottom'] + $muted);
                } elseif ($portrait) {
                    $add('media', 'media', 'media', 'Chart or table', $mx, $bodyY, $cw, 880);
                    $add('body', 'body', 'text', 'Insight', $mx, $bodyY + 920, $cw, 250, 38, 'What the data shows', $text);
                    $add('caption', 'caption', 'text', 'Source', $mx, 1660, $cw, 100, 24, 'Source and date', ['valign' => 'bottom'] + $muted);
                } else {
                    $add('media', 'media', 'media', 'Chart or table', $mx, $bodyY - 20, $cw, 420);
                    $add('body', 'body', 'text', 'Insight', $mx, 730, $cw, 190, 32, 'What the data shows', $text);
                    $add('caption', 'caption', 'text', 'Source', $mx, 930, $cw, 50, 22, 'Source and date', ['valign' => 'bottom'] + $muted);
                }
                $addFooter();
                break;

            case 'kpi':
                $topTitle();
                $kSize = $portrait ? 150 : 104;
                if ($portrait) {
                    $rowH = 250;
                    for ($i = 0; $i < 4; $i++) {
                        $y = $bodyY + 10 + $i * ($rowH + 16);
                        $add('kpi'.($i + 1), 'kpi', 'text', 'Figure '.($i + 1), $mx, $y, 480, $rowH - 20, $kSize, '0', ['font' => '@heading', 'color' => '@accent', 'valign' => 'middle', 'lh' => 1.0, 'ls' => -0.03] + $text);
                        $add('cap'.($i + 1), 'caption', 'text', 'Label '.($i + 1), $mx + 520, $y, $cw - 520, $rowH - 20, 32, 'What it measures', ['valign' => 'middle'] + $text);
                    }
                    $add('body', 'body', 'text', 'Takeaway', $mx, 1640, $cw, 130, 34, 'What these figures mean', ['weight' => 600] + $text);
                } elseif ($wide) {
                    $gap = 48;
                    $col = ($cw - 3 * $gap) / 4;
                    for ($i = 0; $i < 4; $i++) {
                        $x = $mx + $i * ($col + $gap);
                        $add('kpi'.($i + 1), 'kpi', 'text', 'Figure '.($i + 1), $x, 330, $col, 200, $kSize, '0', ['font' => '@heading', 'color' => '@accent', 'valign' => 'bottom', 'lh' => 1.0, 'ls' => -0.03] + $text);
                        $add('cap'.($i + 1), 'caption', 'text', 'Label '.($i + 1), $x, 560, $col, 190, 32, 'What it measures', $text);
                    }
                    $add('body', 'body', 'text', 'Takeaway', $mx, 800, $cw, 160, 40, 'What these figures mean', ['weight' => 600] + $text);
                } else {
                    $gap = 48;
                    $col = ($cw - $gap) / 2;
                    for ($i = 0; $i < 4; $i++) {
                        $x = $mx + ($i % 2) * ($col + $gap);
                        $y = 290 + intdiv($i, 2) * 270;
                        $add('kpi'.($i + 1), 'kpi', 'text', 'Figure '.($i + 1), $x, $y, $col, 150, 118, '0', ['font' => '@heading', 'color' => '@accent', 'valign' => 'bottom', 'lh' => 1.0, 'ls' => -0.03] + $text);
                        $add('cap'.($i + 1), 'caption', 'text', 'Label '.($i + 1), $x, $y + 160, $col, 90, 28, 'What it measures', $text);
                    }
                    $add('body', 'body', 'text', 'Takeaway', $mx, 850, $cw, 120, 32, 'What these figures mean', ['weight' => 600] + $text);
                }
                $addFooter();
                break;

            case 'closing':
                if ($portrait) {
                    $add('title', 'title', 'text', 'Closing line', $mx, 420, $cw, 620, 100, 'Thank you', ['valign' => 'bottom', 'lh' => 1.05, 'ls' => -0.02, 'fit' => 'none'] + $heading);
                    $add('subtitle', 'subtitle', 'text', 'The ask', $mx, 1100, $cw, 280, 44, 'The decision or next step', $muted);
                    $add('body', 'body', 'text', 'Contact', $mx, 1560, $cw, 220, 32, 'Name, email, phone', $muted);
                } else {
                    $add('title', 'title', 'text', 'Closing line', $mx, 240, $wide ? 1400 : 1100, 380, $wide ? 104 : 96, 'Thank you', ['valign' => 'bottom', 'lh' => 1.05, 'ls' => -0.02, 'fit' => 'none'] + $heading);
                    $add('subtitle', 'subtitle', 'text', 'The ask', $mx, 660, $wide ? 1100 : 900, 150, 42, 'The decision or next step', $muted);
                    $add('body', 'body', 'text', 'Contact', $mx, 850, $wide ? 1100 : 900, 140, 30, 'Name, email, phone', $muted);
                }
                break;

            case 'blank':
                break;
        }

        // finish: every placeholder shares the same keys, text ones carry sizes and styles
        return array_map(function (array $p): array {
            if ($p['type'] === 'media') {
                $p['size'] = null;
            }

            return $p;
        }, $ph);
    }
}
