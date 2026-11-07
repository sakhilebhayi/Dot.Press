<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * A project is visible to every team member (as HasTeamScope always did) and, in addition, to anyone who was given
 * a deck inside it by name, so a person outside the team can open the deck shared with them. Their view of the
 * project's decks is still limited by DeckAccessScope.
 */
class ProjectAccessScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (DeckAccessScope::bypassed() || ! Auth::check()) {
            return;
        }

        $user = Auth::user();
        $table = $model->getTable();
        $teamIds = $user->allTeams()->pluck('id');
        $sharedProjects = DB::table('decks')
            ->join('deck_shares', 'deck_shares.deck_id', '=', 'decks.id')
            ->where('deck_shares.user_id', $user->id)
            ->select('decks.project_id');

        $builder->where(function (Builder $query) use ($table, $teamIds, $sharedProjects): void {
            $query->whereIn("{$table}.team_id", $teamIds)
                ->orWhereIn("{$table}.id", $sharedProjects);
        });
    }
}
