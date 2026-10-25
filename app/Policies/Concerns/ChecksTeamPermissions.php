<?php

namespace App\Policies\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Write abilities follow the role the person holds on the team that owns the
 * record, not mere membership: Jetstream's hasTeamPermission() is true for the
 * team owner, and otherwise reads the permissions of the member's role (see
 * JetstreamServiceProvider). A read-only "viewer" holds only 'read'.
 *
 * The app has never used the 'delete' permission: editors have always been able
 * to delete the content they work on. Deleting therefore needs 'update', the
 * "can write" permission every role except viewer holds, so editors keep
 * what they had and only viewers lose it.
 */
trait ChecksTeamPermissions
{
    protected function mayCreateIn(User $user, ?Model $team): bool
    {
        return $team !== null && $user->hasTeamPermission($team, 'create');
    }

    protected function mayChangeIn(User $user, ?Model $team): bool
    {
        return $team !== null && $user->hasTeamPermission($team, 'update');
    }
}
