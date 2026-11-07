<?php

namespace App\Services\Press\Templates\Library;

use App\Services\Press\Templates\Compose;
use App\Services\Press\Templates\Template;

final class MiningProduction implements Template
{
    public static function meta(): array
    {
        return [
            'key' => 'mining-production-performance',
            'name' => 'Mining production performance',
            'label' => 'Production performance',
            'category' => 'operations',
            'blurb' => 'The monthly production review for a mine: safety first, then tonnes against plan, grade and recovery, unit cost, equipment availability and the look ahead. Safety, production, cost, outlook.',
            'narrative' => 'Safety first, then production against plan, quality, cost, equipment, issues and look ahead',
            'theme' => 'site-operations',
            'tags' => ['mining', 'production', 'tonnes', 'grade', 'recovery', 'availability'],
            'transition' => ['type' => 'fade', 'dir' => 'l', 'dur' => 300, 'ease' => 'out'],
            'cover' => 'band',
            'icon' => 'pickaxe',
        ];
    }

    public static function compose(Compose $c): void
    {
        $c->beginSection('Safety');
        $c->cover(
            'Production performance review',
            '[Mine or shaft name], [month and year]',
            'Replace with presenter, role and date',
            "Begin the way every meeting at site begins: with a safety moment. Ask the room for a hazard seen or a near miss from the last month before you show a single production number. Then say the month's headline in one sentence: tonnes against plan, and the main reason. Replace the grey line with your details.",
        );

        $c->figures(
            'Safety first: the month in four numbers',
            [['0', 'Fatalities'], ['1', 'Lost time injuries'], ['0.42', 'LTIFR, rolling 12 months'], ['137', 'Days since last LTI']],
            'One lost time injury this month. The review of it is on the next slide.',
            "Show safety before production and give it time. Read the numbers and then the story behind the injury. Do not look away from a bad month: the room trusts a presenter who names it. The figures here are samples: replace them with your own, and use your company's definitions.",
            true,
        );

        $c->points(
            'Lost time injury: what happened and what we changed',
            [
                '**What happened.** One sentence on the event, with no names.',
                '**Why.** The cause found by the investigation.',
                '**What we changed.** The control now in place, and who checks it.',
            ],
            'Describe the event factually and without blame. Focus on the cause and the control. Say what has been done on other shifts and sections so the same event cannot happen again. Replace each line with your own incident, or delete this slide if there was none.',
            'Learning, shared across every shift.',
        );

        $c->beginSection('Production');
        $c->data(
            'Tonnes hoisted against plan',
            Compose::chart('combo', ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'], [
                ['name' => 'Actual tonnes', 'values' => [182, 176, 190, 168, 185, 197], 'kind' => 'bar'],
                ['name' => 'Plan', 'values' => [180, 180, 185, 185, 190, 190], 'kind' => 'line'],
            ], ['title' => 'Tonnes hoisted', 'sub' => 'Thousand tonnes per month', 'fmt' => 'num', 'dec' => 0]),
            ['Above plan in four of six months.', 'April was short after a hoist stoppage.', 'June is the best month of the half year.'],
            'Source: replace with the production report.',
            'Show actual against plan in one picture: bars for actual, a line for plan. Name the month that missed and the cause. Replace the months and tonnes with your own, and use the units your site reports in.',
        );

        $c->data(
            'Grade and recovery',
            Compose::chart('line', ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'], [
                ['name' => 'Head grade (g/t)', 'values' => [4.1, 4.0, 4.2, 3.8, 3.9, 4.1]],
                ['name' => 'Plan grade (g/t)', 'values' => [4.0, 4.0, 4.0, 4.0, 4.0, 4.0]],
            ], ['title' => 'Head grade against plan', 'sub' => 'Grams per tonne', 'fmt' => 'num', 'dec' => 1, 'smooth' => false]),
            ['Grade was below plan in April and May.', 'Dilution from the new stope explains most of it.', 'Recovery held at plan.'],
            'Source: replace with the metallurgical accounting report.',
            'Grade moves value more than tonnes do, so show it separately. Say what drove each month: dilution, mix of stopes, or sampling. Replace the values with your own, and add recovery as a second chart if it moved.',
        );

        $c->imageFull(
            'The new decline reached its second level in June.',
            'Replace with the site, level and date of the photo',
            'A site photograph that shows progress: a face, a decline, a plant, a stockpile',
            'A site photograph tells the room what is happening faster than a paragraph. Choose one that shows a milestone and say where it was taken. Replace the picture with a real photograph, with the permission required on your site.',
        );

        $c->beginSection('Cost');
        $c->data(
            'Unit cost: what moved it',
            Compose::chart('waterfall', ['Plan', 'Volume', 'Labour', 'Power', 'Consumables', 'Maintenance', 'Actual'], [
                ['name' => 'Cost per tonne', 'values' => [1285, -45, 20, 35, 15, -10, null]],
            ], ['title' => 'Unit cost bridge', 'sub' => 'Rand per tonne, axis starts at 1 200', 'fmt' => 'num', 'dec' => 0, 'labels' => 'value', 'legend' => 'none', 'ax' => ['y' => ['min' => 1200, 'max' => 1340, 'show' => true]]], ['totals' => [0, 6]]),
            ['Volume above plan lowered the unit cost.', 'Power and labour pushed it back up.', 'Net: 15 rand per tonne above plan.'],
            'Source: replace with the cost report.',
            'A bridge from planned to actual unit cost shows the room which lines to manage. Name the largest increase and the action under way. Replace the lines and values with your own and check that the totals add up.',
        );

        $c->data(
            'Equipment availability',
            Compose::table([
                ['Equipment', 'Target', 'Actual', 'Main loss'],
                ['Production drills', '85%', '88%', 'Planned service'],
                ['Loaders', '82%', '79%', 'Tyre failures'],
                ['Trucks', '80%', '83%', 'Waiting for parts'],
                ['Main hoist', '95%', '91%', 'Rope inspection'],
            ], [0.3, 0.16, 0.16, 0.38], ['l', 'r', 'r', 'l']),
            ['Hoist availability cost about 6 000 tonnes.', 'Loader tyre supply is the recurring issue.'],
            'Source: replace with the maintenance system.',
            'Availability explains most production misses, so show it by fleet. Say which gap cost the most tonnes. Replace the rows with your own fleet and the loss reasons with the top cause per row.',
        );

        $c->beginSection('Outlook');
        $c->compare(
            'Last month and next month',
            ['Delivered', ['Hoisted 197 000 tonnes', 'Opened the second decline level', 'Completed the rope inspection', 'Trained 40 operators']],
            ['Planned', ['Hoist 192 000 tonnes', 'Start the new stope', 'Replace two loader tyre sets', 'Complete ventilation upgrade']],
            'Close the loop between what you said last month and what happened. Be honest about slippage. Replace both columns with your own deliverables.',
        );

        $c->timeline(
            'Three month look ahead',
            [['Month 1', 'Ventilation upgrade complete.'], ['Month 2', 'New stope in production.'], ['Month 3', 'Hoist major service.'], ['Month 3', 'Review of the safety plan.']],
            "Show the next quarter's main events so that other teams can plan around them. Note any activity that will reduce production, such as the hoist service, and the mitigation. Replace the four steps with your own.",
        );

        $c->closing(
            'Questions and support needed',
            'Decisions needed: [spares budget] and [approval for the new stope].',
            'Replace with mine manager contact',
            'Close by asking for the decisions or help you need from the room: parts, budget, access. End the meeting by repeating the safety message.',
        );
    }
}
