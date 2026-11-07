<?php

namespace App\Services\Press\Templates\Library;

use App\Services\Press\Templates\Compose;
use App\Services\Press\Templates\Template;

final class ResearchTalk implements Template
{
    public static function meta(): array
    {
        return [
            'key' => 'research-talk',
            'name' => 'Research talk',
            'label' => 'Research talk',
            'category' => 'education',
            'blurb' => 'A conference or seminar talk in the IMRaD order: why it matters, what you asked, how you tested it, what you found, what it means and what it cannot say.',
            'narrative' => 'IMRaD: introduction, methods, results, discussion, with limits and implications',
            'theme' => 'field-report',
            'tags' => ['research', 'conference', 'seminar', 'thesis', 'academic'],
            'transition' => ['type' => 'fade', 'dir' => 'l', 'dur' => 350, 'ease' => 'out'],
            'cover' => 'rule',
            'icon' => 'search',
        ];
    }

    public static function compose(Compose $c): void
    {
        $c->beginSection('Introduction');
        $c->cover(
            '[Title of the study]',
            'What we asked and what we found',
            'Replace with authors, institution and event',
            "Name your co authors and funders before you begin. Say how long you will talk and when you will take questions. Give the one line version of the study in your first breath: 'We tested whether X changes Y.' Replace the grey line with authors and event.",
        );

        $c->points(
            'Why this matters',
            [
                '**The problem.** What goes wrong or is unknown, and for whom.',
                '**What is known.** The state of the evidence, briefly.',
                '**The gap.** What nobody has shown yet.',
            ],
            'Motivate the question for someone outside your sub field. One or two citations are enough; the rest go in the paper. State the gap as a sentence the audience could repeat. Replace the three lines with your own.',
            'The gap is the reason for the study.',
        );

        $c->quote(
            'Does [the intervention] change [the outcome] in [the population]?',
            'Our research question',
            'Put the question on a slide by itself. Say it slowly. Add the hypothesis if you had one before you saw the data, and say so. Replace the sentence with your own question, in plain language.',
        );

        $c->beginSection('Methods');
        $c->imageSide(
            'right',
            'How we tested it',
            ['Design: randomised comparison', 'Sample: [number] participants from [setting]', 'Measure: [outcome] after [period]'],
            'A diagram of the study design, or a photo of the setting or apparatus',
            'Describe the design so a sceptic could judge the result. Cover who was studied, how they were assigned, what was measured and when. A flow diagram of participants is often clearer than words. Replace the three lines with your own design and the picture with your diagram.',
        );

        $c->beginSection('Results');
        $c->data(
            'The main result',
            Compose::chart('column', ['Control', 'Treatment'], [
                ['name' => 'Mean outcome score', 'values' => [52, 61]],
            ], ['title' => 'Outcome score after 12 weeks', 'sub' => 'Mean, higher is better', 'labels' => 'value', 'legend' => 'none', 'ax' => ['y' => ['min' => 0]]]),
            ['Treatment group scored nine points higher.', 'Effect size: report it with its interval.', 'Say what the numbers mean in practice.'],
            'Source: replace with your analysis.',
            'Show the primary result first and clearly, with the uncertainty. Start the axis at zero so the difference is not exaggerated. State the effect size and interval aloud, and say whether the result was the one you predicted. The values are sample data: replace them with your own.',
        );

        $c->data(
            'The relationship across participants',
            Compose::chart('scatter', [], [
                ['name' => 'Participants', 'values' => [], 'points' => [['x' => 2, 'y' => 48], ['x' => 4, 'y' => 52], ['x' => 5, 'y' => 55], ['x' => 7, 'y' => 54], ['x' => 8, 'y' => 60], ['x' => 10, 'y' => 63], ['x' => 12, 'y' => 66], ['x' => 14, 'y' => 65], ['x' => 15, 'y' => 71]]],
            ], ['title' => 'Hours of use and outcome score', 'sub' => 'One point per participant', 'legend' => 'none', 'ax' => ['x' => ['title' => 'Hours of use per week', 'show' => true], 'y' => ['title' => 'Outcome score', 'show' => true]]]),
            ['More use goes with a higher score.', 'This is an association, not proof of cause.', 'Say what else could explain it.'],
            'Source: replace with your own data.',
            'A scatter plot shows the spread, which a mean hides. Say how strong the relationship is and caution that it is correlational unless your design supports a causal claim. Replace the points with your own data.',
        );

        $c->data(
            'Summary of results',
            Compose::table([
                ['Measure', 'Control', 'Treatment', 'Difference'],
                ['Outcome score', '52', '61', '+9'],
                ['Completed', '88%', '91%', '+3 points'],
                ['Satisfaction', '3.8', '4.2', '+0.4'],
            ], [0.34, 0.22, 0.22, 0.22], ['l', 'r', 'r', 'r']),
            ['All three measures favour the treatment.', 'Only the first was pre registered.'],
            'Source: replace with your analysis.',
            'A short table allows precise comparison for those who want numbers. Mark which measures were pre registered and which were exploratory; honesty about this increases trust. Replace the rows with your own.',
        );

        $c->beginSection('Discussion');
        $c->compare(
            'How this fits with earlier work',
            ['Earlier studies', ['Small samples', 'Short follow up', 'Mixed results']],
            ['This study', ['Larger sample', '12 week follow up', 'Consistent direction']],
            'Place your result in the existing literature. Be fair to earlier work: say what each did well. Point out the difference that matters most. Replace both columns with your own comparison.',
        );

        $c->points(
            'What this study cannot say',
            [
                '**Who.** The sample came from one region.',
                '**How long.** We followed people for 12 weeks only.',
                '**Why.** We did not test the mechanism.',
            ],
            'State the limits yourself before someone else does. Each limit should be paired with what would address it. This is the part of the talk that earns the most trust. Replace the three lines with your own.',
            'Each limit points to the next study.',
        );

        $c->timeline(
            'What comes next',
            [['Now', 'Share the data and code openly.'], ['Next 6 months', 'Replicate in a second region.'], ['Next 12 months', 'Test the mechanism.'], ['Next 24 months', 'Follow up at 12 months.']],
            'Finish by showing the path forward, which also shows that the finding has a future. Replace the four steps with your own.',
        );

        $c->closing(
            'Thank you. Questions?',
            'Data, code and the preprint are at [link].',
            'Replace with authors, emails and funding statement',
            'Repeat the main finding in one sentence, thank collaborators and funders, and invite questions. Keep a backup slide with the methods detail in case someone asks.',
        );
    }
}
