<?php

namespace App\Services\Press;

use App\Models\Asset;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * The `assets` map of the document answer (spec section 9): every asset the document points at, with a short-lived
 * signed delivery URL. The URL logic mirrors AssetController::deliveryUrl; the assets are looked up through the
 * Asset model's own team scope, so an id from another team simply does not appear.
 */
class DocumentAssets
{
    /**
     * @param  array{deck: array<string, mixed>, slides: list<array<string, mixed>>}  $document
     * @return array<int, array<string, mixed>>
     */
    public function forDocument(array $document): array
    {
        $ids = [];
        $collect = function (mixed $node) use (&$ids): void {
            if (is_array($node) && isset($node['asset']) && is_numeric($node['asset'])) {
                $ids[] = (int) $node['asset'];
            }
        };

        $bg = $document['deck']['theme']['bg'] ?? null;
        $collect($bg['img'] ?? null);

        foreach ($document['slides'] as $slide) {
            $collect($slide['bg']['img'] ?? null);
            foreach ($slide['els'] ?? [] as $el) {
                $collect($el['src'] ?? null);
                $collect($el['poster'] ?? null);
            }
        }

        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach (Asset::query()->whereIn('id', $ids)->get() as $asset) {
            $url = $this->deliveryUrl($asset);
            $isImage = str_starts_with((string) $asset->mime_type, 'image/');
            $out[$asset->id] = [
                'id' => $asset->id,
                'name' => $asset->original_name,
                'mime' => $asset->mime_type,
                'w' => $asset->width,
                'h' => $asset->height,
                'url' => $url,
                'delivery_url' => $url,
                'thumb' => $isImage ? $url : null,
            ];
        }

        return $out;
    }

    private function deliveryUrl(Asset $asset): string
    {
        $ttl = now()->addMinutes((int) config('filesystems.asset_signed_url_ttl', 10));

        if (config("filesystems.disks.{$asset->disk}.driver") !== 's3') {
            return URL::temporarySignedRoute('assets.download', $ttl, ['asset' => $asset->id]);
        }

        try {
            return Storage::disk($asset->disk)->temporaryUrl($asset->path, $ttl);
        } catch (Throwable) {
            return URL::temporarySignedRoute('assets.download', $ttl, ['asset' => $asset->id]);
        }
    }
}
