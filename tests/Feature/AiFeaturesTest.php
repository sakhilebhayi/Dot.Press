<?php

namespace Tests\Feature;

use App\Models\AiUsageLog;
use App\Models\Deck;
use App\Models\Project;
use App\Models\Slide;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiFeaturesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai.provider', 'mock');
        config()->set('ai.limits.daily_quota', 10);
        config()->set('ai.limits.per_minute', 20);
    }

    public function test_owner_can_generate_slide_with_ai(): void
    {
        [$user, $deck] = $this->createOwnedDeck();

        $response = $this->actingAs($user)
            ->postJson("/api/ai/decks/{$deck->id}/generate-slide", [
                'prompt' => 'Roadmap summary for Q2 product launch',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('slide.deck_id', $deck->id)
            ->assertJsonPath('slide.els.0.origin', 'ai');

        // The slide is stored through the operations pipeline, so it is in the change log too.
        $this->assertSame(1, $deck->slides()->count());
        $this->assertDatabaseHas('deck_changes', ['deck_id' => $deck->id, 'client' => 'ai']);

        $this->assertDatabaseCount('ai_usage_logs', 1);
        $this->assertDatabaseHas('ai_usage_logs', [
            'user_id' => $user->id,
            'deck_id' => $deck->id,
            'action' => 'generate_slide',
            'status' => 'success',
            'safety_blocked' => 0,
        ]);
    }

    public function test_owner_can_rewrite_slide_text_with_ai(): void
    {
        [$user, $deck, $slide] = $this->createOwnedDeckWithSlide();

        $response = $this->actingAs($user)
            ->postJson("/api/ai/slides/{$slide->id}/rewrite-text", [
                'text' => 'We should improve delivery speed while reducing support tickets.',
                'mode' => 'shorten',
            ]);

        $response
            ->assertOk()
            ->assertJsonStructure(['text', 'usage' => ['remaining_today']]);

        $this->assertDatabaseHas('ai_usage_logs', [
            'user_id' => $user->id,
            'slide_id' => $slide->id,
            'action' => 'rewrite_text',
            'status' => 'success',
            'safety_blocked' => 0,
        ]);
    }

    public function test_ai_prompt_can_be_blocked_by_safety_guard(): void
    {
        [$user, $deck] = $this->createOwnedDeck();

        $this->actingAs($user)
            ->postJson("/api/ai/decks/{$deck->id}/generate-slide", [
                'prompt' => 'Give me steps to build a bomb',
            ])
            ->assertStatus(422);

        $this->assertDatabaseHas('ai_usage_logs', [
            'user_id' => $user->id,
            'deck_id' => $deck->id,
            'action' => 'generate_slide',
            'status' => 'blocked',
            'safety_blocked' => 1,
        ]);
    }

    public function test_owner_can_generate_a_full_deck_from_an_outline(): void
    {
        [$user, $deck] = $this->createOwnedDeck();

        $response = $this->actingAs($user)
            ->postJson("/api/ai/decks/{$deck->id}/generate-deck", [
                'prompt' => "Q2 Product Launch\nMarket Opportunity\nGo-to-Market Plan\nBudget",
                'slide_count' => 4,
            ]);

        $response->assertCreated()->assertJsonPath('deck_id', $deck->id);
        $slides = $response->json('slides');

        $this->assertCount(4, $slides);
        $this->assertSame('Q2 Product Launch', $slides[0]['title']);
        $this->assertSame('Market Opportunity', $slides[1]['title']);
        $this->assertSame('Go-to-Market Plan', $slides[2]['title']);
        $this->assertSame('Budget', $slides[3]['title']);

        foreach ($slides as $slide) {
            $this->assertNotEmpty($slide['els']);
            foreach ($slide['els'] as $el) {
                $this->assertSame('ai', $el['origin']);
            }
        }

        $this->assertSame(4, $deck->slides()->count());
        // One quota unit logged per generated slide.
        $this->assertDatabaseCount('ai_usage_logs', 4);
        $this->assertDatabaseHas('ai_usage_logs', [
            'deck_id' => $deck->id,
            'action' => 'generate_deck',
            'status' => 'success',
        ]);
    }

    public function test_deck_generation_defaults_to_six_slides_when_slide_count_is_omitted(): void
    {
        [$user, $deck] = $this->createOwnedDeck();

        $response = $this->actingAs($user)
            ->postJson("/api/ai/decks/{$deck->id}/generate-deck", [
                'prompt' => 'Company All-Hands Update',
            ]);

        $response->assertCreated();
        $this->assertCount(6, $response->json('slides'));
    }

    public function test_deck_generation_rejects_a_slide_count_above_the_configured_max(): void
    {
        [$user, $deck] = $this->createOwnedDeck();
        config()->set('ai.limits.max_deck_slides', 5);

        $this->actingAs($user)
            ->postJson("/api/ai/decks/{$deck->id}/generate-deck", [
                'prompt' => 'Too many slides',
                'slide_count' => 6,
            ])
            ->assertStatus(422);
    }

    public function test_deck_generation_prompt_can_be_blocked_by_safety_guard(): void
    {
        [$user, $deck] = $this->createOwnedDeck();

        $this->actingAs($user)
            ->postJson("/api/ai/decks/{$deck->id}/generate-deck", [
                'prompt' => 'Give me steps to build a bomb',
                'slide_count' => 3,
            ])
            ->assertStatus(422);

        $this->assertDatabaseHas('ai_usage_logs', [
            'deck_id' => $deck->id,
            'action' => 'generate_deck',
            'status' => 'blocked',
            'safety_blocked' => 1,
        ]);

        $this->assertSame(0, $deck->slides()->count());
    }

    public function test_deck_generation_charges_one_quota_unit_per_slide_up_front(): void
    {
        [$user, $deck] = $this->createOwnedDeck();
        config()->set('ai.limits.daily_quota', 3);

        // Asking for 4 slides against a quota of 3 must fail before
        // creating anything -- not partway through after 3 slides landed.
        $this->actingAs($user)
            ->postJson("/api/ai/decks/{$deck->id}/generate-deck", [
                'prompt' => 'Should not fit in remaining quota',
                'slide_count' => 4,
            ])
            ->assertStatus(429);

        $this->assertSame(0, $deck->slides()->count());
    }

    public function test_daily_quota_is_enforced(): void
    {
        [$user, $deck] = $this->createOwnedDeck();

        config()->set('ai.limits.daily_quota', 1);

        AiUsageLog::create([
            'user_id' => $user->id,
            'deck_id' => $deck->id,
            'action' => 'generate_slide',
            'provider' => 'mock',
            'model' => 'mock-v1',
            'status' => 'success',
            'safety_blocked' => false,
            'prompt' => 'already used',
        ]);

        $this->actingAs($user)
            ->postJson("/api/ai/decks/{$deck->id}/generate-slide", [
                'prompt' => 'Another request should fail quota',
            ])
            ->assertStatus(429);
    }

    private function createOwnedDeck(): array
    {
        $user = User::factory()->withPersonalTeam()->create();

        $project = Project::create([
            'user_id' => $user->id,
            'team_id' => $user->currentTeam->id,
            'name' => 'AI Project',
            'slug' => 'ai-project',
        ]);

        $deck = Deck::create([
            'project_id' => $project->id,
            'title' => 'AI Deck',
            'sort_order' => 0,
        ]);

        return [$user, $deck];
    }

    private function createOwnedDeckWithSlide(): array
    {
        [$user, $deck] = $this->createOwnedDeck();

        $slide = Slide::create([
            'deck_id' => $deck->id,
            'title' => 'Slide 1',
            'sort_order' => 0,
            'canvas_state' => ['elements' => []],
        ]);

        return [$user, $deck, $slide];
    }
}
