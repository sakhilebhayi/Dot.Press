<?php

namespace App\Services\Press\Templates\Library;

use App\Services\Press\Templates\Compose;
use App\Services\Press\Templates\Template;

final class EngineeringProposal implements Template
{
    public static function meta(): array
    {
        return [
            'key' => 'engineering-proposal',
            'name' => 'Engineering proposal',
            'label' => 'Engineering proposal',
            'category' => 'operations',
            'blurb' => 'A technical proposal for a design review or a client: the problem, the requirements, the options, how they compare, the recommendation, the plan and the risks.',
            'narrative' => 'Problem, requirements, options, evaluation, recommendation, plan, risks',
            'theme' => 'engineering',
            'tags' => ['engineering', 'design review', 'options', 'trade off', 'technical'],
            'transition' => ['type' => 'fade', 'dir' => 'l', 'dur' => 300, 'ease' => 'out'],
            'cover' => 'band',
            'icon' => 'gear',
        ];
    }

    public static function compose(Compose $c): void
    {
        $c->beginSection('Problem');
        $c->cover(
            '[System or project]: engineering proposal',
            'Problem, options and recommended design',
            'Replace with author, reviewers, revision and date',
            'State what the document is for (a design decision or an approval) and who must approve. Give the revision and date so reviewers know they have the latest. Say how long the review will take and what feedback you want. Replace the grey line with your details.',
        );

        $c->points(
            'The problem and what the solution must do',
            [
                '**Problem.** What fails, wears out or is missing, and the consequence.',
                '**Must have.** The requirements that cannot be traded, with numbers.',
                '**Constraints.** Budget, space, schedule, standards and interfaces.',
            ],
            "Write the requirements as testable statements with numbers, for example 'carries 12 tonnes at 0.5 metres per second'. Separate what is required from what is wanted. Cite the standard for each constraint. Replace the three lines with your own.",
            'If it cannot be tested, it is not a requirement.',
        );

        $c->imageSide(
            'left',
            'The current arrangement',
            ['Where the problem occurs', 'What the existing design does', 'Measured performance today'],
            'A schematic or photo of the current system with the problem area marked',
            'Show the current system so reviewers share the same picture. Mark where the problem occurs, and give one or two measurements. Replace the picture with a drawing or photo and the three lines with your own findings.',
        );

        $c->beginSection('Options');
        $c->compare(
            'Two options we considered',
            ['Option A: refurbish', ['Keeps the existing structure', 'Lowest capital cost', 'Extends life by 5 years', 'Repeat inspection every year']],
            ['Option B: replace', ['New design to current standard', 'Higher capital cost', 'Life of 25 years', 'Inspection every five years']],
            'Describe each option fairly, with the same attributes in the same order. Include the do nothing case in the appendix. Do not argue for your preference on this slide: save it for the evaluation. Replace both columns with your own options.',
        );

        $c->data(
            'How the options compare',
            Compose::table([
                ['Criterion', 'Weight', 'Option A', 'Option B'],
                ['Safety', '30%', '3', '5'],
                ['Life cost over 25 years', '30%', '2', '4'],
                ['Time to implement', '20%', '5', '3'],
                ['Risk to operations', '20%', '4', '3'],
                ['Weighted score', '100%', '3.4', '4.0'],
            ], [0.4, 0.15, 0.225, 0.225], ['l', 'r', 'r', 'r']),
            ['Scores run from 1 to 5; higher is better.', 'Option B wins on safety and life cost.', 'Option A wins on speed.'],
            'Weights and scores are illustrative. Replace with your agreed criteria.',
            'Agree the criteria and weights before you score, and show that you did. Score each option with the people who know the system. State the result and then test it: does the answer change if the weights change by ten points? Replace the sample criteria and scores with your own.',
            true,
        );

        $c->data(
            'Life cost over 25 years',
            Compose::chart('column', ['Year 0', 'Year 5', 'Year 10', 'Year 15', 'Year 20', 'Year 25'], [
                ['name' => 'Option A: refurbish', 'values' => [1.2, 2.4, 4.1, 6.3, 8.9, 11.8]],
                ['name' => 'Option B: replace', 'values' => [4.8, 5.1, 5.6, 6.2, 7.0, 7.9]],
            ], ['title' => 'Cumulative cost', 'sub' => 'Rand millions', 'fmt' => 'num', 'dec' => 1]),
            ['Option B costs more at the start.', 'The costs cross at about year 15.', 'After that, Option B is cheaper every year.'],
            'Source: replace with your cost estimate.',
            'Show the whole life cost, not only the capital cost, and say which assumptions drive the crossover (for example inspection cost and discount rate). Replace the values with your estimate, and state the accuracy of it.',
        );

        $c->beginSection('Recommendation');
        $c->points(
            'Recommendation',
            [
                '**Recommended.** Option B: replace, to the current standard.',
                '**Why.** It meets the safety requirement and has the lowest life cost.',
                '**Condition.** Agreement of a shutdown window of [period].',
            ],
            'State the recommendation, the main reason and any condition. Say how confident you are and what would change your mind. Replace the three lines with your own.',
            'We will confirm cost within 10 percent at detailed design.',
        );

        $c->timeline(
            'Plan to complete',
            [['Months 1 to 2', 'Detailed design and approvals.'], ['Months 3 to 4', 'Procurement and fabrication.'], ['Month 5', 'Installation in the shutdown.'], ['Month 6', 'Commissioning and handover.']],
            'Show the critical path and the one date everything depends on (the shutdown). Name the approvals needed and by when. Replace the four steps with your own plan.',
        );

        $c->points(
            'Risks and assumptions',
            [
                '**Lead time.** Steel delivery may take longer. Order early.',
                '**Access.** The site must be isolated for six weeks.',
                '**Unknowns.** Foundation condition is assumed; test during design.',
            ],
            'List the assumptions you are making and the risks that would change the answer. For each, say how you will check it and when. Reviewers value being told. Replace the three lines with your own.',
            'Each assumption has a check.',
        );

        $c->closing(
            'Review and approval',
            'Please send comments by [date]. Approval is needed from [name].',
            'Replace with author contact and document number',
            'Say where the document lives, the review deadline and who approves. Offer to walk reviewers through any section. Record comments in the review log.',
        );
    }
}
