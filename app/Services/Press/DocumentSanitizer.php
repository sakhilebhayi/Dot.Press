<?php

namespace App\Services\Press;

/**
 * Validates and normalises everything that may enter a presentation document (docs/programme/R40_DOCUMENT_SPEC.md
 * sections 2 to 8 and 10). Pure: no database, no request. Unknown keys are dropped and never stored; a value that is
 * present but unusable throws InvalidDocument with a short reason; numbers are coerced from numeric strings and clamped
 * into a sane range; URLs must be https or `#slide:<id>`; text documents are pruned to the allowed Tiptap nodes and marks.
 */
class DocumentSanitizer
{
    public const MAX_SLIDES = 600;

    public const MAX_ELEMENTS = 400;

    public const MAX_SLIDE_BYTES = 2 * 1024 * 1024;

    public const MAX_ELEMENT_BYTES = 512 * 1024;

    public const MAX_OPS = 200;

    public const MAX_REQUEST_BYTES = 8 * 1024 * 1024;

    public const MAX_SECTIONS = 100;

    public const ID_PATTERN = '/^[A-Za-z0-9_-]{1,40}$/D';

    public const ROLES = ['title', 'subtitle', 'body', 'media', 'caption', 'footer', 'number', 'kpi'];

    public const ORIGINS = ['user', 'ai', 'import', 'template'];

    public const LAYOUTS = ['title', 'section', 'bullets', 'two', 'image-left', 'image-right', 'image-full', 'compare', 'timeline', 'quote', 'data', 'kpi', 'closing', 'blank'];

    public const SHAPE_KINDS = ['rect', 'round', 'ellipse', 'triangle', 'diamond', 'pentagon', 'hexagon', 'star', 'chevron', 'arrow-r', 'arrow-l', 'callout', 'cross', 'heart', 'line', 'arrow'];

    public const CHART_TYPES = ['bar', 'column', 'line', 'area', 'pie', 'doughnut', 'scatter', 'bubble', 'waterfall', 'funnel', 'radar', 'combo', 'kpi', 'map'];

    public const TRANSITIONS = ['fade', 'push', 'wipe', 'reveal', 'zoom', 'morph'];

    public const TOKENS = ['bg', 'surface', 'ink', 'muted', 'line', 'accent', 'accent2', 'good', 'warn', 'bad', 'chart1', 'chart2', 'chart3', 'chart4', 'chart5', 'chart6', 'chart7', 'chart8'];

    private const TEXT_KEYS = ['doc', 'font', 'size', 'weight', 'color', 'align', 'valign', 'lh', 'ls', 'ps', 'fit', 'pad', 'fx'];

    private const COMMON_KEYS = ['name', 'x', 'y', 'w', 'h', 'rot', 'op', 'lock', 'hide', 'group', 'role', 'origin', 'link', 'alt', 'anim', 'morph', 'shadow'];

    /** Keys each element type may carry besides `id` and `type`. */
    private const TYPE_KEYS = [
        'text' => [...self::TEXT_KEYS, 'fill', 'stroke', 'sw', 'radius'],
        'shape' => ['kind', 'fill', 'stroke', 'sw', 'radius', 'dash', 'ends', ...self::TEXT_KEYS],
        'image' => ['src', 'crop', 'fit', 'mask', 'adj', 'flipH', 'flipV', 'cap', 'fill'],
        'video' => ['src', 'embed', 'poster', 'autoplay', 'loop', 'muted', 'controls', 'start'],
        'audio' => ['src', 'autoplay', 'loop', 'controls'],
        'icon' => ['name', 'color', 'sw'],
        'chart' => ['chart'],
        'table' => ['rows', 'colw', 'rowh', 'header', 'band', 'size', 'font', 'lines'],
    ];

    private const REQUIRED_NUMBERS = ['x', 'y', 'w', 'h'];

    /**
     * @return list<string>
     */
    public static function elementTypes(): array
    {
        return array_keys(self::TYPE_KEYS);
    }

    // ------------------------------------------------------------------ elements

    /**
     * A complete element as `el.add` and `slide.add` carry it.
     *
     * @return array<string, mixed>
     */
    public function element(mixed $raw): array
    {
        if (! is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            throw new InvalidDocument('element must be an object');
        }

        $type = $raw['type'] ?? null;
        if (! is_string($type) || ! isset(self::TYPE_KEYS[$type])) {
            throw new InvalidDocument('unknown element type');
        }

        $out = ['id' => $this->id($raw['id'] ?? null, 'id'), 'type' => $type];

        foreach ([...self::COMMON_KEYS, ...self::TYPE_KEYS[$type]] as $key) {
            if (($raw[$key] ?? null) === null) {
                continue;
            }
            $out[$key] = $this->value($type, $key, $raw[$key]);
        }

        $out['x'] ??= 0;
        $out['y'] ??= 0;
        $out['w'] ??= 200;
        $out['h'] ??= 100;

        return $this->finish($out);
    }

    /**
     * Fills what a type cannot do without and checks the size. Run on a new element and on a patched one.
     *
     * @param  array<string, mixed>  $el
     * @return array<string, mixed>
     */
    public function finish(array $el): array
    {
        $this->completeType($el);

        if (strlen((string) json_encode($el)) > self::MAX_ELEMENT_BYTES) {
            throw new InvalidDocument('element too large');
        }

        return $el;
    }

    /**
     * A shallow patch for `el.set`: keys set to null are deleted.
     *
     * @return array{set: array<string, mixed>, unset: list<string>}
     */
    public function patch(string $type, mixed $patch): array
    {
        if (! is_array($patch) || ($patch !== [] && array_is_list($patch))) {
            throw new InvalidDocument('p must be an object');
        }
        if (! isset(self::TYPE_KEYS[$type])) {
            throw new InvalidDocument('unknown element type');
        }

        $allowed = [...self::COMMON_KEYS, ...self::TYPE_KEYS[$type]];
        $set = [];
        $unset = [];

        foreach ($patch as $key => $value) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                continue;
            }
            if ($value === null) {
                if (in_array($key, self::REQUIRED_NUMBERS, true)) {
                    throw new InvalidDocument("{$key} cannot be removed");
                }
                $unset[] = $key;

                continue;
            }
            $set[$key] = $this->value($type, $key, $value);
        }

        if ($set === [] && $unset === []) {
            throw new InvalidDocument('empty patch');
        }

        return ['set' => $set, 'unset' => $unset];
    }

    /**
     * Fills what a type cannot do without, or refuses an element that would be empty of meaning.
     *
     * @param  array<string, mixed>  $el
     */
    private function completeType(array &$el): void
    {
        switch ($el['type']) {
            case 'video':
                if (! isset($el['src']) && ! isset($el['embed'])) {
                    throw new InvalidDocument('video needs a source');
                }
                break;
            case 'audio':
                if (! isset($el['src'])) {
                    throw new InvalidDocument('audio needs a source');
                }
                break;
            case 'icon':
                if (! isset($el['name'])) {
                    throw new InvalidDocument('icon needs a name');
                }
                break;
            case 'chart':
                if (! isset($el['chart'])) {
                    throw new InvalidDocument('chart needs data');
                }
                break;
            case 'table':
                if (! isset($el['rows'])) {
                    throw new InvalidDocument('table needs rows');
                }
                $el['colw'] = $this->columnWidths($el['colw'] ?? null, $this->columnCount($el['rows']));
                break;
        }
    }

    /**
     * @param  array<string, mixed>  $el
     * @return list<int>
     */
    public function assetIds(array $el): array
    {
        $ids = [];
        foreach (['src', 'poster'] as $key) {
            if (isset($el[$key]['asset'])) {
                $ids[] = (int) $el[$key]['asset'];
            }
        }
        if (isset($el['bg']['img']['asset'])) {
            $ids[] = (int) $el['bg']['img']['asset'];
        }

        return array_values(array_unique($ids));
    }

    private function value(string $type, string $key, mixed $v): mixed
    {
        return match ($key) {
            'name' => $type === 'icon' ? $this->id($v, 'icon name') : $this->str($v, 120, $key),
            'x', 'y' => $this->num($v, -100000, 100000, $key),
            'w', 'h' => $this->num($v, 0, 100000, $key),
            'rot' => $this->num($v, -3600, 3600, $key),
            'op' => $this->num($v, 0, 1, $key),
            'lock', 'hide', 'flipH', 'flipV', 'autoplay', 'loop', 'muted', 'controls', 'header', 'band' => $this->bool($v, $key),
            'group', 'morph' => $this->id($v, $key),
            'role' => $this->enum($v, self::ROLES, $key),
            'origin' => $this->enum($v, self::ORIGINS, $key),
            'link' => $this->url($v, true, 'link'),
            'alt', 'cap' => $this->str($v, 500, $key),
            'anim' => $this->anim($v),
            'shadow' => $this->shadow($v),
            'doc' => $this->doc($v),
            'font' => $this->font($v),
            'size' => $this->num($v, 1, 5000, $key),
            'weight' => (int) $this->num($v, 100, 900, $key),
            'color' => $this->colour($v, $key),
            'align' => $this->enum($v, ['left', 'center', 'right', 'justify'], $key),
            'valign' => $this->enum($v, ['top', 'middle', 'bottom'], $key),
            'lh' => $this->num($v, 0.5, 5, $key),
            'ls' => $this->num($v, -1, 5, $key),
            'ps' => $this->num($v, 0, 5, $key),
            'fit' => $type === 'image'
                ? $this->enum($v, ['cover', 'contain', 'fill'], $key)
                : $this->enum($v, ['none', 'shrink'], $key),
            'pad' => $this->num($v, 0, 2000, $key),
            'radius' => $this->num($v, 0, 5000, $key),
            'fx' => $this->enum($v, ['none', 'outline', 'highlight'], $key),
            'fill' => $this->fill($v, $key),
            'stroke' => $this->colour($v, $key),
            'sw' => $this->num($v, 0, 500, $key),
            'kind' => $this->enum($v, self::SHAPE_KINDS, $key),
            'dash' => $this->enum($v, ['solid', 'dash', 'dot'], $key),
            'ends' => $this->ends($v),
            'src' => $this->source($v, 'src'),
            'poster' => $this->source($v, 'poster'),
            'embed' => $this->embed($v),
            'crop' => $this->crop($v),
            'mask' => $this->enum($v, ['none', 'round', 'circle', 'ellipse', 'triangle', 'star', 'hexagon'], $key),
            'adj' => $this->adjustments($v),
            'start' => $this->num($v, 0, 86400, $key),
            'chart' => $this->chart($v),
            'rows' => $this->rows($v),
            'colw' => $this->columnWidths($v, null),
            'rowh' => $this->list($v, 100, $key, fn ($n) => $this->num($n, 0, 10000, 'rowh')),
            'lines' => $this->enum($v, ['none', 'rows', 'grid'], $key),
            default => throw new InvalidDocument("unsupported key {$key}"),
        };
    }

    // ------------------------------------------------------------------ slides, decks, themes

    /**
     * The initial fields of `slide.add` (and the checked part of `slide.set`).
     *
     * @return array<string, mixed>
     */
    public function slideFields(mixed $raw, bool $withElements = true): array
    {
        if (! is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            throw new InvalidDocument('slide must be an object');
        }

        $out = [];
        if (array_key_exists('title', $raw)) {
            $out['title'] = $raw['title'] === null ? null : $this->str($raw['title'], 255, 'title');
        }
        if (array_key_exists('notes', $raw)) {
            $out['notes'] = $raw['notes'] === null ? null : $this->str($raw['notes'], 20000, 'notes', true);
        }
        if (array_key_exists('section_id', $raw)) {
            $out['section_id'] = $raw['section_id'] === null ? null : $this->id($raw['section_id'], 'section_id', 24);
        }
        if (array_key_exists('hidden', $raw)) {
            $out['hidden'] = $this->bool($raw['hidden'], 'hidden');
        }
        if (array_key_exists('layout', $raw)) {
            $out['layout'] = $this->layout($raw['layout']);
        }
        if (array_key_exists('bg', $raw)) {
            $out['bg'] = $raw['bg'] === null ? null : $this->slideBackground($raw['bg']);
        }
        if (array_key_exists('transition', $raw)) {
            $out['transition'] = $raw['transition'] === null ? null : $this->transition($raw['transition']);
        }
        if ($withElements && array_key_exists('els', $raw)) {
            $out['els'] = $this->elements($raw['els']);
        }

        return $out;
    }

    /**
     * The initial content of `slide.add`, read leniently so one bad value does not lose a whole slide: a slide key of the
     * wrong type is ignored, an element that is invalid or repeats an id is dropped. Only too many elements refuses it.
     *
     * @return array<string, mixed>
     */
    public function slideFieldsLenient(mixed $raw): array
    {
        if (! is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            return [];
        }

        $out = [];
        foreach (['title', 'notes', 'section_id', 'hidden', 'layout', 'bg', 'transition'] as $key) {
            if (! array_key_exists($key, $raw)) {
                continue;
            }
            try {
                $out += $this->slideFields([$key => $raw[$key]], false);
            } catch (InvalidDocument) {
                // ignored
            }
        }

        $els = $raw['els'] ?? null;
        if (is_array($els) && array_is_list($els)) {
            if (count($els) > self::MAX_ELEMENTS) {
                throw new InvalidDocument('too many elements on one slide');
            }
            $kept = [];
            $seen = [];
            foreach ($els as $item) {
                try {
                    $el = $this->element($item);
                } catch (InvalidDocument) {
                    continue;
                }
                if (! isset($seen[$el['id']])) {
                    $seen[$el['id']] = true;
                    $kept[] = $el;
                }
            }
            $out['els'] = $kept;
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function elements(mixed $raw): array
    {
        if ($raw === null || $raw === []) {
            return [];
        }
        if (! is_array($raw) || ! array_is_list($raw)) {
            throw new InvalidDocument('els must be a list');
        }
        if (count($raw) > self::MAX_ELEMENTS) {
            throw new InvalidDocument('too many elements on one slide');
        }

        $out = [];
        $seen = [];
        foreach ($raw as $index => $item) {
            try {
                $el = $this->element($item);
            } catch (InvalidDocument $e) {
                throw new InvalidDocument("element {$index}: ".$e->getMessage());
            }
            if (isset($seen[$el['id']])) {
                throw new InvalidDocument("element {$index}: duplicate id");
            }
            $seen[$el['id']] = true;
            $out[] = $el;
        }

        return $out;
    }

    public function layout(mixed $v): string
    {
        if (is_string($v) && in_array($v, self::LAYOUTS, true)) {
            return $v;
        }
        if (is_string($v) && preg_match('/^custom:[A-Za-z0-9_-]{1,40}$/D', $v)) {
            return $v;
        }

        throw new InvalidDocument('invalid layout');
    }

    /**
     * @return array<string, mixed>
     */
    public function transition(mixed $v): array
    {
        if (! is_array($v)) {
            throw new InvalidDocument('invalid transition');
        }

        $out = ['type' => $this->enum($v['type'] ?? null, self::TRANSITIONS, 'transition type')];
        $out['dir'] = isset($v['dir']) ? $this->enum($v['dir'], ['l', 'r', 'u', 'd'], 'transition dir') : 'l';
        $out['dur'] = isset($v['dur']) ? (int) $this->num($v['dur'], 0, 5000, 'transition dur') : 500;
        $out['ease'] = isset($v['ease']) ? $this->enum($v['ease'], ['out', 'inout', 'spring', 'linear'], 'transition ease') : 'out';

        return $out;
    }

    /**
     * A slide background: a fill, or an image background.
     *
     * @return array<string, mixed>|string
     */
    public function slideBackground(mixed $v): array|string
    {
        if (is_array($v) && array_key_exists('img', $v)) {
            $out = ['img' => $this->source($v['img'], 'bg image')];
            $out['fit'] = isset($v['fit']) ? $this->enum($v['fit'], ['cover', 'contain', 'fill'], 'bg fit') : 'cover';
            $out['dim'] = isset($v['dim']) ? $this->num($v['dim'], 0, 1, 'bg dim') : 0;

            return $out;
        }

        return $this->fill($v, 'bg');
    }

    /**
     * @return array<string, mixed>
     */
    public function deckSet(mixed $raw): array
    {
        if (! is_array($raw)) {
            throw new InvalidDocument('invalid deck fields');
        }

        $out = [];
        if (array_key_exists('title', $raw)) {
            $title = trim($this->str($raw['title'], 255, 'title'));
            if ($title === '') {
                throw new InvalidDocument('title cannot be empty');
            }
            $out['title'] = $title;
        }
        if (array_key_exists('theme', $raw)) {
            $out['theme'] = $this->theme($raw['theme'], false);
        }
        if (array_key_exists('status', $raw)) {
            $out['status'] = $this->enum($raw['status'], ['draft', 'in_review', 'approved'], 'status');
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultTheme(): array
    {
        return [
            'id' => 'slate',
            'name' => 'Slate',
            'c' => [
                'bg' => '#ffffff', 'surface' => '#f4f5f7', 'ink' => '#111827', 'muted' => '#6b7280', 'line' => '#d1d5db',
                'accent' => '#2563eb', 'accent2' => '#f59e0b', 'good' => '#15803d', 'warn' => '#b45309', 'bad' => '#b91c1c',
                'chart' => ['#2563eb', '#f59e0b', '#15803d', '#7c3aed', '#db2777', '#0891b2', '#65a30d', '#475569'],
            ],
            't' => ['heading' => 'Public Sans', 'body' => 'Public Sans', 'hw' => 700, 'scale' => 1],
            's' => ['radius' => 12, 'stroke' => 2],
            'bg' => null,
        ];
    }

    /**
     * Spec section 3. With $complete (a new deck) missing tokens are filled from the default theme; through an operation
     * the theme is kept as given, validated.
     *
     * @return array<string, mixed>
     */
    public function theme(mixed $v, bool $complete = true): array
    {
        if (! is_array($v) || ($v !== [] && array_is_list($v))) {
            throw new InvalidDocument('invalid theme');
        }

        $base = self::defaultTheme();
        $out = $complete ? $base : ['id' => null, 'name' => null, 'c' => [], 't' => [], 's' => [], 'bg' => null];

        if (isset($v['id'])) {
            // Library themes are 'slate'; a team's own are 'custom:<n>' and brand kits 'brand-kit:<n>' (the theme API ids).
            if (! is_string($v['id']) || preg_match('/^[A-Za-z0-9_-]+(?::[0-9]+)?$/D', $v['id']) !== 1 || strlen($v['id']) > 40) {
                throw new InvalidDocument('invalid theme id');
            }
            $out['id'] = $v['id'];
        }
        if (isset($v['name'])) {
            $out['name'] = $this->str($v['name'], 80, 'theme name');
        }

        $c = is_array($v['c'] ?? null) ? $v['c'] : [];
        foreach (['bg', 'surface', 'ink', 'muted', 'line', 'accent', 'accent2', 'good', 'warn', 'bad'] as $token) {
            if (isset($c[$token])) {
                $out['c'][$token] = $this->hexOnly($c[$token], "theme colour {$token}");
            }
        }
        if (isset($c['chart'])) {
            $palette = $this->list($c['chart'], 8, 'chart colours', fn ($n) => $this->hexOnly($n, 'chart colour'));
            foreach ($complete ? $base['c']['chart'] : [] as $i => $fallback) {
                $palette[$i] ??= $fallback;
            }
            $out['c']['chart'] = array_slice($palette, 0, 8);
        }

        $t = is_array($v['t'] ?? null) ? $v['t'] : [];
        foreach (['heading', 'body'] as $slot) {
            if (isset($t[$slot])) {
                $out['t'][$slot] = $this->fontFamily($t[$slot], "theme font {$slot}");
            }
        }
        if (isset($t['hw'])) {
            $out['t']['hw'] = (int) $this->num($t['hw'], 100, 900, 'theme heading weight');
        }
        if (isset($t['scale'])) {
            $out['t']['scale'] = $this->num($t['scale'], 0.5, 2, 'theme type scale');
        }

        $s = is_array($v['s'] ?? null) ? $v['s'] : [];
        if (isset($s['radius'])) {
            $out['s']['radius'] = $this->num($s['radius'], 0, 200, 'theme radius');
        }
        if (isset($s['stroke'])) {
            $out['s']['stroke'] = $this->num($s['stroke'], 0, 50, 'theme stroke');
        }

        if (isset($v['bg'])) {
            $out['bg'] = $this->slideBackground($v['bg']);
        } elseif ($complete) {
            $out['bg'] = null;
        }

        // A partial theme (set through an operation) holds only what it was given; renderers fall back to the default.
        return $complete ? $out : array_filter($out, fn ($value) => $value !== null && $value !== []);
    }

    /**
     * @return array{id: string, title: string}
     */
    public function section(mixed $raw, bool $titleRequired = true): array
    {
        if (! is_array($raw)) {
            throw new InvalidDocument('invalid section');
        }

        $out = ['id' => $this->id($raw['id'] ?? null, 'section id', 24)];
        if (isset($raw['title']) || $titleRequired) {
            $title = trim($this->str($raw['title'] ?? null, 120, 'section title'));
            if ($title === '') {
                throw new InvalidDocument('section title cannot be empty');
            }
            $out['title'] = $title;
        }

        return $out;
    }

    // ------------------------------------------------------------------ rich text

    /**
     * A Tiptap document pruned to paragraph, bulletList, orderedList, listItem, hardBreak and text with the marks
     * bold, italic, underline, strike, link and textStyle{color,fontSize,fontFamily}.
     *
     * @return array{type: string, content: list<array<string, mixed>>}
     */
    public function doc(mixed $doc): array
    {
        if (! is_array($doc) || ($doc['type'] ?? null) !== 'doc') {
            throw new InvalidDocument('doc must be a Tiptap document');
        }

        $budget = ['nodes' => 0, 'chars' => 0];
        $content = $this->blocks($doc['content'] ?? [], 0, $budget);

        if ($content === []) {
            $content = [['type' => 'paragraph']];
        }

        return ['type' => 'doc', 'content' => $content];
    }

    /**
     * @return array{type: string, content: list<array<string, mixed>>}
     */
    public function docFromText(string $text): array
    {
        $paragraphs = [];
        foreach (preg_split('/\R/u', $text) ?: [''] as $line) {
            $line = $this->stripControl($line);
            $paragraphs[] = $line === ''
                ? ['type' => 'paragraph']
                : ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $line]]];
        }

        return ['type' => 'doc', 'content' => $paragraphs ?: [['type' => 'paragraph']]];
    }

    /**
     * Plain text of a document (paragraphs on separate lines, list items prefixed).
     *
     * @param  array<string, mixed>|null  $doc
     */
    public function plainText(?array $doc): string
    {
        if ($doc === null) {
            return '';
        }

        $lines = [];
        $this->collectText($doc, $lines, '');

        return trim(implode("\n", $lines));
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $lines
     */
    private function collectText(array $node, array &$lines, string $prefix, int $depth = 0): void
    {
        if ($depth > 12) {
            return;
        }

        switch ($node['type'] ?? '') {
            case 'doc':
            case 'listItem':
                foreach ($node['content'] ?? [] as $child) {
                    if (is_array($child)) {
                        $this->collectText($child, $lines, $prefix, $depth + 1);
                    }
                }
                break;
            case 'bulletList':
            case 'orderedList':
                $n = 0;
                foreach ($node['content'] ?? [] as $child) {
                    if (! is_array($child)) {
                        continue;
                    }
                    $n++;
                    $marker = ($node['type'] === 'orderedList') ? "{$n}. " : '- ';
                    $before = count($lines);
                    $this->collectText($child, $lines, $prefix, $depth + 1);
                    if (isset($lines[$before])) {
                        $lines[$before] = $marker.$lines[$before];
                    }
                }
                break;
            case 'paragraph':
            case 'heading':
                $line = '';
                foreach ($node['content'] ?? [] as $child) {
                    if (is_array($child)) {
                        $line .= ($child['type'] ?? '') === 'hardBreak' ? "\n" : (string) ($child['text'] ?? '');
                    }
                }
                $lines[] = $line;
                break;
        }
    }

    /**
     * @param  array{nodes: int, chars: int}  $budget
     * @return list<array<string, mixed>>
     */
    private function blocks(mixed $nodes, int $depth, array &$budget): array
    {
        if (! is_array($nodes) || ! array_is_list($nodes) || $depth > 8) {
            return [];
        }

        $out = [];
        $run = [];

        $flush = function () use (&$out, &$run): void {
            if ($run !== []) {
                $out[] = ['type' => 'paragraph', 'content' => $run];
                $run = [];
            }
        };

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $this->spend($budget, 'nodes', 1);
            $type = $node['type'] ?? null;

            if ($type === 'text' || $type === 'hardBreak') {
                $inline = $this->inline($node, $budget);
                if ($inline !== null) {
                    $run[] = $inline;
                }

                continue;
            }

            $flush();

            switch ($type) {
                case 'paragraph':
                case 'heading':
                    $inline = $this->inlineRun($node['content'] ?? [], $budget);
                    $out[] = $inline === [] ? ['type' => 'paragraph'] : ['type' => 'paragraph', 'content' => $inline];
                    break;
                case 'bulletList':
                case 'orderedList':
                    $items = $this->listItems($node['content'] ?? [], $depth + 1, $budget);
                    if ($items !== []) {
                        $out[] = ['type' => $type, 'content' => $items];
                    }
                    break;
                case 'listItem':
                    foreach ($this->blocks($node['content'] ?? [], $depth + 1, $budget) as $block) {
                        $out[] = $block;
                    }
                    break;
                default:
                    // An unknown container (a blockquote, say) is unwrapped so its text survives; leaves are dropped.
                    foreach ($this->blocks($node['content'] ?? [], $depth + 1, $budget) as $block) {
                        $out[] = $block;
                    }
            }
        }

        $flush();

        return $out;
    }

    /**
     * @param  array{nodes: int, chars: int}  $budget
     * @return list<array<string, mixed>>
     */
    private function listItems(mixed $nodes, int $depth, array &$budget): array
    {
        if (! is_array($nodes) || ! array_is_list($nodes) || $depth > 8) {
            return [];
        }

        $items = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $this->spend($budget, 'nodes', 1);

            $children = ($node['type'] ?? null) === 'listItem' ? ($node['content'] ?? []) : [$node];
            $blocks = $this->blocks($children, $depth + 1, $budget);
            $items[] = ['type' => 'listItem', 'content' => $blocks === [] ? [['type' => 'paragraph']] : $blocks];
        }

        return $items;
    }

    /**
     * @param  array{nodes: int, chars: int}  $budget
     * @return list<array<string, mixed>>
     */
    private function inlineRun(mixed $nodes, array &$budget): array
    {
        if (! is_array($nodes) || ! array_is_list($nodes)) {
            return [];
        }

        $out = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $this->spend($budget, 'nodes', 1);
            $inline = $this->inline($node, $budget);
            if ($inline !== null) {
                $out[] = $inline;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array{nodes: int, chars: int}  $budget
     * @return array<string, mixed>|null
     */
    private function inline(array $node, array &$budget): ?array
    {
        if (($node['type'] ?? null) === 'hardBreak') {
            return ['type' => 'hardBreak'];
        }
        if (($node['type'] ?? null) !== 'text' || ! is_string($node['text'] ?? null)) {
            return null;
        }

        $text = $this->stripControl($node['text']);
        if ($text === '') {
            return null;
        }
        $this->spend($budget, 'chars', mb_strlen($text));

        $out = ['type' => 'text', 'text' => $text];
        $marks = $this->marks($node['marks'] ?? []);
        if ($marks !== []) {
            $out['marks'] = $marks;
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function marks(mixed $marks): array
    {
        if (! is_array($marks) || ! array_is_list($marks)) {
            return [];
        }

        $out = [];
        foreach ($marks as $mark) {
            $type = is_array($mark) ? ($mark['type'] ?? null) : null;
            if (! is_string($type) || isset($out[$type])) {
                continue;
            }

            if (in_array($type, ['bold', 'italic', 'underline', 'strike'], true)) {
                $out[$type] = ['type' => $type];
            } elseif ($type === 'link') {
                try {
                    $out[$type] = ['type' => 'link', 'attrs' => ['href' => $this->url($mark['attrs']['href'] ?? null, true, 'href')]];
                } catch (InvalidDocument) {
                    // A link that is not https or #slide: loses the mark and keeps its text.
                }
            } elseif ($type === 'textStyle') {
                $attrs = [];
                $in = is_array($mark['attrs'] ?? null) ? $mark['attrs'] : [];
                try {
                    if (isset($in['color'])) {
                        $attrs['color'] = $this->colour($in['color'], 'color');
                    }
                } catch (InvalidDocument) {
                }
                try {
                    if (isset($in['fontSize'])) {
                        // Tiptap writes "24px", a number is accepted too; the form given is kept.
                        $isString = is_string($in['fontSize']);
                        $size = $this->num($isString ? preg_replace('/px$/i', '', trim($in['fontSize'])) : $in['fontSize'], 1, 5000, 'fontSize');
                        $attrs['fontSize'] = $isString ? $size.'px' : $size;
                    }
                } catch (InvalidDocument) {
                }
                try {
                    if (isset($in['fontFamily'])) {
                        $attrs['fontFamily'] = $this->font($in['fontFamily']);
                    }
                } catch (InvalidDocument) {
                }
                if ($attrs !== []) {
                    $out[$type] = ['type' => 'textStyle', 'attrs' => $attrs];
                }
            }
        }

        return array_values($out);
    }

    /**
     * @param  array{nodes: int, chars: int}  $budget
     */
    private function spend(array &$budget, string $what, int $amount): void
    {
        $budget[$what] += $amount;
        if ($budget['nodes'] > 5000 || $budget['chars'] > 100000) {
            throw new InvalidDocument('text too large');
        }
    }

    // ------------------------------------------------------------------ operations

    /**
     * A reference to a slide: a stored id (an integer) or a temporary id minted earlier in the same request.
     */
    public function slideRef(mixed $v, string $what = 'slide'): int|string
    {
        if (is_int($v) && $v > 0) {
            return $v;
        }
        if (is_string($v) && ctype_digit($v) && strlen($v) <= 15 && (int) $v > 0) {
            return (int) $v;
        }
        if (is_string($v) && preg_match(self::ID_PATTERN, $v)) {
            return $v;
        }

        throw new InvalidDocument("invalid {$what} reference");
    }

    public function tempId(mixed $v): string
    {
        $id = $this->id($v, 'tid');
        if (ctype_digit($id)) {
            throw new InvalidDocument('tid cannot be only digits');
        }

        return $id;
    }

    // ------------------------------------------------------------------ scalar helpers

    public function id(mixed $v, string $what, int $max = 40): string
    {
        if (is_int($v) && $v >= 0) {
            $v = (string) $v;
        }
        if (! is_string($v) || ! preg_match('/^[A-Za-z0-9_-]+$/D', $v) || strlen($v) > $max || $v === '') {
            throw new InvalidDocument("invalid {$what}");
        }

        return $v;
    }

    public function num(mixed $v, float $min, float $max, string $what): int|float
    {
        if (is_string($v)) {
            $v = trim($v);
        }
        if (is_bool($v) || ! is_numeric($v)) {
            throw new InvalidDocument("invalid {$what}");
        }

        $f = (float) $v;
        if (! is_finite($f)) {
            throw new InvalidDocument("invalid {$what}");
        }

        $f = round(max($min, min($max, $f)), 3);

        return floor($f) == $f ? (int) $f : $f;
    }

    public function bool(mixed $v, string $what): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if ($v === 1 || $v === '1' || $v === 'true') {
            return true;
        }
        if ($v === 0 || $v === '0' || $v === 'false') {
            return false;
        }

        throw new InvalidDocument("invalid {$what}");
    }

    public function str(mixed $v, int $max, string $what, bool $multiline = false): string
    {
        if (is_int($v) || is_float($v)) {
            $v = (string) $v;
        }
        if (! is_string($v)) {
            throw new InvalidDocument("invalid {$what}");
        }

        $v = $this->stripControl($v, $multiline);

        return mb_substr($v, 0, $max);
    }

    /**
     * @param  list<string>  $allowed
     */
    public function enum(mixed $v, array $allowed, string $what): string
    {
        if (! is_string($v) || ! in_array($v, $allowed, true)) {
            throw new InvalidDocument("invalid {$what}");
        }

        return $v;
    }

    private function stripControl(string $v, bool $keepNewlines = true): string
    {
        $v = preg_replace($keepNewlines ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/', '', $v) ?? '';

        return str_replace("\u{2028}", ' ', $v);
    }

    /**
     * A colour: #rgb, #rrggbb, #rrggbbaa or a theme token.
     */
    public function colour(mixed $v, string $what = 'colour'): string
    {
        if (! is_string($v)) {
            throw new InvalidDocument("invalid {$what}");
        }
        $v = trim($v);

        if (preg_match('/^@(\w+)$/D', $v, $m) && in_array($m[1], self::TOKENS, true)) {
            return $v;
        }
        if (preg_match('/^#([0-9a-f]{3})$/iD', $v, $m)) {
            return '#'.strtolower($m[1][0].$m[1][0].$m[1][1].$m[1][1].$m[1][2].$m[1][2]);
        }
        if (preg_match('/^#([0-9a-f]{6}|[0-9a-f]{8})$/iD', $v)) {
            return strtolower($v);
        }

        throw new InvalidDocument("invalid {$what}");
    }

    private function hexOnly(mixed $v, string $what): string
    {
        $c = $this->colour($v, $what);
        if ($c[0] === '@') {
            throw new InvalidDocument("invalid {$what}");
        }

        return $c;
    }

    /**
     * A fill: a colour, or a linear gradient {g,a,stops}.
     *
     * @return array<string, mixed>|string
     */
    public function fill(mixed $v, string $what = 'fill'): array|string
    {
        if (is_string($v)) {
            return $this->colour($v, $what);
        }
        if (! is_array($v) || ($v['g'] ?? null) !== 'linear') {
            throw new InvalidDocument("invalid {$what}");
        }

        $stops = $v['stops'] ?? null;
        if (! is_array($stops) || ! array_is_list($stops) || count($stops) < 2 || count($stops) > 10) {
            throw new InvalidDocument("invalid {$what} stops");
        }

        $clean = [];
        foreach ($stops as $stop) {
            if (! is_array($stop) || count($stop) !== 2 || ! array_is_list($stop)) {
                throw new InvalidDocument("invalid {$what} stops");
            }
            $clean[] = [$this->num($stop[0], 0, 1, "{$what} stop"), $this->colour($stop[1], "{$what} stop colour")];
        }

        return ['g' => 'linear', 'a' => $this->num($v['a'] ?? 90, -360, 360, "{$what} angle"), 'stops' => $clean];
    }

    /**
     * An https URL, or (when allowed) a link to a slide of this deck.
     */
    public function url(mixed $v, bool $allowSlide, string $what): string
    {
        if (! is_string($v) || $v === '' || strlen($v) > 2000 || preg_match('/[\x00-\x20\x7F]/', $v)) {
            throw new InvalidDocument("invalid {$what}");
        }

        if ($allowSlide && preg_match('/^#slide:[A-Za-z0-9_-]{1,40}$/D', $v)) {
            return $v;
        }

        $parts = parse_url($v);
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            throw new InvalidDocument("{$what} must be https");
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidDocument("invalid {$what}");
        }
        if (! preg_match('/^[\p{L}\p{N}.\-]+$/uD', $parts['host']) || (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535))) {
            throw new InvalidDocument("invalid {$what}");
        }

        return 'https'.substr($v, 5);
    }

    /**
     * @return array{asset: int}|array{url: string}
     */
    private function source(mixed $v, string $what): array
    {
        if (! is_array($v)) {
            throw new InvalidDocument("invalid {$what}");
        }
        if (isset($v['asset'])) {
            $asset = $v['asset'];
            if (is_string($asset) && ctype_digit($asset)) {
                $asset = (int) $asset;
            }
            if (! is_int($asset) || $asset < 1) {
                throw new InvalidDocument("invalid {$what} asset");
            }

            return ['asset' => $asset];
        }
        if (isset($v['url'])) {
            return ['url' => $this->url($v['url'], false, "{$what} url")];
        }

        throw new InvalidDocument("{$what} needs an asset or an https url");
    }

    /**
     * @return array{provider: string, id: string}
     */
    private function embed(mixed $v): array
    {
        if (! is_array($v)) {
            throw new InvalidDocument('invalid embed');
        }

        return [
            'provider' => $this->enum($v['provider'] ?? null, ['youtube', 'vimeo'], 'embed provider'),
            'id' => $this->id($v['id'] ?? null, 'embed id'),
        ];
    }

    private function font(mixed $v): string
    {
        if ($v === '@heading' || $v === '@body') {
            return $v;
        }

        return $this->fontFamily($v, 'font');
    }

    private function fontFamily(mixed $v, string $what): string
    {
        if (! is_string($v) || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9 _.\-]{0,59}$/D', trim($v))) {
            throw new InvalidDocument("invalid {$what}");
        }

        return trim($v);
    }

    /**
     * @return array<string, int|float>
     */
    private function crop(mixed $v): array
    {
        if (! is_array($v)) {
            throw new InvalidDocument('invalid crop');
        }

        return [
            'x' => $this->num($v['x'] ?? 0, 0, 1, 'crop'),
            'y' => $this->num($v['y'] ?? 0, 0, 1, 'crop'),
            'w' => $this->num($v['w'] ?? 1, 0.001, 1, 'crop'),
            'h' => $this->num($v['h'] ?? 1, 0.001, 1, 'crop'),
        ];
    }

    /**
     * @return array<string, int|float>
     */
    private function adjustments(mixed $v): array
    {
        if (! is_array($v)) {
            throw new InvalidDocument('invalid adj');
        }

        $ranges = ['br' => [-100, 100], 'ct' => [-100, 100], 'sat' => [-100, 100], 'blur' => [0, 100], 'gray' => [0, 100], 'sepia' => [0, 100], 'hue' => [-360, 360]];
        $out = [];
        foreach ($ranges as $key => [$min, $max]) {
            if (isset($v[$key])) {
                $out[$key] = $this->num($v[$key], $min, $max, "adj {$key}");
            }
        }

        return $out;
    }

    /**
     * @return array{s: string, e: string}
     */
    private function ends(mixed $v): array
    {
        if (! is_array($v)) {
            throw new InvalidDocument('invalid ends');
        }

        return [
            's' => isset($v['s']) ? $this->enum($v['s'], ['none', 'arrow', 'dot'], 'ends') : 'none',
            'e' => isset($v['e']) ? $this->enum($v['e'], ['none', 'arrow', 'dot'], 'ends') : 'none',
        ];
    }

    /**
     * @return array{x: int|float, y: int|float, blur: int|float, c: string}
     */
    private function shadow(mixed $v): array
    {
        if (! is_array($v)) {
            throw new InvalidDocument('invalid shadow');
        }

        return [
            'x' => $this->num($v['x'] ?? 0, -1000, 1000, 'shadow'),
            'y' => $this->num($v['y'] ?? 0, -1000, 1000, 'shadow'),
            'blur' => $this->num($v['blur'] ?? 0, 0, 1000, 'shadow'),
            'c' => $this->colour($v['c'] ?? '#00000066', 'shadow colour'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function anim(mixed $v): array
    {
        return $this->list($v, 20, 'anim', function (mixed $step): array {
            if (! is_array($step)) {
                throw new InvalidDocument('invalid anim step');
            }

            $out = [
                'id' => isset($step['id']) ? $this->id($step['id'], 'anim id') : substr(bin2hex(random_bytes(6)), 0, 10),
                'ph' => isset($step['ph']) ? $this->enum($step['ph'], ['in', 'out', 'emph'], 'anim ph') : 'in',
                'fx' => $this->enum($step['fx'] ?? null, ['fade', 'rise', 'drop', 'slide-l', 'slide-r', 'zoom', 'wipe', 'pop', 'pulse', 'spin', 'grow'], 'anim fx'),
                'on' => isset($step['on']) ? $this->enum($step['on'], ['click', 'with', 'after'], 'anim on') : 'click',
                'delay' => (int) $this->num($step['delay'] ?? 0, 0, 60000, 'anim delay'),
                'dur' => (int) $this->num($step['dur'] ?? 400, 0, 60000, 'anim dur'),
                'ease' => isset($step['ease']) ? $this->enum($step['ease'], ['out', 'inout', 'spring'], 'anim ease') : 'out',
            ];
            if (isset($step['ord'])) {
                $out['ord'] = (int) round($this->num($step['ord'], 0, 9999, 'anim ord'));
            }

            return $out;
        });
    }

    /**
     * @template T
     *
     * @param  callable(mixed): T  $each
     * @return list<T>
     */
    private function list(mixed $v, int $max, string $what, callable $each): array
    {
        if (! is_array($v) || ($v !== [] && ! array_is_list($v)) || count($v) > $max) {
            throw new InvalidDocument("invalid {$what}");
        }

        return array_map($each, $v);
    }

    // ------------------------------------------------------------------ charts and tables

    /**
     * @return array<string, mixed>
     */
    private function chart(mixed $v): array
    {
        if (! is_array($v)) {
            throw new InvalidDocument('invalid chart');
        }

        $data = is_array($v['data'] ?? null) ? $v['data'] : [];
        $outData = [
            'categories' => $this->list($data['categories'] ?? [], 500, 'categories', fn ($c) => $this->str($c, 120, 'category')),
            'series' => $this->list($data['series'] ?? [], 40, 'series', fn ($s) => $this->series($s)),
        ];
        if (isset($data['totals'])) {
            $outData['totals'] = $this->list($data['totals'], 500, 'totals', fn ($n) => (int) $this->num($n, 0, 500, 'total'));
        }
        if (isset($data['kpis'])) {
            $outData['kpis'] = $this->list($data['kpis'], 40, 'kpis', fn ($k) => $this->kpi($k));
        }
        if (isset($data['regions'])) {
            $outData['regions'] = $this->list($data['regions'], 500, 'regions', fn ($r) => [
                'code' => $this->str(is_array($r) ? ($r['code'] ?? null) : null, 12, 'region code'),
                'value' => $this->num(is_array($r) ? ($r['value'] ?? null) : null, -1e15, 1e15, 'region value'),
            ]);
        }

        $opt = is_array($v['opt'] ?? null) ? $v['opt'] : [];
        $outOpt = [];
        foreach (['title' => 200, 'sub' => 200] as $key => $max) {
            if (isset($opt[$key])) {
                $outOpt[$key] = $this->str($opt[$key], $max, "chart {$key}");
            }
        }
        $outOpt['legend'] = isset($opt['legend']) ? $this->enum($opt['legend'], ['none', 'bottom', 'right', 'top'], 'legend') : 'bottom';
        $outOpt['labels'] = isset($opt['labels']) ? $this->enum($opt['labels'], ['none', 'value', 'percent'], 'labels') : 'none';
        foreach (['stacked' => false, 'smooth' => false, 'grid' => true] as $key => $default) {
            $outOpt[$key] = isset($opt[$key]) ? $this->bool($opt[$key], $key) : $default;
        }
        if (isset($opt['fmt'])) {
            $outOpt['fmt'] = $this->enum($opt['fmt'], ['num', 'pct', 'cur', 'compact'], 'fmt');
        }
        if (isset($opt['cur'])) {
            $outOpt['cur'] = $this->str($opt['cur'], 6, 'cur');
        }
        if (isset($opt['dec'])) {
            $outOpt['dec'] = (int) $this->num($opt['dec'], 0, 3, 'dec');
        }
        if (isset($opt['ax']) && is_array($opt['ax'])) {
            $outOpt['ax'] = [];
            foreach (['x', 'y'] as $axis) {
                $ax = $opt['ax'][$axis] ?? null;
                if (! is_array($ax)) {
                    continue;
                }
                $clean = [];
                if (isset($ax['title'])) {
                    $clean['title'] = $this->str($ax['title'], 120, 'axis title');
                }
                if (isset($ax['show'])) {
                    $clean['show'] = $this->bool($ax['show'], 'axis show');
                }
                foreach (['min', 'max'] as $bound) {
                    if (isset($ax[$bound])) {
                        $clean[$bound] = $this->num($ax[$bound], -1e15, 1e15, "axis {$bound}");
                    }
                }
                $outOpt['ax'][$axis] = $clean;
            }
        }
        if (isset($opt['colors'])) {
            $outOpt['colors'] = $this->list($opt['colors'], 12, 'colors', fn ($c) => $this->colour($c, 'chart colour'));
        }
        if (isset($opt['anim'])) {
            $outOpt['anim'] = $this->bool($opt['anim'], 'chart anim');
        }

        $src = is_array($v['src'] ?? null) ? $v['src'] : [];
        $outSrc = ['kind' => isset($src['kind']) ? $this->enum($src['kind'], ['manual', 'file'], 'chart source') : 'manual'];
        if (isset($src['name'])) {
            $outSrc['name'] = $this->str($src['name'], 200, 'chart source name');
        }
        if (isset($src['at'])) {
            $at = $this->str($src['at'], 40, 'chart source date');
            if (! preg_match('/^\d{4}-\d{2}-\d{2}([T ][\d:.]+(Z|[+-]\d{2}:?\d{2})?)?$/D', $at)) {
                throw new InvalidDocument('invalid chart source date');
            }
            $outSrc['at'] = $at;
        }

        return [
            'type' => $this->enum($v['type'] ?? null, self::CHART_TYPES, 'chart type'),
            'data' => $outData,
            'opt' => $outOpt,
            'src' => $outSrc,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function series(mixed $s): array
    {
        if (! is_array($s)) {
            throw new InvalidDocument('invalid series');
        }

        $out = [
            'name' => $this->str($s['name'] ?? '', 120, 'series name'),
            'values' => $this->list($s['values'] ?? [], 2000, 'values', fn ($n) => $n === null ? null : $this->num($n, -1e15, 1e15, 'value')),
        ];
        if (isset($s['color'])) {
            $out['color'] = $this->colour($s['color'], 'series colour');
        }
        if (isset($s['kind'])) {
            $out['kind'] = $this->enum($s['kind'], ['bar', 'line'], 'series kind');
        }
        if (isset($s['points'])) {
            $out['points'] = $this->list($s['points'], 2000, 'points', function ($p): array {
                if (! is_array($p)) {
                    throw new InvalidDocument('invalid point');
                }
                $point = ['x' => $this->num($p['x'] ?? null, -1e15, 1e15, 'point'), 'y' => $this->num($p['y'] ?? null, -1e15, 1e15, 'point')];
                if (isset($p['r'])) {
                    $point['r'] = $this->num($p['r'], 0, 1e15, 'point');
                }

                return $point;
            });
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function kpi(mixed $k): array
    {
        if (! is_array($k)) {
            throw new InvalidDocument('invalid kpi');
        }

        $out = [
            'label' => $this->str($k['label'] ?? '', 120, 'kpi label'),
            'value' => is_int($k['value'] ?? null) || is_float($k['value'] ?? null) ? $this->num($k['value'], -1e15, 1e15, 'kpi value') : $this->str($k['value'] ?? '', 60, 'kpi value'),
        ];
        foreach (['delta' => 60, 'unit' => 20, 'note' => 240] as $key => $max) {
            if (isset($k[$key])) {
                $out[$key] = $this->str($k[$key], $max, "kpi {$key}");
            }
        }

        return $out;
    }

    /**
     * Rows of cell objects, padded so every row has the same number of cells.
     *
     * @return list<list<array<string, mixed>>>
     */
    private function rows(mixed $v): array
    {
        if (! is_array($v) || ! array_is_list($v) || $v === [] || count($v) > 100) {
            throw new InvalidDocument('invalid rows');
        }

        $rows = [];
        $width = 0;
        foreach ($v as $row) {
            if (! is_array($row) || ! array_is_list($row) || count($row) > 50) {
                throw new InvalidDocument('invalid rows');
            }
            $cells = array_map(fn ($cell) => $this->cell($cell), $row);
            $width = max($width, count($cells));
            $rows[] = $cells;
        }

        foreach ($rows as &$cells) {
            while (count($cells) < $width) {
                $cells[] = ['t' => ''];
            }
        }
        unset($cells);

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function cell(mixed $cell): array
    {
        if (is_string($cell) || is_int($cell) || is_float($cell)) {
            return ['t' => $this->str($cell, 2000, 'cell', true)];
        }
        if (! is_array($cell)) {
            throw new InvalidDocument('invalid cell');
        }

        $out = ['t' => $this->str($cell['t'] ?? '', 2000, 'cell', true)];
        foreach (['b', 'i'] as $flag) {
            if (isset($cell[$flag])) {
                $out[$flag] = $this->bool($cell[$flag], "cell {$flag}");
            }
        }
        if (isset($cell['al'])) {
            $out['al'] = $this->enum($cell['al'], ['l', 'c', 'r'], 'cell al');
        }
        foreach (['fill', 'color'] as $key) {
            if (isset($cell[$key])) {
                $out[$key] = $this->colour($cell[$key], "cell {$key}");
            }
        }
        if (isset($cell['span'])) {
            $span = $this->list($cell['span'], 2, 'span', fn ($n) => (int) $this->num($n, 1, 50, 'span'));
            if (count($span) !== 2) {
                throw new InvalidDocument('invalid span');
            }
            $out['span'] = $span;
        }

        return $out;
    }

    /**
     * @param  list<list<array<string, mixed>>>  $rows
     */
    private function columnCount(array $rows): int
    {
        return max(1, count($rows[0] ?? []));
    }

    /**
     * Fractions that sum to 1; an unusable or mismatched list becomes equal columns.
     *
     * @return list<int|float>
     */
    private function columnWidths(mixed $v, ?int $columns): array
    {
        $widths = $v === null ? [] : $this->list($v, 50, 'colw', fn ($n) => $this->num($n, 0, 1000, 'colw'));
        $sum = array_sum($widths);

        if ($columns !== null && (count($widths) !== $columns || $sum <= 0)) {
            return array_fill(0, $columns, round(1 / $columns, 4));
        }
        if ($sum <= 0) {
            throw new InvalidDocument('invalid colw');
        }

        return array_map(fn ($n) => round($n / $sum, 4), $widths);
    }
}
