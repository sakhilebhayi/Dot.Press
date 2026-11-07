<?php

namespace App\Services\Press\Export\Support;

/**
 * Chart spec helpers shared by the native PPTX charts and the PDF chart renderer. The rules follow the editor's JS
 * chart engine (resources/js/press/charts): the same defaults, the same waterfall steps, the same tick and number formats.
 */
final class ChartData
{
    public const TYPES = ['bar', 'column', 'line', 'area', 'pie', 'doughnut', 'scatter', 'bubble', 'waterfall', 'funnel', 'radar', 'combo', 'kpi', 'map'];

    public const MAX_CATEGORIES = 500;

    public const MAX_SERIES = 40;

    public const MAX_POINTS = 2000;

    /** @return array<string, mixed> a complete, bounded chart */
    public static function normalize(mixed $chart): array
    {
        $src = is_array($chart) ? $chart : [];
        $type = in_array($src['type'] ?? null, self::TYPES, true) ? $src['type'] : 'column';
        $d = is_array($src['data'] ?? null) ? $src['data'] : [];
        $cats = [];
        foreach (array_slice((array) ($d['categories'] ?? []), 0, self::MAX_CATEGORIES) as $c) {
            $cats[] = self::text($c, 80);
        }
        $rawSeries = array_slice(array_values(array_filter((array) ($d['series'] ?? []), 'is_array')), 0, self::MAX_SERIES);
        $longest = 0;
        foreach ($rawSeries as $s) {
            $longest = max($longest, min(count((array) ($s['values'] ?? [])), self::MAX_CATEGORIES));
        }
        for ($i = count($cats); $i < $longest; $i++) {
            $cats[] = (string) ($i + 1);
        }
        $n = count($cats);
        $series = [];
        foreach ($rawSeries as $i => $s) {
            $vals = [];
            $raw = array_values((array) ($s['values'] ?? []));
            for ($k = 0; $k < $n; $k++) {
                $vals[] = self::num($raw[$k] ?? null);
            }
            $o = ['name' => self::text($s['name'] ?? '', 80) ?: 'Series '.($i + 1), 'values' => $vals];
            if (is_string($s['color'] ?? null)) {
                $o['color'] = $s['color'];
            }
            if (in_array($s['kind'] ?? null, ['bar', 'line'], true)) {
                $o['kind'] = $s['kind'];
            }
            if (is_array($s['points'] ?? null)) {
                $pts = [];
                foreach (array_slice($s['points'], 0, self::MAX_POINTS) as $p) {
                    if (! is_array($p)) {
                        continue;
                    }
                    $x = self::num($p['x'] ?? null);
                    $y = self::num($p['y'] ?? null);
                    if ($x === null || $y === null) {
                        continue;
                    }
                    $pt = ['x' => $x, 'y' => $y];
                    $r = self::num($p['r'] ?? null);
                    if ($r !== null && $r >= 0) {
                        $pt['r'] = $r;
                    }
                    $pts[] = $pt;
                }
                $o['points'] = $pts;
            }
            $series[] = $o;
        }
        if ($type === 'scatter' || $type === 'bubble') {
            foreach ($series as &$s) {
                if (empty($s['points'])) {
                    $pts = [];
                    foreach ($s['values'] as $k => $v) {
                        if ($v === null) {
                            continue;
                        }
                        $x = self::num($cats[$k] ?? null);
                        $pts[] = ['x' => $x ?? $k + 1, 'y' => $v];
                    }
                    $s['points'] = $pts;
                }
            }
            unset($s);
        }
        $totals = [];
        foreach ((array) ($d['totals'] ?? []) as $t) {
            if (is_numeric($t) && (int) $t == $t && $t >= 0 && $t < $n && ! in_array((int) $t, $totals, true)) {
                $totals[] = (int) $t;
            }
        }
        sort($totals);
        $kpis = [];
        foreach (array_slice((array) ($d['kpis'] ?? []), 0, 24) as $k) {
            if (! is_array($k)) {
                continue;
            }
            $value = self::num($k['value'] ?? null);
            $item = ['label' => self::text($k['label'] ?? '', 60), 'value' => $value ?? (is_string($k['value'] ?? null) ? self::text($k['value'], 40) : null)];
            if (isset($k['delta']) && $k['delta'] !== '') {
                $dv = self::num($k['delta']);
                $item['delta'] = $dv ?? (is_string($k['delta']) ? self::text($k['delta'], 24) : null);
            }
            if (isset($k['unit']) && is_string($k['unit'])) {
                $item['unit'] = self::text($k['unit'], 12);
            }
            if (isset($k['note']) && is_string($k['note'])) {
                $item['note'] = self::text($k['note'], 80);
            }
            if (($k['good'] ?? null) === 'down') {
                $item['good'] = 'down';
            }
            $kpis[] = $item;
        }
        $regions = [];
        foreach (array_slice((array) ($d['regions'] ?? []), 0, 300) as $r) {
            if (is_array($r) && is_string($r['code'] ?? null) && ($v = self::num($r['value'] ?? null)) !== null) {
                $regions[] = ['code' => strtoupper(trim($r['code'])), 'value' => $v];
            }
        }
        $o = is_array($src['opt'] ?? null) ? $src['opt'] : [];
        $ax = is_array($o['ax'] ?? null) ? $o['ax'] : [];
        $axis = function (mixed $a, bool $range): array {
            $x = is_array($a) ? $a : [];
            $r = ['show' => ($x['show'] ?? true) !== false];
            $t = self::text($x['title'] ?? '', 80);
            if ($t !== '') {
                $r['title'] = $t;
            }
            if ($range) {
                foreach (['min', 'max'] as $k) {
                    $v = self::num($x[$k] ?? null);
                    if ($v !== null) {
                        $r[$k] = $v;
                    }
                }
            }

            return $r;
        };
        $multi = count($series) > 1;
        $pieLike = $type === 'pie' || $type === 'doughnut';
        $opt = [
            'legend' => in_array($o['legend'] ?? null, ['none', 'bottom', 'right', 'top'], true) ? $o['legend'] : ($pieLike ? 'right' : ($type === 'map' ? 'bottom' : ($multi || $type === 'waterfall' ? 'bottom' : 'none'))),
            'labels' => in_array($o['labels'] ?? null, ['none', 'value', 'percent'], true) ? $o['labels'] : 'none',
            'stacked' => ($o['stacked'] ?? false) === true,
            'smooth' => ($o['smooth'] ?? false) === true,
            'grid' => ($o['grid'] ?? true) !== false,
            'fmt' => in_array($o['fmt'] ?? null, ['num', 'pct', 'cur', 'compact'], true) ? $o['fmt'] : 'num',
            'cur' => is_string($o['cur'] ?? null) && trim($o['cur']) !== '' ? self::text($o['cur'], 4) : 'R',
            'ax' => ['x' => $axis($ax['x'] ?? null, false), 'y' => $axis($ax['y'] ?? null, true)],
        ];
        foreach (['title' => 140, 'sub' => 220, 'center' => 40] as $k => $max) {
            $t = self::text($o[$k] ?? '', $max);
            if ($t !== '') {
                $opt[$k] = $t;
            }
        }
        if (isset($o['dec']) && is_int($o['dec']) && $o['dec'] >= 0 && $o['dec'] <= 3) {
            $opt['dec'] = $o['dec'];
        }
        if (is_array($o['colors'] ?? null)) {
            $opt['colors'] = array_slice(array_values(array_filter($o['colors'], fn ($c) => is_string($c) && strlen($c) <= 24)), 0, 16);
        }
        $data = ['categories' => $cats, 'series' => $series];
        if ($totals) {
            $data['totals'] = $totals;
        }
        if ($kpis) {
            $data['kpis'] = $kpis;
        }
        if ($regions) {
            $data['regions'] = $regions;
        }

        return ['type' => $type, 'data' => $data, 'opt' => $opt, 'src' => is_array($src['src'] ?? null) ? $src['src'] : ['kind' => 'manual']];
    }

    public static function text(mixed $v, int $max = 200): string
    {
        if ($v === null) {
            return '';
        }
        $s = is_string($v) ? $v : (is_scalar($v) ? (string) $v : '');
        $s = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s) ?? '');
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1).'…' : $s;
    }

    public static function num(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return is_finite((float) $v) ? (float) $v : null;
        }
        if (is_string($v)) {
            $s = str_replace(["\u{2212}", ' ', "\u{00A0}", ','], ['-', '', '', ''], trim($v));
            $s = preg_replace('/^[+-]?\s*(?:ZAR|USD|EUR|GBP|R|\$)/i', '', $s) ?? $s;
            $s = rtrim($s, '%');
            if ($s !== '' && is_numeric($s) && strlen($s) <= 40) {
                return (float) $s;
            }
        }

        return null;
    }

    /** Waterfall steps: running totals with stated or computed totals. @return list<array<string, mixed>> */
    public static function waterfallSteps(array $chart): array
    {
        $cats = $chart['data']['categories'];
        $vals = $chart['data']['series'][0]['values'] ?? [];
        $totals = $chart['data']['totals'] ?? [];
        $steps = [];
        $run = 0.0;
        foreach ($cats as $i => $_) {
            $v = $vals[$i] ?? null;
            if (in_array($i, $totals, true)) {
                $end = $v ?? $run;
                $steps[] = ['i' => $i, 'total' => true, 'from' => 0.0, 'to' => $end, 'delta' => $end - $run, 'value' => $end];
                $run = $end;
            } elseif ($v === null) {
                $steps[] = ['i' => $i, 'skip' => true, 'from' => $run, 'to' => $run, 'delta' => 0.0, 'value' => null];
            } else {
                $steps[] = ['i' => $i, 'from' => $run, 'to' => $run + $v, 'delta' => $v, 'value' => $v];
                $run += $v;
            }
        }

        return $steps;
    }

    /** Role of each series in a cartesian chart: bar, line or area. @return list<string> */
    public static function roles(array $chart): array
    {
        $out = [];
        foreach ($chart['data']['series'] as $s) {
            $out[] = match ($chart['type']) {
                'line' => 'line',
                'area' => 'area',
                'combo' => ($s['kind'] ?? 'bar') === 'line' ? 'line' : 'bar',
                default => 'bar',
            };
        }

        return $out;
    }

    /** Hex colour of every series (custom colour, then opt.colors, then the theme palette). @return list<string> */
    public static function seriesColors(array $chart, Theme $theme): array
    {
        $out = [];
        $palette = $theme->chart();
        foreach ($chart['data']['series'] as $i => $s) {
            $custom = $s['color'] ?? ($chart['opt']['colors'][$i] ?? null);
            $hex = $custom !== null ? $theme->hex($custom) : null;
            if ($hex === null) {
                $hex = $palette[$i % count($palette)];
                $round = intdiv($i, count($palette));
                if ($round > 0) {
                    $hex = self::mixHex($hex, $round % 2 === 1 ? ($theme->luminance('@bg') < 0.25 ? '#ffffff' : '#000000') : ($theme->luminance('@bg') < 0.25 ? '#000000' : '#ffffff'), $round % 2 === 1 ? 0.28 : 0.4);
                }
            }
            $out[] = $hex;
        }

        return $out;
    }

    public static function mixHex(string $a, string $b, float $t): string
    {
        $ca = [hexdec(substr($a, 1, 2)), hexdec(substr($a, 3, 2)), hexdec(substr($a, 5, 2))];
        $cb = [hexdec(substr($b, 1, 2)), hexdec(substr($b, 3, 2)), hexdec(substr($b, 5, 2))];

        return sprintf('#%02x%02x%02x', ...array_map(fn ($i) => (int) round($ca[$i] + ($cb[$i] - $ca[$i]) * $t), [0, 1, 2]));
    }

    // ------------------------------------------------------------ ticks

    /** @return array{ticks: list<float>, step: float, min: float, max: float} */
    public static function niceTicks(float $min, float $max, int $count = 5, bool $exact = false): array
    {
        $lo = $min;
        $hi = $max;
        if ($lo > $hi) {
            [$lo, $hi] = [$hi, $lo];
        }
        $n = max(2, min(50, $count));
        if ($hi == $lo) {
            if ($lo == 0) {
                $hi = 1;
            } else {
                $pad = abs($lo) * 0.1 ?: 1;
                $lo -= $pad;
                $hi += $pad;
            }
        }
        $range = $hi - $lo;
        $rough = $range / ($n - 1);
        $exp0 = (int) floor(log10($rough)) - 1;
        $step = 0.0;
        for ($e = $exp0; $e < $exp0 + 6 && $step <= 0; $e++) {
            foreach ([1, 2, 2.5, 5, 10] as $m) {
                $sz = $m * (10 ** $e);
                if ($sz < $rough * 0.999) {
                    continue;
                }
                $needed = (int) (ceil($hi / $sz - 1e-9) - floor($lo / $sz + 1e-9) + 1);
                if ($needed <= $n) {
                    $step = $sz;
                    break;
                }
            }
        }
        if ($step <= 0) {
            $step = 1.0;
        }
        if ($exact) {
            $first = ceil($lo / $step - 1e-9) * $step;
            $last = floor($hi / $step + 1e-9) * $step;
        } else {
            $first = floor($lo / $step + 1e-9) * $step;
            $last = ceil($hi / $step - 1e-9) * $step;
        }
        $ticks = [];
        $cnt = min(200, (int) round(($last - $first) / $step));
        for ($i = 0; $i <= $cnt; $i++) {
            $ticks[] = (float) round($first + $i * $step, 10);
        }

        return ['ticks' => $ticks, 'step' => (float) round($step, 10), 'min' => $exact ? $lo : $ticks[0], 'max' => $exact ? $hi : $ticks[count($ticks) - 1]];
    }

    // ------------------------------------------------------------ number formats

    private static function groupNum(float $v, int $dec): string
    {
        $s = number_format(abs($v), max(0, min(6, $dec)), '.', ',');

        return ($v < 0 && (float) str_replace(',', '', $s) != 0.0 ? '-' : '').$s;
    }

    private static function trimZeros(string $s): string
    {
        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }

    private static function autoDecimals(float $v): int
    {
        $a = abs($v);
        if ($v == floor($v)) {
            return 0;
        }
        if ($a >= 1000) {
            return 0;
        }
        if ($a >= 100) {
            return 1;
        }
        if ($a >= 1) {
            return 2;
        }

        return $a == 0 ? 0 : 3;
    }

    private static function compactNum(float $v, ?int $dec): string
    {
        $a = abs($v);
        $sign = $v < 0 ? '-' : '';
        foreach ([[1e12, 'T'], [1e9, 'B'], [1e6, 'M'], [1e3, 'K']] as [$div, $u]) {
            if ($a >= $div * 0.9995) {
                $q = $a / $div;
                $d = $dec ?? ($q >= 100 ? 0 : ($q >= 10 ? 1 : 2));

                return $sign.self::trimZeros(number_format($q, $d, '.', '')).$u;
            }
        }
        $d = $dec ?? self::autoDecimals($a);

        return $sign.self::trimZeros(number_format($a, max(0, min(4, $d)), '.', ''));
    }

    /** A formatter from chart options. @return callable(float, bool=): string */
    public static function formatter(array $opt, ?int $forceDec = null): callable
    {
        $fmt = in_array($opt['fmt'] ?? null, ['num', 'pct', 'cur', 'compact'], true) ? $opt['fmt'] : 'num';
        $cur = is_string($opt['cur'] ?? null) ? mb_substr($opt['cur'], 0, 4) : 'R';
        $dec = $forceDec ?? (isset($opt['dec']) && is_int($opt['dec']) ? $opt['dec'] : null);

        return function (float $v, bool $axis = false) use ($fmt, $cur, $dec): string {
            $useCompact = $fmt === 'compact' || ($axis && abs($v) >= 10000);
            if ($useCompact) {
                $body = self::compactNum(abs($v), $dec);
            } elseif ($dec !== null) {
                $body = self::groupNum(abs($v), $dec);
            } elseif ($fmt === 'cur') {
                $body = self::groupNum(abs($v), $v == floor($v) ? 0 : 2);
            } else {
                $body = self::trimZeros(self::groupNum(abs($v), self::autoDecimals($v)));
            }
            if ($fmt === 'pct') {
                $body .= '%';
            }
            $neg = $v < 0 && (float) preg_replace('/[^0-9.]/', '', $body) != 0.0;
            if ($fmt === 'cur') {
                $body = $cur.$body;
            }

            return ($neg ? '-' : '').$body;
        };
    }

    public static function stepDecimals(float $step): int
    {
        if ($step <= 0) {
            return 0;
        }
        for ($d = 0; $d <= 3; $d++) {
            $m = $step * (10 ** $d);
            if (abs($m - round($m)) < 1e-6) {
                return $d;
            }
        }

        return 3;
    }

    /** Axis tick formatter: decimals follow the tick step. @return callable(float): string */
    public static function tickFormatter(array $opt, float $step): callable
    {
        $fmt = $opt['fmt'] ?? 'num';
        if ($fmt === 'compact') {
            $f = self::formatter($opt);

            return fn (float $v): string => $f($v);
        }
        $f = self::formatter($opt, self::stepDecimals($step));

        return fn (float $v): string => $f($v, true);
    }

    public static function pctShare(float $share): string
    {
        $p = $share * 100;
        if ($p > 0 && $p < 1) {
            return '<1%';
        }
        $d = ($p < 10 && abs($p - round($p)) > 0.05) ? 1 : 0;

        return self::trimZeros(number_format($p, $d, '.', '')).'%';
    }

    // ------------------------------------------------------------ kpi

    /** KPIs from data.kpis, or from the first series when none are given. @return list<array<string, mixed>> */
    public static function kpiItems(array $chart): array
    {
        $kpis = $chart['data']['kpis'] ?? [];
        if ($kpis) {
            return array_slice($kpis, 0, 24);
        }
        $s = $chart['data']['series'][0] ?? null;
        if ($s === null) {
            return [];
        }
        $out = [];
        foreach ($chart['data']['categories'] as $i => $c) {
            if (isset($s['values'][$i]) && $s['values'][$i] !== null) {
                $out[] = ['label' => $c, 'value' => $s['values'][$i]];
            }
        }

        return array_slice($out, 0, 24);
    }

    /** 1 up, -1 down, 0 flat. */
    public static function deltaDirection(mixed $d): int
    {
        if (is_int($d) || is_float($d)) {
            return $d > 0 ? 1 : ($d < 0 ? -1 : 0);
        }
        if (is_string($d)) {
            $t = trim($d);
            if (str_starts_with($t, '+')) {
                return 1;
            }
            if (str_starts_with($t, '-') || str_starts_with($t, "\u{2212}")) {
                return -1;
            }
        }

        return 0;
    }

    public static function deltaText(mixed $d): string
    {
        if (is_int($d) || is_float($d)) {
            $a = abs($d);
            $body = $a == floor($a) ? (string) (int) $a : rtrim(rtrim(number_format($a, $a < 10 ? 1 : 0, '.', ''), '0'), '.');

            return ($d > 0 ? '+' : ($d < 0 ? '-' : '')).$body.'%';
        }

        return (string) $d;
    }
}
