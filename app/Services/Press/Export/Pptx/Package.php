<?php

namespace App\Services\Press\Export\Pptx;

use RuntimeException;
use ZipArchive;

/**
 * An OPC package in memory: parts, their content types and the relationship lists. close() writes the zip.
 */
final class Package
{
    /** @var array<string, string> path => bytes */
    private array $parts = [];

    /** @var array<string, string> path => content type (overrides) */
    private array $overrides = [];

    /** @var array<string, string> extension => content type */
    private array $defaults = ['rels' => 'application/vnd.openxmlformats-package.relationships+xml', 'xml' => 'application/xml'];

    /** @var array<string, list<array{id: string, type: string, target: string, external: bool}>> source part => relationships */
    private array $rels = [];

    public function add(string $path, string $bytes, ?string $contentType = null): void
    {
        $this->parts[$path] = $bytes;
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
        return isset($this->parts[$path]);
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
        foreach (array_keys($this->rels) as $source) {
            if ($source === '') {
                continue;
            }
            $dir = dirname($source);
            $zip->addFromString(($dir === '.' ? '' : $dir.'/').'_rels/'.basename($source).'.rels', $this->relsXml($source));
        }
        if (! $zip->close()) {
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
