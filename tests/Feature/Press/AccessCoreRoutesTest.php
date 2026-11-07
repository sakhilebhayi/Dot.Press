<?php

namespace Tests\Feature\Press;

use App\Models\DeckChange;
use App\Models\DeckReview;
use App\Services\Press\DeckHash;
use App\Services\Press\Reviews;
use App\Services\Press\VersionService;

/** The document, operations, changes, presence and version routes obey DeckAccess like everything else. */
class AccessCoreRoutesTest extends AccessTestCase
{
    private function op(string $title = 'Edited'): array
    {
        return ['client' => 'tab-1', 'key' => 'k'.uniqid(), 'ops' => [['op' => 'slide.set', 'id' => $this->slide->id, 'title' => $title]]];
    }

    public function test_reads_need_view_and_writes_need_update_for_each_kind_of_person(): void
    {
        $people = $this->people($this->deck);
        $id = $this->deck->id;
        $version = VersionService::snapshot($this->deck, $this->owner, 'manual', 'base');

        foreach ($this->expectations() as $key => [$role, , $update]) {
            $see = $role !== null;
            $this->actingAs($people[$key]);

            $this->getJson("/api/decks/{$id}/document")->assertStatus($see ? 200 : 404);
            $this->getJson("/api/decks/{$id}/changes?after=0")->assertStatus($see ? 200 : 404);
            $this->getJson("/api/decks/{$id}/versions")->assertStatus($see ? 200 : 404);
            $this->getJson("/api/decks/{$id}/versions/{$version->id}")->assertStatus($see ? 200 : 404);
            $this->postJson("/api/decks/{$id}/presence", ['client' => 'c'.$people[$key]->id])->assertStatus($see ? 200 : 404);

            $this->postJson("/api/decks/{$id}/ops", $this->op())->assertStatus($update ? 200 : ($see ? 403 : 404));
            $this->postJson("/api/decks/{$id}/versions", ['label' => 'x'])->assertStatus($update ? 201 : ($see ? 403 : 404));
            $this->postJson("/api/decks/{$id}/versions/{$version->id}/restore")->assertStatus($update ? 200 : ($see ? 403 : 404));
        }
    }

    public function test_a_viewer_posting_ops_changes_nothing(): void
    {
        $before = DeckChange::count();
        $hash = DeckHash::compute($this->deck);

        foreach ([$this->viewer, $this->sharedWith('viewer'), $this->sharedWith('commenter', null, 'C')] as $person) {
            $this->actingAs($person)->postJson("/api/decks/{$this->deck->id}/ops", $this->op('Vandal'))->assertForbidden();
        }

        $this->assertSame($before, DeckChange::count());
        $this->assertSame($hash, DeckHash::compute($this->deck->refresh()));
        $this->assertSame('Slide one', $this->slide->refresh()->title);
    }

    public function test_a_share_editor_outside_the_team_can_edit_and_a_team_editor_can_too(): void
    {
        $sharee = $this->sharedWith('editor');

        $this->actingAs($sharee)->postJson("/api/decks/{$this->deck->id}/ops", $this->op('By sharee'))->assertOk();
        $this->assertSame('By sharee', $this->slide->refresh()->title);
        $this->actingAs($this->editor)->getJson("/api/decks/{$this->deck->id}/changes?after=0")->assertOk()->assertJsonPath('changes.0.user.id', $sharee->id);
    }

    public function test_private_decks_are_404_on_every_core_route_for_a_team_admin_and_open_for_the_named(): void
    {
        $private = $this->makeDeck('private', 'Quiet');
        $slideId = $private->slides()->first()->id;
        $named = $this->sharedWith('editor', $private, 'Named');
        $ops = ['client' => 't', 'key' => 'k'.uniqid(), 'ops' => [['op' => 'slide.set', 'id' => $slideId, 'title' => 'Hi']]];

        $this->actingAs($this->admin);
        $this->getJson("/api/decks/{$private->id}/document")->assertNotFound();
        $this->postJson("/api/decks/{$private->id}/ops", $ops)->assertNotFound();
        $this->getJson("/api/decks/{$private->id}/changes")->assertNotFound();
        $this->postJson("/api/decks/{$private->id}/versions")->assertNotFound();
        $this->postJson("/api/decks/{$private->id}/presence", ['client' => 'c'])->assertNotFound();
        $this->getJson("/api/slides/{$slideId}")->assertNotFound();

        $this->actingAs($named);
        $this->getJson("/api/decks/{$private->id}/document")->assertOk()->assertJsonPath('slides.0.id', $slideId);
        $this->postJson("/api/decks/{$private->id}/ops", $ops)->assertOk();
        $this->getJson("/api/slides/{$slideId}")->assertOk();
    }

    public function test_editing_through_ops_withdraws_an_approval(): void
    {
        $reviewer = $this->member('editor', 'Reviewer');
        $this->actingAs($this->owner)->postJson("/api/decks/{$this->deck->id}/reviews", ['reviewer_ids' => [$reviewer->id]])->assertCreated();
        $this->actingAs($reviewer)->postJson("/api/decks/{$this->deck->id}/reviews/".DeckReview::first()->id.'/decision', ['decision' => 'approved'])
            ->assertOk()->assertJsonPath('status', 'approved');

        $this->assertDatabaseHas('deck_versions', ['deck_id' => $this->deck->id, 'kind' => 'approved']);

        $this->actingAs($this->editor)->postJson("/api/decks/{$this->deck->id}/ops", $this->op('After approval'))->assertOk();

        $this->assertSame('draft', $this->deck->refresh()->status);
        $this->assertSame('changed_since_approval', Reviews::effectiveStatus($this->deck));
        $this->actingAs($this->viewer)->getJson("/api/decks/{$this->deck->id}/document")->assertOk()->assertJsonPath('deck.status', 'changed_since_approval');
    }
}
