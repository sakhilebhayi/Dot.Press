<?php

namespace App\Services\Press;

use App\Models\Asset;
use App\Services\Press\Assets\AssetUrls;

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

        return $this->forIds($ids);
    }

    /**
     * The same map for asset ids already collected (the streamed document finds them without decoding the slides).
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    public function forIds(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach (Asset::query()->whereIn('id', $ids)->get() as $asset) {
            $out[$asset->id] = ['id' => $asset->id] + AssetUrls::documentEntry($asset);
        }

        return $out;
    }
}
