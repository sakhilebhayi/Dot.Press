<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamPermissions;
use Illuminate\Support\Facades\DB;

class ProjectPolicy
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
    public function view(User $user, Project $project): bool
    {
        return $user->belongsToTeam($project->team) || $this->hasSharedDeckIn($user, $project);
    }

    /** A person outside the team who was given a deck in this project can see the project that holds it. */
    private function hasSharedDeckIn(User $user, Project $project): bool
    {
        return DB::table('deck_shares')
            ->join('decks', 'decks.id', '=', 'deck_shares.deck_id')
            ->where('deck_shares.user_id', $user->id)
            ->where('decks.project_id', $project->id)
            ->exists();
    }

    /**
     * Determine whether the user can create models.
     *
     * A project is created in the user's current team, so the role they hold
     * there decides.
     */
    public function create(User $user): bool
    {
        return $this->mayCreateIn($user, $user->currentTeam);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Project $project): bool
    {
        return $this->mayChangeIn($user, $project->team);
    }

    /**
     * Determine whether the user can delete the model.
     */
    /**
     * Deleting a project deletes every deck and picture in it, and a team's decks all live in one workspace project.
     * That is a team administrator's decision, not an editor's: editors delete decks (which are archived first), not the
     * library that holds them.
     */
    public function delete(User $user, Project $project): bool
    {
        return $project->team !== null && $user->hasTeamPermission($project->team, 'delete');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Project $project): bool
    {
        return $this->mayChangeIn($user, $project->team);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Project $project): bool
    {
        return $this->mayChangeIn($user, $project->team);
    }
}
