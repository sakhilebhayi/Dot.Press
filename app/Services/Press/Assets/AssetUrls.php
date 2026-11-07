<?php

namespace App\Services\Press\Assets;

use App\Models\Asset;
use DateTimeInterface;
use Illuminate\Support\Facades\URL;

/**
 * Signed delivery urls and the JSON shape of an asset. Everything that hands an asset to a browser
 * (the library API, the document endpoint, exporters) builds its urls here.
 */
final class AssetUrls
{
    public const VARIANTS = ['orig', 'web', 'thumb'];

    public static function ttlMinutes(): int
    {
        return max(1, (int) config('filesystems.asset_signed_url_ttl', 10));
    }

    public static function signed(Asset $asset, string $variant = 'orig', ?DateTimeInterface $expires = null): string
    {
        return URL::temporarySignedRoute(
            'assets.file',
            $expires ?? now()->addMinutes(self::ttlMinutes()),
            ['asset' => $asset->id, 'v' => in_array($variant, self::VARIANTS, true) ? $variant : 'orig'],
        );
    }

    public static function download(Asset $asset, ?DateTimeInterface $expires = null): string
    {
        return URL::temporarySignedRoute('assets.download', $expires ?? now()->addMinutes(self::ttlMinutes()), ['asset' => $asset->id]);
    }

    /** The poster asset linked to a video, or null. */
    public static function posterOf(Asset $asset): ?Asset
    {
        $id = $asset->kind === 'video' ? ($asset->metadata['poster_asset_id'] ?? null) : null;

        return $id ? Asset::query()->where('kind', 'poster')->find($id) : null;
    }

    /**
     * The list/show shape.
     *
     * @param  ?array{decks: int, slides: int, brand_kits: int}  $usage
     * @return array<string, mixed>
     */
    public static function payload(Asset $asset, ?array $usage = null, ?Asset $poster = null): array
    {
        $poster ??= self::posterOf($asset);
        $url = self::signed($asset, 'orig');
        $hasRaster = $asset->thumb_path !== null;

        $thumb = match (true) {
            $hasRaster => self::signed($asset, 'thumb'),
            $asset->kind === 'image' => $url,
            $poster !== null => self::signed($poster, 'thumb'),
            default => null,
        };

        $payload = [
            'id' => $asset->id,
            'project_id' => $asset->project_id,
            'kind' => $asset->kind,
            'name' => $asset->original_name,
            'alt' => $asset->alt,
            'mime' => $asset->mime_type,
            'mime_type' => $asset->mime_type,
            'size' => (int) $asset->size,
            'width' => $asset->width,
            'height' => $asset->height,
            'url' => $url,
            'delivery_url' => $url,
            'web' => $asset->web_path !== null ? self::signed($asset, 'web') : $url,
            'thumb' => $thumb,
            'poster' => $poster === null ? null : ['id' => $poster->id, 'url' => self::signed($poster, 'web'), 'thumb' => self::signed($poster, 'thumb')],
            'animated' => (bool) ($asset->metadata['animated'] ?? false),
            'uploaded_by' => $asset->uploaded_by,
            'created_at' => $asset->created_at?->toIso8601String(),
        ];

        if ($usage !== null) {
            $payload['used_in'] = $usage;
        }

        return $payload;
    }

    /**
     * The entry for `GET /api/decks/{deck}/document` under `assets`: {url, thumb, w, h, mime, name}.
     *
     * @return array{url: string, thumb: ?string, w: ?int, h: ?int, mime: string, name: string}
     */
    public static function documentEntry(Asset $asset, ?Asset $poster = null): array
    {
        $payload = self::payload($asset, null, $poster);

        return [
            'url' => $payload['web'],
            'thumb' => $payload['thumb'],
            'w' => $asset->width,
            'h' => $asset->height,
            'mime' => $asset->mime_type,
            'name' => $asset->original_name,
        ];
    }
}
