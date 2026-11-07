<?php

namespace App\Models\Scopes;

use App\Models\Project;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Which decks a signed-in person can see at all. Mirrors App\Services\Press\DeckAccess so that no query, however it
 * is reached (a relation, an eager load, a count), can return a deck the person has no role on:
 *
 *  - a deck in a team the person belongs to, unless it is `private`;
 *  - a `private` deck only for its creator (while still in the team);
 *  - any deck shared with the person by name, whatever the team.
 *
 * Without a signed-in user (the public link routes, queue jobs, artisan) the scope does not restrict; those paths
 * authorise by token or run as the system. Code that has proved access some other way, such as a valid share token,
 * runs inside DeckAccessScope::without().
 */
class DeckAccessScope implements Scope
{
    private static int $bypass = 0;

    /** Run a callback with the access scopes switched off. Use only after access was proved another way. */
    public static function without(Closure $callback): mixed
    {
        self::$bypass++;

        try {
            return $callback();
        } finally {
            self::$bypass--;
        }
    }

    public static function bypassed(): bool
    {
        return self::$bypass > 0;
    }

    public function apply(Builder $builder, Model $model): void
    {
        if (self::bypassed() || ! Auth::check()) {
            return;
        }

        $user = Auth::user();
        $table = $model->getTable();
        $teamIds = $user->allTeams()->pluck('id');
        $teamProjects = Project::withoutGlobalScopes()->whereIn('team_id', $teamIds)->select('id');
        $shared = DB::table('deck_shares')->where('user_id', $user->id)->select('deck_id');

        $builder->where(function (Builder $query) use ($table, $user, $teamProjects, $shared): void {
            $query->where(function (Builder $viaTeam) use ($table, $user, $teamProjects, $shared): void {
                $viaTeam->whereIn("{$table}.project_id", $teamProjects)
                    ->where(function (Builder $visible) use ($table, $user, $shared): void {
                        $visible->where("{$table}.visibility", '!=', 'private')
                            ->orWhere("{$table}.user_id", $user->id)
                            ->orWhereIn("{$table}.id", $shared);
                    });
            })->orWhereIn("{$table}.id", $shared);
        });
    }
}
