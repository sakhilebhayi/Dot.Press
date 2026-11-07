<?php

namespace App\Services\Press\Intel\Rules;

use App\Services\Press\Intel\DeckContext;
use App\Services\Press\Intel\Ops;
use App\Services\Press\Intel\TextMetrics;

/** Estimated text overflow, from a per-character width model (see TextMetrics). */
final class TextFitRules extends Rule
{
    public function run(DeckContext $ctx): array
    {
        $out = [];
        foreach ($ctx->slides as $s) {
            foreach ($ctx->textEls($s) as $e) {
                $fit = self::check($ctx, $e);
                if ($fit === null) {
                    continue;
                }
                $pct = round(($fit['need'] / max($fit['avail'], 1) - 1) * 100);

                if ($e['fit'] === 'shrink') {
                    // The player shrinks the text: fine unless it ends up tiny.
                    if ($fit['shrink_size'] === null || $fit['shrink_size'] < $ctx->h * 0.02) {
                        $out[] = $this->issue('text-fit', 'text-fit', 'warn', $s, $e,
                            ucfirst($this->label($e)).' will shrink to '.($fit['shrink_size'] === null ? 'a tiny size' : 'about '.round($fit['shrink_size']).' units').' to fit its box, which is too small to read on a screen.',
                            'Shrink-to-fit hides a text-length problem. Cut the text or give it a bigger box.');
                    }

                    continue;
                }

                $alts = [];
                $fix = null;
                $need = $fit['need'] + 2 * $e['pad'];
                if ($ctx->h >= $e['y'] + $need && ! $e['lock']) {
                    $fix = Ops::fix('Grow the box to '.round($need).' units tall', [Ops::elSet($s['id'], $e['id'], ['h' => $this->round($need)])]);
                }
                if ($fit['fit_size'] !== null && ! $e['lock']) {
                    $reduce = Ops::fix('Reduce the text size to '.$this->round($fit['fit_size']), [Ops::elSet($s['id'], $e['id'], ['size' => $this->round($fit['fit_size'])])]);
                    if ($fix === null) {
                        $fix = $reduce;
                    } else {
                        $alts[] = $reduce;
                    }
                }
                if (! $e['lock']) {
                    $alts[] = Ops::fix('Let the player shrink the text to fit', [Ops::elSet($s['id'], $e['id'], ['fit' => 'shrink'])]);
                }
                $extra = $alts !== [] ? ['alternatives' => $alts] : [];
                $out[] = $this->issue('text-fit', 'text-fit', $pct > 15 ? 'issue' : 'warn', $s, $e,
                    ucfirst($this->label($e)).' probably overflows its box by about '.$pct.'% (estimated).',
                    'Text beyond the box is clipped or spills over what is below it. The estimate uses average character widths, so check it on the slide.',
                    $fix, $extra);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $e
     * @return array{need: float, avail: float, fit_size: ?float, shrink_size: ?float}|null null when the text fits
     */
    public static function check(DeckContext $ctx, array $e): ?array
    {
        if ($e['w'] <= 0 || $e['h'] <= 0) {
            return null;
        }
        $innerW = max($e['w'] - 2 * $e['pad'], 1.0);
        $innerH = max($e['h'] - 2 * $e['pad'], 1.0);
        $m = TextMetrics::measure($e['blocks'], $innerW, $e['size'], $e['family'], $e['weight'], $e['lh'], $e['ls'], $e['ps']);
        // Boxes that auto-fit their last line get a 2% tolerance for estimation error.
        if ($m['height'] <= $innerH * 1.02) {
            return null;
        }
        $fitSize = TextMetrics::fitSize($e['blocks'], $innerW, $innerH, $e['size'], max(8.0, $e['size'] * 0.4), $e['family'], $e['weight'], $e['lh'], $e['ls'], $e['ps']);

        return ['need' => $m['height'], 'avail' => $innerH, 'fit_size' => $fitSize, 'shrink_size' => $fitSize];
    }
}
