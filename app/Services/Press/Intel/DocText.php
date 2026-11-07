<?php

namespace App\Services\Press\Intel;

/**
 * Reads and builds the restricted Tiptap documents of text elements (document spec, section 6):
 * paragraph, bulletList, orderedList, listItem, hardBreak, text.
 */
final class DocText
{
    /** Longest text the analysers read from one element; anything beyond is ignored, not an error. */
    public const MAX_CHARS = 20000;

    /**
     * @return list<array{text: string, list: 'bullet'|'ordered'|null, depth: int}>
     */
    public static function blocks(mixed $doc): array
    {
        if (is_string($doc)) {
            $out = [];
            foreach (preg_split('/\n/u', Text::clean($doc)) ?: [] as $line) {
                $out[] = ['text' => $line, 'list' => null, 'depth' => 0];
            }

            return $out;
        }
        if (! is_array($doc)) {
            return [];
        }
        $out = [];
        self::walk($doc, null, 0, $out);

        return $out;
    }

    /** @param  list<array{text: string, list: 'bullet'|'ordered'|null, depth: int}>  $out */
    private static function walk(array $node, ?string $list, int $depth, array &$out): void
    {
        $type = $node['type'] ?? '';
        $content = is_array($node['content'] ?? null) ? $node['content'] : [];

        if ($type === 'paragraph') {
            $out[] = ['text' => self::inline($node), 'list' => $list, 'depth' => $depth];

            return;
        }
        if ($type === 'bulletList' || $type === 'orderedList') {
            foreach ($content as $child) {
                if (is_array($child)) {
                    self::walk($child, $type === 'bulletList' ? 'bullet' : 'ordered', $depth + ($list !== null ? 1 : 0), $out);
                }
            }

            return;
        }
        foreach ($content as $child) {
            if (is_array($child)) {
                self::walk($child, $list, $depth, $out);
            }
        }
    }

    private static function inline(array $node): string
    {
        $s = '';
        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            if (! is_array($child)) {
                continue;
            }
            if (($child['type'] ?? '') === 'text') {
                $s .= (string) ($child['text'] ?? '');
            } elseif (($child['type'] ?? '') === 'hardBreak') {
                $s .= "\n";
            }
        }

        return mb_substr($s, 0, self::MAX_CHARS);
    }

    public static function text(mixed $doc): string
    {
        return implode("\n", array_map(fn ($b) => $b['text'], self::blocks($doc)));
    }

    /** All visible text of an element: text doc, shape text, table cells, chart title. */
    public static function elementText(array $el): string
    {
        $type = $el['type'] ?? '';
        if ($type === 'table') {
            $parts = [];
            foreach (is_array($el['rows'] ?? null) ? $el['rows'] : [] as $row) {
                foreach (is_array($row) ? $row : [] as $cell) {
                    if (is_array($cell) && isset($cell['t']) && is_string($cell['t'])) {
                        $parts[] = $cell['t'];
                    }
                }
            }

            return implode("\n", $parts);
        }

        return isset($el['doc']) ? self::text($el['doc']) : '';
    }

    public static function isEmpty(mixed $doc): bool
    {
        return trim(self::text($doc)) === '';
    }

    /**
     * Rebuild a doc from blocks. Consecutive blocks of the same list kind share one list node.
     *
     * @param  list<array{text: string, list?: 'bullet'|'ordered'|null}|string>  $blocks
     */
    public static function build(array $blocks): array
    {
        $content = [];
        $currentList = null;
        $currentType = null;

        foreach ($blocks as $b) {
            $text = is_string($b) ? $b : (string) $b['text'];
            $list = is_string($b) ? null : ($b['list'] ?? null);
            $para = ['type' => 'paragraph'];
            if ($text !== '') {
                $para['content'] = [['type' => 'text', 'text' => $text]];
            }

            if ($list === null) {
                if ($currentList !== null) {
                    $content[] = $currentList;
                    $currentList = null;
                    $currentType = null;
                }
                $content[] = $para;

                continue;
            }
            if ($currentList === null || $currentType !== $list) {
                if ($currentList !== null) {
                    $content[] = $currentList;
                }
                $currentType = $list;
                $currentList = ['type' => $list === 'bullet' ? 'bulletList' : 'orderedList', 'content' => []];
            }
            $currentList['content'][] = ['type' => 'listItem', 'content' => [$para]];
        }
        if ($currentList !== null) {
            $content[] = $currentList;
        }

        return ['type' => 'doc', 'content' => $content ?: [['type' => 'paragraph']]];
    }

    /** @param  list<string>  $items */
    public static function bullets(array $items): array
    {
        return self::build(array_map(fn ($t) => ['text' => $t, 'list' => 'bullet'], $items));
    }

    /** @param  list<string>  $paras */
    public static function paragraphs(array $paras): array
    {
        return self::build(array_map(fn ($t) => ['text' => $t, 'list' => null], $paras));
    }

    /**
     * Apply $fn to every text node, keeping marks and structure.
     *
     * @param  callable(string): string  $fn
     */
    public static function mapText(array $doc, callable $fn): array
    {
        if (($doc['type'] ?? '') === 'text') {
            $doc['text'] = $fn((string) ($doc['text'] ?? ''));

            return $doc;
        }
        if (is_array($doc['content'] ?? null)) {
            foreach ($doc['content'] as $i => $child) {
                if (is_array($child)) {
                    $doc['content'][$i] = self::mapText($child, $fn);
                }
            }
        }

        return $doc;
    }

    /** Does the doc carry any list? */
    public static function hasList(mixed $doc): bool
    {
        foreach (self::blocks($doc) as $b) {
            if ($b['list'] !== null) {
                return true;
            }
        }

        return false;
    }
}
