<?php

namespace App\Services\Press\Intel;

use InvalidArgumentException;

/**
 * The argument spine of a deck: one claim per beat, each beat owning the slides that argue it.
 *
 *   extract($doc)           -> { beats: [ { id, claim, slides: [slideId], section_id, hollow, evidence } ], uses_sections }
 *   applyEdit($doc, $edit)  -> { ops, order, beats }   (ops that reflow the slides; nothing is applied here)
 *
 * Beats follow the deck's sections when it has them, otherwise runs of related slides (a section
 * layout slide always starts a new beat; a title slide stands alone). A beat is `hollow` when it
 * has no evidence: no chart, table, figure in the text or source line.
 *
 * Edits: { type: "reorder", beat, after: beatId|null }, { type: "merge", beat, into }, { type: "split", beat,
 * at: slideId, claim? }, { type: "rename", beat, claim }. A beat id is "b_" plus a hash of its first slide id,
 * so it survives reordering. Structural edits persist as sections: the first edit on a deck without sections
 * also adopts one section per beat (titled by its claim) so the spine stays stable. That adoption is part of
 * the returned ops and is stated in the response.
 */
final class Spine
{
    /**
     * @param  array<string, mixed>  $doc
     * @return array{beats: list<array<string, mixed>>, uses_sections: bool}
     */
    public function extract(array $doc): array
    {
        $ctx = DeckContext::from($doc);
        $sections = [];
        foreach (is_array($doc['deck']['sections'] ?? null) ? $doc['deck']['sections'] : [] as $sec) {
            if (is_array($sec) && isset($sec['id'])) {
                $sections[(string) $sec['id']] = (string) ($sec['title'] ?? '');
            }
        }
        $uses = false;
        foreach ($ctx->slides as $s) {
            if ($s['section_id'] !== null && isset($sections[(string) $s['section_id']])) {
                $uses = true;
                break;
            }
        }

        $groups = [];
        if ($uses) {
            $cur = null;
            foreach ($ctx->slides as $i => $s) {
                $sid = $s['section_id'] !== null && isset($sections[(string) $s['section_id']]) ? (string) $s['section_id'] : null;
                if ($cur === null || $cur['section'] !== $sid) {
                    if ($cur !== null) {
                        $groups[] = $cur;
                    }
                    $cur = ['section' => $sid, 'idx' => []];
                }
                $cur['idx'][] = $i;
            }
            if ($cur !== null) {
                $groups[] = $cur;
            }
            // Slides without a section are cut into runs of related slides.
            $expanded = [];
            foreach ($groups as $g) {
                if ($g['section'] !== null) {
                    $expanded[] = $g;
                } else {
                    foreach ($this->runs($ctx, $g['idx']) as $run) {
                        $expanded[] = ['section' => null, 'idx' => $run];
                    }
                }
            }
            $groups = $expanded;
        } else {
            foreach ($this->runs($ctx, array_keys($ctx->slides)) as $run) {
                $groups[] = ['section' => null, 'idx' => $run];
            }
        }

        $beats = [];
        foreach ($groups as $g) {
            $slides = array_map(fn ($i) => $ctx->slides[$i], $g['idx']);
            $claim = $g['section'] !== null && trim($sections[$g['section']]) !== '' ? trim($sections[$g['section']]) : $this->claim($ctx, $slides);
            $beats[] = [
                'id' => 'b_'.substr(sha1($slides[0]['id']), 0, 10),
                'claim' => $claim,
                'slides' => array_column($slides, 'id'),
                'section_id' => $g['section'],
            ] + $this->evidence($ctx, $slides);
        }

        return ['beats' => $beats, 'uses_sections' => $uses];
    }

    /**
     * @param  list<int>  $indexes
     * @return list<list<int>>
     */
    private function runs(DeckContext $ctx, array $indexes): array
    {
        $runs = [];
        $cur = [];
        $recent = [];
        foreach ($indexes as $i) {
            $s = $ctx->slides[$i];
            $terms = array_flip(Text::contentWords($ctx->titleText($s).' '.$ctx->slideText($s)));
            $startsNew = false;
            if ($cur !== []) {
                $prev = $ctx->slides[end($cur)];
                $vocab = [];
                foreach ($recent as $r) {
                    $vocab += $r;
                }
                if (in_array($s['layout'], ['section', 'closing'], true) || $prev['layout'] === 'title') {
                    $startsNew = true;
                } elseif ($prev['layout'] !== 'section' && $terms !== [] && $vocab !== []) {
                    $startsNew = count(array_intersect_key($terms, $vocab)) / min(count($terms), count($vocab)) < 0.2;
                }
                if (! $startsNew && count($cur) >= 6) {
                    $startsNew = true;
                }
            }
            if ($startsNew) {
                $runs[] = $cur;
                $cur = [];
                $recent = [];
            }
            $cur[] = $i;
            $recent[] = $terms;
            $recent = array_slice($recent, -2);
        }
        if ($cur !== []) {
            $runs[] = $cur;
        }

        return $runs;
    }

    /** @param  list<array<string, mixed>>  $slides */
    private function claim(DeckContext $ctx, array $slides): string
    {
        $visible = array_values(array_filter($slides, fn ($s) => ! $s['hidden']));
        $pool = $visible ?: $slides;
        $titles = [];
        foreach ($pool as $s) {
            $t = $ctx->titleText($s);
            if ($t !== '') {
                $titles[] = ['t' => $t, 'terms' => array_flip(Text::contentWords($t.' '.$ctx->slideText($s))), 'layout' => $s['layout'], 'slide' => $s];
            }
        }
        if ($titles === []) {
            return '';
        }
        $best = null;
        $bestScore = -1.0;
        foreach ($titles as $a) {
            $score = (Claims::isAssertion($a['t']) ? 2.0 : 0.0) + (in_array($a['layout'], ['title', 'closing'], true) ? -0.5 : 0.0);
            foreach ($titles as $b) {
                if ($a !== $b) {
                    $score += Text::jaccard($a['terms'], $b['terms']);
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $a;
            }
        }
        if (! Claims::isAssertion($best['t'])) {
            // No title makes a statement: fall back to the first sentence of the leading slide that has a figure or a verb.
            foreach ($pool as $s) {
                foreach (Text::sentences($ctx->slideText($s)) as $sentence) {
                    if (Claims::isAssertion($sentence) && Text::wordCount($sentence) <= 30) {
                        return Titles::fromClaim($sentence)['title'];
                    }
                }
            }
        }

        return $best['t'];
    }

    /**
     * @param  list<array<string, mixed>>  $slides
     * @return array{hollow: bool, evidence: array<string, int>}
     */
    private function evidence(DeckContext $ctx, array $slides): array
    {
        $ev = ['charts' => 0, 'tables' => 0, 'images' => 0, 'numbers' => 0, 'sources' => 0];
        $claimBearing = false;
        foreach ($slides as $s) {
            if (! in_array($s['layout'], ['title', 'section', 'closing'], true)) {
                $claimBearing = true;
            }
            foreach ($s['els'] as $e) {
                if ($e['hide']) {
                    continue;
                }
                $ev['charts'] += $e['type'] === 'chart' ? 1 : 0;
                $ev['tables'] += $e['type'] === 'table' ? 1 : 0;
                $ev['images'] += $e['type'] === 'image' ? 1 : 0;
            }
            $text = $ctx->slideText($s);
            $ev['numbers'] += count(Numbers::find($text));
            $ev['sources'] += Claims::hasSourceMarker($text) ? 1 : 0;
        }

        return ['hollow' => $claimBearing && $ev['charts'] + $ev['tables'] + $ev['numbers'] + $ev['sources'] === 0, 'evidence' => $ev];
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  array<string, mixed>  $edit
     * @return array{ops: list<array<string, mixed>>, order: list<string>, beats: list<array<string, mixed>>, adopted_sections: bool}
     */
    public function applyEdit(array $doc, array $edit): array
    {
        $extract = $this->extract($doc);
        $beats = $extract['beats'];
        $byId = [];
        foreach ($beats as $b) {
            $byId[$b['id']] = $b;
        }
        $type = (string) ($edit['type'] ?? '');
        $beatId = (string) ($edit['beat'] ?? '');
        if (! isset($byId[$beatId])) {
            throw new InvalidArgumentException('Unknown beat.');
        }

        $ops = [];
        $adopted = false;
        // Section ids for every beat (existing, or new ones to adopt).
        $sectionOf = [];
        foreach ($beats as $b) {
            $sectionOf[$b['id']] = $b['section_id'];
        }
        $needAdopt = ! $extract['uses_sections'] || in_array(null, $sectionOf, true);
        if ($needAdopt) {
            $adopted = true;
            $prev = null;
            foreach ($beats as $b) {
                if ($sectionOf[$b['id']] === null) {
                    $sid = Ids::make('sec', $b['id']);
                    $sectionOf[$b['id']] = $sid;
                    $ops[] = Ops::sectionAdd($sid, $b['claim'] !== '' ? Text::clip($b['claim'], 120) : 'Section', $prev);
                    foreach ($b['slides'] as $slideId) {
                        $ops[] = Ops::slideSet($slideId, ['section_id' => $sid]);
                    }
                }
                $prev = $sectionOf[$b['id']];
            }
        }

        $order = array_merge(...array_map(fn ($b) => $b['slides'], $beats));
        $newBeats = $beats;
        $beat = $byId[$beatId];

        switch ($type) {
            case 'reorder':
                $after = $edit['after'] ?? null;
                if ($after !== null && (! isset($byId[(string) $after]) || $after === $beatId)) {
                    throw new InvalidArgumentException('Unknown target beat.');
                }
                $rest = array_values(array_filter($newBeats, fn ($b) => $b['id'] !== $beatId));
                $pos = 0;
                if ($after !== null) {
                    foreach ($rest as $k => $b) {
                        if ($b['id'] === $after) {
                            $pos = $k + 1;
                        }
                    }
                }
                array_splice($rest, $pos, 0, [$beat]);
                $newBeats = $rest;
                break;

            case 'merge':
                $into = (string) ($edit['into'] ?? '');
                if (! isset($byId[$into]) || $into === $beatId) {
                    throw new InvalidArgumentException('Unknown target beat.');
                }
                $rest = [];
                foreach ($newBeats as $b) {
                    if ($b['id'] === $beatId) {
                        continue;
                    }
                    if ($b['id'] === $into) {
                        $b['slides'] = array_merge($b['slides'], $beat['slides']);
                    }
                    $rest[] = $b;
                }
                $newBeats = $rest;
                foreach ($beat['slides'] as $slideId) {
                    $ops[] = Ops::slideSet($slideId, ['section_id' => $sectionOf[$into]]);
                }
                $ops[] = Ops::sectionRemove($sectionOf[$beatId]);
                break;

            case 'split':
                $at = (string) ($edit['at'] ?? '');
                $k = array_search($at, array_map('strval', $beat['slides']), true);
                if ($k === false || $k === 0) {
                    throw new InvalidArgumentException('A beat can only be split at one of its later slides.');
                }
                $tail = array_slice($beat['slides'], $k);
                $head = array_slice($beat['slides'], 0, $k);
                $ctx = DeckContext::from($doc);
                $lead = $ctx->slides[$ctx->indexById[$tail[0]]];
                $claim = Text::clip(trim((string) ($edit['claim'] ?? '')) !== '' ? (string) $edit['claim'] : ($ctx->titleText($lead) ?: 'New section'), 120);
                $sid = Ids::make('sec', 'split', $beatId, $at);
                $ops[] = Ops::sectionAdd($sid, $claim, $sectionOf[$beatId]);
                foreach ($tail as $slideId) {
                    $ops[] = Ops::slideSet($slideId, ['section_id' => $sid]);
                }
                $out = [];
                foreach ($newBeats as $b) {
                    if ($b['id'] === $beatId) {
                        $b['slides'] = $head;
                        $out[] = $b;
                        $out[] = ['id' => 'b_'.substr(sha1($tail[0]), 0, 10), 'claim' => $claim, 'slides' => $tail, 'section_id' => $sid, 'hollow' => false, 'evidence' => []];
                    } else {
                        $out[] = $b;
                    }
                }
                $newBeats = $out;
                break;

            case 'rename':
                $claim = Text::clip(trim((string) ($edit['claim'] ?? '')), 120);
                if ($claim === '') {
                    throw new InvalidArgumentException('A claim cannot be empty.');
                }
                $ops[] = Ops::sectionSet($sectionOf[$beatId], $claim);
                foreach ($newBeats as $k => $b) {
                    if ($b['id'] === $beatId) {
                        $newBeats[$k]['claim'] = $claim;
                    }
                }
                break;

            default:
                throw new InvalidArgumentException('Unknown spine edit.');
        }

        $target = array_merge(...array_map(fn ($b) => $b['slides'], $newBeats));
        foreach ($this->moves($order, $target) as $m) {
            $ops[] = $m;
        }

        return [
            'ops' => $ops,
            'order' => $target,
            'beats' => array_map(fn ($b) => ['id' => $b['id'], 'claim' => $b['claim'], 'slides' => $b['slides'], 'section_id' => $sectionOf[$b['id']] ?? ($b['section_id'] ?? null)], $newBeats),
            'adopted_sections' => $adopted,
        ];
    }

    /**
     * Slide moves that turn $current into $target (same ids), with the fewest repositionings found
     * by walking the target and moving only slides that are not already in place.
     *
     * @param  list<string>  $current
     * @param  list<string>  $target
     * @return list<array<string, mixed>>
     */
    private function moves(array $current, array $target): array
    {
        $ops = [];
        foreach ($target as $i => $id) {
            if (($current[$i] ?? null) === $id) {
                continue;
            }
            $from = array_search($id, $current, true);
            array_splice($current, (int) $from, 1);
            array_splice($current, $i, 0, [$id]);
            $ops[] = Ops::slideMove($id, $i === 0 ? null : $target[$i - 1]);
        }

        return $ops;
    }
}
