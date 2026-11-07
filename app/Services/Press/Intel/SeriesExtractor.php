<?php

namespace App\Services\Press\Intel;

use App\Services\Press\Data\Delimited;

/**
 * Finds real data series in prose or pasted tables, so a chart is only ever proposed for numbers
 * the text actually contains. Three shapes are recognised:
 *
 *  - table: three or more consecutive lines split by tabs, pipes, semicolons or commas with
 *    numeric columns (a pasted spreadsheet range or a markdown table);
 *  - lines: three or more consecutive "Label: value" lines sharing one unit;
 *  - inline: three or more time-labelled values in one sentence ("Q1 12.5, Q2 14.1 and Q3 15.8"),
 *    or three or more labelled percentages.
 *
 * Nothing is inferred, smoothed or completed: a block either carries every value or is not returned.
 */
final class SeriesExtractor
{
    private const TIME_LABEL = '(?:Q[1-4](?:\s?\d{2,4})?|H[12](?:\s?\d{2,4})?|FY\s?\d{2,4}|(?:19|20)\d{2}|(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec)[a-z]*(?:\s(?:19|20)?\d{2})?|Mon(?:day)?|Tue(?:s(?:day)?)?|Wed(?:nesday)?|Thu(?:r(?:s(?:day)?)?)?|Fri(?:day)?|Sat(?:urday)?|Sun(?:day)?|Week\s?\d+|W\d+)';

    /**
     * @return list<array{kind: string, line_start: int, line_end: int, categories: list<string>, series: list<array{name: string, values: list<float>}>, unit: string, title: string, time: bool}>
     */
    public static function extract(string $text): array
    {
        $lines = explode("\n", Text::clean($text));
        $blocks = [];
        $claimed = [];

        foreach (self::tables($lines) as $b) {
            $blocks[] = $b;
            for ($i = $b['line_start']; $i <= $b['line_end']; $i++) {
                $claimed[$i] = true;
            }
        }
        foreach (self::labelLines($lines, $claimed) as $b) {
            $blocks[] = $b;
            for ($i = $b['line_start']; $i <= $b['line_end']; $i++) {
                $claimed[$i] = true;
            }
        }
        foreach (self::inline($lines, $claimed) as $b) {
            $blocks[] = $b;
        }

        usort($blocks, fn ($a, $b) => $a['line_start'] <=> $b['line_start']);

        return $blocks;
    }

    /**
     * Chart data in the document spec shape for one block.
     *
     * @param  array<string, mixed>  $block
     * @return array{type: string, data: array<string, mixed>, opt: array<string, mixed>, src: array<string, mixed>}
     */
    public static function chart(array $block): array
    {
        $n = count($block['categories']);
        $one = count($block['series']) === 1;
        $type = 'column';
        if ($one && $block['unit'] === '%' && $n >= 2 && $n <= 6) {
            $sum = array_sum($block['series'][0]['values']);
            if ($sum >= 97 && $sum <= 103) {
                $type = 'doughnut';
            }
        }
        if ($type === 'column') {
            if ($block['time'] && $n >= 5) {
                $type = 'line';
            } elseif (! $block['time'] && ($n > 8 || max(array_map('mb_strlen', $block['categories'])) > 18)) {
                $type = 'bar';
            }
        }
        $fmt = $block['unit'] === '%' ? 'pct' : (in_array($block['unit'], ['R', '$', '€', '£'], true) ? 'cur' : 'num');
        $opt = [
            'legend' => $one ? 'none' : 'bottom',
            'labels' => $type === 'doughnut' ? 'percent' : 'value',
            'stacked' => false,
            'smooth' => false,
            'grid' => true,
            'fmt' => $fmt,
        ];
        if ($fmt === 'cur') {
            $opt['cur'] = $block['unit'];
        }
        if ($block['title'] !== '') {
            $opt['title'] = $block['title'];
        }

        return [
            'type' => $type,
            'data' => [
                'categories' => $block['categories'],
                'series' => array_map(fn ($s) => ['name' => $s['name'], 'values' => $s['values']], $block['series']),
            ],
            'opt' => $opt,
            'src' => ['kind' => 'manual'],
        ];
    }

    // ----- tables -----

    /** @return list<array<string, mixed>> */
    private static function tables(array $lines): array
    {
        $blocks = [];
        $n = count($lines);
        $i = 0;

        while ($i < $n) {
            $found = false;
            foreach (["\t", '|', ';', ','] as $d) {
                $count = self::fieldCount($lines[$i], $d);
                if ($count < 1) {
                    continue;
                }
                $j = $i;
                while ($j + 1 < $n && (self::fieldCount($lines[$j + 1], $d) === $count || self::isRule($lines[$j + 1]))) {
                    $j++;
                }
                if ($j - $i + 1 < 3) {
                    continue;
                }
                $run = array_values(array_filter(array_slice($lines, $i, $j - $i + 1), fn ($l) => ! self::isRule($l)));
                $block = self::tableBlock($run, $d, $i, $j, $lines);
                if ($block !== null) {
                    $blocks[] = $block;
                    $i = $j + 1;
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                $i++;
            }
        }

        return $blocks;
    }

    private static function fieldCount(string $line, string $d): int
    {
        $t = trim($line);
        if ($t === '') {
            return 0;
        }
        if ($d === '|') {
            $t = trim($t, '|');
        }

        return substr_count($t, $d);
    }

    private static function isRule(string $line): bool
    {
        return (bool) preg_match('/^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$/', $line);
    }

    /** @param  list<string>  $run */
    private static function tableBlock(array $run, string $d, int $i, int $j, array $lines): ?array
    {
        $cells = array_map(function ($l) use ($d) {
            $t = trim($l);
            if ($d === '|') {
                $t = trim($t, '|');
            }

            return array_map('trim', str_getcsv($t, $d, '"', ''));
        }, $run);
        $width = count($cells[0]);
        if ($width < 2 || $width > 30) {
            return null;
        }
        $desc = Delimited::describe($cells);
        $types = $desc['column_types'];
        $header = $desc['header'];

        $body = $header ? array_slice($cells, 1) : $cells;
        if (count($body) < 2) {
            return null;
        }

        // Category column: the first one. Numeric columns after it become series.
        $catIdx = 0;
        $numCols = [];
        for ($c = 1; $c < $width; $c++) {
            if (($types[$c] ?? '') === 'number') {
                $numCols[] = $c;
            }
        }
        if ($numCols === []) {
            return null;
        }
        // Prose with commas often has a "number" column by accident; require every body cell to parse.
        $categories = [];
        foreach ($body as $row) {
            $categories[] = $row[$catIdx] ?? '';
        }
        if (in_array('', $categories, true) || count(array_unique($categories)) !== count($categories)) {
            return null;
        }
        $series = [];
        $unit = '';
        foreach ($numCols as $c) {
            $values = [];
            foreach ($body as $row) {
                $v = Delimited::number($row[$c] ?? '');
                if ($v === null) {
                    return null;
                }
                $values[] = $v;
                $u = self::unitOf($row[$c]);
                if ($unit === '' && $u !== '') {
                    $unit = $u;
                }
            }
            $name = $header ? ($cells[0][$c] ?? '') : '';
            $series[] = ['name' => $name !== '' ? $name : 'Series '.(count($series) + 1), 'values' => $values];
        }

        $title = '';
        if ($i > 0 && trim($lines[$i - 1]) !== '' && mb_strlen(trim($lines[$i - 1])) <= 80 && ! preg_match('/[.!?]$/', trim($lines[$i - 1]))) {
            $title = rtrim(trim($lines[$i - 1]), ':');
        }

        return [
            'kind' => 'table',
            'line_start' => $i,
            'line_end' => $j,
            'categories' => $categories,
            'series' => $series,
            'unit' => $unit,
            'title' => $title,
            'time' => self::looksTime($categories),
        ];
    }

    // ----- "Label: value" lines -----

    /**
     * @param  array<int, true>  $claimed
     * @return list<array<string, mixed>>
     */
    private static function labelLines(array $lines, array $claimed): array
    {
        $re = '/^\s*(?:[-*•]\s*)?(?<label>[^\d:=]{2,50}?)\s*[:=\x{2013}\x{2014}-]\s*(?<cur>R|\$|€|£)?\s?(?<num>-?\d[\d ,.]*?)\s*(?<suf>%|million|billion|thousand|m|bn|k|t|tonnes?|kg|ha|MW|GWh|oz|units?)?\s*\.?\s*$/ui';
        $blocks = [];
        $n = count($lines);
        $i = 0;

        while ($i < $n) {
            if (isset($claimed[$i]) || ! preg_match($re, $lines[$i], $m)) {
                $i++;

                continue;
            }
            $unit = self::normUnit($m['cur'] ?? '', $m['suf'] ?? '');
            $labels = [trim($m['label'])];
            $values = [Delimited::number($m['num'])];
            $j = $i;
            while ($j + 1 < $n && ! isset($claimed[$j + 1]) && preg_match($re, $lines[$j + 1], $mm) && self::normUnit($mm['cur'] ?? '', $mm['suf'] ?? '') === $unit) {
                $labels[] = trim($mm['label']);
                $values[] = Delimited::number($mm['num']);
                $j++;
            }
            if (count($labels) >= 3 && ! in_array(null, $values, true) && count(array_unique($labels)) === count($labels)) {
                $title = '';
                if ($i > 0 && trim($lines[$i - 1]) !== '' && mb_strlen(trim($lines[$i - 1])) <= 80 && preg_match('/:$|^[^.!?]+$/u', trim($lines[$i - 1]))) {
                    $title = rtrim(trim($lines[$i - 1]), ':');
                }
                $blocks[] = [
                    'kind' => 'lines',
                    'line_start' => $i,
                    'line_end' => $j,
                    'categories' => $labels,
                    'series' => [['name' => $unit !== '' ? $unit : 'Value', 'values' => $values]],
                    'unit' => $unit,
                    'title' => $title,
                    'time' => self::looksTime($labels),
                ];
                $i = $j + 1;
            } else {
                $i++;
            }
        }

        return $blocks;
    }

    // ----- inline -----

    /**
     * @param  array<int, true>  $claimed
     * @return list<array<string, mixed>>
     */
    private static function inline(array $lines, array $claimed): array
    {
        $blocks = [];
        $pair = '(?<l>'.self::TIME_LABEL.')\s?[:=]?\s*(?<cur>R|\$|€|£)?\s?(?<n>-?\d[\d,]*(?:\.\d+)?)\s?(?<s>%|m|bn|k|million|billion)?';

        foreach ($lines as $idx => $line) {
            if (isset($claimed[$idx]) || mb_strlen($line) > 2000) {
                continue;
            }
            // Time labelled runs: pairs separated by comma, semicolon, "and" or "then".
            if (preg_match_all('/(?<![\p{L}\d])'.$pair.'(?=\s*(?:[,;.)]|\sand\b|\sthen\b|$))/iu', $line, $ms, PREG_SET_ORDER) && count($ms) >= 3) {
                $labels = [];
                $values = [];
                $unit = '';
                $uniform = true;
                foreach ($ms as $m) {
                    $u = self::normUnit($m['cur'] ?? '', $m['s'] ?? '');
                    if ($unit === '') {
                        $unit = $u;
                    } elseif ($u !== $unit) {
                        $uniform = false;
                    }
                    $labels[] = trim($m['l']);
                    $values[] = Delimited::number($m['n']);
                }
                // A year label followed by a count of the same magnitude ("2022 2023 2024") is not a series.
                if ($uniform && ! in_array(null, $values, true) && count(array_unique($labels)) === count($labels)
                    && ! self::allYearsAsValues($labels, $values)) {
                    $blocks[] = [
                        'kind' => 'inline',
                        'line_start' => $idx,
                        'line_end' => $idx,
                        'categories' => $labels,
                        'series' => [['name' => $unit !== '' ? $unit : 'Value', 'values' => $values]],
                        'unit' => $unit,
                        'title' => '',
                        'time' => true,
                    ];

                    continue;
                }
            }
            // Labelled percentages: "retail at 42%, wholesale 33% and online 25%".
            if (preg_match_all('/(?<![\p{L}\d])(?<l>[A-Za-z][A-Za-z&\/ -]{1,24}?)\s(?:at\s|is\s|was\s|:\s?)?(?<n>\d+(?:\.\d+)?)\s?%/u', $line, $ms, PREG_SET_ORDER) && count($ms) >= 3) {
                $labels = array_map(fn ($m) => trim((string) preg_replace('/^(?:and|or|with|plus)\s+/iu', '', trim($m['l']))), $ms);
                if (count(array_unique($labels)) === count($labels)) {
                    $blocks[] = [
                        'kind' => 'inline',
                        'line_start' => $idx,
                        'line_end' => $idx,
                        'categories' => $labels,
                        'series' => [['name' => '%', 'values' => array_map(fn ($m) => (float) $m['n'], $ms)]],
                        'unit' => '%',
                        'title' => '',
                        'time' => false,
                    ];
                }
            }
        }

        return $blocks;
    }

    private static function allYearsAsValues(array $labels, array $values): bool
    {
        foreach ($labels as $l) {
            if (! preg_match('/^(19|20)\d{2}$/', $l)) {
                return false;
            }
        }
        foreach ($values as $v) {
            if ($v === null || $v < 1900 || $v > 2100) {
                return false;
            }
        }

        return true;
    }

    // ----- shared -----

    private static function unitOf(string $cell): string
    {
        $c = trim($cell);
        if (str_ends_with($c, '%')) {
            return '%';
        }
        foreach (['R', '$', '€', '£'] as $sym) {
            if (str_starts_with($c, $sym)) {
                return $sym;
            }
        }

        return '';
    }

    private static function normUnit(string $cur, string $suf): string
    {
        $cur = trim($cur);
        $suf = mb_strtolower(trim($suf));
        if ($suf === '%') {
            return '%';
        }
        $suf = match ($suf) {
            'm' => 'million', 'bn' => 'billion', 'k' => 'thousand', default => $suf,
        };

        return trim($cur.($suf !== '' ? ' '.$suf : ''));
    }

    /** @param  list<string>  $cats */
    private static function looksTime(array $cats): bool
    {
        if ($cats === []) {
            return false;
        }
        $re = '/^'.self::TIME_LABEL.'$/iu';
        $iso = '/^\d{4}-\d{2}(-\d{2})?$/';
        foreach ($cats as $c) {
            if (! preg_match($re, trim($c)) && ! preg_match($iso, trim($c))) {
                return false;
            }
        }

        return true;
    }
}
