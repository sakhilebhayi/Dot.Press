<?php

namespace App\Services\Press\Templates;

use App\Services\Press\LayoutLibrary;
use App\Services\Press\ThemeTokens;

/**
 * Builds the slides of a template from the built-in layouts, so every template shares one grid and a slide re-flows
 * when its layout is changed in the editor. A template file calls the semantic helpers (cover, section, points, ...)
 * and never touches coordinates.
 *
 * Content slots are the placeholder keys of the layout (title, subtitle, body, body2, caption, media, kpi1, cap1 ...).
 * A slot value is a string (paragraphs split on newlines, **bold** marks a phrase), a list of strings (bullets),
 * ['ol' => [...]] (numbered) or, for a media slot, a spec made by chart(), table(), image() or icon().
 * Empty slots are left out: a template never carries an empty text element.
 */
final class Compose
{
    /** @var list<array<string, mixed>> */
    private array $slides = [];

    /** @var list<array{id: string, title: string}> */
    private array $sections = [];

    private ?string $sectionId = null;

    private ?string $sectionTitle = null;

    private int $decor = 0;

    private bool $dark;

    /**
     * @param  array<string, mixed>  $theme  a theme as ThemeLibrary::find() returns it
     * @param  array{label?: string, transition?: ?array<string, mixed>, cover?: string, icon?: string}  $options
     */
    public function __construct(
        public readonly int $w,
        public readonly int $h,
        private readonly array $theme,
        private readonly array $options = [],
    ) {
        $c = $theme['c'];
        $this->dark = ThemeTokens::luminance($c['bg']) < ThemeTokens::luminance($c['ink']);
    }

    public function family(): string
    {
        return LayoutLibrary::family($this->w, $this->h);
    }

    // ------------------------------------------------------------------------------------------ content specs

    /**
     * @param  list<string>  $categories
     * @param  list<array<string, mixed>>  $series  [{name, values, kind?}]
     * @param  array<string, mixed>  $opt
     * @return array<string, mixed>
     */
    public static function chart(string $type, array $categories, array $series, array $opt = [], array $extra = []): array
    {
        $opt += ['legend' => count($series) > 1 ? 'bottom' : 'none', 'labels' => 'none', 'stacked' => false, 'smooth' => false, 'grid' => true];

        return ['chart' => [
            'type' => $type,
            'data' => ['categories' => $categories, 'series' => $series] + $extra,
            'opt' => $opt,
            'src' => ['kind' => 'manual'],
        ]];
    }

    /**
     * @param  list<list<string>>  $rows  the first row is the header
     * @param  list<float>  $colw
     * @param  list<string>  $align  per column: l, c or r
     * @return array<string, mixed>
     */
    public static function table(array $rows, array $colw = [], array $align = [], ?float $rowH = null, ?int $size = null): array
    {
        return ['table' => ['rows' => $rows, 'colw' => $colw, 'align' => $align, 'rowh' => $rowH, 'size' => $size]];
    }

    /** @return array<string, mixed> */
    public static function image(string $hint): array
    {
        return ['image' => $hint];
    }

    /** @return array<string, mixed> */
    public static function icon(string $name): array
    {
        return ['icon' => $name];
    }

    // ------------------------------------------------------------------------------------------ structure

    public function beginSection(string $title): void
    {
        $id = 's'.(count($this->sections) + 1);
        $this->sections[] = ['id' => $id, 'title' => $title];
        $this->sectionId = $id;
        $this->sectionTitle = $title;
    }

    /** @return list<array<string, mixed>> */
    public function slides(): array
    {
        return $this->slides;
    }

    /** @return list<array{id: string, title: string}> */
    public function sections(): array
    {
        return $this->sections;
    }

    // ------------------------------------------------------------------------------------------ slide helpers

    /** The opening slide: dark or accent ground, large title, one emblem. */
    public function cover(string $title, string $subtitle, string $meta, string $notes): void
    {
        $this->slide('title', ['title' => $title, 'subtitle' => $subtitle, 'caption' => $meta], $notes, ['inverse' => true, 'cover' => true, 'footer' => false]);
    }

    public function section(string $number, string $title, string $summary, string $notes): void
    {
        $this->beginSection($title);
        $this->slide('section', ['number' => $number, 'title' => $title, 'subtitle' => $summary], $notes, ['accent' => true, 'footer' => false]);
    }

    /**
     * Statement on the left, points on the right, one "why it matters" line under the statement.
     *
     * @param  list<string>|array<string, mixed>  $points
     */
    public function points(string $title, array $points, string $notes, string $why = '', array $opt = []): void
    {
        if (array_is_list($points) && $points !== [] && count(array_filter($points, fn ($p) => is_string($p) && str_starts_with($p, '**'))) === count($points)) {
            $points = ['p' => $points];
        }
        $this->slide('bullets', ['title' => $title, 'body' => $points, 'subtitle' => $why], $notes, $opt);
    }

    /**
     * @param  list<string>|array<string, mixed>  $left
     * @param  list<string>|array<string, mixed>  $right
     */
    public function columns(string $title, array|string $left, array|string $right, string $notes, array $opt = []): void
    {
        $this->slide('two', ['title' => $title, 'body' => $left, 'body2' => $right], $notes, $opt);
    }

    /**
     * @param  array{0: string, 1: list<string>}  $left  heading and points
     * @param  array{0: string, 1: list<string>}  $right
     */
    public function compare(string $title, array $left, array $right, string $notes, array $opt = []): void
    {
        $this->slide('compare', ['title' => $title, 'subtitle' => $left[0], 'body' => $left[1], 'subtitle2' => $right[0], 'body2' => $right[1]], $notes, $opt);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $steps  up to four: label and text
     */
    public function timeline(string $title, array $steps, string $notes, array $opt = []): void
    {
        $content = ['title' => $title];
        foreach (array_slice($steps, 0, 4) as $i => [$label, $text]) {
            $content['subtitle'.($i ? $i + 1 : '')] = $label;
            $content['body'.($i ? $i + 1 : '')] = $text;
        }
        $this->slide('timeline', $content, $notes, $opt);
    }

    public function quote(string $quote, string $who, string $notes, array $opt = []): void
    {
        $this->slide('quote', ['body' => $quote, 'caption' => $who], $notes, $opt + ['tint' => true]);
    }

    /**
     * A chart or table with the insight beside it. Pass $sample = true when the numbers are illustrative; the caption
     * then starts with "Sample data, replace with yours."
     *
     * @param  array<string, mixed>  $media
     * @param  list<string>|string  $insight
     */
    public function data(string $title, array $media, array|string $insight, string $source, string $notes, bool $sample = true, array $opt = []): void
    {
        $caption = ($sample ? 'Sample data, replace with yours. ' : '').$source;
        $this->slide('data', ['title' => $title, 'media' => $media, 'body' => $insight, 'caption' => trim($caption)], $notes, $opt);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $figures  up to four: the number and what it measures
     */
    public function figures(string $title, array $figures, string $takeaway, string $notes, bool $sample = true, array $opt = []): void
    {
        $content = ['title' => $title, 'body' => $takeaway];
        foreach (array_slice($figures, 0, 4) as $i => [$value, $label]) {
            $content['kpi'.($i + 1)] = $value;
            $content['cap'.($i + 1)] = $label;
        }
        if ($sample) {
            $opt['sampleNote'] = true;
        }
        if (count($figures) === 3) {
            $opt['spread3'] = true;
        }
        $this->slide('kpi', $content, $notes, $opt);
    }

    /** @param list<string>|string $text */
    public function imageSide(string $side, string $title, array|string $text, string $imageHint, string $notes, array $opt = []): void
    {
        $this->slide($side === 'left' ? 'image-left' : 'image-right', ['title' => $title, 'body' => $text, 'media' => self::image($imageHint)], $notes, $opt);
    }

    public function imageFull(string $statement, string $caption, string $imageHint, string $notes, array $opt = []): void
    {
        $this->slide('image-full', ['title' => $statement, 'caption' => $caption, 'media' => self::image($imageHint)], $notes, $opt);
    }

    public function closing(string $title, string $ask, string $contact, string $notes): void
    {
        $this->slide('closing', ['title' => $title, 'subtitle' => $ask, 'body' => $contact], $notes, ['inverse' => true, 'cover' => true, 'footer' => false]);
    }

    // ------------------------------------------------------------------------------------------ the engine

    /**
     * Build one slide from a layout and its content.
     *
     * Options: inverse (cover ground), accent (accent ground), cover (draw the cover decoration), footer (false to
     * omit), reveal (list of slots that appear on click, in order), tweak (slot => element patch).
     *
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $opt
     */
    public function slide(string $layout, array $content, string $notes, array $opt = []): void
    {
        $ph = LayoutLibrary::placeholders($layout, $this->w, $this->h);
        $inverse = ! empty($opt['inverse']);
        $accent = ! empty($opt['accent']);
        $this->decor = 0;
        if (! empty($opt['spread3']) && $this->family() !== 'portrait') {
            $ph = $this->spreadThree($ph);
        }

        if (($opt['footer'] ?? true) !== false && ! isset($content['footer']) && $this->sectionTitle !== null) {
            $label = (string) ($this->options['label'] ?? '');
            $content['footer'] = $label !== '' ? $label.'  /  '.$this->sectionTitle : $this->sectionTitle;
        }

        $els = [];
        $byKey = [];
        foreach ($ph as $p) {
            $value = $content[$p['key']] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $built = $p['type'] === 'media' ? $this->mediaElements($p, $value) : [$this->textElement($p, $value, $inverse, $accent)];
            foreach ($built as $el) {
                if (isset($opt['tweak'][$p['key']]) && ($el['id'] === $p['key'])) {
                    $el = $this->tweak($el, $opt['tweak'][$p['key']]);
                }
                $byKey[$p['key']][] = $el['id'];
                $els[] = $el;
            }
        }

        if (! empty($opt['cover']) && $this->family() !== 'portrait' && ($this->options['cover'] ?? 'band') !== 'rule') {
            $limit = $this->w * (1 - (($this->options['cover'] ?? 'band') === 'split' ? 0.34 : 0.18)) - 56;
            $els = array_map(function (array $el) use ($limit): array {
                if ($limit < $el['x'] + $el['w']) {
                    $el['w'] = $this->n($limit - $el['x']);
                }

                return $el;
            }, $els);
        }

        // decoration sits under the content, so it goes first
        $under = $this->decoration($layout, $ph, $content, $inverse, $accent, ! empty($opt['cover']));
        $els = array_merge($under, $els);

        if (! empty($opt['sampleNote'])) {
            $els = $this->withSampleNote($els);
        }

        foreach ($this->revealGroups($layout, $opt['reveal'] ?? [], $content) as $i => $group) {
            foreach ($group as $n => $key) {
                foreach ($byKey[$key] ?? [] as $id) {
                    $els = array_map(function (array $el) use ($id, $i, $n): array {
                        if ($el['id'] === $id) {
                            $el['anim'] = [['id' => 'a'.($i + 1).($n ? 'b' : ''), 'ph' => 'in', 'fx' => 'rise', 'on' => $n === 0 ? 'click' : 'with', 'delay' => 0, 'dur' => 450, 'ease' => 'out']];
                        }

                        return $el;
                    }, $els);
                }
            }
        }

        $bg = null;
        if (! empty($opt['tint'])) {
            $bg = '@surface';
        }
        if ($accent) {
            $bg = '@accent';
        } elseif ($inverse) {
            $bg = $this->dark ? ['g' => 'linear', 'a' => 160, 'stops' => [[0, '@bg'], [1, '@surface']]] : '@ink';
        }

        $title = $opt['outline'] ?? $content['title'] ?? ($content['body'] ?? '');
        $this->slides[] = [
            'title' => mb_strimwidth($this->plain(is_array($title) ? (string) ($title[0] ?? '') : (string) $title), 0, 90, '...'),
            'notes' => $notes,
            'layout' => $layout,
            'section_id' => $this->sectionId,
            'bg' => $bg,
            'transition' => $this->slides === [] ? null : ($this->options['transition'] ?? null),
            'els' => $els,
        ];
    }

    // ------------------------------------------------------------------------------------------ elements

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function textElement(array $p, mixed $value, bool $inverse, bool $accent): array
    {
        $s = $p['style'];
        $color = $s['color'] ?? '@ink';
        $op = 1;
        if ($inverse || $accent) {
            // On the ink or accent ground the text takes the page colour. A dark theme is already dark: its cover keeps its own inks.
            $onDark = $accent || ! $this->dark;
            if ($onDark) {
                if ($color === '@muted') {
                    $op = $accent ? 0.9 : 0.78;
                } elseif ($color === '@accent') {
                    $op = 0.9;
                }
                $color = '@bg';
            }
            if ($p['role'] === 'number') {
                $op = 0.4;
            }
        }

        $el = [
            'id' => $p['key'],
            'type' => 'text',
            'name' => $p['name'],
            'role' => $p['role'],
            'origin' => 'template',
            'x' => $this->n($p['x'] * $this->w),
            'y' => $this->n($p['y'] * $this->h),
            'w' => $this->n($p['w'] * $this->w),
            'h' => $this->n($p['h'] * $this->h),
            'doc' => $this->doc($value),
            'font' => $s['font'],
            'size' => $this->n($p['size']),
            'color' => $color,
            'align' => $s['align'],
            'valign' => $s['valign'],
            'lh' => $s['lh'],
            'fit' => $s['fit'],
        ];
        if (isset($s['weight'])) {
            $el['weight'] = $s['weight'];
        }
        if (isset($s['ls'])) {
            $el['ls'] = $s['ls'];
        }
        if (isset($s['ps'])) {
            $el['ps'] = $s['ps'];
        }
        if (isset($s['pad'])) {
            $el['pad'] = $this->n($s['pad'] * $this->typeUnit());
        }
        if (isset($s['fill'])) {
            $el['fill'] = $s['fill'];
            if (isset($s['radius'])) {
                $el['radius'] = $this->n($this->theme['s']['radius'] * $this->h / 1080);
            }
        }
        if ($op !== 1) {
            $el['op'] = $op;
        }

        return $el;
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  array<string, mixed>  $spec
     * @return list<array<string, mixed>>
     */
    private function mediaElements(array $p, array $spec): array
    {
        $x = $this->n($p['x'] * $this->w);
        $y = $this->n($p['y'] * $this->h);
        $w = $this->n($p['w'] * $this->w);
        $h = $this->n($p['h'] * $this->h);
        $base = ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'role' => 'media', 'origin' => 'template'];

        if (isset($spec['chart'])) {
            return [['id' => 'media', 'type' => 'chart', 'name' => 'Chart'] + $base + ['chart' => $spec['chart'], 'alt' => $this->chartAlt($spec['chart'])]];
        }

        if (isset($spec['table'])) {
            $t = $spec['table'];
            $rows = [];
            foreach ($t['rows'] as $r => $row) {
                $cells = [];
                foreach ($row as $c => $text) {
                    $cell = ['t' => (string) $text];
                    $al = $t['align'][$c] ?? null;
                    if ($al) {
                        $cell['al'] = $al;
                    }
                    $cells[] = $cell;
                }
                $rows[] = $cells;
            }
            $count = count($rows);
            $size = $t['size'] ?? (int) round(30 * $this->typeUnit() * ($this->family() === 'portrait' ? 1.15 : 1));
            $rowH = $t['rowh'] ?? min($h / $count, $size * 2.4);

            return [[
                'id' => 'media', 'type' => 'table', 'name' => 'Table', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $this->n($rowH * $count), 'role' => 'media', 'origin' => 'template',
                'rows' => $rows, 'colw' => $t['colw'] ?: array_fill(0, count($rows[0]), round(1 / count($rows[0]), 4)),
                'rowh' => array_fill(0, $count, $this->n($rowH)), 'header' => true, 'band' => false, 'size' => $this->n($size), 'font' => '@body', 'lines' => 'rows',
            ]];
        }

        if (isset($spec['image'])) {
            $iconSize = $this->n(min($w, $h) * 0.16);
            $label = $this->n(min($w, $h) * 0.045);

            return [
                [
                    'id' => 'media', 'type' => 'shape', 'name' => 'Image placeholder', 'kind' => 'rect', 'fill' => '@surface', 'sw' => 0, 'radius' => 0,
                    'doc' => $this->doc($spec['image']), 'font' => '@body', 'size' => max(18, $label), 'color' => '@muted', 'align' => 'center', 'valign' => 'middle',
                    'lh' => 1.3, 'pad' => $this->n($w * 0.12), 'fit' => 'shrink', 'alt' => 'Image placeholder: '.$this->plain($spec['image']),
                ] + $base,
                [
                    'id' => 'media-icon', 'type' => 'icon', 'name' => 'image', 'x' => $this->n($x + $w / 2 - $iconSize / 2), 'y' => $this->n($y + $h / 2 - $iconSize * 1.9),
                    'w' => $iconSize, 'h' => $iconSize, 'origin' => 'template', 'color' => '@muted', 'sw' => 1.25,
                ],
            ];
        }

        if (isset($spec['icon'])) {
            $s = $this->n(min($w, $h) * 0.5);

            return [[
                'id' => 'media', 'type' => 'icon', 'name' => (string) $spec['icon'], 'x' => $this->n($x + ($w - $s) / 2), 'y' => $this->n($y + ($h - $s) / 2), 'w' => $s, 'h' => $s,
                'role' => 'media', 'origin' => 'template', 'color' => '@accent', 'sw' => 1.25,
            ]];
        }

        return [];
    }

    /** Type size multiplier for this deck: the layout library's rule (see LayoutLibrary). */
    private function typeUnit(): float
    {
        return match ($this->family()) {
            'portrait' => 0.9 * $this->w / 1080,
            'standard' => 0.92 * $this->h / 1080,
            default => $this->h / 1080,
        };
    }

    /** @param array<string, mixed> $chart */
    private function chartAlt(array $chart): string
    {
        $names = array_map(fn ($s) => $s['name'], $chart['data']['series']);

        return ucfirst($chart['type']).' chart of '.implode(', ', $names).' (sample data)';
    }

    /**
     * Decorative shapes: dividers, rails, bands. They carry no role, so re-applying a layout never moves them.
     *
     * @param  list<array<string, mixed>>  $ph
     * @param  array<string, mixed>  $content
     * @return list<array<string, mixed>>
     */
    private function decoration(string $layout, array $ph, array $content, bool $inverse, bool $accent, bool $cover): array
    {
        $W = $this->w;
        $H = $this->h;
        $family = $this->family();
        $by = [];
        foreach ($ph as $p) {
            $by[$p['key']] = $p;
        }
        $px = fn (string $key, string $edge): float => match ($edge) {
            'x' => $by[$key]['x'] * $W,
            'y' => $by[$key]['y'] * $H,
            'r' => ($by[$key]['x'] + $by[$key]['w']) * $W,
            'b' => ($by[$key]['y'] + $by[$key]['h']) * $H,
            'w' => $by[$key]['w'] * $W,
            'h' => $by[$key]['h'] * $H,
        };
        $out = [];
        $rect = function (string $name, float $x, float $y, float $w, float $h, string $fill, float $op = 1) use (&$out): void {
            $id = 'd'.(count($out) + 1);
            $el = ['id' => $id, 'type' => 'shape', 'name' => $name, 'kind' => 'rect', 'x' => $this->n($x), 'y' => $this->n($y), 'w' => $this->n($w), 'h' => $this->n($h), 'fill' => $fill, 'sw' => 0, 'radius' => 0, 'origin' => 'template'];
            if ($op !== 1.0) {
                $el['op'] = $op;
            }
            $out[] = $el;
        };

        if ($cover) {
            $style = $this->options['cover'] ?? 'band';
            $icon = $this->options['icon'] ?? 'target';
            $onInk = ! $this->dark;
            $portrait = $family === 'portrait';
            $kickerY = $portrait ? $H * 0.2 : 100;
            if ($style === 'rule') {
                $rect('Accent rule', $by['title']['x'] * $W, $kickerY - 34, $W * ($portrait ? 0.2 : 0.07), $H * 0.008, '@accent');
            } else {
                $split = $style === 'split';
                if ($portrait) {
                    $bh = $H * ($split ? 0.3 : 0.16);
                    $rect('Accent band', 0, 0, $W, $bh, '@accent');
                    $is = $this->n($bh * 0.46);
                    $out[] = $this->emblem($icon, $W - $is - $W * 0.078, ($bh - $is) / 2, $is, $onInk);
                } else {
                    $bw = $W * ($split ? 0.34 : 0.18);
                    $rect('Accent band', $W - $bw, 0, $bw, $H, '@accent');
                    $is = $this->n($bw * ($split ? 0.52 : 0.46));
                    $out[] = $this->emblem($icon, $W - $bw + ($bw - $is) / 2, ($H - $is) / 2, $is, $onInk);
                }
            }

            $unit = $this->typeUnit();
            $kicker = mb_strtoupper((string) ($this->options['label'] ?? ''));
            if ($kicker !== '' && $layout === 'title') {
                $out[] = [
                    'id' => 'kicker', 'type' => 'text', 'name' => 'Label', 'x' => $this->n($by['title']['x'] * $W), 'y' => $this->n($kickerY), 'w' => $this->n($W * ($portrait ? 0.7 : 0.5)), 'h' => $this->n(40 * $unit),
                    'doc' => $this->doc($kicker), 'font' => '@body', 'size' => $this->n(24 * $unit), 'weight' => 600, 'ls' => 0.14, 'color' => $this->dark ? '@accent' : '@bg', 'align' => 'left', 'valign' => 'middle', 'lh' => 1.2, 'fit' => 'shrink', 'op' => $this->dark ? 1 : 0.85, 'origin' => 'template',
                ];
            }

            return $out;
        }

        switch ($layout) {
            case 'bullets':
                if ($family !== 'portrait') {
                    $x = ($by['body']['x'] * $W + $by['subtitle']['x'] * $W + $by['subtitle']['w'] * $W) / 2;
                    $rect('Divider', $x - 1, 104, 2, $H * 0.78, '@line');
                }
                break;
            case 'compare':
                foreach (['subtitle', 'subtitle2'] as $k) {
                    $rect('Heading rule', $px($k, 'x'), $px($k, 'b') + 4, $px($k, 'w'), $H * 0.004, '@accent');
                }
                break;
            case 'timeline':
                if ($family === 'portrait') {
                    $x = $px('subtitle', 'x') + 290;
                    $rect('Rail', $x - 1, $px('subtitle', 'y'), 2, $px('body4', 'b') - $px('subtitle', 'y'), '@line');
                } else {
                    $y = $px('subtitle', 'y') - 52;
                    $rect('Rail', $px('subtitle', 'x'), $y - 2, $px('body4', 'r') - $px('subtitle', 'x'), 4, '@line');
                    foreach (['subtitle', 'subtitle2', 'subtitle3', 'subtitle4'] as $i => $k) {
                        if (! isset($content[$k])) {
                            continue;
                        }
                        $d = $H * 0.026;
                        $id = 'd'.(count($out) + 1);
                        $out[] = ['id' => $id, 'type' => 'shape', 'name' => 'Step marker', 'kind' => 'ellipse', 'x' => $this->n($px($k, 'x')), 'y' => $this->n($y - $d / 2), 'w' => $this->n($d), 'h' => $this->n($d), 'fill' => '@accent', 'sw' => 0, 'origin' => 'template'];
                    }
                }
                break;
            case 'kpi':
                for ($i = 1; $i <= 4; $i++) {
                    if (isset($content['kpi'.$i])) {
                        $rect('Figure rule', $px('kpi'.$i, 'x'), $px('kpi'.$i, 'y') - 10, $px('kpi'.$i, 'w'), $family === 'portrait' ? 2 : 4, '@line');
                    }
                }
                break;
            case 'quote':
                $q = $by['body'];
                $size = $this->n($family === 'portrait' ? 380 : 340);
                $out[] = ['id' => 'd1', 'type' => 'text', 'name' => 'Quote mark', 'x' => $this->n($q['x'] * $W - ($family === 'portrait' ? 6 : 14)), 'y' => $this->n($family === 'portrait' ? 330 : 150), 'w' => $this->n($W * 0.2), 'h' => $this->n($size * 0.75),
                    'doc' => $this->doc("\u{201C}"), 'font' => '@heading', 'size' => $size, 'color' => '@accent', 'align' => 'left', 'valign' => 'top', 'lh' => 1, 'fit' => 'none', 'origin' => 'template'];
                break;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function emblem(string $icon, float $x, float $y, float $size, bool $onInk): array
    {
        return ['id' => 'emblem', 'type' => 'icon', 'name' => $icon, 'x' => $this->n($x), 'y' => $this->n($y), 'w' => $this->n($size), 'h' => $this->n($size), 'origin' => 'template', 'color' => '@bg', 'sw' => 1.1];
    }

    /**
     * @param  list<array<string, mixed>>  $els
     * @return list<array<string, mixed>>
     */
    private function withSampleNote(array $els): array
    {
        $by = [];
        foreach (LayoutLibrary::placeholders('kpi', $this->w, $this->h) as $p) {
            $by[$p['key']] = $p;
        }
        $f = $by['footer'];
        $portrait = $this->family() === 'portrait';
        $mx = $f['x'];
        $width = $portrait ? 1 - 2 * $mx : 0.38;
        $note = $this->textElement(
            ['key' => 'note', 'role' => 'caption', 'name' => 'Sample data note', 'x' => $portrait ? $mx : 1 - $mx - $width, 'y' => $f['y'] - ($portrait ? 0.025 : 0), 'w' => $width, 'h' => $f['h'], 'size' => $f['size'],
                'style' => ['font' => '@body', 'color' => '@muted', 'align' => $portrait ? 'left' : 'right', 'valign' => 'middle', 'fit' => 'shrink', 'lh' => 1.2]],
            'Sample data, replace with yours',
            false,
            false,
        );
        $els[] = $note;

        return $els;
    }

    /**
     * What appears on each click. true means the natural steps of the layout (a figure and its label, a column and its
     * heading); 'right' reveals only the right hand column of a comparison; a list names slots (a string is one slot,
     * a list of slots appears together).
     *
     * @param  array<string, mixed>|bool|string  $reveal
     * @param  array<string, mixed>  $content
     * @return list<list<string>>
     */
    private function revealGroups(string $layout, array|bool|string $reveal, array $content): array
    {
        if ($reveal === true) {
            if ($layout === 'kpi') {
                $groups = [];
                for ($i = 1; $i <= 4; $i++) {
                    if (isset($content['kpi'.$i])) {
                        $groups[] = ['kpi'.$i, 'cap'.$i];
                    }
                }

                return $groups;
            }

            return $layout === 'compare' ? [['subtitle', 'body'], ['subtitle2', 'body2']] : [['body'], ['body2']];
        }
        if ($reveal === 'right') {
            return [['subtitle2', 'body2']];
        }

        return array_map(fn ($g): array => (array) $g, is_array($reveal) ? array_values($reveal) : []);
    }

    /**
     * Three figures use three equal columns instead of four.
     *
     * @param  list<array<string, mixed>>  $ph
     * @return list<array<string, mixed>>
     */
    private function spreadThree(array $ph): array
    {
        $by = array_column($ph, null, 'key');
        $left = $by['kpi1']['x'];
        $total = $by['kpi4']['x'] + $by['kpi4']['w'] - $left;
        $gap = 48 / ($this->family() === 'standard' ? 1440 : 1920);
        $col = ($total - 2 * $gap) / 3;

        return array_map(function (array $p) use ($left, $gap, $col): array {
            if (preg_match('/^(?:kpi|cap)([1-3])$/', $p['key'], $m)) {
                $p['x'] = $left + ((int) $m[1] - 1) * ($col + $gap);
                $p['w'] = $col;
            }

            return $p;
        }, $ph);
    }

    /** @param array<string, mixed> $el */
    private function tweak(array $el, array $patch): array
    {
        return $patch + $el;
    }

    // ------------------------------------------------------------------------------------------ text documents

    /** @return array<string, mixed> */
    private function doc(mixed $value): array
    {
        if (is_string($value)) {
            $blocks = array_map(fn (string $line): array => $this->paragraph($line), preg_split('/\R/u', $value) ?: ['']);

            return ['type' => 'doc', 'content' => $blocks];
        }

        $ordered = isset($value['ol']);
        $items = $ordered ? $value['ol'] : (isset($value['p']) ? $value['p'] : $value);
        if (isset($value['p'])) {
            return ['type' => 'doc', 'content' => array_map(fn (string $line): array => $this->paragraph($line), $items)];
        }

        return ['type' => 'doc', 'content' => [[
            'type' => $ordered ? 'orderedList' : 'bulletList',
            'content' => array_map(fn (string $item): array => ['type' => 'listItem', 'content' => [$this->paragraph($item)]], array_values($items)),
        ]]];
    }

    /** @return array<string, mixed> */
    private function paragraph(string $line): array
    {
        $parts = preg_split('/\*\*(.+?)\*\*/u', $line, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$line];
        $content = [];
        foreach ($parts as $i => $part) {
            if ($part === '') {
                continue;
            }
            $node = ['type' => 'text', 'text' => $part];
            if ($i % 2 === 1) {
                $node['marks'] = [['type' => 'bold']];
            }
            $content[] = $node;
        }

        return $content === [] ? ['type' => 'paragraph'] : ['type' => 'paragraph', 'content' => $content];
    }

    private function plain(string $text): string
    {
        return trim(str_replace('**', '', preg_replace('/\s+/u', ' ', $text) ?? $text));
    }

    private function n(float|int $value): int|float
    {
        $r = round((float) $value, 2);

        return floor($r) == $r ? (int) $r : $r;
    }
}
