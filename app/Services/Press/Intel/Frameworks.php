<?php

namespace App\Services\Press\Intel;

/**
 * The narrative structures the Storyteller and the OutlineGenerator work with.
 *
 * Each beat has an id, a label, the question it answers, the slide kinds (layout ids from the
 * document spec) that usually carry it, and detection keywords. A keyword with a space is a phrase
 * matched in the lowercase text; a single word is matched on its stem. Optional beats are used only
 * when the requested length leaves room for them. The `free` structure is the fallback that never
 * forces a story shape.
 */
final class Frameworks
{
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $cache = null;

    /** @return array<string, array{id: string, name: string, summary: string, beats: list<array<string, mixed>>}> */
    public static function all(): array
    {
        return self::$cache ??= self::build();
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::all());
    }

    public static function get(string $id): ?array
    {
        return self::all()[$id] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function beats(string $id, bool $withOptional = true): array
    {
        $fw = self::get($id) ?? self::get('free');

        return array_values(array_filter($fw['beats'], fn ($b) => $withOptional || ! $b['optional']));
    }

    /**
     * Keyword score of a text against a beat: distinct keywords found, each counted up to twice.
     *
     * @param  array<string, int>  $stems  stem => occurrences (from stemCounts)
     */
    public static function beatScore(string $lower, array $stems, array $beat): float
    {
        $score = 0.0;
        foreach ($beat['keywords'] as $kw) {
            if (str_contains($kw, ' ') || str_contains($kw, '-') || str_contains($kw, '&')) {
                $n = substr_count($lower, $kw);
            } else {
                $n = $stems[Text::stem($kw)] ?? 0;
            }
            $score += min($n, 2);
        }

        return $score;
    }

    /** @return array<string, int> */
    public static function stemCounts(string $text): array
    {
        $counts = [];
        foreach (Text::words($text) as $w) {
            $s = Text::stem($w);
            $counts[$s] = ($counts[$s] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Rank the structures by how well a text (a brief plus its source, or a whole deck) fits them.
     *
     * @return list<array{id: string, score: float, coverage: float, hits: array<string, float>}> best first
     */
    public static function rank(string $text): array
    {
        $lower = mb_strtolower($text);
        $stems = self::stemCounts($text);
        $out = [];

        foreach (self::all() as $id => $fw) {
            if ($id === 'free') {
                continue;
            }
            $core = array_values(array_filter($fw['beats'], fn ($b) => ! $b['optional']));
            $hits = [];
            $covered = 0;
            $score = 0.0;
            foreach ($core as $beat) {
                $s = self::beatScore($lower, $stems, $beat);
                $hits[$beat['id']] = $s;
                $score += min($s, 8);
                if ($s >= 2) {
                    $covered++;
                }
            }
            $out[] = ['id' => $id, 'score' => $score, 'coverage' => count($core) ? $covered / count($core) : 0.0, 'hits' => $hits];
        }

        usort($out, fn ($a, $b) => [$b['coverage'], $b['score']] <=> [$a['coverage'], $a['score']]);

        return $out;
    }

    /**
     * @return array<string, array{id: string, name: string, summary: string, beats: list<array<string, mixed>>}>
     */
    private static function build(): array
    {
        $fw = [];
        $add = function (string $id, string $name, string $summary, array $beats) use (&$fw) {
            $fw[$id] = ['id' => $id, 'name' => $name, 'summary' => $summary, 'beats' => array_map(fn ($b) => $b + ['optional' => false], $beats)];
        };

        $add('problem-solution-value', 'Problem, solution, value', 'Name the problem, show the fix, prove what it is worth.', [
            ['id' => 'problem', 'label' => 'The problem', 'question' => 'What is going wrong, for whom, and what does it cost?',
                'kinds' => ['bullets', 'kpi', 'image-left', 'quote'],
                'keywords' => ['problem', 'challenge', 'pain', 'issue', 'struggle', 'cost of', 'risk', 'gap', 'inefficient', 'delay', 'loss', 'shortage', 'fail', 'bottleneck', 'waste', 'complaint', 'frustrat', 'downtime', 'currently']],
            ['id' => 'solution', 'label' => 'The solution', 'question' => 'What do you propose, and how does it work?',
                'kinds' => ['bullets', 'image-right', 'timeline', 'two'],
                'keywords' => ['solution', 'propose', 'introduce', 'approach', 'platform', 'implement', 'build', 'deploy', 'design', 'system', 'offering', 'how it works', 'feature', 'automate', 'deliver', 'we will']],
            ['id' => 'value', 'label' => 'The value', 'question' => 'What changes, and what is it worth?',
                'kinds' => ['kpi', 'data', 'compare', 'bullets'],
                'keywords' => ['value', 'benefit', 'saving', 'save', 'return', 'roi', 'impact', 'result', 'improve', 'increase', 'reduce', 'growth', 'gain', 'outcome', 'payback', 'efficiency', 'productivity', 'revenue', 'cost reduction']],
            ['id' => 'next', 'label' => 'Next steps', 'question' => 'What happens next and what do you need from the audience?', 'optional' => true,
                'kinds' => ['timeline', 'closing', 'bullets'],
                'keywords' => ['next step', 'next steps', 'timeline', 'roadmap', 'plan', 'ask', 'decision', 'approve', 'start', 'pilot', 'launch', 'contact', 'action', 'recommend']],
        ]);

        $add('summary-findings-recommendations', 'Executive summary, findings, recommendations', 'Lead with the answer, support it, then say what to do.', [
            ['id' => 'summary', 'label' => 'Executive summary', 'question' => 'What is the headline in one slide?',
                'kinds' => ['bullets', 'kpi'],
                'keywords' => ['executive summary', 'summary', 'overview', 'purpose', 'scope', 'objective', 'background', 'headline', 'key points', 'highlights', 'at a glance', 'overall']],
            ['id' => 'findings', 'label' => 'Findings', 'question' => 'What did the work find, and what is the evidence?',
                'kinds' => ['data', 'kpi', 'compare', 'bullets'],
                'keywords' => ['finding', 'found', 'analysis', 'data', 'result', 'survey', 'review', 'observed', 'evidence', 'shows', 'trend', 'performance', 'compared', 'increase', 'decrease', 'measured', 'audit', 'assessment']],
            ['id' => 'recommendations', 'label' => 'Recommendations', 'question' => 'What should the audience do, in priority order?',
                'kinds' => ['bullets', 'timeline', 'closing'],
                'keywords' => ['recommend', 'should', 'action', 'propose', 'next step', 'priority', 'suggest', 'advise', 'implement', 'must', 'decision', 'option', 'approve', 'adopt']],
        ]);

        $add('introduction-evidence-conclusion', 'Introduction, evidence, conclusion', 'Say what you will argue, prove it, close on what it means.', [
            ['id' => 'introduction', 'label' => 'Introduction', 'question' => 'What is this about and why does it matter now?',
                'kinds' => ['bullets', 'image-full', 'quote'],
                'keywords' => ['introduction', 'introduce', 'background', 'context', 'purpose', 'today', 'agenda', 'overview', 'welcome', 'scope', 'aim', 'objective', 'why']],
            ['id' => 'evidence', 'label' => 'Evidence', 'question' => 'What facts, data or examples support the argument?',
                'kinds' => ['data', 'compare', 'bullets', 'quote', 'kpi'],
                'keywords' => ['evidence', 'data', 'study', 'research', 'example', 'case', 'result', 'shows', 'finding', 'analysis', 'statistics', 'survey', 'because', 'figure', 'proof', 'according']],
            ['id' => 'conclusion', 'label' => 'Conclusion', 'question' => 'What should the audience take away?',
                'kinds' => ['closing', 'bullets'],
                'keywords' => ['conclusion', 'conclude', 'summary', 'in summary', 'takeaway', 'therefore', 'overall', 'finally', 'recap', 'thank', 'next step', 'in short']],
        ]);

        $add('opportunity-market-strategy-financials', 'Opportunity, market, strategy, financials', 'The investor shape: why this, why now, how, and the numbers.', [
            ['id' => 'opportunity', 'label' => 'The opportunity', 'question' => 'What unmet need or shift creates the opening?',
                'kinds' => ['bullets', 'quote', 'image-full'],
                'keywords' => ['opportunity', 'problem', 'need', 'gap', 'demand', 'pain', 'unmet', 'why now', 'vision', 'trend', 'shift', 'mission']],
            ['id' => 'market', 'label' => 'The market', 'question' => 'How big is it, who buys, and who else competes?',
                'kinds' => ['data', 'kpi', 'compare'],
                'keywords' => ['market', 'customer', 'segment', 'tam', 'sam', 'som', 'competitor', 'competition', 'industry', 'size', 'growth', 'demand', 'audience', 'buyer', 'landscape', 'addressable']],
            ['id' => 'strategy', 'label' => 'The strategy', 'question' => 'How will you win, and in what order?',
                'kinds' => ['timeline', 'bullets', 'two'],
                'keywords' => ['strategy', 'plan', 'go-to-market', 'gtm', 'product', 'approach', 'roadmap', 'channel', 'pricing', 'model', 'differentiat', 'advantage', 'moat', 'partnership', 'milestone', 'team', 'execution', 'operations']],
            ['id' => 'financials', 'label' => 'The financials', 'question' => 'What do the numbers show: revenue, cost, margin and funding need?',
                'kinds' => ['data', 'kpi'],
                'keywords' => ['financial', 'revenue', 'profit', 'margin', 'cost', 'budget', 'forecast', 'projection', 'ebitda', 'cash', 'funding', 'capex', 'opex', 'valuation', 'income', 'runway', 'cagr', 'return', 'investment']],
            ['id' => 'ask', 'label' => 'The ask', 'question' => 'What do you want from the audience, and what will it be used for?', 'optional' => true,
                'kinds' => ['closing', 'bullets', 'kpi'],
                'keywords' => ['ask', 'raise', 'raising', 'investment', 'round', 'use of funds', 'funding', 'terms', 'invest', 'partner', 'next step', 'join']],
        ]);

        $add('situation-complication-resolution', 'Situation, complication, resolution', 'Start with common ground, introduce the tension, resolve it.', [
            ['id' => 'situation', 'label' => 'The situation', 'question' => 'What does everyone already agree is true?',
                'kinds' => ['bullets', 'data', 'image-left'],
                'keywords' => ['situation', 'context', 'background', 'currently', 'today', 'status quo', 'history', 'baseline', 'landscape', 'existing', 'so far']],
            ['id' => 'complication', 'label' => 'The complication', 'question' => 'What has changed or gone wrong that makes the situation untenable?',
                'kinds' => ['bullets', 'kpi', 'compare', 'quote'],
                'keywords' => ['complication', 'however', 'but', 'challenge', 'problem', 'risk', 'change', 'threat', 'issue', 'pressure', 'gap', 'unexpected', 'disruption', 'constraint', 'delay', 'shortfall', 'decline']],
            ['id' => 'resolution', 'label' => 'The resolution', 'question' => 'What is the answer, and why does it resolve the tension?',
                'kinds' => ['bullets', 'timeline', 'two'],
                'keywords' => ['resolution', 'resolve', 'solution', 'recommend', 'answer', 'address', 'response', 'fix', 'plan', 'action', 'we propose', 'approach', 'mitigat', 'way forward', 'decision']],
            ['id' => 'action', 'label' => 'The action', 'question' => 'Who does what by when?', 'optional' => true,
                'kinds' => ['timeline', 'closing', 'bullets'],
                'keywords' => ['next step', 'action', 'owner', 'deadline', 'timeline', 'decision', 'approve']],
        ]);

        $add('training', 'Training objective, learning content, practical application', 'Say what people will be able to do, teach it, let them practise.', [
            ['id' => 'objective', 'label' => 'Training objective', 'question' => 'What will people be able to do after this session?',
                'kinds' => ['bullets', 'title'],
                'keywords' => ['objective', 'learning outcome', 'goal', 'by the end', 'you will be able', 'outcomes', 'aim', 'purpose', 'learn', 'competency', 'audience', 'prerequisite', 'agenda']],
            ['id' => 'content', 'label' => 'Learning content', 'question' => 'What do people need to know, step by step?',
                'kinds' => ['bullets', 'timeline', 'two', 'image-right'],
                'keywords' => ['content', 'concept', 'principle', 'module', 'topic', 'definition', 'rule', 'procedure', 'standard', 'theory', 'steps', 'process', 'knowledge', 'policy', 'how', 'hazard', 'requirement', 'fundamental']],
            ['id' => 'application', 'label' => 'Practical application', 'question' => 'How will people practise it, and how will you check it worked?',
                'kinds' => ['bullets', 'compare', 'image-left'],
                'keywords' => ['practical', 'application', 'apply', 'exercise', 'activity', 'scenario', 'case study', 'practice', 'hands-on', 'demonstration', 'simulation', 'workshop', 'role play', 'drill', 'assessment', 'quiz', 'checklist']],
            ['id' => 'recap', 'label' => 'Recap and check', 'question' => 'What are the key points, and what happens after the session?', 'optional' => true,
                'kinds' => ['closing', 'bullets'],
                'keywords' => ['recap', 'review', 'summary', 'key takeaway', 'question', 'assessment', 'next', 'certification', 'follow-up']],
        ]);

        $add('project-status', 'Project overview, progress, risks, next steps', 'The status update: where we are, what could hurt, what is next.', [
            ['id' => 'overview', 'label' => 'Project overview', 'question' => 'What is the project, its scope and its goal?',
                'kinds' => ['bullets', 'kpi'],
                'keywords' => ['overview', 'project', 'scope', 'objective', 'purpose', 'background', 'sponsor', 'deliverable', 'goal', 'phase']],
            ['id' => 'progress', 'label' => 'Progress', 'question' => 'What is done, what is on track, what is behind?',
                'kinds' => ['kpi', 'data', 'timeline'],
                'keywords' => ['progress', 'complete', 'completed', 'done', 'milestone', 'delivered', 'achieved', 'on track', 'status', 'percent complete', 'ahead', 'behind', 'schedule', 'update', 'finished', 'to date', 'so far']],
            ['id' => 'risks', 'label' => 'Risks and issues', 'question' => 'What could derail the project and what is being done about it?',
                'kinds' => ['bullets', 'compare', 'kpi'],
                'keywords' => ['risk', 'issue', 'blocker', 'delay', 'concern', 'dependency', 'mitigation', 'threat', 'constraint', 'challenge', 'impact', 'escalat', 'overrun', 'shortage', 'incident', 'safety']],
            ['id' => 'next', 'label' => 'Next steps', 'question' => 'What happens next, who owns it and what do you need decided?',
                'kinds' => ['timeline', 'closing', 'bullets'],
                'keywords' => ['next step', 'next steps', 'upcoming', 'plan', 'action', 'forecast', 'target', 'deadline', 'priority', 'decision', 'ask', 'owner', 'recommend', 'approve', 'approving', 'should']],
        ]);

        $add('free', 'Free structure', 'No fixed story shape: open, make the points, show evidence, draw the implications, close.', [
            ['id' => 'opening', 'label' => 'Opening and purpose', 'question' => 'What is this about and why are we here?',
                'kinds' => ['bullets', 'quote', 'image-full'],
                'keywords' => ['purpose', 'overview', 'context', 'background', 'introduction', 'agenda', 'today']],
            ['id' => 'points', 'label' => 'Key points', 'question' => 'What are the main things you want people to remember?',
                'kinds' => ['bullets', 'two', 'compare', 'timeline'],
                'keywords' => ['key', 'main', 'point', 'reason', 'factor', 'driver', 'why', 'how', 'approach']],
            ['id' => 'evidence', 'label' => 'Evidence', 'question' => 'What data or examples back the points?',
                'kinds' => ['data', 'kpi', 'compare', 'quote'],
                'keywords' => ['data', 'evidence', 'result', 'figure', 'study', 'shows', 'example', 'case', 'number', 'analysis']],
            ['id' => 'implications', 'label' => 'Implications', 'question' => 'So what: what does this mean for the audience?', 'optional' => true,
                'kinds' => ['bullets', 'compare', 'two'],
                'keywords' => ['implication', 'means', 'therefore', 'impact', 'so what', 'consequence', 'risk', 'opportunity', 'effect']],
            ['id' => 'close', 'label' => 'Close and call to action', 'question' => 'What should happen next?',
                'kinds' => ['closing', 'timeline', 'bullets'],
                'keywords' => ['conclusion', 'next step', 'summary', 'recommend', 'action', 'decision', 'ask', 'thank']],
        ]);

        return $fw;
    }
}
