<?php

namespace App\Services\Press\Data;

use App\Services\Press\Intel\Text;

/**
 * Turns an uploaded csv, tsv, txt or xlsx file into rows. The upload is read from its temporary
 * path and never stored. Caps come from config/press-ai.php.
 */
final class DataParser
{
    /**
     * @return array{format: string, delimiter: ?string, sheets: list<array{name: string, hidden: bool}>, sheet: ?string, header: bool, column_types: list<string>, rows: list<list<string>>, row_count: int, col_count: int, truncated: array{rows: bool, cols: bool}}
     */
    public function parseFile(string $path, string $originalName, ?string $sheet = null): array
    {
        $maxBytes = (int) config('press-ai.data.max_bytes', 5 * 1024 * 1024);
        $maxRows = (int) config('press-ai.data.max_rows', 5000);
        $maxCols = (int) config('press-ai.data.max_cols', 100);

        $size = @filesize($path);
        if ($size === false) {
            throw new DataImportException('The file could not be read.', 'unreadable');
        }
        if ($size > $maxBytes) {
            throw new DataImportException('The file is larger than '.round($maxBytes / 1048576).' MB.', 'too_large');
        }
        $head = (string) file_get_contents($path, false, null, 0, 4);
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        // The content decides, not the extension: an .xlsx is a zip, anything else must be text.
        if (str_starts_with($head, 'PK') || str_starts_with($head, "\xD0\xCF\x11\xE0")) {
            $x = XlsxReader::read($path, $sheet, $maxRows, $maxCols, $maxBytes);
            $d = Delimited::describe($x['rows']);

            return [
                'format' => 'xlsx',
                'delimiter' => null,
                'sheets' => $x['sheets'],
                'sheet' => $x['sheet'],
                'header' => $d['header'],
                'column_types' => $d['column_types'],
                'rows' => $x['rows'],
                'row_count' => count($x['rows']),
                'col_count' => count($x['rows'][0] ?? []),
                'truncated' => ['rows' => $x['truncated_rows'], 'cols' => $x['truncated_cols']],
            ];
        }

        $raw = (string) file_get_contents($path);

        return $this->parseText($this->toUtf8($raw), $ext === 'tsv' ? "\t" : null, $ext);
    }

    /**
     * @return array<string, mixed>
     */
    public function parseText(string $text, ?string $forceDelimiter = null, string $ext = 'txt'): array
    {
        $maxRows = (int) config('press-ai.data.max_rows', 5000);
        $maxCols = (int) config('press-ai.data.max_cols', 100);

        if (str_contains($text, "\0")) {
            throw new DataImportException('This file looks binary, not text.', 'not_text');
        }

        $delimiter = $forceDelimiter ?? Delimited::sniff($text);
        // A .tsv with no tabs falls back to sniffing.
        if ($forceDelimiter !== null && ! str_contains($text, $forceDelimiter)) {
            $delimiter = Delimited::sniff($text);
        }
        $parsed = Delimited::parse($text, $delimiter, $maxRows, $maxCols);
        $d = Delimited::describe($parsed['rows']);

        return [
            'format' => $delimiter === "\t" ? 'tsv' : ($delimiter === null ? 'txt' : 'csv'),
            'delimiter' => $delimiter,
            'sheets' => [],
            'sheet' => null,
            'header' => $d['header'],
            'column_types' => $d['column_types'],
            'rows' => $parsed['rows'],
            'row_count' => count($parsed['rows']),
            'col_count' => count($parsed['rows'][0] ?? []),
            'truncated' => ['rows' => $parsed['truncated_rows'], 'cols' => $parsed['truncated_cols']],
        ];
    }

    private function toUtf8(string $raw): string
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        if (str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF")) {
            return (string) mb_convert_encoding($raw, 'UTF-8', 'UTF-16');
        }
        if (mb_check_encoding($raw, 'UTF-8')) {
            return Text::clean($raw);
        }

        return Text::clean((string) mb_convert_encoding($raw, 'UTF-8', 'Windows-1252'));
    }
}
