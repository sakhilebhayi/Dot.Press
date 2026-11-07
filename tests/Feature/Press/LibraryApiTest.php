<?php

namespace Tests\Feature\Press;

use App\Models\Deck;
use App\Models\Slide;
use App\Services\Press\DocumentSanitizer;
use App\Services\Press\LayoutLibrary;
use App\Services\Press\TemplateLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LibraryApiTest extends TestCase
{
    use AssetsFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssets();
    }

    // ------------------------------------------------------------------------------------------ layout library

    public function test_the_layout_library_lists_the_fourteen_built_in_layouts_with_geometry(): void
    {
        $response = $this->actingAs($this->owner)->getJson('/api/layout-library')->assertOk();

        $response->assertJsonPath('family', 'wide')->assertJsonPath('w', 1920)->assertJsonPath('h', 1080)->assertJsonCount(14, 'data');
        $this->assertSame(LayoutLibrary::ids(), array_column($response->json('data'), 'id'));

        $bullets = collect($response->json('data'))->firstWhere('id', 'bullets');
        $this->assertSame('Statement and points', $bullets['name']);
        $this->assertNotEmpty($bullets['description']);
        $this->assertSame('none', $bullets['evidence']);
        $this->assertSame(['title', 'subtitle', 'body', 'footer'], array_column($bullets['ph'], 'role'));
        $this->assertSame(array_column($bullets['ph'], 'role'), array_column($bullets['preview'], 'role'));
        foreach ($bullets['ph'] as $p) {
            $this->assertGreaterThanOrEqual(0, $p['x']);
            $this->assertLessThanOrEqual(1.0001, $p['x'] + $p['w']);
        }
        $this->assertSame('image', collect($response->json('data'))->firstWhere('id', 'image-left')['evidence']);
    }

    public function test_the_layout_library_follows_the_deck_size(): void
    {
        $portrait = $this->actingAs($this->owner)->getJson('/api/layout-library?w=1080&h=1920')->assertOk();
        $wide = $this->actingAs($this->owner)->getJson('/api/layout-library')->assertOk();

        $portrait->assertJsonPath('family', 'portrait');
        $this->assertNotSame(
            collect($wide->json('data'))->firstWhere('id', 'image-left')['ph'][0],
            collect($portrait->json('data'))->firstWhere('id', 'image-left')['ph'][0],
        );

        $this->actingAs($this->owner)->getJson('/api/layout-library?w=1440&h=1080')->assertOk()->assertJsonPath('family', 'standard');
        $this->actingAs($this->owner)->getJson('/api/layout-library?w=10&h=1080')->assertStatus(422);
        $this->actingAs($this->owner)->getJson('/api/layout-library?w=abc')->assertStatus(422);
    }

    public function test_the_layout_library_needs_a_signed_in_user_and_leaves_team_layouts_alone(): void
    {
        $this->getJson('/api/layout-library')->assertUnauthorized();
        $this->getJson('/api/templates')->assertUnauthorized();

        $this->actingAs($this->owner)->postJson('/api/layouts', ['name' => 'Mine', 'definition' => [['id' => 'a', 'type' => 'text', 'role' => 'title', 'x' => 0.1, 'y' => 0.1, 'w' => 0.8, 'h' => 0.2]]])->assertCreated();

        $mine = $this->actingAs($this->owner)->getJson('/api/layouts')->assertOk();
        $mine->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Mine');
        $this->assertArrayNotHasKey('ph', $mine->json('data.0'));
    }

    // ------------------------------------------------------------------------------------------ template library

    public function test_the_template_list_has_metadata_only_and_the_categories(): void
    {
        $response = $this->actingAs($this->owner)->getJson('/api/templates')->assertOk();

        $this->assertCount(count(TemplateLibrary::all()), $response->json('data'));
        $first = $response->json('data.0');
        foreach (['key', 'name', 'category', 'blurb', 'narrative', 'slides', 'theme', 'aspect', 'tags'] as $field) {
            $this->assertArrayHasKey($field, $first);
        }
        $this->assertArrayNotHasKey('els', $first);
        $this->assertArrayNotHasKey('preview', $first);
        $this->assertSame(['business', 'sales', 'finance', 'education', 'operations'], array_column($response->json('categories'), 'id'));
        $this->assertSame(count($response->json('data')), array_sum(array_column($response->json('categories'), 'count')));
    }

    public function test_the_template_list_filters_by_category_and_search(): void
    {
        $finance = $this->actingAs($this->owner)->getJson('/api/templates?category=finance')->assertOk();
        $this->assertNotEmpty($finance->json('data'));
        $this->assertSame(['finance'], array_values(array_unique(array_column($finance->json('data'), 'category'))));

        $mining = $this->actingAs($this->owner)->getJson('/api/templates?q=mining')->assertOk();
        $this->assertSame(['mining-production-performance'], array_column($mining->json('data'), 'key'));

        $narrative = $this->actingAs($this->owner)->getJson('/api/templates?q=sostac')->assertOk();
        $this->assertSame(['marketing-plan'], array_column($narrative->json('data'), 'key'));

        $both = $this->actingAs($this->owner)->getJson('/api/templates?category=operations&q=toolbox')->assertOk();
        $this->assertSame(['safety-briefing'], array_column($both->json('data'), 'key'));

        $this->actingAs($this->owner)->getJson('/api/templates?q=zzzzzz')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->owner)->getJson('/api/templates?category=nope')->assertStatus(422);
        $this->actingAs($this->owner)->getJson('/api/templates?q='.str_repeat('a', 101))->assertStatus(422);
    }

    public function test_one_template_comes_with_its_theme_outline_and_a_preview_of_the_first_slide(): void
    {
        $response = $this->actingAs($this->owner)->getJson('/api/templates/executive-summary')->assertOk();

        $t = $response->json('template');
        $this->assertSame('executive-summary', $t['key']);
        $this->assertSame('boardroom', $t['theme_data']['id']);
        $this->assertCount($t['slides'], $t['outline']);
        $this->assertSame('title', $t['outline'][0]['layout']);
        $this->assertNotEmpty($t['sections']);
        $this->assertSame(1920, $t['preview']['w']);
        $this->assertSame('title', $t['preview']['slide']['layout']);
        $this->assertNotEmpty($t['preview']['slide']['els']);

        $this->actingAs($this->owner)->getJson('/api/templates/nope')->assertNotFound();
        $this->actingAs($this->owner)->getJson('/api/templates/Not_A_Key')->assertNotFound();
    }

    // ------------------------------------------------------------------------------------------ creating a deck

    public function test_a_deck_created_from_a_template_gets_its_theme_sections_and_slides(): void
    {
        $meta = TemplateLibrary::find('quarterly-business-review');

        $response = $this->actingAs($this->owner)->postJson('/api/decks', ['title' => 'Q3 review', 'template' => 'quarterly-business-review'])->assertCreated();

        $deck = Deck::findOrFail($response->json('id'));
        $this->assertSame('slate', $deck->theme['id']);
        $this->assertSame(1920, $deck->w);
        $slides = Slide::where('deck_id', $deck->id)->orderBy('sort_order')->get();
        $this->assertCount($meta['slides'], $slides);
        $this->assertCount($meta['sections'], $deck->sections);
        $this->assertSame('title', $slides[0]->layout);
        $this->assertNotEmpty($slides[0]->els);
        $this->assertNull($slides[0]->transition);
        $this->assertSame('push', $slides[1]->transition['type']);
        $this->assertNotEmpty($slides[1]->notes);

        $sectionIds = array_column($deck->sections, 'id');
        foreach ($slides as $slide) {
            $this->assertContains($slide->section_id, $sectionIds);
        }
    }

    public function test_a_template_deck_takes_the_chosen_size_and_every_element_stays_on_the_slide(): void
    {
        $response = $this->actingAs($this->owner)->postJson('/api/decks', ['title' => 'Portrait', 'template' => 'safety-briefing', 'size' => 'portrait'])->assertCreated();

        $deck = Deck::findOrFail($response->json('id'));
        $this->assertSame([1080, 1920], [$deck->w, $deck->h]);
        $this->assertSame('projector', $deck->theme['id']);

        foreach (Slide::where('deck_id', $deck->id)->get() as $slide) {
            foreach ($slide->els as $el) {
                $this->assertLessThanOrEqual(1080.5, $el['x'] + $el['w']);
                $this->assertLessThanOrEqual(1920.5, $el['y'] + $el['h']);
            }
        }
    }

    public function test_a_template_deck_can_still_choose_its_own_theme(): void
    {
        $response = $this->actingAs($this->owner)->postJson('/api/decks', [
            'title' => 'Own theme', 'template' => 'lesson', 'theme' => ['id' => 'mine', 'name' => 'Mine', 'c' => ['accent' => '#00ff00']],
        ])->assertCreated();

        $this->assertSame('mine', Deck::findOrFail($response->json('id'))->theme['id']);
    }

    public function test_an_unknown_template_key_creates_a_blank_deck_and_every_template_creates_through_the_api(): void
    {
        $this->actingAs($this->owner)->postJson('/api/decks', ['title' => 'Blank', 'template' => 'does-not-exist'])->assertCreated();
        $this->assertSame(1, Slide::count());

        foreach (TemplateLibrary::keys() as $key) {
            $response = $this->actingAs($this->owner)->postJson('/api/decks', ['title' => $key, 'template' => $key])->assertCreated();
            $deck = Deck::findOrFail($response->json('id'));
            $this->assertCount(TemplateLibrary::find($key)['slides'], Slide::where('deck_id', $deck->id)->get(), $key);
        }
    }

    public function test_slides_stored_from_a_template_are_exactly_what_the_sanitizer_returns(): void
    {
        $response = $this->actingAs($this->owner)->postJson('/api/decks', ['title' => 'T', 'template' => 'mining-production-performance'])->assertCreated();

        $sanitizer = new DocumentSanitizer;
        foreach (Slide::where('deck_id', $response->json('id'))->get() as $slide) {
            $this->assertSame($slide->els, $sanitizer->elements($slide->els), 'stored elements are already clean');
        }
    }
}
