<?php

namespace App\Policies;

use App\Models\Deck;
use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Services\Press\DeckAccess;

/**
 * Every ability derives from DeckAccess (see there for the role rules). `create` is the one exception: a deck does
 * not exist yet, so it is decided by the team role in the project that will own it.
 */
class DeckPolicy
{
    use ChecksTeamPermissions;

    public function viewAny(User $user): bool
    {
        return $user->exists;
    }

    public function view(User $user, Deck $deck): bool
    {
        return DeckAccess::can($user, $deck, 'view');
    }

    /**
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

    public function update(User $user, Deck $deck): bool
    {
        return DeckAccess::can($user, $deck, 'update');
    }

    public function comment(User $user, Deck $deck): bool
    {
        return DeckAccess::can($user, $deck, 'comment');
    }

    public function present(User $user, Deck $deck): bool
    {
        return DeckAccess::can($user, $deck, 'present');
    }

    public function export(User $user, Deck $deck): bool
    {
        return DeckAccess::can($user, $deck, 'export');
    }

    /** Sharing, visibility, review requests, deleting. */
    public function manage(User $user, Deck $deck): bool
    {
        return DeckAccess::can($user, $deck, 'manage');
    }

    /** Renaming is an editing right: whoever can update can rename. */
    public function rename(User $user, Deck $deck): bool
    {
        return DeckAccess::can($user, $deck, 'rename');
    }

    public function delete(User $user, Deck $deck): bool
    {
        return $this->manage($user, $deck);
    }

    public function restore(User $user, Deck $deck): bool
    {
        return $this->manage($user, $deck);
    }

    public function forceDelete(User $user, Deck $deck): bool
    {
        return $this->manage($user, $deck);
    }
}
