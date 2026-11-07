<?php

namespace App\Services\Press\Intel\Rules;

use App\Services\Press\Intel\Claims;
use App\Services\Press\Intel\DeckContext;
use App\Services\Press\Intel\Text;

/** Missing titles, competing title-sized blocks, topic titles that say nothing. */
final class HierarchyRules extends Rule
{
    /** Layouts where several large blocks or a missing title are normal. */
    private const FREE_LAYOUTS = ['title', 'section', 'compare', 'two', 'kpi', 'timeline', 'quote', 'closing', 'blank', 'image-full', 'data'];

    public function run(DeckContext $ctx): array
    {
        $out = [];
        foreach ($ctx->slides as $s) {
            if ($s['hidden']) {
                continue;
            }
            $texts = $ctx->textEls($s);
            $titleText = $ctx->titleText($s);

            if ($s['title_el'] === null && $texts !== [] && ! in_array($s['layout'], ['blank', 'image-full', 'quote'], true)) {
                $out[] = $this->issue('missing-title', 'hierarchy', 'warn', $s, null,
                    'This slide has no element that reads as a title.',
                    'Without a clear title the audience has to work out what the slide is for. A title should be the largest text near the top.');
            }

            if (! in_array($s['layout'], self::FREE_LAYOUTS, true) && count($texts) >= 3) {
                $max = max(array_map(fn ($e) => $e['size'], $texts));
                $big = array_values(array_filter($texts, fn ($e) => $e['size'] >= 0.85 * $max && ! in_array($e['role'], ['kpi', 'number']) && $e['size'] >= $ctx->h * 0.04));
                $rest = array_values(array_filter($texts, fn ($e) => $e['size'] < 0.85 * $max));
                if (count($big) >= 2 && $rest !== []) {
                    $restMax = max(array_map(fn ($e) => $e['size'], $rest));
                    if ($max >= $restMax * 1.3) {
                        $out[] = $this->issue('title-competition', 'hierarchy', 'info', $s, $big[1],
                            count($big).' blocks of text share the largest size ('.round($max).' units).',
                            'When several blocks are title-sized nothing leads. Make one the title and bring the others down a level.');
                    }
                }
            }

            if ($titleText !== '' && ! in_array($s['layout'], ['title', 'section', 'closing'], true)) {
                $body = array_filter($texts, fn ($e) => ! ($s['title_el'] !== null && $e['id'] === $s['title_el']['id']));
                $bodyWords = array_sum(array_map(fn ($e) => Text::wordCount($e['text']), $body));
                if ($bodyWords >= 12 && ! Claims::isStructuralTitle($titleText) && ! Claims::isAssertion($titleText)) {
                    $out[] = $this->issue('topic-title', 'hierarchy', 'info', $s, $s['title_el'],
                        'The title "'.mb_substr($titleText, 0, 60).'" names a topic but does not say what the slide shows.',
                        'A title that states the point (for example "Costs fell 3% below budget") lets the audience get the message from the title alone.');
                }
            }
        }

        return $out;
    }
}
