<?php

namespace Tests\Feature\Press\Security;

use App\Models\Asset;
use App\Models\Deck;
use App\Models\Project;
use App\Models\Slide;
use App\Models\User;
use Laravel\Jetstream\Contracts\DeletesUsers;
use Tests\Feature\Press\AccessTestCase;

/**
 * No silent data loss: the shared workspace project of a team is created by whoever made the first deck. When that
 * person later deletes their own account, the team's decks and pictures must stay with the team.
 */
class AccountDeletionKeepsTeamWorkTest extends AccessTestCase
{
    public function test_a_members_deleted_account_does_not_take_the_teams_workspace_with_it(): void
    {
        // The editor makes the first deck through the API, which creates the team's workspace project in their name.
        $created = $this->actingAs($this->editor)->postJson('/api/decks', ['title' => 'First deck'])->assertCreated();
        $deckId = $created->json('id');
        $project = Project::withoutGlobalScopes()->findOrFail(Deck::withoutGlobalScopes()->findOrFail($deckId)->project_id);
        $this->assertSame($this->editor->id, (int) $project->user_id, 'precondition: the editor owns the project row');
        $asset = $this->asset('logo.png', $project);

        // A colleague's deck in the same project, and the editor's own account goes away.
        $other = $this->makeDeck('team', 'Colleague deck', $this->owner);
        $other->forceFill(['project_id' => $project->id])->save();

        app(DeletesUsers::class)->delete($this->editor);

        $this->assertNull(User::find($this->editor->id));
        $this->assertNotNull(Project::withoutGlobalScopes()->find($project->id), 'the workspace stays');
        $this->assertSame($this->team->user_id, (int) Project::withoutGlobalScopes()->find($project->id)->user_id, 'and belongs to the team owner now');
        $this->assertNotNull(Deck::withoutGlobalScopes()->find($deckId), 'the editor\'s deck stays with the team');
        $this->assertNotNull(Deck::withoutGlobalScopes()->find($other->id));
        $this->assertSame(1, Slide::withoutGlobalScopes()->where('deck_id', $other->id)->count());
        $this->assertNotNull(Asset::withoutGlobalScopes()->find($asset->id));
        $this->assertNull(Deck::withoutGlobalScopes()->find($deckId)->user_id, 'the deck no longer names a person who is gone');
    }

    public function test_deleting_the_owner_still_deletes_the_team_it_owned(): void
    {
        $deckId = $this->deck->id;
        app(DeletesUsers::class)->delete($this->owner);

        $this->assertNull(Deck::withoutGlobalScopes()->find($deckId), 'a team that was deleted takes its decks with it, as the page says');
    }

    public function test_an_editor_cannot_delete_the_teams_workspace_project_and_nobody_can_while_it_holds_decks(): void
    {
        $projectId = $this->project->id;
        $deckId = $this->deck->id;

        $this->actingAs($this->editor)->deleteJson("/api/projects/{$projectId}")->assertForbidden();
        $this->actingAs($this->viewer)->deleteJson("/api/projects/{$projectId}")->assertForbidden();

        // even an administrator is told to deal with the decks first: one request must not erase a team's whole library
        $this->actingAs($this->admin)->deleteJson("/api/projects/{$projectId}")->assertStatus(409);
        $this->assertNotNull(Project::withoutGlobalScopes()->find($projectId));
        $this->assertNotNull(Deck::withoutGlobalScopes()->find($deckId));

        // an empty project can go
        $empty = Project::create(['user_id' => $this->owner->id, 'team_id' => $this->team->id, 'name' => 'Empty', 'slug' => 'empty-'.uniqid()]);
        $this->actingAs($this->admin)->deleteJson("/api/projects/{$empty->id}")->assertNoContent();
    }
}
