<?php

namespace App\Services\Press;

use App\Models\Deck;
use App\Models\Slide;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Copies a deck: slides (with their elements), sections, theme and size. Versions, comments, shares and activity are
 * not copied. Links to other slides of the deck (`#slide:<id>`) are pointed at the copies.
 */
class DeckCloner
{
    public function duplicate(Deck $source, User $user, ?string $title = null): Deck
    {
        return DB::transaction(function () use ($source, $user, $title): Deck {
            $copy = Deck::create([
                'project_id' => $source->project_id,
                'user_id' => $user->id,
                'title' => Str::limit($title ?? 'Copy of '.$source->title, 255, ''),
                'theme' => $source->theme,
                'sort_order' => (int) $source->sort_order,
                'w' => $source->w,
                'h' => $source->h,
                'sections' => $source->sections ?? [],
            ]);

            $slides = Slide::withoutGlobalScopes()->where('deck_id', $source->id)->orderBy('sort_order')->orderBy('id')->get();
            $created = [];
            foreach ($slides as $position => $slide) {
                $new = new Slide;
                $new->deck_id = $copy->id;
                $new->title = $slide->title;
                $new->notes = $slide->notes;
                $new->layout = $slide->layout ?: 'blank';
                $new->bg = $slide->bg;
                $new->transition = $slide->transition;
                $new->section_id = $slide->section_id;
                $new->hidden = (bool) $slide->hidden;
                $new->sort_order = $position;
                $new->revision = 0;
                $new->els = $slide->els ?? [];
                $new->save();
                $created[$slide->id] = $new;
            }

            $map = [];
            foreach ($created as $oldId => $new) {
                $map[$oldId] = $new->id;
            }
            foreach ($created as $new) {
                $relinked = $this->relink($new->els, $map);
                if ($relinked !== $new->els) {
                    $new->els = $relinked;
                    $new->save();
                }
            }

            ActivityRecorder::record($copy, $user, 'created', ['from' => $source->id]);

            return $copy;
        });
    }

    /**
     * @param  array<int|string, mixed>  $node
     * @param  array<int, int>  $map
     * @return array<int|string, mixed>
     */
    private function relink(array $node, array $map): array
    {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->relink($value, $map);
            } elseif (is_string($value) && ($key === 'link' || $key === 'href') && preg_match('/^#slide:(\d+)$/', $value, $m) && isset($map[(int) $m[1]])) {
                $node[$key] = '#slide:'.$map[(int) $m[1]];
            }
        }

        return $node;
    }
}
