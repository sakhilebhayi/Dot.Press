<?php

namespace App\Services\Press\Templates\Library;

use App\Services\Press\Templates\Compose;
use App\Services\Press\Templates\Template;

final class SalesProposal implements Template
{
    public static function meta(): array
    {
        return [
            'key' => 'sales-proposal',
            'name' => 'Sales proposal',
            'label' => 'Proposal',
            'category' => 'sales',
            'blurb' => 'A client proposal in the problem, solution, value order: what we heard, what we propose, what it costs and what it returns, then the plan and the terms.',
            'narrative' => 'Problem, solution, value: client understanding, proposal, pricing and return, delivery plan, next steps',
            'theme' => 'boardroom',
            'tags' => ['proposal', 'client', 'pitch', 'pricing', 'sales'],
            'transition' => ['type' => 'fade', 'dir' => 'l', 'dur' => 400, 'ease' => 'out'],
            'cover' => 'band',
            'icon' => 'handshake',
        ];
    }

    public static function compose(Compose $c): void
    {
        $c->beginSection('Understanding');
        $c->cover(
            'Proposal for [client name]',
            'How we will help you [achieve the outcome they asked for]',
            'Replace with your name, role and the date',
            "Use the client's name and the outcome in their own words on the cover. Thank them for the time they gave you in discovery and say what you will cover: what you heard, what you propose, what it costs and returns, and how you will deliver. Replace the grey line with your details.",
        );

        $c->points(
            'What we heard from you',
            [
                '**The goal.** What the client said success looks like, in their words.',
                '**The obstacle.** What is stopping them today.',
                '**The deadline.** Why this has to happen by [date].',
            ],
            "Play the conversation back, and use their phrases. If a client hears their own words, they trust that you listened. Check one point with them before you move on: 'Have I got that right?' Replace each line with what you actually heard.",
            'If we have this wrong, tell us now.',
        );

        $c->imageSide(
            'right',
            'The cost of leaving it as it is',
            ['Hours lost each week to manual work', 'Errors that reach customers', 'A growing gap to competitors who have moved'],
            'Photo or diagram of the client problem, for example their current process',
            'Make the problem concrete and visual. A photograph of the current process, a screenshot of the workaround or a simple diagram does more than another bullet. Put a number on the cost if you can source one from the client; if you cannot, ask for it before you present.',
        );

        $c->beginSection('Solution');
        $c->points(
            'What we propose',
            [
                '**Scope.** What we will build or deliver, in one sentence.',
                '**Approach.** How we will do it, in one sentence.',
                '**Outcome.** What changes for you on the day it is done.',
            ],
            'A proposal should fit on one slide before you add detail. If you cannot summarise scope, approach and outcome in a sentence each, the proposal is not clear yet. Mention what is out of scope in your own words, since that prevents disputes later.',
            'Scope, approach and outcome in three sentences.',
        );

        $c->timeline(
            'How we will deliver it',
            [['Weeks 1 to 2', 'Discovery and detailed plan.'], ['Weeks 3 to 6', 'Build and weekly demonstrations.'], ['Weeks 7 to 8', 'Testing with your team.'], ['Week 9', 'Go live and handover.']],
            'Clients buy a plan they can picture. Show four stages with dates and name what the client has to provide at each one (access, decisions, feedback). Replace the stages and dates with your own, and be conservative: a delivered date beats a promised one.',
        );

        $c->beginSection('Value');
        $c->data(
            'Three packages, one clear recommendation',
            Compose::table([
                ['', 'Essential', 'Recommended', 'Complete'],
                ['Price', 'R 85 000', 'R 140 000', 'R 210 000'],
                ['Users', 'Up to 10', 'Up to 50', 'Unlimited'],
                ['Support', 'Email', 'Email and phone', 'Named manager'],
                ['Delivery', '6 weeks', '9 weeks', '12 weeks'],
            ], [0.25, 0.25, 0.25, 0.25], ['l', 'l', 'l', 'l']),
            ['The recommended package fits the goal you described.', 'Essential saves money but leaves the second problem open.'],
            'Prices exclude VAT. Replace with your own packages.',
            'Three options let the client choose without feeling pushed. Say which you recommend and why, in terms of their goal, before you discuss price. Replace the packages, rows and prices with your own; keep the rows to four so the table stays readable.',
        );

        $c->data(
            'What the recommended package returns',
            Compose::chart('column', ['Year 1', 'Year 2', 'Year 3'], [
                ['name' => 'Cost', 'values' => [140, 30, 30]],
                ['name' => 'Benefit', 'values' => [90, 210, 260]],
            ], ['title' => 'Cost and benefit', 'sub' => 'Rand thousands', 'fmt' => 'num', 'dec' => 0, 'labels' => 'value']),
            ['Cost is front loaded.', 'Benefit passes cost in year 2.', 'Three year net benefit is the number to remember.'],
            'Source: replace with the model you built with the client.',
            "Use the client's own numbers wherever you can, and show the assumptions in the appendix. Say plainly when the investment is paid back. If the benefit is uncertain, show a cautious case and be explicit about it. Replace the values with your own model.",
        );

        $c->quote(
            '[A short quote from a customer who had the same problem and the result they got.]',
            'Replace with name, role and company, and ask permission first.',
            'A real quote from a similar client is worth more than any claim you make about yourself. Ask the customer to approve the words and the attribution before you use them. If you have no quote yet, replace this slide with a short case study.',
        );

        $c->points(
            'Terms and next steps',
            [
                '**Validity.** This proposal is valid until [date].',
                '**Payment.** 50 percent on signing, 50 percent on delivery.',
                '**Start.** We can begin on [date] if we agree by [date].',
            ],
            'Be clear and brief about terms. Surprises here lose deals. Say what the client needs to do next, such as signing, naming a project contact and arranging system access. Replace each line with your own terms.',
            'One decision from you starts the clock.',
        );

        $c->closing(
            'Shall we begin?',
            'Confirm the recommended package and we will send the agreement today.',
            'Replace with your name, email and phone',
            'Ask for the decision directly and then be quiet. Offer a concrete next step with a date. If the client needs time, agree who else needs to see the proposal and book a follow up before you leave.',
        );
    }
}
