<?php

namespace App\Services\Press\Intel\Rules;

use App\Services\Press\Intel\DeckContext;
use App\Services\Press\Intel\Ops;
use App\Services\Press\Intel\Text;

/** Bullets repeated across slides and near duplicate slides (3-word shingles, Jaccard). */
final class RepetitionRules extends Rule
{
    public const DUPLICATE_THRESHOLD = 0.8;

    public function run(DeckContext $ctx): array
    {
        return [...$this->bullets($ctx), ...$this->slides($ctx)];
    }

    private function bullets(DeckContext $ctx): array
    {
        $seen = [];
        foreach ($ctx->slides as $s) {
            if ($s['hidden']) {
                continue;
            }
            foreach ($ctx->textEls($s) as $e) {
                if (in_array($e['role'], ['footer', 'number', 'caption', 'title'], true)) {
                    continue;
                }
                foreach ($e['blocks'] as $b) {
                    if (Text::wordCount($b['text']) < 4) {
                        continue;
                    }
                    $key = Text::key($b['text']);
                    if ($key === '' || substr_count($key, ' ') < 2) {
                        continue;
                    }
                    $seen[$key][] = [$s, $e, $b['text']];
                }
            }
        }
        $out = [];
        foreach ($seen as $key => $uses) {
            $slideIds = array_unique(array_map(fn ($u) => $u[0]['id'], $uses));
            if (count($slideIds) < 2) {
                continue;
            }
            [$s, $e, $text] = $uses[1];
            $where = implode(', ', array_map(fn ($u) => $u[0]['index'], array_slice($uses, 0, 6)));
            $out[] = $this->issue('repeated-bullet', 'repetition', 'info', $s, $e,
                '"'.Text::clip($text, 70).'" appears on '.count($slideIds)." slides ({$where}).",
                'Saying the same thing on several slides can be deliberate (a recap), but often it is a leftover. Keep it where it earns its place.',
                null, ['slides' => $slideIds]);
        }

        return $out;
    }

    private function slides(DeckContext $ctx): array
    {
        $sig = [];
        foreach ($ctx->slides as $i => $s) {
            if ($s['hidden']) {
                continue;
            }
            $text = $ctx->titleText($s)."\n".$ctx->slideText($s);
            if (Text::wordCount($text) < 15) {
                continue;
            }
            $sig[$i] = ['sh' => Text::shingles($text), 'n' => Text::wordCount($text)];
        }
        $out = [];
        $flagged = [];
        $idx = array_keys($sig);
        $n = count($idx);
        for ($a = 0; $a < $n; $a++) {
            for ($b = $a + 1; $b < $n; $b++) {
                $x = $sig[$idx[$a]];
                $y = $sig[$idx[$b]];
                if (min($x['n'], $y['n']) / max($x['n'], $y['n']) < 0.6) {
                    continue;
                }
                $j = Text::jaccard($x['sh'], $y['sh']);
                if ($j < self::DUPLICATE_THRESHOLD || isset($flagged[$idx[$b]])) {
                    continue;
                }
                $flagged[$idx[$b]] = true;
                $first = $ctx->slides[$idx[$a]];
                $second = $ctx->slides[$idx[$b]];
                $out[] = $this->issue('duplicate-slide', 'repetition', 'warn', $second, null,
                    "Slide {$second['index']} is ".round($j * 100)."% the same text as slide {$first['index']}.",
                    'Near duplicate slides pad a deck and the audience notices. If it is a deliberate build (a step by step reveal), ignore this.',
                    Ops::fix("Remove slide {$second['index']}", [Ops::slideRemove($second['id'])]),
                    ['similar_to' => $first['id'], 'similarity' => round($j, 2)]);
            }
        }

        return $out;
    }
}
