<?php

namespace App\Services\Press\Intel;

/**
 * A deliberately plain geometry for content proposals, so that every proposal the intelligence
 * services make arrives with operations that are valid today. It is NOT the SlideComposer: the real
 * layouts are written elsewhere, and a client that has the composer should compose from the
 * proposal's content model instead of using these elements. Everything is in deck units and uses
 * theme tokens, so the elements follow a theme change.
 */
final class PlainLayout
{
    /**
     * @param  array<string, mixed>  $model  a ContentModel proposal
     * @return list<array<string, mixed>>
     */
    public static function elements(array $model, float $W, float $H, string $key, string $origin = 'user'): array
    {
        $slots = $model['slots'] ?? ContentModel::emptySlots();
        $mx = round($W * 0.065);
        $cw = $W - 2 * $mx;
        $y0 = round($H * 0.29);
        $ch = round($H * 0.62);
        $els = [];
        $add = function (array $el) use (&$els, $origin, $key) {
            $el['id'] = Ids::make('pl', $key, (string) count($els), $el['role'] ?? $el['type']);
            if ($origin === 'ai') {
                $el['origin'] = 'ai';
            }
            $els[] = $el;
        };
        $text = fn (array $blocks, float $x, float $y, float $w, float $h, float $size, array $o = []) => $o + [
            'type' => 'text', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'size' => round($size), 'font' => '@body', 'doc' => DocText::build($blocks),
        ];
        $kind = (string) ($model['kind'] ?? 'bullets');
        $title = (string) ($slots['title'] ?: ($model['title'] ?? ''));

        if (in_array($kind, ['title', 'section'], true)) {
            if ($title !== '') {
                $add($text([$title], $mx, round($H * 0.32), $cw, round($H * 0.22), $H * 0.09, ['role' => 'title', 'font' => '@heading', 'weight' => 700, 'valign' => 'bottom']));
            }
            if (($slots['subtitle'] ?? '') !== '') {
                $add($text([$slots['subtitle']], $mx, round($H * 0.58), $cw, round($H * 0.12), $H * 0.04, ['role' => 'subtitle', 'color' => '@muted']));
            }

            return $els;
        }

        if ($title !== '') {
            $add($text([$title], $mx, round($H * 0.07), $cw, round($H * 0.17), $H * 0.056, ['role' => 'title', 'font' => '@heading', 'weight' => 700, 'valign' => 'middle']));
        }
        $bodySize = $H * 0.035;

        $bullets = fn (array $items) => array_map(fn ($t) => ['text' => $t, 'list' => 'bullet'], $items);

        switch ($kind) {
            case 'quote':
                $add($text([$slots['quote'] ?? ''], $mx, $y0, $cw, round($ch * 0.62), $H * 0.05, ['role' => 'body', 'font' => '@heading', 'valign' => 'middle']));
                if (($slots['attribution'] ?? '') !== '') {
                    $add($text([$slots['attribution']], $mx, $y0 + round($ch * 0.66), $cw, round($H * 0.08), $H * 0.032, ['role' => 'caption', 'color' => '@muted']));
                }
                break;
            case 'compare':
                $half = round(($cw - $W * 0.03) / 2);
                foreach ([['left', 'leftTitle', $mx], ['right', 'rightTitle', $mx + $half + round($W * 0.03)]] as [$side, $head, $x]) {
                    $blocks = [];
                    if (($slots[$head] ?? '') !== '') {
                        $blocks[] = ['text' => $slots[$head], 'list' => null];
                    }
                    array_push($blocks, ...$bullets($slots[$side] ?? []));
                    $add($text($blocks ?: [''], $x, $y0, $half, $ch, $bodySize, ['role' => 'body']));
                }
                break;
            case 'two':
                $items = $slots['bullets'] ?? [];
                $mid = (int) ceil(count($items) / 2);
                $half = round(($cw - $W * 0.03) / 2);
                $add($text($bullets(array_slice($items, 0, $mid)) ?: [''], $mx, $y0, $half, $ch, $bodySize, ['role' => 'body']));
                $add($text($bullets(array_slice($items, $mid)) ?: [''], $mx + $half + round($W * 0.03), $y0, $half, $ch, $bodySize, ['role' => 'body']));
                break;
            case 'data':
                $items = $slots['bullets'] ?? [];
                $chartW = $items === [] ? $cw : round($cw * 0.6);
                if (is_array($slots['chart'] ?? null)) {
                    $add(['type' => 'chart', 'x' => $mx, 'y' => $y0, 'w' => $chartW, 'h' => $ch, 'chart' => $slots['chart'], 'alt' => self::chartAlt($slots['chart'])]);
                }
                if ($items !== []) {
                    $add($text($bullets($items), $mx + $chartW + round($W * 0.03), $y0, $cw - $chartW - round($W * 0.03), $ch, $bodySize * 0.9, ['role' => 'body']));
                }
                break;
            case 'kpi':
                $k = array_slice($slots['kpis'] ?? [], 0, 4);
                $n = max(count($k), 1);
                $gap = round($W * 0.02);
                $cardW = round(($cw - $gap * ($n - 1)) / $n);
                foreach ($k as $i => $card) {
                    $x = $mx + $i * ($cardW + $gap);
                    $add(['type' => 'shape', 'kind' => 'round', 'x' => $x, 'y' => $y0, 'w' => $cardW, 'h' => round($ch * 0.5), 'fill' => '@surface', 'radius' => 16]);
                    $add($text([$card['value']], $x, $y0 + round($ch * 0.08), $cardW, round($ch * 0.22), $H * 0.07, ['role' => 'kpi', 'font' => '@heading', 'weight' => 700, 'align' => 'center', 'valign' => 'middle']));
                    $label = $card['label'].(isset($card['delta']) ? ' ('.$card['delta'].')' : '');
                    $add($text([$label], $x, $y0 + round($ch * 0.31), $cardW, round($ch * 0.16), $H * 0.03, ['role' => 'caption', 'color' => '@ink', 'align' => 'center']));
                }
                if (($slots['bullets'] ?? []) !== []) {
                    $add($text($bullets($slots['bullets']), $mx, $y0 + round($ch * 0.56), $cw, round($ch * 0.44), $bodySize * 0.9, ['role' => 'body']));
                }
                break;
            case 'timeline':
                $add($text(array_map(fn ($t) => ['text' => $t, 'list' => 'ordered'], $slots['steps'] ?? []) ?: [''], $mx, $y0, $cw, $ch, $bodySize, ['role' => 'body']));
                break;
            default:
                $items = $slots['bullets'] ?? [];
                if ($items !== [] || ($model['needs'] ?? []) === []) {
                    $add($text($bullets($items) ?: [''], $mx, $y0, $cw, ($model['needs'] ?? []) !== [] ? round($ch * 0.5) : $ch, $bodySize, ['role' => 'body']));
                }
        }

        $needY = ($slots['bullets'] ?? []) !== [] ? $y0 + round($ch * 0.55) : $y0;
        foreach ($model['needs'] ?? [] as $n) {
            $add($text([['text' => $n['label'], 'list' => null], ['text' => (string) $n['question'], 'list' => null]], $mx, $needY, $cw, round($ch * 0.35), $bodySize, ['role' => 'body', 'color' => '@muted']));
        }

        return $els;
    }

    /** @param  array<string, mixed>  $chart */
    private static function chartAlt(array $chart): string
    {
        $d = $chart['data'] ?? [];
        $cats = array_values($d['categories'] ?? []);
        $ser = $d['series'][0] ?? null;
        if (! $ser || $cats === []) {
            return '';
        }
        $pairs = [];
        foreach (array_slice($cats, 0, 8) as $i => $c) {
            if (isset($ser['values'][$i])) {
                $pairs[] = $c.' '.$ser['values'][$i];
            }
        }

        return mb_substr(ucfirst((string) ($chart['type'] ?? 'chart')).' chart. '.implode(', ', $pairs).'.', 0, 400);
    }

    /**
     * Slide fields for a `slide.add` operation.
     *
     * @param  array<string, mixed>  $model
     * @return array<string, mixed>
     */
    public static function slide(array $model, float $W, float $H, string $key, string $origin = 'user'): array
    {
        return [
            'title' => (string) ($model['title'] ?? ''),
            'notes' => (string) ($model['notes'] ?? ''),
            'layout' => in_array($model['kind'] ?? 'bullets', ContentModel::KINDS, true) ? $model['kind'] : 'bullets',
            'els' => self::elements($model, $W, $H, $key, $origin),
        ];
    }
}
