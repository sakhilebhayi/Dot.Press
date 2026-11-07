<?php

namespace App\Services\Press\Templates\Library;

use App\Services\Press\Templates\Compose;
use App\Services\Press\Templates\Template;

final class BoardPack implements Template
{
    public static function meta(): array
    {
        return [
            'key' => 'board-pack',
            'name' => 'Board pack',
            'label' => 'Board pack',
            'category' => 'business',
            'blurb' => 'A board meeting deck in paper order: purpose, decisions required, performance, risk and governance, then resolutions. Decisions come before the detail.',
            'narrative' => 'Board paper convention: purpose and decisions required, performance, risk and governance, resolutions',
            'theme' => 'annual-report',
            'tags' => ['board', 'governance', 'directors', 'risk', 'resolutions'],
            'transition' => ['type' => 'fade', 'dir' => 'l', 'dur' => 400, 'ease' => 'out'],
            'cover' => 'rule',
            'icon' => 'scale',
        ];
    }

    public static function compose(Compose $c): void
    {
        $c->beginSection('Purpose');
        $c->cover(
            'Board meeting pack',
            'For decision and discussion',
            'Replace with meeting date, venue and chair',
            'Open as the chair or company secretary would: confirm the date, the quorum and that the pack was circulated in time. Tell the board what kind of meeting this is: items marked for decision need a resolution, items marked for noting do not. Replace the grey line with the meeting details.',
        );

        $c->points(
            'Decisions required today',
            ['ol' => ['Approve the minutes of the previous meeting', 'Approve the annual budget for the coming year', 'Approve the revised risk appetite statement', 'Note the committee reports']],
            'List every decision before any discussion so directors can plan their time. Mark which need a vote and which are for noting. State the paper number beside each item when you adapt this slide. If an item is contentious, say so now and agree how much time it gets.',
            'Four items, two need a vote.',
        );

        $c->section('01', 'Performance', 'How the company performed against budget and prior year', 'Move to the numbers. Remind the board that the full financial statements are in the appendix, and that this section covers only what changes a decision.');

        $c->figures(
            'Year to date at a glance',
            [['14.2m', 'Revenue, rand'], ['18%', 'Operating margin'], ['6.1m', 'Cash at bank, rand'], ['212', 'Employees']],
            'Revenue and margin are ahead of budget; cash is steady.',
            'Four numbers are enough for a board. Read each against its budget and say whether it is above, on or below. These sample figures are illustrative: replace them with year to date results from your management accounts. Name the one figure the board should ask questions about.',
            true,
        );

        $c->data(
            'Revenue by quarter against the prior year',
            Compose::chart('column', ['Q1', 'Q2', 'Q3', 'Q4'], [
                ['name' => 'Prior year', 'values' => [3.1, 3.3, 3.4, 3.6]],
                ['name' => 'This year', 'values' => [3.4, 3.6, 3.7, 3.5]],
            ], ['title' => 'Revenue', 'sub' => 'Rand millions', 'fmt' => 'num', 'dec' => 1, 'labels' => 'value']),
            ['Growth in the first three quarters.', 'The fourth quarter slipped below last year.', 'Management explains the slip on the next slide.'],
            'Source: replace with management accounts.',
            'Show the shape of the year, then name the exception. Directors will notice the dip in the fourth quarter whether or not you mention it, so mention it first and say what management knows about the cause. Replace the values with your own and rewrite the headline if the story changes.',
        );

        $c->data(
            'Financial summary',
            Compose::table([
                ['Rand millions', 'Actual', 'Budget', 'Variance'],
                ['Revenue', '14.2', '13.6', '+0.6'],
                ['Cost of sales', '8.1', '7.9', '+0.2'],
                ['Operating expenses', '3.5', '3.4', '+0.1'],
                ['Operating profit', '2.6', '2.3', '+0.3'],
            ], [0.4, 0.2, 0.2, 0.2], ['l', 'r', 'r', 'r']),
            ['Profit is ahead because revenue grew faster than cost.', 'Operating expenses are close to budget.'],
            'Source: replace with the signed management accounts.',
            'Keep the table to five lines; the full statements belong in the appendix. Use a plus sign for favourable and a minus sign for unfavourable, and say once which way you are reading variance. Replace every figure with your own.',
        );

        $c->section('02', 'Risk and governance', 'What could change the outcome, and what the board must note', 'Explain that this section covers the top risks and the standing compliance items. Directors carry personal duties here, so slow down.');

        $c->data(
            'Top risks and who owns them',
            Compose::table([
                ['Risk', 'Likelihood', 'Impact', 'Owner'],
                ['Key customer concentration', 'Medium', 'High', 'Chief executive'],
                ['Cyber security incident', 'Medium', 'High', 'Chief technology officer'],
                ['Regulatory change', 'Low', 'Medium', 'Company secretary'],
                ['Loss of skilled staff', 'High', 'Medium', 'Head of people'],
            ], [0.36, 0.2, 0.16, 0.28], ['l', 'l', 'l', 'l']),
            ['Two risks rate high on impact.', 'Each has a named owner and a quarterly review.'],
            'Source: replace with your risk register.',
            'Show the four or five risks the board should actively watch, not the whole register. Ask the committee chair to confirm the ratings. If a risk has moved since the last meeting, say which way and why. Replace the sample risks with your own.',
            true,
        );

        $c->compare(
            'Strategic options for the board to consider',
            ['Option 1: organic growth', ['Lowest risk and cost', 'Growth of 8 to 10 percent', 'No change to the balance sheet', 'Slower share gain']],
            ['Option 2: acquisition', ['Faster entry to a new region', 'Needs new funding', 'Integration risk for two years', 'Board approval of terms required']],
            "Present options so directors can compare on the same criteria. State management's preference and the evidence behind it, but let the board see the alternative at its strongest. Replace both options and all eight lines with your own.",
        );

        $c->points(
            'Governance and compliance matters for noting',
            [
                '**Audit committee.** Met in [month]; no matters of concern raised.',
                '**Remuneration committee.** Reviewed senior pay against the market.',
                '**Compliance.** All statutory filings are up to date.',
                '**Conflicts of interest.** Register reviewed and unchanged.',
            ],
            'These are for noting, so keep it brief. Each line states what was done and whether anything needs the board. If a committee wants a decision, move it to the decisions slide. Replace the lines with your own and delete any that do not apply.',
            'For noting. No vote needed.',
        );

        $c->timeline(
            'Board calendar for the next twelve months',
            [['Quarter 1', 'Budget approval and strategy review.'], ['Quarter 2', 'Annual general meeting and audit sign off.'], ['Quarter 3', 'Mid year performance and risk review.'], ['Quarter 4', 'Succession planning and next year budget.']],
            'Directors appreciate knowing what is coming so they can plan. Agree the dates here and send invitations straight after the meeting. Replace the four quarters with your own cycle.',
        );

        $c->closing(
            'Resolutions',
            'The board is asked to approve items 1 to 3 and to note item 4.',
            'Replace with company secretary name, email and phone',
            'Read the resolutions exactly as they will appear in the minutes. Ask for a mover and a seconder and record each vote. Close by confirming the date of the next meeting.',
        );
    }
}
