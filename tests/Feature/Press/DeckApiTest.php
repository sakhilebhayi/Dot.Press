<?php

namespace Tests\Feature\Press;

use App\Models\Deck;
use App\Models\DeckActivity;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeckApiTest extends TestCase
{
    use MakesDecks;
    use RefreshDatabase;

    public function test_a_deck_is_created_in_the_team_workspace_with_one_blank_slide_and_the_creator_as_owner(): void
    {
        $user = $this->owner();

        $response = $this->actingAs($user)->postJson('/api/decks', ['title' => '  Q3 review '])->assertCreated();

        $deck = Deck::find($response->json('id'));
        $this->assertSame('Q3 review', $deck->title);
        $this->assertSame($user->id, $deck->user_id);
        $this->assertSame([1920, 1080], [$deck->w, $deck->h]);
        $this->assertSame('Dot.Press Workspace', $deck->project->name);
        $this->assertSame($user->currentTeam->id, $deck->project->team_id);
        $this->assertSame('slate', $deck->theme['id']);
        $this->assertSame('draft', $deck->status);
        $this->assertSame(1, $deck->slides()->count());
        $this->assertSame([], $deck->slides()->first()->els);
        $this->assertTrue(DeckActivity::where('deck_id', $deck->id)->where('event', 'created')->exists());

        // The workspace project is reused, not duplicated.
        $this->actingAs($user)->postJson('/api/decks', ['title' => 'Second'])->assertCreated();
        $this->assertSame(1, Project::where('name', 'Dot.Press Workspace')->count());
    }

    public function test_size_presets_and_custom_sizes(): void
    {
        $user = $this->owner();

        foreach ([['4:3', 1440, 1080], ['portrait', 1080, 1920], ['16:9', 1920, 1080]] as [$size, $w, $h]) {
            $this->actingAs($user)->postJson('/api/decks', ['title' => $size, 'size' => $size])->assertCreated()->assertJsonPath('w', $w)->assertJsonPath('h', $h);
        }
        $this->actingAs($user)->postJson('/api/decks', ['title' => 'c', 'size' => 'custom', 'w' => 800, 'h' => 600])->assertCreated()->assertJsonPath('w', 800);
        $this->actingAs($user)->postJson('/api/decks', ['title' => 'c', 'size' => 'custom', 'w' => 100, 'h' => 600])->assertStatus(422);
        $this->actingAs($user)->postJson('/api/decks', ['title' => 'c', 'size' => 'custom'])->assertStatus(422);
        $this->actingAs($user)->postJson('/api/decks', ['title' => 'c', 'size' => 'huge'])->assertStatus(422);
        $this->actingAs($user)->postJson('/api/decks', ['title' => ''])->assertStatus(422);
    }

    public function test_a_theme_is_validated_and_filled_from_the_default(): void
    {
        $user = $this->owner();

        $deck = $this->actingAs($user)->postJson('/api/decks', ['title' => 'T', 'theme' => ['name' => 'Ink', 'c' => ['accent' => '#00ff00']]])->assertCreated();
        $this->assertSame('#00ff00', $deck->json('theme.c.accent'));
        $this->assertSame('#111827', $deck->json('theme.c.ink'));

        $this->actingAs($user)->postJson('/api/decks', ['title' => 'T', 'theme' => ['c' => ['accent' => 'javascript:1']]])->assertStatus(422);
    }

    public function test_a_template_key_is_ignored_without_a_template_library(): void
    {
        $user = $this->owner();

        $this->actingAs($user)->postJson('/api/decks', ['title' => 'T', 'template' => 'pitch'])->assertCreated();
        $this->assertSame(1, Deck::count());
    }

    public function test_creating_in_another_teams_project_is_refused_and_a_viewer_cannot_create(): void
    {
        $user = $this->owner();
        $stranger = $this->owner();
        $theirs = $this->deckFor($stranger)->project;
        $viewer = $this->teammate($user, 'viewer');
        $mine = $this->deckFor($user)->project;

        $this->actingAs($user)->postJson('/api/decks', ['title' => 'T', 'project_id' => $theirs->id])->assertNotFound();
        $this->actingAs($viewer)->postJson('/api/decks', ['title' => 'T', 'project_id' => $mine->id])->assertForbidden();
        $this->actingAs($viewer)->postJson('/api/decks', ['title' => 'T'])->assertForbidden();
        $this->actingAs($user)->postJson('/api/decks', ['title' => 'T', 'project_id' => $mine->id])->assertCreated();
    }

    public function test_only_the_title_changes_through_update_and_it_is_logged_as_an_operation(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user, 'Old');

        $this->actingAs($user)->patchJson("/api/decks/{$deck->id}", ['title' => 'New', 'w' => 100, 'status' => 'approved'])->assertOk()->assertJsonPath('title', 'New');

        $fresh = $deck->fresh();
        $this->assertSame([1920, 1080, 'draft'], [$fresh->w, $fresh->h, $fresh->status]);
        $this->assertSame(1, $fresh->rev);
        $this->assertDatabaseHas('deck_changes', ['deck_id' => $deck->id, 'client' => 'api']);
        $this->actingAs($user)->patchJson("/api/decks/{$deck->id}", ['title' => ''])->assertStatus(422);
    }

    public function test_show_returns_the_deck_with_slide_summaries_and_personal_flags(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user, 'D', 2);

        $this->actingAs($user)->postJson("/api/decks/{$deck->id}/favourite")->assertOk()->assertJsonPath('favourite', true);

        $this->actingAs($user)->getJson("/api/decks/{$deck->id}")->assertOk()
            ->assertJsonPath('favourite', true)
            ->assertJsonPath('owner.id', $user->id)
            ->assertJsonCount(2, 'slides')
            ->assertJsonMissingPath('slides.0.els');
    }

    public function test_duplicate_copies_slides_sections_theme_and_size_with_a_new_title(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user, 'Pitch', 2);
        [$one, $two] = $deck->slides()->get()->all();
        $this->ops($user, $deck, [
            ['op' => 'deck.set', 'theme' => ['name' => 'Mine', 'c' => ['accent' => '#ff00ff']]],
            ['op' => 'section.add', 'id' => 's1', 'title' => 'Intro'],
            ['op' => 'slide.set', 'id' => $one->id, 'section_id' => 's1', 'notes' => 'n'],
            ['op' => 'el.add', 'slide' => $one->id, 'el' => $this->shape('a', ['link' => '#slide:'.$two->id])],
        ])->assertOk();
        $this->ops($user, $deck, [['op' => 'deck.resize', 'w' => 1440, 'h' => 1080, 'mode' => 'fit']])->assertOk();

        $copyId = $this->actingAs($user)->postJson("/api/decks/{$deck->id}/duplicate")->assertCreated()->assertJsonPath('title', 'Copy of Pitch')->json('id');

        $copy = Deck::find($copyId);
        $this->assertSame([1440, 1080], [$copy->w, $copy->h]);
        $this->assertSame('#ff00ff', $copy->theme['c']['accent']);
        $this->assertSame([['id' => 's1', 'title' => 'Intro']], $copy->sections);
        $this->assertSame($user->id, $copy->user_id);
        $this->assertSame(0, $copy->rev);
        $slides = $copy->slides()->get();
        $this->assertCount(2, $slides);
        $this->assertNotSame($one->id, $slides[0]->id);
        $this->assertSame('s1', $slides[0]->section_id);
        $this->assertSame('n', $slides[0]->notes);
        // The link to the second slide now points at the copy's second slide, not the original's.
        $this->assertSame('#slide:'.$slides[1]->id, $slides[0]->els[0]['link']);
        $this->assertSame('#slide:'.$two->id, $one->fresh()->els[0]['link']);
    }

    public function test_destroy_archives_and_permanent_delete_needs_an_archived_deck(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user);

        $this->actingAs($user)->deleteJson("/api/decks/{$deck->id}?permanent=1")->assertStatus(422);
        $this->assertNotNull(Deck::find($deck->id));

        $this->actingAs($user)->deleteJson("/api/decks/{$deck->id}")->assertNoContent();
        $this->assertNotNull($deck->fresh()->archived_at);
        $this->assertTrue(DeckActivity::where('deck_id', $deck->id)->where('event', 'archived')->exists());

        // An archived deck cannot be edited until it is restored.
        $this->ops($user, $deck, [['op' => 'deck.set', 'title' => 'x']])->assertStatus(409);
        $this->actingAs($user)->postJson("/api/decks/{$deck->id}/unarchive")->assertOk();
        $this->assertNull($deck->fresh()->archived_at);

        $this->actingAs($user)->deleteJson("/api/decks/{$deck->id}")->assertNoContent();
        $this->actingAs($user)->deleteJson("/api/decks/{$deck->id}?permanent=1")->assertNoContent();
        $this->assertNull(Deck::find($deck->id));
        $this->assertSame(0, DB::table('slides')->where('deck_id', $deck->id)->count());
    }

    public function test_favourite_toggles_and_touch_records_the_last_opened_time_per_person(): void
    {
        $user = $this->owner();
        $other = $this->teammate($user);
        $deck = $this->deckFor($user);

        $this->actingAs($user)->postJson("/api/decks/{$deck->id}/favourite")->assertJsonPath('favourite', true);
        $this->actingAs($user)->postJson("/api/decks/{$deck->id}/favourite")->assertJsonPath('favourite', false);
        $this->actingAs($user)->postJson("/api/decks/{$deck->id}/favourite", ['favourite' => true])->assertJsonPath('favourite', true);
        $this->actingAs($user)->postJson("/api/decks/{$deck->id}/touch")->assertOk();

        $this->actingAs($other)->getJson("/api/decks/{$deck->id}")->assertJsonPath('favourite', false)->assertJsonPath('last_opened_at', null);
        $this->assertNotNull($this->actingAs($user)->getJson("/api/decks/{$deck->id}")->json('last_opened_at'));

        // A viewer may star and open too: both are personal.
        $viewer = $this->teammate($user, 'viewer');
        $this->actingAs($viewer)->postJson("/api/decks/{$deck->id}/favourite")->assertOk();
        $this->actingAs($viewer)->postJson("/api/decks/{$deck->id}/touch")->assertOk();
    }

    public function test_the_slide_read_endpoint_returns_one_slide_document(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user);
        $slide = $this->slideFor($deck, 'Hello', 1, [$this->shape('a')]);

        $this->actingAs($user)->getJson("/api/slides/{$slide->id}")->assertOk()
            ->assertJsonPath('title', 'Hello')
            ->assertJsonPath('els.0.id', 'a')
            ->assertJsonPath('deck.w', 1920)
            ->assertJsonPath('rev', 0);
    }
}
