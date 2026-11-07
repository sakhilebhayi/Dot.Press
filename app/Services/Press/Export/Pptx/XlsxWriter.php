<?php

namespace App\Services\Press\Export\Pptx;

use RuntimeException;
use ZipArchive;

/**
 * A minimal single sheet XLSX writer: strings (shared), numbers and blanks. It is what sits behind a native chart so
 * "Edit data" in PowerPoint opens a real workbook.
 */
final class XlsxWriter
{
    /**
     * @param  list<list<string|int|float|null>>  $rows
     * @return string the .xlsx bytes
     */
    public static function build(array $rows, string $sheetName = 'Sheet1'): string
    {
        $strings = [];
        $index = [];
        $sheet = '';
        foreach ($rows as $r => $row) {
            $sheet .= '<row r="'.($r + 1).'">';
            foreach ($row as $c => $v) {
                $ref = self::col($c).($r + 1);
                if ($v === null || $v === '') {
                    continue;
                }
                if (is_int($v) || is_float($v)) {
                    if (is_float($v) && (is_nan($v) || is_infinite($v))) {
                        continue;
                    }
                    $sheet .= '<c r="'.$ref.'"><v>'.self::num($v).'</v></c>';
                } else {
                    $s = (string) $v;
                    if (! isset($index[$s])) {
                        $index[$s] = count($strings);
                        $strings[] = $s;
                    }
                    $sheet .= '<c r="'.$ref.'" t="s"><v>'.$index[$s].'</v></c>';
                }
            }
            $sheet .= '</row>';
        }
        $sheetXml = Ooxml::head().'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="'.Ooxml::NS_R.'"><sheetData>'.$sheet.'</sheetData></worksheet>';
        $sst = Ooxml::head().'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($strings).'" uniqueCount="'.count($strings).'">';
        foreach ($strings as $s) {
            $sst .= '<si><t xml:space="preserve">'.Ooxml::esc($s).'</t></si>';
        }
        $sst .= '</sst>';
        $name = Ooxml::esc(preg_replace('/[\[\]:*?\/\\\\]/', '', $sheetName) ?: 'Sheet1');

        $parts = [
            '[Content_Types].xml' => Ooxml::head().'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
            '_rels/.rels' => Ooxml::head().'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => Ooxml::head().'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="'.Ooxml::NS_R.'"><sheets><sheet name="'.$name.'" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => Ooxml::head().'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'xl/worksheets/sheet1.xml' => $sheetXml,
            'xl/sharedStrings.xml' => $sst,
            'xl/styles.xml' => Ooxml::head().'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs></styleSheet>',
        ];
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($tmp === false) {
            throw new RuntimeException('No temporary file for the chart workbook.');
        }
        try {
            $zip = new ZipArchive;
            if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Cannot create the chart workbook.');
            }
            foreach ($parts as $path => $bytes) {
                $zip->addFromString($path, $bytes);
            }
            $zip->close();

            return (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /** Column letters for a zero based index. */
    public static function col(int $i): string
    {
        $s = '';
        $i++;
        while ($i > 0) {
            $i--;
            $s = chr(65 + $i % 26).$s;
            $i = intdiv($i, 26);
        }

        return $s;
    }

    private static function num(int|float $v): string
    {
        if (is_int($v)) {
            return (string) $v;
        }

        return rtrim(rtrim(sprintf('%.10F', $v), '0'), '.') ?: '0';
    }
}
