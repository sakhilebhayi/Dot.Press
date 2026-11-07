<?php

namespace App\Services\Press\Templates\Library;

use App\Services\Press\Templates\Compose;
use App\Services\Press\Templates\Template;

final class BudgetPresentation implements Template
{
    public static function meta(): array
    {
        return [
            'key' => 'budget-presentation',
            'name' => 'Budget presentation',
            'label' => 'Budget',
            'category' => 'finance',
            'blurb' => 'Ask for a budget: the assumptions, the allocation, the change from last year, the trade offs and the risks, with approval as the last step. Ask, evidence, trade offs, approval.',
            'narrative' => 'Ask, assumptions, allocation, change from last year, trade offs, risks, approval',
            'theme' => 'ledger',
            'tags' => ['budget', 'allocation', 'approval', 'planning', 'department'],
            'transition' => ['type' => 'fade', 'dir' => 'l', 'dur' => 350, 'ease' => 'out'],
            'cover' => 'band',
            'icon' => 'percent',
        ];
    }

    public static function compose(Compose $c): void
    {
        $c->beginSection('The ask');
        $c->cover(
            'Budget for [next year]',
            'What we ask for, why and what it delivers',
            'Replace with department, presenter and date',
            'Open by stating the total requested and the outcome it pays for. Decision makers should know the size of the ask in the first thirty seconds. Replace the grey line with your department and date.',
        );

        $c->quote(
            'We are asking for R 8.4 million, 6 percent more than last year, to [deliver the outcome].',
            'Replace with your total, the change and the outcome.',
            'Say the total, the change from last year and the outcome in one sentence. If the budget is flat or falling, say that. The figure on this slide is a sample: replace it with yours. Everything after this slide supports this sentence.',
        );

        $c->beginSection('Basis');
        $c->points(
            'The assumptions behind the numbers',
            [
                '**Inflation.** Costs rise by 5 percent, in line with the national forecast.',
                '**Headcount.** Two new roles from month four.',
                '**Volume.** Activity grows by 8 percent.',
            ],
            'Assumptions are where budget arguments hide. State the three that move the total the most and the source of each. Invite challenge now. Replace each line with your own and keep it to the assumptions that matter.',
            'Change an assumption and the total changes.',
        );

        $c->data(
            'Where the money goes',
            Compose::chart('doughnut', ['People', 'Equipment', 'Services', 'Travel and training', 'Other'], [
                ['name' => 'Share of budget', 'values' => [58, 17, 13, 7, 5]],
            ], ['title' => 'Budget by category', 'sub' => 'Percent of the total', 'legend' => 'right', 'labels' => 'percent', 'fmt' => 'pct']),
            ['People cost is the largest item.', 'Equipment is the biggest increase.', 'Travel is held flat.'],
            'Source: replace with your budget model.',
            "Show the split first, then the detail. Point to the two largest slices and say what drives them. A doughnut works when there are five or fewer slices; if you have more, group the small ones as 'other'. Replace the categories and percentages.",
        );

        $c->data(
            'Budget by area',
            Compose::table([
                ['Rand thousands', 'This year', 'Proposed', 'Change'],
                ['Operations', '3 100', '3 300', '+6%'],
                ['Technology', '1 900', '2 150', '+13%'],
                ['People and culture', '1 400', '1 450', '+4%'],
                ['Marketing', '1 000', '1 000', '0%'],
                ['Total', '7 400', '7 900', '+7%'],
            ], [0.4, 0.2, 0.2, 0.2], ['l', 'r', 'r', 'r']),
            ['Technology has the largest increase.', 'Marketing is flat.'],
            'Source: replace with the budget workbook.',
            'A budget table must foot: check that the lines add up to the total before you present. Mark the largest change and explain it in one sentence. Replace the five areas and amounts with your own.',
        );

        $c->beginSection('Trade offs');
        $c->compare(
            'What we add and what we stop',
            ['We add', ['Two analysts to cut report time', 'New scanners to replace old ones', 'A year of vendor support', 'Staff training']],
            ['We stop', ['Two low value subscriptions', 'Paper based approvals', 'Duplicate site visits', 'An unused office lease']],
            'Showing what you stop builds trust. Be specific enough that the finance team can check the savings. Replace both lists with your own and give a rand figure for the total of each side if you can.',
            ['reveal' => true],
        );

        $c->timeline(
            'When the money is spent',
            [['Quarter 1', 'Recruit and order equipment. 20 percent of budget.'], ['Quarter 2', 'Install and train. 30 percent.'], ['Quarter 3', 'Run at full capacity. 30 percent.'], ['Quarter 4', 'Review and close out. 20 percent.']],
            "Phasing shows that you have a plan for spending, and helps finance forecast cash. Replace each quarter's description and share with your own, and make sure they add up to 100 percent.",
        );

        $c->points(
            'Risks to the budget',
            [
                '**Costs rise faster than assumed.** We hold a 3 percent contingency.',
                '**Recruitment is delayed.** Savings are returned at the mid year review.',
                '**Equipment arrives late.** The supplier agreement includes penalties.',
            ],
            'Name the risks that could push the budget up or down and the action for each. Show that you have a contingency and a rule for returning unspent money. Replace the three lines with your own.',
            'We report variances every month.',
        );

        $c->closing(
            'For approval',
            'Approve the budget of R 7.9 million for [year].',
            'Replace with name, email and phone',
            'Restate the total and the outcome, and ask for approval. If conditions are attached, state them clearly. Confirm the date the approved budget will be loaded.',
        );
    }
}
