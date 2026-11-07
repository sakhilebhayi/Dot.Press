<?php

namespace App\Broadcasting;

use App\Models\Deck;
use App\Models\Slide;
use App\Models\User;
use App\Services\Press\DeckAccess;

/**
 * Authorization for the 'slide.{slideId}' private channel, factored out of
 * routes/channels.php so it can be unit tested directly -- the "null" and
 * "log" broadcast drivers (what tests and local dev use by default) never
 * actually invoke a channel's callback via the /broadcasting/auth HTTP
 * endpoint, so that endpoint can't be used to verify this logic.
 *
 * Every lookup here is deliberately unscoped (Slide -> Deck) rather than
 * via a relation: eager-loading would apply the access scopes using the
 * *current* authenticated user, which would silently return null for
 * anyone without access instead of the row the check needs. The decision
 * itself is DeckAccess's, like every other route.
 */
class SlideChannelAuthorizer
{
    public static function authorize(User $user, int $slideId): bool
    {
        $slide = Slide::withoutGlobalScopes()->find($slideId);

        if (! $slide) {
            return false;
        }

        $deck = Deck::withoutGlobalScopes()->find($slide->deck_id);

        if (! $deck) {
            return false;
        }

        return DeckAccess::can($user, $deck, 'view');
    }
}
