<?php

namespace Tests\Feature;

use App\Models\Deck;
use App\Models\Project;
use App\Models\Slide;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end coverage for the actual point of team-scoping: a teammate who
 * did not create a project/deck/slide -- but shares a team with whoever
 * did -- can view and edit it, while someone outside that team cannot.
 */
class TeamScopedDeckSharingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_teammate_can_edit_a_deck_they_did_not_create(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $teammate = User::factory()->create();
        $owner->currentTeam->users()->attach($teammate, ['role' => 'editor']);
        $teammate->switchTeam($owner->currentTeam);

        $project = Project::create([
            'user_id' => $owner->id,
            'team_id' => $owner->currentTeam->id,
            'name' => 'Team Project',
            'slug' => 'team-project',
        ]);

        $deck = Deck::create([
            'project_id' => $project->id,
            'title' => 'Team Deck',
            'sort_order' => 0,
        ]);

        $slide = Slide::create([
            'deck_id' => $deck->id,
            'title' => 'Slide 1',
            'sort_order' => 0,
            'canvas_state' => ['elements' => []],
        ]);

        $this->actingAs($teammate)
            ->postJson('/api/decks/'.$deck->id.'/ops', [
                'client' => 'tab-1',
                'key' => 'team-1',
                'ops' => [['op' => 'el.add', 'slide' => $slide->id, 'el' => ['id' => 'el-1', 'type' => 'shape', 'x' => 10, 'y' => 10, 'w' => 50, 'h' => 50]]],
            ])
            ->assertOk()
            ->assertJsonPath('skipped', []);

        $this->assertSame('el-1', $slide->fresh()->els[0]['id']);
    }

    public function test_someone_outside_the_team_still_cannot_see_the_deck(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $outsider = User::factory()->withPersonalTeam()->create();

        $project = Project::create([
            'user_id' => $owner->id,
            'team_id' => $owner->currentTeam->id,
            'name' => 'Team Project',
            'slug' => 'team-project-2',
        ]);

        $deck = Deck::create([
            'project_id' => $project->id,
            'title' => 'Team Deck',
            'sort_order' => 0,
        ]);

        $slide = Slide::create([
            'deck_id' => $deck->id,
            'title' => 'Slide 1',
            'sort_order' => 0,
            'canvas_state' => ['elements' => []],
        ]);

        $this->actingAs($outsider)
            ->postJson('/api/decks/'.$deck->id.'/ops', ['client' => 'tab-1', 'key' => 'out-1', 'ops' => [['op' => 'slide.remove', 'id' => $slide->id]]])
            ->assertNotFound();
        $this->actingAs($outsider)->getJson('/api/slides/'.$slide->id)->assertNotFound();
    }
}
