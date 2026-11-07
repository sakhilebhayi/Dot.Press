<?php

namespace App\Services\Press\Intel;

/** Builds assertion style slide titles from a claim sentence, by extraction only. */
final class Titles
{
    /**
     * @return array{title: string, lossless: bool} lossless is false when words were cut from the claim
     */
    public static function fromClaim(string $claim, int $maxWords = 16): array
    {
        $s = Text::collapse($claim);
        $s = (string) preg_replace('/^(?:however|moreover|furthermore|additionally|in addition|also|therefore|thus|so|and|but|finally|first|second|third|next|then|overall|in summary|in conclusion)[,:]?\s+/iu', '', $s);
        $s = (string) preg_replace('/^(?:we|our team)\s+(?:believe|think|found|see|note)\s+(?:that\s+)?/iu', '', $s);
        $s = rtrim($s, ' .;:!');
        $s = Text::upperFirst($s);
        $lossless = true;

        if (Text::wordCount($s) > $maxWords) {
            // Cut at the first natural clause boundary that leaves a full statement.
            foreach (['/\s[\x{2013}\x{2014}-]\s/u', '/;\s/u', '/,\s+(?:which|while|whereas|and|but|so|although|because|as)\s/iu', '/:\s/u', '/,\s/u'] as $re) {
                $parts = preg_split($re, $s, 2);
                if ($parts && count($parts) === 2) {
                    $head = trim($parts[0]);
                    $n = Text::wordCount($head);
                    if ($n >= 5 && $n <= $maxWords) {
                        $s = $head;
                        $lossless = false;
                        break;
                    }
                }
            }
        }
        if (Text::wordCount($s) > $maxWords) {
            $words = preg_split('/\s+/u', $s) ?: [];
            $words = array_slice($words, 0, $maxWords);
            // Never end on a dangling article or conjunction.
            while (count($words) > 5 && in_array(mb_strtolower(rtrim(end($words), ',;:')), ['the', 'a', 'an', 'and', 'or', 'of', 'in', 'on', 'at', 'to', 'for', 'with', 'by', 'from', 'as', 'than', 'that', 'which', 'is', 'was', 'are', 'were', 'our', 'its', 'their'], true)) {
                array_pop($words);
            }
            $s = rtrim(implode(' ', $words), ' ,;:-');
            $lossless = false;
        }

        return ['title' => $s, 'lossless' => $lossless];
    }
}
