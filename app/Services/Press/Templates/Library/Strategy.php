<?php

namespace App\Services\Press\Templates\Library;

use App\Services\Press\Templates\Compose;
use App\Services\Press\Templates\Template;

final class Strategy implements Template
{
    public static function meta(): array
    {
        return [
            'key' => 'strategy',
            'name' => 'Strategy',
            'label' => 'Strategy',
            'category' => 'business',
            'blurb' => 'A strategy in the shape Richard Rumelt calls the kernel: a diagnosis of the challenge, a guiding policy, and coherent actions. Ends with how you will know it is working.',
            'narrative' => 'Rumelt kernel: diagnosis, guiding policy, coherent actions, then measures',
            'theme' => 'night-briefing',
            'tags' => ['strategy', 'planning', 'offsite', 'choices', 'roadmap'],
            'transition' => ['type' => 'fade', 'dir' => 'l', 'dur' => 500, 'ease' => 'out'],
            'cover' => 'split',
            'icon' => 'compass',
        ];
    }

    public static function compose(Compose $c): void
    {
        $c->beginSection('Challenge');
        $c->cover(
            'Our strategy',
            'The challenge, the choices and the actions that follow',
            'Replace with team, date and presenter',
            'A strategy is a response to a challenge, so begin with the challenge. Tell the room that the next forty minutes follow three steps: diagnose, choose, act. Warn them that the choices will rule things out, and that is the point. Replace the grey line with your details.',
        );

        $c->quote(
            'The challenge: [state it in one sentence, in plain words, so a new hire would understand it].',
            'A good diagnosis names the obstacle, not the goal.',
            "Strategy fails most often at this step: people state a goal ('grow 20 percent') instead of a challenge ('our best customers are buying from us less often'). Write the obstacle in a sentence with no jargon. If you cannot do it in one sentence, you do not yet have a diagnosis. Read it aloud and test it with the room.",
        );

        $c->section('01', 'Diagnosis', 'What is really going on', 'Frame the next slides as evidence for the challenge. Each should make the challenge more specific, not add more topics.');

        $c->points(
            'Three forces shape the challenge',
            [
                '**Customers.** What they now expect that they did not five years ago.',
                '**Competitors.** Who is moving into our space and with what advantage.',
                '**Ourselves.** The capability or habit that holds us back.',
            ],
            'Choose forces that explain the challenge, not a list of everything that changed. One sentence of evidence per force. If you have a number, use it. Resist adding a fourth force; if it matters, it goes in the notes.',
            'Name the force that matters most.',
        );

        $c->data(
            'Where we stand against the best alternative',
            Compose::chart('radar', ['Speed', 'Reliability', 'Price', 'Support', 'Features', 'Ease of use'], [
                ['name' => 'Us', 'values' => [6, 8, 7, 5, 6, 7]],
                ['name' => 'Best alternative', 'values' => [8, 7, 5, 8, 8, 6]],
            ], ['title' => 'Customer scorecard', 'sub' => 'Score out of ten', 'legend' => 'bottom']),
            ['We lead on reliability and ease of use.', 'We trail on speed and support.', 'Price is a strength we can protect.'],
            'Source: replace with customer survey results.',
            'A radar chart is good for comparing two or three things on the same six measures. Point to the two biggest gaps and ask which of them the customer would pay to close. Replace the six measures with the ones your customers actually use to choose, and replace the scores with survey data.',
        );

        $c->section('02', 'Guiding policy', 'The choices that deal with the challenge', 'This is the part leaders avoid: choosing. Say that every choice here means saying no to something, and that you will name what.');

        $c->points(
            'Three choices that guide everything else',
            [
                '**Where we play.** The customers and markets we serve first.',
                '**How we win.** The advantage we build and protect.',
                '**What we give up.** The work we stop to make room.',
            ],
            'Write each choice as a sentence a team could use to settle an argument. A policy that cannot settle an argument is a slogan. Spend the most time on the third item: what you stop is the proof that you chose.',
            'A choice that costs nothing is not a choice.',
        );

        $c->compare(
            'What we will do and what we will not',
            ['We will', ['Serve fewer segments well', 'Invest in service speed', 'Price on value, not volume', 'Partner for reach']],
            ['We will not', ['Chase every tender', 'Build what partners already offer', 'Compete on the lowest price', 'Open new regions this year']],
            'Make the right-hand column uncomfortable on purpose. If nobody in the room would object to anything in it, it is not a real trade off. Replace both columns with your own and be ready for someone to ask why a favourite project is on the list.',
            ['reveal' => ['body', 'body2']],
        );

        $c->section('03', 'Coherent actions', 'What we do, in what order, and how we will know', 'Move from ideas to commitments. Every action must follow from a choice on the previous slides; if one does not, cut it.');

        $c->timeline(
            'Actions by half year',
            [['Next 6 months', 'Stop the three lowest value projects.'], ['6 to 12 months', 'Rebuild service around the chosen segments.'], ['12 to 18 months', 'Launch the repriced offer.'], ['18 to 24 months', 'Review and decide the next set of choices.']],
            'Actions should read as a sequence in which each step makes the next one possible. Name an owner for each step when you adapt this slide. Replace the four steps with your own.',
        );

        $c->figures(
            'How we will know it is working',
            [['85%', 'Customers who would recommend us'], ['30%', 'Faster response to requests'], ['12%', 'Revenue from chosen segments, growth'], ['0', 'Projects outside the strategy']],
            'Four measures, reviewed every quarter.',
            'Measures keep the strategy honest. Choose two that lag (results) and two that lead (behaviours you can change this quarter). The numbers here are illustrative targets: replace them with your own. The last measure, work outside the strategy, is the one most teams forget.',
            true,
        );

        $c->points(
            'Risks to the strategy and how we will watch them',
            [
                '**A competitor moves first.** We will track their releases monthly.',
                '**The chosen segment shrinks.** We will review segment size every half year.',
                '**We drift back to old habits.** The leadership team reviews stopped work each quarter.',
            ],
            'Name the three ways this strategy most plausibly fails, and the signal that would tell you early. Keep this short: the aim is to show you thought about failure, not to scare the room.',
            'Each risk has a signal and a review date.',
        );

        $c->closing(
            'Agree the choices, then the actions',
            'Confirm the three choices today. Owners for each action by [date].',
            'Replace with name, email and phone',
            'End by asking for agreement on the three choices before anything else. Do not let the group skip to actions. If there is disagreement, record it and name who will resolve it by when.',
        );
    }
}
