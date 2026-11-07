<?php

namespace App\Services\Press\Templates\Library;

use App\Services\Press\Templates\Compose;
use App\Services\Press\Templates\Template;

final class Workshop implements Template
{
    public static function meta(): array
    {
        return [
            'key' => 'workshop',
            'name' => 'Workshop',
            'label' => 'Workshop',
            'category' => 'education',
            'blurb' => 'Facilitate a working session that ends with decisions. Built on the diverge then converge pattern from the Double Diamond: understand, generate options, choose, commit.',
            'narrative' => 'Double Diamond: understand, diverge, converge, commit',
            'theme' => 'workshop',
            'tags' => ['workshop', 'facilitation', 'brainstorm', 'design thinking', 'team'],
            'transition' => ['type' => 'push', 'dir' => 'l', 'dur' => 350, 'ease' => 'out'],
            'cover' => 'split',
            'icon' => 'puzzle',
        ];
    }

    public static function compose(Compose $c): void
    {
        $c->beginSection('Open');
        $c->cover(
            '[Workshop question]',
            'A working session to decide [what]',
            'Replace with facilitator, date and time',
            'Welcome people and say what the session is for: to leave with a decision, not just ideas. Make sure the question on the cover is one the group can answer in the time. Replace the grey line with your name, date and time.',
        );

        $c->points(
            'How we will work today',
            [
                '**One conversation at a time.** Use the timer and the parking lot.',
                '**Ideas before judgement.** No critique until we choose.',
                '**Everyone writes first.** Quiet writing, then sharing.',
                '**Decide, then commit.** We leave with owners and dates.',
            ],
            'Agree the working rules before you begin: it is far easier than fixing behaviour in the middle. Ask whether anyone wants to add a rule. Say who keeps time and who captures decisions. Replace the four rules with those that suit your group.',
            'Add a rule if you need one.',
        );

        $c->timeline(
            'The plan for the session',
            [['30 minutes', 'Understand the problem.'], ['40 minutes', 'Generate many options.'], ['30 minutes', 'Choose with a vote and a matrix.'], ['20 minutes', 'Commit: owners and dates.']],
            'Show the shape of the session: open up, then narrow down. Keep to the times; a timer on screen helps. Replace the four steps and minutes with your own agenda.',
        );

        $c->section('01', 'Understand', 'What problem are we solving, and for whom?', 'Start by agreeing the problem. A group that solves different problems gets no result, so spend the first part of the session making sure everyone is working on the same question.');

        $c->columns(
            'Frame the question as a how might we',
            ['p' => ['**Too broad**', 'How might we improve the customer experience?', '', '**Too narrow**', 'How might we add a button to the invoice page?']],
            ['p' => ['**About right**', 'How might we help new customers pay their first invoice without calling us?', '', '**Test it**', 'Does it point to a person, a moment and a result?']],
            "A good question is open enough to allow many answers and specific enough to know when you have one. Ask each person to rewrite the group's question on a sticky note and compare. Replace the examples with your own.",
        );

        $c->section('02', 'Diverge', 'Many options, no judgement', 'Explain that quantity comes first in this part of the session, and that quality comes from choosing later. Remind people that ideas are not criticised yet, and keep the timer visible.');

        $c->points(
            'Brainstorm rules',
            ['ol' => ['Write alone for five minutes: one idea per note', 'Read out your notes; no comments yet', 'Group similar ideas together', 'Add to someone else\'s idea']],
            'Silent writing first avoids loud voices dominating and reduces anchoring on the first idea. Tell people to aim for twenty notes each. Put the timer on screen and stop on time. Replace the steps with your own method if you use a different technique.',
            'Aim for twenty notes each.',
        );

        $c->section('03', 'Converge', 'Choose together, on purpose', "Now the group narrows its options. Explain the method before you start so people trust the result, and say that everyone's vote counts the same, whatever their seniority.");

        $c->data(
            'Impact against effort',
            Compose::table([
                ['Idea', 'Impact', 'Effort', 'Verdict'],
                ['Guided first invoice', 'High', 'Low', 'Do now'],
                ['Payment reminders', 'High', 'Medium', 'Plan next'],
                ['New pricing page', 'Medium', 'Low', 'Do now'],
                ['Full redesign', 'High', 'High', 'Later'],
            ], [0.38, 0.18, 0.18, 0.26], ['l', 'l', 'l', 'l']),
            ['Start with high impact and low effort.', 'Vote with dots: three each.', 'Do not argue about a score for more than a minute.'],
            'Replace with the ideas the group generated.',
            "Score each idea on two things the group agrees: impact and effort. Do it by dot voting so every voice counts equally, then discuss the top three. Replace the sample ideas with your group's own. Keep the table to six ideas or fewer.",
            true,
        );

        $c->section('04', 'Commit', 'Who does what by when', 'A workshop with no owners is a conversation. Do not let people leave until each decision has a name and a date.');

        $c->points(
            'Our decisions and actions',
            [
                '**Decision 1.** [What we chose] and why.',
                '**Action.** [Name] will [do the thing] by [date].',
                '**Parking lot.** Topics we will return to, with an owner.',
            ],
            'Read back what was decided and ask the group to confirm. Capture owner and date on every action. Send the notes within 24 hours. Replace the lines with the real decisions made in the session.',
            'Owner, action, date. Write them down now.',
        );

        $c->closing(
            'Thank you for your thinking',
            'Notes and photos will be shared by [date].',
            'Replace with facilitator contact',
            'Thank the group, say when the notes will arrive and who will chase the actions. Ask for one word of feedback from each person on their way out.',
        );
    }
}
