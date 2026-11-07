<?php

namespace App\Services\Press;

use App\Models\Deck;
use App\Models\DeckChange;

/**
 * Assembles the presentation document (docs/programme/R40_DOCUMENT_SPEC.md sections 4, 5 and 9) from the models.
 * Read only: every change goes through OpApplier.
 */
class DeckDocument
{
    /**
     * @return array{deck: array<string, mixed>, slides: list<array<string, mixed>>, seq: int}
     */
    public static function toArray(Deck $deck): array
    {
        $slides = $deck->slides()->orderBy('sort_order')->orderBy('id')->get();

        return [
            'deck' => [
                'id' => $deck->id,
                'title' => $deck->title,
                'w' => (int) $deck->w,
                'h' => (int) $deck->h,
                'theme' => $deck->theme ?: null,
                'rev' => (int) $deck->rev,
                'status' => $deck->status,
                'visibility' => $deck->visibility,
                'sections' => $deck->sections ?? [],
                'slide_order' => $slides->pluck('id')->all(),
                'updated_at' => optional($deck->updated_at)->toIso8601String(),
            ],
            'slides' => $slides->map(fn ($slide) => [
                'id' => $slide->id,
                'title' => $slide->title,
                'notes' => $slide->notes,
                'section_id' => $slide->section_id,
                'hidden' => (bool) $slide->hidden,
                'layout' => $slide->layout ?: 'blank',
                'bg' => $slide->bg,
                'transition' => $slide->transition,
                'rev' => (int) $slide->revision,
                'els' => $slide->els ?? [],
            ])->all(),
            'seq' => (int) (DeckChange::where('deck_id', $deck->id)->max('id') ?? 0),
        ];
    }
}
