<?php

namespace App\Services\Press\Intel\Rules;

use App\Services\Press\Intel\DeckContext;

/** One family of deck checks. Rules only read the context and return issues; they change nothing. */
abstract class Rule
{
    /** @return list<array<string, mixed>> */
    abstract public function run(DeckContext $ctx): array;

    /**
     * @param  array<string, mixed>|null  $slide  a context slide, or null for a deck level issue
     * @param  array<string, mixed>|null  $el  a context element
     * @param  array{label: string, ops: list<array<string, mixed>>}|null  $fix
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function issue(string $kind, string $category, string $severity, ?array $slide, ?array $el, string $message, string $why, ?array $fix = null, array $extra = []): array
    {
        $issue = [
            'id' => $kind.':'.substr(sha1($kind.'|'.($slide['id'] ?? '').'|'.($el['id'] ?? '').'|'.$message), 0, 10),
            'severity' => $severity,
            'kind' => $kind,
            'category' => $category,
            'slide' => $slide['id'] ?? null,
            'slide_index' => $slide['index'] ?? null,
            'message' => $message,
            'why' => $why,
        ];
        if ($el !== null) {
            $issue['element'] = $el['id'];
        }
        if ($fix !== null && $fix['ops'] !== []) {
            $issue['fix'] = $fix;
        }

        return $issue + $extra;
    }

    /** A short human name for an element in messages. @param array<string, mixed> $el */
    protected function label(array $el): string
    {
        if ($el['name'] !== '') {
            return '"'.mb_substr($el['name'], 0, 40).'"';
        }
        if ($el['has_text']) {
            return '"'.mb_substr(preg_replace('/\s+/u', ' ', $el['text']) ?? '', 0, 32).'"';
        }

        return 'the '.$el['type'];
    }

    protected function round(float $v): float
    {
        return round($v * 2) / 2;
    }
}
