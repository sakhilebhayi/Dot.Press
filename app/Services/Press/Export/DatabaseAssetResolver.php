<?php

namespace App\Services\Press\Export;

use App\Models\Asset;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Reads assets through the Asset model and its storage disk. Ownership is not checked here: the caller
 * (an authorised export of a deck the user may view) decides which asset ids may be read. When a project id list
 * is given, only assets of those projects resolve, so a document that names a foreign asset id gets nothing.
 */
final class DatabaseAssetResolver implements AssetResolver
{
    /** @var array<int, array<string, mixed>|null> */
    private array $cache = [];

    /** Skip files larger than this: a PDF or PPTX built from a 60 MB original would exhaust shared-host memory. */
    private const MAX_BYTES = 40 * 1024 * 1024;

    /** @param list<int>|null $projectIds */
    public function __construct(private ?array $projectIds = null) {}

    public function find(int $id): ?array
    {
        if (array_key_exists($id, $this->cache)) {
            return $this->cache[$id];
        }

        return $this->cache[$id] = $this->load($id);
    }

    /** @return array{bytes: string, mime: string, width: ?int, height: ?int, name: string}|null */
    private function load(int $id): ?array
    {
        $query = Asset::withoutGlobalScopes()->whereKey($id);
        if ($this->projectIds !== null) {
            $query->whereIn('project_id', $this->projectIds);
        }
        $asset = $query->first();
        if ($asset === null) {
            return null;
        }

        try {
            $disk = Storage::disk($asset->disk ?: config('filesystems.asset_upload_disk', 'local'));
            // Prefer the web variant (smaller, already oriented), fall back to the original.
            foreach (array_filter([$asset->web_path, $asset->path]) as $path) {
                if (! $disk->exists($path)) {
                    continue;
                }
                if ($disk->size($path) > self::MAX_BYTES) {
                    continue;
                }
                $bytes = $disk->get($path);
                if ($bytes === null || $bytes === '') {
                    continue;
                }
                $mime = $path === $asset->web_path ? $this->sniffMime($bytes, (string) $asset->mime_type) : (string) $asset->mime_type;

                return [
                    'bytes' => $bytes,
                    'mime' => $mime !== '' ? $mime : $this->sniffMime($bytes, 'application/octet-stream'),
                    'width' => $asset->width ? (int) $asset->width : null,
                    'height' => $asset->height ? (int) $asset->height : null,
                    'name' => (string) ($asset->original_name ?: 'asset-'.$asset->id),
                ];
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function sniffMime(string $bytes, string $fallback): string
    {
        $head = substr($bytes, 0, 16);
        if (str_starts_with($head, "\x89PNG")) {
            return 'image/png';
        }
        if (str_starts_with($head, "\xFF\xD8")) {
            return 'image/jpeg';
        }
        if (str_starts_with($head, 'GIF8')) {
            return 'image/gif';
        }
        if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        return $fallback;
    }
}
