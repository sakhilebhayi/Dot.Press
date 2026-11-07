<?php

namespace App\Services\Press\Intel;

/** Headline figures stated in sentences, taken exactly as written. */
final class Kpis
{
    /**
     * Headline figures stated in the sentences, exactly as written.
     *
     * @param  list<string>  $sentences
     * @return list<array{label: string, value: string, delta?: string}>
     */
    public static function from(array $sentences): array
    {
        $out = [];
        $seen = [];
        $unitWords = 'tonnes?|tons?|kg|kilograms?|ounces?|oz|units?|hours?|days?|weeks?|months?|people|employees|customers|users|members|sites?|trucks?|machines?|incidents?|injuries|cases|orders|MW|GWh|kWh|ha|litres?|liters?|barrels?|metres?|meters?|km';
        foreach ($sentences as $s) {
            if (Text::wordCount($s) > 34) {
                continue;
            }
            // "Production rose 12% to 1.2 million tonnes"
            if (preg_match('/(?<label>[\p{L}][\p{L} &\/\-]{2,40}?)\s+(?<dir>rose|grew|increased|climbed|up|fell|dropped|declined|decreased|down)\s+(?:by\s+)?(?<delta>\d+(?:\.\d+)?\s?%)\s+(?:to|reaching|at)\s+(?<val>(?:R|\$|€|£)?\s?\d[\d ,.]*\s?(?:%|million|billion|thousand|m|bn|k)?(?:\s(?:'.$unitWords.'))?)/iu', $s, $m)) {
                $label = self::cleanLabel($m['label']);
                $value = trim($m['val']);
                $value = rtrim($value, ' ,.');
                $neg = in_array(mb_strtolower($m['dir']), ['fell', 'dropped', 'declined', 'decreased', 'down'], true);
                if ($label !== '' && ! isset($seen[$value])) {
                    $seen[$value] = true;
                    $out[] = ['label' => $label, 'value' => $value, 'delta' => ($neg ? '-' : '+').str_replace(' ', '', $m['delta'])];
                }

                continue;
            }
            // Years and bare small counts ("5 steps", "Point 3") are not headline figures.
            $nums = array_values(array_filter(Numbers::find($s), fn ($n) => $n['pct'] || $n['cur'] || $n['mag'] || ($n['value'] > 20 && ! ($n['value'] >= 1900 && $n['value'] <= 2100))));
            if (count($nums) !== 1) {
                continue;
            }
            $n = $nums[0];
            $before = trim(substr($s, 0, $n['pos']));
            $after = substr($s, $n['pos'] + strlen($n['raw']));
            $value = $n['raw'];
            if (preg_match('/^\s?((?:'.$unitWords.')\b)/iu', $after, $um)) {
                $value .= ' '.$um[1];
            }
            if (preg_match('/^(?:we|they|it|you|i|he|she|there|this|that|these|those)\b/iu', $before)) {
                continue;
            }
            $label = self::cleanLabel($before);
            if ($label === '' || isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            $out[] = ['label' => $label, 'value' => $value];
        }

        return array_slice($out, 0, 4);
    }

    private static function cleanLabel(string $before): string
    {
        $before = trim($before, " \t,;:-()");
        $words = preg_split('/\s+/u', $before) ?: [];
        $drop = ['was', 'were', 'is', 'are', 'of', 'at', 'to', 'reached', 'totalled', 'totaled', 'stood', 'came', 'in', 'by', 'from', 'around', 'about', 'approximately', 'up', 'down', 'now', 'at', 'a', 'an', 'the', 'and', 'for', 'with', 'will', 'be', 'has', 'have', 'had', 'rose', 'grew', 'fell', 'increased', 'decreased', 'on', 'over', 'under', 'than', 'only', 'just', 'nearly', 'almost', 'is'];
        while ($words !== [] && in_array(mb_strtolower(end($words)), $drop, true)) {
            array_pop($words);
        }
        while ($words !== [] && in_array(mb_strtolower($words[0]), ['the', 'a', 'an', 'our', 'we', 'and', 'but', 'so', 'however', 'in', 'on', 'during', 'for', 'this', 'that', 'last', 'next'], true)) {
            array_shift($words);
        }
        // A label is a short noun phrase; a long run of words is a clause, not a label.
        if (count($words) > 5) {
            $words = array_slice($words, -4);
        }
        $label = implode(' ', $words);
        if ($label === '' || Text::wordCount($label) < 1 || ! preg_match('/\p{L}{3,}/u', $label)) {
            return '';
        }
        $first = mb_strtolower($words[0]);
        if (in_array($first, Text::STOP, true) && count($words) === 1) {
            return '';
        }

        return Text::upperFirst($label);
    }
}
