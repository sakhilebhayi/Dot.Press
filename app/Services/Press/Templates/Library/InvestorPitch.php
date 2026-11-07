<?php

namespace App\Services\Press\Templates\Library;

use App\Services\Press\Templates\Compose;
use App\Services\Press\Templates\Template;

final class InvestorPitch implements Template
{
    public static function meta(): array
    {
        return [
            'key' => 'investor-pitch',
            'name' => 'Investor pitch',
            'label' => 'Investor pitch',
            'category' => 'finance',
            'blurb' => 'The classic seed to Series A pitch: problem, solution, market, traction, business model, competition, team and the ask. Short enough to present in ten minutes.',
            'narrative' => 'Problem, solution, market, traction, business model, competition, team, ask',
            'theme' => 'night-briefing',
            'tags' => ['investor', 'startup', 'fundraising', 'pitch', 'seed'],
            'transition' => ['type' => 'fade', 'dir' => 'l', 'dur' => 500, 'ease' => 'out'],
            'cover' => 'band',
            'icon' => 'trending-up',
        ];
    }

    public static function compose(Compose $c): void
    {
        $c->beginSection('Opportunity');
        $c->cover(
            '[Company name]',
            'One sentence on what you do and for whom',
            'Replace with round, date and presenter',
            "Investors see dozens of pitches. Your first sentence must say what the company does in plain words, with no buzzwords. Try the form: 'We help [who] to [do what] by [how].' Replace the grey line with the round you are raising and your name.",
        );

        $c->quote(
            '[Customers] lose [amount or time] every [period] because [the problem].',
            'Replace with the problem, in numbers you can defend.',
            'State the problem as a cost to a named person, with a number. Investors fund problems they believe are large, urgent and getting worse. Say where the number came from. If you have a story of one customer hitting the problem, tell it in two sentences after this slide.',
        );

        $c->imageSide(
            'right',
            'Our solution',
            ['What it does, in one line', 'Why it works better than the alternatives', 'How a customer uses it in a day'],
            'Product screenshot or photo showing the solution in use',
            'Show the product working, not an architecture diagram. Walk through the single most important task. Keep the three lines to what the customer sees, not what you built. Replace the picture with a real screenshot.',
        );

        $c->data(
            'The market we can reach',
            Compose::chart('bar', ['Total market', 'Serviceable market', 'First target'], [
                ['name' => 'Annual revenue opportunity', 'values' => [24, 6.5, 0.9]],
            ], ['title' => 'Market size', 'sub' => 'Rand billions per year', 'fmt' => 'num', 'dec' => 1, 'labels' => 'value', 'legend' => 'none']),
            ['Start with the segment we can win this year.', 'Show how it grows into the larger market.', 'Say how you counted.'],
            'Source: replace with named research or a bottom up count.',
            "Investors distrust top down numbers like 'one percent of a huge market'. Build the number bottom up: customers you can reach times what they pay. Show three steps: the whole market, the part you can serve, the first segment you will win. Replace the values and cite your sources.",
        );

        $c->beginSection('Traction');
        $c->data(
            'Traction: revenue is doubling every quarter',
            Compose::chart('area', ['Q1', 'Q2', 'Q3', 'Q4'], [
                ['name' => 'Monthly recurring revenue', 'values' => [12, 26, 51, 98]],
            ], ['title' => 'Monthly recurring revenue', 'sub' => 'Rand thousands', 'fmt' => 'num', 'dec' => 0, 'smooth' => true, 'legend' => 'none']),
            ['Growth is the story: say the rate.', 'Add customers and retention beside it.', 'Be exact about what counts as revenue.'],
            'Source: replace with your billing system.',
            'Traction is the strongest part of any pitch, so lead with it if you have it. Show one chart that moves clearly in one direction and say the growth rate once. If revenue is still small, show users, pilots or waiting list instead and be honest about it. Replace the values with your own.',
        );

        $c->beginSection('Business model');
        $c->figures(
            'The business model in four numbers',
            [['R 4 800', 'Yearly contract per customer'], ['78%', 'Gross margin'], ['R 9 600', 'Lifetime value'], ['5 mo', 'To earn back acquisition cost']],
            'Healthy unit economics, early. They improve with scale.',
            'Investors check unit economics before anything else. Explain each number in a phrase: price, margin, lifetime value, payback. These figures are illustrative: replace them with yours, and be ready to show how you calculated each.',
            true,
        );

        $c->compare(
            'Why customers choose us over the alternatives',
            ['Alternatives', ['Spreadsheets and email', 'Slow to set up', 'No single record', 'Cheap but risky']],
            ['Us', ['Ready in a day', 'One shared record', 'Fits how teams already work', 'Priced by usage']],
            'Do not show a grid in which you win every box; investors will not believe it. Compare on the two or three things customers actually care about, and name the real competitor, including the do nothing option. Replace both columns with your own.',
        );

        $c->beginSection('Team and ask');
        $c->points(
            'The team',
            [
                '**[Name], chief executive.** Ten years in [the industry]; built and sold [previous company].',
                '**[Name], chief technology officer.** Led [the relevant system] at [company].',
                '**[Name], head of sales.** Grew [a relevant market] from zero to [number] customers.',
            ],
            'Investors back people. For each person, give one line that shows why they are the right person to solve this problem. Include real roles and real facts; do not pad. Replace each name and line with your own team.',
            'Why this team, for this problem.',
        );

        $c->data(
            'How we will use the money',
            Compose::chart('doughnut', ['Product', 'Sales', 'Operations', 'Reserve'], [
                ['name' => 'Share of funds', 'values' => [45, 35, 10, 10]],
            ], ['title' => 'Use of funds', 'sub' => 'Percent of the round', 'legend' => 'right', 'labels' => 'percent', 'fmt' => 'pct']),
            ['Eighteen months of runway.', 'Each slice maps to a milestone.', 'The next milestone is the next round.'],
            'Percentages are illustrative. Replace with your plan.',
            'Say how long the money lasts and what milestone it buys. Map each slice to something checkable, such as customers, product releases or hires. Replace the shares with your plan and name the milestone beside each.',
        );

        $c->closing(
            'We are raising [amount]',
            'To reach [milestone] in [period]. We are speaking with investors now.',
            'Replace with name, email and phone',
            'State the amount, the milestone and the timeline in one breath, then invite questions. Have your data room ready and say how you will follow up within a day.',
        );
    }
}
