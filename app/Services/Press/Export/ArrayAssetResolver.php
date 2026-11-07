<?php

namespace App\Services\Press\Export;

/** In-memory resolver used by tests and by the importer round trip. */
final class ArrayAssetResolver implements AssetResolver
{
    /** @param array<int, array{bytes: string, mime: string, width?: ?int, height?: ?int, name?: string}> $assets */
    public function __construct(private array $assets = []) {}

    public function add(int $id, string $bytes, string $mime, ?int $width = null, ?int $height = null, string $name = 'asset'): void
    {
        $this->assets[$id] = ['bytes' => $bytes, 'mime' => $mime, 'width' => $width, 'height' => $height, 'name' => $name];
    }

    public function find(int $id): ?array
    {
        $a = $this->assets[$id] ?? null;
        if ($a === null) {
            return null;
        }

        return [
            'bytes' => $a['bytes'],
            'mime' => $a['mime'],
            'width' => $a['width'] ?? null,
            'height' => $a['height'] ?? null,
            'name' => $a['name'] ?? 'asset',
        ];
    }
}
