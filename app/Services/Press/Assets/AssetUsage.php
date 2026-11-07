<?php

namespace App\Services\Press\Assets;

use App\Models\Asset;
use App\Models\BrandKit;
use App\Models\Deck;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Where an asset is used. A reference is `{"asset": <id>}` inside a slide's `els` or `bg`, a deck theme's
 * background image, or a brand kit logo. The scan reads the stored JSON text (one pass per project, in chunks)
 * so it also finds references the editor wrote in shapes this code has never seen.
 */
class AssetUsage
{
    private const REFERENCE = '/"asset"\s*:\s*"?(\d+)"?/';

    /**
     * Every asset id referenced in a project, with where.
     *
     * @return array{slides: array<int, array<int, list<int>>>, themes: array<int, list<int>>}
     */
    public function scan(int $projectId): array
    {
        $slides = [];
        $themes = [];

        DB::table('slides')
            ->join('decks', 'decks.id', '=', 'slides.deck_id')
            ->where('decks.project_id', $projectId)
            ->where(function ($query): void {
                $query->where('slides.els', 'like', '%"asset"%')->orWhere('slides.bg', 'like', '%"asset"%');
            })
            ->select('slides.id as slide_id', 'slides.deck_id', 'slides.els', 'slides.bg')
            ->chunkById(200, function ($rows) use (&$slides): void {
                foreach ($rows as $row) {
                    foreach (self::idsIn((string) $row->els.' '.(string) $row->bg) as $assetId) {
                        $slides[$assetId][(int) $row->deck_id][] = (int) $row->slide_id;
                    }
                }
            }, 'slides.id', 'slide_id');

        DB::table('decks')
            ->where('project_id', $projectId)
            ->where('theme', 'like', '%"asset"%')
            ->select('id', 'theme')
            ->orderBy('id')
            ->each(function ($row) use (&$themes): void {
                foreach (self::idsIn((string) $row->theme) as $assetId) {
                    $themes[$assetId][] = (int) $row->id;
                }
            });

        return ['slides' => $slides, 'themes' => $themes];
    }

    /**
     * Counts for the library list: [assetId => {decks, slides, brand_kits}]. All assets must be in one project.
     *
     * @param  iterable<Asset>  $assets
     * @return array<int, array{decks: int, slides: int, brand_kits: int}>
     */
    public function countsFor(iterable $assets): array
    {
        $assets = collect($assets);
        $counts = [];

        foreach ($assets->groupBy('project_id') as $projectId => $group) {
            $scan = $this->scan((int) $projectId);
            $logos = $this->logoUsage($this->teamOf((int) $projectId));

            foreach ($group as $asset) {
                $deckIds = [];
                $slideCount = 0;
                $kits = [];

                foreach ($this->idsFor($asset) as $id) {
                    foreach ($scan['slides'][$id] ?? [] as $deckId => $slideIds) {
                        $deckIds[$deckId] = true;
                        $slideCount += count($slideIds);
                    }
                    foreach ($scan['themes'][$id] ?? [] as $deckId) {
                        $deckIds[$deckId] = true;
                    }
                    foreach ($logos[$id] ?? [] as $kitId) {
                        $kits[$kitId] = true;
                    }
                }

                $counts[$asset->id] = ['decks' => count($deckIds), 'slides' => $slideCount, 'brand_kits' => count($kits)];
            }
        }

        return $counts;
    }

    /**
     * The detail behind the counts. With a viewer, titles of decks the viewer may not open are hidden.
     *
     * @return array{asset_id: int, in_use: bool, deck_count: int, slide_count: int, brand_kit_count: int, decks: list<array<string, mixed>>, brand_kits: list<array<string, mixed>>}
     */
    public function forAsset(Asset $asset, ?User $viewer = null): array
    {
        $scan = $this->scan((int) $asset->project_id);
        $perDeck = [];

        foreach ($this->idsFor($asset) as $id) {
            foreach ($scan['slides'][$id] ?? [] as $deckId => $slideIds) {
                $perDeck[$deckId]['slides'] = array_values(array_unique(array_merge($perDeck[$deckId]['slides'] ?? [], $slideIds)));
            }
            foreach ($scan['themes'][$id] ?? [] as $deckId) {
                $perDeck[$deckId]['theme'] = true;
            }
        }

        $decks = [];
        $slideTotal = 0;

        if ($perDeck !== []) {
            foreach (Deck::withoutGlobalScopes()->whereIn('id', array_keys($perDeck))->orderBy('id')->get() as $deck) {
                $entry = $perDeck[$deck->id];
                $slides = $entry['slides'] ?? [];
                $slideTotal += count($slides);

                $decks[] = [
                    'id' => $deck->id,
                    'title' => ($viewer === null || $viewer->can('view', $deck)) ? $deck->title : 'A deck you cannot open',
                    'slide_count' => count($slides),
                    'slide_ids' => $slides,
                    'in_theme' => (bool) ($entry['theme'] ?? false),
                ];
            }
        }

        $logos = $this->logoUsage($this->teamOf((int) $asset->project_id));
        $kitIds = [];
        foreach ($this->idsFor($asset) as $id) {
            foreach ($logos[$id] ?? [] as $kitId) {
                $kitIds[$kitId] = true;
            }
        }

        $kits = $kitIds === [] ? [] : BrandKit::withoutGlobalScopes()->whereIn('id', array_keys($kitIds))->orderBy('id')->get(['id', 'name'])
            ->map(fn (BrandKit $kit): array => ['id' => $kit->id, 'name' => $kit->name])->all();

        return [
            'asset_id' => $asset->id,
            'in_use' => $decks !== [] || $kits !== [],
            'deck_count' => count($decks),
            'slide_count' => $slideTotal,
            'brand_kit_count' => count($kits),
            'decks' => $decks,
            'brand_kits' => $kits,
        ];
    }

    /**
     * Distinct asset ids named in a JSON text.
     *
     * @return list<int>
     */
    public static function idsIn(string $json): array
    {
        if (! str_contains($json, '"asset"')) {
            return [];
        }

        preg_match_all(self::REFERENCE, $json, $matches);

        return array_values(array_unique(array_map('intval', $matches[1])));
    }

    /**
     * The asset itself and, for a video, its poster: an element can name either.
     *
     * @return list<int>
     */
    private function idsFor(Asset $asset): array
    {
        $ids = [$asset->id];

        if ($asset->kind === 'video' && ! empty($asset->metadata['poster_asset_id'])) {
            $ids[] = (int) $asset->metadata['poster_asset_id'];
        }

        return $ids;
    }

    private function teamOf(int $projectId): int
    {
        return (int) DB::table('projects')->where('id', $projectId)->value('team_id');
    }

    /**
     * @return array<int, list<int>> assetId => brand kit ids that carry it as a logo
     */
    private function logoUsage(int $teamId): array
    {
        $usage = [];

        foreach (BrandKit::withoutGlobalScopes()->where('team_id', $teamId)->get(['id', 'tokens']) as $kit) {
            foreach ((array) ($kit->tokens['logos'] ?? []) as $logo) {
                $id = is_array($logo) ? (int) ($logo['asset'] ?? 0) : (int) $logo;
                if ($id > 0) {
                    $usage[$id][] = $kit->id;
                }
            }
        }

        return $usage;
    }
}
