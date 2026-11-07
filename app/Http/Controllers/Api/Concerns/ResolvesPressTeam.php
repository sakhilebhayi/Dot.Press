<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The team a library request is about (themes, brand kits, layouts): the `team_id` the client names when the
 * person belongs to that team, otherwise their current team. Roles come from Jetstream: editors and admins write,
 * viewers read.
 */
trait ResolvesPressTeam
{
    protected function resolveTeam(Request $request): Team
    {
        /** @var User $user */
        $user = $request->user();

        $teamId = $request->query('team_id', $request->input('team_id'));

        if ($teamId !== null && $teamId !== '') {
            $team = ctype_digit((string) $teamId) ? $user->allTeams()->firstWhere('id', (int) $teamId) : null;
            abort_if($team === null, 404);

            return $team;
        }

        abort_if($user->currentTeam === null, 422, 'Choose a team first.');

        return $user->currentTeam;
    }

    protected function requireTeamRole(Request $request, Team $team, string $permission): void
    {
        abort_unless($request->user()->hasTeamPermission($team, $permission), 403, 'Your role in this team does not allow that.');
    }
}
