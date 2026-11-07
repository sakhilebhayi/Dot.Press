<?php

namespace App\Services\Press\Templates\Library;

use App\Services\Press\Templates\Compose;
use App\Services\Press\Templates\Template;

final class ConstructionProgress implements Template
{
    public static function meta(): array
    {
        return [
            'key' => 'construction-progress',
            'name' => 'Construction progress',
            'label' => 'Construction progress',
            'category' => 'operations',
            'blurb' => 'The monthly site progress report: progress against programme, the S curve, photographs, safety, variations and the three week look ahead. Programme, progress, risk, look ahead.',
            'narrative' => 'Programme and progress, photographs, safety, cost and variations, look ahead',
            'theme' => 'field-report',
            'tags' => ['construction', 'site', 'progress', 'programme', 'variations'],
            'transition' => ['type' => 'fade', 'dir' => 'l', 'dur' => 350, 'ease' => 'out'],
            'cover' => 'rule',
            'icon' => 'building',
        ];
    }

    public static function compose(Compose $c): void
    {
        $c->beginSection('Summary');
        $c->cover(
            '[Project name]: progress report',
            'Month [number], [period]',
            'Replace with site agent, client representative and date',
            'Open with where the project stands in a sentence: ahead, on programme or behind, and by how many days. Say that safety follows. Replace the grey line with the reporting period, your name and the date.',
        );

        $c->figures(
            'Progress at a glance',
            [['58%', 'Physical progress'], ['-6', 'Days against programme'], ['0', 'Lost time injuries'], ['12', 'Open variations']],
            'Six days behind, mostly from rain. Recovery plan later in this deck.',
            'Show four numbers a client can act on. Define progress and say how it is measured (for example by value of work done). The numbers here are samples: replace them with your own. A negative number of days means behind programme; say that aloud.',
            true,
        );

        $c->data(
            'Progress against programme (S curve)',
            Compose::chart('line', ['Mo 1', 'Mo 2', 'Mo 3', 'Mo 4', 'Mo 5', 'Mo 6', 'Mo 7', 'Mo 8'], [
                ['name' => 'Planned', 'values' => [4, 12, 24, 40, 58, 74, 88, 100]],
                ['name' => 'Actual', 'values' => [3, 10, 21, 36, 52, null, null, null]],
            ], ['title' => 'Cumulative progress', 'sub' => 'Percent complete', 'fmt' => 'pct', 'dec' => 0, 'smooth' => true]),
            ['Behind plan since month 2.', 'The gap is six percentage points.', 'The recovery plan closes it by month 7.'],
            'Source: replace with your programme.',
            'The S curve shows whether the gap is widening. Point to where actual leaves plan and say why. Show the forecast to completion if you have one. Replace the planned and actual values with your own.',
        );

        $c->imageSide(
            'left',
            'Work completed this month',
            ['Level 3 slab poured', 'Façade framing started on the east side', 'Services first fix complete on level 2'],
            'Site photograph of the main work completed, with the date and location',
            'Photographs prove progress. Use two or three, taken from the same positions each month, with the date. Replace the picture with a site photograph and the three lines with your own achievements.',
        );

        $c->beginSection('Site');
        $c->points(
            'Safety and quality on site',
            [
                '**Safety.** 24 000 hours worked with no lost time injury.',
                '**Inspections.** 18 completed, 2 actions open.',
                '**Quality.** 3 non conformances raised, 2 closed.',
            ],
            'Report the numbers and the one lesson of the month. Say what you changed on site because of it. Replace the three lines with your own safety and quality record.',
            'One lesson, shared with every crew.',
        );

        $c->data(
            'Variations and instructions',
            Compose::table([
                ['Ref', 'Description', 'Value', 'Status'],
                ['V-014', 'Additional basement waterproofing', 'R 185 000', 'Approved'],
                ['V-015', 'Changed lift specification', 'R 92 000', 'Pending'],
                ['V-016', 'Extra fire stopping on level 2', 'R 64 000', 'Pending'],
                ['V-017', 'Revised facade colour', 'R 0', 'Approved'],
            ], [0.14, 0.5, 0.18, 0.18], ['l', 'l', 'r', 'l'], null, 26),
            ['Two variations await a decision.', 'Time effect of V-015 is two weeks.'],
            'Source: replace with your variation register.',
            'Keep to the items that need attention: those pending, and any that affect time. Say what you need from the client and by when. Replace the four rows with your own register.',
        );

        $c->beginSection('Look ahead');
        $c->timeline(
            'Three week look ahead',
            [['Week 1', 'Pour level 4 slab. Begin roof steel.'], ['Week 2', 'East facade glazing. Services second fix.'], ['Week 3', 'Roof sheeting. Lift installation begins.'], ['Constraint', 'Crane access closed on Friday of week 2.']],
            'Show what is planned and what could block it. Name the constraint so it can be resolved before it costs a day. Replace the four items with your own look ahead.',
        );

        $c->compare(
            'Recovering the six days',
            ['Actions in hand', ['Add a second shift for the slab', 'Pre-order roof steel', 'Move the lift delivery forward']],
            ['What we need from the client', ['Decision on V-015 within a week', 'Early access to the east wing', 'Confirmation of the colour schedule']],
            'A recovery plan has two sides: what the contractor will do and what the client must do. Make both specific, with dates. Replace both columns with your own.',
        );

        $c->closing(
            'Questions and decisions',
            'Decisions needed: V-015 and V-016 by [date].',
            'Replace with site agent contact',
            'Ask for the decisions listed, record who gave them and when, and confirm the date of the next report. Offer a site walk.',
        );
    }
}
