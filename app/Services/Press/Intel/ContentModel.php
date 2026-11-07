<?php

namespace App\Services\Press\Intel;

/**
 * The layout agnostic slide content model that outlines, suggestions and the (later) SlideComposer
 * all share. It carries what a slide says and none of how it looks:
 *
 *   { title, claim, kind, slots: { title, subtitle, bullets[], quote, attribution, left[], right[],
 *     steps[], kpis[], chart, imageHint, leftTitle, rightTitle }, notes, needs[], origin, ... }
 *
 * `kind` is a layout id from the document spec. `chart` is null or {type, data, opt, src} in the
 * spec chart shape. `needs` lists slots that are deliberately empty because the source has nothing
 * for them: [{slot, label: "Needs your evidence", question}].
 */
final class ContentModel
{
    public const KINDS = ['title', 'section', 'bullets', 'two', 'image-left', 'image-right', 'image-full', 'compare', 'timeline', 'quote', 'data', 'kpi', 'closing', 'blank'];

    public const NEEDS_LABEL = 'Needs your evidence';

    /** @return array<string, mixed> */
    public static function emptySlots(): array
    {
        return [
            'title' => '', 'subtitle' => '', 'bullets' => [], 'quote' => '', 'attribution' => '',
            'left' => [], 'right' => [], 'steps' => [], 'kpis' => [], 'chart' => null, 'imageHint' => '',
            'leftTitle' => '', 'rightTitle' => '',
        ];
    }

    /**
     * Normalise a partial proposal: known keys only, bounded sizes, valid kind, plain strings.
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    public static function make(array $p): array
    {
        $slots = self::emptySlots();
        $in = is_array($p['slots'] ?? null) ? $p['slots'] : [];

        foreach (['title', 'subtitle', 'quote', 'attribution', 'imageHint', 'leftTitle', 'rightTitle'] as $k) {
            $slots[$k] = self::str($in[$k] ?? '', 300);
        }
        foreach (['bullets' => 12, 'left' => 8, 'right' => 8, 'steps' => 10] as $k => $max) {
            $slots[$k] = self::strList($in[$k] ?? [], $max, 400);
        }
        $kpis = [];
        foreach (is_array($in['kpis'] ?? null) ? $in['kpis'] : [] as $k) {
            if (! is_array($k) || self::str($k['value'] ?? '', 40) === '') {
                continue;
            }
            $row = ['label' => self::str($k['label'] ?? '', 80), 'value' => self::str($k['value'], 40)];
            foreach (['delta', 'unit', 'note'] as $opt) {
                if (self::str($k[$opt] ?? '', 120) !== '') {
                    $row[$opt] = self::str($k[$opt], 120);
                }
            }
            $kpis[] = $row;
            if (count($kpis) >= 6) {
                break;
            }
        }
        $slots['kpis'] = $kpis;
        $slots['chart'] = self::chart($in['chart'] ?? null);

        $kind = (string) ($p['kind'] ?? 'bullets');
        $title = self::str($p['title'] ?? ($slots['title'] ?: ''), 200);
        $slots['title'] = $slots['title'] !== '' ? $slots['title'] : $title;

        $needs = [];
        foreach (is_array($p['needs'] ?? null) ? $p['needs'] : [] as $n) {
            if (is_array($n) && isset($n['slot'])) {
                $needs[] = ['slot' => self::str($n['slot'], 30), 'label' => self::str($n['label'] ?? self::NEEDS_LABEL, 60), 'question' => self::str($n['question'] ?? '', 300)];
            }
        }

        return [
            'title' => $title,
            'claim' => self::str($p['claim'] ?? '', 400),
            'kind' => in_array($kind, self::KINDS, true) ? $kind : 'bullets',
            'slots' => $slots,
            'notes' => self::multi($p['notes'] ?? '', 4000),
            'needs' => $needs,
            'needs_evidence' => $needs !== [],
            'origin' => ($p['origin'] ?? 'local') === 'ai' ? 'ai' : 'local',
            'unsupported' => (bool) ($p['unsupported'] ?? false),
            'unsupported_numbers' => array_values(array_map('strval', is_array($p['unsupported_numbers'] ?? null) ? $p['unsupported_numbers'] : [])),
        ];
    }

    /** All text a proposal would show or say, for number auditing. */
    public static function allText(array $p): string
    {
        $parts = [$p['title'] ?? '', $p['claim'] ?? '', $p['notes'] ?? ''];
        $s = $p['slots'] ?? [];
        foreach (['title', 'subtitle', 'quote', 'attribution', 'leftTitle', 'rightTitle'] as $k) {
            $parts[] = $s[$k] ?? '';
        }
        foreach (['bullets', 'left', 'right', 'steps'] as $k) {
            foreach ($s[$k] ?? [] as $v) {
                $parts[] = $v;
            }
        }
        foreach ($s['kpis'] ?? [] as $k) {
            $parts[] = ($k['label'] ?? '').' '.($k['value'] ?? '').' '.($k['delta'] ?? '').' '.($k['unit'] ?? '').' '.($k['note'] ?? '');
        }
        if (is_array($s['chart'] ?? null)) {
            $d = $s['chart']['data'] ?? [];
            $parts[] = implode(' ', $d['categories'] ?? []);
            foreach ($d['series'] ?? [] as $series) {
                $parts[] = ($series['name'] ?? '').' '.implode(' ', array_map(fn ($v) => (string) $v, $series['values'] ?? []));
            }
        }

        return implode("\n", array_map('strval', $parts));
    }

    /**
     * Content model of an existing slide of a document (spec section 5 and 6).
     *
     * @param  array<string, mixed>  $slide
     * @return array<string, mixed>
     */
    public static function fromSlide(array $slide): array
    {
        $title = trim((string) ($slide['title'] ?? ''));
        $bullets = [];
        $paragraphs = [];
        $chart = null;
        $subtitle = '';
        $texts = [];

        foreach ($slide['els'] ?? [] as $el) {
            if (! is_array($el) || ! empty($el['hide'])) {
                continue;
            }
            $type = $el['type'] ?? '';
            if ($type === 'chart' && $chart === null && is_array($el['chart'] ?? null)) {
                $chart = self::chart($el['chart']);
            }
            if ($type !== 'text' && $type !== 'shape') {
                continue;
            }
            $role = $el['role'] ?? '';
            $blocks = DocText::blocks($el['doc'] ?? null);
            $joined = trim(implode(' ', array_column($blocks, 'text')));
            if ($joined === '') {
                continue;
            }
            if ($role === 'title') {
                $title = $title !== '' ? $title : $joined;

                continue;
            }
            if ($role === 'subtitle') {
                $subtitle = $joined;

                continue;
            }
            if (in_array($role, ['footer', 'number', 'caption'], true)) {
                continue;
            }
            foreach ($blocks as $b) {
                if (trim($b['text']) === '') {
                    continue;
                }
                if ($b['list'] !== null) {
                    $bullets[] = trim($b['text']);
                } else {
                    $paragraphs[] = trim($b['text']);
                }
            }
            $texts[] = $joined;
        }

        return self::make([
            'title' => $title,
            'claim' => '',
            'kind' => in_array($slide['layout'] ?? '', self::KINDS, true) ? $slide['layout'] : 'bullets',
            'slots' => [
                'title' => $title,
                'subtitle' => $subtitle,
                'bullets' => $bullets !== [] ? $bullets : $paragraphs,
                'chart' => $chart,
            ],
            'notes' => (string) ($slide['notes'] ?? ''),
        ]);
    }

    private static function str(mixed $v, int $max): string
    {
        if (! is_scalar($v)) {
            return '';
        }

        return Text::clip(strip_tags(Text::clean((string) $v)), $max);
    }

    private static function multi(mixed $v, int $max): string
    {
        if (! is_scalar($v)) {
            return '';
        }
        $s = trim(strip_tags(Text::clean((string) $v)));

        return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max)) : $s;
    }

    /** @return list<string> */
    private static function strList(mixed $v, int $maxItems, int $maxLen): array
    {
        $out = [];
        foreach (is_array($v) ? $v : [] as $x) {
            $s = self::str($x, $maxLen);
            if ($s !== '') {
                $out[] = $s;
            }
            if (count($out) >= $maxItems) {
                break;
            }
        }

        return $out;
    }

    private static function chart(mixed $c): ?array
    {
        if (! is_array($c) || ! is_array($c['data'] ?? null)) {
            return null;
        }
        $types = ['bar', 'column', 'line', 'area', 'pie', 'doughnut', 'scatter', 'bubble', 'waterfall', 'funnel', 'radar', 'combo', 'kpi', 'map'];
        $type = in_array($c['type'] ?? '', $types, true) ? $c['type'] : 'column';
        $data = $c['data'];
        $cats = array_slice(array_map(fn ($x) => self::str($x, 60), is_array($data['categories'] ?? null) ? array_values($data['categories']) : []), 0, 60);
        $series = [];
        foreach (is_array($data['series'] ?? null) ? $data['series'] : [] as $s) {
            if (! is_array($s) || ! is_array($s['values'] ?? null)) {
                continue;
            }
            $vals = [];
            foreach (array_slice(array_values($s['values']), 0, 60) as $v) {
                $vals[] = is_numeric($v) ? (float) $v + 0 : null;
            }
            $series[] = ['name' => self::str($s['name'] ?? '', 60), 'values' => $vals];
            if (count($series) >= 8) {
                break;
            }
        }
        if ($series === [] || $cats === []) {
            return null;
        }

        return [
            'type' => $type,
            'data' => ['categories' => $cats, 'series' => $series],
            'opt' => is_array($c['opt'] ?? null) ? $c['opt'] : ['legend' => 'bottom', 'labels' => 'value', 'stacked' => false, 'smooth' => false, 'grid' => true],
            'src' => ['kind' => 'manual'],
        ];
    }
}
