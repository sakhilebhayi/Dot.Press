<?php

namespace App\Services\Press\Export\Support;

/**
 * Flattens a Tiptap/ProseMirror document (paragraph, bulletList, orderedList, listItem, hardBreak, text) into a list
 * of paragraphs with styled runs. The PDF converter, the PPTX writer and the fit estimator all read this one shape.
 *
 * paragraph: {list: null|'bullet'|'ordered', level: int, n: int, runs: [run]}
 * run:       {t: string, br?: true, b: bool, i: bool, u: bool, s: bool, color: ?string, size: ?float, font: ?string, link: ?string}
 */
final class TextDoc
{
    private const MAX_PARAGRAPHS = 400;

    private const MAX_DEPTH = 8;

    /**
     * @param  array<string, mixed>|null  $doc
     * @return list<array{list: ?string, level: int, n: int, runs: list<array<string, mixed>>}>
     */
    public static function paragraphs(?array $doc, ?string $plainFallback = null): array
    {
        $out = [];
        if (is_array($doc) && ($doc['type'] ?? null) === 'doc') {
            self::walk((array) ($doc['content'] ?? []), null, 0, $out, 0);
        }
        if ($out === [] && $plainFallback !== null && $plainFallback !== '') {
            foreach (preg_split('/\R/u', $plainFallback) ?: [] as $line) {
                $out[] = ['list' => null, 'level' => 0, 'n' => 0, 'runs' => $line === '' ? [] : [self::run($line, [])]];
            }
        }
        if ($out === []) {
            $out[] = ['list' => null, 'level' => 0, 'n' => 0, 'runs' => []];
        }

        return $out;
    }

    /** The plain text of the paragraphs, one paragraph per line. */
    public static function plain(?array $doc): string
    {
        $lines = [];
        foreach (self::paragraphs($doc) as $p) {
            $line = '';
            foreach ($p['runs'] as $r) {
                $line .= isset($r['br']) ? "\n" : $r['t'];
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /** @param list<mixed> $nodes @param list<array<string, mixed>> $out */
    private static function walk(array $nodes, ?string $list, int $level, array &$out, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }
        $n = 0;
        foreach ($nodes as $node) {
            if (! is_array($node) || count($out) >= self::MAX_PARAGRAPHS) {
                continue;
            }
            switch ($node['type'] ?? null) {
                case 'paragraph':
                    if ($list !== null) {
                        $n++;
                    }
                    $out[] = ['list' => $list, 'level' => $level, 'n' => $n, 'runs' => self::runs((array) ($node['content'] ?? []))];
                    break;
                case 'bulletList':
                case 'orderedList':
                    self::walk((array) ($node['content'] ?? []), $node['type'] === 'bulletList' ? 'bullet' : 'ordered', $list === null ? 0 : $level + 1, $out, $depth + 1);
                    break;
                case 'listItem':
                    // A list item holds paragraphs (the first carries the marker) and nested lists.
                    $first = true;
                    $itemN = ++$n;
                    foreach ((array) ($node['content'] ?? []) as $child) {
                        if (! is_array($child)) {
                            continue;
                        }
                        if (($child['type'] ?? null) === 'paragraph') {
                            $out[] = ['list' => $first ? $list : $list, 'level' => $level, 'n' => $first ? $itemN : 0, 'runs' => self::runs((array) ($child['content'] ?? [])), 'cont' => ! $first];
                            $first = false;
                        } else {
                            self::walk([$child], $list, $level, $out, $depth + 1);
                        }
                    }
                    if ($first) {
                        $out[] = ['list' => $list, 'level' => $level, 'n' => $itemN, 'runs' => []];
                    }
                    break;
                default:
                    break;
            }
        }
    }

    /** @param list<mixed> $nodes @return list<array<string, mixed>> */
    private static function runs(array $nodes): array
    {
        $runs = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            if (($node['type'] ?? null) === 'hardBreak') {
                $runs[] = ['t' => '', 'br' => true, 'b' => false, 'i' => false, 'u' => false, 's' => false, 'color' => null, 'size' => null, 'font' => null, 'link' => null];

                continue;
            }
            if (($node['type'] ?? null) !== 'text' || ! isset($node['text']) || ! is_string($node['text'])) {
                continue;
            }
            $runs[] = self::run($node['text'], (array) ($node['marks'] ?? []));
        }

        return $runs;
    }

    /** @param list<mixed> $marks @return array<string, mixed> */
    private static function run(string $text, array $marks): array
    {
        $r = ['t' => $text, 'b' => false, 'i' => false, 'u' => false, 's' => false, 'color' => null, 'size' => null, 'font' => null, 'link' => null];
        foreach ($marks as $m) {
            if (! is_array($m)) {
                continue;
            }
            $a = (array) ($m['attrs'] ?? []);
            switch ($m['type'] ?? null) {
                case 'bold':
                    $r['b'] = true;
                    break;
                case 'italic':
                    $r['i'] = true;
                    break;
                case 'underline':
                    $r['u'] = true;
                    break;
                case 'strike':
                    $r['s'] = true;
                    break;
                case 'link':
                    $r['link'] = Els::link($a['href'] ?? null);
                    break;
                case 'textStyle':
                    if (isset($a['color']) && is_string($a['color'])) {
                        $r['color'] = $a['color'];
                    }
                    if (isset($a['fontSize']) && is_numeric($a['fontSize']) && $a['fontSize'] > 0) {
                        $r['size'] = Els::num($a['fontSize'], 0, 1, 2000);
                    }
                    if (isset($a['fontFamily']) && is_string($a['fontFamily'])) {
                        $r['font'] = $a['fontFamily'];
                    }
                    break;
            }
        }

        return $r;
    }
}
