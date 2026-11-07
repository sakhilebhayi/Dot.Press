<?php

namespace App\Services\Press\Templates\Library;

use App\Services\Press\Templates\Compose;
use App\Services\Press\Templates\Template;

final class FinancialReview implements Template
{
    public static function meta(): array
    {
        return [
            'key' => 'financial-review',
            'name' => 'Financial review',
            'label' => 'Financial review',
            'category' => 'finance',
            'blurb' => 'A monthly or quarterly financial review: headline results, a profit bridge, the income statement, cash, and the outlook. Result, explanation, outlook.',
            'narrative' => 'Result, explanation, outlook: headline numbers, bridge, statements, cash, forecast',
            'theme' => 'ledger',
            'tags' => ['finance', 'results', 'profit', 'cash', 'forecast'],
            'transition' => ['type' => 'fade', 'dir' => 'l', 'dur' => 350, 'ease' => 'out'],
            'cover' => 'rule',
            'icon' => 'money',
        ];
    }

    public static function compose(Compose $c): void
    {
        $c->beginSection('Headline');
        $c->cover(
            'Financial review',
            'Results, what explains them and what we expect next',
            'Replace with period, finance lead and date',
            'Say in a sentence whether the period was good, fair or poor against budget, then promise the explanation. Finance audiences prefer the conclusion first. Replace the grey line with the period you are reviewing and your name.',
        );

        $c->figures(
            'The period in four numbers',
            [['9.8m', 'Revenue, rand'], ['2.1m', 'Operating profit'], ['21%', 'Operating margin'], ['3.4m', 'Cash at period end']],
            'Profit is above budget; cash is slightly below plan.',
            "Pick the four numbers the reader will ask for first. Give each against budget and prior period. The numbers here are samples: replace them with figures from your management accounts. Say the one that needs the reader's attention.",
            true,
            ['reveal' => true],
        );

        $c->beginSection('Explanation');
        $c->data(
            'What moved profit from last year to this year',
            Compose::chart('waterfall', ['Last year', 'Volume', 'Price', 'Material costs', 'Wages', 'Other', 'This year'], [
                ['name' => 'Operating profit', 'values' => [1.6, 0.9, 0.5, -0.4, -0.3, -0.2, null]],
            ], ['title' => 'Operating profit bridge', 'sub' => 'Rand millions', 'fmt' => 'num', 'dec' => 1, 'labels' => 'value', 'legend' => 'none'], ['totals' => [0, 6]]),
            ['Volume and price added 1.4 million.', 'Cost rises took back 0.9 million.', 'Net improvement of 0.5 million.'],
            'Source: replace with your management accounts.',
            "A bridge answers 'why did profit change' faster than any table. Start at last year, add each driver in order of size, and end at this year. Say the biggest positive and the biggest negative. Replace the labels and values with your own drivers; totals must still add up.",
        );

        $c->data(
            'Income statement',
            Compose::table([
                ['Rand millions', 'Actual', 'Budget', 'Prior year'],
                ['Revenue', '9.8', '9.4', '8.6'],
                ['Cost of sales', '5.4', '5.3', '4.9'],
                ['Gross profit', '4.4', '4.1', '3.7'],
                ['Operating costs', '2.3', '2.3', '2.1'],
                ['Operating profit', '2.1', '1.8', '1.6'],
            ], [0.4, 0.2, 0.2, 0.2], ['l', 'r', 'r', 'r']),
            ['Gross margin 45 percent, up two points.', 'Operating costs are on budget.', 'Operating profit beats budget by 0.3 million.'],
            'Source: replace with your trial balance.',
            'Keep the statement to six lines so each figure can be read in the meeting. Use the same units and order throughout. If a line is under discussion, mention it first. Replace all figures with your own.',
        );

        $c->data(
            'Cash held up through the busy season',
            Compose::chart('line', ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'], [
                ['name' => 'Cash at month end', 'values' => [2.9, 2.6, 2.4, 2.8, 3.1, 3.4]],
            ], ['title' => 'Cash at month end', 'sub' => 'Rand millions', 'fmt' => 'num', 'dec' => 1, 'legend' => 'none', 'smooth' => false, 'labels' => 'value']),
            ['Low point in March after stock purchases.', 'Collections improved in April.', 'No overdraft used.'],
            'Source: replace with your bank reconciliation.',
            'Cash tells you whether profit is real. Show the low point and the reason for it, and say whether you used any facility. Replace the six months with your own period and keep the headline true to the data.',
        );

        $c->compare(
            'Budget against forecast for the year',
            ['Original budget', ['Revenue 19.4 million', 'Operating margin 19 percent', 'Capital spend 1.2 million', 'Year end cash 3.0 million']],
            ['Current forecast', ['Revenue 20.1 million', 'Operating margin 20 percent', 'Capital spend 1.4 million', 'Year end cash 3.2 million']],
            'Reforecasting is a promise to the board, so make the changes visible. Highlight which line moved most and why. Replace both columns with your figures, and keep the four lines in the same order.',
            ['reveal' => 'right'],
        );

        $c->beginSection('Outlook');
        $c->points(
            'Risks and opportunities to the forecast',
            [
                '**Risk.** A weaker rand raises input costs by about 3 percent.',
                '**Risk.** Two customers pay late.',
                '**Opportunity.** A new price list starts next quarter.',
            ],
            "Name the items that could move the forecast and size each if you can. Mark which are within management's control. Replace the three lines with your own and add a number to each.",
            'Size each one, even roughly.',
        );

        $c->timeline(
            'What finance will do next',
            [['This month', 'Close the quarter and circulate the pack.'], ['Next month', 'Review pricing with sales.'], ['Next quarter', 'Update the forecast and cash plan.'], ['Year end', 'Agree the budget for next year.']],
            'Close with the finance calendar so the reader knows when the next numbers arrive. Replace the four steps with your own dates.',
        );

        $c->closing(
            'Questions on the numbers',
            'Decisions needed: [approve the forecast] and [agree the pricing review].',
            'Replace with name, email and phone',
            'Invite questions on the numbers first, then raise the decisions. Offer to send the full workbook and say when. If a question needs more work, name who will answer and by when.',
        );
    }
}
