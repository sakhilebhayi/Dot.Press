<?php

namespace App\Services\Press\Export\Pdf;

use App\Services\Press\Export\Support\TextStyle;
use App\Services\Press\Export\Support\Theme;

/**
 * Converts text paragraphs (from TextDoc) to the small, safe subset of HTML and CSS that DomPDF lays out reliably.
 * Every piece of user text goes through htmlspecialchars; colours, fonts and sizes are rebuilt from validated values,
 * never copied through.
 */
final class RichTextHtml
{
    /** Characters the bundled fonts cover. Anything else is set in DejaVu Sans, which ships with DomPDF. */
    private const COVERED = '/[^\x{0}-\x{24F}\x{2000}-\x{206F}\x{20A0}-\x{20BF}\x{2190}-\x{21FF}\x{2212}\x{2260}\x{2264}\x{2265}\x{2248}\x{221E}\x{2713}\x{2717}\x{25CF}\x{25A0}\x{25B2}\x{25BC}\x{2605}\x{2122}\x{2116}]+/u';

    /**
     * @param  list<array<string, mixed>>  $paras
     * @param  array<string, mixed>  $style  from TextStyle::of
     */
    public static function render(array $paras, array $style, Theme $theme, float $scale = 1.0, float $opacity = 1.0): string
    {
        $html = '';
        $first = true;
        foreach ($paras as $p) {
            $size = $style['size'] * $scale;
            $css = 'margin:0;padding:0;text-align:'.$style['align'].';line-height:'.self::n(FontRegistry::lineHeightPx($style['font'], $style['lh'], $size)).'px;font-size:'.self::n($size).'px;';
            if (! $first) {
                $css .= 'margin-top:'.self::n($style['ps'] * $size).'px;';
            }
            $first = false;
            $inner = self::runs($p['runs'], $style, $theme, $scale, $opacity);
            if ($inner === '') {
                $inner = '&nbsp;';
            }
            if ($p['list'] !== null) {
                $ind = TextStyle::listIndent((int) $p['level'], $size);
                $marker = ! empty($p['cont']) ? '' : htmlspecialchars(TextStyle::marker($p['list'], (int) $p['n'], (int) $p['level']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $css .= 'position:relative;padding-left:'.self::n($ind['left']).'px;';
                $mcss = 'position:absolute;top:0;left:'.self::n($ind['left'] - $ind['marker']).'px;width:'.self::n($ind['marker']).'px;font-weight:'.($style['bold'] ? 'bold' : 'normal').';color:'.($theme->css($style['color'], $opacity) ?? '#000').';font-family:'.self::family($style['font']).';';
                $inner = ($marker !== '' ? '<div style="'.$mcss.'">'.$marker.'</div>' : '').$inner;
            }
            $html .= '<div style="'.$css.'">'.$inner.'</div>';
        }

        return $html;
    }

    /** @param list<array<string, mixed>> $runs */
    private static function runs(array $runs, array $style, Theme $theme, float $scale, float $opacity): string
    {
        $out = '';
        foreach ($runs as $r) {
            if (! empty($r['br'])) {
                $out .= '<br/>';

                continue;
            }
            $css = '';
            $bold = $r['b'] || $style['bold'];
            $css .= 'font-weight:'.($bold ? 'bold' : 'normal').';';
            if ($r['i']) {
                $css .= 'font-style:italic;';
            }
            $deco = trim(($r['u'] ? 'underline ' : '').($r['s'] ? 'line-through' : ''));
            if ($deco !== '') {
                $css .= 'text-decoration:'.$deco.';';
            }
            $colorSpec = $r['color'] ?? $style['color'];
            $color = $theme->css($colorSpec, $opacity) ?? $theme->css('@ink');
            if ($r['link'] !== null && $r['color'] === null) {
                $color = $theme->css('@accent') ?? $color;
                $deco = 'underline';
                $css .= 'text-decoration:underline;';
            }
            $css .= 'color:'.$color.';';
            if ($r['size'] !== null) {
                $css .= 'font-size:'.self::n($r['size'] * $scale).'px;';
            }
            $family = $r['font'] ? $theme->family($r['font']) : $style['font'];
            $css .= 'font-family:'.self::family($family).';';
            if ($style['ls'] != 0.0) {
                $css .= 'letter-spacing:'.self::n($style['ls'] * ($r['size'] ?? $style['size']) * $scale).'px;';
            }
            if ($style['fx'] === 'highlight') {
                $bg = $theme->css('@accent2', 0.45) ?? '#ffe08a';
                $css .= 'background-color:'.$bg.';';
            }
            $text = self::escapeWithFallback((string) $r['t']);
            $span = '<span style="'.$css.'">'.$text.'</span>';
            if ($r['link'] !== null) {
                $href = $r['link'];
                if (str_starts_with($href, '#slide:')) {
                    $href = '#s'.substr($href, 7);
                }
                $span = '<a href="'.htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" style="text-decoration:underline;color:'.$color.';">'.$span.'</a>';
            }
            $out .= $span;
        }

        return $out;
    }

    /** CSS font-family list: the family, the default family, then a generic. */
    public static function family(string $family): string
    {
        $fam = str_replace(['"', "'", ';', '\\'], '', $family);

        return "'".$fam."','Public Sans','DejaVu Sans',sans-serif";
    }

    private static function escapeWithFallback(string $text): string
    {
        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        preg_match_all(self::COVERED, $text, $m, PREG_OFFSET_CAPTURE);
        if (empty($m[0])) {
            return $esc($text);
        }
        $out = '';
        $pos = 0;
        foreach ($m[0] as [$chunk, $offset]) {
            $out .= $esc(substr($text, $pos, $offset - $pos));
            $out .= '<span style="font-family:\'DejaVu Sans\',sans-serif;">'.$esc($chunk).'</span>';
            $pos = $offset + strlen($chunk);
        }
        $out .= $esc(substr($text, $pos));

        return $out;
    }

    private static function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.') ?: '0';
    }
}
