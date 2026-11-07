<?php

namespace App\Services\Press\Intel\Rules;

use App\Services\Press\Intel\DeckContext;
use App\Services\Press\Intel\DocText;
use App\Services\Press\Intel\Ops;
use App\Services\Press\Intel\Text;

/**
 * Spelling variants (email or e-mail, UK or US), capitalisation variants of one term, number and
 * currency formats, and acronyms used before they are defined.
 */
final class TerminologyRules extends Rule
{
    /** Spellings that differ by a hyphen or a space. The first form is the tie-break winner. */
    private const FORM_GROUPS = [
        ['email', 'e-mail'],
        ['website', 'web site', 'web-site'],
        ['online', 'on-line'],
        ['healthcare', 'health care'],
        ['dataset', 'data set'],
        ['database', 'data base'],
        ['workflow', 'work flow', 'work-flow'],
        ['timeline', 'time line'],
        ['checklist', 'check list', 'check-list'],
        ['setup', 'set-up'],
    ];

    private const COMMON_ACRONYMS = ['UK', 'US', 'USA', 'EU', 'UN', 'AI', 'HR', 'IT', 'PR', 'CEO', 'CFO', 'COO', 'CTO', 'CIO', 'KPI', 'ROI', 'OK', 'PDF', 'PC', 'TV', 'USD', 'ZAR', 'EUR', 'GBP',
        'GDP', 'VAT', 'FAQ', 'AM', 'PM', 'URL', 'API', 'SEO', 'CSV', 'ID', 'IP', 'UI', 'UX', 'SA', 'RSA', 'ASAP', 'FYI', 'ETA', 'VIP', 'DIY', 'ATM', 'GPS', 'SMS', 'USB', 'WIFI', 'HTML', 'CSS', 'PPT',
        'II', 'III', 'IV', 'VI', 'VII', 'VIII', 'IX', 'XI', 'XII', 'TBD', 'TBC', 'XX', 'XXX', 'NA', 'FY', 'YTD', 'MTD', 'QA', 'FAQS', 'ISO', 'CV', 'RSVP', 'AKA', 'EG', 'IE', 'VS', 'NB', 'PS'];

    /** @var array<string, string>|null UK form => US form */
    private static ?array $uk = null;

    public function run(DeckContext $ctx): array
    {
        $corpus = [];
        foreach ($ctx->slides as $s) {
            if ($s['hidden']) {
                continue;
            }
            foreach ($ctx->textEls($s) as $e) {
                $corpus[] = [$s, $e];
            }
        }

        return [
            ...$this->dialects($corpus),
            ...$this->forms($corpus),
            ...$this->caseVariants($corpus),
            ...$this->numberFormats($corpus),
            ...$this->acronyms($ctx),
        ];
    }

    // ------------------------------------------------------------------ spelling

    /** @return array<string, string> */
    public static function ukToUs(): array
    {
        if (self::$uk !== null) {
            return self::$uk;
        }
        $m = [];
        $ise = ['organ', 'real', 'recogn', 'optim', 'minim', 'maxim', 'util', 'final', 'special', 'summar', 'priorit', 'standard', 'central', 'local', 'custom', 'mobil', 'author', 'capital', 'categor',
            'emphas', 'legal', 'normal', 'personal', 'visual', 'harmon', 'memor', 'modern', 'neutral', 'synthes', 'apolog', 'critic', 'digit', 'general', 'global', 'social', 'stabil', 'symbol', 'character',
            'computer', 'equal', 'formal', 'fertil', 'hypothes', 'immun', 'initial', 'institution', 'liberal', 'magnet', 'mechan', 'monopol', 'motiv', 'natural', 'nation', 'penal', 'polar', 'popular',
            'pressur', 'privat', 'rational', 'regular', 'revolution', 'scrutin', 'sensit', 'steril', 'sympath', 'terror', 'theor', 'trivial', 'urban', 'vandal', 'vapor', 'verbal', 'vocal', 'commercial',
            'industrial', 'internation', 'hospital', 'fossil', 'item'];
        foreach ($ise as $stem) {
            foreach (['e', 'es', 'ed', 'ing', 'er', 'ers', 'ation', 'ations'] as $end) {
                $m[$stem.'is'.$end] = $stem.'iz'.$end;
            }
        }
        foreach (['analys' => 'analyz', 'paralys' => 'paralyz', 'catalys' => 'catalyz'] as $uk => $us) {
            foreach (['e', 'es', 'ed', 'ing', 'er', 'ers'] as $end) {
                $m[$uk.$end] = $us.$end;
            }
        }
        foreach (['colo', 'favo', 'behavi', 'labo', 'neighbo', 'hono', 'humo', 'rumo', 'flavo', 'savo', 'harbo', 'endeavo', 'vapo', 'armo'] as $stem) {
            foreach (['', 's', 'ed', 'ing', 'ful', 'less', 'able', 'ite', 'ist', 'ists', 'ably'] as $end) {
                $m[$stem.'ur'.$end] = $stem.'or'.$end;
            }
        }
        $pairs = [
            'centre' => 'center', 'centres' => 'centers', 'centred' => 'centered', 'centring' => 'centering', 'litre' => 'liter', 'litres' => 'liters', 'theatre' => 'theater', 'theatres' => 'theaters',
            'fibre' => 'fiber', 'fibres' => 'fibers', 'calibre' => 'caliber', 'kilometre' => 'kilometer', 'kilometres' => 'kilometers', 'centimetre' => 'centimeter', 'centimetres' => 'centimeters',
            'millimetre' => 'millimeter', 'millimetres' => 'millimeters',
            'catalogue' => 'catalog', 'catalogues' => 'catalogs', 'catalogued' => 'cataloged', 'cataloguing' => 'cataloging', 'dialogue' => 'dialog', 'dialogues' => 'dialogs', 'analogue' => 'analog',
            'defence' => 'defense', 'offence' => 'offense', 'pretence' => 'pretense',
            'travelled' => 'traveled', 'travelling' => 'traveling', 'traveller' => 'traveler', 'travellers' => 'travelers', 'cancelled' => 'canceled', 'cancelling' => 'canceling', 'labelled' => 'labeled',
            'labelling' => 'labeling', 'modelled' => 'modeled', 'modelling' => 'modeling', 'modeller' => 'modeler', 'fuelled' => 'fueled', 'fuelling' => 'fueling', 'signalled' => 'signaled',
            'signalling' => 'signaling', 'levelled' => 'leveled', 'levelling' => 'leveling', 'counselling' => 'counseling', 'counsellor' => 'counselor', 'counsellors' => 'counselors',
            'totalled' => 'totaled', 'totalling' => 'totaling', 'equalled' => 'equaled', 'equalling' => 'equaling', 'marvellous' => 'marvelous', 'jewellery' => 'jewelry',
            'enrolment' => 'enrollment', 'enrolments' => 'enrollments', 'instalment' => 'installment', 'instalments' => 'installments', 'fulfilment' => 'fulfillment', 'skilful' => 'skillful',
            'fulfil' => 'fulfill', 'fulfils' => 'fulfills', 'enrol' => 'enroll', 'enrols' => 'enrolls',
            'grey' => 'gray', 'greys' => 'grays', 'greyed' => 'grayed', 'judgement' => 'judgment', 'judgements' => 'judgments', 'acknowledgement' => 'acknowledgment', 'ageing' => 'aging',
            'aluminium' => 'aluminum', 'artefact' => 'artifact', 'artefacts' => 'artifacts', 'plough' => 'plow', 'ploughs' => 'plows', 'mould' => 'mold', 'moulds' => 'molds', 'moulded' => 'molded',
            'sceptic' => 'skeptic', 'sceptical' => 'skeptical', 'sceptics' => 'skeptics', 'enquiry' => 'inquiry', 'enquiries' => 'inquiries', 'enquire' => 'inquire', 'enquired' => 'inquired',
            'programme' => 'program', 'programmes' => 'programs', 'kerb' => 'curb', 'speciality' => 'specialty', 'specialities' => 'specialties',
            'sulphur' => 'sulfur', 'paediatric' => 'pediatric', 'anaemia' => 'anemia', 'anaemic' => 'anemic', 'oestrogen' => 'estrogen', 'manoeuvre' => 'maneuver', 'manoeuvres' => 'maneuvers',
            'manoeuvring' => 'maneuvering',
        ];

        return self::$uk = $m + $pairs;
    }

    /**
     * @param  list<array{0: array<string, mixed>, 1: array<string, mixed>}>  $corpus
     */
    private function dialects(array $corpus): array
    {
        $uk = self::ukToUs();
        $us = array_flip($uk);
        $found = [];
        $totals = ['uk' => 0, 'us' => 0];
        foreach ($corpus as [$s, $e]) {
            preg_match_all('/\p{L}[\p{L}\'’-]*/u', mb_strtolower($e['text']), $m);
            foreach ($m[0] as $w) {
                if (isset($uk[$w])) {
                    $found[$uk[$w]]['uk'][$w] = ($found[$uk[$w]]['uk'][$w] ?? 0) + 1;
                    $found[$uk[$w]]['slides'][$s['id']] = true;
                    $totals['uk']++;
                } elseif (isset($us[$w])) {
                    $found[$w]['us'][$w] = ($found[$w]['us'][$w] ?? 0) + 1;
                    $found[$w]['slides'][$s['id']] = true;
                    $totals['us']++;
                }
            }
        }
        if ($totals['uk'] === 0 || $totals['us'] === 0) {
            return [];
        }
        $dominant = $totals['uk'] >= $totals['us'] ? 'uk' : 'us';
        $minority = $dominant === 'uk' ? 'us' : 'uk';

        $out = [];
        foreach ($found as $usForm => $info) {
            if (empty($info[$minority])) {
                continue;
            }
            $map = [];
            foreach (array_keys($info[$minority]) as $form) {
                $map[$form] = $dominant === 'uk' ? array_search($form, $uk, true) : $uk[$form];
            }
            $map = array_filter($map);
            $fix = $this->replaceFix($corpus, $map, 'Change to '.($dominant === 'uk' ? 'UK' : 'US').' spelling');
            $out[] = $this->issue('spelling-variants', 'terminology', 'warn', null, null,
                'UK and US spelling are mixed: "'.implode('", "', array_keys($info[$minority])).'" is '.($minority === 'uk' ? 'UK' : 'US').' spelling, while most of the deck ('.$totals[$dominant].' words) is '.($dominant === 'uk' ? 'UK' : 'US').'.',
                'Mixed spelling looks careless to readers who notice. Pick one variety for the whole deck.',
                $fix, ['slides' => array_keys($info['slides']), 'terms' => array_keys($info[$minority])]);
        }

        return $out;
    }

    /**
     * @param  list<array{0: array<string, mixed>, 1: array<string, mixed>}>  $corpus
     */
    private function forms(array $corpus): array
    {
        $out = [];
        foreach (self::FORM_GROUPS as $group) {
            $counts = [];
            $slides = [];
            foreach ($corpus as [$s, $e]) {
                $lower = mb_strtolower($e['text']);
                foreach ($group as $form) {
                    $n = preg_match_all('/(?<![\p{L}-])'.preg_quote($form, '/').'(?![\p{L}-])/u', $lower);
                    if ($n) {
                        $counts[$form] = ($counts[$form] ?? 0) + $n;
                        $slides[$s['id']] = true;
                    }
                }
            }
            if (count($counts) < 2) {
                continue;
            }
            $winner = $group[0];
            $best = -1;
            foreach ($group as $form) {
                if (($counts[$form] ?? 0) > $best) {
                    $best = $counts[$form] ?? 0;
                    $winner = $form;
                }
            }
            $map = [];
            foreach ($counts as $form => $n) {
                if ($form !== $winner) {
                    $map[$form] = $winner;
                }
            }
            $desc = implode(' and ', array_map(fn ($f, $n) => "\"{$f}\" ({$n})", array_keys($counts), $counts));
            $out[] = $this->issue('spelling-variants', 'terminology', 'warn', null, null,
                "The same word is written in different ways: {$desc}.",
                'Use one form throughout so the deck reads as one voice and searches find every mention.',
                $this->replaceFix($corpus, $map, 'Use "'.$winner.'" everywhere'), ['slides' => array_keys($slides)]);
        }

        return $out;
    }

    /**
     * Replace words in the text docs. $map is lowercase form => replacement; case is carried over.
     *
     * @param  list<array{0: array<string, mixed>, 1: array<string, mixed>}>  $corpus
     * @param  array<string, string>  $map
     * @return array{label: string, ops: list<array<string, mixed>>}
     */
    private function replaceFix(array $corpus, array $map, string $label): array
    {
        $forms = array_keys($map);
        usort($forms, fn ($a, $b) => strlen($b) <=> strlen($a));
        $re = '/(?<![\p{L}-])('.implode('|', array_map(fn ($f) => preg_quote($f, '/'), $forms)).')(?![\p{L}-])/iu';
        $ops = [];
        foreach ($corpus as [$s, $e]) {
            if ($e['lock'] || ! isset($e['raw']['doc']) || ! preg_match($re, $e['text'])) {
                continue;
            }
            $doc = DocText::mapText($e['raw']['doc'], fn ($t) => (string) preg_replace_callback($re, function ($m) use ($map) {
                $to = $map[mb_strtolower($m[1])] ?? $m[1];
                if (Text::isAllCaps($m[1]) && mb_strlen($m[1]) > 1) {
                    return mb_strtoupper($to);
                }
                if (preg_match('/^\p{Lu}/u', $m[1])) {
                    return Text::upperFirst($to);
                }

                return $to;
            }, $t));
            if ($doc !== $e['raw']['doc']) {
                $ops[] = Ops::elSet($s['id'], $e['id'], ['doc' => $doc]);
            }
        }

        return Ops::fix($label, $ops);
    }

    // ------------------------------------------------------------------ capitalisation

    /**
     * @param  list<array{0: array<string, mixed>, 1: array<string, mixed>}>  $corpus
     */
    private function caseVariants(array $corpus): array
    {
        $forms = [];
        $slides = [];
        foreach ($corpus as [$s, $e]) {
            if (in_array($e['role'], ['title', 'subtitle'], true) || Text::isAllCaps($e['text'])) {
                continue;
            }
            foreach ($e['blocks'] as $b) {
                $text = $b['text'];
                if (! preg_match_all('/(?<![\p{L}\d])\p{L}[\p{L}\d.\-]{1,38}\p{L}(?![\p{L}\d])/u', $text, $m, PREG_OFFSET_CAPTURE)) {
                    continue;
                }
                foreach ($m[0] as [$tok, $pos]) {
                    // Skip sentence-initial words: their capital is grammar, not spelling.
                    $before = rtrim(substr($text, 0, (int) $pos));
                    if ($before === '' || preg_match('/[.!?:]$/u', $before)) {
                        continue;
                    }
                    $forms[mb_strtolower($tok)][$tok] = ($forms[mb_strtolower($tok)][$tok] ?? 0) + 1;
                    $slides[mb_strtolower($tok)][$s['id']] = true;
                }
            }
        }
        $out = [];
        foreach ($forms as $key => $variants) {
            if (count($variants) < 2) {
                continue;
            }
            $kinds = array_map(fn ($f) => $this->caseKind($f), array_keys($variants));
            if (! in_array('other', $kinds, true)) {
                continue;
            }
            arsort($variants);
            $winner = array_key_first($variants);
            $map = [];
            foreach (array_keys($variants) as $f) {
                if ($f !== $winner) {
                    $map[mb_strtolower($f)] = $winner;
                }
            }
            // The replacement keeps the exact winning form, so build a case sensitive fix here.
            $ops = [];
            foreach ($corpus as [$s, $e]) {
                if ($e['lock'] || ! isset($e['raw']['doc'])) {
                    continue;
                }
                $changed = false;
                $doc = DocText::mapText($e['raw']['doc'], function ($t) use ($variants, $winner, &$changed) {
                    foreach (array_keys($variants) as $f) {
                        if ($f === $winner) {
                            continue;
                        }
                        $n = 0;
                        $t = (string) preg_replace('/(?<![\p{L}\d])'.preg_quote($f, '/').'(?![\p{L}\d])/u', $winner, $t, -1, $n);
                        $changed = $changed || $n > 0;
                    }

                    return $t;
                });
                if ($changed) {
                    $ops[] = Ops::elSet($s['id'], $e['id'], ['doc' => $doc]);
                }
            }
            $list = implode(', ', array_map(fn ($f, $n) => "\"{$f}\" ({$n})", array_keys($variants), $variants));
            $out[] = $this->issue('case-variants', 'terminology', 'info', null, null,
                "One term is capitalised in different ways: {$list}.",
                'A product, acronym or name should be written the same way every time.',
                Ops::fix('Use "'.$winner.'" everywhere', $ops), ['slides' => array_keys($slides[$key])]);
        }

        return $out;
    }

    private function caseKind(string $f): string
    {
        if ($f === mb_strtolower($f)) {
            return 'lower';
        }
        if (mb_substr($f, 1) === mb_strtolower(mb_substr($f, 1))) {
            return 'capitalised';
        }

        return 'other';
    }

    // ------------------------------------------------------------------ number formats

    /**
     * @param  list<array{0: array<string, mixed>, 1: array<string, mixed>}>  $corpus
     */
    private function numberFormats(array $corpus): array
    {
        $all = implode("\n", array_map(fn ($c) => $c[1]['text'], $corpus));
        $out = [];

        // Thousands separators.
        $styles = [
            'comma' => preg_match_all('/(?<![\d.,])\d{1,3}(?:,\d{3})+(?![\d,])(?!\.\d{3})/u', $all),
            'space' => preg_match_all('/(?<![\d.,])\d{1,3}(?:[ \x{00A0}]\d{3})+(?![\d])/u', $all),
            'plain' => preg_match_all('/(?<![\d.,])(?!(?:19|20)\d{2}(?![\d]))\d{4,}(?![\d,])/u', $all),
        ];
        $styles = array_filter($styles);
        if (count($styles) >= 2) {
            arsort($styles);
            $dominant = array_key_first($styles);
            $fix = $this->thousandsFix($corpus, $dominant);
            $out[] = $this->issue('number-format', 'terminology', 'warn', null, null,
                'Thousands are written in different ways: '.implode(', ', array_map(fn ($k, $n) => "{$k} separators ({$n})", array_keys($styles), $styles)).'.',
                'Pick one style, for example 1,200,000, so figures are read the same way everywhere.',
                $fix);
        }

        // Decimal separators.
        $dot = preg_match_all('/(?<![\d.,])\d+\.\d{1,2}(?![\d.])/u', $all);
        $comma = preg_match_all('/(?<![\d.,])\d+,\d{1,2}(?![\d,])/u', $all);
        if ($dot > 0 && $comma > 0) {
            $out[] = $this->issue('number-format', 'terminology', 'warn', null, null,
                "Both decimal points ({$dot}) and decimal commas ({$comma}) appear.",
                'Mixing 1.5 and 1,5 makes numbers ambiguous. Use one decimal mark throughout.');
        }

        // Currency.
        $cur = array_filter([
            'symbol attached (R1200)' => preg_match_all('/[R$€£]\d/u', $all),
            'symbol spaced (R 1200)' => preg_match_all('/[R$€£] \d/u', $all),
            'code before (ZAR 1200)' => preg_match_all('/\b(?:ZAR|USD|EUR|GBP) ?\d/u', $all),
            'code after (1200 ZAR)' => preg_match_all('/\d ?(?:ZAR|USD|EUR|GBP)\b/u', $all),
        ]);
        if (count($cur) >= 2) {
            $out[] = $this->issue('currency-format', 'terminology', 'warn', null, null,
                'Money is written in different ways: '.implode(', ', array_map(fn ($k, $n) => "{$k} x{$n}", array_keys($cur), $cur)).'.',
                'Choose one currency style, for example R1.2m or ZAR 1.2 million, and keep it.');
        }
        $mag = array_filter([
            'lower m (5m)' => preg_match_all('/\d ?m\b(?!\w)/u', $all) - preg_match_all('/\d ?m(?:in|ins|inutes?|etres?|eters?)\b/ui', $all),
            'capital M (5M)' => preg_match_all('/\d ?M\b/u', $all),
            'word (5 million)' => preg_match_all('/\d ?million\b/iu', $all),
        ]);
        $mag = array_filter($mag, fn ($n) => $n > 0);
        if (count($mag) >= 2) {
            $out[] = $this->issue('currency-format', 'terminology', 'info', null, null,
                'Millions are written in different ways: '.implode(', ', array_map(fn ($k, $n) => "{$k} x{$n}", array_keys($mag), $mag)).'.',
                'Use one abbreviation for millions throughout.');
        }

        // Percent.
        $pct = array_filter([
            '5%' => preg_match_all('/\d%/u', $all),
            '5 %' => preg_match_all('/\d [%]/u', $all),
            '5 percent' => preg_match_all('/\d ?(?:percent|per cent)\b/iu', $all),
        ]);
        if (count($pct) >= 2) {
            arsort($pct);
            $fix = null;
            if (array_key_first($pct) === '5%') {
                $fix = $this->percentFix($corpus);
            }
            $out[] = $this->issue('number-format', 'terminology', 'info', null, null,
                'Percentages are written in different ways: '.implode(', ', array_map(fn ($k, $n) => "\"{$k}\" x{$n}", array_keys($pct), $pct)).'.',
                'Write percentages one way, usually 5%.', $fix);
        }

        return $out;
    }

    private function thousandsFix(array $corpus, string $dominant): ?array
    {
        $convert = function (string $t) use ($dominant) {
            return (string) preg_replace_callback('/(?<![\d.,])(\d{1,3}(?:[,\x{00A0} ]\d{3})+|\d{4,})(?![\d,])(?!\.\d{3})/u', function ($m) use ($dominant) {
                $raw = $m[1];
                if (preg_match('/^(19|20)\d{2}$/', $raw)) {
                    return $raw;
                }
                $digits = preg_replace('/[^\d]/', '', $raw) ?? $raw;
                if (str_contains($raw, ',') && $dominant !== 'comma') {
                    return $dominant === 'plain' ? $digits : trim(strrev(chunk_split(strrev($digits), 3, ' ')));
                }

                return match ($dominant) {
                    'comma' => number_format((float) $digits, 0, '.', ','),
                    'space' => trim(strrev(chunk_split(strrev($digits), 3, ' '))),
                    default => $digits,
                };
            }, $t);
        };
        $ops = [];
        foreach ($corpus as [$s, $e]) {
            if ($e['lock'] || ! isset($e['raw']['doc'])) {
                continue;
            }
            $doc = DocText::mapText($e['raw']['doc'], $convert);
            if ($doc !== $e['raw']['doc']) {
                $ops[] = Ops::elSet($s['id'], $e['id'], ['doc' => $doc]);
            }
        }

        return $ops === [] ? null : Ops::fix('Write thousands with '.$dominant.' separators', $ops);
    }

    private function percentFix(array $corpus): ?array
    {
        $ops = [];
        foreach ($corpus as [$s, $e]) {
            if ($e['lock'] || ! isset($e['raw']['doc'])) {
                continue;
            }
            $doc = DocText::mapText($e['raw']['doc'], fn ($t) => (string) preg_replace(['/(\d) %/u', '/(\d) ?(?:percent|per cent)\b/iu'], '$1%', $t));
            if ($doc !== $e['raw']['doc']) {
                $ops[] = Ops::elSet($s['id'], $e['id'], ['doc' => $doc]);
            }
        }

        return $ops === [] ? null : Ops::fix('Write every percentage as 5%', $ops);
    }

    // ------------------------------------------------------------------ acronyms

    private function acronyms(DeckContext $ctx): array
    {
        $defined = [];
        $firstUse = [];
        foreach ($ctx->slides as $s) {
            if ($s['hidden']) {
                continue;
            }
            $els = $ctx->textEls($s);
            usort($els, fn ($a, $b) => [round($a['y'] / 40), $a['x']] <=> [round($b['y'] / 40), $b['x']]);
            foreach ($els as $e) {
                if (Text::isAllCaps($e['text']) && Text::wordCount($e['text']) >= 2) {
                    continue;
                }
                $text = $e['text'];
                // Definitions: "Key Performance Indicator (KPI)", "KPI (key performance indicator)", "KPI stands for".
                $defs = [];
                if (preg_match_all('/(?:\p{Lu}[\p{L}\'-]+\s+){1,6}\(([A-Z]{2,7})\)/u', $text, $m)) {
                    array_push($defs, ...$m[1]);
                }
                if (preg_match_all('/\b([A-Z]{2,7})\s+\(\s*[a-zA-Z][^)]{3,70}\)/u', $text, $m)) {
                    array_push($defs, ...$m[1]);
                }
                if (preg_match_all('/\b([A-Z]{2,7})\s+(?:stands for|means|is short for|refers to)\b/u', $text, $m)) {
                    array_push($defs, ...$m[1]);
                }
                foreach (array_unique($defs) as $a) {
                    $defined[$a] ??= $s['index'];
                }
                if (preg_match_all('/(?<![\p{L}\d&-])([A-Z]{2,7})(?![\p{L}\d-])/u', $text, $m)) {
                    foreach (array_unique($m[1]) as $a) {
                        if (in_array($a, self::COMMON_ACRONYMS, true) || isset($firstUse[$a])) {
                            continue;
                        }
                        $firstUse[$a] = [$s, $e, in_array($a, $defs, true)];
                    }
                }
            }
        }
        $out = [];
        foreach ($firstUse as $a => [$s, $e, $definesHere]) {
            if ($definesHere) {
                continue;
            }
            if (! isset($defined[$a])) {
                $out[] = $this->issue('acronym', 'terminology', 'info', $s, $e,
                    "The acronym {$a} is used but never defined.",
                    'Not everyone in the audience will know it. Spell it out on first use, for example "Full name ('.$a.')".');
            } elseif ($defined[$a] > $s['index']) {
                $out[] = $this->issue('acronym', 'terminology', 'warn', $s, $e,
                    "The acronym {$a} is used on slide {$s['index']} but only defined on slide {$defined[$a]}.",
                    'Define an acronym where it first appears.');
            }
        }

        return $out;
    }
}
