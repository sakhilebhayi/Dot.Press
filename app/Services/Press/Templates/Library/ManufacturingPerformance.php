<?php

namespace App\Services\Press\Templates\Library;

use App\Services\Press\Templates\Compose;
use App\Services\Press\Templates\Template;

final class ManufacturingPerformance implements Template
{
    public static function meta(): array
    {
        return [
            'key' => 'manufacturing-performance',
            'name' => 'Manufacturing performance',
            'label' => 'Manufacturing performance',
            'category' => 'operations',
            'blurb' => 'The plant performance review built on OEE: availability, performance and quality, the losses behind them, output by line, quality and the improvement actions. Measure, find the loss, act.',
            'narrative' => 'OEE headline, loss waterfall, output, downtime Pareto, quality, actions',
            'theme' => 'engineering',
            'tags' => ['manufacturing', 'oee', 'plant', 'downtime', 'quality', 'lean'],
            'transition' => ['type' => 'fade', 'dir' => 'l', 'dur' => 300, 'ease' => 'out'],
            'cover' => 'split',
            'icon' => 'factory',
        ];
    }

    public static function compose(Compose $c): void
    {
        $c->beginSection('Headline');
        $c->cover(
            'Plant performance review',
            '[Plant name], [month and year]',
            'Replace with plant manager, date and shift cover',
            'Open with the overall equipment effectiveness for the month and the one loss that mattered most. Explain in a sentence that OEE multiplies availability, performance and quality, so a gap in any one shows up. Replace the grey line with your details.',
        );

        $c->figures(
            'OEE and its three parts',
            [['71%', 'OEE'], ['88%', 'Availability'], ['89%', 'Performance'], ['96%', 'Quality']],
            'OEE is 71 percent: 88 times 89 times 96. Performance and availability hold it back.',
            'OEE is availability times performance times quality, so say which part is the weakest. The numbers here are samples: replace them with your own plant data and state the definition of ideal cycle time you use. World class is often quoted at 85 percent, but compare against your own trend first.',
            true,
            ['reveal' => true],
        );

        $c->beginSection('Losses');
        $c->data(
            'Where the capacity went',
            Compose::chart('waterfall', ['Planned', 'Breakdowns', 'Setups', 'Stops', 'Speed', 'Scrap', 'Output'], [
                ['name' => 'Percent of available time', 'values' => [100, -7, -5, -7, -4, -4, null]],
            ], ['title' => 'OEE loss waterfall', 'sub' => 'Percent of planned production time', 'fmt' => 'num', 'dec' => 0, 'labels' => 'value', 'legend' => 'none'], ['totals' => [0, 6]]),
            ['Breakdowns and minor stops are the largest losses.', 'Together they take 14 points.', 'Focus the next month on those two.'],
            'Source: replace with your production data.',
            'A loss waterfall turns a single OEE number into a work list. Say the two largest losses and what is under way for each. Replace the loss categories and values with your own, and check that good output equals 100 minus the losses.',
        );

        $c->data(
            'Output by line against target',
            Compose::chart('column', ['Line 1', 'Line 2', 'Line 3', 'Line 4'], [
                ['name' => 'Target', 'values' => [120, 120, 90, 90]],
                ['name' => 'Actual', 'values' => [118, 104, 91, 76]],
            ], ['title' => 'Units per shift', 'sub' => 'Average per shift', 'fmt' => 'num', 'dec' => 0, 'labels' => 'value']),
            ['Lines 1 and 3 are on target.', 'Line 4 is 16 percent short.', 'Line 2 lost output to changeovers.'],
            'Source: replace with the plant production report.',
            'Compare lines on the same chart so the weakest is obvious. Name the cause for each shortfall. Replace the line names and values with your own, and add shift or product splits only if the question requires them.',
        );

        $c->data(
            'Top downtime causes',
            Compose::chart('bar', ['Filler jams', 'Label changeover', 'Material shortage', 'Power dips', 'Operator absent'], [
                ['name' => 'Hours lost', 'values' => [34, 27, 19, 12, 8]],
            ], ['title' => 'Downtime by cause', 'sub' => 'Hours in the month', 'fmt' => 'num', 'dec' => 0, 'labels' => 'value', 'legend' => 'none']),
            ['Two causes explain 60 percent of the downtime.', 'Filler jams have a known fix.', 'Start with those two.'],
            'Source: replace with the downtime log.',
            'A Pareto ranking tells you where effort pays: the first two or three causes usually explain most of the loss. Show the sorted bars and say the share. Replace the five causes with your own from the downtime log.',
        );

        $c->beginSection('Quality');
        $c->data(
            'Quality: scrap and rework by product',
            Compose::table([
                ['Product', 'Made', 'Scrap', 'Rework', 'First pass yield'],
                ['Product A', '42 000', '1.6%', '2.0%', '96.4%'],
                ['Product B', '18 500', '3.1%', '4.2%', '92.7%'],
                ['Product C', '9 200', '2.0%', '1.8%', '96.2%'],
            ], [0.26, 0.17, 0.15, 0.15, 0.27], ['l', 'r', 'r', 'r', 'r']),
            ['Product B has the worst yield.', 'The cause is a worn die; replacement is ordered.'],
            'Source: replace with the quality system.',
            'Show first pass yield by product and name the one that needs action. Say how the issue was found and when it will be fixed. Replace the three rows with your own products and measures.',
        );

        $c->compare(
            'Day shift and night shift',
            ['Day shift', ['OEE 74 percent', 'Fewer minor stops', 'Maintenance on site', 'Supervisor ratio 1 to 12']],
            ['Night shift', ['OEE 66 percent', 'Slower restarts after stops', 'On call maintenance', 'Supervisor ratio 1 to 20']],
            'Compare shifts to find practices worth spreading, not to blame. Look for cause: maintenance cover, supervision, training. Replace both columns with your own comparison, and agree with shift leaders before you present it.',
        );

        $c->beginSection('Actions');
        $c->timeline(
            'Improvement actions this month',
            [['Week 1', 'Fix filler jams: replace wear parts.'], ['Week 2', 'Standard work for label changeover.'], ['Week 3', 'Stock check for packaging materials.'], ['Week 4', 'Review OEE and downtime again.']],
            'Each action should have an owner and a measure of success. Keep to four actions the plant can complete in the month. Replace them with your own.',
        );

        $c->points(
            'What we ask of the leadership team',
            [
                '**Budget.** Approve [amount] for a replacement die.',
                '**People.** Release two operators for changeover training.',
                '**Maintenance.** Add a night shift planned maintenance slot.',
            ],
            'Ask for specific help with an amount, a number of people or a time slot. Link each ask to a loss on the waterfall so the benefit is clear. Replace the three lines with your own.',
            'Each ask removes a named loss.',
        );

        $c->closing(
            'Questions and next review',
            'We will present the same measures next month.',
            'Replace with plant manager contact',
            'Close by repeating the one loss you will fix first. Confirm the date of the next review and who attends.',
        );
    }
}
