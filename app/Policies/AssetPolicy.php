<?php

namespace App\Policies;

use App\Models\Asset;
use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamPermissions;

class AssetPolicy
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
    public function view(User $user, Asset $asset): bool
    {
        return $user->belongsToTeam($asset->project->team);
    }

    /**
     * Determine whether the user can create models.
     *
     * Class-level (no project) only says the user is real; creating a
     * asset is authorised against the team that owns the project.
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
    public function update(User $user, Asset $asset): bool
    {
        return $this->mayChangeIn($user, $asset->project->team);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Asset $asset): bool
    {
        return $this->mayChangeIn($user, $asset->project->team);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Asset $asset): bool
    {
        return $this->mayChangeIn($user, $asset->project->team);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Asset $asset): bool
    {
        return $this->mayChangeIn($user, $asset->project->team);
    }
}
