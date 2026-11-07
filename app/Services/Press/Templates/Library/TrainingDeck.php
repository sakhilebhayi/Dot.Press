<?php

namespace App\Services\Press\Templates\Library;

use App\Services\Press\Templates\Compose;
use App\Services\Press\Templates\Template;

final class TrainingDeck implements Template
{
    public static function meta(): array
    {
        return [
            'key' => 'training-deck',
            'name' => 'Training deck',
            'label' => 'Training',
            'category' => 'education',
            'blurb' => 'A session that teaches a skill: objectives first, then tell, show and practise, then a recap. High contrast type for rooms with a projector. Tell, show, do.',
            'narrative' => 'Objectives, tell, show, do, recap: learners practise before they leave',
            'theme' => 'projector',
            'tags' => ['training', 'onboarding', 'skills', 'learning', 'session'],
            'transition' => ['type' => 'fade', 'dir' => 'l', 'dur' => 300, 'ease' => 'out'],
            'cover' => 'band',
            'icon' => 'lightbulb',
        ];
    }

    public static function compose(Compose $c): void
    {
        $c->beginSection('Start');
        $c->cover(
            '[Skill or topic]',
            'A 60 minute session for [audience]',
            'Replace with trainer name, date and venue',
            'Welcome people, say how long the session is and when the breaks are. Tell them the session is practical: they will do the task before they leave. Say where the materials are. Replace the grey line with your name, date and venue.',
        );

        $c->points(
            'By the end of this session you will be able to',
            ['ol' => ['Explain [the concept] in your own words', 'Complete [the task] without help', 'Check your own work against the checklist']],
            "Write objectives as things people can do, using verbs like explain, complete, check, choose. Avoid 'understand' because you cannot observe it. Read them out and say you will return to them at the end. Replace the three objectives with your own.",
            'We will check these at the end.',
        );

        $c->section('01', 'Learn', 'The idea, in plain words', 'Keep this part short. People learn more from doing than from listening. Aim for no more than 15 minutes.');

        $c->points(
            'The idea in three parts',
            [
                '**What it is.** One sentence in everyday words.',
                '**Why it matters.** What goes wrong without it.',
                '**How it works.** The three steps, in order.',
            ],
            "Explain the concept in a form a newcomer can repeat. Use one example from the learners' own work. If you use a term of art, define it the first time and then keep using the same word. Replace the three lines with your own.",
            'Say it plainly before you say it precisely.',
        );

        $c->imageSide(
            'right',
            'See how it looks when it is done well',
            ['Point to the three parts in the picture', 'Notice what is missing in the poor example', 'Ask the group what they would change'],
            'A photo, screenshot or diagram of a good example, with the key parts labelled',
            "Show a finished example and ask learners to find the three parts. Showing a poor example next to it helps people see the difference. Replace the picture with your own example, ideally from the learners' workplace.",
        );

        $c->compare(
            'Do and do not',
            ['Do', ['Check the setting before you start', 'Record what you did', 'Ask when you are unsure', 'Tell a colleague when it is done']],
            ['Do not', ['Skip the first check to save time', 'Rely on memory', 'Guess', 'Leave without handover']],
            'Pair every do with a matching do not so learners can see the contrast. Use real mistakes you have seen. Replace the eight lines with your own, and keep the wording short enough to remember.',
            ['reveal' => ['body', 'body2']],
        );

        $c->quote(
            'Check twice, then do it once.',
            'Replace with the one rule you want people to remember.',
            'Choose the one rule that prevents the most expensive mistake and put it on a slide by itself. Say it, then say why. Ask learners to say it back. Replace the sentence with your own.',
        );

        $c->section('02', 'Practise', 'Your turn: do the task', 'Hand over to the learners. Tell them how long they have and what you will be doing while they work (walking round, answering questions).');

        $c->points(
            'The exercise',
            ['ol' => ['Open the practice file', 'Complete steps 1 to 5 of the checklist', 'Compare your result with the model answer', 'Note one thing you would do differently']],
            'Give clear steps and a time limit (10 to 15 minutes works for most tasks). Walk the room and ask questions rather than giving answers. Replace the four steps with your own exercise.',
            'You have 15 minutes. Ask if you are stuck.',
        );

        $c->data(
            'Self check: did you get it right?',
            Compose::table([
                ['Check', 'What good looks like', 'Done'],
                ['Setting', 'Matches the job sheet', ''],
                ['Record', 'Complete and signed', ''],
                ['Result', 'Within the limit', ''],
                ['Handover', 'Colleague informed', ''],
            ], [0.25, 0.55, 0.2], ['l', 'l', 'c']),
            ['Tick each line as you check it.', 'Discuss any line you could not tick.'],
            'Print this slide or share it as a handout.',
            "A self check puts the standard in the learner's hands. Let them check their own work first, then compare in pairs. Replace the four checks with your own, and keep 'what good looks like' observable.",
            true,
        );

        $c->section('03', 'Recap', 'What to take away', 'Close the loop on the objectives you showed at the start. Tell learners you will ask each of them to say which objective they can now meet and which one they still need practice with.');

        $c->points(
            'What we covered',
            [
                '**The idea.** Three parts, in order.',
                '**The rule.** Check twice, then do it once.',
                '**The skill.** You have done the task once, with a checklist.',
            ],
            'Return to the objectives and ask: can you now do each of these? Ask learners to rate themselves with a show of hands. Note who needs more practice and plan a follow up. Replace the lines with your own recap.',
            'Which objective do you feel least sure about?',
        );

        $c->closing(
            'Questions, then your next step',
            'Practise the task once this week and tell [name] how it went.',
            'Replace with trainer contact and where to find materials',
            'Take questions, then give each learner one concrete thing to do in the next week. Say where the materials and the checklist are stored, and who to ask for help. Thank them for their time.',
        );
    }
}
