<?php

namespace Tests\Unit\Press\Intel;

use App\Services\Press\Intel\DocText;

/** Builds small presentation documents in the R-40 spec shape for the intelligence tests. */
final class DeckFixture
{
    private static int $n = 0;

    /**
     * @param  list<array<string, mixed>>  $slides
     * @param  array<string, mixed>  $deck
     * @return array{deck: array<string, mixed>, slides: list<array<string, mixed>>}
     */
    public static function doc(array $slides, array $deck = []): array
    {
        return [
            'deck' => $deck + ['id' => 1, 'title' => 'Test', 'w' => 1920, 'h' => 1080, 'theme' => null, 'sections' => [], 'slide_order' => array_column($slides, 'id')],
            'slides' => $slides,
        ];
    }

    /** @param  list<array<string, mixed>>  $els */
    public static function slide(string $id, array $els = [], array $extra = []): array
    {
        return $extra + ['id' => $id, 'title' => 'Slide '.$id, 'notes' => '', 'section_id' => null, 'hidden' => false, 'layout' => 'bullets', 'bg' => null, 'transition' => null, 'els' => $els];
    }

    /** @param  string|list<string>  $text */
    public static function text(string $id, string|array $text, array $o = []): array
    {
        $lines = is_array($text) ? $text : [$text];
        $doc = isset($o['bullets']) ? DocText::bullets($lines) : DocText::paragraphs($lines);
        unset($o['bullets']);

        return $o + ['id' => $id, 'type' => 'text', 'x' => 120, 'y' => 300, 'w' => 1200, 'h' => 300, 'size' => 40, 'doc' => $doc];
    }

    public static function title(string $id, string $text, array $o = []): array
    {
        return self::text($id, $text, $o + ['role' => 'title', 'x' => 120, 'y' => 80, 'w' => 1680, 'h' => 120, 'size' => 72, 'weight' => 700]);
    }

    public static function image(string $id, array $o = []): array
    {
        return $o + ['id' => $id, 'type' => 'image', 'x' => 1000, 'y' => 300, 'w' => 700, 'h' => 500, 'src' => ['url' => 'https://example.com/a.png']];
    }

    public static function chart(string $id, array $o = []): array
    {
        return $o + ['id' => $id, 'type' => 'chart', 'x' => 120, 'y' => 300, 'w' => 900, 'h' => 500, 'chart' => ['type' => 'column', 'data' => ['categories' => ['Q1', 'Q2'], 'series' => [['name' => 'Tonnes', 'values' => [10, 12]]]], 'opt' => [], 'src' => ['kind' => 'manual']]];
    }

    public static function shape(string $id, array $o = []): array
    {
        return $o + ['id' => $id, 'type' => 'shape', 'kind' => 'rect', 'x' => 0, 'y' => 0, 'w' => 400, 'h' => 200, 'fill' => '@surface'];
    }

    /** Find an issue of a kind in an analyzer result. */
    public static function issues(array $result, string $kind): array
    {
        return array_values(array_filter($result['issues'], fn ($i) => $i['kind'] === $kind));
    }

    public static function uid(string $p = 'e'): string
    {
        return $p.(++self::$n);
    }
}
