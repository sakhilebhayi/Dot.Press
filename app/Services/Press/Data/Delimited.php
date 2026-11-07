<?php

namespace App\Services\Press\Data;

use App\Services\Press\Intel\Numbers;
use App\Services\Press\Intel\Text;

/**
 * CSV, TSV and similar delimited text: delimiter sniffing, a quote aware parser with row and
 * column caps, a header guess and column type guesses. Nothing here touches the file system.
 */
final class Delimited
{
    public const CANDIDATES = ["\t", ',', ';', '|'];

    /**
     * Pick the delimiter that splits the first lines most consistently, or null for plain prose.
     */
    public static function sniff(string $text): ?string
    {
        $lines = array_slice(array_values(array_filter(preg_split('/\n/', Text::clean($text)) ?: [], fn ($l) => trim($l) !== '')), 0, 30);
        if (count($lines) < 1) {
            return null;
        }

        $best = null;
        $bestScore = 0.0;
        foreach (self::CANDIDATES as $d) {
            $counts = array_map(fn ($l) => self::countOutsideQuotes($l, $d), $lines);
            $freq = array_count_values($counts);
            arsort($freq);
            $modal = (int) array_key_first($freq);
            if ($modal < 1) {
                continue;
            }
            $consistency = $freq[$modal] / count($lines);
            // One line with a stray comma is prose, not a table: with several lines it has to repeat.
            if (count($lines) === 1 && $d !== "\t") {
                $consistency *= 0.5;
            }
            if (count($lines) >= 2 && $consistency < 0.6) {
                continue;
            }
            $score = $consistency * (1 + min($modal, 6) / 10);
            if ($score > $bestScore + 1e-9) {
                $bestScore = $score;
                $best = $d;
            }
        }

        return $bestScore >= 0.5 ? $best : null;
    }

    private static function countOutsideQuotes(string $line, string $d): int
    {
        $n = 0;
        $in = false;
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $ch = $line[$i];
            if ($ch === '"') {
                $in = ! $in;
            } elseif (! $in && $ch === $d) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @return array{rows: list<list<string>>, truncated_rows: bool, truncated_cols: bool}
     */
    public static function parse(string $text, ?string $delimiter, int $maxRows = 5000, int $maxCols = 100): array
    {
        $text = Text::clean($text);
        if (str_starts_with($text, "\u{FEFF}")) {
            $text = substr($text, 3);
        }

        $rows = [];
        $truncRows = false;
        $truncCols = false;

        if ($delimiter === null) {
            foreach (explode("\n", $text) as $line) {
                if (trim($line) === '') {
                    continue;
                }
                if (count($rows) >= $maxRows) {
                    $truncRows = true;
                    break;
                }
                $rows[] = [trim($line)];
            }

            return ['rows' => $rows, 'truncated_rows' => $truncRows, 'truncated_cols' => false];
        }

        $row = [];
        $field = '';
        $inQuotes = false;
        $len = strlen($text);
        $endRow = function () use (&$rows, &$row, &$field, &$truncRows, &$truncCols, $maxRows, $maxCols) {
            $row[] = $field;
            $field = '';
            if (count($row) === 1 && trim($row[0]) === '') {
                $row = [];

                return true;
            }
            if (count($row) > $maxCols) {
                $row = array_slice($row, 0, $maxCols);
                $truncCols = true;
            }
            if (count($rows) >= $maxRows) {
                $truncRows = true;
                $row = [];

                return false;
            }
            $rows[] = $row;
            $row = [];

            return true;
        };

        for ($i = 0; $i < $len; $i++) {
            $ch = $text[$i];
            if ($inQuotes) {
                if ($ch === '"') {
                    if ($i + 1 < $len && $text[$i + 1] === '"') {
                        $field .= '"';
                        $i++;
                    } else {
                        $inQuotes = false;
                    }
                } else {
                    $field .= $ch;
                }

                continue;
            }
            if ($ch === '"' && $field === '') {
                $inQuotes = true;
            } elseif ($ch === $delimiter) {
                if (count($row) < $maxCols + 1) {
                    $row[] = $field;
                }
                $field = '';
            } elseif ($ch === "\n") {
                if (! $endRow()) {
                    return ['rows' => $rows, 'truncated_rows' => true, 'truncated_cols' => $truncCols];
                }
            } else {
                $field .= $ch;
            }
        }
        if ($field !== '' || $row !== []) {
            $endRow();
        }

        // Trim every cell once and make the rows rectangular.
        $width = 0;
        foreach ($rows as $r) {
            $width = max($width, count($r));
        }
        foreach ($rows as $i => $r) {
            $r = array_map(fn ($c) => trim($c), $r);
            $rows[$i] = array_pad($r, $width, '');
        }

        return ['rows' => $rows, 'truncated_rows' => $truncRows, 'truncated_cols' => $truncCols];
    }

    /** Parse a cell as a number, tolerating currency symbols, percent signs and thousands separators. */
    public static function number(string $cell): ?float
    {
        $c = trim($cell);
        if ($c === '') {
            return null;
        }
        $neg = false;
        if (preg_match('/^\((.*)\)$/', $c, $m)) {
            $neg = true;
            $c = $m[1];
        }
        $c = preg_replace('/^(?:R|\$|€|£|¥|USD|ZAR|EUR|GBP)\s?/u', '', $c) ?? $c;
        if (str_starts_with($c, '-') || str_starts_with($c, '−')) {
            $neg = ! $neg;
            $c = substr($c, str_starts_with($c, '-') ? 1 : 3);
        }
        $c = rtrim($c, '%');
        $c = trim($c);
        if (! preg_match('/^(\d{1,3}([ \x{00A0},]\d{3})+(\.\d+)?|\d+([.,]\d+)?)$/u', $c)) {
            return null;
        }
        $v = Numbers::parseNumber($c);

        return $neg ? -$v : $v;
    }

    public static function isDate(string $cell): bool
    {
        return (bool) preg_match('/^(\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?)?|\d{1,2}\/\d{1,2}\/\d{2,4}|\d{1,2} [A-Za-z]{3,9} \d{4})$/', trim($cell));
    }

    /**
     * @param  list<list<string>>  $rows
     * @return array{header: bool, column_types: list<string>}
     */
    public static function describe(array $rows): array
    {
        if ($rows === []) {
            return ['header' => false, 'column_types' => []];
        }
        $width = count($rows[0]);
        $body = array_slice($rows, 1);

        $typeOf = function (string $c): string {
            if (trim($c) === '') {
                return 'empty';
            }
            if (self::isDate($c)) {
                return 'date';
            }

            return self::number($c) !== null ? 'number' : 'text';
        };

        $types = [];
        $firstTypes = array_map($typeOf, $rows[0]);
        for ($c = 0; $c < $width; $c++) {
            $counts = [];
            foreach ($body ?: $rows as $r) {
                $t = $typeOf($r[$c] ?? '');
                if ($t !== 'empty') {
                    $counts[$t] = ($counts[$t] ?? 0) + 1;
                }
            }
            arsort($counts);
            $top = array_key_first($counts) ?? 'text';
            $total = array_sum($counts);
            $types[] = $total > 0 && ($counts[$top] / $total) >= 0.8 ? $top : ($total > 0 ? 'text' : 'empty');
        }

        $header = false;
        if (count($rows) >= 2) {
            $firstAllText = true;
            foreach ($firstTypes as $t) {
                if ($t === 'number' || $t === 'date') {
                    $firstAllText = false;
                }
            }
            $bodyHasTyped = false;
            foreach ($types as $c => $t) {
                if (($t === 'number' || $t === 'date') && ($firstTypes[$c] ?? 'text') === 'text') {
                    $bodyHasTyped = true;
                }
            }
            $unique = count(array_unique(array_map('mb_strtolower', $rows[0]))) === count($rows[0]);
            $header = $firstAllText && ($bodyHasTyped || $unique && count($rows) > 2);
        }

        return ['header' => $header, 'column_types' => $types];
    }
}
