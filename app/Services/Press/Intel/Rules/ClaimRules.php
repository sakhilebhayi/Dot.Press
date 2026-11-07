<?php

namespace App\Services\Press\Intel\Rules;

use App\Services\Press\Intel\Claims;
use App\Services\Press\Intel\DeckContext;
use App\Services\Press\Intel\Numbers;

/**
 * Claims that show a figure or a comparison with no source, chart or footnote on the slide. This
 * never says a claim is false; it asks the question the audience will ask.
 */
final class ClaimRules extends Rule
{
    public function run(DeckContext $ctx): array
    {
        $out = [];
        foreach ($ctx->slides as $s) {
            if ($s['hidden']) {
                continue;
            }
            $visible = $ctx->visible($s);
            $hasChart = false;
            $chartValues = [];
            foreach ($visible as $e) {
                if ($e['type'] === 'chart' || $e['type'] === 'table') {
                    $hasChart = true;
                    foreach (is_array($e['chart']['data']['series'] ?? null) ? $e['chart']['data']['series'] : [] as $ser) {
                        foreach (is_array($ser['values'] ?? null) ? $ser['values'] : [] as $v) {
                            if (is_numeric($v)) {
                                $chartValues[] = (float) $v;
                            }
                        }
                    }
                    foreach (Numbers::find($e['text']) as $n) {
                        $chartValues[] = $n['value'];
                    }
                }
            }
            $allText = $ctx->titleText($s)."\n".$ctx->slideText($s);
            $sourced = Claims::hasSourceMarker($allText) || preg_match('/\bsources?\b/i', $s['notes']) === 1;
            foreach ($visible as $e) {
                if (in_array($e['role'], ['footer', 'caption'], true) && preg_match('/\bsource/i', $e['text'])) {
                    $sourced = true;
                }
            }
            if ($sourced) {
                continue;
            }

            $claims = [];
            $texts = $ctx->textEls($s);
            foreach ($texts as $e) {
                if (in_array($e['role'], ['footer', 'number'], true)) {
                    continue;
                }
                foreach (Claims::find($e['text']) as $c) {
                    // A chart or table on the slide that contains the figure is its evidence.
                    if ($hasChart && in_array($c['type'], ['percentage', 'money'], true)) {
                        $nums = Numbers::find($c['match']);
                        $inChart = $nums !== [] && count(array_filter($nums, fn ($n) => in_array($n['value'], $chartValues, true) || in_array($n['value'] / 1e6, $chartValues, true))) === count($nums);
                        if ($inChart) {
                            continue;
                        }
                    }
                    $claims[] = $c + ['element' => $e];
                }
            }
            if ($claims === []) {
                continue;
            }
            $first = $claims[0];
            $questions = array_map(fn ($c) => '"'.$c['match'].'": '.$c['question'], array_slice($claims, 0, 5));
            $out[] = $this->issue('unsupported-claim', 'claims', 'warn', $s, $first['element'],
                'Unsupported claim'.(count($claims) > 1 ? 's' : '').': nothing on this slide says where '.($first['type'] === 'superlative' || $first['type'] === 'comparative' ? 'the comparison' : 'the figure').' "'.$first['match'].'" comes from'.(count($claims) > 1 ? ' (and '.(count($claims) - 1).' more)' : '').'.',
                'Figures and comparisons invite the question "says who?". A source line, a chart or a footnote answers it. This is not a judgement that the claim is wrong.',
                null, ['claims' => array_map(fn ($c) => ['text' => $c['match'], 'type' => $c['type'], 'element' => $c['element']['id'], 'question' => $c['question']], $claims), 'questions' => $questions]);
        }

        return $out;
    }
}
