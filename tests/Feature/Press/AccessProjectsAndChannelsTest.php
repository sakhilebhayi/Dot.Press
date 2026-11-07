<?php

namespace Tests\Feature\Press;

use App\Broadcasting\SlideChannelAuthorizer;
use App\Models\Project;

class AccessProjectsAndChannelsTest extends AccessTestCase
{
    public function test_a_sharee_outside_the_team_sees_the_project_that_holds_the_deck_and_only_that_deck(): void
    {
        $this->makeDeck('team', 'Not shared');
        $sharee = $this->sharedWith('viewer');

        $this->actingAs($sharee);
        $this->assertTrue($sharee->can('view', $this->project));
        $this->assertFalse($sharee->can('update', $this->project));
        $this->assertFalse($sharee->can('delete', $this->project));
        $this->getJson("/api/projects/{$this->project->id}")->assertOk();
        $this->assertSame(['Quarterly deck'], $this->project->decks()->pluck('title')->all());

        $this->getJson('/api/decks?project_id='.$this->project->id)->assertOk()->assertJsonCount(1);
        $this->putJson("/api/projects/{$this->project->id}", ['name' => 'Mine now'])->assertForbidden();
    }

    public function test_an_outsider_without_a_share_does_not_see_the_project(): void
    {
        $this->actingAs($this->outsider)->getJson("/api/projects/{$this->project->id}")->assertNotFound();
        $this->actingAs($this->outsider);
        $this->assertSame(0, Project::where('id', $this->project->id)->count());
    }

    public function test_removing_the_share_removes_the_project_again(): void
    {
        $sharee = $this->sharedWith('viewer');
        $this->actingAs($this->owner)->deleteJson("/api/decks/{$this->deck->id}/sharing/people/{$sharee->id}")->assertNoContent();

        $this->actingAs($sharee)->getJson("/api/projects/{$this->project->id}")->assertNotFound();
        $this->getJson("/api/decks/{$this->deck->id}")->assertNotFound();
    }

    public function test_the_slide_channel_follows_deck_access(): void
    {
        $private = $this->makeDeck('private', 'Quiet');
        $privateSlide = $private->slides()->first();
        $sharee = $this->sharedWith('viewer', $private);

        $this->assertTrue(SlideChannelAuthorizer::authorize($this->viewer, $this->slide->id));
        $this->assertFalse(SlideChannelAuthorizer::authorize($this->outsider, $this->slide->id));

        $this->assertTrue(SlideChannelAuthorizer::authorize($this->editor, $privateSlide->id));
        $this->assertTrue(SlideChannelAuthorizer::authorize($sharee, $privateSlide->id));
        $this->assertFalse(SlideChannelAuthorizer::authorize($this->admin, $privateSlide->id), 'not even a team admin');
        $this->assertFalse(SlideChannelAuthorizer::authorize($sharee, $this->slide->id));
    }
}
