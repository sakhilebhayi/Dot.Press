<?php

namespace App\Services\Press\Intel\Rules;

use App\Services\Press\Intel\DeckContext;
use App\Services\Press\Intel\DocText;
use App\Services\Press\Intel\Ids;
use App\Services\Press\Intel\Ops;
use App\Services\Press\Intel\Text;

/** Words and bullets per slide, long paragraphs, and a split proposal for overloaded slides. */
final class DensityRules extends Rule
{
    public const WORDS_WARN = 80;

    public const WORDS_ISSUE = 130;

    public const BULLETS_WARN = 7;

    public const BULLETS_ISSUE = 10;

    public const PARAGRAPH_WORDS = 60;

    public function run(DeckContext $ctx): array
    {
        $out = [];
        foreach ($ctx->slides as $s) {
            $words = 0;
            $bullets = 0;
            $biggest = null;
            foreach ($ctx->textEls($s) as $e) {
                if (in_array($e['role'], ['footer', 'number', 'caption'], true)) {
                    continue;
                }
                $words += Text::wordCount($e['text']);
                $items = count(array_filter($e['blocks'], fn ($b) => $b['list'] !== null));
                $bullets += $items;
                if ($items >= 4 && ($biggest === null || $items > $biggest['items'])) {
                    $biggest = ['el' => $e, 'items' => $items];
                }
                foreach ($e['blocks'] as $b) {
                    $n = Text::wordCount($b['text']);
                    if ($b['list'] === null && $n > self::PARAGRAPH_WORDS && $e['role'] !== 'title') {
                        $out[] = $this->issue('long-paragraph', 'density', 'warn', $s, $e,
                            ucfirst($this->label($e)).' has a paragraph of '.$n.' words.',
                            'Slides are not documents. A paragraph this long is read instead of listened to; turn it into short points or a visual.');
                        break;
                    }
                }
            }

            $sev = null;
            if ($words > self::WORDS_ISSUE || $bullets > self::BULLETS_ISSUE) {
                $sev = 'issue';
            } elseif ($words > self::WORDS_WARN || $bullets > self::BULLETS_WARN) {
                $sev = 'warn';
            }
            if ($sev === null) {
                continue;
            }
            $fix = null;
            if ($biggest !== null && $bullets > self::BULLETS_WARN) {
                $fix = $this->splitFix($ctx, $s, $biggest['el']);
            }
            $out[] = $this->issue('density', 'density', $sev, $s, null,
                "This slide carries {$words} words and {$bullets} bullets.",
                'More than about 80 words or 7 bullets on one slide splits the audience between reading and listening. Consider two slides.',
                $fix, ['words' => $words, 'bullets' => $bullets]);
        }

        return $out;
    }

    /** Move the second half of the longest list to a new slide that repeats the title. */
    private function splitFix(DeckContext $ctx, array $s, array $list): ?array
    {
        if ($list['lock']) {
            return null;
        }
        $blocks = $list['blocks'];
        $total = count($blocks);
        $half = (int) ceil($total / 2);
        $first = array_slice($blocks, 0, $half);
        $second = array_slice($blocks, $half);
        if ($first === [] || $second === []) {
            return null;
        }

        $els = [];
        $title = $s['title_el'];
        $titleText = $ctx->titleText($s);
        if ($title !== null) {
            $t = $title['raw'];
            $t['id'] = Ids::make('sp', $s['id'], $title['id']);
            unset($t['anim']);
            $els[] = $t;
        }
        $body = $list['raw'];
        $body['id'] = Ids::make('sp', $s['id'], $list['id']);
        $body['doc'] = DocText::build($second);
        unset($body['anim']);
        $els[] = $body;

        $newSlide = [
            'title' => $titleText !== '' ? $titleText.' (continued)' : '',
            'layout' => $s['layout'],
            'section_id' => $s['section_id'],
            'bg' => $s['bg'],
            'notes' => '',
            'els' => $els,
        ];

        return Ops::fix('Split into two slides ('.count($first).' and '.count($second).' items)', [
            Ops::elSet($s['id'], $list['id'], ['doc' => DocText::build($first)]),
            Ops::slideAdd(Ids::make('t', $s['id'], 'split'), $s['id'], $newSlide),
        ]);
    }
}
