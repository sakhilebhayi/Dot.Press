<?php

namespace App\Services\Press\Templates\Library;

use App\Services\Press\Templates\Compose;
use App\Services\Press\Templates\Template;

final class QuarterlyReview implements Template
{
    public static function meta(): array
    {
        return [
            'key' => 'quarterly-business-review',
            'name' => 'Quarterly business review',
            'label' => 'Quarterly review',
            'category' => 'business',
            'blurb' => 'Results, drivers, plan: a review that explains the quarter and sets the next one. Built on the Review, Learn, Plan cycle with a scorecard and a bridge.',
            'narrative' => 'Review, learn, plan: results against targets, the drivers behind them, then priorities and asks',
            'theme' => 'slate',
            'tags' => ['quarterly', 'review', 'kpi', 'scorecard', 'results'],
            'transition' => ['type' => 'push', 'dir' => 'l', 'dur' => 450, 'ease' => 'out'],
            'cover' => 'split',
            'icon' => 'chart-bar',
        ];
    }

    public static function compose(Compose $c): void
    {
        $c->beginSection('Overview');
        $c->cover(
            'Quarterly business review',
            'Results, what drove them and what we do next',
            'Replace with quarter, presenter and date',
            "Set the frame in one breath: 'This review has three parts: how we did, why, and what we change.' Say who is in the room and what you need from them (decisions, not applause). Replace the grey line with the quarter, your name and the date. If the audience has read the pre-read, say so and promise to skip the recap.",
        );

        $c->points(
            'What this review covers',
            ['ol' => ['Results: the quarter against target', 'Drivers: what moved the numbers', 'Plan: priorities, risks and the asks for next quarter']],
            "Agenda slides earn their place only if they set expectations about time and outcome. Give each part a minute budget out loud (for example 10, 15 and 15) and say which part needs the room's decision. Then move on; do not read the agenda.",
            'Three parts, one decision at the end.',
        );

        $c->section('01', 'Results', 'How the quarter landed against the targets we set', "Pause on the divider and state the headline result in a single sentence before showing any numbers: 'We hit revenue, missed margin.' People hold a headline better when they hear it before they see the detail.");

        $c->figures(
            'The quarter in four numbers',
            [['12.4m', 'Revenue in rand'], ['41%', 'Gross margin'], ['58', 'Net promoter score'], ['2.1%', 'Monthly churn']],
            'Revenue is ahead of target; margin and churn need attention.',
            "Read each figure with its target: 'Revenue 12.4 million against 11.8 million.' The sample numbers are illustrative; replace them with your own and keep the labels to three words. Color is not needed here, so say aloud which of the four is a concern. The takeaway line is the sentence you want repeated after the meeting.",
            true,
            ['reveal' => ['kpi1', 'kpi2', 'kpi3', 'kpi4']],
        );

        $c->data(
            'Revenue passed target in May and stayed there',
            Compose::chart('line', ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'], [
                ['name' => 'Target', 'values' => [3.6, 3.8, 3.9, 4.0, 4.1, 4.2]],
                ['name' => 'Actual', 'values' => [3.4, 3.7, 3.9, 4.1, 4.4, 4.6]],
            ], ['title' => 'Monthly revenue', 'sub' => 'Rand millions', 'fmt' => 'num', 'dec' => 1, 'smooth' => false]),
            ['Behind target until March.', 'Ahead from April, widening in May and June.', 'Two large renewals explain most of the lift.'],
            'Source: replace with your finance report.',
            "Lead with the shape: 'We were behind, then crossed over in April.' Then name the cause in the right-hand notes. A line chart with only two lines lets the eye see the crossover without a legend hunt. Replace the six months and the values; the headline must still be true when you do, so rewrite it after you change the data.",
        );

        $c->data(
            'Scorecard: three of five measures are on target',
            Compose::table([
                ['Measure', 'Target', 'Actual', 'Status'],
                ['Revenue (R m)', '11.8', '12.4', 'On target'],
                ['Gross margin', '43%', '41%', 'Below'],
                ['New customers', '120', '131', 'On target'],
                ['Net promoter score', '55', '58', 'On target'],
                ['Monthly churn', '1.8%', '2.1%', 'Below'],
            ], [0.38, 0.2, 0.2, 0.22], ['l', 'r', 'r', 'l']),
            ['Margin missed because of freight and discounting.', 'Churn is concentrated in one segment.', 'Both are covered in the next section.'],
            'Source: replace with your scorecard.',
            "A scorecard is a table because the reader wants to scan down one column and across a row. Say the two misses first and the three hits second; people forgive a miss that is named early. Replace the five measures with the ones your executive team actually tracks, and keep the status words honest: 'Below' is better than 'Slightly challenged'.",
        );

        $c->section('02', 'Drivers', 'Why the numbers moved, and what we would do again', 'Explain that this section separates cause from noise. Promise the room two things: what to repeat and what to stop.');

        $c->compare(
            'What worked and what did not',
            ['Worked: repeat it', ['Renewal calls started 60 days out', 'One owner per large account', 'Weekly pipeline review', 'Clear pricing for the new tier']],
            ['Did not: change it', ['Freight cost passed on too late', 'Discounts approved without a margin check', 'Onboarding took six weeks, not three', 'Two reports with different numbers']],
            'Keep the two lists parallel and specific. Each line should be something a team can act on, not a theme. Do not assign blame on the slide; assign owners in the plan. Replace all eight lines with your own, and if one side has fewer than four honest items, show fewer.',
            ['reveal' => ['body', 'body2']],
        );

        $c->data(
            'Where the growth came from: enterprise did most of the work',
            Compose::chart('bar', ['Enterprise', 'Mid-market', 'Small business', 'Public sector'], [
                ['name' => 'Revenue growth', 'values' => [34, 18, 6, -3]],
            ], ['title' => 'Revenue growth by segment', 'sub' => 'Percent against last quarter', 'fmt' => 'pct', 'dec' => 0, 'labels' => 'value', 'legend' => 'none']),
            ['Enterprise grew a third.', 'Small business is flat.', 'Public sector slipped for the second quarter.'],
            'Source: replace with your CRM export.',
            'Sort the bars from largest to smallest so the answer is visible before anyone reads a number. A bar chart handles long category names better than columns. Call out the one segment you want the room to discuss, probably the one that shrank. Replace the four segments with your own.',
        );

        $c->section('03', 'Plan', 'Priorities, risks and what we need from this room', 'Signal the shift from looking back to deciding. Tell the room that this section ends with two or three asks, and that you will ask for a decision on each of them before the meeting ends.');

        $c->timeline(
            'Next quarter: four priorities in order',
            [['Month 1', 'Fix discount approval and freight pricing.'], ['Month 1 to 2', 'Cut onboarding from six weeks to three.'], ['Month 2', 'Launch the renewal playbook in all segments.'], ['Month 3', 'Review margin and churn against target.']],
            "Four priorities is the limit the room can remember. Say who owns each and what 'done' looks like. Order them by what unlocks the rest, not by importance. Replace the four steps with your own and give each a date range that you can defend.",
        );

        $c->points(
            'Risks to the plan and what we need from you',
            [
                '**Risk.** Freight costs stay high; we will review prices monthly.',
                '**Risk.** A key engineer leaves; we are documenting the integration.',
                '**Ask.** Approve the discount policy change by [date].',
                '**Ask.** Release [number] hours of engineering time in month one.',
            ],
            'Put the asks last and make them specific enough to say yes to. A risk without a mitigation is a worry; a mitigation without an owner is a wish. Replace each line, and keep the total to four. Pause after the last ask and let the room respond.',
            'Every ask has a name and a date.',
        );

        $c->closing(
            'Questions and decisions',
            'Decide the discount policy and the engineering time today.',
            'Replace with your name, email and phone',
            'Return to the two asks and ask for a decision on each. If the room needs more time, agree what is missing and who will provide it. Send the scorecard and the plan within a day so the decision is written down while it is fresh.',
        );
    }
}
