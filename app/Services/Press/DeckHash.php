<?php

namespace App\Services\Press;

use App\Models\Deck;

/**
 * A stable fingerprint of what a deck says: its size, theme, sections and every slide's content in order.
 * Approvals are bound to this value, so an approved deck stops being approved the moment it changes.
 * Presence, comments, activity and revision counters are not part of it.
 */
class DeckHash
{
    public static function compute(Deck $deck): string
    {
        $slides = $deck->slides()->orderBy('sort_order')->orderBy('id')->get()->map(fn ($slide) => [
            'title' => $slide->title,
            'notes' => $slide->notes,
            'hidden' => (bool) $slide->hidden,
            'section' => $slide->section_id,
            'layout' => $slide->layout,
            'bg' => $slide->bg,
            'transition' => $slide->transition,
            'els' => $slide->els ?? [],
        ])->all();

        return hash('sha256', self::canonical([
            'title' => $deck->title,
            'w' => (int) $deck->w,
            'h' => (int) $deck->h,
            'theme' => $deck->theme,
            'sections' => $deck->sections ?? [],
            'slides' => $slides,
        ]));
    }

    public static function canonical(mixed $value): string
    {
        return json_encode(self::sorted($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(fn ($item) => self::sorted($item), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
