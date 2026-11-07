<?php

namespace App\Services\Press\Data;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use XMLReader;
use ZipArchive;

/**
 * Reads the cell values of one sheet from an .xlsx file, safely.
 *
 * Safety rules: the file is capped (5 MB by default); the archive is never extracted to disk, so a
 * path traversal name cannot write anywhere, and an archive that contains one is refused outright;
 * entries are checked for entry count, uncompressed size and compression ratio before they are read
 * and the read itself is bounded, so a zip bomb is refused; XML is parsed with external entities and
 * network access off, and any DOCTYPE is refused. Formulas are not evaluated: the cached value the
 * spreadsheet stored is what you get. Dates become ISO strings.
 */
final class XlsxReader
{
    private const MAX_ENTRIES = 2000;

    private const MAX_PART_BYTES = 40 * 1024 * 1024;

    private const MAX_RATIO = 150;

    private const BUILTIN_DATE_FORMATS = [14, 15, 16, 17, 18, 19, 20, 21, 22, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 45, 46, 47, 50, 51, 52, 53, 54, 55, 56, 57, 58];

    /**
     * @return array{sheets: list<array{name: string, hidden: bool}>, sheet: string, rows: list<list<string>>, truncated_rows: bool, truncated_cols: bool}
     */
    public static function read(string $path, ?string $sheet = null, int $maxRows = 5000, int $maxCols = 100, int $maxBytes = 5 * 1024 * 1024): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new DataImportException('The file could not be read.', 'unreadable');
        }
        $size = filesize($path);
        if ($size === false || $size > $maxBytes) {
            throw new DataImportException('The file is larger than '.round($maxBytes / 1048576).' MB.', 'too_large');
        }

        $head = (string) file_get_contents($path, false, null, 0, 8);
        if (str_starts_with($head, "\xD0\xCF\x11\xE0")) {
            throw new DataImportException('This looks like a password protected or old binary Excel file. Save it as .xlsx without a password and upload it again.', 'encrypted');
        }
        if (! str_starts_with($head, 'PK')) {
            throw new DataImportException('This is not an .xlsx file.', 'not_xlsx');
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new DataImportException('This is not a readable .xlsx file.', 'not_xlsx');
        }

        try {
            self::checkArchive($zip);

            $workbook = self::part($zip, 'xl/workbook.xml');
            if ($workbook === null) {
                throw new DataImportException('This is not an Excel workbook: xl/workbook.xml is missing.', 'not_xlsx');
            }
            $rels = self::part($zip, 'xl/_rels/workbook.xml.rels');

            [$sheets, $date1904] = self::sheets($workbook, $rels ?? '');
            if ($sheets === []) {
                throw new DataImportException('The workbook has no sheets.', 'empty');
            }

            $chosen = self::chooseSheet($sheets, $sheet);
            $shared = self::sharedStrings($zip);
            $dateStyles = self::dateStyles($zip);

            $sheetXml = self::part($zip, $chosen['target']);
            if ($sheetXml === null) {
                throw new DataImportException('The sheet "'.$chosen['name'].'" could not be found in the file.', 'missing_sheet');
            }

            [$rows, $tr, $tc] = self::cells($sheetXml, $shared, $dateStyles, $date1904, $maxRows, $maxCols);
        } finally {
            $zip->close();
        }

        return [
            'sheets' => array_map(fn ($s) => ['name' => $s['name'], 'hidden' => $s['hidden']], $sheets),
            'sheet' => $chosen['name'],
            'rows' => $rows,
            'truncated_rows' => $tr,
            'truncated_cols' => $tc,
        ];
    }

    private static function checkArchive(ZipArchive $zip): void
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            throw new DataImportException('The file has too many parts to be a normal workbook.', 'zip_bomb');
        }
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            if ($st === false) {
                throw new DataImportException('The archive is damaged.', 'invalid');
            }
            $name = (string) $st['name'];
            if (str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\') || str_contains($name, "\0") || preg_match('/^[A-Za-z]:/', $name)) {
                throw new DataImportException('The archive contains an unsafe file name and was refused.', 'traversal');
            }
            $total += (int) $st['size'];
            if ((int) $st['size'] > self::MAX_PART_BYTES || $total > self::MAX_PART_BYTES * 3) {
                throw new DataImportException('The file expands to far more data than a normal workbook and was refused.', 'zip_bomb');
            }
            if ((int) $st['comp_size'] > 0 && (int) $st['size'] / (int) $st['comp_size'] > self::MAX_RATIO && (int) $st['size'] > 1024 * 1024) {
                throw new DataImportException('The file is compressed in a suspicious way and was refused.', 'zip_bomb');
            }
        }
    }

    private static function part(ZipArchive $zip, string $name): ?string
    {
        $name = ltrim($name, '/');
        $st = $zip->statName($name);
        if ($st === false) {
            return null;
        }
        if ((int) $st['size'] > self::MAX_PART_BYTES) {
            throw new DataImportException('A part of the file is too large.', 'zip_bomb');
        }
        $fp = $zip->getStream($name);
        if ($fp === false) {
            return null;
        }
        $data = stream_get_contents($fp, self::MAX_PART_BYTES + 1);
        fclose($fp);
        if ($data === false || strlen($data) > self::MAX_PART_BYTES) {
            throw new DataImportException('A part of the file is too large.', 'zip_bomb');
        }
        if (stripos(substr($data, 0, 4096), '<!DOCTYPE') !== false || stripos($data, '<!ENTITY') !== false) {
            throw new DataImportException('The file contains a document type declaration, which is not allowed.', 'xxe');
        }

        return $data;
    }

    private static function reader(string $xml): XMLReader
    {
        $r = new XMLReader;
        // No LIBXML_NOENT (entities are not substituted) and no network access.
        if (! $r->XML($xml, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT)) {
            throw new DataImportException('The file contains XML that cannot be read.', 'invalid');
        }

        return $r;
    }

    /**
     * @return array{0: list<array{name: string, hidden: bool, target: string}>, 1: bool}
     */
    private static function sheets(string $workbookXml, string $relsXml): array
    {
        $targets = [];
        if ($relsXml !== '') {
            $r = self::reader($relsXml);
            while (@$r->read()) {
                if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'Relationship') {
                    $targets[(string) $r->getAttribute('Id')] = (string) $r->getAttribute('Target');
                }
            }
            $r->close();
        }

        $sheets = [];
        $date1904 = false;
        $r = self::reader($workbookXml);
        $n = 0;
        while (@$r->read()) {
            if ($r->nodeType !== XMLReader::ELEMENT) {
                continue;
            }
            if ($r->localName === 'workbookPr') {
                $date1904 = in_array($r->getAttribute('date1904'), ['1', 'true'], true);
            }
            if ($r->localName === 'sheet') {
                $n++;
                $rid = (string) $r->getAttributeNs('id', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                $target = $targets[$rid] ?? 'worksheets/sheet'.$n.'.xml';
                $target = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.ltrim($target, '/');
                $sheets[] = [
                    'name' => (string) $r->getAttribute('name'),
                    'hidden' => in_array($r->getAttribute('state'), ['hidden', 'veryHidden'], true),
                    'target' => $target,
                ];
            }
        }
        $r->close();

        return [$sheets, $date1904];
    }

    private static function chooseSheet(array $sheets, ?string $wanted): array
    {
        if ($wanted !== null && $wanted !== '') {
            foreach ($sheets as $s) {
                if ($s['name'] === $wanted) {
                    return $s;
                }
            }
            foreach ($sheets as $s) {
                if (mb_strtolower($s['name']) === mb_strtolower($wanted)) {
                    return $s;
                }
            }
            throw new DataImportException('There is no sheet called "'.mb_substr($wanted, 0, 60).'".', 'missing_sheet');
        }
        foreach ($sheets as $s) {
            if (! $s['hidden']) {
                return $s;
            }
        }

        return $sheets[0];
    }

    /** @return list<string> */
    private static function sharedStrings(ZipArchive $zip): array
    {
        $xml = self::part($zip, 'xl/sharedStrings.xml');
        if ($xml === null) {
            return [];
        }
        $out = [];
        $r = self::reader($xml);
        $current = null;
        $inRph = false;
        while (@$r->read()) {
            if ($r->nodeType === XMLReader::ELEMENT) {
                if ($r->localName === 'si' && ! $r->isEmptyElement) {
                    $current = '';
                } elseif ($r->localName === 'si') {
                    $out[] = '';
                } elseif ($r->localName === 'rPh') {
                    $inRph = true;
                } elseif ($r->localName === 't' && $current !== null && ! $inRph && ! $r->isEmptyElement) {
                    $current .= $r->readString();
                }
            } elseif ($r->nodeType === XMLReader::END_ELEMENT) {
                if ($r->localName === 'rPh') {
                    $inRph = false;
                } elseif ($r->localName === 'si' && $current !== null) {
                    $out[] = $current;
                    $current = null;
                    if (count($out) >= 300000) {
                        break;
                    }
                }
            }
        }
        $r->close();

        return $out;
    }

    /** @return array<int, true> cell style index => is a date format */
    private static function dateStyles(ZipArchive $zip): array
    {
        $xml = self::part($zip, 'xl/styles.xml');
        if ($xml === null) {
            return [];
        }
        $custom = [];
        $xfs = [];
        $r = self::reader($xml);
        $inCellXfs = false;
        while (@$r->read()) {
            if ($r->nodeType === XMLReader::ELEMENT) {
                if ($r->localName === 'numFmt') {
                    $custom[(int) $r->getAttribute('numFmtId')] = (string) $r->getAttribute('formatCode');
                } elseif ($r->localName === 'cellXfs') {
                    $inCellXfs = true;
                } elseif ($r->localName === 'xf' && $inCellXfs) {
                    $xfs[] = (int) $r->getAttribute('numFmtId');
                }
            } elseif ($r->nodeType === XMLReader::END_ELEMENT && $r->localName === 'cellXfs') {
                $inCellXfs = false;
            }
        }
        $r->close();

        $dates = [];
        foreach ($xfs as $i => $id) {
            if (in_array($id, self::BUILTIN_DATE_FORMATS, true)) {
                $dates[$i] = true;
            } elseif (isset($custom[$id])) {
                // Strip quoted text and [colour] sections, then look for date or time tokens.
                $code = preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', $custom[$id]) ?? '';
                if (preg_match('/[ymdhs]/i', $code) && ! preg_match('/^(general|0|#)/i', $code)) {
                    $dates[$i] = true;
                }
            }
        }

        return $dates;
    }

    /**
     * @param  list<string>  $shared
     * @param  array<int, true>  $dateStyles
     * @return array{0: list<list<string>>, 1: bool, 2: bool}
     */
    private static function cells(string $xml, array $shared, array $dateStyles, bool $date1904, int $maxRows, int $maxCols): array
    {
        $r = self::reader($xml);
        $rows = [];
        $width = 0;
        $truncRows = false;
        $truncCols = false;
        $rowCells = null;
        $rowIndex = 0;

        $cell = null;
        $inIs = false;
        while (@$r->read()) {
            if ($r->nodeType === XMLReader::ELEMENT) {
                if ($inIs) {
                    if ($r->localName === 't' && ! $r->isEmptyElement && $cell !== null) {
                        $cell['is'] = ($cell['is'] ?? '').$r->readString();
                    }

                    continue;
                }
                if ($r->localName === 'row') {
                    $rowIndex = $r->getAttribute('r') !== null ? (int) $r->getAttribute('r') : $rowIndex + 1;
                    $rowCells = [];
                    if ($r->isEmptyElement) {
                        $rowCells = null;
                    }
                } elseif ($r->localName === 'c' && $rowCells !== null) {
                    $ref = (string) $r->getAttribute('r');
                    $col = $ref !== '' ? self::columnIndex($ref) : count($rowCells);
                    $cell = [
                        'col' => $col,
                        't' => (string) $r->getAttribute('t'),
                        's' => (int) $r->getAttribute('s'),
                        'v' => null,
                        'is' => null,
                    ];
                    if ($r->isEmptyElement) {
                        $cell = null;
                    }
                } elseif ($cell !== null && $r->localName === 'v' && ! $r->isEmptyElement) {
                    $cell['v'] = $r->readString();
                } elseif ($cell !== null && $r->localName === 'is' && ! $r->isEmptyElement) {
                    $inIs = true;
                    $cell['is'] = '';
                }
            } elseif ($r->nodeType === XMLReader::END_ELEMENT) {
                if ($r->localName === 'is') {
                    $inIs = false;
                } elseif ($r->localName === 'c' && $cell !== null) {
                    if ($cell['col'] >= $maxCols) {
                        $truncCols = true;
                    } else {
                        $rowCells[$cell['col']] = self::cellValue($cell, $shared, $dateStyles, $date1904);
                    }
                    $cell = null;
                } elseif ($r->localName === 'row' && $rowCells !== null) {
                    if ($rowCells !== []) {
                        if (count($rows) >= $maxRows) {
                            $truncRows = true;
                            break;
                        }
                        $max = max(array_keys($rowCells));
                        $line = array_fill(0, $max + 1, '');
                        foreach ($rowCells as $c => $v) {
                            $line[$c] = $v;
                        }
                        $width = max($width, count($line));
                        $rows[] = $line;
                    }
                    $rowCells = null;
                }
            }
        }
        $r->close();

        // Drop trailing empty rows and make the grid rectangular.
        while ($rows !== [] && trim(implode('', end($rows))) === '') {
            array_pop($rows);
        }
        foreach ($rows as $i => $line) {
            $rows[$i] = array_pad($line, $width, '');
        }
        // Drop trailing empty columns.
        $lastCol = -1;
        foreach ($rows as $line) {
            foreach ($line as $c => $v) {
                if ($v !== '' && $c > $lastCol) {
                    $lastCol = $c;
                }
            }
        }
        if ($lastCol + 1 < $width) {
            foreach ($rows as $i => $line) {
                $rows[$i] = array_slice($line, 0, max($lastCol + 1, 0));
            }
        }

        return [$rows, $truncRows, $truncCols];
    }

    private static function columnIndex(string $ref): int
    {
        $n = 0;
        $len = strlen($ref);
        for ($i = 0; $i < $len; $i++) {
            $c = ord($ref[$i]);
            if ($c >= 65 && $c <= 90) {
                $n = $n * 26 + ($c - 64);
            } elseif ($c >= 97 && $c <= 122) {
                $n = $n * 26 + ($c - 96);
            } else {
                break;
            }
        }

        return max($n - 1, 0);
    }

    /**
     * @param  array{col: int, t: string, s: int, v: ?string, is: ?string}  $cell
     * @param  list<string>  $shared
     * @param  array<int, true>  $dateStyles
     */
    private static function cellValue(array $cell, array $shared, array $dateStyles, bool $date1904): string
    {
        $v = $cell['v'];

        switch ($cell['t']) {
            case 's':
                return $v !== null ? ($shared[(int) $v] ?? '') : '';
            case 'inlineStr':
                return $cell['is'] ?? ($v ?? '');
            case 'b':
                return $v === '1' ? 'TRUE' : 'FALSE';
            case 'str':
            case 'e':
                return $v ?? '';
        }

        if ($v === null || $v === '') {
            return '';
        }
        if (isset($dateStyles[$cell['s']]) && is_numeric($v)) {
            return self::excelDate((float) $v, $date1904) ?? $v;
        }
        if (is_numeric($v)) {
            // Excel stores doubles with 15 to 17 digits; 0.1 + 0.2 style noise is rounded away.
            $f = (float) $v;
            if (floor($f) === $f && abs($f) < 1e15) {
                return (string) (int) $f;
            }

            return rtrim(rtrim(sprintf('%.12F', round($f, 12)), '0'), '.');
        }

        return $v;
    }

    private static function excelDate(float $serial, bool $date1904): ?string
    {
        if ($serial < 0 || $serial > 2958465) {
            return null;
        }
        $days = (int) floor($serial);
        $frac = $serial - $days;
        if ($date1904) {
            $base = new DateTimeImmutable('1904-01-01', new DateTimeZone('UTC'));
        } else {
            // 1900 is treated as a leap year by Excel; serials above 60 are one day too high.
            $base = new DateTimeImmutable('1899-12-30', new DateTimeZone('UTC'));
            if ($days < 61) {
                $base = new DateTimeImmutable('1899-12-31', new DateTimeZone('UTC'));
            }
        }
        $date = $base->add(new DateInterval('P'.$days.'D'));
        if ($frac > 0.000001) {
            $seconds = (int) round($frac * 86400);
            $date = $date->add(new DateInterval('PT'.$seconds.'S'));

            return $date->format('Y-m-d H:i');
        }

        return $date->format('Y-m-d');
    }
}
