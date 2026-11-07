<?php

namespace App\Services\Press\Intel\Rules;

use App\Services\Press\Intel\DeckContext;

/** More than 12 animation steps on one slide is a presenter's trap. */
final class AnimationRules extends Rule
{
    public const MAX_STEPS = 12;

    public function run(DeckContext $ctx): array
    {
        $out = [];
        foreach ($ctx->slides as $s) {
            $steps = 0;
            $clicks = 0;
            foreach ($s['els'] as $e) {
                foreach ($e['anim'] as $a) {
                    if (is_array($a)) {
                        $steps++;
                        if (($a['on'] ?? 'click') === 'click') {
                            $clicks++;
                        }
                    }
                }
            }
            if ($steps > self::MAX_STEPS) {
                $out[] = $this->issue('animation-steps', 'animation', 'warn', $s, null,
                    "This slide has {$steps} animation steps ({$clicks} clicks).",
                    'More than twelve steps on one slide is hard to present and easy to get lost in. Group elements, or split the slide.');
            }
        }

        return $out;
    }
}
