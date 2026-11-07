<?php

namespace App\Services\Press\Intel;

use App\Services\Press\Intel\Rules\AccessibilityRules;
use App\Services\Press\Intel\Rules\AnimationRules;
use App\Services\Press\Intel\Rules\ClaimRules;
use App\Services\Press\Intel\Rules\ConsistencyRules;
use App\Services\Press\Intel\Rules\DensityRules;
use App\Services\Press\Intel\Rules\HierarchyRules;
use App\Services\Press\Intel\Rules\PlaceholderRules;
use App\Services\Press\Intel\Rules\ReadabilityRules;
use App\Services\Press\Intel\Rules\RepetitionRules;
use App\Services\Press\Intel\Rules\Rule;
use App\Services\Press\Intel\Rules\TerminologyRules;
use App\Services\Press\Intel\Rules\TextFitRules;

/**
 * Deterministic whole-deck review. Pure function of the document (docs/programme/R40_DOCUMENT_SPEC.md):
 * no model, no network, no side effects, same result for the same deck. Always mode "local".
 *
 * Issues: { id, severity: info|warn|issue, kind, category, slide, slide_index, element?, message, why,
 *           fix?: { label, ops: [spec ops] }, alternatives?: [fix] }. A fix is only ever a proposal.
 * Claims are never judged true or false; an unsupported claim comes with the question to answer.
 */
final class DeckAnalyzer
{
    private const SEVERITY_ORDER = ['issue' => 0, 'warn' => 1, 'info' => 2];

    /** @return list<Rule> */
    private function rules(): array
    {
        return [
            new ConsistencyRules, new TextFitRules, new ReadabilityRules, new DensityRules, new HierarchyRules,
            new TerminologyRules, new RepetitionRules, new ClaimRules, new PlaceholderRules, new AccessibilityRules, new AnimationRules,
        ];
    }

    /**
     * @param  array<string, mixed>  $doc  {deck: {...}, slides: [...]}
     * @return array{mode: string, issues: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function analyze(array $doc): array
    {
        $ctx = DeckContext::from($doc);
        $issues = [];
        foreach ($this->rules() as $rule) {
            array_push($issues, ...$rule->run($ctx));
        }

        // Stable order: worst first, then deck position, then kind.
        usort($issues, fn ($a, $b) => [self::SEVERITY_ORDER[$a['severity']], $a['slide_index'] ?? 0, $a['kind'], $a['id']]
            <=> [self::SEVERITY_ORDER[$b['severity']], $b['slide_index'] ?? 0, $b['kind'], $b['id']]);

        $cap = (int) config('press-ai.analyze.max_issues_per_kind', 60);
        $kept = [];
        $perKind = [];
        $truncated = [];
        foreach ($issues as $i) {
            $perKind[$i['kind']] = ($perKind[$i['kind']] ?? 0) + 1;
            if ($perKind[$i['kind']] > $cap) {
                $truncated[$i['kind']] = ($truncated[$i['kind']] ?? 0) + 1;

                continue;
            }
            $kept[] = $i;
        }

        $bySeverity = ['issue' => 0, 'warn' => 0, 'info' => 0];
        $byCategory = [];
        $slidesWith = [];
        foreach ($issues as $i) {
            $bySeverity[$i['severity']]++;
            $byCategory[$i['category']] = ($byCategory[$i['category']] ?? 0) + 1;
            if ($i['slide'] !== null) {
                $slidesWith[$i['slide']] = true;
            }
        }
        ksort($byCategory);

        return [
            'mode' => 'local',
            'issues' => $kept,
            'summary' => [
                'total' => count($issues),
                'by_severity' => $bySeverity,
                'by_category' => $byCategory,
                'by_kind' => $perKind,
                'truncated' => $truncated,
                'slides' => count($ctx->slides),
                'slides_with_issues' => count($slidesWith),
                'with_fix' => count(array_filter($issues, fn ($i) => isset($i['fix']))),
            ],
        ];
    }
}
