<?php

namespace App\Services\Press\Import;

use ZipArchive;

/**
 * Reads a PPTX package defensively: size and entry limits, no path traversal, no zip bombs, and no trust in the sizes the
 * archive declares (every read is capped on the bytes actually produced).
 */
final class ZipGuard
{
    private ZipArchive $zip;

    /** @var array<string, array{index: int, size: int, comp: int}> */
    private array $entries = [];

    private int $totalRead = 0;

    /** @param array{max_bytes: int, max_entries: int, max_entry_bytes: int, max_xml_bytes: int, max_total_bytes: int, max_ratio: int} $limits */
    public function __construct(string $path, private array $limits)
    {
        $size = @filesize($path);
        if ($size === false || $size <= 0) {
            throw new ImportException('The uploaded file is empty or unreadable.');
        }
        if ($size > $limits['max_bytes']) {
            throw new ImportException('The file is larger than '.(int) ($limits['max_bytes'] / 1048576).' MB, which is the most that can be imported.');
        }
        $head = @file_get_contents($path, false, null, 0, 4);
        if ($head === false || ! str_starts_with($head, "PK\x03\x04")) {
            throw new ImportException('This is not a PowerPoint (.pptx) file.');
        }
        $this->zip = new ZipArchive;
        $res = $this->zip->open($path, ZipArchive::RDONLY);
        if ($res !== true) {
            throw new ImportException('The file is damaged and cannot be opened as a .pptx.');
        }
        $n = $this->zip->numFiles;
        if ($n > $limits['max_entries']) {
            throw new ImportException('The file has too many parts ('.$n.') to import safely.');
        }
        $declaredTotal = 0;
        for ($i = 0; $i < $n; $i++) {
            $st = $this->zip->statIndex($i);
            if ($st === false) {
                throw new ImportException('The file is damaged: a part cannot be read.');
            }
            $name = (string) $st['name'];
            if (! self::safeName($name)) {
                throw new ImportException('The file contains an unsafe path ("'.mb_substr(preg_replace('/[^\x20-\x7E]/', '?', $name) ?? '', 0, 60).'") and was refused.');
            }
            if (str_ends_with($name, '/')) {
                continue;
            }
            $declaredTotal += (int) $st['size'];
            if ($st['size'] > $limits['max_entry_bytes']) {
                throw new ImportException('A part of the file is larger than '.(int) ($limits['max_entry_bytes'] / 1048576).' MB uncompressed and was refused.');
            }
            // A tiny compressed entry that expands enormously is a zip bomb.
            if ($st['size'] > 1048576 && $st['comp_size'] > 0 && $st['size'] / $st['comp_size'] > $limits['max_ratio']) {
                throw new ImportException('The file looks like a compression bomb and was refused.');
            }
            if ($st['size'] > 1048576 && $st['comp_size'] === 0) {
                throw new ImportException('The file looks like a compression bomb and was refused.');
            }
            $this->entries[$name] = ['index' => $i, 'size' => (int) $st['size'], 'comp' => (int) $st['comp_size']];
        }
        if ($declaredTotal > $limits['max_total_bytes']) {
            throw new ImportException('The file expands to more than '.(int) ($limits['max_total_bytes'] / 1048576).' MB and was refused.');
        }
    }

    public function __destruct()
    {
        @$this->zip->close();
    }

    public static function safeName(string $name): bool
    {
        if ($name === '' || strlen($name) > 300 || str_contains($name, "\0") || str_contains($name, '\\')) {
            return false;
        }
        if (str_starts_with($name, '/') || preg_match('#^[A-Za-z]:#', $name)) {
            return false;
        }
        foreach (explode('/', $name) as $seg) {
            if ($seg === '..') {
                return false;
            }
        }

        return true;
    }

    public function has(string $name): bool
    {
        return isset($this->entries[$name]);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->entries);
    }

    public function size(string $name): int
    {
        return $this->entries[$name]['size'] ?? 0;
    }

    /** Read one part, never more than $cap bytes and never past the running total. Null when absent. */
    public function read(string $name, ?int $cap = null): ?string
    {
        if (! isset($this->entries[$name])) {
            return null;
        }
        $cap ??= str_ends_with($name, '.xml') || str_ends_with($name, '.rels') ? $this->limits['max_xml_bytes'] : $this->limits['max_entry_bytes'];
        $stream = $this->zip->getStream($name);
        if ($stream === false) {
            return null;
        }
        $out = '';
        while (! feof($stream)) {
            $chunk = fread($stream, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $out .= $chunk;
            if (strlen($out) > $cap) {
                fclose($stream);
                throw new ImportException('A part of the file is larger than its declared size and was refused.');
            }
        }
        fclose($stream);
        $this->totalRead += strlen($out);
        if ($this->totalRead > $this->limits['max_total_bytes']) {
            throw new ImportException('The file expands to more than '.(int) ($this->limits['max_total_bytes'] / 1048576).' MB and was refused.');
        }

        return $out;
    }

    /** Resolve a relationship target against the part that owns the relationship. */
    public static function resolve(string $ownerPart, string $target): string
    {
        if (str_starts_with($target, '/')) {
            return ltrim($target, '/');
        }
        $dir = dirname($ownerPart);
        $parts = $dir === '.' ? [] : explode('/', $dir);
        foreach (explode('/', $target) as $seg) {
            if ($seg === '..') {
                array_pop($parts);
            } elseif ($seg !== '.' && $seg !== '') {
                $parts[] = $seg;
            }
        }

        return implode('/', $parts);
    }
}
