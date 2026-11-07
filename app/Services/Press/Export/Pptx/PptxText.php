<?php

namespace App\Services\Press\Export\Pptx;

use App\Services\Press\Export\Pdf\FontRegistry;
use App\Services\Press\Export\Support\TextMeasurer;
use App\Services\Press\Export\Support\TextStyle;
use App\Services\Press\Export\Support\Theme;

/** Builds a:txBody for text boxes, shapes and table cells from the paragraph model. */
final class PptxText
{
    /** @var callable(string): ?string resolves a link to a relationship id */
    private $linkRel;

    /** @param callable(string): ?string $linkRel */
    public function __construct(private Theme $theme, callable $linkRel)
    {
        $this->linkRel = $linkRel;
    }

    /**
     * @param  list<array<string, mixed>>  $paras
     * @param  array<string, mixed>  $style  from TextStyle::of
     * @param  array{w: float, h: float, pad: float, anchor?: string, wrap?: bool, alphaMul?: float, bodyList?: bool, inset?: array{0: float, 1: float, 2: float, 3: float}}  $box
     */
    public function txBody(array $paras, array $style, array $box): string
    {
        $pad = $box['pad'];
        $ins = $box['inset'] ?? [$pad, $pad, $pad, $pad];
        $anchor = match ($box['anchor'] ?? $style['valign']) {
            'middle' => 'ctr',
            'bottom' => 'b',
            default => 't',
        };
        $auto = '<a:noAutofit/>';
        if ($style['fit'] === 'shrink') {
            $scale = TextMeasurer::fitScale($paras, $style, max(1.0, $box['w'] - $ins[0] - $ins[2]), max(1.0, $box['h'] - $ins[1] - $ins[3]));
            $auto = $scale < 0.999 ? '<a:normAutofit fontScale="'.(int) round($scale * 100000).'"/>' : '<a:normAutofit/>';
        }
        $x = '<p:txBody><a:bodyPr wrap="'.(($box['wrap'] ?? true) ? 'square' : 'none').'" lIns="'.Ooxml::emu($ins[0]).'" tIns="'.Ooxml::emu($ins[1]).'" rIns="'.Ooxml::emu($ins[2]).'" bIns="'.Ooxml::emu($ins[3]).'" rtlCol="0" anchor="'.$anchor.'">'.$auto.'</a:bodyPr><a:lstStyle/>';
        $count = count($paras);
        foreach ($paras as $i => $p) {
            $x .= $this->paragraph($p, $style, $i < $count - 1, (float) ($box['alphaMul'] ?? 1.0));
        }

        return $x.'</p:txBody>';
    }

    /** @param array<string, mixed> $p */
    public function paragraph(array $p, array $style, bool $spaceAfter, float $alphaMul = 1.0): string
    {
        $size = $style['size'];
        $attrs = ' algn="'.match ($style['align']) {
            'center' => 'ctr',
            'right' => 'r',
            'justify' => 'just',
            default => 'l',
        }.'"';
        $natural = FontRegistry::naturalLineHeight($style['font']);
        $kids = '<a:lnSpc><a:spcPct val="'.(int) round($style['lh'] / $natural * 100000).'"/></a:lnSpc>';
        $kids .= '<a:spcBef><a:spcPts val="0"/></a:spcBef>';
        $after = $spaceAfter ? (int) round($style['ps'] * $size * 0.75 * 100) : 0;
        $kids .= '<a:spcAft><a:spcPts val="'.$after.'"/></a:spcAft>';
        if ($p['list'] !== null) {
            $ind = TextStyle::listIndent((int) $p['level'], $size);
            $attrs .= ' marL="'.Ooxml::emu($ind['left']).'" indent="-'.Ooxml::emu($ind['marker']).'"';
            if ($p['level'] > 0) {
                $attrs .= ' lvl="'.min(8, (int) $p['level']).'"';
            }
            if (! empty($p['cont'])) {
                $kids .= '<a:buNone/>';
            } elseif ($p['list'] === 'ordered') {
                $type = match ($p['level'] % 3) {
                    1 => 'alphaLcPeriod',
                    2 => 'romanLcPeriod',
                    default => 'arabicPeriod',
                };
                $kids .= '<a:buFont typeface="+mj-lt"/><a:buAutoNum type="'.$type.'" startAt="'.max(1, (int) $p['n']).'"/>';
            } else {
                $char = TextStyle::marker('bullet', 1, (int) $p['level']);
                $kids .= '<a:buFont typeface="Arial"/><a:buChar char="'.Ooxml::esc($char).'"/>';
            }
        } else {
            $attrs .= ' marL="0" indent="0"';
            $kids .= '<a:buNone/>';
        }
        $x = '<a:p><a:pPr'.$attrs.'>'.$kids.'</a:pPr>';
        foreach ($p['runs'] as $r) {
            if (! empty($r['br'])) {
                $x .= '<a:br>'.$this->rPr($r, $style, $alphaMul).'</a:br>';

                continue;
            }
            if ($r['t'] === '') {
                continue;
            }
            $x .= '<a:r>'.$this->rPr($r, $style, $alphaMul).'<a:t>'.Ooxml::esc((string) $r['t']).'</a:t></a:r>';
        }
        $x .= '<a:endParaRPr lang="en-US" sz="'.(int) round($size * 0.75 * 100).'" dirty="0"/>';

        return $x.'</a:p>';
    }

    /** @param array<string, mixed> $r */
    private function rPr(array $r, array $style, float $alphaMul): string
    {
        $size = $r['size'] ?? $style['size'];
        $bold = $r['b'] || $style['bold'];
        $attrs = ' lang="en-US" sz="'.max(100, (int) round($size * 0.75 * 100)).'"';
        if ($bold) {
            $attrs .= ' b="1"';
        }
        if ($r['i']) {
            $attrs .= ' i="1"';
        }
        if ($r['u'] || $r['link'] !== null) {
            $attrs .= ' u="sng"';
        }
        if ($r['s']) {
            $attrs .= ' strike="sngStrike"';
        }
        if ($style['ls'] != 0.0) {
            $attrs .= ' spc="'.(int) round($style['ls'] * $size * 0.75 * 100).'"';
        }
        $attrs .= ' dirty="0"';
        $colorSpec = $r['color'] ?? $style['color'];
        if ($r['link'] !== null && $r['color'] === null) {
            $colorSpec = '@accent';
        }
        $kids = '';
        if ($style['fx'] === 'outline') {
            $kids .= '<a:ln w="12700"><a:solidFill>'.(Ooxml::color($this->theme, $colorSpec, $alphaMul) ?? '<a:schemeClr val="tx1"/>').'</a:solidFill></a:ln><a:noFill/>';
        } else {
            $kids .= Ooxml::solid($this->theme, $colorSpec, $alphaMul);
        }
        if ($style['fx'] === 'highlight') {
            $kids .= '<a:highlight>'.(Ooxml::color($this->theme, '@accent2', 0.45) ?? '').'</a:highlight>';
        }
        $font = $r['font'] ?? null;
        $typeface = $font !== null ? $this->typeface($font) : $this->typeface($style['fontSpec'] ?? $style['font']);
        $kids .= '<a:latin typeface="'.Ooxml::esc($typeface).'"/>';
        if ($r['link'] !== null) {
            $rid = ($this->linkRel)($r['link']);
            if ($rid !== null) {
                $kids .= '<a:hlinkClick r:id="'.$rid.'"'.(str_starts_with($r['link'], '#slide:') ? ' action="ppaction://hlinksldjump"' : '').'/>';
            }
        }

        return '<a:rPr'.$attrs.'>'.$kids.'</a:rPr>';
    }

    /** The theme font references keep a theme change working; a family name is written as is. */
    private function typeface(string $family): string
    {
        if ($family === $this->theme->heading && $family !== $this->theme->body) {
            return '+mj-lt';
        }
        if ($family === $this->theme->body) {
            return '+mn-lt';
        }

        return $family;
    }
}
