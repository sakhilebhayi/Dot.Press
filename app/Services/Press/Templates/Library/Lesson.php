<?php

namespace App\Services\Press\Templates\Library;

use App\Services\Press\Templates\Compose;
use App\Services\Press\Templates\Template;

final class Lesson implements Template
{
    public static function meta(): array
    {
        return [
            'key' => 'lesson',
            'name' => 'Lesson',
            'label' => 'Lesson',
            'category' => 'education',
            'blurb' => 'A single lesson in the gradual release order: a hook, the goal, direct teaching, a worked example, guided and independent practice, and a check for understanding.',
            'narrative' => 'Hook, objective, direct instruction, worked example, guided then independent practice, exit check',
            'theme' => 'lecture',
            'tags' => ['lesson', 'teaching', 'class', 'school', 'university'],
            'transition' => ['type' => 'fade', 'dir' => 'l', 'dur' => 350, 'ease' => 'out'],
            'cover' => 'rule',
            'icon' => 'document',
        ];
    }

    public static function compose(Compose $c): void
    {
        $c->beginSection('Start');
        $c->cover(
            '[Lesson title]',
            'Subject, grade and the one big question',
            'Replace with teacher name, class and date',
            'Greet the class and put the title on the board while they settle. Tell them the lesson has a question at its centre, and that they will answer it themselves by the end. Replace the grey line with your name, class and date.',
        );

        $c->quote(
            'Why does [the surprising thing] happen?',
            'Think about it for one minute. Talk to the person next to you.',
            'A good opening question makes learners want the answer. Pick something that is slightly puzzling or that challenges what they believe. Give a minute for pair talk, then take two or three answers without judging. Come back to the question at the end of the lesson.',
        );

        $c->points(
            'Today you will learn to',
            ['ol' => ['Describe [the key idea] in your own words', 'Use it to solve a problem', 'Explain why the answer makes sense']],
            'State the objectives in learner friendly language and read them out. Link them to what the class already knows. Tell them how they will show the learning at the end (the exit question). Replace the three objectives.',
            'You will show this in the last five minutes.',
        );

        $c->beginSection('Teach');
        $c->imageSide(
            'left',
            'The key idea',
            ['Define it in one sentence', 'Show where it appears in real life', 'Name the three parts to remember'],
            'A clear diagram, photo or labelled example of the key idea',
            "Teach the idea in small steps and check understanding after each one: ask a quick question and look at faces. A clear picture beats a long paragraph. Replace the picture with your own diagram or photo, labelled in the learners' language.",
        );

        $c->columns(
            'A worked example',
            ['p' => ['**The problem**', 'A short, concrete problem the class can read in ten seconds.', 'Replace with your own example.']],
            ['p' => ['**The solution, step by step**', 'Step 1: say what you notice.', 'Step 2: choose the idea that fits.', 'Step 3: do the working.', 'Step 4: check the answer makes sense.']],
            'Think aloud as you solve the problem, so learners hear how an expert reasons. Say why you chose each step, not only what you did. Then ask: what would I do if the numbers changed? Replace both columns with your own worked example.',
        );

        $c->beginSection('Practise');
        $c->timeline(
            'How the lesson runs',
            [['10 minutes', 'Hook and the key idea.'], ['10 minutes', 'Worked example together.'], ['15 minutes', 'Practice with a partner, then alone.'], ['5 minutes', 'Exit question.']],
            'Show the lesson shape so learners know what is expected and when. Keep the times honest. Replace the four stages with yours, adjusting the minutes to fit your period.',
        );

        $c->points(
            'Your turn: practise',
            [
                '**Together.** Do question 1 with your partner.',
                '**Then alone.** Do questions 2 to 4.',
                '**Stretch.** If you finish, try question 5 and explain your method.',
            ],
            'Gradual release: first together, then alone. Circulate and look for the common mistake, then address it with the whole class. Prepare a stretch task for early finishers. Replace the three lines with your own tasks.',
            'Ask three before you ask me.',
        );

        $c->data(
            'What the class found',
            Compose::chart('column', ['Group A', 'Group B', 'Group C', 'Group D'], [
                ['name' => 'Correct answers', 'values' => [7, 9, 6, 8]],
            ], ['title' => 'Correct answers by group', 'sub' => 'Out of 10', 'labels' => 'value', 'legend' => 'none']),
            ['Group B did best.', 'Ask what Group B did differently.', 'Which question did most groups miss?'],
            'Replace with the results from your own class.',
            'If you collect results during practice, show them to start a discussion rather than to rank groups. Ask the best group to explain their method. The numbers here are sample data: replace them with your own, or delete the slide if you do not collect data.',
        );

        $c->points(
            'Exit question',
            [
                '**Write:** In your own words, why does [the thing] happen?',
                '**Show:** One example from today.',
                '**Rate:** How sure are you? 1 to 4.',
            ],
            'Collect the answers as learners leave. Read them before the next lesson: the pattern of errors tells you what to reteach. Return to the opening question and ask for a better answer than the one at the start. Replace the three prompts with your own.',
            'Five minutes. Hand in on the way out.',
        );

        $c->closing(
            'Next lesson',
            'Read pages [x to y] and bring one question.',
            'Replace with homework details and where to find help',
            'Set homework that matches the lesson, with a clear time estimate. Say where learners can find help. Thank the class.',
        );
    }
}
