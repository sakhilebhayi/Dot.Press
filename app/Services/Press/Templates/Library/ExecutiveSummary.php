<?php

namespace App\Services\Press\Templates\Library;

use App\Services\Press\Templates\Compose;
use App\Services\Press\Templates\Template;

final class ExecutiveSummary implements Template
{
    public static function meta(): array
    {
        return [
            'key' => 'executive-summary',
            'name' => 'Executive summary',
            'label' => 'Executive summary',
            'category' => 'business',
            'blurb' => 'Answer first, in the Pyramid Principle order: the recommendation, the situation and complication, three findings, the path and the decision you need.',
            'narrative' => 'Pyramid Principle (answer, key arguments, evidence) with a situation, complication, question opening',
            'theme' => 'boardroom',
            'tags' => ['executive', 'decision', 'summary', 'recommendation'],
            'transition' => ['type' => 'fade', 'dir' => 'l', 'dur' => 400, 'ease' => 'out'],
            'cover' => 'band',
            'icon' => 'briefcase',
        ];
    }

    public static function compose(Compose $c): void
    {
        $c->beginSection('The answer');
        $c->cover(
            'Executive summary',
            'What we found, what we recommend and what we need from you',
            'Replace with presenter, role and date',
            "Open by telling the room how long this takes and what you want at the end: a decision. Say it plainly: 'In ten minutes you will have our recommendation, the three findings behind it and the one decision we need today.' Executives read the first slide for the stakes, so the subtitle should name the decision, not the topic. Replace the grey line with your name, role and the date.",
        );

        $c->slide('quote', [
            'body' => 'We recommend [the action], because [the one reason that matters]. It costs [amount] and pays back in [period].',
            'caption' => 'The answer comes first. Replace each bracket with your own facts.',
        ], 'This is the Pyramid Principle in one slide: the answer before the argument. Read it out loud once, slowly, then stop talking for a beat. Fill the brackets with a verb-led action, a single reason (not three), a cost and a payback period. If the sentence needs more than two lines, the recommendation is not yet sharp enough; cut it until it fits. Everything after this slide exists to defend this sentence.');

        $c->beginSection('Context');
        $c->points(
            'Where we are, what changed and what we must decide',
            [
                '**Situation.** What is true today and what everyone already agrees on.',
                '**Complication.** What changed, broke or became urgent.',
                '**Question.** The decision this forces, stated as a question.',
            ],
            'Keep this to the situation, complication and question (SCQA). Use one sentence each. The situation must be uncontroversial: if someone could disagree with it, it belongs in the findings. The complication is the reason the meeting exists. The question should be answerable by the sentence on the previous slide, and the room should hear it as the obvious next thing to ask.',
            'Executives decide faster when the question is on the table first.',
        );

        $c->beginSection('Findings');
        $c->figures(
            'Three numbers carry the case',
            [['+12%', 'Revenue against last year'], ['R 1.8m', 'Annual saving at full rollout'], ['14 mo', 'Payback on the investment']],
            'Replace each figure with your own, and keep the label to six words.',
            'Pick the three numbers an executive would quote in the corridor. One should show the problem or opportunity, one the benefit and one the cost or payback. Say each number once, say what it compares against, and move on. Sample figures here are illustrative; replace them before you present. If you cannot source a number, drop it rather than round it.',
            true,
            ['reveal' => true],
        );

        $c->data(
            'Finding 1: we are tracking above plan, but the gap is narrowing',
            Compose::chart('column', ['Q1', 'Q2', 'Q3', 'Q4'], [
                ['name' => 'Plan', 'values' => [4.0, 4.4, 4.8, 5.2]],
                ['name' => 'Actual', 'values' => [4.3, 4.7, 4.9, 5.1]],
            ], ['title' => 'Revenue against plan', 'sub' => 'Rand millions', 'fmt' => 'num', 'dec' => 1, 'labels' => 'value']),
            ['Name the trend in one sentence.', 'Say what is driving it.', 'Say what happens if nothing changes.'],
            'Source: replace with your finance system and period.',
            'State the finding as a headline, then let the chart prove it. Point at the one comparison that matters (here, actual against plan in the last quarter) and say what is driving it. Keep the chart to two series; a third makes the room read instead of listen. The sample numbers are illustrative: replace the values by clicking the chart and editing the data.',
        );

        $c->compare(
            'Finding 2: the two options differ most on risk and speed',
            ['Option A: phased rollout', ['Starts in one region', 'Lower upfront cost', 'Slower to full benefit', 'Easy to stop']],
            ['Option B: full launch', ['Starts everywhere at once', 'Higher upfront cost', 'Benefit from month three', 'Hard to reverse']],
            'Compare the options on the same four criteria, in the same order, so the eye can run across. Do not hide the downside of your preferred option: executives trust a comparison that admits cost. Finish by saying which option you recommend and which single criterion decided it. Replace both headings and every line with your own options.',
            ['reveal' => true],
        );

        $c->points(
            'Finding 3: three risks, each with an owner',
            [
                '**Delivery risk.** What could slip, and who watches it.',
                '**Cost risk.** Where the estimate is softest.',
                '**Adoption risk.** Who has to change what they do.',
            ],
            'Name the risks that could change the decision, not every risk on the register. For each, say who owns it and what the first warning sign is. Executives do not need the mitigation plan in this meeting; they need to know you have one and who holds it. If a risk has no owner yet, say so: it is a good place for the room to help.',
            'A risk with an owner is a plan. A risk without one is a surprise.',
        );

        $c->beginSection('Recommendation');
        $c->timeline(
            'The recommended path: the first 90 days',
            [['Week 1', 'Confirm the decision and name the owner.'], ['Weeks 2 to 4', 'Agree scope, budget and the success measure.'], ['Weeks 5 to 8', 'Run the first release with one team.'], ['Week 12', 'Review results and decide on the next stage.']],
            'A recommendation is credible when the first step is small and dated. Walk left to right, spending the most time on the first two steps. Say what the review at the end will measure so the room knows how you will be held to account. Replace the four steps with your own, keeping each to one sentence.',
        );

        $c->closing(
            'The decision we need today',
            'Approve [the option] and name an owner by [date].',
            'Replace with your name, email and phone',
            'End on the ask, not on thanks. Read the decision line, then stop and wait; the silence is the invitation to decide. Have the one-page detail ready as a follow-up document rather than a slide. If the room cannot decide, agree what information would let it and by when.',
        );
    }
}
