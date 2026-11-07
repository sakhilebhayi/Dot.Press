<?php

namespace App\Services\Press\Templates\Library;

use App\Services\Press\Templates\Compose;
use App\Services\Press\Templates\Template;

final class MarketingPlan implements Template
{
    public static function meta(): array
    {
        return [
            'key' => 'marketing-plan',
            'name' => 'Marketing plan',
            'label' => 'Marketing plan',
            'category' => 'sales',
            'blurb' => 'A marketing plan in the SOSTAC order: situation, objectives, strategy, tactics, action and control. Each step answers the question the next one needs.',
            'narrative' => 'SOSTAC: situation, objectives, strategy, tactics, action, control',
            'theme' => 'workshop',
            'tags' => ['marketing', 'campaign', 'channels', 'budget', 'sostac'],
            'transition' => ['type' => 'fade', 'dir' => 'l', 'dur' => 400, 'ease' => 'out'],
            'cover' => 'split',
            'icon' => 'target',
        ];
    }

    public static function compose(Compose $c): void
    {
        $c->beginSection('Overview');
        $c->cover(
            'Marketing plan',
            'Where we are, where we are going and how we get there',
            'Replace with period, owner and date',
            'Tell the room the plan follows six steps (situation, objectives, strategy, tactics, action, control) and that each step answers one question: where are we, where do we want to be, how do we get there, what exactly will we do, who does what by when, how will we know. Replace the grey line with the plan period and your name.',
        );

        $c->section('01', 'Situation', 'Where we are today', 'Keep the situation analysis short. The aim is the two or three facts that change what you do next.');

        $c->data(
            'Where our leads come from today',
            Compose::chart('doughnut', ['Search', 'Referrals', 'Social', 'Events', 'Email'], [
                ['name' => 'Share of leads', 'values' => [34, 27, 17, 14, 8]],
            ], ['title' => 'Leads by channel', 'sub' => 'Percent of qualified leads', 'legend' => 'right', 'labels' => 'percent', 'fmt' => 'pct']),
            ['Search and referrals bring most leads.', 'Events convert best but cost most.', 'Email is under used.'],
            'Source: replace with your CRM report.',
            'Show where the business already gets its leads, because new plans must start from reality. Replace the five channels and shares with your own. Say which channel surprised you and why, in one sentence.',
        );

        $c->compare(
            'What is working and what is not',
            ['Strengths and opportunities', ['Strong referral base', 'Customers trust our content', 'Competitor price rises', 'A growing local market']],
            ['Weaknesses and threats', ['Small team', 'Few case studies published', 'Competitors outspend us on search', 'Seasonal demand']],
            'This is a SWOT in two columns. Keep each item concrete enough that it could change a decision. Replace all eight lines, and cut any item you cannot connect to a tactic later in the plan.',
        );

        $c->section('02', 'Objectives', 'What we want to achieve, in numbers', 'Objectives must be measurable, dated and few. Three is plenty. Say that every objective on the next slide has a number, a deadline and an owner, and that you will ask the room to challenge any that are vague.');

        $c->figures(
            'Three objectives for the year',
            [['+40%', 'Qualified leads'], ['18%', 'Lead to customer conversion'], ['R 2m', 'New revenue from marketing']],
            'Each objective has a number, a date and an owner.',
            'Write objectives you can check on the last day of the year. These sample numbers are illustrative: replace them with your own targets and name the baseline behind each one. If an objective cannot fail, it is not an objective.',
            true,
        );

        $c->section('03', 'Strategy', 'Who we target and what we say', 'Strategy is a short list of choices: the audience, the position and the main channels. Tell the room that the choices will leave some audiences and channels out, and that this is intended.');

        $c->points(
            'Audience, position and promise',
            [
                '**Audience.** The one customer type we serve first.',
                '**Position.** How we are different in one sentence.',
                '**Promise.** What the customer can expect, and how we prove it.',
            ],
            'A plan without a chosen audience becomes a plan for everyone. Say out loud who you are not targeting this year. The position sentence should pass the competitor test: if a rival could say the same, rewrite it.',
            'Choose one audience for the year.',
        );

        $c->section('04', 'Tactics', 'What we will do, channel by channel', 'Tactics are the actual activities. Link each one to one objective, and tell the room that anything that cannot be linked to an objective has been left out of the plan.');

        $c->data(
            'Channel plan',
            Compose::table([
                ['Channel', 'What we will do', 'Objective', 'Budget'],
                ['Search', 'Publish 24 guides', 'Leads', 'R 60 000'],
                ['Email', 'Monthly newsletter and a 5 part welcome series', 'Conversion', 'R 20 000'],
                ['Events', 'Two workshops, one conference stand', 'Leads', 'R 120 000'],
                ['Referrals', 'Customer reward programme', 'Revenue', 'R 40 000'],
            ], [0.16, 0.44, 0.2, 0.2], ['l', 'l', 'l', 'r'], null, 28),
            ['Every line points to one objective.', 'Events take the largest share of budget.', 'Referrals are the cheapest route to revenue.'],
            'Budgets are illustrative. Replace with yours.',
            'A channel plan is a table: the audience wants to scan down. Keep each cell to a short phrase. Make sure every line has an objective and a budget, and that the totals match the budget slide. Replace all four rows with your own channels.',
        );

        $c->beginSection('Action');

        $c->timeline(
            'The first six months',
            [['Month 1', 'Publish the audience research and set up tracking.'], ['Months 2 to 3', 'Launch search guides and the welcome email series.'], ['Months 4 to 5', 'Run the first workshop and referral reward.'], ['Month 6', 'Review results and rebalance spend.']],
            "Put the first six months on one line so the team can see the rhythm. Add owners' names when you adapt the slide. Replace the four steps with your own.",
        );

        $c->beginSection('Control');

        $c->data(
            'Weekly lead tracker',
            Compose::chart('line', ['Wk 1', 'Wk 2', 'Wk 3', 'Wk 4', 'Wk 5', 'Wk 6', 'Wk 7', 'Wk 8'], [
                ['name' => 'Target', 'values' => [20, 22, 24, 26, 28, 30, 32, 34]],
                ['name' => 'Actual', 'values' => [18, 19, 24, 27, 26, 31, 33, 36]],
            ], ['title' => 'Qualified leads per week', 'sub' => 'Count', 'smooth' => false]),
            ['We trail target for two weeks, then lead.', 'Review every Monday.', 'Two weeks below target triggers a channel review.'],
            'Source: replace with your weekly report.',
            'Control means a regular review with a rule for action. State the rule: for example, two weeks below target triggers a review of that channel. Replace the weeks and values with your own tracker, and keep the line chart to two lines.',
        );

        $c->closing(
            'Approve the plan and the budget',
            'We need a yes on the plan and the budget by [date].',
            'Replace with name, email and phone',
            'End with the decision. Restate the three objectives and the total budget, and ask for approval. If there are open issues, name them and say who will resolve them.',
        );
    }
}
