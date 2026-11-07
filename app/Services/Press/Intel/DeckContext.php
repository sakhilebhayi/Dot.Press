<?php

namespace App\Services\Press\Intel;

/**
 * A defensive, pre-digested view of a presentation document (docs/programme/R40_DOCUMENT_SPEC.md)
 * that the analyzer rules share: geometry as floats, resolved fonts and theme palette, extracted
 * text, and the title of each slide. Malformed input is coerced, never trusted.
 */
final class DeckContext
{
    public const MAX_SLIDES = 600;

    public const MAX_ELS = 400;

    /** @var list<array<string, mixed>> */
    public array $slides = [];

    /** @var array<string, int> slide id => index in */
    public array $indexById = [];

    /**
     * @param  array<string, string>  $palette
     * @param  array<string, mixed>  $theme
     */
    private function __construct(public readonly float $w, public readonly float $h, public readonly array $theme, public readonly array $palette) {}

    /**
     * @param  array<string, mixed>  $doc  {deck: {...}, slides: [...]} as returned by DeckDocument::toArray
     */
    public static function from(array $doc): self
    {
        $deck = is_array($doc['deck'] ?? null) ? $doc['deck'] : [];
        $w = max(100.0, min(20000.0, self::num($deck['w'] ?? 1920) ?: 1920.0));
        $h = max(100.0, min(20000.0, self::num($deck['h'] ?? 1080) ?: 1080.0));
        $theme = is_array($deck['theme'] ?? null) && $deck['theme'] !== [] ? $deck['theme'] : Colour::defaultTheme();
        $ctx = new self($w, $h, $theme, Colour::palette($theme));

        $i = 0;
        foreach (is_array($doc['slides'] ?? null) ? $doc['slides'] : [] as $slide) {
            if (! is_array($slide) || $i >= self::MAX_SLIDES) {
                continue;
            }
            $id = $slide['id'] ?? ('s'.($i + 1));
            if (! is_int($id) && ! is_string($id)) {
                $id = 's'.($i + 1);
            }
            $els = [];
            foreach (array_slice(is_array($slide['els'] ?? null) ? array_values($slide['els']) : [], 0, self::MAX_ELS) as $z => $el) {
                if (is_array($el)) {
                    $els[] = $ctx->element($el, $z);
                }
            }
            $info = [
                'id' => $id,
                'index' => $i + 1,
                'title' => trim(Text::clean(self::scalar($slide['title'] ?? ''))),
                'notes' => self::scalar($slide['notes'] ?? ''),
                'layout' => self::scalar($slide['layout'] ?? 'blank'),
                'hidden' => ! empty($slide['hidden']),
                'bg' => $slide['bg'] ?? null,
                'section_id' => $slide['section_id'] ?? null,
                'transition' => is_array($slide['transition'] ?? null) ? $slide['transition'] : null,
                'els' => $els,
            ];
            $ctx->slides[] = $info;
            $ctx->indexById[$id] = $i;
            $i++;
        }
        foreach ($ctx->slides as $k => $s) {
            $ctx->slides[$k]['title_el'] = $ctx->detectTitle($s);
        }

        return $ctx;
    }

    private static function num(mixed $v): float
    {
        return is_numeric($v) ? (float) $v : 0.0;
    }

    private static function scalar(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }

    /**
     * @param  array<string, mixed>  $el
     * @return array<string, mixed>
     */
    private function element(array $el, int $z): array
    {
        $type = self::scalar($el['type'] ?? '');
        $blocks = ($type === 'text' || $type === 'shape') && isset($el['doc']) ? DocText::blocks($el['doc']) : [];
        $text = trim(implode("\n", array_column($blocks, 'text')));
        if ($type === 'table') {
            $text = trim(DocText::elementText($el));
        }
        $family = $this->family(self::scalar($el['font'] ?? '@body'));

        return [
            'id' => self::scalar($el['id'] ?? ('e'.$z)) ?: ('e'.$z),
            'z' => $z,
            'type' => $type,
            'kind' => self::scalar($el['kind'] ?? ''),
            'role' => self::scalar($el['role'] ?? ''),
            'name' => self::scalar($el['name'] ?? ''),
            'x' => self::num($el['x'] ?? 0),
            'y' => self::num($el['y'] ?? 0),
            'w' => max(0.0, self::num($el['w'] ?? 0)),
            'h' => max(0.0, self::num($el['h'] ?? 0)),
            'rot' => self::num($el['rot'] ?? 0),
            'lock' => ! empty($el['lock']),
            'hide' => ! empty($el['hide']),
            'group' => isset($el['group']) && is_scalar($el['group']) ? (string) $el['group'] : null,
            'origin' => self::scalar($el['origin'] ?? 'user'),
            'alt' => array_key_exists('alt', $el) && is_string($el['alt']) ? $el['alt'] : null,
            'cap' => isset($el['cap']) && is_string($el['cap']) ? $el['cap'] : null,
            'blocks' => $blocks,
            'text' => $text,
            'has_text' => $text !== '',
            'size' => isset($el['size']) && is_numeric($el['size']) && $el['size'] > 0 ? (float) $el['size'] : $this->h * 0.03,
            'size_set' => isset($el['size']) && is_numeric($el['size']),
            'weight' => (int) self::num($el['weight'] ?? 400),
            'family' => $family,
            'font_raw' => self::scalar($el['font'] ?? '@body'),
            'lh' => isset($el['lh']) && is_numeric($el['lh']) && $el['lh'] > 0 ? (float) $el['lh'] : TextMetrics::DEFAULT_LH,
            'ls' => self::num($el['ls'] ?? 0),
            'ps' => isset($el['ps']) && is_numeric($el['ps']) ? (float) $el['ps'] : TextMetrics::DEFAULT_PS,
            'pad' => self::num($el['pad'] ?? 0),
            'fit' => self::scalar($el['fit'] ?? 'none'),
            'color' => $el['color'] ?? null,
            'fill' => $el['fill'] ?? null,
            'stroke' => $el['stroke'] ?? null,
            'anim' => is_array($el['anim'] ?? null) ? $el['anim'] : [],
            'chart' => is_array($el['chart'] ?? null) ? $el['chart'] : null,
            'raw' => $el,
        ];
    }

    private function family(string $font): string
    {
        $t = is_array($this->theme['t'] ?? null) ? $this->theme['t'] : [];
        if ($font === '@heading') {
            return (string) ($t['heading'] ?? 'Public Sans');
        }
        if ($font === '@body' || $font === '') {
            return (string) ($t['body'] ?? 'Public Sans');
        }

        return $font;
    }

    /** The element that serves as the slide title, or null. @param array<string, mixed> $slide */
    private function detectTitle(array $slide): ?array
    {
        $texts = array_values(array_filter($slide['els'], fn ($e) => ! $e['hide'] && $e['type'] === 'text' && $e['has_text']));
        foreach ($texts as $e) {
            if ($e['role'] === 'title') {
                return $e;
            }
        }
        if ($texts === []) {
            return null;
        }
        usort($texts, fn ($a, $b) => $b['size'] <=> $a['size']);
        $top = $texts[0];
        if ($top['y'] > $this->h * 0.45) {
            return null;
        }
        $others = array_map(fn ($e) => $e['size'], array_slice($texts, 1));
        if ($others === []) {
            return $top['size'] >= $this->h * 0.04 ? $top : null;
        }
        sort($others);
        $median = $others[(int) floor((count($others) - 1) / 2)];

        return $top['size'] >= $median * 1.25 ? $top : null;
    }

    /** Visible text-bearing elements of a slide, in z order. @return list<array<string, mixed>> */
    public function textEls(array $slide): array
    {
        return array_values(array_filter($slide['els'], fn ($e) => ! $e['hide'] && ($e['type'] === 'text' || $e['type'] === 'shape') && $e['has_text']));
    }

    /** @return list<array<string, mixed>> */
    public function visible(array $slide): array
    {
        return array_values(array_filter($slide['els'], fn ($e) => ! $e['hide']));
    }

    /** The visible title of a slide: the slide field, else the title element. */
    public function titleText(array $slide): string
    {
        if ($slide['title'] !== '') {
            return $slide['title'];
        }

        return $slide['title_el']['text'] ?? '';
    }

    /** All visible slide text joined (not notes). */
    public function slideText(array $slide): string
    {
        return implode("\n", array_map(fn ($e) => $e['text'], array_filter($slide['els'], fn ($e) => ! $e['hide'] && $e['has_text'])));
    }

    public function isFullBleed(array $e): bool
    {
        return $e['w'] >= $this->w * 0.9 && $e['h'] >= $this->h * 0.9;
    }

    /** Is the element a line or arrow (no area worth aligning)? */
    public function isLine(array $e): bool
    {
        return $e['type'] === 'shape' && in_array($e['kind'], ['line', 'arrow'], true);
    }

    /**
     * Bounding box of the element after rotation.
     *
     * @return array{0: float, 1: float, 2: float, 3: float} left, top, right, bottom
     */
    public function bbox(array $e): array
    {
        if (abs(fmod($e['rot'], 180)) < 0.01) {
            return [$e['x'], $e['y'], $e['x'] + $e['w'], $e['y'] + $e['h']];
        }
        $cx = $e['x'] + $e['w'] / 2;
        $cy = $e['y'] + $e['h'] / 2;
        $a = deg2rad($e['rot']);
        $cw = abs(cos($a)) * $e['w'] + abs(sin($a)) * $e['h'];
        $ch = abs(sin($a)) * $e['w'] + abs(cos($a)) * $e['h'];

        return [$cx - $cw / 2, $cy - $ch / 2, $cx + $cw / 2, $cy + $ch / 2];
    }
}
