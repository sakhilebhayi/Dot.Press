<?php

namespace App\Services\Press;

use App\Models\Deck;
use App\Models\Element;
use App\Models\Project;
use App\Models\Slide;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Moves decks made before R-40 into the document model. Safe to run again at any time and on an empty database:
 *
 *  - decks.user_id comes from the owner of the project that holds the deck;
 *  - a deck theme that is not a spec theme (the old one held only a background colour) becomes the default theme, with
 *    that colour as its default slide background;
 *  - a slide whose `els` is still null gets its elements from the old `elements` table (or from canvas_state when the
 *    table holds none), mapped by LegacyElementMapper, and its `bg` from the deck theme.
 *
 * `slides.revision` is not touched and the old tables stay as they are.
 */
class Backfill
{
    public function __construct(private readonly LegacyElementMapper $mapper = new LegacyElementMapper) {}

    /**
     * @return array{owners: int, themes: int, slides: int, elements: int, dropped: int, failed: int}
     */
    public function run(): array
    {
        $stats = ['owners' => 0, 'themes' => 0, 'slides' => 0, 'elements' => 0, 'dropped' => 0, 'failed' => 0];

        Deck::withoutGlobalScopes()->chunkById(200, function ($decks) use (&$stats): void {
            foreach ($decks as $deck) {
                if ($deck->user_id === null) {
                    $owner = Project::withoutGlobalScopes()->whereKey($deck->project_id)->value('user_id');
                    if ($owner !== null) {
                        $deck->user_id = $owner;
                        $stats['owners']++;
                    }
                }

                if (! is_array($deck->theme) || ! isset($deck->theme['c'])) {
                    $deck->theme = $this->specTheme($deck->theme);
                    $stats['themes']++;
                }

                if ($deck->isDirty()) {
                    $deck->timestamps = false;
                    $deck->save();
                }
            }
        });

        Slide::withoutGlobalScopes()->whereNull('els')->chunkById(100, function ($slides) use (&$stats): void {
            $themes = Deck::withoutGlobalScopes()->whereIn('id', $slides->pluck('deck_id')->unique())->pluck('theme', 'id');

            foreach ($slides as $slide) {
                try {
                    $this->slide($slide, $themes[$slide->deck_id] ?? null, $stats);
                } catch (Throwable $e) {
                    // The slide stays unconverted (els null) so a later run can try again.
                    $stats['failed']++;
                    Log::warning('Dot.Press backfill could not convert a slide', ['slide' => $slide->id, 'error' => $e->getMessage()]);
                }
            }
        });

        return $stats;
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function slide(Slide $slide, mixed $theme, array &$stats): void
    {
        $legacy = Element::withoutGlobalScopes()
            ->where('slide_id', $slide->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Element $element) => $element->toCanvasElement())
            ->all();

        if ($legacy === [] && is_array($slide->canvas_state['elements'] ?? null)) {
            $legacy = array_values(array_filter($slide->canvas_state['elements'], 'is_array'));
        }

        $used = [];
        $els = [];
        foreach ($legacy as $item) {
            $mapped = $this->mapper->map($item, $used);
            if ($mapped === null) {
                $stats['dropped']++;

                continue;
            }
            $els[] = $mapped;
        }

        $slide->els = $els;
        if ($slide->bg === null && is_array($theme) && isset($theme['bg']) && is_string($theme['bg'])) {
            $slide->bg = $theme['bg'];
        }

        $slide->timestamps = false;
        $slide->save();

        $stats['slides']++;
        $stats['elements'] += count($els);
    }

    /**
     * @return array<string, mixed>
     */
    private function specTheme(mixed $old): array
    {
        $theme = DocumentSanitizer::defaultTheme();
        $background = is_array($old) ? ($old['background'] ?? null) : null;

        if (is_string($background) && preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $background)) {
            $theme['bg'] = (new DocumentSanitizer)->colour($background);
        }

        return $theme;
    }
}
