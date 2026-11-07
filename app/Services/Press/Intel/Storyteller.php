<?php

namespace App\Services\Press\Intel;

/**
 * The storytelling assistant. It detects which narrative structure a deck most resembles (or uses
 * the one the person chose), maps slides to its beats, and reports what a listener would miss:
 * purpose stated early, logical progression, evidence for claim slides, consistent messaging, a
 * conclusion and a call to action. It suggests and never forces: when no structure fits well it
 * says so and offers the free structure. Everything it proposes is a list of operations.
 * Always mode "local": rules over the text of the deck, no model.
 */
final class Storyteller
{
    private const PURPOSE = '/\b(purpose|today|this (?:presentation|session|talk|deck|update)|we (?:will|are going to|\'ll)|goal|objective|aim|agenda|overview|why|the problem|in this|welcome)\b/iu';

    private const CONCLUSION = '/\b(conclusion|in summary|summary|takeaways?|in short|to conclude|recap|key points|what (?:this|it) means|therefore|bottom line)\b/iu';

    private const ACTION = '/\b(next steps?|we ask|the ask|approve|decide|decision|please|contact|recommend\w*|action|agree|sign|join|start|book|call us|get in touch|apply|register|invest|vote|we need)\b/iu';

    private const CONNECTIVE = '/\b(however|therefore|so|next|because|as a result|building on|but|meanwhile|then|finally|first|second|third|also|and yet|which means|this is why)\b/iu';

    /**
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    public function analyze(array $doc, ?string $frameworkId = null): array
    {
        $ctx = DeckContext::from($doc);
        $slides = array_values(array_filter($ctx->slides, fn ($s) => ! $s['hidden']));
        $n = count($slides);
        $notes = [];

        if ($n === 0) {
            return $this->empty();
        }

        $info = [];
        foreach ($slides as $i => $s) {
            $title = $ctx->titleText($s);
            $body = $ctx->slideText($s);
            $info[$i] = [
                'slide' => $s,
                'title' => $title,
                'body' => $body,
                'titleLower' => mb_strtolower($title),
                'titleStems' => Frameworks::stemCounts($title),
                'bodyLower' => mb_strtolower($body),
                'bodyStems' => Frameworks::stemCounts($body),
            ];
        }

        $forced = $frameworkId !== null && Frameworks::get($frameworkId) !== null;
        $candidates = $forced ? [$frameworkId] : array_values(array_diff(Frameworks::ids(), ['free']));
        $fits = [];
        foreach ($candidates as $fid) {
            $fits[$fid] = $this->fit($fid, $info);
        }
        uasort($fits, fn ($a, $b) => [$b['coverage'], $b['score']] <=> [$a['coverage'], $a['score']]);

        $alternatives = [];
        foreach (array_slice($fits, 0, 3, true) as $fid => $f) {
            $alternatives[] = ['id' => $fid, 'name' => Frameworks::get($fid)['name'], 'coverage' => round($f['coverage'], 2), 'score' => $f['score']];
        }

        if ($forced) {
            $chosen = $frameworkId;
            $fit = $fits[$chosen];
        } else {
            $chosen = array_key_first($fits);
            $fit = $fits[$chosen];
            if ($fit['coverage'] < 0.5 || $fit['score'] < 4) {
                $chosen = 'free';
                $fit = $this->fit('free', $info);
                $notes[] = 'No standard story structure matches this deck well, so none is forced on it. The free structure is used instead; choose a structure yourself if you want to be held to one.';
            }
        }
        $strength = $chosen === 'free' && ! $forced ? 'weak' : ($fit['coverage'] >= 0.75 ? 'strong' : ($fit['coverage'] >= 0.5 ? 'moderate' : 'weak'));
        if ($forced && $strength === 'weak') {
            $notes[] = 'This deck does not read much like the structure you chose. The report shows what is missing against it; you decide whether the structure suits the talk.';
        }

        $fw = Frameworks::get($chosen);
        $beats = $fw['beats'];

        // Output pieces
        $mapping = [];
        foreach ($fit['assign'] as $i => $b) {
            $mapping[] = [
                'slide' => $slides[$i]['id'],
                'slide_index' => $slides[$i]['index'],
                'beat' => $beats[$b]['id'],
                'beat_label' => $beats[$b]['label'],
                'basis' => $fit['kw'][$i][$b] > 0 ? 'keywords' : 'position',
            ];
        }
        $beatOut = [];
        foreach ($beats as $b => $beat) {
            $on = [];
            foreach ($fit['assign'] as $i => $bb) {
                if ($bb === $b) {
                    $on[] = $slides[$i]['id'];
                }
            }
            $beatOut[] = ['id' => $beat['id'], 'label' => $beat['label'], 'question' => $beat['question'], 'optional' => $beat['optional'], 'covered' => in_array($b, $fit['covered'], true), 'slides' => $on];
        }

        $checks = $this->checks($ctx, $info, $fit, $beats);
        $missing = [];
        $proposals = [];

        foreach ($beats as $b => $beat) {
            if ($beat['optional'] || in_array($b, $fit['covered'], true) || $chosen === 'free') {
                continue;
            }
            // Place the new slide after the last slide of the nearest earlier beat that has slides.
            $after = null;
            for ($pb = $b - 1; $pb >= 0 && $after === null; $pb--) {
                foreach (array_reverse(array_keys($fit['assign'])) as $i) {
                    if ($fit['assign'][$i] === $pb) {
                        $after = $slides[$i]['id'];
                        break;
                    }
                }
            }
            $model = ContentModel::make([
                'title' => $beat['label'], 'kind' => 'bullets', 'slots' => ['title' => $beat['label']],
                'notes' => 'Prompt: '.$beat['question'],
                'needs' => [['slot' => 'bullets', 'label' => ContentModel::NEEDS_LABEL, 'question' => $beat['question']]],
            ]);
            $tid = Ids::make('t', 'story', $beat['id']);
            $ops = [Ops::slideAdd($tid, $after, PlainLayout::slide($model, $ctx->w, $ctx->h, $tid))];
            $missing[] = ['beat' => $beat['id'], 'label' => $beat['label'], 'question' => $beat['question'], 'after' => $after];
            $proposals[] = [
                'id' => 'add:'.$beat['id'],
                'kind' => 'add_beat',
                'mode' => 'local',
                'summary' => 'Add a "'.$beat['label'].'" slide. The deck does not yet answer: '.$beat['question'],
                'ops' => $ops,
            ];
        }

        [$orderIssues, $reorder] = $this->progression($info, $fit, $beats, $slides, $chosen);
        if ($reorder !== null) {
            $proposals[] = $reorder;
        }
        foreach ($checks as $k => $c) {
            if ($c['id'] === 'logical_progression') {
                $checks[$k]['slides'] = array_column($orderIssues, 'slide');
                if ($orderIssues !== []) {
                    $checks[$k]['status'] = count($orderIssues) <= 2 ? 'weak' : 'missing';
                    $checks[$k]['detail'] = implode(' ', array_map(fn ($o) => $o['reason'], array_slice($orderIssues, 0, 3)));
                }
            }
        }

        return [
            'mode' => 'local',
            'framework' => ['id' => $chosen, 'name' => $fw['name'], 'summary' => $fw['summary'], 'strength' => $strength, 'coverage' => round($fit['coverage'], 2), 'forced' => $forced],
            'alternatives' => $alternatives,
            'notes' => $notes,
            'beats' => $beatOut,
            'mapping' => $mapping,
            'checks' => $checks,
            'missing' => $missing,
            'weak_transitions' => $this->transitions($info, $fit),
            'proposals' => $proposals,
        ];
    }

    private function empty(): array
    {
        return ['mode' => 'local', 'framework' => ['id' => 'free', 'name' => 'Free structure', 'summary' => '', 'strength' => 'weak', 'coverage' => 0.0, 'forced' => false], 'alternatives' => [], 'notes' => ['The deck has no visible slides.'], 'beats' => [], 'mapping' => [], 'checks' => [], 'missing' => [], 'weak_transitions' => [], 'proposals' => []];
    }

    /**
     * Align slides to the beats of a framework in order (dynamic programming over a keyword score plus
     * a mild position prior), and decide which beats the text really supports.
     *
     * @param  array<int, array<string, mixed>>  $info
     * @return array{assign: array<int, int>, kw: array<int, array<int, float>>, covered: list<int>, coverage: float, score: float}
     */
    private function fit(string $fid, array $info): array
    {
        $beats = Frameworks::get($fid)['beats'];
        $B = count($beats);
        $n = count($info);
        $kw = [];
        $total = [];
        foreach ($info as $i => $s) {
            foreach ($beats as $b => $beat) {
                $t = Frameworks::beatScore($s['titleLower'], $s['titleStems'], $beat);
                $body = Frameworks::beatScore($s['bodyLower'], $s['bodyStems'], $beat);
                $kw[$i][$b] = 3 * $t + $body;
                $p = $n > 1 ? $i / ($n - 1) : 0.5;
                $prior = 0.8 * max(0.0, 1 - abs($p - ($b + 0.5) / $B) * $B);
                $total[$i][$b] = $kw[$i][$b] + $prior;
            }
        }
        // dp[i][b] = best total for slides 0..i with slide i on beat b, beats never going backwards.
        $dp = [];
        $from = [];
        for ($i = 0; $i < $n; $i++) {
            for ($b = 0; $b < $B; $b++) {
                if ($i === 0) {
                    $dp[0][$b] = $total[0][$b];
                    $from[0][$b] = null;

                    continue;
                }
                $best = -INF;
                $arg = 0;
                for ($pb = 0; $pb <= $b; $pb++) {
                    if ($dp[$i - 1][$pb] > $best) {
                        $best = $dp[$i - 1][$pb];
                        $arg = $pb;
                    }
                }
                $dp[$i][$b] = $best + $total[$i][$b];
                $from[$i][$b] = $arg;
            }
        }
        $b = (int) array_keys($dp[$n - 1], max($dp[$n - 1]))[0];
        $assign = [];
        for ($i = $n - 1; $i >= 0; $i--) {
            $assign[$i] = $b;
            $b = $from[$i][$b] ?? 0;
        }
        ksort($assign);

        $covered = [];
        $score = 0.0;
        foreach ($assign as $i => $b) {
            $score += $kw[$i][$b];
        }
        foreach ($beats as $b => $beat) {
            foreach ($assign as $i => $bb) {
                if ($bb === $b && $kw[$i][$b] >= 2) {
                    $covered[] = $b;
                    break;
                }
            }
            // A slide whose own title names the beat covers it even when it sits out of order (the order is reported separately).
            if (! in_array($b, $covered, true)) {
                foreach ($info as $s) {
                    if (Frameworks::beatScore($s['titleLower'], $s['titleStems'], $beat) >= 1) {
                        $covered[] = $b;
                        break;
                    }
                }
            }
        }
        if ($fid === 'free') {
            $covered = $this->freeCoverage($info);
        }
        $core = array_keys(array_filter($beats, fn ($x) => ! $x['optional']));
        $coverage = count($core) ? count(array_intersect($covered, $core)) / count($core) : 0.0;

        return ['assign' => $assign, 'kw' => $kw, 'covered' => $covered, 'coverage' => $coverage, 'score' => $score];
    }

    /** Free structure: coverage by plain, checkable facts. @return list<int> */
    private function freeCoverage(array $info): array
    {
        $covered = [];
        $n = count($info);
        $first = $info[0]['title']."\n".$info[0]['body'];
        if ($n >= 2 || preg_match(self::PURPOSE, $first)) {
            $covered[] = 0;
        }
        if ($n >= 3) {
            $covered[] = 1;
        }
        foreach ($info as $s) {
            $hasData = false;
            foreach ($s['slide']['els'] as $e) {
                if (in_array($e['type'], ['chart', 'table'], true)) {
                    $hasData = true;
                }
            }
            if ($hasData || Numbers::find($s['body']) !== [] || Claims::hasSourceMarker($s['body'])) {
                $covered[] = 2;
                break;
            }
        }
        $last = $info[$n - 1]['title']."\n".$info[$n - 1]['body'];
        if (preg_match(self::CONCLUSION, $last) || preg_match(self::ACTION, $last)) {
            $covered[] = 4;
        }
        foreach ($info as $s) {
            if (preg_match('/\b(implication|means|so what|impact|consequence)\b/iu', $s['title'].' '.$s['body'])) {
                $covered[] = 3;
                break;
            }
        }

        return array_values(array_unique($covered));
    }

    /**
     * @param  array<int, array<string, mixed>>  $info
     * @param  array<string, mixed>  $fit
     * @param  list<array<string, mixed>>  $beats
     * @return list<array<string, mixed>>
     */
    private function checks(DeckContext $ctx, array $info, array $fit, array $beats): array
    {
        $n = count($info);
        $checks = [];

        // Purpose early
        $early = array_slice($info, 0, 3);
        $purpose = null;
        foreach ($early as $i => $s) {
            if (preg_match(self::PURPOSE, $s['title']."\n".$s['body'])) {
                $purpose = $s;
                break;
            }
        }
        $checks[] = $purpose !== null
            ? ['id' => 'purpose_early', 'label' => 'Purpose stated early', 'status' => 'ok', 'detail' => 'Slide '.$purpose['slide']['index'].' states what the talk is for.', 'slides' => [$purpose['slide']['id']]]
            : ['id' => 'purpose_early', 'label' => 'Purpose stated early', 'status' => ($info[0]['slide']['layout'] === 'title' && count($ctx->textEls($info[0]['slide'])) >= 2) ? 'weak' : 'missing', 'detail' => 'None of the first three slides says what the talk is for or why it matters now. Add one sentence stating the purpose near the start.', 'slides' => [$info[0]['slide']['id']]];

        // Progression (details filled in by the caller from the ordering analysis)
        $checks[] = ['id' => 'logical_progression', 'label' => 'Logical progression', 'status' => 'ok', 'detail' => 'The slides follow the order of the structure.', 'slides' => []];

        // Evidence for claim slides
        $claimSlides = [];
        $unevidenced = [];
        foreach ($info as $s) {
            $sl = $s['slide'];
            if (in_array($sl['layout'], ['title', 'section', 'closing', 'blank'], true) || ! Claims::isAssertion($s['title']) || Claims::isStructuralTitle($s['title'])) {
                continue;
            }
            $claimSlides[] = $sl['id'];
            $has = Numbers::find($s['body']) !== [] || Claims::hasSourceMarker($s['body'].' '.$sl['notes']);
            foreach ($sl['els'] as $e) {
                if (! $e['hide'] && in_array($e['type'], ['chart', 'table', 'image'], true)) {
                    $has = true;
                }
            }
            if (! $has) {
                $unevidenced[] = $sl['id'];
            }
        }
        $share = count($claimSlides) ? count($unevidenced) / count($claimSlides) : 0;
        $checks[] = [
            'id' => 'evidence_for_claims', 'label' => 'Evidence for every claim slide',
            'status' => $unevidenced === [] ? 'ok' : ($share <= 0.4 ? 'weak' : 'missing'),
            'detail' => $claimSlides === [] ? 'No slide makes a claim in its title, so there is nothing to back up yet.' : (count($claimSlides) - count($unevidenced)).' of '.count($claimSlides).' claim slides show a number, chart, table, image or source.',
            'slides' => $unevidenced,
        ];

        // Consistent messaging: do the opening and the close talk about the same things?
        $terms = fn (array $items) => array_count_values(array_merge(...array_map(fn ($s) => Text::contentWords($s['title'].' '.$s['body']), $items) ?: [[]]));
        $open = $terms(array_slice($info, 0, 2));
        $close = $terms(array_slice($info, -2));
        $shared = array_values(array_intersect(array_keys($open), array_keys($close)));
        $checks[] = $n < 4
            ? ['id' => 'consistent_messaging', 'label' => 'Consistent messaging', 'status' => 'ok', 'detail' => 'Too short to compare the opening with the close.', 'slides' => []]
            : ['id' => 'consistent_messaging', 'label' => 'Consistent messaging', 'status' => $shared !== [] ? 'ok' : 'weak',
                'detail' => $shared !== [] ? 'The close returns to terms from the opening ('.implode(', ', array_slice($shared, 0, 4)).').' : 'The closing slides share no key term with the opening. Check that the talk ends where it said it was going.',
                'slides' => [end($info)['slide']['id']]];

        // Conclusion
        $lastTwo = array_slice($info, -2);
        $conclusion = null;
        foreach ($lastTwo as $s) {
            if (preg_match(self::CONCLUSION, $s['title']."\n".$s['body']) || $s['slide']['layout'] === 'closing' && Text::wordCount($s['body']) >= 8) {
                $conclusion = $s;
            }
        }
        $lastSlide = end($info);
        $thanksOnly = Text::wordCount($lastSlide['title'].' '.$lastSlide['body']) <= 6 && preg_match('/\b(thank|questions|q&a)\b/iu', $lastSlide['title'].' '.$lastSlide['body']);
        $checks[] = $conclusion !== null
            ? ['id' => 'conclusion', 'label' => 'A conclusion', 'status' => 'ok', 'detail' => 'Slide '.$conclusion['slide']['index'].' draws the talk together.', 'slides' => [$conclusion['slide']['id']]]
            : ['id' => 'conclusion', 'label' => 'A conclusion', 'status' => $thanksOnly ? 'weak' : 'missing', 'detail' => $thanksOnly ? 'The deck ends on a thank-you slide without summarising what the audience should remember.' : 'No closing slide summarises the argument or the takeaways.', 'slides' => [$lastSlide['slide']['id']]];

        // Call to action
        $cta = null;
        foreach (array_slice($info, -3) as $s) {
            $imperative = false;
            foreach ($ctx->textEls($s['slide']) as $e) {
                foreach ($e['blocks'] as $b) {
                    if ($b['list'] !== null && preg_match('/^(approve|decide|review|join|contact|sign|start|begin|book|schedule|launch|adopt|fund|agree|confirm|choose|apply|register|call|email|visit|download|try|share|send|submit)\b/iu', $b['text'])) {
                        $imperative = true;
                    }
                }
            }
            if ($imperative || preg_match(self::ACTION, $s['title']."\n".$s['body'])) {
                $cta = $s;
            }
        }
        $checks[] = $cta !== null
            ? ['id' => 'call_to_action', 'label' => 'A call to action', 'status' => 'ok', 'detail' => 'Slide '.$cta['slide']['index'].' says what happens next or what is asked of the audience.', 'slides' => [$cta['slide']['id']]]
            : ['id' => 'call_to_action', 'label' => 'A call to action', 'status' => 'missing', 'detail' => 'Nothing in the last slides says what the audience should do, decide or expect next.', 'slides' => [$lastSlide['slide']['id']]];

        return $checks;
    }

    /**
     * Slides whose title points at an earlier beat than the deck has already moved past.
     *
     * @return array{0: list<array<string, mixed>>, 1: ?array<string, mixed>}
     */
    private function progression(array $info, array $fit, array $beats, array $slides, string $chosen): array
    {
        if ($chosen === 'free') {
            return [[], null];
        }
        $titleBeat = [];
        foreach ($info as $i => $s) {
            $best = null;
            $bestScore = 0.0;
            foreach ($beats as $b => $beat) {
                $sc = Frameworks::beatScore($s['titleLower'], $s['titleStems'], $beat);
                if ($sc > $bestScore) {
                    $bestScore = $sc;
                    $best = $b;
                }
            }
            $titleBeat[$i] = $best;
        }
        $maxSeen = -1;
        $out = [];
        foreach ($titleBeat as $i => $b) {
            if ($b === null) {
                continue;
            }
            if ($b < $maxSeen) {
                $out[] = ['slide' => $slides[$i]['id'], 'index' => $slides[$i]['index'], 'beat' => $beats[$b]['id'], 'reason' => 'Slide '.$slides[$i]['index'].' ("'.Text::clip($info[$i]['title'], 50).'") reads like "'.$beats[$b]['label'].'" but comes after the deck has moved on to "'.$beats[$maxSeen]['label'].'".'];

                continue;
            }
            $maxSeen = max($maxSeen, $b);
        }
        if ($out === []) {
            return [[], null];
        }

        // Proposal: move each out-of-place slide after the last in-place slide of its beat.
        $ids = array_column($slides, 'id');
        $moves = [];
        $order = $ids;
        foreach ($out as $o) {
            $target = null;
            foreach ($titleBeat as $i => $b) {
                if ($b === $this->beatIndex($beats, $o['beat']) && $slides[$i]['id'] !== $o['slide'] && ! in_array($slides[$i]['id'], array_column($out, 'slide'), true)) {
                    $target = $slides[$i]['id'];
                }
            }
            if ($target === null) {
                // No slide of its beat to follow: go just before the first slide that belongs to a later beat.
                $ob = $this->beatIndex($beats, $o['beat']);
                foreach ($titleBeat as $i => $b) {
                    if ($b !== null && $b > $ob && $slides[$i]['id'] !== $o['slide']) {
                        $target = $i === 0 ? false : $slides[$i - 1]['id'];
                        if ($target === $o['slide']) {
                            $target = $i - 1 > 0 ? $slides[$i - 2]['id'] : false;
                        }
                        break;
                    }
                }
                if ($target === null) {
                    continue;
                }
                $target = $target === false ? null : $target;
            }
            $moves[] = Ops::slideMove($o['slide'], $target);
        }

        return [$out, $moves === [] ? null : [
            'id' => 'reorder:progression',
            'kind' => 'reorder',
            'mode' => 'local',
            'summary' => 'Move '.count($moves).' slide'.(count($moves) === 1 ? '' : 's').' so the deck follows the order of "'.Frameworks::get($chosen)['name'].'".',
            'ops' => $moves,
        ]];
    }

    private function beatIndex(array $beats, string $id): int
    {
        foreach ($beats as $b => $beat) {
            if ($beat['id'] === $id) {
                return $b;
            }
        }

        return -1;
    }

    /**
     * Neighbouring slides that share no key term and carry no connective.
     *
     * @param  array<int, array<string, mixed>>  $info
     * @return list<array<string, mixed>>
     */
    private function transitions(array $info, array $fit): array
    {
        $out = [];
        $n = count($info);
        for ($i = 0; $i + 1 < $n; $i++) {
            $a = $info[$i];
            $b = $info[$i + 1];
            $skip = ['title', 'section', 'closing', 'blank'];
            if (in_array($a['slide']['layout'], $skip, true) || in_array($b['slide']['layout'], $skip, true)) {
                continue;
            }
            $ta = array_flip(Text::contentWords($a['title'].' '.$a['body']));
            $tb = array_flip(Text::contentWords($b['title'].' '.$b['body']));
            if ($ta === [] || $tb === [] || array_intersect_key($ta, $tb) !== []) {
                continue;
            }
            if (preg_match(self::CONNECTIVE, $b['title']) || preg_match('/^(however|therefore|so|next|because|but|then|finally)\b/iu', $b['body'])) {
                continue;
            }
            $out[] = [
                'from' => $a['slide']['id'], 'to' => $b['slide']['id'], 'from_index' => $a['slide']['index'], 'to_index' => $b['slide']['index'],
                'reason' => 'Slides '.$a['slide']['index'].' and '.$b['slide']['index'].' share no key term and the second does not signal how it follows from the first.',
                'question' => 'How does "'.Text::clip($a['title'], 50).'" lead to "'.Text::clip($b['title'], 50).'"?',
            ];
            if (count($out) >= 12) {
                break;
            }
        }

        return $out;
    }
}
