<?php

namespace Tests\Feature\Press;

use App\Models\Project;
use App\Models\Slide;
use Inertia\Testing\AssertableInertia as Assert;

/** The print view (GET /decks/{deck}/print): who may open it and what it sends to the page. */
class PrintViewTest extends AccessTestCase
{
    private function addSlides(): void
    {
        Slide::where('id', $this->slide->id)->update(['notes' => 'SPEAKER-NOTE-ONE']);
        Slide::create([
            'deck_id' => $this->deck->id, 'title' => 'Hidden slide', 'notes' => 'HIDDEN-NOTE', 'sort_order' => 1, 'hidden' => true,
            'canvas_state' => ['elements' => []], 'els' => [['id' => 'h1', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10]],
        ]);
        Slide::create([
            'deck_id' => $this->deck->id, 'title' => 'Third slide', 'notes' => '', 'sort_order' => 2,
            'canvas_state' => ['elements' => []], 'els' => [],
        ]);
    }

    public function test_every_kind_of_person_follows_the_view_permission(): void
    {
        $people = $this->people($this->deck);

        foreach ($this->expectations() as $key => [$role]) {
            $this->actingAs($people[$key]);
            $res = $this->get("/decks/{$this->deck->id}/print");
            if ($role === null) {
                $res->assertNotFound();
            } else {
                $res->assertOk()->assertInertia(fn (Assert $page) => $page->component('Press/Print')->where('deck.id', $this->deck->id));
            }
        }
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get("/decks/{$this->deck->id}/print")->assertRedirect(route('login'));
    }

    public function test_a_private_deck_is_not_printable_by_the_team_but_by_its_creator(): void
    {
        $private = $this->makeDeck('private', 'Secret deck', $this->editor);

        $this->actingAs($this->editor)->get("/decks/{$private->id}/print")->assertOk();
        $this->actingAs($this->viewer)->get("/decks/{$private->id}/print")->assertNotFound();
        $this->actingAs($this->outsider)->get("/decks/{$private->id}/print")->assertNotFound();
    }

    public function test_hidden_slides_are_left_out_and_not_sent_unless_asked_for(): void
    {
        $this->addSlides();
        $this->actingAs($this->viewer);

        $this->get("/decks/{$this->deck->id}/print")->assertInertia(fn (Assert $page) => $page
            ->has('slides', 2)
            ->where('slides.0.title', 'Slide one')
            ->where('slides.1.title', 'Third slide')
            ->where('options.hidden', false));

        $res = $this->get("/decks/{$this->deck->id}/print?hidden=1");
        $res->assertInertia(fn (Assert $page) => $page
            ->has('slides', 3)
            ->where('slides.1.title', 'Hidden slide')
            ->where('options.hidden', true));
    }

    public function test_the_page_does_not_contain_hidden_slide_content_when_they_are_left_out(): void
    {
        $this->addSlides();
        $body = $this->actingAs($this->viewer)->get("/decks/{$this->deck->id}/print?notes=1")->getContent();

        $this->assertStringNotContainsString('Hidden slide', $body);
        $this->assertStringNotContainsString('HIDDEN-NOTE', $body);
    }

    public function test_speaker_notes_reach_the_page_only_when_notes_are_asked_for(): void
    {
        $this->addSlides();
        $this->actingAs($this->editor);

        $without = $this->get("/decks/{$this->deck->id}/print")->getContent();
        $this->assertStringNotContainsString('SPEAKER-NOTE-ONE', $without);

        $this->get("/decks/{$this->deck->id}/print?notes=1")->assertInertia(fn (Assert $page) => $page
            ->where('slides.0.notes', 'SPEAKER-NOTE-ONE')
            ->where('options.notes', true));
    }

    public function test_slides_come_in_deck_order_with_the_deck_header_and_a_way_back(): void
    {
        $this->addSlides();
        Slide::where('title', 'Third slide')->update(['sort_order' => 0]);
        Slide::where('id', $this->slide->id)->update(['sort_order' => 5]);

        $this->actingAs($this->owner)->get("/decks/{$this->deck->id}/print")->assertInertia(fn (Assert $page) => $page
            ->where('slides.0.title', 'Third slide')
            ->where('slides.1.title', 'Slide one')
            ->where('deck.title', 'Quarterly deck')
            ->where('deck.w', 1920)
            ->where('backUrl', "/decks/{$this->deck->id}"));
    }

    public function test_picture_urls_are_signed_for_the_decks_own_assets_only(): void
    {
        $own = $this->asset('mine.png');
        $foreign = $this->asset('theirs.png', Project::create([
            'user_id' => $this->outsider->id, 'team_id' => $this->otherTeam->id, 'name' => 'Other', 'slug' => 'other-'.uniqid(),
        ]));
        Slide::where('id', $this->slide->id)->update(['els' => [
            ['id' => 'p1', 'type' => 'image', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'src' => ['asset' => $own->id]],
            ['id' => 'p2', 'type' => 'image', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'src' => ['asset' => $foreign->id]],
        ]]);

        $this->actingAs($this->viewer)->get("/decks/{$this->deck->id}/print")->assertInertia(fn (Assert $page) => $page
            ->has('assets', 1)
            ->has("assets.{$own->id}.url")
            ->where("assets.{$own->id}.url", fn ($url) => str_contains((string) $url, 'signature=') && str_starts_with((string) $url, '/present-asset/'))
            ->missing("assets.{$foreign->id}"));
    }
}
