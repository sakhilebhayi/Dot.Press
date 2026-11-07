<?php

namespace App\Services\Press\Intel\Rules;

use App\Services\Press\Intel\DeckContext;
use App\Services\Press\Intel\Ops;

/** Alt text, slide titles, reading order, motion heavy slides. Tiny text lives in the readability rules. */
final class AccessibilityRules extends Rule
{
    public function run(DeckContext $ctx): array
    {
        $out = [];
        foreach ($ctx->slides as $s) {
            if ($s['hidden']) {
                continue;
            }
            array_push($out, ...$this->alt($ctx, $s));
            array_push($out, ...$this->slideTitle($ctx, $s));
            array_push($out, ...$this->order($ctx, $s));
            array_push($out, ...$this->motion($s));
        }

        return $out;
    }

    private function alt(DeckContext $ctx, array $s): array
    {
        $out = [];
        foreach ($s['els'] as $e) {
            if ($e['hide'] || ! in_array($e['type'], ['image', 'chart', 'video'], true)) {
                continue;
            }
            // An empty string means "decorative" and is a decision, not an omission.
            if ($e['alt'] === null) {
                $fix = null;
                if ($e['type'] === 'image' && $e['cap'] !== null && trim($e['cap']) !== '' && ! $e['lock']) {
                    $fix = Ops::fix('Use the caption as the alt text', [Ops::elSet($s['id'], $e['id'], ['alt' => trim($e['cap'])])]);
                }
                if ($e['type'] === 'chart' && ! $e['lock']) {
                    $desc = $this->describeChart($e['chart']);
                    if ($desc !== '') {
                        $fix = Ops::fix('Describe the chart from its data', [Ops::elSet($s['id'], $e['id'], ['alt' => $desc])]);
                    }
                }
                $out[] = $this->issue('alt-text', 'accessibility', 'warn', $s, $e,
                    'The '.$e['type'].($e['name'] !== '' ? ' "'.$e['name'].'"' : '').' has no alternative text.',
                    'People using a screen reader cannot see it. Describe what it shows, or set an empty alt text if it is purely decorative.',
                    $fix);
            } elseif (preg_match('/\.(?:png|jpe?g|gif|webp|svg|heic)$|^img[_-]?\d+|^dsc[_-]?\d+|^screenshot/i', trim($e['alt']))) {
                $out[] = $this->issue('alt-text', 'accessibility', 'warn', $s, $e,
                    'The alt text "'.mb_substr($e['alt'], 0, 50).'" looks like a file name.',
                    'A file name tells a screen reader user nothing. Describe what the picture shows.');
            }
        }

        return $out;
    }

    private function describeChart(?array $chart): string
    {
        if ($chart === null || ! is_array($chart['data'] ?? null)) {
            return '';
        }
        $d = $chart['data'];
        $cats = array_values($d['categories'] ?? []);
        $series = array_values($d['series'] ?? []);
        if ($cats === [] || $series === []) {
            return '';
        }
        $type = (string) ($chart['type'] ?? 'chart');
        $parts = [];
        foreach (array_slice($series, 0, 3) as $ser) {
            $vals = array_values($ser['values'] ?? []);
            $pairs = [];
            foreach (array_slice($cats, 0, 8) as $i => $c) {
                if (isset($vals[$i]) && $vals[$i] !== null) {
                    $pairs[] = $c.' '.$vals[$i];
                }
            }
            if ($pairs !== []) {
                $parts[] = (($ser['name'] ?? '') !== '' ? $ser['name'].': ' : '').implode(', ', $pairs);
            }
        }
        $title = (string) ($chart['opt']['title'] ?? '');

        return mb_substr(ucfirst($type).' chart'.($title !== '' ? ' "'.$title.'"' : '').'. '.implode('; ', $parts).'.', 0, 500);
    }

    private function slideTitle(DeckContext $ctx, array $s): array
    {
        if ($s['title'] !== '') {
            return [];
        }
        $fix = null;
        if ($s['title_el'] !== null) {
            $fix = Ops::fix('Use the title text as the slide title', [Ops::slideSet($s['id'], ['title' => mb_substr(preg_replace('/\s+/u', ' ', $s['title_el']['text']) ?? '', 0, 200)])]);
        }

        return [$this->issue('slide-title', 'accessibility', 'warn', $s, null,
            'This slide has no title in its slide properties.',
            'Slide titles name the slide in the outline, in navigation and for screen readers. Every slide should have a unique one.',
            $fix)];
    }

    private function order(DeckContext $ctx, array $s): array
    {
        $texts = $ctx->textEls($s);
        if (count($texts) < 3) {
            return [];
        }
        // Visual reading order: top to bottom in bands, then left to right.
        $band = $ctx->h * 0.06;
        $visual = $texts;
        usort($visual, fn ($a, $b) => [round($a['y'] / $band), $a['x']] <=> [round($b['y'] / $band), $b['x']]);
        $visualIds = array_column($visual, 'id');
        $zIds = array_column($texts, 'id');
        if ($visualIds === $zIds) {
            return [];
        }
        $pos = array_flip($visualIds);
        $inversions = 0;
        $pairs = 0;
        for ($i = 0; $i < count($zIds); $i++) {
            for ($j = $i + 1; $j < count($zIds); $j++) {
                $pairs++;
                if ($pos[$zIds[$i]] > $pos[$zIds[$j]]) {
                    $inversions++;
                }
            }
        }
        $titleFirst = $s['title_el'] === null || $zIds[0] === $s['title_el']['id'];
        if ($titleFirst && $inversions / $pairs <= 0.34) {
            return [];
        }

        $fix = null;
        $overlap = false;
        foreach ($texts as $a) {
            foreach ($texts as $b) {
                if ($a['id'] < $b['id'] && $a['x'] < $b['x'] + $b['w'] && $b['x'] < $a['x'] + $a['w'] && $a['y'] < $b['y'] + $b['h'] && $b['y'] < $a['y'] + $a['h']) {
                    $overlap = true;
                }
            }
        }
        if (! $overlap && ! in_array(true, array_column($texts, 'lock'), true)) {
            // Reuse the layer slots text elements occupy so other elements keep their stacking.
            $all = array_column($s['els'], 'id');
            $slots = [];
            foreach ($all as $k => $id) {
                if (in_array($id, $zIds, true)) {
                    $slots[] = $k;
                }
            }
            foreach ($slots as $k => $slot) {
                $all[$slot] = $visualIds[$k];
            }
            $fix = Ops::fix('Reorder layers to follow the visual reading order', [Ops::elOrder($s['id'], $all)]);
        }

        return [$this->issue('reading-order', 'accessibility', 'info', $s, null,
            $titleFirst ? 'The layer order of the text differs from its visual order.' : 'The title is not the first text in the layer order.',
            'Screen readers read elements in layer order, bottom first. If it differs from the visual order the slide is read out of sequence.',
            $fix)];
    }

    private function motion(array $s): array
    {
        $steps = 0;
        $strong = 0;
        $ms = 0;
        foreach ($s['els'] as $e) {
            foreach ($e['anim'] as $a) {
                if (! is_array($a)) {
                    continue;
                }
                $steps++;
                $ms += (int) ($a['dur'] ?? 0) + (int) ($a['delay'] ?? 0);
                if (in_array($a['fx'] ?? '', ['spin', 'pulse', 'zoom', 'drop'], true)) {
                    $strong++;
                }
            }
        }
        $moving = in_array($s['transition']['type'] ?? '', ['zoom', 'morph'], true);
        if ($steps >= 8 || $strong >= 3 || $ms > 8000) {
            return [$this->issue('motion-heavy', 'accessibility', 'info', $s, null,
                "This slide has {$steps} animation steps ({$strong} spinning, pulsing, zooming or dropping".($moving ? ', plus a '.$s['transition']['type'].' transition' : '').').',
                'A lot of movement is tiring and can trigger discomfort for some viewers. The player respects the reduced motion setting, but keep the default calm.')];
        }

        return [];
    }
}
