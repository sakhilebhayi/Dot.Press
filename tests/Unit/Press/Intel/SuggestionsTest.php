<?php

namespace Tests\Unit\Press\Intel;

use App\Services\Press\Intel\DocText;
use App\Services\Press\Intel\Suggestions;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class SuggestionsTest extends TestCase
{
    private function suggest(array $slides, array $req, bool $allowModel = true): array
    {
        return app(Suggestions::class)->suggest(DeckFixture::doc($slides), $req, $allowModel);
    }

    private function model(): void
    {
        config()->set('ai.provider', 'anthropic');
        config()->set('ai.anthropic.api_key', 'sk-test');
    }

    private function reply(array $payload, array $usage = ['input_tokens' => 10, 'output_tokens' => 5]): Response|PromiseInterface
    {
        return Http::response(['content' => [['type' => 'text', 'text' => json_encode($payload)]], 'usage' => $usage]);
    }

    private function textSlide(string|array $text, array $o = [], array $slide = []): array
    {
        return DeckFixture::slide('s', [DeckFixture::title('t', 'Overview'), DeckFixture::text('b', $text, $o)], $slide + ['title' => 'Overview']);
    }

    public function test_simplify_removes_listed_filler_and_proposes_an_edit(): void
    {
        $slides = [$this->textSlide(['In order to win we utilize the tool, due to the fact that it is cheap.', 'Basically the plan is really good'], ['bullets' => 1])];
        $r = $this->suggest($slides, ['kind' => 'simplify', 'slide' => 's', 'element' => 'b']);
        $this->assertSame('local', $r['mode']);
        $this->assertFalse($r['degraded']);
        $this->assertCount(1, $r['proposals']);
        $p = $r['proposals'][0];
        foreach (['id', 'kind', 'summary', 'mode', 'slide', 'before', 'after', 'ops', 'unsupported_numbers', 'diff'] as $k) {
            $this->assertArrayHasKey($k, $p);
        }
        $this->assertSame('local', $p['mode']);
        $this->assertSame('local', $p['origin']);
        $this->assertSame("To win we use the tool, because it is cheap\nThe plan is good", $p['after']);
        $this->assertStringContainsString('[-In order to-]', $p['diff']);
        $this->assertSame([], $p['unsupported_numbers']);
        $this->assertSame('el.set', $p['ops'][0]['op']);
        $this->assertArrayNotHasKey('origin', $p['ops'][0]['p'], 'local suggestions stay origin user once accepted');

        $after = OpsApplier::apply(DeckFixture::doc($slides), $p['ops']);
        $blocks = DocText::blocks($after['slides'][0]['els'][1]['doc']);
        $this->assertSame(['bullet', 'bullet'], array_column($blocks, 'list'));
        $this->assertSame(['To win we use the tool, because it is cheap', 'The plan is good'], array_column($blocks, 'text'));
    }

    public function test_nothing_to_simplify_gives_an_honest_note_not_a_fake_rewrite(): void
    {
        $r = $this->suggest([$this->textSlide('Costs fell 3% below budget')], ['kind' => 'simplify', 'slide' => 's', 'element' => 'b']);
        $this->assertSame([], $r['proposals']);
        $this->assertStringContainsString('language model', $r['notes'][0]);
    }

    public function test_a_locked_element_gets_a_proposal_without_operations(): void
    {
        $r = $this->suggest([$this->textSlide('In order to win we act', ['lock' => true])], ['kind' => 'simplify', 'slide' => 's', 'element' => 'b']);
        $this->assertSame([], $r['proposals'][0]['ops']);
    }

    public function test_simplify_without_an_element_covers_every_body_text_but_not_the_title(): void
    {
        $slide = DeckFixture::slide('s', [
            DeckFixture::title('t', 'In order to win we act now'),
            DeckFixture::text('b1', 'Basically we act', ['y' => 300]),
            DeckFixture::text('b2', 'Prior to launch we test', ['y' => 600]),
        ], ['title' => 'Win']);
        $r = $this->suggest([$slide], ['kind' => 'improve', 'slide' => 's']);
        $this->assertSame(['b1', 'b2'], array_column($r['proposals'], 'element'));
    }

    public function test_a_text_selection_is_simplified_without_touching_an_element(): void
    {
        $r = $this->suggest([$this->textSlide('x')], ['kind' => 'simplify', 'slide' => 's', 'text' => 'We utilize numerous tools in order to win.']);
        $this->assertSame('We use many tools to win.', $r['proposals'][0]['after']);
        $this->assertSame([], $r['proposals'][0]['ops']);
    }

    public function test_changing_tone_is_reported_as_needing_a_language_model(): void
    {
        $r = $this->suggest([$this->textSlide('We act')], ['kind' => 'professional_tone', 'slide' => 's', 'element' => 'b']);
        $this->assertSame([], $r['proposals']);
        $this->assertSame('professional_tone', $r['unavailable'][0]['kind']);
        $this->assertStringContainsString('language model', $r['unavailable'][0]['reason']);
        $this->assertSame('local', $r['mode']);
    }

    public function test_a_paragraph_becomes_bullets(): void
    {
        $para = 'Production rose 8% on Q2. We recorded two lost time injuries in the quarter. The conveyor failed in August and cost 36 hours of downtime.';
        $slides = [$this->textSlide($para)];
        $r = $this->suggest($slides, ['kind' => 'bulletise', 'slide' => 's', 'element' => 'b']);
        $this->assertCount(1, $r['proposals']);
        $after = OpsApplier::apply(DeckFixture::doc($slides), $r['proposals'][0]['ops']);
        $blocks = DocText::blocks($after['slides'][0]['els'][1]['doc']);
        $this->assertCount(3, $blocks);
        $this->assertSame(['bullet', 'bullet', 'bullet'], array_column($blocks, 'list'));
        $this->assertSame('Production rose 8% on Q2', $blocks[0]['text']);
        $this->assertSame([], $r['proposals'][0]['unsupported_numbers']);
        $this->assertSame([], $this->suggest([$this->textSlide('Short text')], ['kind' => 'bulletise', 'slide' => 's', 'element' => 'b'])['proposals']);
    }

    public function test_a_topic_title_gets_an_assertion_title_drawn_from_the_slide(): void
    {
        $slide = DeckFixture::slide('s', [DeckFixture::title('t', 'Costs'), DeckFixture::text('b', ['Operating cost per tonne was R412, which is 3% below budget', 'Diesel is a risk'], ['bullets' => 1])], ['title' => 'Costs']);
        $r = $this->suggest([$slide], ['kind' => 'assertion_title', 'slide' => 's']);
        $p = $r['proposals'][0];
        $this->assertSame('Costs', $p['before']);
        $this->assertSame('Operating cost per tonne was R412, which is 3% below budget', $p['after']);
        $after = OpsApplier::apply(DeckFixture::doc([$slide]), $p['ops']);
        $this->assertSame('Operating cost per tonne was R412, which is 3% below budget', $after['slides'][0]['title']);
        $this->assertSame('Operating cost per tonne was R412, which is 3% below budget', DocText::text($after['slides'][0]['els'][0]['doc']));

        $good = DeckFixture::slide('s', [DeckFixture::title('t', 'Costs fell 3% below budget')], ['title' => 'Costs fell 3% below budget']);
        $this->assertSame([], $this->suggest([$good], ['kind' => 'assertion_title', 'slide' => 's'])['proposals']);
        $empty = DeckFixture::slide('s', [DeckFixture::title('t', 'Costs')], ['title' => 'Costs']);
        $this->assertStringContainsString('no sentence', $this->suggest([$empty], ['kind' => 'assertion_title', 'slide' => 's'])['notes'][0]);
    }

    public function test_numbers_in_a_paragraph_become_a_chart_using_only_those_numbers(): void
    {
        $text = "Quarter\tTonnes\nQ1\t1050\nQ2\t1110\nQ3\t1200";
        $slides = [$this->textSlide($text)];
        $r = $this->suggest($slides, ['kind' => 'paragraph_to_visual', 'slide' => 's', 'element' => 'b']);
        $chart = collect($r['proposals'])->first(fn ($p) => ($p['content']['kind'] ?? '') === 'data');
        $this->assertNotNull($chart);
        $this->assertSame([], $chart['unsupported_numbers']);
        $after = OpsApplier::apply(DeckFixture::doc($slides), $chart['ops']);
        $this->assertCount(2, $after['slides']);
        $new = $after['slides'][1];
        $chartEl = collect($new['els'])->firstWhere('type', 'chart');
        $this->assertSame([1050.0, 1110.0, 1200.0], $chartEl['chart']['data']['series'][0]['values']);
        $this->assertSame(['Q1', 'Q2', 'Q3'], $chartEl['chart']['data']['categories']);
        $this->assertArrayNotHasKey('origin', $chartEl, 'a local proposal leaves the element origin at user');
        $this->assertNotSame('', $chartEl['alt']);
    }

    public function test_sequential_points_become_steps_and_figures_become_kpi_cards(): void
    {
        $steps = 'First, notify the control room. Then isolate each energy source. Next apply your personal lock. Finally test that the equipment is dead.';
        $r = $this->suggest([$this->textSlide($steps)], ['kind' => 'paragraph_to_visual', 'slide' => 's', 'element' => 'b']);
        $timeline = collect($r['proposals'])->first(fn ($p) => ($p['content']['kind'] ?? '') === 'timeline');
        $this->assertNotNull($timeline);
        $this->assertCount(4, $timeline['content']['slots']['steps']);

        $kpi = 'Production reached 1.2 million tonnes. Recovery averaged 91.4%. Operating cost per tonne was R412.';
        $r = $this->suggest([$this->textSlide($kpi)], ['kind' => 'paragraph_to_visual', 'slide' => 's', 'element' => 'b']);
        $cards = collect($r['proposals'])->first(fn ($p) => ($p['content']['kind'] ?? '') === 'kpi');
        $this->assertNotNull($cards);
        $this->assertGreaterThanOrEqual(2, count($cards['content']['slots']['kpis']));
        $this->assertSame([], $cards['unsupported_numbers']);
    }

    public function test_a_paragraph_with_nothing_to_draw_says_so(): void
    {
        $r = $this->suggest([$this->textSlide('We care about our customers.')], ['kind' => 'paragraph_to_visual', 'slide' => 's', 'element' => 'b']);
        $this->assertSame([], $r['proposals']);
        $this->assertStringContainsString('language model', $r['notes'][0]);
    }

    public function test_two_lists_side_by_side_become_a_comparison_slide(): void
    {
        $slide = DeckFixture::slide('s', [
            DeckFixture::title('t', 'Options'),
            DeckFixture::text('l', ['Low cost', 'Fast to install'], ['bullets' => 1, 'x' => 100, 'w' => 800]),
            DeckFixture::text('r', ['High cost', 'Slow to install'], ['bullets' => 1, 'x' => 1000, 'w' => 800]),
        ], ['title' => 'Options']);
        $r = $this->suggest([$slide], ['kind' => 'comparison_slide', 'slide' => 's']);
        $p = $r['proposals'][0];
        $this->assertSame('compare', $p['content']['kind']);
        $this->assertSame(['Low cost', 'Fast to install'], $p['content']['slots']['left']);
        $this->assertSame(['High cost', 'Slow to install'], $p['content']['slots']['right']);
        $after = OpsApplier::apply(DeckFixture::doc([$slide]), $p['ops']);
        $this->assertSame('compare', $after['slides'][1]['layout']);
        $this->assertCount(3, $after['slides'][1]['els']);
    }

    public function test_versus_wording_becomes_a_comparison_and_prose_without_two_sides_does_not(): void
    {
        $slide = $this->textSlide(['Solar vs diesel generators', 'Batteries versus the grid'], ['bullets' => 1]);
        $r = $this->suggest([$slide], ['kind' => 'comparison_slide', 'slide' => 's', 'element' => 'b']);
        $this->assertSame(['Solar', 'Batteries'], $r['proposals'][0]['content']['slots']['left']);
        $this->assertSame(['Diesel generators', 'The grid'], $r['proposals'][0]['content']['slots']['right']);
        $none = $this->suggest([$this->textSlide('We care about customers.')], ['kind' => 'comparison_slide', 'slide' => 's', 'element' => 'b']);
        $this->assertSame([], $none['proposals']);
        $this->assertStringContainsString('two sets', $none['notes'][0]);
    }

    public function test_a_better_layout_is_chosen_from_the_content_only_when_it_differs(): void
    {
        $chart = DeckFixture::slide('s', [DeckFixture::title('t', 'Output rose'), DeckFixture::chart('c')], ['title' => 'Output rose', 'layout' => 'bullets']);
        $p = $this->suggest([$chart], ['kind' => 'better_layout', 'slide' => 's'])['proposals'][0];
        $this->assertSame('data', $p['after']);
        $this->assertSame([['op' => 'slide.set', 'id' => 's', 'layout' => 'data']], $p['ops']);
        $this->assertTrue($p['needs_composer']);

        $many = [];
        for ($i = 1; $i <= 8; $i++) {
            $many[] = "Point number {$i}";
        }
        $two = $this->suggest([$this->textSlide($many, ['bullets' => 1], ['layout' => 'bullets'])], ['kind' => 'better_layout', 'slide' => 's']);
        $this->assertSame('two', $two['proposals'][0]['after']);

        $already = DeckFixture::slide('s', [DeckFixture::title('t', 'Output rose'), DeckFixture::chart('c')], ['title' => 'Output rose', 'layout' => 'data']);
        $this->assertSame([], $this->suggest([$already], ['kind' => 'better_layout', 'slide' => 's'])['proposals']);
    }

    public function test_a_section_is_summarised_extractively(): void
    {
        $slides = [
            DeckFixture::slide('a', [DeckFixture::title('ta', 'Production reached 1.2 million tonnes')], ['title' => 'Production reached 1.2 million tonnes', 'section_id' => 'x']),
            DeckFixture::slide('b', [DeckFixture::title('tb', 'Recovery averaged 91.4%')], ['title' => 'Recovery averaged 91.4%', 'section_id' => 'x']),
            DeckFixture::slide('c', [DeckFixture::title('tc', 'Safety improved')], ['title' => 'Safety improved', 'section_id' => 'y']),
        ];
        $doc = DeckFixture::doc($slides, ['sections' => [['id' => 'x', 'title' => 'Production is up'], ['id' => 'y', 'title' => 'Safety']]]);
        $r = app(Suggestions::class)->suggest($doc, ['kind' => 'summarise_section', 'slide' => 'a']);
        $p = $r['proposals'][0];
        $this->assertSame(['Production reached 1.2 million tonnes', 'Recovery averaged 91.4%'], $p['content']['slots']['bullets']);
        $after = OpsApplier::apply($doc, $p['ops']);
        $this->assertSame(['a', 'b', 'new_'.$p['ops'][0]['tid'], 'c'], OpsApplier::order($after), 'the summary follows the last slide of the section');
        $this->assertStringContainsString('extractive', implode(' ', $r['notes']));

        $single = app(Suggestions::class)->suggest($doc, ['kind' => 'summarise_section', 'slide' => 'c']);
        $this->assertSame([], $single['proposals']);
    }

    public function test_improve_flow_and_find_missing_come_from_the_storyteller(): void
    {
        $slides = [
            DeckFixture::slide('a', [DeckFixture::title('ta', 'Purpose of this talk today'), DeckFixture::text('xa', 'We will cover the conveyor')], ['title' => 'Purpose of this talk today']),
            DeckFixture::slide('b', [DeckFixture::title('tb', 'Our solution: a monitored drive'), DeckFixture::text('xb', 'We propose to implement sensors')], ['title' => 'Our solution: a monitored drive']),
            DeckFixture::slide('c', [DeckFixture::title('tc', 'The problem: conveyor failure in August'), DeckFixture::text('xc', 'The delay was a risk')], ['title' => 'The problem: conveyor failure in August']),
            DeckFixture::slide('d', [DeckFixture::title('td', 'The value: savings of R4 million'), DeckFixture::text('xd', 'Reduce downtime and save 40%')], ['title' => 'The value: savings of R4 million']),
        ];
        $flow = $this->suggest($slides, ['kind' => 'improve_flow', 'framework' => 'problem-solution-value']);
        $this->assertSame('improve_flow', $flow['proposals'][0]['kind']);
        $this->assertStringContainsString('The problem', $flow['proposals'][0]['after']);
        $after = OpsApplier::apply(DeckFixture::doc($slides), $flow['proposals'][0]['ops']);
        $order = OpsApplier::order($after);
        $this->assertLessThan(array_search('b', $order), array_search('c', $order));

        $missing = $this->suggest([$slides[0], $slides[3]], ['kind' => 'find_missing', 'framework' => 'problem-solution-value']);
        $summaries = implode(' ', array_column($missing['proposals'], 'summary'));
        $this->assertStringContainsString('The problem', $summaries);
        $this->assertStringContainsString('The solution', $summaries);
        $this->assertStringContainsString('call to action', strtolower($summaries));
        foreach ($missing['proposals'] as $p) {
            $this->assertSame('find_missing', $p['kind']);
        }
    }

    public function test_find_missing_asks_the_question_for_an_unsupported_claim(): void
    {
        $slide = DeckFixture::slide('s', [DeckFixture::title('t', 'Growth is strong'), DeckFixture::text('b', 'Production grew 40% year on year')], ['title' => 'Growth is strong']);
        $r = $this->suggest([$slide], ['kind' => 'find_missing']);
        $q = collect($r['proposals'])->firstWhere('question', '!=', null);
        $this->assertNotNull($q);
        $this->assertStringContainsString('40%', $q['question']);
        $this->assertSame([], $q['ops']);
    }

    public function test_bad_requests_are_refused(): void
    {
        $slides = [$this->textSlide('x')];
        foreach ([['kind' => 'nope', 'slide' => 's'], ['kind' => 'simplify', 'slide' => 'zzz'], ['kind' => 'simplify', 'slide' => 's', 'element' => 'zzz'], ['kind' => 'simplify']] as $req) {
            try {
                $this->suggest($slides, $req);
                $this->fail('accepted '.json_encode($req));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ---------------------------------------------------------------- model mode

    public function test_model_mode_rewrites_with_validated_json_and_marks_the_result_ai(): void
    {
        $this->model();
        Http::fake(['api.anthropic.com/*' => $this->reply(['blocks' => [['text' => 'Costs fell 3% below budget in the quarter', 'list' => null], ['text' => 'Output rose 99%', 'list' => 'bullet']]])]);
        $slides = [$this->textSlide(['Costs came in 3% under budget this quarter'], ['bullets' => 1])];
        $r = $this->suggest($slides, ['kind' => 'improve', 'slide' => 's', 'element' => 'b']);
        $this->assertSame('ai', $r['mode']);
        $this->assertTrue($r['model_called']);
        $this->assertSame(15, $r['usage']['input_tokens'] + $r['usage']['output_tokens']);
        $p = $r['proposals'][0];
        $this->assertSame('ai', $p['mode']);
        $this->assertSame('ai', $p['origin']);
        $this->assertSame('ai', $p['ops'][0]['p']['origin']);
        $this->assertSame(['99%'], $p['unsupported_numbers']);
    }

    public function test_model_tone_change_works_only_with_a_model(): void
    {
        $this->model();
        Http::fake(['api.anthropic.com/*' => $this->reply(['blocks' => [['text' => 'Costs were below budget.', 'list' => null]]])]);
        $r = $this->suggest([$this->textSlide('costs were under budget lol')], ['kind' => 'professional_tone', 'slide' => 's', 'element' => 'b', 'tone' => 'formal']);
        $this->assertSame('ai', $r['mode']);
        $this->assertStringContainsString('formal', $r['proposals'][0]['summary']);
        Http::assertSent(fn ($req) => str_contains((string) json_encode($req->data()), 'formal'));
    }

    public function test_a_failed_model_call_falls_back_to_local_rules_and_says_degraded(): void
    {
        $this->model();
        Http::fake(['api.anthropic.com/*' => Http::response('boom', 500)]);
        $r = $this->suggest([$this->textSlide('In order to win we act')], ['kind' => 'improve', 'slide' => 's', 'element' => 'b']);
        $this->assertSame('local', $r['mode']);
        $this->assertTrue($r['degraded']);
        $this->assertNotNull($r['degraded_reason']);
        $this->assertSame('To win we act', $r['proposals'][0]['after']);
        Http::assertSentCount(2);

        $tone = $this->suggest([$this->textSlide('We act')], ['kind' => 'professional_tone', 'slide' => 's', 'element' => 'b']);
        $this->assertTrue($tone['degraded']);
        $this->assertSame([], $tone['proposals']);
        $this->assertNotEmpty($tone['unavailable']);
    }

    public function test_the_model_is_not_called_for_kinds_that_rules_answer_well_or_when_disallowed(): void
    {
        $this->model();
        Http::fake();
        $this->suggest([$this->textSlide('In order to win we act')], ['kind' => 'simplify', 'slide' => 's', 'element' => 'b']);
        $this->suggest([$this->textSlide('In order to win we act')], ['kind' => 'improve', 'slide' => 's', 'element' => 'b'], false);
        Http::assertNothingSent();
    }

    public function test_a_model_slide_proposal_flags_numbers_it_cannot_trace(): void
    {
        $this->model();
        Http::fake(['api.anthropic.com/*' => $this->reply(['slide' => ['title' => 'Two options', 'kind' => 'compare', 'slots' => ['left' => ['Cheap'], 'right' => ['Costs R500']]]])]);
        $slide = $this->textSlide(['Cheap option', 'Dear option'], ['bullets' => 1]);
        $r = $this->suggest([$slide], ['kind' => 'comparison_slide', 'slide' => 's', 'element' => 'b']);
        $this->assertSame('ai', $r['mode']);
        $p = $r['proposals'][0];
        $this->assertTrue($p['content']['unsupported']);
        $this->assertSame(['R500'], $p['unsupported_numbers']);
        $this->assertSame('ai', $p['ops'][0]['slide']['els'][0]['origin'] ?? 'ai');
        $this->assertSame('ai', $p['content']['origin']);
    }
}
