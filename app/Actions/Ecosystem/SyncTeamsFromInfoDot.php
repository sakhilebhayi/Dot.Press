<?php

namespace App\Actions\Ecosystem;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies the companies InfoDot says a person belongs to, on every sign-in.
 *
 * A company here is a team linked to an InfoDot team by uuid. Rules:
 * - Only people InfoDot lists as owner or admin of a company create its team here;
 *   an ordinary member is attached once it exists, so a member can never become
 *   the local owner of someone else's company.
 * - Membership and role follow InfoDot (owner and admin become admin, editor stays
 *   editor, viewer becomes the read-only viewer role, and any other role becomes viewer
 *   too: least privilege). An existing membership has its role updated at every sign-in.
 * - Companies InfoDot no longer lists for the person are detached, so removing
 *   someone in InfoDot removes their access here at their next sign-in.
 * - The company InfoDot says is active becomes the person's current team.
 * - Personal teams are never linked or removed.
 * - R-50: a company InfoDot marks `entitled: false` for this platform is left exactly as it is here: not created,
 *   not renamed, no membership or role change, and it cannot be or stay the person's current team. A company with
 *   no `entitled` key (an InfoDot from before R-50) is treated as it always was.
 */
class SyncTeamsFromInfoDot
{
    /**
     * @param  array<string, mixed>  $identity  the redeem response
     */
    public function __invoke(User $user, array $identity): void
    {
        $listed = collect($identity['teams'] ?? [])
            ->filter(fn ($t) => is_array($t) && filled($t['uuid'] ?? null) && ! ($t['personal'] ?? false))
            ->keyBy('uuid');

        $admitted = $listed->filter(fn ($t) => self::admits($t));

        DB::transaction(function () use ($user, $listed, $admitted, $identity) {
            foreach ($admitted as $uuid => $info) {
                $this->apply($user, (string) $uuid, $info);
                $this->forgetCachedTeams($user);
            }

            $this->revoke($user, $listed->keys()->all());
            $this->forgetCachedTeams($user);

            $active = $identity['current_team_uuid'] ?? null;
            $team = $active && $admitted->has($active) ? Team::where('infodot_team_uuid', $active)->first() : null;

            if ($team && $user->belongsToTeam($team)) {
                $user->switchTeam($team);
            }

            $refused = $listed->keys()->diff($admitted->keys())->all();
            $this->leaveRefusedCompany($user->fresh() ?? $user, $refused, $admitted->keys()->all(), in_array($active, $refused, true));
        });
    }

    /** Whether InfoDot lets this company use the platform: `entitled` exactly true, or the key absent (older InfoDot). */
    public static function admits(mixed $team): bool
    {
        return is_array($team) && (! array_key_exists('entitled', $team) || $team['entitled'] === true);
    }

    /**
     * What InfoDot said about the person's personal workspace for this platform: true, false, or null when it said
     * nothing (no personal workspace listed, or an InfoDot from before R-50).
     *
     * @param  array<string, mixed>  $identity
     */
    public static function personalEntitled(array $identity): ?bool
    {
        $personal = collect($identity['teams'] ?? [])->first(fn ($t) => is_array($t) && ($t['personal'] ?? false) === true);

        return is_array($personal) && is_bool($personal['entitled'] ?? null) ? $personal['entitled'] : null;
    }

    /**
     * True when InfoDot marked the person's companies and admitted none of them: there is nowhere to sign them in to.
     *
     * @param  array<string, mixed>  $identity
     */
    public static function admitsNone(array $identity): bool
    {
        $teams = collect($identity['teams'] ?? [])->filter(fn ($t) => is_array($t));

        return $teams->contains(fn ($t) => array_key_exists('entitled', $t)) && ! $teams->contains(fn ($t) => self::admits($t));
    }

    /**
     * The reason and the company to name on the "not switched on" page: the company InfoDot says is active, else the first.
     *
     * @param  array<string, mixed>  $identity
     * @return array{reason: string|null, company: string|null}
     */
    public static function refusal(array $identity): array
    {
        $teams = collect($identity['teams'] ?? [])->filter(fn ($t) => is_array($t));
        $team = $teams->firstWhere('uuid', $identity['current_team_uuid'] ?? null) ?? $teams->first(fn ($t) => ! ($t['personal'] ?? false)) ?? $teams->first();

        return [
            'reason' => is_string($team['access'] ?? null) ? $team['access'] : null,
            'company' => is_array($team) && ! ($team['personal'] ?? false) && is_string($team['name'] ?? null) ? $team['name'] : null,
        ];
    }

    /**
     * A person whose current team is a company InfoDot has just refused, or whose active company in InfoDot is one,
     * is moved to a company it admits; with none, a person standing in a refused company goes to their personal
     * team. The refused company and its membership stay untouched.
     *
     * @param  list<string>  $refused
     * @param  list<string>  $admitted
     */
    private function leaveRefusedCompany(User $user, array $refused, array $admitted, bool $activeRefused): void
    {
        $current = $user->currentTeam;
        $standingInRefused = $current && in_array($current->infodot_team_uuid, $refused, true);

        if (! $standingInRefused && ! $activeRefused) {
            return;
        }

        $next = $user->allTeams()->first(fn ($team) => in_array($team->infodot_team_uuid, $admitted, true));

        if (! $standingInRefused) {
            if ($next) {
                $user->switchTeam($next);
            }

            return;
        }

        $next ??= $user->allTeams()->first(fn ($team) => (bool) $team->personal_team);

        if ($next) {
            $user->switchTeam($next);
        } else {
            $user->forceFill(['current_team_id' => null])->save();
        }
    }

    /** Membership checks read relations cached on the model; reload after changing them. */
    private function forgetCachedTeams(User $user): void
    {
        $user->unsetRelation('teams')->unsetRelation('ownedTeams')->unsetRelation('currentTeam');
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private function apply(User $user, string $uuid, array $info): void
    {
        $role = (string) ($info['role'] ?? '');
        $team = Team::where('infodot_team_uuid', $uuid)->first();

        if (! $team) {
            if (! in_array($role, ['owner', 'admin'], true)) {
                return;
            }

            $team = Team::forceCreate([
                'user_id' => $user->id,
                'name' => (string) ($info['name'] ?? 'Company'),
                'personal_team' => false,
                'infodot_team_uuid' => $uuid,
            ]);
        } elseif (filled($info['name'] ?? null) && $team->name !== $info['name']) {
            $team->forceFill(['name' => $info['name']])->save();
        }

        if ($team->user_id === $user->id) {
            return; // the local owner needs no membership row
        }

        $localRole = self::localRole($role);

        if ($user->belongsToTeam($team)) {
            $team->users()->updateExistingPivot($user->id, ['role' => $localRole]);
        } else {
            $team->users()->attach($user->id, ['role' => $localRole]);
        }
    }

    /**
     * @param  list<string>  $keep
     */
    private function revoke(User $user, array $keep): void
    {
        $stale = $user->allTeams()->filter(fn ($team) => filled($team->infodot_team_uuid) && ! in_array($team->infodot_team_uuid, $keep, true));

        foreach ($stale as $team) {
            if ($team->user_id === $user->id) {
                // Cannot detach a team's owner; surface it instead of leaving it silent.
                Log::warning('InfoDot no longer lists a company this person owns locally.', ['team_id' => $team->id, 'user_id' => $user->id]);

                continue;
            }

            $team->users()->detach($user->id);

            if ($user->current_team_id === $team->id) {
                $user->forceFill(['current_team_id' => null])->save();
            }
        }
    }

    /**
     * The role this app gives an InfoDot role. Anything not named here, including
     * a role InfoDot adds later, is the least powerful role (read-only), never more.
     */
    public static function localRole(string $infodotRole): string
    {
        return match ($infodotRole) {
            'owner', 'admin' => 'admin',
            'editor' => 'editor',
            default => 'viewer',
        };
    }
}
