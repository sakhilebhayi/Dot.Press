<?php

namespace App\Services\Press;

use App\Models\Deck;
use App\Models\DeckChange;
use App\Models\Slide;

/**
 * Assembles the presentation document (docs/programme/R40_DOCUMENT_SPEC.md sections 4, 5 and 9) from the models.
 * Read only: every change goes through OpApplier.
 */
class DeckDocument
{
    /**
     * The status to show: the review flow decides when an approval no longer holds (Reviews, when present).
     */
    public static function status(Deck $deck): string
    {
        if (class_exists(Reviews::class) && method_exists(Reviews::class, 'effectiveStatus')) {
            return (string) Reviews::effectiveStatus($deck);
        }

        return (string) $deck->status;
    }

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
                'status' => self::status($deck),
                'visibility' => $deck->visibility,
                'sections' => $deck->sections ?? [],
                'slide_order' => $slides->pluck('id')->all(),
                'updated_at' => optional($deck->updated_at)->toIso8601String(),
            ],
            'slides' => $slides->map(fn ($slide) => self::slideToArray($slide))->all(),
            'seq' => (int) (DeckChange::where('deck_id', $deck->id)->max('id') ?? 0),
        ];
    }

    /**
     * One slide as spec section 5 describes it.
     *
     * @return array<string, mixed>
     */
    public static function slideToArray(Slide $slide): array
    {
        return [
            'id' => $slide->id,
            'title' => $slide->title ?? '',
            'notes' => $slide->notes ?? '',
            'section_id' => $slide->section_id,
            'hidden' => (bool) $slide->hidden,
            'layout' => $slide->layout ?: 'blank',
            'bg' => $slide->bg,
            'transition' => $slide->transition,
            'rev' => (int) $slide->revision,
            'els' => $slide->els ?? [],
        ];
    }
}
