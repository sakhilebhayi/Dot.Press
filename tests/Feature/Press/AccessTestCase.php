<?php

namespace Tests\Feature\Press;

use App\Models\Asset;
use App\Models\Deck;
use App\Models\DeckShare;
use App\Models\Project;
use App\Models\Slide;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One team with every kind of member, an outsider team, and people who only hold a share. Helpers build decks
 * with any visibility and create people with a given relationship to the deck.
 */
abstract class AccessTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $owner;       // owns the team

    protected User $admin;       // team admin

    protected User $editor;      // team editor, creates the decks

    protected User $viewer;      // team viewer

    protected User $outsider;    // member of another team only

    protected Team $team;

    protected Team $otherTeam;

    protected Project $project;

    protected Deck $deck;        // team visibility, created by $editor

    protected Slide $slide;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->withPersonalTeam()->create(['name' => 'Olive Owner']);
        $this->team = $this->owner->currentTeam;
        $this->admin = $this->member('admin', 'Adam Admin');
        $this->editor = $this->member('editor', 'Edie Editor');
        $this->viewer = $this->member('viewer', 'Vera Viewer');

        $stranger = User::factory()->withPersonalTeam()->create(['name' => 'Otto Outsider']);
        $this->otherTeam = $stranger->currentTeam;
        $this->outsider = $stranger;

        $this->project = Project::create([
            'user_id' => $this->owner->id, 'team_id' => $this->team->id, 'name' => 'Board', 'slug' => 'board-'.uniqid(),
        ]);
        $this->deck = $this->makeDeck('team', 'Quarterly deck');
        $this->slide = $this->deck->slides()->first();
    }

    protected function member(string $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $this->team->users()->attach($user, ['role' => $role]);
        $user->switchTeam($this->team);

        return $user->fresh();
    }

    protected function makeDeck(string $visibility = 'team', string $title = 'A deck', ?User $creator = null): Deck
    {
        $deck = new Deck(['project_id' => $this->project->id, 'title' => $title, 'sort_order' => 0]);
        $deck->forceFill(['user_id' => ($creator ?? $this->editor)->id, 'visibility' => $visibility])->save();

        Slide::create([
            'deck_id' => $deck->id, 'title' => 'Slide one', 'sort_order' => 0, 'canvas_state' => ['elements' => []],
            'els' => [['id' => 'title1', 'type' => 'text', 'x' => 10, 'y' => 10, 'w' => 100, 'h' => 40]],
        ]);

        return $deck->refresh();
    }

    /** A user with no team role who holds a share on the deck. */
    protected function sharedWith(string $role, ?Deck $deck = null, string $name = 'Sam Shared'): User
    {
        $user = User::factory()->withPersonalTeam()->create(['name' => $name]);
        DeckShare::create(['deck_id' => ($deck ?? $this->deck)->id, 'user_id' => $user->id, 'role' => $role]);

        return $user->fresh();
    }

    protected function asset(string $name = 'pic.png', ?Project $project = null): Asset
    {
        return Asset::create([
            'project_id' => ($project ?? $this->project)->id,
            'uploaded_by' => $this->owner->id,
            'disk' => 'local',
            'path' => 'projects/'.($project ?? $this->project)->id.'/assets/'.$name,
            'original_name' => $name,
            'mime_type' => 'image/png',
            'size' => 4,
        ]);
    }

    /** @return array<string, array{0: ?string, 1: bool, 2: bool, 3: bool}> */
    protected function expectations(): array
    {
        // person => [role, comment, update, manage]
        return [
            'creator' => ['owner', true, true, true],
            'owner' => ['owner', true, true, true],
            'admin' => ['owner', true, true, true],
            'editor' => ['editor', true, true, false],   // an editor who did not create the deck
            'viewer' => ['viewer', true, false, false],  // team viewers may comment
            'share_viewer' => ['viewer', false, false, false],
            'share_commenter' => ['commenter', true, false, false],
            'share_editor' => ['editor', true, true, false],
            'outsider' => [null, false, false, false],
        ];
    }

    /** @return array<string, User> */
    protected function people(Deck $deck): array
    {
        $other = $this->member('editor', 'Other Editor');

        return [
            'creator' => $this->editor,
            'owner' => $this->owner,
            'admin' => $this->admin,
            'editor' => $other,
            'viewer' => $this->viewer,
            'share_viewer' => $this->sharedWith('viewer', $deck, 'Share Viewer'),
            'share_commenter' => $this->sharedWith('commenter', $deck, 'Share Commenter'),
            'share_editor' => $this->sharedWith('editor', $deck, 'Share Editor'),
            'outsider' => $this->outsider,
        ];
    }
}
