<?php

namespace App\Services\Press\Templates\Library;

use App\Services\Press\Templates\Compose;
use App\Services\Press\Templates\Template;

final class SafetyBriefing implements Template
{
    public static function meta(): array
    {
        return [
            'key' => 'safety-briefing',
            'name' => 'Safety briefing',
            'label' => 'Safety briefing',
            'category' => 'operations',
            'blurb' => 'A toolbox talk or shift briefing: today\'s hazard, the risks, the controls in order of strength, the emergency plan and the right to stop work. Hazard, risk, control.',
            'narrative' => 'Hazard, risk, controls in the hierarchy of controls, emergency plan, stop work authority',
            'theme' => 'projector',
            'tags' => ['safety', 'toolbox talk', 'hazard', 'risk', 'induction'],
            'transition' => ['type' => 'fade', 'dir' => 'l', 'dur' => 250, 'ease' => 'out'],
            'cover' => 'band',
            'icon' => 'hard-hat',
        ];
    }

    public static function compose(Compose $c): void
    {
        $c->beginSection('Today');
        $c->cover(
            'Safety briefing',
            '[Task or area], [date and shift]',
            'Replace with the supervisor name',
            'Start on time and make sure everyone can see and hear. Say the task and the area for today, and that everyone has the right to stop work. Record attendance. Replace the grey line with the task, date and your name.',
        );

        $c->imageFull(
            'Today\'s main hazard: [name it].',
            'Replace with where the hazard is and who is exposed',
            'A photo of the actual work area or hazard, with nothing staged',
            'Show the real hazard in the real place. Ask the crew what they see before you tell them. Let them point. This builds ownership. Replace the picture with a current photograph and the statement with the hazard for the day.',
        );

        $c->beginSection('Risk');
        $c->points(
            'What could go wrong, and how badly',
            [
                '**The hazard.** The thing that could cause harm.',
                '**Who is exposed.** The people nearby and the number of them.',
                '**The worst credible outcome.** Said plainly.',
            ],
            'Name the worst credible outcome in plain words and do not soften it. People take controls seriously when they understand what they protect against. Ask a crew member to describe the hazard back in their own words. Replace the three lines with the real hazard.',
            'Say the worst case out loud.',
        );

        $c->data(
            'Risk before and after the controls',
            Compose::table([
                ['Step', 'Likelihood', 'Consequence', 'Risk'],
                ['Before controls', 'Likely', 'Serious', 'High'],
                ['After controls', 'Unlikely', 'Serious', 'Medium'],
                ['After controls and supervision', 'Rare', 'Serious', 'Low'],
            ], [0.34, 0.22, 0.24, 0.2], ['l', 'l', 'l', 'l']),
            ['Controls reduce likelihood.', 'The consequence stays the same, so we never relax.'],
            'Use your own risk matrix and ratings.',
            'Show how each control lowers the risk. Consequence stays serious because the hazard has not gone away. Use the risk matrix your company uses, and replace the sample rows with the real task. Keep to three rows.',
            true,
        );

        $c->beginSection('Controls');
        $c->points(
            'Controls, strongest first',
            ['ol' => ['**Eliminate.** Can we remove the hazard?', '**Substitute.** Can we use something safer?', '**Engineer.** Guards, barriers, isolation', '**Administer.** Procedures, signs, training', '**Protect.** Personal protective equipment, the last line']],
            'This is the hierarchy of controls. Work down from the strongest and say which level you are using today. If a crew relies on protective equipment alone, ask what stronger control could be added. Replace the examples with your own controls.',
            'The weakest control is the one we rely on last.',
        );

        $c->compare(
            'Do and do not',
            ['Do', ['Check the isolation before you start', 'Wear the protective equipment for the task', 'Tell the supervisor of any change', 'Keep the area tidy']],
            ['Do not', ['Work under a suspended load', 'Bypass a guard or an interlock', 'Start if you are unsure', 'Rush to make up time']],
            'Pick the four behaviours that most often cause harm at your site. Say each short sentence aloud and ask for a nod. Replace the eight lines with your own, and drop any that do not apply to the task.',
            ['reveal' => true],
        );

        $c->beginSection('Emergency');
        $c->timeline(
            'If something goes wrong',
            [['Stop', 'Make the area safe if you can do so safely.'], ['Raise the alarm', 'Radio channel [number] or call [number].'], ['Muster', 'Go to assembly point [name].'], ['Account', 'Wait for the head count. Do not go back in.']],
            "Everyone should be able to say the four steps without looking. Point out the exit routes, the first aid station and the assembly point. Ask one person to repeat the radio channel. Replace the numbers and names with your site's.",
        );

        $c->quote(
            'If it is not safe, stop. Nobody will ever be disciplined for stopping.',
            'Your right and your duty to stop work',
            "Say that this applies to everyone, including contractors, and that supervisors must back it up. Mention a recent example of someone stopping work and what it prevented. Ask whether anyone has a concern before you begin. Replace the sentence with your company's own wording if it differs.",
        );

        $c->closing(
            'Questions before we start?',
            'Sign the attendance register. Begin only when everyone is ready.',
            'Replace with supervisor name and radio channel',
            'Ask each person directly: are you fit for work and clear on the task? Wait for a spoken yes. Sign the register and start. Keep a copy for the record.',
        );
    }
}
