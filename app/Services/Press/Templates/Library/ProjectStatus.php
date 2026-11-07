<?php

namespace App\Services\Press\Templates\Library;

use App\Services\Press\Templates\Compose;
use App\Services\Press\Templates\Template;

final class ProjectStatus implements Template
{
    public static function meta(): array
    {
        return [
            'key' => 'project-status-update',
            'name' => 'Project status update',
            'label' => 'Project status',
            'category' => 'operations',
            'blurb' => 'A steering committee update in a fixed order: status in one line, progress against plan, milestones, budget, risks and issues, then decisions. Status, progress, risk, decision.',
            'narrative' => 'Status summary, progress, milestones, budget, risks and issues, decisions needed',
            'theme' => 'slate',
            'tags' => ['project', 'status', 'steering committee', 'milestones', 'risks'],
            'transition' => ['type' => 'push', 'dir' => 'l', 'dur' => 350, 'ease' => 'out'],
            'cover' => 'split',
            'icon' => 'flag',
        ];
    }

    public static function compose(Compose $c): void
    {
        $c->beginSection('Summary');
        $c->cover(
            '[Project name] status update',
            'Report for [period], steering committee',
            'Replace with project manager, sponsor and date',
            'Give the status in one sentence at the start: on track, at risk or off track, and the main reason. The committee should not have to hunt for it. Then say how long you need and what decisions you want. Replace the grey line with your names and the date.',
        );

        $c->figures(
            'Where the project stands',
            [['62%', 'Complete'], ['71%', 'Budget used'], ['34', 'Days to go live'], ['5', 'Open risks']],
            'Amber: spend is ahead of progress by nine points.',
            'Say the overall status first, then explain it with these four numbers. Spend ahead of progress is a classic early warning, so name it if it applies. The figures are samples: replace them with your own project data, and agree with the sponsor how each is measured.',
            true,
            ['reveal' => true],
        );

        $c->beginSection('Progress');
        $c->data(
            'Progress by workstream',
            Compose::table([
                ['Workstream', 'Progress', 'Status', 'Comment'],
                ['Design', '100%', 'Green', 'Signed off'],
                ['Build', '70%', 'Amber', 'Two weeks behind'],
                ['Data migration', '40%', 'Red', 'Waiting for data'],
                ['Training', '20%', 'Green', 'On schedule'],
                ['Go live', '0%', 'Green', 'Date unchanged'],
            ], [0.26, 0.16, 0.16, 0.42], ['l', 'r', 'l', 'l'], null, 26),
            ['Data migration is the critical item.', 'Build is catching up with extra capacity.'],
            'Source: replace with your project plan.',
            'Use the same status words every time: green, amber, red. Define them once: red means a decision or help is needed. Name the red item first and say what it needs. Replace the five rows with your own workstreams.',
        );

        $c->data(
            'Milestones: what is done and what is next',
            Compose::chart('bar', ['Kick off', 'Design signed off', 'Build complete', 'Data migrated', 'Go live'], [
                ['name' => 'Percent complete', 'values' => [100, 100, 70, 40, 0]],
            ], ['title' => 'Milestone progress', 'sub' => 'Percent complete', 'fmt' => 'pct', 'dec' => 0, 'labels' => 'value', 'legend' => 'none', 'ax' => ['x' => ['min' => 0, 'max' => 100, 'show' => true]]]),
            ['Two milestones complete.', 'Build and data migration are in flight.', 'Go live date has not moved.'],
            'Source: replace with your milestone plan.',
            'Show milestones as a short list with progress, and say whether any date has moved since the last update. If a date has moved, show the old and new dates. Replace the five milestones with your own.',
        );

        $c->beginSection('Budget');
        $c->data(
            'Budget: spend against progress',
            Compose::chart('column', ['Month 1', 'Month 2', 'Month 3', 'Month 4', 'Month 5'], [
                ['name' => 'Planned spend', 'values' => [60, 130, 220, 310, 390]],
                ['name' => 'Actual spend', 'values' => [55, 140, 245, 340, 415]],
            ], ['title' => 'Cumulative spend', 'sub' => 'Rand thousands', 'fmt' => 'num', 'dec' => 0]),
            ['Spend is 6 percent above plan.', 'The overrun comes from contract resources.', 'Forecast at completion: 3 percent over budget.'],
            'Source: replace with your finance report.',
            'Compare cumulative spend with plan, then say the forecast at completion and what is driving any overrun. If you need contingency released, say so here and again on the decisions slide. Replace the values with your own.',
        );

        $c->beginSection('Risks and decisions');
        $c->points(
            'Top risks and issues',
            [
                '**Issue.** Source data is late. Owner: [name]. Due: [date].',
                '**Risk.** Key developer unavailable in month six. Mitigation: a second developer is being briefed.',
                '**Risk.** Training overlaps with year end. Mitigation: sessions moved earlier.',
            ],
            'An issue has already happened; a risk may. Keep them separate. Give each an owner and a date, and say whether it needs the committee. Replace the lines with your own, and keep the list to the top three.',
            'Issues have owners and dates.',
        );

        $c->timeline(
            'Next four weeks',
            [['Week 1', 'Receive the data and start the load.'], ['Week 2', 'Complete the build.'], ['Week 3', 'User testing begins.'], ['Week 4', 'Training and go live readiness review.']],
            'Show only what will happen before the next update. The committee should be able to check you against this slide next time. Replace the four steps with your own.',
        );

        $c->points(
            'Decisions needed from this committee',
            ['ol' => ['Approve release of [amount] contingency', 'Confirm [name] to provide the source data by [date]', 'Agree to move go live by [one week] if the data is late']],
            'List only decisions the committee can make today, and state the consequence of not deciding. If there are none, say so; this slide is optional. Replace the three lines with your own decisions.',
            'Each decision has a date it is needed by.',
        );

        $c->closing(
            'Questions and discussion',
            'Next update on [date]. Send questions to [name] before then.',
            'Replace with project manager contact',
            'Invite questions, record decisions and read them back. Confirm the date of the next update. Send the summary and actions within a day.',
        );
    }
}
