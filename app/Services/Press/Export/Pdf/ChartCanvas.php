<?php

namespace App\Services\Press\Export\Pdf;

use App\Services\Press\Export\Support\ChartData;
use App\Services\Press\Export\Support\Theme;

/**
 * The drawing context for a chart: size, colours, the frame (title, legend, notes) and the primitives. It follows the
 * editor's JS chart engine (resources/js/press/charts/svg.js and util.js): the same text measurement table, the same
 * frame and legend rules. Marks are collected as SVG without text; text is collected separately so the PDF can set it
 * in the real fonts (DomPDF's SVG reader only knows the core PDF fonts).
 */
final class ChartCanvas
{
    /** Helvetica advance widths per 1000 em for ASCII 32..126, the same table the editor uses to plan labels. */
    private const ASCII_W = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
        556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
    ];

    private const TEXT_SAFETY = 1.05;

    public float $w;

    public float $h;

    public float $fs;

    public float $k;

    public float $pad;

    public string $ink;

    public string $muted;

    public string $line;

    public string $surface;

    public string $font;

    /** @var list<string> */
    public array $colors;

    /** @var list<string> */
    public array $body = [];

    /** @var list<array<string, mixed>> */
    public array $texts = [];

    /** @var list<string> */
    public array $notes = [];

    /** @var array<string, mixed>|null */
    public ?array $chrome = null;

    /** @var callable(float, bool=): string */
    public $fmt;

    /** @param array<string, mixed> $chart a normalised chart */
    public function __construct(public array $chart, float $w, float $h, public Theme $theme)
    {
        $this->w = max(120.0, min(4000.0, $w));
        $this->h = max(90.0, min(4000.0, $h));
        $this->fs = max(10.0, min(36.0, min($this->h * 0.045, $this->w * 0.04)));
        $this->k = max(1.0, $this->fs / 14);
        $this->pad = max(8.0, $this->fs * 0.8);
        $this->ink = (string) $theme->hex('@ink');
        $this->muted = (string) $theme->hex('@muted');
        $this->line = (string) $theme->hex('@line');
        $this->surface = (string) $theme->hex('@bg');
        $this->font = $theme->body;
        $this->colors = ChartData::seriesColors($chart, $theme);
        $this->fmt = ChartData::formatter($chart['opt']);
    }

    // ------------------------------------------------------------ numbers and strings

    public static function fx(float $v): string
    {
        if (! is_finite($v)) {
            return '0';
        }
        $r = round($v * 100) / 100;

        return $r == (int) $r ? (string) (int) $r : rtrim(rtrim(number_format($r, 2, '.', ''), '0'), '.');
    }

    public static function esc(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? '';

        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param array<string, mixed> $attrs */
    public static function el(string $tag, array $attrs = [], string $inner = ''): string
    {
        $a = '';
        foreach ($attrs as $k => $v) {
            if ($v === null || $v === false || $v === '') {
                continue;
            }
            $a .= ' '.$k.'="'.(is_float($v) || is_int($v) ? self::fx((float) $v) : self::esc((string) $v)).'"';
        }

        return $inner === '' ? '<'.$tag.$a.'/>' : '<'.$tag.$a.'>'.$inner.'</'.$tag.'>';
    }

    public function add(string $s): void
    {
        $this->body[] = $s;
    }

    // ------------------------------------------------------------ text measurement (same table as the editor)

    public static function charWidth(int $code): int
    {
        if ($code >= 32 && $code <= 126) {
            return self::ASCII_W[$code - 32];
        }
        if ($code === 0x2026) {
            return 1000;
        }
        if ($code === 0xA0 || $code === 0x202F || $code === 0x2009) {
            return 278;
        }
        if ($code < 32) {
            return 0;
        }
        if ($code >= 0x300 && $code <= 0x36F) {
            return 0;
        }
        if ($code >= 0x2E80 || ($code >= 0x1F000 && $code <= 0x1FAFF)) {
            return 1000;
        }
        if ($code >= 0xFF00 && $code <= 0xFFEF) {
            return 1000;
        }
        if ($code >= 0xC0 && $code <= 0x24F) {
            return 560;
        }
        if ($code >= 0x2190 && $code <= 0x2BFF) {
            return 800;
        }

        return 600;
    }

    public static function textWidth(string $str, float $size, int $weight = 400): float
    {
        $w = 0;
        foreach (mb_str_split($str) as $ch) {
            $w += self::charWidth(mb_ord($ch));
        }
        $bold = $weight >= 600 ? 1.07 : 1.0;

        return ($w / 1000) * $size * $bold * self::TEXT_SAFETY;
    }

    public static function fitText(string $str, float $size, float $maxW, int $weight = 400): string
    {
        if (self::textWidth($str, $size, $weight) <= $maxW) {
            return $str;
        }
        if ($maxW < self::textWidth('…', $size, $weight)) {
            return '';
        }
        $lo = 0;
        $hi = mb_strlen($str);
        while ($lo < $hi) {
            $mid = ($lo + $hi + 1) >> 1;
            if (self::textWidth(rtrim(mb_substr($str, 0, $mid)).'…', $size, $weight) <= $maxW) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }

        return rtrim(mb_substr($str, 0, $lo)).'…';
    }

    /** @return list<string> */
    public static function wrapText(string $str, float $size, float $maxW, int $maxLines = 2, int $weight = 400): array
    {
        $text = ChartData::text($str, 400);
        if ($text === '') {
            return [];
        }
        $words = explode(' ', $text);
        $lines = [];
        $cur = '';
        $i = 0;
        while ($i < count($words) && count($lines) < $maxLines) {
            $w = $words[$i];
            if ($cur === '') {
                if (self::textWidth($w, $size, $weight) <= $maxW) {
                    $cur = $w;
                    $i++;
                } else {
                    $k = mb_strlen($w);
                    while ($k > 1 && self::textWidth(mb_substr($w, 0, $k), $size, $weight) > $maxW) {
                        $k--;
                    }
                    $lines[] = mb_substr($w, 0, $k);
                    $words[$i] = mb_substr($w, $k);
                    if ($words[$i] === '') {
                        $i++;
                    }
                }
            } elseif (self::textWidth($cur.' '.$w, $size, $weight) <= $maxW) {
                $cur .= ' '.$w;
                $i++;
            } else {
                $lines[] = $cur;
                $cur = '';
            }
        }
        if ($cur !== '' && count($lines) < $maxLines) {
            $lines[] = $cur;
        }
        if ($i < count($words) && $lines) {
            $rest = implode(' ', array_slice($words, $i));
            $lines[count($lines) - 1] = self::fitText($lines[count($lines) - 1].' '.$rest, $size, $maxW, $weight);
        }

        return array_slice($lines, 0, $maxLines);
    }

    // ------------------------------------------------------------ text primitives

    /**
     * A text line. $y is the vertical centre of the line (the editor's convention); the baseline sits a third of the size below.
     *
     * @param  array{size?: float, weight?: int, anchor?: string, fill?: string, rotate?: float}  $o
     */
    public function text(float $x, float $y, string $str, array $o = []): void
    {
        if ($str === '') {
            return;
        }
        $this->texts[] = [
            'x' => $x,
            'y' => $y + ($o['size'] ?? $this->fs) * 0.35,
            'text' => $str,
            'size' => (float) ($o['size'] ?? $this->fs),
            'anchor' => $o['anchor'] ?? 'start',
            'bold' => ($o['weight'] ?? 400) >= 600,
            'color' => $o['fill'] ?? $this->ink,
            'rot' => $o['rotate'] ?? 0,
            'maxw' => 700,
        ];
    }

    /** @return array{x: float, y: float, w: float, h: float} */
    public static function textBox(float $x, float $y, string $str, float $size, string $anchor = 'start', int $weight = 400, float $rotate = 0): array
    {
        $w = self::textWidth($str, $size, $weight);
        $h = $size * 1.15;
        $left = $anchor === 'middle' ? $x - $w / 2 : ($anchor === 'end' ? $x - $w : $x);
        if (! $rotate) {
            return ['x' => $left, 'y' => $y - $h / 2, 'w' => $w, 'h' => $h];
        }
        $rad = deg2rad(abs($rotate));
        $bw = $w * cos($rad) + $h * sin($rad);
        $bh = $w * sin($rad) + $h * cos($rad);

        return ['x' => $anchor === 'end' ? $x - $bw : $x, 'y' => $rotate < 0 ? $y - $bh + $h / 2 : $y - $h / 2, 'w' => $bw, 'h' => $bh];
    }

    /** The finished SVG: marks, then every text line as <text> (the standalone form). */
    public function svg(string $title = ''): string
    {
        $t = '';
        foreach ($this->texts as $p) {
            $attrs = ['x' => $p['x'], 'y' => $p['y'], 'font-size' => $p['size'], 'font-family' => $this->font.', Helvetica, Arial, sans-serif', 'fill' => $p['color']];
            if ($p['bold']) {
                $attrs['font-weight'] = 700;
            }
            if ($p['anchor'] !== 'start') {
                $attrs['text-anchor'] = $p['anchor'];
            }
            if ($p['rot']) {
                $attrs['transform'] = 'rotate('.self::fx((float) $p['rot']).' '.self::fx($p['x']).' '.self::fx($p['y'] - $p['size'] * 0.35).')';
            }
            $t .= self::el('text', $attrs, self::esc($p['text']));
        }

        return $this->shapesSvg().$t.'</svg>';
    }

    /** Opening tag plus marks, no text, no closing tag. */
    private function shapesSvg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.self::fx($this->w).' '.self::fx($this->h).'" width="'.self::fx($this->w).'" height="'.self::fx($this->h).'">'.implode('', $this->body);
    }

    /** Marks only (no text), as a complete SVG document. */
    public function shapes(): string
    {
        return $this->shapesSvg().'</svg>';
    }

    // ------------------------------------------------------------ markers

    public function marker(string $shape, float $cx, float $cy, float $r, string $fill, float $fillOpacity = 1.0, ?string $stroke = null, float $strokeWidth = 0.0): string
    {
        $common = ['fill' => $fill, 'fill-opacity' => $fillOpacity < 1 ? $fillOpacity : null, 'stroke' => $stroke, 'stroke-width' => $stroke ? $strokeWidth : null];
        $poly = function (array $pts) use ($common): string {
            return self::el('polygon', ['points' => implode(' ', array_map(fn ($p) => self::fx($p[0]).','.self::fx($p[1]), $pts))] + $common);
        };

        return match ($shape) {
            'square' => self::el('rect', ['x' => $cx - $r * 0.88, 'y' => $cy - $r * 0.88, 'width' => $r * 1.76, 'height' => $r * 1.76] + $common),
            'diamond' => $poly([[$cx, $cy - $r * 1.25], [$cx + $r * 1.25, $cy], [$cx, $cy + $r * 1.25], [$cx - $r * 1.25, $cy]]),
            'triangle' => $poly([[$cx, $cy - $r * 1.2], [$cx + $r * 1.15, $cy + $r * 0.85], [$cx - $r * 1.15, $cy + $r * 0.85]]),
            'triangle-down' => $poly([[$cx, $cy + $r * 1.2], [$cx + $r * 1.15, $cy - $r * 0.85], [$cx - $r * 1.15, $cy - $r * 0.85]]),
            default => self::el('circle', ['cx' => $cx, 'cy' => $cy, 'r' => $r] + $common),
        };
    }

    public const MARKERS = ['circle', 'square', 'diamond', 'triangle', 'triangle-down', 'circle', 'square', 'diamond'];

    public function markerShape(int $i): string
    {
        return self::MARKERS[$i % count(self::MARKERS)];
    }

    /** Dash pattern for series i (comma separated: the SVG reader needs commas). */
    public function dash(int $i): ?string
    {
        $d = [null, null, null, null, '10,6', '3,5', '14,5,3,5', '2,4'][$i % 8];
        if ($i >= 8) {
            return '10,6';
        }

        return $d;
    }

    // ------------------------------------------------------------ frame: title, subtitle, legend, notes

    /**
     * @param  list<array{name: string, color: string, kind: string, marker?: string, dash?: ?string, opacity?: float}>  $legend
     * @return array{x: float, y: float, w: float, h: float}
     */
    public function planFrame(array $legend = [], bool $noLegend = false): array
    {
        $opt = $this->chart['opt'];
        $fs = $this->fs;
        $top = $this->pad;
        $bottom = $this->h - $this->pad;
        $left = $this->pad;
        $right = $this->w - $this->pad;
        $chrome = ['title' => null, 'sub' => null, 'legend' => null, 'notes' => []];
        if (isset($opt['title'])) {
            $size = $fs * 1.3;
            $lines = ($this->h >= 300 && self::textWidth($opt['title'], $size, 700) > $right - $left) ? self::wrapText($opt['title'], $size, $right - $left, 2, 700) : [self::fitText($opt['title'], $size, $right - $left, 700)];
            $chrome['title'] = ['lines' => $lines, 'size' => $size, 'x' => $left, 'y' => $top + $size * 0.65, 'lh' => $size * 1.2];
            $top += count($lines) * $size * 1.2 + $fs * 0.1;
        }
        if (isset($opt['sub'])) {
            $size = $fs * 0.92;
            $chrome['sub'] = ['text' => self::fitText($opt['sub'], $size, $right - $left), 'size' => $size, 'x' => $left, 'y' => $top + $size * 0.6];
            $top += $size * 1.35;
        }
        if ($chrome['title'] || $chrome['sub']) {
            $top += $fs * 0.45;
        }
        $notes = array_slice($this->notes, 0, 3);
        if ($notes) {
            $size = $fs * 0.8;
            $lh = $size * 1.3;
            foreach ($notes as $i => $n) {
                $chrome['notes'][] = ['text' => self::fitText($n, $size, $right - $left), 'size' => $size, 'x' => $left, 'y' => $bottom - (count($notes) - 1 - $i) * $lh - $size * 0.4];
            }
            $bottom -= count($notes) * $lh + $fs * 0.3;
        }
        $items = array_values(array_filter($legend, fn ($it) => $it['name'] !== ''));
        $position = $items && ! $noLegend ? $opt['legend'] : 'none';
        if ($position === 'right' && ($this->w < 340 || count($items) > 14)) {
            $position = 'bottom';
        }
        $size = $fs * 0.9;
        $keyW = fn (array $it) => $it['kind'] === 'line' ? $size * 1.9 : $size * 0.84;
        if ($position === 'bottom' || $position === 'top') {
            $avail = $right - $left;
            $gap = $fs * 1.3;
            $maxItemW = max(40.0, $avail * 0.7);
            $rows = [[]];
            $rowW = 0.0;
            foreach ($items as $it) {
                $label = self::fitText($it['name'], $size, $maxItemW - $keyW($it) - $size * 0.5);
                $iw = $keyW($it) + $size * 0.5 + self::textWidth($label, $size);
                if ($rowW > 0 && $rowW + $gap + $iw > $avail) {
                    $rows[] = [];
                    $rowW = 0.0;
                }
                $rows[count($rows) - 1][] = ['it' => $it, 'label' => $label, 'iw' => $iw];
                $rowW += ($rowW > 0 ? $gap : 0) + $iw;
            }
            $shown = $rows;
            $more = 0;
            $maxRows = $this->h >= 400 ? 3 : 2;
            if (count($rows) > $maxRows) {
                $shown = array_slice($rows, 0, $maxRows);
                $kept = array_sum(array_map('count', $shown));
                $more = count($items) - $kept;
                $last = &$shown[$maxRows - 1];
                while (count($last) > 1 && array_sum(array_map(fn ($x) => $x['iw'] + $gap, $last)) + self::textWidth('+99 more', $size) > $avail) {
                    array_pop($last);
                    $more++;
                }
                unset($last);
            }
            $rh = $size * 1.55;
            $height = count($shown) * $rh + $fs * 0.2;
            $y0 = $position === 'bottom' ? $bottom - $height : $top;
            $chrome['legend'] = ['position' => $position, 'rows' => $shown, 'more' => $more, 'gap' => $gap, 'size' => $size, 'rh' => $rh, 'x' => $left, 'w' => $avail, 'y' => $y0, 'column' => false];
            if ($position === 'bottom') {
                $bottom -= $height + $fs * 0.3;
            } else {
                $top += $height + $fs * 0.3;
            }
        } elseif ($position === 'right') {
            $maxW = min($this->w * 0.28, 260.0);
            $rowsAll = [];
            foreach ($items as $it) {
                $label = self::fitText($it['name'], $size, $maxW - $keyW($it) - $size * 0.6);
                $rowsAll[] = ['it' => $it, 'label' => $label, 'iw' => $keyW($it) + $size * 0.6 + self::textWidth($label, $size)];
            }
            $rh = $size * 1.6;
            $maxRows = max(1, (int) floor(($bottom - $top) / $rh));
            $shown = $rowsAll;
            $more = 0;
            if (count($rowsAll) > $maxRows) {
                $shown = array_slice($rowsAll, 0, max(1, $maxRows - 1));
                $more = count($rowsAll) - count($shown);
            }
            $lw = max(array_map(fn ($r) => $r['iw'], $shown) ?: [0.0]);
            if ($more) {
                $lw = max($lw, self::textWidth('+99 more', $size));
            }
            $height = (count($shown) + ($more ? 1 : 0)) * $rh;
            $ly = $top + max(0.0, ($bottom - $top - $height) / 2);
            $chrome['legend'] = ['position' => 'right', 'rows' => array_map(fn ($r) => [$r], $shown), 'more' => $more, 'gap' => 0.0, 'size' => $size, 'rh' => $rh, 'x' => $right - $lw, 'w' => $lw, 'y' => $ly, 'column' => true];
            $right -= $lw + $fs * 1.4;
        }
        $this->chrome = $chrome;

        return ['x' => $left, 'y' => $top, 'w' => max(10.0, $right - $left), 'h' => max(10.0, $bottom - $top)];
    }

    public function drawChrome(): void
    {
        $c = $this->chrome;
        if (! $c) {
            return;
        }
        if ($c['title']) {
            foreach ($c['title']['lines'] as $i => $ln) {
                $this->text($c['title']['x'], $c['title']['y'] + $i * $c['title']['lh'], $ln, ['size' => $c['title']['size'], 'weight' => 700]);
            }
        }
        if ($c['sub']) {
            $this->text($c['sub']['x'], $c['sub']['y'], $c['sub']['text'], ['size' => $c['sub']['size'], 'fill' => $this->muted]);
        }
        if ($c['legend']) {
            $L = $c['legend'];
            $keyW = fn (array $it) => $it['kind'] === 'line' ? $L['size'] * 1.9 : $L['size'] * 0.84;
            foreach ($L['rows'] as $ri => $row) {
                $rowW = array_sum(array_map(fn ($r) => $r['iw'], $row)) + max(0, count($row) - 1) * $L['gap'];
                if ($ri === count($L['rows']) - 1 && $L['more'] && ! $L['column']) {
                    $rowW += $L['gap'] + self::textWidth('+'.$L['more'].' more', $L['size']);
                }
                $x = $L['column'] ? $L['x'] : $L['x'] + max(0.0, ($L['w'] - $rowW) / 2);
                $y = $L['y'] + $ri * $L['rh'] + $L['rh'] / 2;
                foreach ($row as $r) {
                    $this->legendKey($r['it'], $x, $y, $L['size']);
                    $this->text($x + $keyW($r['it']) + $L['size'] * 0.5, $y, $r['label'], ['size' => $L['size']]);
                    $x += $r['iw'] + $L['gap'];
                }
                if ($ri === count($L['rows']) - 1 && $L['more'] && ! $L['column']) {
                    $this->text($x, $y, '+'.$L['more'].' more', ['size' => $L['size'], 'fill' => $this->muted]);
                }
            }
            if ($L['column'] && $L['more']) {
                $this->text($L['x'], $L['y'] + count($L['rows']) * $L['rh'] + $L['rh'] / 2, '+'.$L['more'].' more', ['size' => $L['size'], 'fill' => $this->muted]);
            }
        }
        foreach ($c['notes'] as $n) {
            $this->text($n['x'], $n['y'], $n['text'], ['size' => $n['size'], 'fill' => $this->muted]);
        }
    }

    /** @param array{name: string, color: string, kind: string, marker?: string, dash?: ?string, opacity?: float} $item */
    private function legendKey(array $item, float $x, float $y, float $size): void
    {
        $stroke = max(2.0, $this->k * 2);
        if ($item['kind'] === 'line') {
            $this->add(self::el('line', ['x1' => $x, 'y1' => $y, 'x2' => $x + $size * 1.9, 'y2' => $y, 'stroke' => $item['color'], 'stroke-width' => $stroke, 'stroke-linecap' => 'round', 'stroke-dasharray' => $item['dash'] ?? null]));
            $this->add($this->marker($item['marker'] ?? 'circle', $x + $size * 0.95, $y, max(3.0, $size * 0.32), $item['color'], 1.0, $this->surface, max(1.0, $this->k)));
        } elseif ($item['kind'] === 'marker') {
            $this->add($this->marker($item['marker'] ?? 'circle', $x + $size * 0.5, $y, max(3.5, $size * 0.38), $item['color'], $item['opacity'] ?? 1.0));
        } else {
            $this->add(self::el('rect', ['x' => $x, 'y' => $y - $size * 0.42, 'width' => $size * 0.84, 'height' => $size * 0.84, 'rx' => $size * 0.16, 'fill' => $item['color']]));
        }
    }

    public function drawEmpty(array $box, string $message): void
    {
        $size = $this->fs * 1.05;
        $lines = self::wrapText($message, $size, max(60.0, $box['w'] - $this->fs * 2), 2);
        foreach ($lines as $i => $ln) {
            $this->text($box['x'] + $box['w'] / 2, $box['y'] + $box['h'] / 2 + ($i - (count($lines) - 1) / 2) * $size * 1.3, $ln, ['size' => $size, 'anchor' => 'middle', 'fill' => $this->muted]);
        }
    }

    /** Series legend entries. @param list<string>|null $kinds */
    public function legendForSeries(?array $kinds = null): array
    {
        $out = [];
        foreach ($this->chart['data']['series'] as $i => $s) {
            $out[] = ['name' => $s['name'], 'color' => $this->colors[$i], 'kind' => $kinds[$i] ?? 'swatch', 'marker' => $this->markerShape($i), 'dash' => $this->dash($i)];
        }

        return $out;
    }

    /** Collision-aware label placement. */
    public static function placer(array $bounds, array $avoid = []): ChartPlacer
    {
        return new ChartPlacer($bounds, $avoid);
    }

    /** ink colour (white or near black) with the better contrast on a fill. */
    public static function inkOn(string $fill): string
    {
        $lum = fn (array $c) => (0.2126 * self::lin($c[0]) + 0.7152 * self::lin($c[1]) + 0.0722 * self::lin($c[2]));
        $rgb = [hexdec(substr($fill, 1, 2)), hexdec(substr($fill, 3, 2)), hexdec(substr($fill, 5, 2))];
        $l = $lum($rgb);
        $cw = (1.0 + 0.05) / ($l + 0.05);
        $dark = $lum([11, 11, 11]);
        $cb = ($l + 0.05) / ($dark + 0.05);

        return $cw >= $cb ? '#ffffff' : '#0b0b0b';
    }

    private static function lin(float $v): float
    {
        $s = $v / 255;

        return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
    }
}
