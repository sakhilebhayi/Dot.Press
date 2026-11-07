<?php

namespace Tests\Feature\Press\Intel;

use App\Models\AiUsageLog;
use App\Models\Deck;
use App\Models\Project;
use App\Models\Slide;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Unit\Press\Intel\DeckFixture;
use Tests\Unit\Press\Intel\XlsxBuilder;

class IntelControllersTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $editor;

    private User $viewer;

    private User $outsider;

    private Team $team;

    private Deck $deck;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ai.provider', 'mock');
        config()->set('ai.limits.daily_quota', 50);
        config()->set('ai.limits.per_minute', 100);

        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->team = $this->owner->currentTeam;
        $this->editor = $this->member('editor');
        $this->viewer = $this->member('viewer');
        $this->outsider = User::factory()->withPersonalTeam()->create();

        $project = Project::create(['user_id' => $this->owner->id, 'team_id' => $this->team->id, 'name' => 'Board', 'slug' => 'board-'.uniqid()]);
        $this->deck = Deck::create(['project_id' => $project->id, 'title' => 'Quarterly review', 'sort_order' => 0, 'w' => 1920, 'h' => 1080]);

        $this->addSlide(0, 'Costs', [DeckFixture::title('t', 'Costs'), DeckFixture::text('b', ['Operating cost per tonne was R412, which is 3% below budget', 'In order to win we utilize the plan'], ['bullets' => 1])]);
        $this->addSlide(1, 'Click to add title', [DeckFixture::text('x', 'TBD'), DeckFixture::image('i')]);
    }

    private function member(string $role): User
    {
        $user = User::factory()->create();
        $this->team->users()->attach($user, ['role' => $role]);
        $user->switchTeam($this->team);

        return $user->fresh();
    }

    private function addSlide(int $order, string $title, array $els): Slide
    {
        return Slide::create(['deck_id' => $this->deck->id, 'title' => $title, 'sort_order' => $order, 'canvas_state' => ['elements' => []], 'layout' => 'bullets', 'els' => $els]);
    }

    private function url(string $path): string
    {
        return "/api/decks/{$this->deck->id}/intel/{$path}";
    }

    private function modelOn(): void
    {
        config()->set('ai.provider', 'anthropic');
        config()->set('ai.anthropic.api_key', 'sk-test');
    }

    private function modelReply(array $payload): mixed
    {
        return Http::response(['content' => [['type' => 'text', 'text' => json_encode($payload)]], 'usage' => ['input_tokens' => 120, 'output_tokens' => 60]]);
    }

    // ---------------------------------------------------------------- authorisation

    public function test_everything_needs_a_signed_in_user(): void
    {
        $this->postJson($this->url('analyze'))->assertUnauthorized();
        $this->postJson($this->url('story'))->assertUnauthorized();
        $this->getJson($this->url('spine'))->assertUnauthorized();
        $this->postJson($this->url('suggest'), ['kind' => 'simplify'])->assertUnauthorized();
        $this->postJson('/api/ai/outline', ['brief' => 'x y z'])->assertUnauthorized();
        $this->postJson('/api/data/parse')->assertUnauthorized();
    }

    public function test_someone_in_another_team_cannot_reach_a_deck(): void
    {
        foreach ([['postJson', 'analyze', []], ['postJson', 'story', []], ['getJson', 'spine', []], ['postJson', 'spine', ['type' => 'rename', 'beat' => 'b_x', 'claim' => 'y']], ['postJson', 'suggest', ['kind' => 'simplify']]] as [$method, $path, $body]) {
            $res = $this->actingAs($this->outsider)->{$method}($this->url($path), $body);
            $this->assertContains($res->status(), [403, 404], "$path leaked to an outsider: ".$res->status());
        }
        $this->assertDatabaseCount('ai_usage_logs', 0);
    }

    public function test_viewers_may_read_the_analysis_but_not_ask_for_edits(): void
    {
        $this->actingAs($this->viewer)->postJson($this->url('analyze'))->assertOk();
        $this->actingAs($this->viewer)->postJson($this->url('story'))->assertOk();
        $this->actingAs($this->viewer)->getJson($this->url('spine'))->assertOk();
        $this->actingAs($this->viewer)->postJson($this->url('suggest'), ['kind' => 'simplify', 'slide' => (string) $this->deck->slides()->first()->id])->assertForbidden();
        $this->actingAs($this->viewer)->postJson($this->url('spine'), ['type' => 'rename', 'beat' => 'b_x', 'claim' => 'y'])->assertForbidden();
    }

    // ---------------------------------------------------------------- analyze, story, spine

    public function test_analyze_returns_local_issues_with_fixes_for_the_stored_deck(): void
    {
        $res = $this->actingAs($this->editor)->postJson($this->url('analyze'))->assertOk();
        $res->assertJsonPath('mode', 'local')
            ->assertJsonStructure(['issues' => [['id', 'severity', 'kind', 'slide', 'message', 'why']], 'summary' => ['total', 'by_severity', 'by_category']]);
        $kinds = array_column($res->json('issues'), 'kind');
        foreach (['placeholder', 'alt-text', 'spelling-variants', 'topic-title'] as $k) {
            $this->assertNotContains('nonsense', $kinds);
        }
        $this->assertContains('placeholder', $kinds);
        $this->assertContains('alt-text', $kinds);
        $this->assertSame(2, $res->json('summary.slides'));
        // Slide ids in operations are the stored ids, as numbers.
        $slideIds = $this->deck->slides()->pluck('id')->all();
        foreach ($res->json('issues') as $issue) {
            if ($issue['slide'] !== null) {
                $this->assertContains($issue['slide'], $slideIds);
            }
        }
        $this->assertDatabaseCount('ai_usage_logs', 0);
    }

    public function test_story_reports_a_framework_and_accepts_a_choice(): void
    {
        $this->actingAs($this->owner)->postJson($this->url('story'))->assertOk()->assertJsonPath('mode', 'local')->assertJsonStructure(['framework' => ['id', 'strength'], 'checks', 'beats', 'proposals']);
        $this->actingAs($this->owner)->postJson($this->url('story'), ['framework' => 'training'])->assertOk()->assertJsonPath('framework.id', 'training')->assertJsonPath('framework.forced', true);
        $this->actingAs($this->owner)->postJson($this->url('story'), ['framework' => 'auto'])->assertOk()->assertJsonPath('framework.forced', false);
        $this->actingAs($this->owner)->postJson($this->url('story'), ['framework' => 'not-a-structure'])->assertStatus(422);
    }

    public function test_the_spine_can_be_read_and_an_edit_proposed_but_nothing_is_applied(): void
    {
        $res = $this->actingAs($this->editor)->getJson($this->url('spine'))->assertOk();
        $res->assertJsonPath('mode', 'local');
        $beats = $res->json('beats');
        $this->assertNotEmpty($beats);
        $edit = $this->actingAs($this->editor)->postJson($this->url('spine'), ['type' => 'rename', 'beat' => $beats[0]['id'], 'claim' => 'Costs are under control'])->assertOk();
        $edit->assertJsonPath('adopted_sections', true);
        $this->assertSame('section.set', collect($edit->json('ops'))->firstWhere('op', 'section.set')['op']);
        $this->assertNull($this->deck->fresh()->sections, 'the server only proposes');
        $this->actingAs($this->editor)->postJson($this->url('spine'), ['type' => 'rename', 'beat' => 'b_missing', 'claim' => 'x'])->assertStatus(422);
        $this->actingAs($this->editor)->postJson($this->url('spine'), ['type' => 'explode', 'beat' => 'b_x'])->assertStatus(422);
    }

    // ---------------------------------------------------------------- suggest (local)

    public function test_local_suggestions_cost_nothing_and_say_they_are_local(): void
    {
        $slide = $this->deck->slides()->orderBy('sort_order')->first();
        $res = $this->actingAs($this->editor)->postJson($this->url('suggest'), ['kind' => 'simplify', 'slide' => (string) $slide->id, 'element' => 'b'])->assertOk();
        $res->assertJsonPath('mode', 'local')->assertJsonPath('degraded', false);
        $p = $res->json('proposals.0');
        $this->assertStringContainsString('To win we use the plan', $p['after']);
        $this->assertSame($slide->id, $p['ops'][0]['slide']);
        $this->assertDatabaseCount('ai_usage_logs', 0);
        $res->assertJsonMissingPath('quota');
    }

    public function test_suggest_validates_its_input(): void
    {
        $slide = (string) $this->deck->slides()->first()->id;
        $this->actingAs($this->editor)->postJson($this->url('suggest'), ['kind' => 'nope', 'slide' => $slide])->assertStatus(422);
        $this->actingAs($this->editor)->postJson($this->url('suggest'), [])->assertStatus(422);
        $this->actingAs($this->editor)->postJson($this->url('suggest'), ['kind' => 'simplify', 'slide' => 'bad id!'])->assertStatus(422);
        $this->actingAs($this->editor)->postJson($this->url('suggest'), ['kind' => 'simplify', 'slide' => '99999'])->assertStatus(422);
        $this->actingAs($this->editor)->postJson($this->url('suggest'), ['kind' => 'simplify', 'slide' => $slide, 'element' => 'nope'])->assertStatus(422);
        $this->actingAs($this->editor)->postJson($this->url('suggest'), ['kind' => 'simplify', 'slide' => $slide, 'text' => str_repeat('a', 9000)])->assertStatus(422);
        $this->actingAs($this->editor)->postJson($this->url('suggest'), ['kind' => 'simplify'])->assertStatus(422);
        $this->actingAs($this->editor)->postJson($this->url('suggest'), ['kind' => 'improve_flow'])->assertOk();
    }

    // ---------------------------------------------------------------- suggest (model)

    public function test_a_model_suggestion_is_labelled_logged_and_counted(): void
    {
        $this->modelOn();
        Http::fake(['api.anthropic.com/*' => $this->modelReply(['blocks' => [['text' => 'Cost per tonne was R412, 3% under budget.', 'list' => null]]])]);
        $slide = (string) $this->deck->slides()->orderBy('sort_order')->first()->id;
        $res = $this->actingAs($this->editor)->postJson($this->url('suggest'), ['kind' => 'improve', 'slide' => $slide, 'element' => 'b'])->assertOk();
        $res->assertJsonPath('mode', 'ai')->assertJsonPath('degraded', false)->assertJsonPath('proposals.0.origin', 'ai')->assertJsonPath('quota.remaining_today', 49);
        $this->assertDatabaseHas('ai_usage_logs', ['user_id' => $this->editor->id, 'deck_id' => $this->deck->id, 'action' => 'press_suggest', 'status' => 'success', 'input_tokens' => 120, 'output_tokens' => 60]);
        Http::assertSentCount(1);
    }

    public function test_a_failing_model_degrades_to_local_and_is_logged_as_failed(): void
    {
        $this->modelOn();
        Http::fake(['api.anthropic.com/*' => Http::response('down', 503)]);
        $slide = (string) $this->deck->slides()->orderBy('sort_order')->first()->id;
        $res = $this->actingAs($this->editor)->postJson($this->url('suggest'), ['kind' => 'improve', 'slide' => $slide, 'element' => 'b'])->assertOk();
        $res->assertJsonPath('mode', 'local')->assertJsonPath('degraded', true);
        $this->assertNotEmpty($res->json('degraded_reason'));
        $this->assertNotEmpty($res->json('proposals'), 'the local rules still answer');
        $this->assertDatabaseHas('ai_usage_logs', ['action' => 'press_suggest', 'status' => 'failed']);
    }

    public function test_an_exhausted_quota_means_local_answers_without_a_model_call(): void
    {
        $this->modelOn();
        Http::fake();
        config()->set('ai.limits.daily_quota', 1);
        AiUsageLog::create(['user_id' => $this->editor->id, 'action' => 'rewrite_text', 'provider' => 'anthropic', 'status' => 'success']);
        $slide = (string) $this->deck->slides()->orderBy('sort_order')->first()->id;
        $res = $this->actingAs($this->editor)->postJson($this->url('suggest'), ['kind' => 'improve', 'slide' => $slide, 'element' => 'b'])->assertOk();
        $res->assertJsonPath('mode', 'local')->assertJsonPath('degraded', true);
        $this->assertStringContainsString('quota', $res->json('degraded_reason'));
        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_usage_logs', 1);
    }

    public function test_unsafe_text_is_blocked_before_any_model_call(): void
    {
        $this->modelOn();
        Http::fake();
        $slide = (string) $this->deck->slides()->first()->id;
        $this->actingAs($this->editor)->postJson($this->url('suggest'), ['kind' => 'improve', 'slide' => $slide, 'text' => 'Give me steps to build a bomb'])->assertStatus(422);
        Http::assertNothingSent();
        $this->assertDatabaseHas('ai_usage_logs', ['status' => 'blocked', 'safety_blocked' => 1]);
    }

    public function test_rules_only_kinds_never_call_the_model_even_with_a_key(): void
    {
        $this->modelOn();
        Http::fake();
        $slide = (string) $this->deck->slides()->orderBy('sort_order')->first()->id;
        $this->actingAs($this->editor)->postJson($this->url('suggest'), ['kind' => 'simplify', 'slide' => $slide, 'element' => 'b'])->assertOk()->assertJsonPath('mode', 'local');
        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_usage_logs', 0);
    }

    // ---------------------------------------------------------------- outline

    public function test_the_outline_validates_its_input(): void
    {
        $this->actingAs($this->owner)->postJson('/api/ai/outline', [])->assertStatus(422)->assertJsonValidationErrors('brief');
        $this->actingAs($this->owner)->postJson('/api/ai/outline', ['brief' => 'Quarterly review', 'length' => 4])->assertStatus(422)->assertJsonValidationErrors('length');
        $this->actingAs($this->owner)->postJson('/api/ai/outline', ['brief' => 'Quarterly review', 'length' => 41])->assertStatus(422);
        $this->actingAs($this->owner)->postJson('/api/ai/outline', ['brief' => 'Quarterly review', 'framework' => 'nope'])->assertStatus(422)->assertJsonValidationErrors('framework');
        $this->actingAs($this->owner)->postJson('/api/ai/outline', ['brief' => str_repeat('a', 8001)])->assertStatus(422);
        $this->actingAs($this->owner)->postJson('/api/ai/outline', ['brief' => 'Quarterly review', 'source' => str_repeat('a', 60001)])->assertStatus(422);
    }

    public function test_the_outline_defaults_to_honest_local_rules_with_no_usage(): void
    {
        $src = file_get_contents(base_path('tests/Unit/Press/Intel/fixtures/pitch.txt'));
        $res = $this->actingAs($this->editor)->postJson('/api/ai/outline', ['brief' => 'Investor pitch for SolarGrid', 'source' => $src, 'length' => 8, 'audience' => 'Investors', 'tone' => 'confident'])->assertOk();
        $res->assertJsonPath('mode', 'local')->assertJsonPath('degraded', false)->assertJsonPath('framework.id', 'opportunity-market-strategy-financials')
            ->assertJsonPath('beats.0.id', 'title')->assertJsonPath('quota.remaining_today', 50);
        $this->assertStringContainsString('local rules', $res->json('notes_on_mode'));
        $this->assertArrayNotHasKey('usage', $res->json());
        $this->assertArrayNotHasKey('model_called', $res->json());
        $this->assertSame([], $res->json('unsupported_numbers'));
        $this->assertDatabaseCount('ai_usage_logs', 0);
        $this->assertDatabaseCount('decks', 1);
    }

    public function test_a_model_outline_is_charged_and_logged(): void
    {
        $this->modelOn();
        $reply = ['title' => 'SolarGrid', 'beats' => [['id' => 'opportunity', 'slides' => [['title' => 'Load shedding costs 14 days a year', 'kind' => 'bullets', 'slots' => ['bullets' => ['14 days lost']]]]]]];
        Http::fake(['api.anthropic.com/*' => $this->modelReply($reply)]);
        $res = $this->actingAs($this->editor)->postJson('/api/ai/outline', ['brief' => 'Investor pitch', 'source' => 'Businesses lose 14 days a year.', 'length' => 5])->assertOk();
        $res->assertJsonPath('mode', 'ai')->assertJsonPath('degraded', false)->assertJsonPath('quota.remaining_today', 47);
        $this->assertSame(3, AiUsageLog::withoutGlobalScopes()->where('action', 'press_outline')->count());
        $this->assertDatabaseHas('ai_usage_logs', ['action' => 'press_outline', 'status' => 'success', 'input_tokens' => 120]);
    }

    public function test_a_failed_model_outline_falls_back_to_local_with_degraded(): void
    {
        $this->modelOn();
        Http::fake(['api.anthropic.com/*' => Http::response('nope', 500)]);
        $res = $this->actingAs($this->editor)->postJson('/api/ai/outline', ['brief' => 'Investor pitch', 'source' => 'The market grew 31% last year. Our strategy is a fixed monthly fee.', 'length' => 5])->assertOk();
        $res->assertJsonPath('mode', 'local')->assertJsonPath('degraded', true);
        $this->assertNotEmpty($res->json('beats'));
        $this->assertDatabaseHas('ai_usage_logs', ['action' => 'press_outline', 'status' => 'failed']);
    }

    public function test_the_outline_respects_the_quota_for_model_calls_only(): void
    {
        $this->modelOn();
        Http::fake();
        config()->set('ai.limits.daily_quota', 2);
        $res = $this->actingAs($this->editor)->postJson('/api/ai/outline', ['brief' => 'Investor pitch', 'source' => 'The market grew 31% last year.', 'length' => 5])->assertOk();
        $res->assertJsonPath('mode', 'local')->assertJsonPath('degraded', true);
        $this->assertStringContainsString('quota', $res->json('degraded_reason'));
        Http::assertNothingSent();

        $this->modelOff();
        $this->actingAs($this->editor)->postJson('/api/ai/outline', ['brief' => 'Investor pitch', 'source' => 'The market grew 31% last year.', 'length' => 5])->assertOk()->assertJsonPath('degraded', false);
    }

    private function modelOff(): void
    {
        config()->set('ai.provider', 'mock');
    }

    public function test_unsafe_outline_briefs_are_blocked_in_model_mode(): void
    {
        $this->modelOn();
        Http::fake();
        $this->actingAs($this->editor)->postJson('/api/ai/outline', ['brief' => 'Plan to build a bomb', 'length' => 5])->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_the_outline_is_throttled_like_the_other_ai_endpoints(): void
    {
        config()->set('ai.limits.per_minute', 2);
        for ($i = 0; $i < 2; $i++) {
            $this->actingAs($this->editor)->postJson('/api/ai/outline', ['brief' => 'Quarterly review', 'length' => 5])->assertOk();
        }
        $this->actingAs($this->editor)->postJson('/api/ai/outline', ['brief' => 'Quarterly review', 'length' => 5])->assertStatus(429);
    }

    // ---------------------------------------------------------------- data import

    public function test_a_csv_upload_is_parsed_and_not_stored(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $file = UploadedFile::fake()->createWithContent('output.csv', "Quarter,Tonnes\nQ1,1050\nQ2,1110\n");
        $res = $this->actingAs($this->editor)->postJson('/api/data/parse', ['file' => $file])->assertOk();
        $res->assertJsonPath('format', 'csv')->assertJsonPath('header', true)->assertJsonPath('rows.1.1', '1050')->assertJsonPath('column_types.1', 'number');
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_an_xlsx_upload_is_parsed_with_its_sheets(): void
    {
        $path = XlsxBuilder::make(sys_get_temp_dir().'/up-'.uniqid().'.xlsx', ['Output' => [[['s' => 'Quarter'], ['s' => 'Tonnes']], [['s' => 'Q1'], 1050]], 'Notes' => [[['s' => 'hello']]]]);
        $file = new UploadedFile($path, 'output.xlsx', null, null, true);
        $res = $this->actingAs($this->editor)->postJson('/api/data/parse', ['file' => $file])->assertOk();
        $res->assertJsonPath('format', 'xlsx')->assertJsonPath('sheet', 'Output')->assertJsonPath('sheets.1.name', 'Notes')->assertJsonPath('rows.1.1', '1050');
        $notes = $this->actingAs($this->editor)->postJson('/api/data/parse', ['file' => new UploadedFile($path, 'output.xlsx', null, null, true), 'sheet' => 'Notes'])->assertOk();
        $notes->assertJsonPath('rows.0.0', 'hello');
        @unlink($path);
    }

    public function test_bad_uploads_are_refused_with_a_reason(): void
    {
        $this->actingAs($this->editor)->postJson('/api/data/parse', [])->assertStatus(422)->assertJsonValidationErrors('file');
        $this->actingAs($this->editor)->postJson('/api/data/parse', ['file' => UploadedFile::fake()->createWithContent('run.exe', 'MZ')])->assertStatus(422)->assertJsonValidationErrors('file');
        $this->actingAs($this->editor)->postJson('/api/data/parse', ['file' => UploadedFile::fake()->createWithContent('fake.xlsx', 'not really a workbook')])->assertStatus(422)->assertJsonPath('reason', 'not_xlsx');
        $this->actingAs($this->editor)->postJson('/api/data/parse', ['file' => UploadedFile::fake()->createWithContent('bin.csv', "a,b\n\x00\x01,2")])->assertStatus(422)->assertJsonPath('reason', 'not_text');
        $big = UploadedFile::fake()->create('big.csv', 6000, 'text/csv');
        $this->actingAs($this->editor)->postJson('/api/data/parse', ['file' => $big])->assertStatus(422)->assertJsonValidationErrors('file');
        $this->actingAs($this->editor)->postJson('/api/data/parse', ['file' => UploadedFile::fake()->createWithContent('a.csv', "a,b\n1,2"), 'sheet' => str_repeat('x', 200)])->assertStatus(422);
    }

    public function test_data_parsing_is_throttled(): void
    {
        $file = fn () => UploadedFile::fake()->createWithContent('a.csv', "a,b\n1,2");
        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($this->editor)->postJson('/api/data/parse', ['file' => $file()])->assertOk();
        }
        $this->actingAs($this->editor)->postJson('/api/data/parse', ['file' => $file()])->assertStatus(429);
    }
}
