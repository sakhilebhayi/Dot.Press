<?php

namespace App\Services\Press\Export\Pdf;

use RuntimeException;

/**
 * Joins PDF files written by DomPDF (classic cross reference table, no object streams, page resources kept on the
 * Pages node) into one file. It exists so that a big deck can be drawn a few slides at a time: DomPDF keeps everything
 * it draws until it writes the file (about 3.4 MB of PHP memory for each dense slide), so one pass over 100 dense slides
 * needs more than 340 MB, while chunks of ten need about 45 MB at a time.
 *
 * It is deliberately not a general PDF merger. It reads the objects by the offsets in the cross reference table,
 * renumbers them, hangs every page under one new Pages node and gives each page the resources its old Pages node held.
 * Anything it does not recognise throws, and the caller reports a plain error rather than shipping a damaged file.
 */
final class PdfMerger
{
    /**
     * @param  list<string>  $pdfs  complete PDF files as strings, in page order
     */
    public static function merge(array $pdfs): string
    {
        if ($pdfs === []) {
            throw new RuntimeException('There is nothing to merge.');
        }
        if (count($pdfs) === 1) {
            return $pdfs[0];
        }

        $paths = [];
        $out = (string) tempnam(sys_get_temp_dir(), 'pdfm');
        try {
            foreach ($pdfs as $pdf) {
                $paths[] = $tmp = (string) tempnam(sys_get_temp_dir(), 'pdfp');
                file_put_contents($tmp, $pdf);
            }
            self::mergeFiles($paths, $out);

            return (string) file_get_contents($out);
        } finally {
            foreach ([...$paths, $out] as $f) {
                @unlink($f);
            }
        }
    }

    /**
     * Joins PDF files on disk into $outPath, one part in memory at a time. Memory stays at the size of the largest part
     * however many there are, which is the point of drawing a big deck in parts.
     *
     * @param  list<string>  $paths  PDF files, in page order
     */
    public static function mergeFiles(array $paths, string $outPath): void
    {
        if ($paths === []) {
            throw new RuntimeException('There is nothing to merge.');
        }
        $handle = fopen($outPath, 'wb');
        if ($handle === false) {
            throw new RuntimeException('The PDF file could not be written.');
        }

        $header = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        fwrite($handle, $header);
        $position = strlen($header);
        $offsets = [];
        $kids = [];
        $info = null;
        $next = 3; // object 1 is the catalog, 2 the page tree
        $emit = function (int $number, string $body) use ($handle, &$position, &$offsets): void {
            $offsets[$number] = $position;
            $text = $number." 0 obj\n".$body."\nendobj\n";
            fwrite($handle, $text);
            $position += strlen($text);
        };

        foreach ($paths as $k => $path) {
            $pdf = (string) file_get_contents($path);
            $objects = self::objects($pdf);
            $trailer = self::trailer($pdf);
            $root = self::dict($objects[$trailer['root']] ?? '');
            $pagesNumber = self::ref($root, 'Pages');
            $pagesObject = self::dict($objects[$pagesNumber] ?? '');
            $pageNumbers = self::refs(self::value($pagesObject, 'Kids') ?? '');
            $resources = self::value($pagesObject, 'Resources');
            if ($pageNumbers === [] || $resources === null) {
                throw new RuntimeException('A part of the PDF has an unexpected structure.');
            }
            $base = $next - 1;
            $drop = [$trailer['root'] => true, $pagesNumber => true];
            if (($outline = self::ref($root, 'Outlines')) !== null) {
                $drop[$outline] = true;
            }
            $shift = fn (string $text): string => (string) preg_replace_callback('/(\d+) 0 R\b/', fn (array $m): string => ((int) $m[1] + $base).' 0 R', $text);

            ksort($objects);
            foreach ($objects as $number => $raw) {
                $new = $number + $base;
                if (isset($drop[$number])) {
                    $emit($new, 'null');

                    continue;
                }
                [$dict, $stream] = self::split(self::strip($raw));
                $dict = $shift($dict);
                if (in_array($number, $pageNumbers, true)) {
                    $dict = (string) preg_replace('/\/Parent\s+\d+ 0 R/', '/Parent 2 0 R', $dict);
                    $dict = preg_replace('/>>\s*$/', ' /Resources '.$shift($resources).' >>', rtrim($dict), 1);
                    $kids[] = $new.' 0 R';
                }
                $emit($new, $dict.$stream);
            }
            if ($k === 0 && $trailer['info'] !== null) {
                $info = $trailer['info'] + $base;
            }
            $next = $base + max(array_keys($objects)) + 1;
            unset($pdf, $objects);
        }

        $emit(1, '<< /Type /Catalog /Pages 2 0 R >>');
        $emit(2, '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>');

        $xref = $position;
        $size = $next;
        $table = "xref\n0 {$size}\n0000000000 65535 f \n";
        for ($n = 1; $n < $size; $n++) {
            $table .= isset($offsets[$n]) ? sprintf("%010d 00000 n \n", $offsets[$n]) : "0000000000 65535 f \n";
        }
        $table .= "trailer\n<< /Size {$size} /Root 1 0 R".($info !== null ? " /Info {$info} 0 R" : '')." >>\nstartxref\n{$xref}\n%%EOF\n";
        fwrite($handle, $table);
        if (! fclose($handle)) {
            throw new RuntimeException('The PDF file could not be written.');
        }
    }

    /**
     * Object number => the text of the object (without "N 0 obj" and "endobj"), cut at the offsets of the xref table.
     *
     * @return array<int, string>
     */
    private static function objects(string $pdf): array
    {
        if (! preg_match('/startxref\s+(\d+)\s+%%EOF\s*$/', $pdf, $m)) {
            throw new RuntimeException('A part of the PDF has no cross reference table.');
        }
        $xrefAt = (int) $m[1];
        if (! preg_match('/\Gxref\s+(\d+) (\d+)\s/', $pdf, $h, 0, $xrefAt)) {
            throw new RuntimeException('A part of the PDF uses a cross reference format that is not supported.');
        }
        $first = (int) $h[1];
        $count = (int) $h[2];
        $pos = $xrefAt + strlen($h[0]);
        $offsets = [];
        for ($i = 0; $i < $count; $i++) {
            $entry = substr($pdf, $pos + $i * 20, 20);
            if (strlen($entry) < 18 || ! preg_match('/^(\d{10}) (\d{5}) ([nf])/', $entry, $e)) {
                throw new RuntimeException('A part of the PDF has a damaged cross reference table.');
            }
            if ($e[3] === 'n') {
                $offsets[$first + $i] = (int) $e[1];
            }
        }
        $ends = array_values($offsets);
        sort($ends);
        $ends[] = $xrefAt;
        $objects = [];
        foreach ($offsets as $number => $at) {
            $next = $xrefAt;
            foreach ($ends as $end) {
                if ($end > $at) {
                    $next = $end;
                    break;
                }
            }
            $objects[$number] = substr($pdf, $at, $next - $at);
        }

        return $objects;
    }

    /** @return array{root: int, info: int|null} */
    private static function trailer(string $pdf): array
    {
        $at = strrpos($pdf, 'trailer');
        $text = $at === false ? '' : substr($pdf, $at, 1000);
        if (! preg_match('/\/Root\s+(\d+) 0 R/', $text, $r)) {
            throw new RuntimeException('A part of the PDF has no document root.');
        }

        return ['root' => (int) $r[1], 'info' => preg_match('/\/Info\s+(\d+) 0 R/', $text, $i) ? (int) $i[1] : null];
    }

    /** "N 0 obj\n<body>endobj\n" => "<body>" */
    private static function strip(string $raw): string
    {
        $raw = (string) preg_replace('/^\s*\d+ 0 obj\s*/', '', $raw, 1);
        $end = strrpos($raw, 'endobj');

        return rtrim($end === false ? $raw : substr($raw, 0, $end), "\r\n");
    }

    /** Separates a dictionary (or other plain value) from the stream that may follow it. @return array{0: string, 1: string} */
    private static function split(string $body): array
    {
        if (preg_match('/>>\s*stream\r?\n/', $body, $m, PREG_OFFSET_CAPTURE)) {
            $at = $m[0][1] + 2;

            return [substr($body, 0, $at), substr($body, $at)];
        }

        return [$body, ''];
    }

    private static function dict(string $raw): string
    {
        return self::strip($raw);
    }

    /** The value after /Key in a dictionary text: a balanced << >> or [ ] group, or a single token. */
    private static function value(string $dict, string $key): ?string
    {
        if (! preg_match('/\/'.preg_quote($key, '/').'\s*/', $dict, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $start = $m[0][1] + strlen($m[0][0]);
        $open = $dict[$start] ?? '';
        if ($open === '<' && ($dict[$start + 1] ?? '') === '<') {
            $depth = 0;
            for ($i = $start; $i < strlen($dict) - 1; $i++) {
                $two = $dict[$i].$dict[$i + 1];
                if ($two === '<<') {
                    $depth++;
                    $i++;
                } elseif ($two === '>>') {
                    $depth--;
                    $i++;
                    if ($depth === 0) {
                        return substr($dict, $start, $i - $start + 1);
                    }
                }
            }

            return null;
        }
        if ($open === '[') {
            $end = strpos($dict, ']', $start);

            return $end === false ? null : substr($dict, $start, $end - $start + 1);
        }

        return preg_match('/\G[^\s\/<>\[\]]+(?: \d+ R)?/', $dict, $t, 0, $start) ? $t[0] : null;
    }

    private static function ref(string $dict, string $key): ?int
    {
        $v = self::value($dict, $key);

        return $v !== null && preg_match('/^(\d+) 0 R$/', $v, $m) ? (int) $m[1] : null;
    }

    /** @return list<int> */
    private static function refs(string $array): array
    {
        preg_match_all('/(\d+) 0 R/', $array, $m);

        return array_map('intval', $m[1]);
    }
}
