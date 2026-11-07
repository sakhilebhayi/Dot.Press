<?php

namespace App\Policies;

use App\Models\Deck;
use App\Models\Slide;
use App\Models\User;
use App\Services\Press\DeckAccess;

/** A slide is governed by its deck: see DeckAccess. */
class SlidePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->exists;
    }

    public function view(User $user, Slide $slide): bool
    {
        return $this->on($user, $slide, 'view');
    }

    /**
     * Class-level (no deck) only says the user is real; creating a
     * slide is authorised against the deck it goes into.
     */
    public function create(User $user, ?Deck $deck = null): bool
    {
        if ($deck === null) {
            return $user->exists;
        }

        return DeckAccess::can($user, $deck, 'update');
    }

    public function update(User $user, Slide $slide): bool
    {
        return $this->on($user, $slide, 'update');
    }

    public function delete(User $user, Slide $slide): bool
    {
        return $this->on($user, $slide, 'update');
    }

    public function restore(User $user, Slide $slide): bool
    {
        return $this->on($user, $slide, 'update');
    }

    public function forceDelete(User $user, Slide $slide): bool
    {
        return $this->on($user, $slide, 'update');
    }

    private function on(User $user, Slide $slide, string $ability): bool
    {
        $deck = DeckAccess::deckOf($slide);

        return $deck !== null && DeckAccess::can($user, $deck, $ability);
    }
}
