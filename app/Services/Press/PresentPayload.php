<?php

namespace App\Services\Press;

use App\Models\Asset;
use App\Models\Deck;
use App\Models\Slide;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

/**
 * What the player, the presenter console, the audience view and the offline package need to draw a deck:
 * the deck header, the slides (with or without speaker notes) and a map of the assets the slides use.
 *
 * Every query here ignores the per-user global scopes on purpose. The callers authorise the request first (a policy
 * for signed-in routes, an unguessable session token for live links), and a signed-in person from another team who
 * opens a live link must still see the deck, not an empty one.
 */
class PresentPayload
{
    /** @return array{id: int, title: string, w: int, h: int, theme: array<string, mixed>|null} */
    public static function deckHeader(Deck $deck): array
    {
        return [
            'id' => $deck->id,
            'title' => (string) $deck->title,
            'w' => (int) ($deck->w ?: 1920),
            'h' => (int) ($deck->h ?: 1080),
            'theme' => $deck->theme ?: null,
        ];
    }

    /**
     * Slides in presentation order, in the shape of the document spec.
     *
     * @param  bool  $notes  include speaker notes (only the presenter ever gets them)
     * @param  bool  $blankHidden  keep hidden slides in place (indexes must line up) but strip their content
     * @return list<array<string, mixed>>
     */
    public static function slides(Deck $deck, bool $notes, bool $blankHidden = false): array
    {
        return Slide::withoutGlobalScopes()
            ->where('deck_id', $deck->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(function (Slide $slide) use ($notes, $blankHidden): array {
                $blank = $blankHidden && $slide->hidden;

                return [
                    'id' => $slide->id,
                    'title' => $blank ? null : $slide->title,
                    'notes' => $notes ? (string) $slide->notes : '',
                    'hidden' => (bool) $slide->hidden,
                    'layout' => $slide->layout ?: 'blank',
                    'bg' => $blank ? null : $slide->bg,
                    'transition' => $slide->transition,
                    'els' => $blank ? [] : ($slide->els ?? []),
                ];
            })
            ->all();
    }

    /**
     * Asset ids a list of slides refers to (element sources, posters, slide backgrounds).
     *
     * @param  list<array<string, mixed>>  $slides
     * @return list<int>
     */
    public static function assetIds(array $slides): array
    {
        $ids = [];
        $take = function ($src) use (&$ids): void {
            if (is_array($src) && isset($src['asset']) && is_numeric($src['asset'])) {
                $ids[(int) $src['asset']] = true;
            }
        };
        foreach ($slides as $slide) {
            foreach ($slide['els'] ?? [] as $el) {
                if (! is_array($el)) {
                    continue;
                }
                $take($el['src'] ?? null);
                $take($el['poster'] ?? null);
            }
            $bg = $slide['bg'] ?? null;
            if (is_array($bg)) {
                $take($bg['img'] ?? null);
            }
        }

        return array_keys($ids);
    }

    /**
     * Assets of this deck's project that the slides use. An id in a document that belongs to another project is
     * simply not found, so a crafted document cannot reach another team's files through the player.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Asset>
     */
    public static function assets(Deck $deck, array $ids)
    {
        if ($ids === []) {
            return collect();
        }

        return Asset::withoutGlobalScopes()
            ->where('project_id', $deck->project_id)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }

    /**
     * The `assets` map of spec section 9 for the player: relative signed URLs that need no session, valid until
     * $expires. The URL is the capability, so it is only ever put in pages the caller was allowed to open.
     *
     * @param  list<array<string, mixed>>  $slides
     * @return array<string, array{url: string, thumb: string, w: int|null, h: int|null, mime: string|null, name: string|null}>
     */
    public static function assetMap(Deck $deck, array $slides, DateTimeInterface $expires): array
    {
        $map = [];
        foreach (self::assets($deck, self::assetIds($slides)) as $asset) {
            $map[(string) $asset->id] = [
                'url' => URL::temporarySignedRoute('press.asset', $expires, ['asset' => $asset->id, 'v' => 'web'], absolute: false),
                'thumb' => URL::temporarySignedRoute('press.asset', $expires, ['asset' => $asset->id, 'v' => 'thumb'], absolute: false),
                'w' => $asset->width ? (int) $asset->width : null,
                'h' => $asset->height ? (int) $asset->height : null,
                'mime' => $asset->mime_type,
                'name' => $asset->original_name,
            ];
        }

        return $map;
    }
}
