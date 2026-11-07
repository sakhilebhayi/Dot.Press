<?php

namespace Tests\Feature\Press;

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The two signed-in pages: the library (Press/Library) and the editor (Press/Editor), and what each person is told
 * about the deck they open. The editor receives the whole document once so it can draw at once; roles come from
 * DeckAccess, never from the client.
 */
class PageTest extends AccessTestCase
{
    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get("/decks/{$this->deck->id}")->assertRedirect('/login');
    }

    public function test_the_library_page_carries_the_new_flow_inputs(): void
    {
        $this->actingAs($this->editor)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Press/Library')
                ->where('canCreate', true)
                ->where('team.id', $this->team->id)
                ->where('team.name', $this->team->name)
                ->where('ai.mode', 'local')
                ->has('themes', fn (Assert $themes) => $themes->each(fn (Assert $theme) => $theme
                    ->has('id')->has('name')->has('c')->has('t')->has('s')->etc()))
            );
    }

    public function test_a_team_viewer_sees_the_library_but_cannot_create(): void
    {
        $this->actingAs($this->viewer)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Press/Library')->where('canCreate', false));
    }

    public function test_the_library_lists_a_decks_theme_so_thumbnails_use_the_right_colours(): void
    {
        $this->actingAs($this->editor);
        $row = collect($this->getJson('/api/decks?per_page=60')->assertOk()->json('data'))->firstWhere('id', $this->deck->id);

        $this->assertNotNull($row);
        $this->assertArrayHasKey('theme', $row);
        $this->assertArrayHasKey('preview', $row);
    }

    public function test_the_owner_opens_the_editor_with_the_document(): void
    {
        $slide = $this->deck->slides()->first();

        $this->actingAs($this->owner)->get("/decks/{$this->deck->id}?slide={$slide->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Press/Editor')
                ->where('role', 'owner')
                ->where('can.update', true)
                ->where('can.manage', true)
                ->where('can.comment', true)
                ->where('can.present', true)
                ->where('can.export', true)
                ->where('me.id', $this->owner->id)
                ->where('me.name', $this->owner->name)
                ->where('ai.mode', 'local')
                ->where('startSlide', (string) $slide->id)
                ->where('document.deck.id', $this->deck->id)
                ->where('document.deck.title', $this->deck->title)
                ->has('document.deck.w')->has('document.deck.theme')->has('document.deck.sections')
                ->has('document.slides', 1)
                ->where('document.slides.0.id', $slide->id)
                ->has('document.slides.0.els')
                ->has('document.seq')
                ->has('document.assets')
            );
    }

    public function test_opening_a_deck_counts_as_recent_and_the_start_slide_defaults_to_nothing(): void
    {
        $this->actingAs($this->owner)->get("/decks/{$this->deck->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('startSlide', null));

        $this->assertNotNull(DB::table('deck_user')->where('deck_id', $this->deck->id)->where('user_id', $this->owner->id)->value('last_opened_at'));
    }

    public function test_a_team_editor_may_edit_but_not_manage(): void
    {
        $other = $this->member('editor', 'Other Editor');

        $this->actingAs($other)->get("/decks/{$this->deck->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('role', 'editor')
                ->where('can.update', true)
                ->where('can.manage', false)
                ->where('can.comment', true));
    }

    public function test_a_viewer_gets_a_read_only_editor_that_can_still_comment_present_and_export(): void
    {
        $this->actingAs($this->viewer)->get("/decks/{$this->deck->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('role', 'viewer')
                ->where('can.update', false)
                ->where('can.manage', false)
                ->where('can.comment', true)
                ->where('can.present', true)
                ->where('can.export', true));
    }

    public function test_shared_people_get_the_role_they_were_given(): void
    {
        $private = $this->makeDeck('private', 'Private deck', $this->owner);

        foreach (['viewer' => [false, false], 'commenter' => [false, true], 'editor' => [true, true]] as $role => [$update, $comment]) {
            $person = $this->sharedWith($role, $private, "Shared {$role}");

            $this->actingAs($person)->get("/decks/{$private->id}")
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('role', $role)
                    ->where('can.update', $update)
                    ->where('can.comment', $comment)
                    ->where('can.manage', false));
        }
    }

    public function test_an_outsider_cannot_open_the_editor_and_leaves_no_trace(): void
    {
        $status = $this->actingAs($this->outsider)->get("/decks/{$this->deck->id}")->status();

        $this->assertContains($status, [403, 404]);
        $this->assertNull(DB::table('deck_user')->where('deck_id', $this->deck->id)->where('user_id', $this->outsider->id)->first());
    }

    public function test_a_private_deck_is_closed_to_the_team_until_it_is_shared(): void
    {
        $private = $this->makeDeck('private', 'Private deck', $this->owner);

        $status = $this->actingAs($this->viewer)->get("/decks/{$private->id}")->status();

        $this->assertContains($status, [403, 404]);
    }

    public function test_an_archived_deck_sends_the_person_back_to_the_library(): void
    {
        $this->deck->forceFill(['archived_at' => now()])->save();

        $this->actingAs($this->owner)->get("/decks/{$this->deck->id}")
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('status', 'That presentation is in the archive.');
    }

    public function test_links_from_before_the_new_editor_still_land_somewhere_useful(): void
    {
        $slide = $this->deck->slides()->first();
        $this->actingAs($this->owner);

        $this->get('/editor')->assertRedirect(route('dashboard'));
        $this->get("/editor/decks/{$this->deck->id}/slides/{$slide->id}")->assertRedirect(route('press.editor', ['deck' => $this->deck, 'slide' => $slide->id]));
        $this->get("/present/decks/{$this->deck->id}/slides/{$slide->id}")->assertRedirect(route('press.player', $this->deck));
    }
}
