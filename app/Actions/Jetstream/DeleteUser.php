<?php

namespace App\Actions\Jetstream;

use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Contracts\DeletesTeams;
use Laravel\Jetstream\Contracts\DeletesUsers;

class DeleteUser implements DeletesUsers
{
    /**
     * Create a new action instance.
     */
    public function __construct(protected DeletesTeams $deletesTeams) {}

    /**
     * Delete the given user.
     */
    public function delete(User $user): void
    {
        DB::transaction(function () use ($user) {
            $this->deleteTeams($user);
            $this->handOverProjects($user);
            $user->deleteProfilePhoto();
            $user->tokens->each->delete();
            $user->delete();
        });
    }

    /**
     * The shared workspace project of a team belongs, on paper, to whoever made the first deck, and a project row is
     * deleted with its creator. Without this a member leaving would take every deck and picture of the team with
     * them. Projects of teams the person does not own pass to that team's owner; the ones in teams they owned are
     * already gone with the team.
     */
    protected function handOverProjects(User $user): void
    {
        Project::withoutGlobalScopes()->where('user_id', $user->id)->with('team')->get()->each(function (Project $project) use ($user): void {
            $owner = $project->team?->user_id;

            if ($owner !== null && (int) $owner !== $user->id) {
                $project->forceFill(['user_id' => $owner])->save();
            }
        });
    }

    /**
     * Delete the teams and team associations attached to the user.
     */
    protected function deleteTeams(User $user): void
    {
        $user->teams()->detach();

        $user->ownedTeams->each(function (Team $team) {
            $this->deletesTeams->delete($team);
        });
    }
}
