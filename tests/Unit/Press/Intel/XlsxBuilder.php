<?php

namespace Tests\Unit\Press\Intel;

use ZipArchive;

/** Writes small but real .xlsx files for the importer tests. */
final class XlsxBuilder
{
    /**
     * @param  array<string, list<list<mixed>>>  $sheets  sheet name => rows; a cell is a scalar, or ['s' => string] for a shared string, ['d' => serial] for a date, ['f' => 'SUM(A1)', 'v' => 3] for a formula
     * @param  array{hidden?: list<string>, date1904?: bool, extra?: array<string, string>}  $opts
     */
    public static function make(string $path, array $sheets, array $opts = []): string
    {
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $shared = [];
        $sheetXml = [];
        $n = 0;
        foreach ($sheets as $name => $rows) {
            $n++;
            $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
            foreach ($rows as $r => $row) {
                $xml .= '<row r="'.($r + 1).'">';
                foreach ($row as $c => $cell) {
                    if ($cell === null || $cell === '') {
                        continue;
                    }
                    $ref = self::col($c).($r + 1);
                    if (is_array($cell) && isset($cell['s'])) {
                        $idx = array_search($cell['s'], $shared, true);
                        if ($idx === false) {
                            $shared[] = $cell['s'];
                            $idx = count($shared) - 1;
                        }
                        $xml .= '<c r="'.$ref.'" t="s"><v>'.$idx.'</v></c>';
                    } elseif (is_array($cell) && isset($cell['d'])) {
                        $xml .= '<c r="'.$ref.'" s="1"><v>'.$cell['d'].'</v></c>';
                    } elseif (is_array($cell) && isset($cell['f'])) {
                        $xml .= '<c r="'.$ref.'"><f>'.htmlspecialchars($cell['f']).'</f><v>'.$cell['v'].'</v></c>';
                    } elseif (is_array($cell) && isset($cell['inline'])) {
                        $xml .= '<c r="'.$ref.'" t="inlineStr"><is><t>'.htmlspecialchars($cell['inline']).'</t></is></c>';
                    } elseif (is_bool($cell)) {
                        $xml .= '<c r="'.$ref.'" t="b"><v>'.($cell ? 1 : 0).'</v></c>';
                    } elseif (is_string($cell)) {
                        $xml .= '<c r="'.$ref.'" t="str"><v>'.htmlspecialchars($cell).'</v></c>';
                    } else {
                        $xml .= '<c r="'.$ref.'"><v>'.$cell.'</v></c>';
                    }
                }
                $xml .= '</row>';
            }
            $xml .= '</sheetData></worksheet>';
            $sheetXml[$n] = $xml;
        }

        $wb = '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .(! empty($opts['date1904']) ? '<workbookPr date1904="1"/>' : '').'<sheets>';
        $rels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $i = 0;
        foreach (array_keys($sheets) as $name) {
            $i++;
            $state = in_array($name, $opts['hidden'] ?? [], true) ? ' state="hidden"' : '';
            $wb .= '<sheet name="'.htmlspecialchars($name).'" sheetId="'.$i.'"'.$state.' r:id="rId'.$i.'"/>';
            $rels .= '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
            $zip->addFromString('xl/worksheets/sheet'.$i.'.xml', $sheetXml[$i]);
        }
        $wb .= '</sheets></workbook>';
        $rels .= '</Relationships>';

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/></Types>');
        $zip->addFromString('xl/workbook.xml', $wb);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
        $ss = '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        foreach ($shared as $s) {
            $ss .= '<si><t xml:space="preserve">'.htmlspecialchars($s).'</t></si>';
        }
        $zip->addFromString('xl/sharedStrings.xml', $ss.'</sst>');
        // style 0 general, style 1 date (builtin 14), style 2 custom yyyy-mm-dd
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="yyyy\-mm\-dd"/></numFmts><cellXfs count="3"><xf numFmtId="0"/><xf numFmtId="14"/><xf numFmtId="164"/></cellXfs></styleSheet>');
        foreach ($opts['extra'] ?? [] as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    private static function col(int $i): string
    {
        $s = '';
        $i++;
        while ($i > 0) {
            $m = ($i - 1) % 26;
            $s = chr(65 + $m).$s;
            $i = intdiv($i - $m - 1, 26);
        }

        return $s;
    }
}
