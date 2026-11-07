<?php

namespace App\Services\Press\Intel;

/**
 * Rule based tightening of slide text. It only deletes or swaps words from the documented lists
 * and splits long sentences; it never adds a fact, a number or a claim, and it leaves anything it
 * does not recognise alone. It is honest about its reach: it cannot change tone or rewrite.
 */
final class Simplifier
{
    /** Phrase to shorter phrase ('' means the phrase is simply dropped). Matched case-insensitively on word boundaries. */
    public const PHRASES = [
        'in order to' => 'to',
        'due to the fact that' => 'because',
        'owing to the fact that' => 'because',
        'in light of the fact that' => 'because',
        'at this point in time' => 'now',
        'at the present time' => 'now',
        'in the event that' => 'if',
        'for the purpose of' => 'for',
        'with regard to' => 'on',
        'with respect to' => 'on',
        'a number of' => 'several',
        'a majority of' => 'most',
        'the vast majority of' => 'most',
        'has the ability to' => 'can',
        'have the ability to' => 'can',
        'is able to' => 'can',
        'are able to' => 'can',
        'in the near future' => 'soon',
        'on a daily basis' => 'daily',
        'on a weekly basis' => 'weekly',
        'on a monthly basis' => 'monthly',
        'prior to' => 'before',
        'subsequent to' => 'after',
        'it is important to note that' => '',
        'it should be noted that' => '',
        'it is worth noting that' => '',
        'needless to say' => '',
        'as a matter of fact' => '',
        'at the end of the day' => '',
        'going forward' => '',
        'moving forward' => '',
        'basically' => '',
        'essentially' => '',
        'actually' => '',
        'really' => '',
        'very' => '',
        'quite' => '',
        'utilize' => 'use',
        'utilise' => 'use',
        'utilizing' => 'using',
        'utilising' => 'using',
        'leverage' => 'use',
        'leveraging' => 'using',
        'commence' => 'start',
        'terminate' => 'end',
        'facilitate' => 'help',
        'ascertain' => 'find out',
        'demonstrate' => 'show',
        'numerous' => 'many',
        'approximately' => 'about',
        'additionally' => 'also',
        'in addition' => 'also',
        'furthermore' => 'also',
        'moreover' => 'also',
    ];

    /** Leading connectives that add nothing at the start of a bullet. */
    private const LEADING = '/^(?:also|additionally|furthermore|moreover|in addition|and|so|then)[,:]?\s+/iu';

    public static function simplify(string $text): string
    {
        $out = [];
        foreach (explode("\n", Text::clean($text)) as $line) {
            $out[] = self::line($line);
        }

        return implode("\n", $out);
    }

    public static function line(string $line): string
    {
        $s = Text::collapse($line);
        if ($s === '') {
            return '';
        }
        // Longest phrases first so a longer phrase wins over a shorter overlap.
        $phrases = self::PHRASES;
        uksort($phrases, fn ($a, $b) => strlen($b) <=> strlen($a));
        foreach ($phrases as $from => $to) {
            $s = (string) preg_replace_callback('/(?<![\p{L}])'.preg_quote($from, '/').'(?![\p{L}])/iu', function ($m) use ($to) {
                if ($to === '') {
                    return '';
                }

                return ctype_upper(mb_substr($m[0], 0, 1)) ? Text::upperFirst($to) : $to;
            }, $s);
        }
        $s = (string) preg_replace('/\s+([,.;:!?])/u', '$1', $s);
        $s = (string) preg_replace('/^[,;:\s]+/u', '', $s);
        $s = (string) preg_replace('/,\s*,/u', ',', $s);
        $s = Text::collapse($s);

        $s = (string) preg_replace(self::LEADING, '', $s);
        $s = Text::upperFirst($s);

        return $s;
    }

    /**
     * Split sentences longer than $maxWords at a semicolon or at ", and" / ", but" / ", while"
     * when both halves are long enough to stand alone.
     *
     * @return list<string>
     */
    public static function splitLong(string $sentence, int $maxWords = 24): array
    {
        $sentence = Text::collapse($sentence);
        if (Text::wordCount($sentence) <= $maxWords) {
            return [$sentence];
        }
        foreach (['/;\s+/u', '/,\s+(?:and then|but|while|whereas|so)\s+/iu', '/,\s+and\s+(?=(?:we|they|it|the|this|our|these|those|[\p{L}]+ will)\b)/iu'] as $re) {
            $parts = preg_split($re, $sentence, 2);
            if ($parts && count($parts) === 2 && Text::wordCount($parts[0]) >= 6 && Text::wordCount($parts[1]) >= 6) {
                $a = rtrim($parts[0], ' ,;.').'.';
                $b = Text::upperFirst(rtrim($parts[1], ' ,;.')).'.';

                return [...self::splitLong($a, $maxWords), ...self::splitLong($b, $maxWords)];
            }
        }

        return [$sentence];
    }

    /** Tighten one bullet: swap or drop filler, drop a trailing full stop, keep one clause. */
    public static function bullet(string $text): string
    {
        $s = self::line($text);
        $s = rtrim($s, ' .');

        return $s;
    }

    /**
     * Turn a paragraph into bullets: one per sentence, long ones split, each tightened.
     *
     * @return list<string>
     */
    public static function bulletise(string $paragraph): array
    {
        $out = [];
        foreach (Text::sentences($paragraph) as $sentence) {
            foreach (self::splitLong($sentence) as $part) {
                $b = self::bullet($part);
                if ($b !== '') {
                    $out[] = $b;
                }
            }
        }

        return $out;
    }

    /** What changed, in words the UI can show. */
    public static function changeNotes(string $before, string $after): array
    {
        $notes = [];
        $b = mb_strtolower($before);
        foreach (self::PHRASES as $from => $to) {
            if (preg_match('/(?<![\p{L}])'.preg_quote($from, '/').'(?![\p{L}])/iu', $b)) {
                $notes[] = $to === '' ? "removed \"{$from}\"" : "\"{$from}\" became \"{$to}\"";
            }
        }
        if (Text::wordCount($after) < Text::wordCount($before)) {
            $notes[] = 'shorter by '.(Text::wordCount($before) - Text::wordCount($after)).' words';
        }

        return array_values(array_unique($notes));
    }
}
