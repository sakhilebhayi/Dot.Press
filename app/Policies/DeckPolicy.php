<?php

namespace App\Policies;

use App\Models\Deck;
use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamPermissions;

class DeckPolicy
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
    public function view(User $user, Deck $deck): bool
    {
        return $user->belongsToTeam($deck->project->team);
    }

    /**
     * Determine whether the user can create models.
     *
     * Class-level (no project) only says the user is real; creating a
     * deck is authorised against the team that owns the project.
     */
    public function create(User $user, ?Project $project = null): bool
    {
        if ($project === null) {
            return $user->exists;
        }

        return $this->mayCreateIn($user, $project->team);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Deck $deck): bool
    {
        return $this->mayChangeIn($user, $deck->project->team);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Deck $deck): bool
    {
        return $this->mayChangeIn($user, $deck->project->team);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Deck $deck): bool
    {
        return $this->mayChangeIn($user, $deck->project->team);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Deck $deck): bool
    {
        return $this->mayChangeIn($user, $deck->project->team);
    }
}
