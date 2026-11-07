<?php

namespace App\Services\Press;

use App\Models\Deck;
use App\Models\DeckActivity;
use App\Models\User;

/**
 * The deck's activity trail: who did what, newest last. Callers pass a short event name such as `created`,
 * `renamed`, `resized`, `version.saved`, `version.restored`, `shared`, `visibility`, `review.requested`,
 * `review.approved`, `comment`, `exported`, `imported`; `meta` holds small facts for display, never document content.
 */
class ActivityRecorder
{
    /** @param  array<string, mixed>  $meta */
    public static function record(Deck $deck, ?User $user, string $event, array $meta = []): DeckActivity
    {
        return DeckActivity::create([
            'deck_id' => $deck->id,
            'user_id' => $user?->id,
            'event' => $event,
            'meta' => $meta ?: null,
            'created_at' => now(),
        ]);
    }
}
