<?php

namespace App\Services\Press\Export\Pptx;

use App\Services\Press\Export\Support\Els;
use App\Services\Press\Export\Support\Theme;

/** Small builders for DrawingML fragments: colours, fills, lines, escaping and EMU maths. */
final class Ooxml
{
    public const EMU = 9525;

    public const NS_A = 'http://schemas.openxmlformats.org/drawingml/2006/main';

    public const NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    public const NS_P = 'http://schemas.openxmlformats.org/presentationml/2006/main';

    public const NS_C = 'http://schemas.openxmlformats.org/drawingml/2006/chart';

    /** Largest slide dimension PowerPoint accepts (56 inches) in EMU. */
    public const MAX_EMU = 51206400;

    public const SCHEME = ['bg' => 'bg1', 'ink' => 'tx1', 'surface' => 'bg2', 'muted' => 'tx2', 'accent' => 'accent1', 'accent2' => 'accent2', 'chart3' => 'accent3', 'chart4' => 'accent4', 'chart5' => 'accent5', 'chart6' => 'accent6'];

    public static function emu(float $units): int
    {
        return (int) round($units * self::EMU);
    }

    /** XML text: escapes and drops characters XML 1.0 forbids. */
    public static function esc(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x{FFFE}\x{FFFF}]/u', '', $s) ?? '';

        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * A colour element. Theme tokens that have a slot in the exported theme stay scheme references (so a theme change
     * in PowerPoint recolours them); everything else is written as a hex value.
     */
    public static function color(Theme $theme, mixed $c, float $alphaMul = 1.0, bool $allowScheme = true): ?string
    {
        if (! is_string($c)) {
            return null;
        }
        $alpha = $theme->alpha($c) * $alphaMul;
        $alphaXml = $alpha < 0.999 ? '<a:alpha val="'.(int) round(max(0, $alpha) * 100000).'"/>' : '';
        if ($allowScheme && str_starts_with($c, '@') && isset(self::SCHEME[substr($c, 1)]) && $theme->isToken($c)) {
            $v = self::SCHEME[substr($c, 1)];

            return $alphaXml === '' ? '<a:schemeClr val="'.$v.'"/>' : '<a:schemeClr val="'.$v.'">'.$alphaXml.'</a:schemeClr>';
        }
        $hex = $theme->hex($c);
        if ($hex === null) {
            return null;
        }
        $v = strtoupper(substr($hex, 1));

        return $alphaXml === '' ? '<a:srgbClr val="'.$v.'"/>' : '<a:srgbClr val="'.$v.'">'.$alphaXml.'</a:srgbClr>';
    }

    public static function solid(Theme $theme, mixed $c, float $alphaMul = 1.0): string
    {
        $col = self::color($theme, $c, $alphaMul);

        return $col === null ? '<a:noFill/>' : '<a:solidFill>'.$col.'</a:solidFill>';
    }

    /** CSS angle (0 = to the top, clockwise) to the DrawingML linear angle (0 = to the right, clockwise), in 60000ths. */
    public static function linAngle(float $css): int
    {
        return (int) round(fmod(fmod($css - 90, 360) + 360, 360) * 60000);
    }

    /** A solid or gradient fill, or noFill for null. */
    public static function fill(Theme $theme, mixed $fill, float $alphaMul = 1.0): string
    {
        if (Theme::isGradient($fill)) {
            $gs = '';
            foreach ((array) $fill['stops'] as $s) {
                if (! is_array($s) || ! isset($s[0], $s[1])) {
                    continue;
                }
                $col = self::color($theme, $s[1], $alphaMul);
                if ($col === null) {
                    continue;
                }
                $gs .= '<a:gs pos="'.(int) round(max(0, min(1, (float) $s[0])) * 100000).'">'.$col.'</a:gs>';
            }
            if ($gs === '') {
                return '<a:noFill/>';
            }

            return '<a:gradFill rotWithShape="1"><a:gsLst>'.$gs.'</a:gsLst><a:lin ang="'.self::linAngle((float) ($fill['a'] ?? 180)).'" scaled="0"/></a:gradFill>';
        }

        return self::solid($theme, $fill, $alphaMul);
    }

    /**
     * An outline. $ends are the line ends for connectors.
     *
     * @param  array{s?: string, e?: string}|null  $ends
     */
    public static function line(Theme $theme, mixed $stroke, float $sw, string $dash = 'solid', float $alphaMul = 1.0, ?array $ends = null, bool $roundCap = false): string
    {
        $col = self::color($theme, $stroke, $alphaMul);
        if ($col === null || $sw <= 0) {
            return '<a:ln><a:noFill/></a:ln>';
        }
        $w = max(1, self::emu($sw * 1.0));
        $x = '<a:ln w="'.$w.'"'.($roundCap || $dash === 'dot' ? ' cap="rnd"' : '').'><a:solidFill>'.$col.'</a:solidFill>';
        if ($dash === 'dash') {
            $x .= '<a:prstDash val="dash"/>';
        } elseif ($dash === 'dot') {
            $x .= '<a:prstDash val="sysDot"/>';
        }
        $x .= '<a:round/>';
        if ($ends !== null) {
            $map = ['arrow' => 'triangle', 'dot' => 'oval'];
            if (isset($map[$ends['s'] ?? 'none'])) {
                $x .= '<a:headEnd type="'.$map[$ends['s']].'" w="med" len="med"/>';
            }
            if (isset($map[$ends['e'] ?? 'none'])) {
                $x .= '<a:tailEnd type="'.$map[$ends['e']].'" w="med" len="med"/>';
            }
        }

        return $x.'</a:ln>';
    }

    /** @param array<string, mixed>|null $shadow */
    public static function shadow(Theme $theme, ?array $shadow, float $alphaMul = 1.0): string
    {
        if (! is_array($shadow)) {
            return '';
        }
        $x = Els::num($shadow['x'] ?? 0, 0, -500, 500);
        $y = Els::num($shadow['y'] ?? 0, 0, -500, 500);
        $blur = Els::num($shadow['blur'] ?? 0, 0, 0, 300);
        $c = $shadow['c'] ?? '#00000066';
        $col = self::color($theme, $c, $alphaMul, false);
        if ($col === null) {
            return '';
        }
        $dist = hypot($x, $y);
        $dir = ($dist > 0 ? rad2deg(atan2($y, $x)) : 0);
        $dir = fmod($dir + 360, 360);

        return '<a:effectLst><a:outerShdw blurRad="'.self::emu($blur).'" dist="'.self::emu($dist).'" dir="'.(int) round($dir * 60000).'" algn="ctr" rotWithShape="0">'.$col.'</a:outerShdw></a:effectLst>';
    }

    /** xfrm for an element: offset, extent, rotation and flips. */
    public static function xfrm(float $x, float $y, float $w, float $h, float $rot = 0, bool $flipH = false, bool $flipV = false, string $tag = 'a:xfrm'): string
    {
        $attrs = '';
        $rot = fmod(fmod($rot, 360) + 360, 360);
        if ($rot > 0.0005) {
            $attrs .= ' rot="'.(int) round($rot * 60000).'"';
        }
        if ($flipH) {
            $attrs .= ' flipH="1"';
        }
        if ($flipV) {
            $attrs .= ' flipV="1"';
        }

        return '<'.$tag.$attrs.'><a:off x="'.self::emu($x).'" y="'.self::emu($y).'"/><a:ext cx="'.max(0, self::emu($w)).'" cy="'.max(0, self::emu($h)).'"/></'.$tag.'>';
    }

    public static function head(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n";
    }
}
