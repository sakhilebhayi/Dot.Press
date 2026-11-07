<?php

namespace App\Services\Ai;

use App\Services\Press\DocumentSanitizer;
use Illuminate\Support\Str;

/**
 * Turns generated content (a title, bullets, a quote, sections) into spec elements sized from the deck's own width and
 * height, so a model never has to invent coordinates. Text uses theme tokens (`@ink`, `@muted`, `@accent`,
 * `@heading`, `@body`), so the slide follows the deck theme; every element is marked origin "ai".
 */
class SlideComposer
{
    public const LAYOUTS = ['title', 'bullets', 'quote', 'sections'];

    public function __construct(private readonly DocumentSanitizer $san = new DocumentSanitizer) {}

    /**
     * @param  array<string, mixed>  $spec  layout, title, subtitle, bullets[], quote, attribution, sections[{heading, body}], notes
     * @return array{title: string, layout: string, notes: string|null, els: list<array<string, mixed>>}
     */
    public function compose(array $spec, int $w, int $h): array
    {
        $layout = in_array($spec['layout'] ?? null, self::LAYOUTS, true) ? $spec['layout'] : 'bullets';
        $title = trim((string) ($spec['title'] ?? '')) ?: 'Untitled';
        $notes = isset($spec['notes']) && trim((string) $spec['notes']) !== '' ? trim((string) $spec['notes']) : null;

        // Type scales with the shorter side so portrait and 4:3 decks stay readable.
        $u = min($w, $h) / 1080;
        $mx = round($w * 0.07);

        return match ($layout) {
            'title' => $this->titleSlide($spec, $title, $notes, $w, $h, $u, $mx),
            'quote' => $this->quoteSlide($spec, $title, $notes, $w, $h, $u, $mx),
            'sections' => $this->sectionsSlide($spec, $title, $notes, $w, $h, $u, $mx),
            default => $this->bulletsSlide($spec, $title, $notes, $w, $h, $u, $mx),
        };
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function titleSlide(array $spec, string $title, ?string $notes, int $w, int $h, float $u, float $mx): array
    {
        $els = [$this->text($title, 'title', $mx, $h * 0.30, $w - 2 * $mx, $h * 0.26, 104 * $u, 700, '@ink', 'bottom')];
        if (filled($spec['subtitle'] ?? null)) {
            $els[] = $this->text((string) $spec['subtitle'], 'subtitle', $mx, $h * 0.60, $w - 2 * $mx, $h * 0.16, 44 * $u, 400, '@muted', 'top', 'body');
        }

        return ['title' => $title, 'layout' => 'title', 'notes' => $notes, 'els' => $els];
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function bulletsSlide(array $spec, string $title, ?string $notes, int $w, int $h, float $u, float $mx): array
    {
        $bullets = array_values(array_filter(array_map(fn ($b) => trim((string) $b), (array) ($spec['bullets'] ?? [])), fn ($b) => $b !== ''));

        $els = [$this->text($title, 'title', $mx, $h * 0.08, $w - 2 * $mx, $h * 0.16, 68 * $u, 700, '@ink', 'middle')];
        if ($bullets !== []) {
            $els[] = $this->bullets($bullets, $mx, $h * 0.28, $w - 2 * $mx, $h * 0.62, 42 * $u);
        }

        return ['title' => $title, 'layout' => 'bullets', 'notes' => $notes, 'els' => $els];
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function quoteSlide(array $spec, string $title, ?string $notes, int $w, int $h, float $u, float $mx): array
    {
        $quote = trim((string) ($spec['quote'] ?? '')) ?: $title;
        $els = [$this->text($quote, 'body', $w * 0.12, $h * 0.22, $w * 0.76, $h * 0.40, 64 * $u, 600, '@ink', 'middle', 'heading', 'left')];
        if (filled($spec['attribution'] ?? null)) {
            $els[] = $this->text((string) $spec['attribution'], 'caption', $w * 0.12, $h * 0.66, $w * 0.76, $h * 0.08, 34 * $u, 400, '@muted', 'top', 'body');
        }

        return ['title' => $title, 'layout' => 'quote', 'notes' => $notes, 'els' => $els];
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function sectionsSlide(array $spec, string $title, ?string $notes, int $w, int $h, float $u, float $mx): array
    {
        $sections = array_slice(array_values(array_filter((array) ($spec['sections'] ?? []), 'is_array')), 0, 4);

        $els = [$this->text($title, 'title', $mx, $h * 0.08, $w - 2 * $mx, $h * 0.16, 68 * $u, 700, '@ink', 'middle')];

        $count = max(1, count($sections));
        $gap = $w * 0.03;
        $colW = ($w - 2 * $mx - $gap * ($count - 1)) / $count;
        foreach ($sections as $i => $section) {
            $x = $mx + $i * ($colW + $gap);
            $els[] = $this->text((string) ($section['heading'] ?? ''), 'subtitle', $x, $h * 0.30, $colW, $h * 0.10, 40 * $u, 700, '@accent', 'top', 'heading');
            $els[] = $this->text((string) ($section['body'] ?? ''), 'body', $x, $h * 0.42, $colW, $h * 0.46, 30 * $u, 400, '@ink', 'top', 'body');
        }

        $layout = count($sections) === 2 ? 'two' : 'blank';

        return ['title' => $title, 'layout' => $layout, 'notes' => $notes, 'els' => $els];
    }

    /**
     * @return array<string, mixed>
     */
    private function text(string $text, string $role, float $x, float $y, float $w, float $h, float $size, int $weight, string $color, string $valign, string $font = 'heading', string $align = 'left'): array
    {
        return [
            'id' => Str::lower(Str::random(10)),
            'type' => 'text',
            'role' => $role,
            'origin' => 'ai',
            'x' => round($x), 'y' => round($y), 'w' => round($w), 'h' => round($h),
            'doc' => $this->san->docFromText(trim($text)),
            'font' => '@'.$font,
            'size' => round($size),
            'weight' => $weight,
            'color' => $color,
            'align' => $align,
            'valign' => $valign,
            'lh' => 1.25,
            'fit' => 'shrink',
        ];
    }

    /**
     * @param  list<string>  $items
     * @return array<string, mixed>
     */
    private function bullets(array $items, float $x, float $y, float $w, float $h, float $size): array
    {
        $doc = [
            'type' => 'doc',
            'content' => [[
                'type' => 'bulletList',
                'content' => array_map(fn (string $item) => [
                    'type' => 'listItem',
                    'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $item]]]],
                ], array_slice($items, 0, 8)),
            ]],
        ];

        return [
            'id' => Str::lower(Str::random(10)),
            'type' => 'text',
            'role' => 'body',
            'origin' => 'ai',
            'x' => round($x), 'y' => round($y), 'w' => round($w), 'h' => round($h),
            'doc' => $this->san->doc($doc),
            'font' => '@body',
            'size' => round($size),
            'weight' => 400,
            'color' => '@ink',
            'align' => 'left',
            'valign' => 'top',
            'lh' => 1.35,
            'ps' => 0.5,
            'fit' => 'shrink',
        ];
    }
}
