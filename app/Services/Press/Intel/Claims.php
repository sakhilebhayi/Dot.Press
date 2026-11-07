<?php

namespace App\Services\Press\Intel;

/**
 * Language rules shared by the analyzer, the storyteller and the suggestions: is a title an
 * assertion or a bare topic, and which sentences make a claim that needs a source.
 * These are heuristics for English text. They never judge whether a claim is true.
 */
final class Claims
{
    private const AUXILIARIES = ['is', 'are', 'was', 'were', 'be', 'been', 'has', 'have', 'had', 'will', 'would', 'can', 'could', 'should', 'must', 'may', 'might', 'does', 'do', 'did', "isn't", "aren't", "doesn't", "don't", "can't", "won't", 'needs', 'need'];

    /** Base forms of verbs that commonly head a business assertion. Inflections are generated. */
    private const VERBS = [
        'grow', 'rise', 'fall', 'drop', 'increase', 'decrease', 'reduce', 'improve', 'cut', 'save', 'cost', 'earn', 'lose', 'gain', 'win', 'lead', 'drive', 'deliver',
        'achieve', 'exceed', 'miss', 'meet', 'beat', 'outperform', 'outpace', 'trail', 'lag', 'stall', 'slow', 'accelerate', 'expand', 'shrink', 'double', 'triple',
        'halve', 'fix', 'solve', 'enable', 'allow', 'let', 'help', 'make', 'create', 'build', 'launch', 'ship', 'release', 'open', 'close', 'start', 'stop', 'begin',
        'end', 'finish', 'complete', 'need', 'require', 'depend', 'rely', 'cause', 'prevent', 'avoid', 'protect', 'secure', 'risk', 'threaten', 'hurt', 'harm',
        'show', 'prove', 'confirm', 'reveal', 'suggest', 'indicate', 'find', 'see', 'know', 'think', 'believe', 'expect', 'forecast', 'predict', 'plan', 'target',
        'aim', 'want', 'wish', 'hope', 'choose', 'pick', 'select', 'decide', 'approve', 'reject', 'accept', 'adopt', 'use', 'apply', 'run', 'operate', 'work',
        'move', 'shift', 'change', 'turn', 'become', 'remain', 'stay', 'keep', 'hold', 'carry', 'bring', 'take', 'give', 'get', 'put', 'set', 'pay', 'charge',
        'sell', 'buy', 'cover', 'include', 'exclude', 'contain', 'offer', 'provide', 'support', 'serve', 'connect', 'link', 'combine', 'merge', 'split', 'replace',
        'remove', 'add', 'raise', 'lower', 'boost', 'lift', 'push', 'pull', 'invest', 'fund', 'spend', 'budget', 'staff', 'hire', 'train', 'teach', 'learn',
        'measure', 'track', 'monitor', 'report', 'review', 'audit', 'test', 'check', 'verify', 'test', 'mean', 'matter', 'fail', 'succeed', 'pass', 'reach', 'hit',
        'cross', 'top', 'average', 'total', 'represent', 'account', 'remove', 'automate', 'simplify', 'standardise', 'standardize', 'centralise', 'centralize',
        'consolidate', 'streamline', 'optimise', 'optimize', 'prioritise', 'prioritize', 'ensure', 'guarantee', 'commit', 'promise', 'warn', 'recommend', 'propose',
        'face', 'suffer', 'struggle', 'lack', 'miss', 'wait', 'delay', 'defer', 'extend', 'shorten', 'lengthen', 'happen', 'occur', 'emerge', 'appear', 'look',
        'sit', 'stand', 'rest', 'flow', 'sum', 'exist', 'differ', 'compare', 'match', 'fit', 'suit', 'benefit', 'profit', 'pay', 'outweigh', 'overtake', 'undercut',
    ];

    private const IRREGULAR = ['grew', 'grown', 'rose', 'risen', 'fell', 'fallen', 'lost', 'won', 'led', 'drove', 'driven', 'met', 'beat', 'beaten', 'made', 'built', 'ran', 'sold', 'bought',
        'paid', 'took', 'taken', 'gave', 'given', 'got', 'gotten', 'held', 'kept', 'found', 'saw', 'seen', 'knew', 'known', 'thought', 'chose', 'chosen', 'began', 'begun', 'cut', 'set', 'put',
        'came', 'went', 'gone', 'showed', 'shown', 'spent', 'brought', 'taught', 'became', 'stood', 'left', 'shrank', 'shrunk', 'slowed', 'doubled', 'halved', 'is', 'am', 'was', 'were', 'has', 'does'];

    /** @var array<string, true>|null */
    private static ?array $verbSet = null;

    /** Titles that are topics by convention and need no verb. */
    private const STRUCTURAL_TITLES = ['agenda', 'overview', 'introduction', 'summary', 'executive summary', 'contents', 'table of contents', 'appendix', 'questions', 'q&a', 'thank you', 'thanks', 'next steps', 'conclusion', 'recap', 'background', 'about us', 'contact', 'references', 'sources', 'objectives', 'welcome', 'recommendations', 'key takeaways', 'agenda today'];

    public static function isStructuralTitle(string $title): bool
    {
        return in_array(mb_strtolower(trim($title, " \t\n.:")), self::STRUCTURAL_TITLES, true);
    }

    private static function verbs(): array
    {
        if (self::$verbSet !== null) {
            return self::$verbSet;
        }
        $set = [];
        foreach (self::VERBS as $v) {
            $set[$v] = true;
            $set[$v.'s'] = true;
            $set[$v.'es'] = true;
            $set[$v.'ed'] = true;
            $set[$v.'d'] = true;
            $set[$v.'ing'] = true;
            if (str_ends_with($v, 'e')) {
                $set[mb_substr($v, 0, -1).'ing'] = true;
            }
            if (str_ends_with($v, 'y')) {
                $set[mb_substr($v, 0, -1).'ies'] = true;
                $set[mb_substr($v, 0, -1).'ied'] = true;
            }
            // doubled final consonant: stop -> stopped, run -> running
            if (preg_match('/[^aeiou][aeiou][bdgmnprt]$/', $v)) {
                $set[$v.substr($v, -1).'ed'] = true;
                $set[$v.substr($v, -1).'ing'] = true;
            }
        }
        foreach (self::IRREGULAR as $v) {
            $set[$v] = true;
        }
        foreach (self::AUXILIARIES as $v) {
            $set[$v] = true;
        }

        return self::$verbSet = $set;
    }

    /** Does the text read as a statement (verb, figure or full sentence) rather than a topic label? */
    public static function isAssertion(string $title): bool
    {
        $title = Text::collapse($title);
        if ($title === '') {
            return false;
        }
        if (self::isStructuralTitle($title)) {
            return false;
        }
        $words = Text::words($title);
        if (count($words) >= 9) {
            return true;
        }
        $verbs = self::verbs();
        foreach ($words as $w) {
            if (isset($verbs[$w])) {
                return true;
            }
        }
        // "Costs up 12%" has no listed verb but carries a figure with a direction.
        if (preg_match('/\d/', $title) && count($words) >= 3) {
            return true;
        }

        return false;
    }

    /**
     * Sentences that make a claim needing a source. Never a verdict on truth.
     *
     * @return list<array{type: string, match: string, sentence: string, question: string}>
     */
    public static function find(string $text): array
    {
        $out = [];
        foreach (Text::sentences($text) as $sentence) {
            if (mb_strlen($sentence) > 1500) {
                $sentence = mb_substr($sentence, 0, 1500);
            }
            foreach (self::patterns() as $type => $re) {
                if (preg_match($re, $sentence, $m)) {
                    $out[] = ['type' => $type, 'match' => trim($m[0]), 'sentence' => $sentence, 'question' => self::question($type, trim($m[0]))];
                    break;
                }
            }
        }

        return $out;
    }

    /** @return array<string, string> */
    private static function patterns(): array
    {
        return [
            'percentage' => '/\d+(?:\.\d+)?\s?(?:%|percent\b|per cent\b)/iu',
            'money' => '/(?:(?:R|\$|€|£|¥|USD|ZAR|EUR|GBP)\s?\d[\d ,.]*(?:\s?(?:k|m|bn|mn|million|billion|thousand|trillion)\b)?|\b\d[\d ,.]*\s?(?:million|billion|trillion)\b)/iu',
            'superlative' => '/\b(?:best|fastest|largest|biggest|leading|leader|top|highest|lowest|greatest|cheapest|safest|only|unmatched|unrivalled|unrivaled|world-class|record|first-ever|number one|#1|most (?:advanced|popular|trusted|efficient|reliable|profitable)|market leader)\b/iu',
            'comparative' => '/\b(?:\w+er than|more than|less than|fewer than|outperform\w*|\d+(?:\.\d+)?\s?x\b|\d+ times|twice as|double[sd]?|triple[sd]?|halved|up from|down from|grew|rose|fell|declined)\b/iu',
        ];
    }

    private static function question(string $type, string $match): string
    {
        return match ($type) {
            'percentage' => "Where does {$match} come from, over what period and against what base?",
            'money' => "What is the source and date for {$match}, and is it actual or forecast?",
            'superlative' => 'Best, largest or first according to which measure, source and date?',
            default => 'Compared with what, over what period, and who measured it?',
        };
    }

    /** Does the text carry a source marker (a "Source:" line, "according to", a numbered footnote)? */
    public static function hasSourceMarker(string $text): bool
    {
        return (bool) preg_match('/\bsources?\s*[:\-]|\(sources?\b|according to\b|\bper\s+[A-Z][\p{L}]+|\[\d+\]|[¹²³⁴⁵⁶⁷⁸⁹]|\bsrc\s*:|\*\s*(?:source|based on|estimate|note)|\bnote\s*:|\bsurvey\b|\bfootnote\b|\bcitation\b/iu', $text);
    }
}
