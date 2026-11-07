<?php

namespace Tests\Unit\Press\Intel;

use App\Services\Press\Data\DataImportException;
use App\Services\Press\Data\DataParser;
use App\Services\Press\Data\Delimited;
use App\Services\Press\Data\XlsxReader;
use Tests\TestCase;
use ZipArchive;

class DataImportTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/press-data-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function path(string $name): string
    {
        return $this->dir.'/'.$name;
    }

    /** A file of $bytes copies of $char, written in chunks so the test itself stays small in memory. */
    private function bigFile(string $name, int $bytes, string $char): string
    {
        $path = $this->path($name);
        $fp = fopen($path, 'wb');
        $chunk = str_repeat($char, 1024 * 1024);
        for ($i = 0; $i < intdiv($bytes, 1024 * 1024); $i++) {
            fwrite($fp, $chunk);
        }
        fclose($fp);

        return $path;
    }

    private function refused(callable $fn, string $reason): void
    {
        try {
            $fn();
            $this->fail('expected a DataImportException ('.$reason.')');
        } catch (DataImportException $e) {
            $this->assertSame($reason, $e->reason, $e->getMessage());
        }
    }

    // ---------------------------------------------------------------- xlsx

    public function test_reads_strings_numbers_booleans_inline_strings_and_formula_values(): void
    {
        $p = XlsxBuilder::make($this->path('a.xlsx'), ['Data' => [
            [['s' => 'Quarter'], ['s' => 'Tonnes'], ['s' => 'Done']],
            [['s' => 'Q1'], 1050, true],
            [['s' => 'Q2'], 1110.5, false],
            [['inline' => 'Q3 & more'], ['f' => 'B2+B3', 'v' => 2160.5], null],
        ]]);
        $r = XlsxReader::read($p);
        $this->assertSame('Data', $r['sheet']);
        $this->assertSame([['Quarter', 'Tonnes', 'Done'], ['Q1', '1050', 'TRUE'], ['Q2', '1110.5', 'FALSE'], ['Q3 & more', '2160.5', '']], $r['rows']);
        $this->assertFalse($r['truncated_rows']);
        $this->assertSame([['name' => 'Data', 'hidden' => false]], $r['sheets']);
    }

    public function test_picks_the_first_visible_sheet_or_the_named_one(): void
    {
        $p = XlsxBuilder::make($this->path('b.xlsx'), ['Hidden' => [[['s' => 'secret']]], 'Visible' => [[['s' => 'shown']]], 'Other' => [[['s' => 'other']]]], ['hidden' => ['Hidden']]);
        $this->assertSame('Visible', XlsxReader::read($p)['sheet']);
        $this->assertSame([['other']], XlsxReader::read($p, 'Other')['rows']);
        $this->assertSame([['other']], XlsxReader::read($p, 'other')['rows'], 'case insensitive');
        $this->assertSame([true, false, false], array_column(XlsxReader::read($p)['sheets'], 'hidden'));
        $this->refused(fn () => XlsxReader::read($p, 'Nope'), 'missing_sheet');
    }

    public function test_sparse_cells_keep_their_column(): void
    {
        $p = XlsxBuilder::make($this->path('c.xlsx'), ['S' => [[['s' => 'A'], null, null, ['s' => 'D']], [null, 5]]]);
        $this->assertSame([['A', '', '', 'D'], ['', '5', '', '']], XlsxReader::read($p)['rows']);
    }

    public function test_dates_become_iso_strings(): void
    {
        $p = XlsxBuilder::make($this->path('d.xlsx'), ['S' => [[['d' => 45292], ['d' => 45292.5], ['d' => 61], 45292]]]);
        $row = XlsxReader::read($p)['rows'][0];
        $this->assertSame('2024-01-01', $row[0]);
        $this->assertSame('2024-01-01 12:00', $row[1]);
        $this->assertSame('1900-03-01', $row[2]);
        $this->assertSame('45292', $row[3], 'a plain number in a general cell stays a number');
        $p1904 = XlsxBuilder::make($this->path('e.xlsx'), ['S' => [[['d' => 43830]]]], ['date1904' => true]);
        $this->assertSame('2024-01-01', XlsxReader::read($p1904)['rows'][0][0]);
    }

    public function test_row_and_column_caps_are_applied_and_reported(): void
    {
        $rows = [];
        for ($i = 0; $i < 30; $i++) {
            $rows[] = array_map(fn ($c) => $i * 100 + $c, range(0, 14));
        }
        $p = XlsxBuilder::make($this->path('f.xlsx'), ['S' => $rows]);
        $r = XlsxReader::read($p, null, 10, 5);
        $this->assertCount(10, $r['rows']);
        $this->assertCount(5, $r['rows'][0]);
        $this->assertTrue($r['truncated_rows']);
        $this->assertTrue($r['truncated_cols']);
        $full = XlsxReader::read($p);
        $this->assertCount(30, $full['rows']);
        $this->assertFalse($full['truncated_rows']);
    }

    public function test_the_default_caps_are_5000_rows_and_100_columns(): void
    {
        $rows = array_map(fn ($i) => [$i + 1], range(0, 5099));
        $p = XlsxBuilder::make($this->path('g.xlsx'), ['S' => $rows]);
        $r = XlsxReader::read($p);
        $this->assertCount(5000, $r['rows']);
        $this->assertTrue($r['truncated_rows']);
        $wide = XlsxBuilder::make($this->path('h.xlsx'), ['S' => [range(1, 130)]]);
        $r = XlsxReader::read($wide);
        $this->assertCount(100, $r['rows'][0]);
        $this->assertTrue($r['truncated_cols']);
    }

    public function test_a_file_over_the_size_cap_is_refused_before_it_is_opened(): void
    {
        $p = XlsxBuilder::make($this->path('i.xlsx'), ['S' => [[1]]]);
        $this->refused(fn () => XlsxReader::read($p, null, 5000, 100, 100), 'too_large');
    }

    public function test_a_zip_bomb_is_refused(): void
    {
        $zip = new ZipArchive;
        $p = $this->path('bomb.xlsx');
        $zip->open($p, ZipArchive::CREATE);
        $zip->addFromString('xl/workbook.xml', '<workbook/>');
        // 45 MB of zeros compresses to a few tens of KB: a classic bomb.
        $zip->addFile($this->bigFile('zeros.bin', 45 * 1024 * 1024, "\0"), 'xl/worksheets/sheet1.xml');
        $zip->close();
        $this->assertLessThan(5 * 1024 * 1024, filesize($p));
        $this->refused(fn () => XlsxReader::read($p), 'zip_bomb');
    }

    public function test_a_suspiciously_compressed_part_and_too_many_parts_are_refused(): void
    {
        $zip = new ZipArchive;
        $p = $this->path('ratio.xlsx');
        $zip->open($p, ZipArchive::CREATE);
        $zip->addFromString('xl/workbook.xml', '<workbook/>');
        $zip->addFile($this->bigFile('a.bin', 30 * 1024 * 1024, 'a'), 'xl/sharedStrings.xml');
        $zip->close();
        $this->refused(fn () => XlsxReader::read($p), 'zip_bomb');

        $many = $this->path('many.xlsx');
        $zip = new ZipArchive;
        $zip->open($many, ZipArchive::CREATE);
        for ($i = 0; $i < 2100; $i++) {
            $zip->addFromString("junk/{$i}.txt", 'x');
        }
        $zip->close();
        $this->refused(fn () => XlsxReader::read($many), 'zip_bomb');
    }

    public function test_path_traversal_names_are_refused_and_nothing_is_written(): void
    {
        $evil = $this->dir.'/evil-target.txt';
        $p = XlsxBuilder::make($this->path('trav.xlsx'), ['S' => [[1]]]);
        $zip = new ZipArchive;
        $zip->open($p);
        $zip->addFromString('../evil-target.txt', 'pwned');
        $zip->close();
        $this->refused(fn () => XlsxReader::read($p), 'traversal');
        $this->assertFileDoesNotExist($evil);
        $this->assertFileDoesNotExist(dirname($this->dir).'/evil-target.txt');

        $abs = XlsxBuilder::make($this->path('abs.xlsx'), ['S' => [[1]]]);
        $zip = new ZipArchive;
        $zip->open($abs);
        $zip->addFromString('/etc/evil', 'x');
        $zip->close();
        $this->refused(fn () => XlsxReader::read($abs), 'traversal');
    }

    public function test_external_entities_and_doctypes_are_refused(): void
    {
        $xxe = '<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>&xxe;</t></is></c></row></sheetData></worksheet>';
        $p = XlsxBuilder::make($this->path('xxe.xlsx'), ['S' => [[1]]], ['extra' => ['xl/worksheets/sheet1.xml' => $xxe]]);
        $this->refused(fn () => XlsxReader::read($p), 'xxe');

        $billion = '<?xml version="1.0"?><!DOCTYPE lolz [<!ENTITY lol "lol"><!ENTITY lol2 "&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;">]><worksheet/>';
        $p2 = XlsxBuilder::make($this->path('lol.xlsx'), ['S' => [[1]]], ['extra' => ['xl/worksheets/sheet1.xml' => $billion]]);
        $this->refused(fn () => XlsxReader::read($p2), 'xxe');
    }

    public function test_things_that_are_not_xlsx_files_are_refused_politely(): void
    {
        file_put_contents($this->path('text.xlsx'), "a,b\n1,2");
        $this->refused(fn () => XlsxReader::read($this->path('text.xlsx')), 'not_xlsx');
        file_put_contents($this->path('ole.xlsx'), "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 100));
        $this->refused(fn () => XlsxReader::read($this->path('ole.xlsx')), 'encrypted');
        file_put_contents($this->path('broken.xlsx'), 'PK'.str_repeat("\x01", 50));
        $this->refused(fn () => XlsxReader::read($this->path('broken.xlsx')), 'not_xlsx');
        $zip = new ZipArchive;
        $zip->open($this->path('nowb.xlsx'), ZipArchive::CREATE);
        $zip->addFromString('hello.txt', 'hi');
        $zip->close();
        $this->refused(fn () => XlsxReader::read($this->path('nowb.xlsx')), 'not_xlsx');
        $this->refused(fn () => XlsxReader::read($this->path('missing.xlsx')), 'unreadable');
    }

    public function test_malformed_sheet_xml_is_an_import_error_not_a_crash(): void
    {
        $p = XlsxBuilder::make($this->path('bad.xlsx'), ['S' => [[1]]], ['extra' => ['xl/worksheets/sheet1.xml' => '<worksheet><sheetData><row r="1"><c r="A1"><v>1</v></c></row></sheetData']]);
        try {
            $r = XlsxReader::read($p);
            $this->assertIsArray($r['rows']);
        } catch (DataImportException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
    }

    public function test_unicode_and_markup_in_cells_are_read_as_text(): void
    {
        $p = XlsxBuilder::make($this->path('u.xlsx'), ['S' => [[['s' => 'Café <b>bold</b> & "quotes" 日本語'], ['s' => '=SUM(A1:A9)']]]]);
        $row = XlsxReader::read($p)['rows'][0];
        $this->assertSame('Café <b>bold</b> & "quotes" 日本語', $row[0]);
        $this->assertSame('=SUM(A1:A9)', $row[1], 'text that looks like a formula stays text');
    }

    // ---------------------------------------------------------------- delimited

    public function test_delimiters_are_sniffed(): void
    {
        $this->assertSame(',', Delimited::sniff("a,b,c\n1,2,3\n4,5,6"));
        $this->assertSame("\t", Delimited::sniff("a\tb\tc\n1\t2\t3"));
        $this->assertSame(';', Delimited::sniff("a;b;c\n1,5;2,5;3\n4;5;6"));
        $this->assertSame('|', Delimited::sniff("a|b|c\n1|2|3\n4|5|6"));
        $this->assertSame(',', Delimited::sniff("name,note\n\"Smith, J\",\"likes, commas\"\nDoe,plain"));
        $this->assertNull(Delimited::sniff("This is prose.\nIt has no table in it at all."));
        $this->assertNull(Delimited::sniff("Dear team, thanks for coming.\nSee you next week.\nBest, Sam"));
        $this->assertNull(Delimited::sniff(''));
    }

    public function test_quoted_fields_embedded_newlines_and_escaped_quotes_parse(): void
    {
        $csv = "name,quote\n\"Smith, J\",\"He said \"\"hi\"\"\"\nDoe,\"two\nlines\"\n";
        $r = Delimited::parse($csv, ',');
        $this->assertSame([['name', 'quote'], ['Smith, J', 'He said "hi"'], ['Doe', "two\nlines"]], $r['rows']);
        $this->assertSame([['a', 'b'], ['1', '']], Delimited::parse("\u{FEFF}a,b\r\n1\r\n", ',')['rows'], 'a BOM and CRLF are handled and short rows are padded');
    }

    public function test_parse_caps_rows_and_columns(): void
    {
        $csv = implode("\n", array_map(fn ($i) => implode(',', range(1, 12)), range(1, 50)));
        $r = Delimited::parse($csv, ',', 20, 5);
        $this->assertCount(20, $r['rows']);
        $this->assertCount(5, $r['rows'][0]);
        $this->assertTrue($r['truncated_rows']);
        $this->assertTrue($r['truncated_cols']);
    }

    public function test_header_and_column_types_are_guessed(): void
    {
        $d = Delimited::describe([['Quarter', 'Tonnes', 'Date'], ['Q1', '1,050', '2025-01-31'], ['Q2', '1 110', '2025-04-30'], ['Q3', '12%', '2025-07-31']]);
        $this->assertTrue($d['header']);
        $this->assertSame(['text', 'number', 'date'], $d['column_types']);
        $this->assertFalse(Delimited::describe([['1', '2'], ['3', '4']])['header']);
        $this->assertSame([], Delimited::describe([])['column_types']);
    }

    public function test_cell_numbers_handle_currency_percent_and_separators(): void
    {
        $this->assertSame(1050.0, Delimited::number('1,050'));
        $this->assertSame(1200.0, Delimited::number('1 200'));
        $this->assertSame(12.5, Delimited::number('12.5%'));
        $this->assertSame(1200.0, Delimited::number('R1,200'));
        $this->assertSame(-5.0, Delimited::number('(5)'));
        $this->assertSame(-5.0, Delimited::number('-5'));
        $this->assertSame(0.8, Delimited::number('0,8'));
        $this->assertNull(Delimited::number('abc'));
        $this->assertNull(Delimited::number(''));
        $this->assertNull(Delimited::number('1,2,3'));
    }

    // ---------------------------------------------------------------- parser

    public function test_the_parser_reads_csv_tsv_txt_and_xlsx_by_content(): void
    {
        $parser = new DataParser;
        file_put_contents($this->path('a.csv'), "Quarter,Tonnes\nQ1,1050\nQ2,1110\n");
        $r = $parser->parseFile($this->path('a.csv'), 'a.csv');
        $this->assertSame('csv', $r['format']);
        $this->assertSame(',', $r['delimiter']);
        $this->assertTrue($r['header']);
        $this->assertSame(['text', 'number'], $r['column_types']);
        $this->assertSame(3, $r['row_count']);
        $this->assertSame(2, $r['col_count']);

        file_put_contents($this->path('b.tsv'), "Quarter\tTonnes\nQ1\t1050\n");
        $this->assertSame('tsv', $parser->parseFile($this->path('b.tsv'), 'b.tsv')['format']);

        file_put_contents($this->path('c.txt'), "line one\nline two\n");
        $r = $parser->parseFile($this->path('c.txt'), 'c.txt');
        $this->assertSame('txt', $r['format']);
        $this->assertNull($r['delimiter']);
        $this->assertSame([['line one'], ['line two']], $r['rows']);

        $x = XlsxBuilder::make($this->path('d.xlsx'), ['S' => [[['s' => 'A'], ['s' => 'B']], [['s' => 'x'], 4]]]);
        $r = $parser->parseFile($x, 'whatever.csv');
        $this->assertSame('xlsx', $r['format'], 'the content decides, not the extension');
        $this->assertSame('S', $r['sheet']);
        $this->assertTrue($r['header']);
    }

    public function test_the_parser_handles_encodings_and_refuses_binary(): void
    {
        $parser = new DataParser;
        file_put_contents($this->path('w.csv'), "Name,City\nZo\xEB,Z\xFCrich\n");
        $r = $parser->parseFile($this->path('w.csv'), 'w.csv');
        $this->assertSame([['Name', 'City'], ['Zoë', 'Zürich']], $r['rows']);
        file_put_contents($this->path('u16.csv'), mb_convert_encoding("\u{FEFF}a,b\n1,2\n", 'UTF-16LE', 'UTF-8'));
        $this->assertSame([['a', 'b'], ['1', '2']], $parser->parseFile($this->path('u16.csv'), 'u16.csv')['rows']);
        file_put_contents($this->path('bin.csv'), "a,b\n\x00\x01\x02,\x03");
        $this->refused(fn () => $parser->parseFile($this->path('bin.csv'), 'bin.csv'), 'not_text');
    }

    public function test_the_parser_enforces_the_configured_caps(): void
    {
        config()->set('press-ai.data.max_bytes', 200);
        config()->set('press-ai.data.max_rows', 3);
        config()->set('press-ai.data.max_cols', 2);
        $parser = new DataParser;
        file_put_contents($this->path('s.csv'), "a,b,c\n1,2,3\n4,5,6\n7,8,9\n");
        $r = $parser->parseFile($this->path('s.csv'), 's.csv');
        $this->assertCount(3, $r['rows']);
        $this->assertCount(2, $r['rows'][0]);
        $this->assertTrue($r['truncated']['rows']);
        $this->assertTrue($r['truncated']['cols']);
        file_put_contents($this->path('big.csv'), str_repeat("a,b\n", 100));
        $this->refused(fn () => $parser->parseFile($this->path('big.csv'), 'big.csv'), 'too_large');
    }

    public function test_formula_looking_cells_are_returned_as_inert_text(): void
    {
        file_put_contents($this->path('f.csv'), "name,cmd\nx,\"=HYPERLINK(\"\"http://evil\"\",\"\"click\"\")\"\ny,@SUM(1)\n");
        $r = (new DataParser)->parseFile($this->path('f.csv'), 'f.csv');
        $this->assertSame('=HYPERLINK("http://evil","click")', $r['rows'][1][1]);
    }
}
