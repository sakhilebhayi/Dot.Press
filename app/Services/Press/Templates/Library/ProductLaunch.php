<?php

namespace App\Services\Press\Templates\Library;

use App\Services\Press\Templates\Compose;
use App\Services\Press\Templates\Template;

final class ProductLaunch implements Template
{
    public static function meta(): array
    {
        return [
            'key' => 'product-launch',
            'name' => 'Product launch',
            'label' => 'Launch',
            'category' => 'sales',
            'blurb' => 'Introduce a product: the problem it solves, who it is for, what it does and why it is better, then the launch plan and goals. Problem, solution, value, plan.',
            'narrative' => 'Problem, solution, value, then launch plan: audience, product, difference, goals, schedule, messaging',
            'theme' => 'launch',
            'tags' => ['launch', 'product', 'go to market', 'announcement'],
            'transition' => ['type' => 'push', 'dir' => 'l', 'dur' => 400, 'ease' => 'out'],
            'cover' => 'band',
            'icon' => 'rocket',
        ];
    }

    public static function compose(Compose $c): void
    {
        $c->beginSection('Problem');
        $c->cover(
            'Introducing [product name]',
            'The one thing it does better than anything else',
            'Replace with launch date, presenter and team',
            'Open with the name and a promise in one line. Pause. The cover is the only slide the audience will see before they decide whether to listen, so the subtitle must say what changes for them, not what the product is. Replace the grey line with the launch date and your name.',
        );

        $c->imageFull(
            'Customers spend [hours] a week on [the problem].',
            'Replace with the real customer observation behind the problem',
            'A photo of the problem in the real world: a person at work, a queue, a spreadsheet, a delay',
            "Start with the problem in one strong picture and one sentence. Name the number if you have it, and say where it came from (an interview, a survey, usage data). Do not mention the product yet. Replace the picture with a real photograph of your customers' environment.",
        );

        $c->points(
            'Who it is for',
            [
                '**Primary audience.** The person who feels the problem most.',
                '**Buyer.** The person who approves the spend.',
                '**Not for.** Who this product is deliberately not built for.',
            ],
            "Be specific about the person. 'Small business owners' is a category; 'owners of two to ten person shops who keep their books on a phone' is a person. Naming who the product is not for makes the rest of the story more believable.",
            'Describe one person, not a market.',
        );

        $c->beginSection('Solution');
        $c->imageSide(
            'left',
            'What it does',
            ['Does the main job in one step', 'Works with the tools people already use', 'Starts working the same day'],
            'Product screenshot or photo of the product in use',
            'Show the product, not a feature list. Walk through the single most important task start to finish. Keep it to three statements that a customer would repeat to a colleague. Replace the picture with a real screenshot or product photo.',
        );

        $c->compare(
            'Before and after',
            ['Before', ['Six steps across three tools', 'Waiting for another team', 'Mistakes found late', 'No single record']],
            ['After', ['One step in one place', 'Done by the person who needs it', 'Mistakes caught as they happen', 'A single, shared record']],
            'Before and after is the clearest way to show value. Make the left column feel familiar, so the audience nods, and the right column plausible, so they believe it. Replace both columns with your own.',
            ['reveal' => 'right'],
        );

        $c->beginSection('Value and plan');
        $c->figures(
            'What we want to see in the first 90 days',
            [['5 000', 'Trial sign ups'], ['25%', 'Trials that become paying'], ['40', 'Customer stories collected'], ['4.5', 'Average rating']],
            'Four goals. We will review them every two weeks.',
            'Set goals you can measure in the window, and say how you will measure them. These sample targets are illustrative: replace them with yours. Choose one number for reach, one for conversion, one for proof and one for quality.',
            true,
            ['reveal' => true],
        );

        $c->data(
            'Where we expect to win and lose people',
            Compose::chart('funnel', ['Saw the launch', 'Visited the site', 'Started a trial', 'Became a customer'], [
                ['name' => 'People', 'values' => [50000, 12000, 3200, 800]],
            ], ['title' => 'Launch funnel', 'sub' => 'People over 90 days', 'labels' => 'value', 'fmt' => 'compact', 'legend' => 'none']),
            ['The biggest drop is from visit to trial.', 'That is where onboarding work goes first.', 'Compare against this funnel every week.'],
            'Source: replace with your own forecast.',
            "A funnel makes the plan testable. Show where you expect the biggest drop and what you will do about it. The numbers here are illustrative: replace them with your forecast and keep the stage names in the customer's words.",
        );

        $c->timeline(
            'Launch plan',
            [['Four weeks before', 'Brief partners and press. Finish materials.'], ['Launch day', 'Announce, publish and open sign up.'], ['Week 2', 'Share first customer stories.'], ['Week 6', 'Review results and decide the next push.']],
            'A launch is a plan with a date, so give every step an owner when you adapt it. Make sure the order is right: partners and press must hear before the public does. Replace the four steps with your own.',
        );

        $c->points(
            'Three things we will say, in this order',
            [
                '**Promise.** The outcome in one sentence.',
                '**Proof.** The single number or story that backs it.',
                '**Invitation.** The one action we want people to take.',
            ],
            'Every channel carries the same three messages in the same order. Write each in plain words, then test it on someone outside the team. If they cannot repeat it, shorten it. Replace the three lines with your messages.',
            'Same words in every channel.',
        );

        $c->closing(
            'Launch on [date]',
            'We need final sign off on messaging and pricing by [date].',
            'Replace with name, email and phone',
            'End on the date and the decisions still open. Confirm who signs off messaging, pricing and the press list, and by when. Thank the team by name if they are in the room.',
        );
    }
}
