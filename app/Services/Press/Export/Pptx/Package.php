<?php

namespace App\Services\Press\Export\Pptx;

use RuntimeException;
use ZipArchive;

/**
 * An OPC package: parts, their content types and the relationship lists. save() writes the zip.
 *
 * A part larger than SPILL_BYTES (a picture, a chart workbook, a long slide) is written to a temporary file the moment it
 * is added and handed to the zip from there, so the pictures of a big deck never sit in PHP memory together. Measured:
 * a 100 slide deck went from 76 MB to 41 MB of peak PHP memory, and the largest deck a 128 MB limit survives from 150
 * slides to the 600 slide maximum.
 */
final class Package
{
    /** Parts above this many bytes live in a temporary file until the zip is written. */
    public const SPILL_BYTES = 24 * 1024;

    /** @var array<string, string> path => bytes */
    private array $parts = [];

    /** @var array<string, string> path => temporary file */
    private array $files = [];

    /** @var array<string, string> path => content type (overrides) */
    private array $overrides = [];

    /** @var array<string, string> extension => content type */
    private array $defaults = ['rels' => 'application/vnd.openxmlformats-package.relationships+xml', 'xml' => 'application/xml'];

    /** @var array<string, list<array{id: string, type: string, target: string, external: bool}>> source part => relationships */
    private array $rels = [];

    public function add(string $path, string $bytes, ?string $contentType = null): void
    {
        unset($this->parts[$path], $this->files[$path]);
        if (strlen($bytes) > self::SPILL_BYTES && ($tmp = tempnam(sys_get_temp_dir(), 'pxp')) !== false) {
            if (file_put_contents($tmp, $bytes) === strlen($bytes)) {
                $this->files[$path] = $tmp;
            } else {
                @unlink($tmp);
                $this->parts[$path] = $bytes;
            }
        } else {
            $this->parts[$path] = $bytes;
        }
        if ($contentType !== null) {
            $this->overrides['/'.$path] = $contentType;
        }
    }

    public function defaultType(string $ext, string $type): void
    {
        $this->defaults[$ext] = $type;
    }

    /** Add a relationship from $source (e.g. "ppt/slides/slide1.xml", or "" for the package) and return its id. */
    public function rel(string $source, string $type, string $target, bool $external = false): string
    {
        $list = $this->rels[$source] ?? [];
        foreach ($list as $r) {
            if ($r['type'] === $type && $r['target'] === $target && $r['external'] === $external) {
                return $r['id'];
            }
        }
        $id = 'rId'.(count($list) + 1);
        $this->rels[$source][] = ['id' => $id, 'type' => $type, 'target' => $target, 'external' => $external];

        return $id;
    }

    public function has(string $path): bool
    {
        return isset($this->parts[$path]) || isset($this->files[$path]);
    }

    public function __destruct()
    {
        $this->discard();
    }

    /** Remove the temporary files. Called after the zip is written, and again when the object goes. */
    public function discard(): void
    {
        foreach ($this->files as $tmp) {
            @unlink($tmp);
        }
        $this->files = [];
    }

    /** Write the zip to $file. */
    public function save(string $file): void
    {
        $zip = new ZipArchive;
        if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create the PPTX file.');
        }
        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->relsXml(''));
        foreach ($this->parts as $path => $bytes) {
            $zip->addFromString($path, $bytes);
        }
        foreach ($this->files as $path => $tmp) {
            $zip->addFile($tmp, $path);
        }
        foreach (array_keys($this->rels) as $source) {
            if ($source === '') {
                continue;
            }
            $dir = dirname($source);
            $zip->addFromString(($dir === '.' ? '' : $dir.'/').'_rels/'.basename($source).'.rels', $this->relsXml($source));
        }
        // The zip reads the spilled files when it is closed, so they are removed only afterwards.
        $closed = $zip->close();
        $this->discard();
        if (! $closed) {
            throw new RuntimeException('Cannot finish the PPTX file.');
        }
    }

    private function contentTypes(): string
    {
        $x = Ooxml::head().'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
        foreach ($this->defaults as $ext => $type) {
            $x .= '<Default Extension="'.$ext.'" ContentType="'.$type.'"/>';
        }
        foreach ($this->overrides as $path => $type) {
            $x .= '<Override PartName="'.Ooxml::esc($path).'" ContentType="'.$type.'"/>';
        }

        return $x.'</Types>';
    }

    private function relsXml(string $source): string
    {
        $x = Ooxml::head().'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($this->rels[$source] ?? [] as $r) {
            $x .= '<Relationship Id="'.$r['id'].'" Type="'.$r['type'].'" Target="'.Ooxml::esc($r['target']).'"'.($r['external'] ? ' TargetMode="External"' : '').'/>';
        }

        return $x.'</Relationships>';
    }
}
