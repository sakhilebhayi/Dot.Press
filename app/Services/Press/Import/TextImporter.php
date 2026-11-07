<?php

namespace App\Services\Press\Import;

use App\Services\Press\Export\Pdf\FontRegistry;
use DOMElement;

/**
 * Reads a DrawingML text body into the spec's text fields: a Tiptap document plus the element level properties
 * (font, size, weight, colour, alignment, spacing). Run level differences become marks; paragraph bullets become lists.
 * Properties are resolved through the inheritance chain (shape list style, layout, master, presentation defaults).
 */
final class TextImporter
{
    /** @var callable(string): ?string */
    private $link;

    /** @param callable(string): ?string $link resolves a hyperlink relationship id to an https URL or #slide: link */
    public function __construct(private ColorResolver $colors, private string $majorFont, private string $minorFont, callable $link)
    {
        $this->link = $link;
    }

    /**
     * @param  list<DOMElement>  $chain  list style sources, highest priority first (lstStyle, txStyles style, defaults)
     * @return array{doc: array<string, mixed>, text: string, props: array<string, mixed>, chars: int, equation: bool}
     */
    public function parse(DOMElement $txBody, array $chain, ?string $defaultColor = null, float $fontScale = 1.0, float $lnReduction = 0.0): array
    {
        $paras = [];
        $equation = false;
        foreach (Xml::children($txBody, 'p') as $p) {
            $paras[] = $this->paragraph($p, $chain, $defaultColor, $fontScale, $equation);
        }
        if ($paras === []) {
            $paras[] = ['lvl' => 0, 'list' => null, 'runs' => [], 'algn' => null, 'lh' => null, 'ps' => null, 'size' => 24.0 * 1.0];
        }

        // Element level properties: the value covering most characters.
        $count = fn (callable $key) => $this->mode($paras, $key);
        $sizeMode = $count(fn ($r) => round($r['size'], 2));
        $colorMode = $count(fn ($r) => $r['color'] ?? '');
        $fontMode = $count(fn ($r) => $r['font'] ?? '');
        $boldAll = true;
        $any = false;
        foreach ($paras as $p) {
            foreach ($p['runs'] as $r) {
                if ($r['t'] === '') {
                    continue;
                }
                $any = true;
                if (! $r['b']) {
                    $boldAll = false;
                }
            }
        }
        $size = $sizeMode !== null ? (float) $sizeMode : ($paras[0]['size'] ?? 24.0);
        $size = max(1.0, $size);
        $color = ($colorMode !== null && $colorMode !== '') ? $colorMode : ($defaultColor ?? '@ink');
        $font = $fontMode !== null && $fontMode !== '' ? $fontMode : '@body';
        $first = null;
        foreach ($paras as $p) {
            if ($p['runs'] !== []) {
                $first = $p;
                break;
            }
        }
        $first ??= $paras[0];
        $algn = $first['algn'] ?? 'left';
        $family = $this->familyOf($font);
        $natural = FontRegistry::naturalLineHeight($family);
        $lh = $first['lh'] ?? 1.0;
        $lhUnits = $first['lhPts'] !== null ? ($first['lhPts'] / 0.75) / $size : $lh * $natural;
        $lhUnits = $lhUnits * (1 - $lnReduction);
        $psEm = $first['ps'] === null ? 0.0 : ($first['ps'] < 0 ? -$first['ps'] : $first['ps'] / $size);

        $doc = $this->tiptap($paras, $size, $color, $font, $boldAll && $any);
        $text = '';
        $chars = 0;
        foreach ($paras as $p) {
            foreach ($p['runs'] as $r) {
                $text .= $r['t'];
                $chars += mb_strlen($r['t']);
            }
            $text .= "\n";
        }

        return [
            'doc' => $doc,
            'text' => trim($text),
            'props' => [
                'font' => $font,
                'size' => round($size, 2),
                'weight' => ($boldAll && $any) ? 700 : 400,
                'color' => $color,
                'align' => $algn,
                'lh' => round(max(0.5, min(4.0, $lhUnits)), 3),
                'ps' => round(max(0.0, min(5.0, $psEm)), 3),
            ],
            'chars' => $chars,
            'equation' => $equation,
        ];
    }

    private function familyOf(string $font): string
    {
        return $font === '@heading' ? $this->majorFont : ($font === '@body' ? $this->minorFont : $font);
    }

    /** @param list<array<string, mixed>> $paras */
    private function mode(array $paras, callable $key): mixed
    {
        $w = [];
        foreach ($paras as $p) {
            foreach ($p['runs'] as $r) {
                if ($r['t'] === '') {
                    continue;
                }
                $k = (string) $key($r);
                $w[$k] = ($w[$k] ?? 0) + mb_strlen($r['t']);
            }
        }
        if ($w === []) {
            return null;
        }
        arsort($w);
        $k = (string) array_key_first($w);

        return is_numeric($k) && ! str_starts_with($k, '#') ? (float) $k : $k;
    }

    // ------------------------------------------------------------------ paragraph

    /**
     * @param  list<DOMElement>  $chain
     * @return array<string, mixed>
     */
    private function paragraph(DOMElement $p, array $chain, ?string $defaultColor, float $fontScale, bool &$equation): array
    {
        $pPr = Xml::child($p, 'pPr');
        $lvl = max(0, min(8, Xml::int($pPr, 'lvl', 0)));
        [$src, $own] = $this->levelSources($pPr, $chain, $lvl);
        $algn = $this->attrFrom($src, 'algn');
        $algn = match ($algn) {
            'ctr' => 'center',
            'r' => 'right',
            'just', 'justLow', 'dist', 'thaiDist' => 'justify',
            'l' => 'left',
            default => null,
        };
        // Bullets: nearest bu* element wins.
        $list = null;
        foreach ($src as $s) {
            foreach (Xml::children($s) as $c) {
                if (in_array($c->localName, ['buNone', 'buChar', 'buAutoNum', 'buBlip'], true)) {
                    $list = match ($c->localName) {
                        'buChar', 'buBlip' => 'bullet',
                        'buAutoNum' => 'ordered',
                        default => null,
                    };
                    break 2;
                }
            }
        }
        $lh = null;
        $lhPts = null;
        $ps = null;
        foreach ($src as $s) {
            $ln = Xml::child($s, 'lnSpc');
            if ($ln !== null && $lh === null && $lhPts === null) {
                $pct = Xml::child($ln, 'spcPct');
                $pts = Xml::child($ln, 'spcPts');
                if ($pct !== null) {
                    $lh = Xml::int($pct, 'val', 100000) / 100000;
                } elseif ($pts !== null) {
                    $lhPts = Xml::int($pts, 'val', 0) / 100;
                }
            }
            if ($ps === null) {
                foreach (['spcAft', 'spcBef'] as $k) {
                    $sp = Xml::child($s, $k);
                    $pts = Xml::child($sp, 'spcPts');
                    $pct = Xml::child($sp, 'spcPct');
                    if ($pts !== null && Xml::int($pts, 'val', 0) > 0) {
                        $ps = Xml::int($pts, 'val', 0) / 100 / 0.75;
                        break;
                    }
                    if ($pct !== null && Xml::int($pct, 'val', 0) > 0) {
                        // a percentage of a line: about 1.2 x the text size
                        $ps = -Xml::int($pct, 'val', 0) / 100000 * 1.2;
                        break;
                    }
                }
            }
        }
        $baseSz = $this->rprAttr($src, null, 'sz');
        $ownCount = $own;
        $baseSize = ($baseSz !== null ? (float) $baseSz : 1800.0) / 100 / 0.75 * $fontScale;
        $runs = [];
        foreach (Xml::children($p) as $c) {
            switch ($c->localName) {
                case 'r':
                case 'fld':
                    $t = Xml::child($c, 't');
                    $text = $t !== null ? $t->textContent : '';
                    $runs[] = $this->run($c, $text, $src, $defaultColor, $fontScale, $ownCount);
                    break;
                case 'br':
                    $runs[] = ['t' => '', 'br' => true, 'b' => false, 'i' => false, 'u' => false, 's' => false, 'color' => null, 'size' => $baseSize, 'font' => null, 'link' => null];
                    break;
                case 'AlternateContent':
                    if (str_contains($c->ownerDocument->saveXML($c) ?: '', 'oMath')) {
                        $equation = true;
                    }
                    break;
            }
        }
        // The size of an empty paragraph comes from its end properties.
        $size = $baseSize;
        foreach ($runs as $r) {
            if (($r['t'] ?? '') !== '') {
                $size = $r['size'];
                break;
            }
        }

        return ['lvl' => $lvl, 'list' => $list, 'runs' => $runs, 'algn' => $algn, 'lh' => $lh, 'lhPts' => $lhPts, 'ps' => $ps, 'size' => $size];
    }

    /**
     * The property sources for a paragraph: its own pPr first, then the chain's level elements.
     *
     * The first element of the chain is the shape's own list style; the count says how many leading sources are the shape's own.
     *
     * @param  list<DOMElement>  $chain
     * @return array{0: list<DOMElement>, 1: int}
     */
    private function levelSources(?DOMElement $pPr, array $chain, int $lvl): array
    {
        $src = [];
        $own = 0;
        if ($pPr !== null) {
            $src[] = $pPr;
            $own++;
        }
        foreach ($chain as $i => $c) {
            $l = Xml::child($c, 'lvl'.($lvl + 1).'pPr');
            if ($l !== null) {
                $src[] = $l;
                if ($i === 0) {
                    $own++;
                }
            }
        }

        return [$src, $own];
    }

    /** @param list<DOMElement> $src */
    private function attrFrom(array $src, string $name): ?string
    {
        foreach ($src as $s) {
            $v = Xml::attr($s, $name);
            if ($v !== null) {
                return $v;
            }
        }

        return null;
    }

    /** Attribute of the run properties: the run's own rPr first, then each level's defRPr. @param list<DOMElement> $src */
    private function rprAttr(array $src, ?DOMElement $rPr, string $name): ?string
    {
        $v = Xml::attr($rPr, $name);
        if ($v !== null) {
            return $v;
        }
        foreach ($src as $s) {
            $d = Xml::child($s, 'defRPr');
            $v = Xml::attr($d, $name);
            if ($v !== null) {
                return $v;
            }
        }

        return null;
    }

    /** @param list<DOMElement> $src */
    private function rprChild(array $src, ?DOMElement $rPr, string $name): ?DOMElement
    {
        $c = Xml::child($rPr, $name);
        if ($c !== null) {
            return $c;
        }
        foreach ($src as $s) {
            $c = Xml::child(Xml::child($s, 'defRPr'), $name);
            if ($c !== null) {
                return $c;
            }
        }

        return null;
    }

    /**
     * @param  list<DOMElement>  $src
     * @return array<string, mixed>
     */
    private function run(DOMElement $r, string $text, array $src, ?string $defaultColor, float $fontScale, int $own = 0): array
    {
        $rPr = Xml::child($r, 'rPr');
        $sz = $this->rprAttr($src, $rPr, 'sz');
        $size = ($sz !== null ? (float) $sz : 1800.0) / 100 / 0.75 * $fontScale;
        // Colour: the run, the shape's own list style, the shape style's font colour, then layout, master and defaults.
        $fill = Xml::child($rPr, 'solidFill');
        for ($i = 0; $fill === null && $i < $own; $i++) {
            $fill = Xml::child(Xml::child($src[$i], 'defRPr') ?? ($src[$i]->localName === 'defRPr' ? $src[$i] : null), 'solidFill');
        }
        $color = $fill !== null ? $this->colors->spec($fill) : null;
        if ($color === null && $defaultColor !== null) {
            $color = $defaultColor;
        }
        if ($color === null) {
            $fill = $this->rprChild($src, null, 'solidFill');
            $color = $fill !== null ? $this->colors->spec($fill) : null;
        }
        $latin = $this->rprChild($src, $rPr, 'latin');
        $face = Xml::attr($latin, 'typeface');
        $font = null;
        if ($face !== null && $face !== '') {
            $font = match (true) {
                $face === '+mj-lt' || $face === $this->majorFont && $face !== $this->minorFont => '@heading',
                $face === '+mn-lt' || $face === $this->minorFont => '@body',
                str_starts_with($face, '+') => '@body',
                default => preg_replace('/[^A-Za-z0-9 \-_.]/', '', $face) ?: '@body',
            };
        }
        $u = $this->rprAttr($src, $rPr, 'u');
        $strike = $this->rprAttr($src, $rPr, 'strike');
        $link = null;
        $h = Xml::child($rPr, 'hlinkClick');
        if ($h !== null) {
            $rid = Xml::attrLocal($h, 'id');
            if ($rid !== null && $rid !== '') {
                $link = ($this->link)($rid);
            }
        }
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? '';

        return [
            't' => $text,
            'b' => $this->rprAttr($src, $rPr, 'b') === '1' || $this->rprAttr($src, $rPr, 'b') === 'true',
            'i' => in_array($this->rprAttr($src, $rPr, 'i'), ['1', 'true'], true),
            'u' => $u !== null && $u !== 'none',
            's' => $strike !== null && $strike !== 'noStrike',
            'color' => $color,
            'size' => $size,
            'font' => $font,
            'link' => $link,
        ];
    }

    // ------------------------------------------------------------------ tiptap

    /** @param list<array<string, mixed>> $paras */
    private function tiptap(array $paras, float $baseSize, string $baseColor, string $baseFont, bool $baseBold): array
    {
        $root = [];
        $stack = []; // each: ['level' => int, 'kind' => string, 'node' => &array]
        foreach ($paras as $p) {
            $content = $this->inline($p['runs'], $baseSize, $baseColor, $baseFont, $baseBold);
            $para = ['type' => 'paragraph'];
            if ($content !== []) {
                $para['content'] = $content;
            }
            if ($p['list'] === null) {
                $stack = [];
                $root[] = $para;

                continue;
            }
            $lvl = $p['lvl'];
            while ($stack && $stack[count($stack) - 1]['level'] > $lvl) {
                array_pop($stack);
            }
            if ($stack && $stack[count($stack) - 1]['level'] === $lvl && $stack[count($stack) - 1]['kind'] !== $p['list']) {
                array_pop($stack);
            }
            if (! $stack || $stack[count($stack) - 1]['level'] < $lvl) {
                $node = ['type' => $p['list'] === 'ordered' ? 'orderedList' : 'bulletList', 'content' => []];
                if ($stack) {
                    $top = &$stack[count($stack) - 1]['ref'];
                    $last = count($top['content']) - 1;
                    if ($last < 0) {
                        $top['content'][] = ['type' => 'listItem', 'content' => []];
                        $last = 0;
                    }
                    $top['content'][$last]['content'][] = &$node;
                    unset($top);
                } else {
                    $root[] = &$node;
                }
                $stack[] = ['level' => $lvl, 'kind' => $p['list'], 'ref' => &$node];
                unset($node);
            }
            $top = &$stack[count($stack) - 1]['ref'];
            $top['content'][] = ['type' => 'listItem', 'content' => [$para]];
            unset($top);
        }
        // break the reference links before returning a plain array
        return json_decode((string) json_encode(['type' => 'doc', 'content' => $root]), true) ?: ['type' => 'doc', 'content' => [['type' => 'paragraph']]];
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     * @return list<array<string, mixed>>
     */
    private function inline(array $runs, float $baseSize, string $baseColor, string $baseFont, bool $baseBold): array
    {
        $out = [];
        foreach ($runs as $r) {
            if (! empty($r['br'])) {
                $out[] = ['type' => 'hardBreak'];

                continue;
            }
            if ($r['t'] === '') {
                continue;
            }
            $marks = [];
            if ($r['b'] && ! $baseBold) {
                $marks[] = ['type' => 'bold'];
            }
            if (! $r['b'] && $baseBold) {
                // A regular run inside an all bold element cannot be expressed with a mark: it stays bold.
            }
            if ($r['i']) {
                $marks[] = ['type' => 'italic'];
            }
            if ($r['u'] && $r['link'] === null) {
                $marks[] = ['type' => 'underline'];
            }
            if ($r['s']) {
                $marks[] = ['type' => 'strike'];
            }
            $ts = [];
            if ($r['color'] !== null && $r['color'] !== $baseColor && $r['link'] === null) {
                $ts['color'] = $r['color'];
            }
            if (abs($r['size'] - $baseSize) > 0.5) {
                $ts['fontSize'] = round($r['size'], 2);
            }
            if ($r['font'] !== null && $r['font'] !== $baseFont) {
                $ts['fontFamily'] = $r['font'];
            }
            if ($ts) {
                $marks[] = ['type' => 'textStyle', 'attrs' => $ts];
            }
            if ($r['link'] !== null) {
                $marks[] = ['type' => 'link', 'attrs' => ['href' => $r['link']]];
            }
            $node = ['type' => 'text', 'text' => $r['t']];
            if ($marks) {
                $node['marks'] = $marks;
            }
            $out[] = $node;
        }

        return $out;
    }
}
