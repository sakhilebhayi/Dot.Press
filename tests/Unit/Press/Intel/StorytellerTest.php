<?php

namespace Tests\Unit\Press\Intel;

use App\Services\Press\Intel\DeckAnalyzer;
use App\Services\Press\Intel\Storyteller;
use Tests\TestCase;

class StorytellerTest extends TestCase
{
    private function slide(string $id, string $title, array|string $body = [], array $extra = []): array
    {
        $body = is_array($body) ? $body : [$body];

        return DeckFixture::slide($id, [
            DeckFixture::title($id.'t', $title),
            DeckFixture::text($id.'b', $body ?: ['x'], ['bullets' => 1]),
        ], ['title' => $title] + $extra);
    }

    private function story(array $slides, ?string $fw = null): array
    {
        return (new Storyteller)->analyze(DeckFixture::doc($slides), $fw);
    }

    private function check(array $r, string $id): array
    {
        foreach ($r['checks'] as $c) {
            if ($c['id'] === $id) {
                return $c;
            }
        }
        $this->fail("no check $id");
    }

    /** A deck that follows problem, solution, value and closes with a call to action. */
    private function goodDeck(): array
    {
        return [
            $this->slide('s1', 'Why we are here today', ['The purpose of this talk is to decide on the conveyor replacement'], ['layout' => 'title']),
            $this->slide('s2', 'The problem: conveyor failures cost 36 hours in August', ['Unplanned downtime is a risk to production', 'The delay cost 14,000 tonnes']),
            $this->slide('s3', 'Our solution: replace the conveyor with a monitored system', ['We propose to implement sensors on 12 drive units and deploy a new drive', 'The system alerts the control room']),
            $this->slide('s4', 'The value: savings of R4 million a year', ['Reduce downtime by 60%', 'Payback in 14 months and a strong return', 'Source: engineering study 2026']),
            $this->slide('s5', 'Next steps: approve the replacement in Q4', ['Please approve the budget', 'Start procurement in November'], ['layout' => 'closing']),
        ];
    }

    public function test_a_deck_that_follows_problem_solution_value_is_detected_strongly(): void
    {
        $r = $this->story($this->goodDeck());
        $this->assertSame('local', $r['mode']);
        $this->assertSame('problem-solution-value', $r['framework']['id']);
        $this->assertSame('strong', $r['framework']['strength']);
        $this->assertFalse($r['framework']['forced']);
        $this->assertSame(1.0, $r['framework']['coverage']);
        $this->assertSame([], $r['missing']);
        $mapped = array_column($r['mapping'], 'beat', 'slide');
        $this->assertSame('problem', $mapped['s2']);
        $this->assertSame('solution', $mapped['s3']);
        $this->assertSame('value', $mapped['s4']);
        $covered = array_column(array_filter($r['beats'], fn ($b) => $b['covered']), 'id');
        $this->assertContains('problem', $covered);
        $this->assertContains('value', $covered);
    }

    public function test_the_checks_pass_for_a_good_deck(): void
    {
        $r = $this->story($this->goodDeck());
        foreach (['purpose_early', 'conclusion', 'call_to_action', 'logical_progression'] as $id) {
            $this->assertSame('ok', $this->check($r, $id)['status'], $id.': '.$this->check($r, $id)['detail']);
        }
        $this->assertSame('ok', $this->check($r, 'evidence_for_claims')['status']);
        $this->assertSame('ok', $this->check($r, 'consistent_messaging')['status']);
    }

    public function test_a_missing_beat_is_reported_with_a_needs_evidence_slide_proposal(): void
    {
        $slides = $this->goodDeck();
        unset($slides[2]);
        $slides = array_values($slides);
        $r = $this->story($slides, 'problem-solution-value');
        $this->assertTrue($r['framework']['forced']);
        $this->assertSame('solution', $r['missing'][0]['beat']);
        $this->assertSame('s2', $r['missing'][0]['after']);
        $this->assertStringEndsWith('?', $r['missing'][0]['question']);

        $proposal = collect($r['proposals'])->firstWhere('id', 'add:solution');
        $this->assertSame('add_beat', $proposal['kind']);
        $doc = DeckFixture::doc($slides);
        $after = OpsApplier::apply($doc, $proposal['ops']);
        $this->assertCount(5, $after['slides']);
        $this->assertSame('s2', $after['slides'][1]['id']);
        $this->assertStringStartsWith('new_', $after['slides'][2]['id']);
        $text = json_encode($after['slides'][2]['els']);
        $this->assertStringContainsString('Needs your evidence', $text);
        // The new slide is a placeholder the analyzer will keep flagging until it is filled in.
        $issues = DeckFixture::issues((new DeckAnalyzer)->analyze($after), 'placeholder');
        $this->assertNotEmpty($issues);
    }

    public function test_a_weak_match_says_so_and_offers_the_free_structure(): void
    {
        $slides = [
            $this->slide('a', 'Canteen opening hours', ['Lunch is at noon', 'Coffee all day']),
            $this->slide('b', 'Parking at the east gate', ['Limited spaces', 'Use the north lot']),
            $this->slide('c', 'Recycling bins', ['Blue for paper', 'Green for glass']),
        ];
        $r = $this->story($slides);
        $this->assertSame('free', $r['framework']['id']);
        $this->assertSame('weak', $r['framework']['strength']);
        $this->assertStringContainsString('none is forced', implode(' ', $r['notes']));
        $this->assertNotEmpty($r['alternatives']);
        $this->assertSame([], $r['missing'], 'nothing is added when no structure was chosen');
    }

    public function test_a_chosen_structure_is_not_overridden_but_a_poor_fit_is_noted(): void
    {
        $r = $this->story($this->goodDeck(), 'training');
        $this->assertSame('training', $r['framework']['id']);
        $this->assertTrue($r['framework']['forced']);
        $this->assertContains($r['framework']['strength'], ['weak', 'moderate']);
        $this->assertStringContainsString('you decide', implode(' ', $r['notes']));
    }

    public function test_purpose_conclusion_and_call_to_action_are_flagged_when_absent(): void
    {
        $slides = [
            $this->slide('a', 'Mill throughput', ['Tonnes per hour averaged 410', 'Recovery held steady']),
            $this->slide('b', 'Conveyor availability', ['Availability was 94%', 'Two stoppages occurred']),
            $this->slide('c', 'Thank you', ['Questions']),
        ];
        $r = $this->story($slides);
        $this->assertSame('missing', $this->check($r, 'purpose_early')['status']);
        $this->assertSame('weak', $this->check($r, 'conclusion')['status'], 'a thank-you slide is not a conclusion');
        $this->assertSame('missing', $this->check($r, 'call_to_action')['status']);
    }

    public function test_claim_slides_without_evidence_are_listed(): void
    {
        $slides = [
            $this->slide('a', 'Our new process reduces waste significantly', ['It is much better', 'People like it']),
            $this->slide('b', 'Costs fell 3% below budget', ['Operating cost per tonne was R412']),
            $this->slide('c', 'Customers prefer the new service', ['They said so']),
        ];
        $r = $this->story($slides);
        $c = $this->check($r, 'evidence_for_claims');
        $this->assertSame(['a', 'c'], $c['slides']);
        $this->assertSame('missing', $c['status']);
    }

    public function test_slides_out_of_order_get_a_reorder_proposal_that_works(): void
    {
        $slides = [
            $this->slide('a', 'Purpose of this talk today', ['We will cover the conveyor']),
            $this->slide('b', 'Our solution: a monitored drive', ['We propose to implement sensors']),
            $this->slide('c', 'The problem: conveyor failure in August', ['The delay was a risk']),
            $this->slide('d', 'The value: savings of R4 million', ['Reduce downtime and save']),
        ];
        $r = $this->story($slides, 'problem-solution-value');
        $p = $this->check($r, 'logical_progression');
        $this->assertNotSame('ok', $p['status']);
        $this->assertSame(['c'], $p['slides']);
        $reorder = collect($r['proposals'])->firstWhere('kind', 'reorder');
        $this->assertNotNull($reorder);
        $after = OpsApplier::apply(DeckFixture::doc($slides), $reorder['ops']);
        $order = OpsApplier::order($after);
        $this->assertLessThan(array_search('b', $order), array_search('c', $order), 'the problem now comes before the solution');
        $this->assertSame(4, count($order));
    }

    public function test_weak_transitions_are_listed_with_the_question_to_answer(): void
    {
        $slides = [
            $this->slide('a', 'Mill throughput held at 410 tonnes per hour', ['Recovery stayed steady', 'The mill ran well']),
            $this->slide('b', 'Canteen menu changes soon', ['Lunch will be vegetarian on Fridays']),
            $this->slide('c', 'However the canteen menu depends on suppliers', ['Suppliers may change prices']),
        ];
        $r = $this->story($slides);
        $this->assertCount(1, $r['weak_transitions']);
        $this->assertSame('a', $r['weak_transitions'][0]['from']);
        $this->assertSame('b', $r['weak_transitions'][0]['to']);
        $this->assertStringEndsWith('?', $r['weak_transitions'][0]['question']);
    }

    public function test_hidden_slides_are_ignored_and_an_empty_deck_is_handled(): void
    {
        $slides = $this->goodDeck();
        $slides[1]['hidden'] = true;
        $r = $this->story($slides);
        $this->assertCount(4, $r['mapping']);
        $empty = (new Storyteller)->analyze(DeckFixture::doc([]));
        $this->assertSame('local', $empty['mode']);
        $this->assertSame([], $empty['mapping']);
        $this->assertNotEmpty($empty['notes']);
    }

    public function test_the_free_structure_has_checkable_coverage(): void
    {
        $r = $this->story($this->goodDeck(), 'free');
        $this->assertSame('free', $r['framework']['id']);
        $this->assertGreaterThan(0, $r['framework']['coverage']);
        $this->assertSame([], $r['missing']);
    }
}
