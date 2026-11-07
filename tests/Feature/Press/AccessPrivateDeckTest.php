<?php

namespace Tests\Feature\Press;

use App\Models\Deck;
use App\Models\DeckActivity;
use App\Models\DeckComment;
use App\Models\DeckReview;
use App\Models\DeckShare;
use App\Models\Element;
use App\Models\Project;
use App\Models\Slide;
use App\Services\Press\DeckAccess;

/** A `private` deck is invisible to everyone but its creator and the people it was shared with, in every query. */
class AccessPrivateDeckTest extends AccessTestCase
{
    private Deck $private;

    protected function setUp(): void
    {
        parent::setUp();

        $this->private = $this->makeDeck('private', 'Secret plan');
        DeckComment::create(['deck_id' => $this->private->id, 'user_id' => $this->editor->id, 'body' => 'private note']);
        DeckReview::create(['deck_id' => $this->private->id, 'requested_by' => $this->editor->id, 'reviewer_id' => $this->editor->id]);
        DeckActivity::create(['deck_id' => $this->private->id, 'user_id' => $this->editor->id, 'event' => 'created', 'created_at' => now()]);
        Element::create(['slide_id' => $this->private->slides()->first()->id, 'client_id' => 'e1', 'type' => 'text', 'sort_order' => 0]);
    }

    public function test_team_owner_and_admin_do_not_see_a_private_deck(): void
    {
        foreach ([$this->owner, $this->admin, $this->viewer, $this->member('editor', 'Other')] as $person) {
            $this->assertNull(DeckAccess::roleFor($person, $this->private));

            $this->actingAs($person);
            $this->assertFalse(Deck::whereKey($this->private->id)->exists());
            $this->assertSame(0, Slide::where('deck_id', $this->private->id)->count());
            $this->assertSame(0, DeckComment::where('deck_id', $this->private->id)->count());
            $this->assertSame(0, DeckReview::where('deck_id', $this->private->id)->count());
            $this->assertSame(0, DeckActivity::where('deck_id', $this->private->id)->count());
            $this->assertSame(0, Element::count());
            $this->assertSame(0, DeckShare::where('deck_id', $this->private->id)->count());

            $this->getJson("/api/decks/{$this->private->id}")->assertNotFound();
            $this->getJson("/api/decks/{$this->private->id}/comments")->assertNotFound();
            $this->getJson("/api/decks/{$this->private->id}/activity")->assertNotFound();
            $this->getJson("/api/decks/{$this->private->id}/sharing")->assertNotFound();
            $this->getJson("/api/slides?deck_id={$this->private->id}")->assertNotFound();
            $this->getJson('/api/slides')->assertOk()->assertJsonPath('total', 1);
            $this->deleteJson("/api/decks/{$this->private->id}")->assertNotFound();
        }
    }

    public function test_relations_and_eager_loads_through_a_private_deck_are_hidden_too(): void
    {
        $this->actingAs($this->admin);

        $this->assertSame(['Quarterly deck'], $this->project->decks()->pluck('title')->all());
        $this->assertSame(1, Project::with('decks')->find($this->project->id)->decks->count());
        $this->assertSame(1, Project::withCount('decks')->find($this->project->id)->decks_count);
        $this->assertSame(['Slide one'], Slide::pluck('title')->unique()->values()->all());
        $this->assertSame(1, Slide::count());
        $this->assertSame(0, Deck::has('comments')->count());
    }

    public function test_the_creator_and_named_people_do_see_it(): void
    {
        $viewer = $this->sharedWith('viewer', $this->private, 'Named Viewer');

        $this->actingAs($this->editor);
        $this->assertSame('owner', DeckAccess::roleFor($this->editor, $this->private));
        $this->getJson("/api/decks/{$this->private->id}")->assertOk();
        $this->assertSame(1, DeckComment::where('deck_id', $this->private->id)->count());

        $this->actingAs($viewer);
        $this->getJson("/api/decks/{$this->private->id}")->assertOk();
        $this->assertSame(1, Slide::where('deck_id', $this->private->id)->count());
        $this->assertSame(1, DeckComment::where('deck_id', $this->private->id)->count());
        $this->assertSame(0, Deck::whereKey($this->deck->id)->count(), 'a sharee outside the team sees nothing else');
        $this->postJson("/api/decks/{$this->private->id}/comments", ['body' => 'x'])->assertForbidden();
    }

    public function test_a_sharee_can_be_raised_and_a_team_admin_can_be_shared_in(): void
    {
        DeckShare::create(['deck_id' => $this->private->id, 'user_id' => $this->admin->id, 'role' => 'commenter']);

        $this->assertSame('commenter', DeckAccess::roleFor($this->admin, $this->private));
        $this->assertFalse(DeckAccess::can($this->admin, $this->private, 'manage'), 'on a private deck even an admin only has the shared role');
        $this->actingAs($this->admin)->getJson("/api/decks/{$this->private->id}")->assertOk();
    }

    public function test_making_a_deck_private_hides_it_and_making_it_team_again_shows_it(): void
    {
        $this->actingAs($this->editor)->putJson("/api/decks/{$this->deck->id}/sharing", ['visibility' => 'private'])->assertOk();
        $this->actingAs($this->admin)->getJson("/api/decks/{$this->deck->id}")->assertNotFound();

        $this->actingAs($this->editor)->putJson("/api/decks/{$this->deck->id}/sharing", ['visibility' => 'team'])->assertOk();
        $this->actingAs($this->admin)->getJson("/api/decks/{$this->deck->id}")->assertOk();
    }

    public function test_the_people_list_for_a_private_deck_is_the_creator_and_the_shares(): void
    {
        $this->sharedWith('commenter', $this->private, 'Named Commenter');

        $names = collect(DeckAccess::people($this->private))->pluck('name')->sort()->values()->all();

        $this->assertSame(['Edie Editor', 'Named Commenter'], $names);
    }
}
