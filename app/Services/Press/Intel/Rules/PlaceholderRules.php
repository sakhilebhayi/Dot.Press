<?php

namespace App\Services\Press\Intel\Rules;

use App\Services\Press\Intel\ContentModel;
use App\Services\Press\Intel\DeckContext;
use App\Services\Press\Intel\Ops;

/** Lorem ipsum, TBD, XX, "click to add", the outline's "Needs your evidence", and empty text boxes. */
final class PlaceholderRules extends Rule
{
    private const PATTERNS = [
        'lorem ipsum' => '/\blorem\s+ipsum\b|\bdolor\s+sit\s+amet\b/iu',
        'TBD or TBC' => '/\bTB[DC]\b/u',
        'XX' => '/(?<![\p{L}])X{2,}(?![\p{L}])|\bxx%/iu',
        'click to add' => '/\bclick\s+to\s+(?:add|edit)\b|\bdouble[- ]click\s+to\b/iu',
        'your text here' => '/\b(?:your|add)\s+(?:text|title|content|name|subtitle)\s+here\b|\[(?:insert|add)[^\]]*\]|<insert[^>]*>/iu',
        'placeholder' => '/\bplaceholder\b|\bFIXME\b|\bTODO\b/u',
        'Needs your evidence' => '/needs your evidence/iu',
    ];

    public function run(DeckContext $ctx): array
    {
        $out = [];
        foreach ($ctx->slides as $s) {
            if ($s['hidden']) {
                continue;
            }
            foreach ($s['els'] as $e) {
                if ($e['hide'] || $e['type'] !== 'text') {
                    continue;
                }
                if (! $e['has_text']) {
                    $out[] = $this->issue('placeholder', 'placeholders', 'warn', $s, $e,
                        'This text box is empty.',
                        'Empty boxes show as nothing when presenting but get in the way when editing, and a forgotten one may show a prompt in some viewers.',
                        $e['lock'] ? null : Ops::fix('Delete the empty box', [Ops::elRemove($s['id'], $e['id'])]));

                    continue;
                }
                foreach (self::PATTERNS as $name => $re) {
                    if (preg_match($re, $e['text'], $m)) {
                        $isEvidence = $name === ContentModel::NEEDS_LABEL;
                        $out[] = $this->issue('placeholder', 'placeholders', 'warn', $s, $e,
                            $isEvidence ? ucfirst($this->label($e)).' still says "'.ContentModel::NEEDS_LABEL.'".' : ucfirst($this->label($e)).' contains placeholder text ("'.trim($m[0]).'").',
                            $isEvidence ? 'This slot was left empty on purpose because the source had nothing for it. Add real evidence or remove the slide.' : 'Placeholder text must be replaced before the deck is shown.');
                        break;
                    }
                }
            }
            if (preg_match('/lorem ipsum|\bTB[DC]\b/iu', $s['title'])) {
                $out[] = $this->issue('placeholder', 'placeholders', 'warn', $s, null, 'The slide title is a placeholder ("'.$s['title'].'").', 'Placeholder text must be replaced before the deck is shown.');
            }
        }

        return $out;
    }
}
