<?php

namespace App\Policies;

use App\Models\Deck;
use App\Models\Slide;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamPermissions;

class SlidePolicy
{
    use ChecksTeamPermissions;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->exists;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Slide $slide): bool
    {
        return $user->belongsToTeam($slide->deck->project->team);
    }

    /**
     * Determine whether the user can create models.
     *
     * Class-level (no deck) only says the user is real; creating a
     * slide is authorised against the team that owns the deck.
     */
    public function create(User $user, ?Deck $deck = null): bool
    {
        if ($deck === null) {
            return $user->exists;
        }

        return $this->mayCreateIn($user, $deck->project->team);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Slide $slide): bool
    {
        return $this->mayChangeIn($user, $slide->deck->project->team);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Slide $slide): bool
    {
        return $this->mayChangeIn($user, $slide->deck->project->team);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Slide $slide): bool
    {
        return $this->mayChangeIn($user, $slide->deck->project->team);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Slide $slide): bool
    {
        return $this->mayChangeIn($user, $slide->deck->project->team);
    }
}
