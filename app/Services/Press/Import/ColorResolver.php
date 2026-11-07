<?php

namespace App\Services\Press\Import;

use DOMElement;

/**
 * Turns DrawingML colour elements into spec colours. Scheme colours without modifiers stay theme tokens (@accent, @ink,
 *
 * @bg ...) so a theme change still recolours them; anything with a lum/tint/shade/alpha modifier becomes a hex value.
 */
final class ColorResolver
{
    private const TOKENS = ['dk1' => '@ink', 'lt1' => '@bg', 'dk2' => '@muted', 'lt2' => '@surface', 'accent1' => '@accent', 'accent2' => '@accent2', 'accent3' => '@chart3', 'accent4' => '@chart4', 'accent5' => '@chart5', 'accent6' => '@chart6', 'hlink' => '@accent', 'folHlink' => '@muted'];

    private const PRESET = ['black' => '000000', 'white' => 'FFFFFF', 'red' => 'FF0000', 'green' => '008000', 'blue' => '0000FF', 'yellow' => 'FFFF00', 'gray' => '808080', 'grey' => '808080', 'orange' => 'FFA500', 'purple' => '800080', 'silver' => 'C0C0C0', 'cyan' => '00FFFF', 'magenta' => 'FF00FF', 'brown' => 'A52A2A', 'pink' => 'FFC0CB', 'maroon' => '800000', 'navy' => '000080', 'teal' => '008080', 'lime' => '00FF00', 'olive' => '808000', 'aqua' => '00FFFF', 'fuchsia' => 'FF00FF', 'darkBlue' => '00008B', 'darkRed' => '8B0000', 'darkGreen' => '006400', 'lightGray' => 'D3D3D3', 'darkGray' => 'A9A9A9', 'gold' => 'FFD700'];

    /** @param array<string, string> $scheme slot => hex without #, for dk1 lt1 dk2 lt2 accent1..6 hlink folHlink @param array<string, string> $clrMap */
    public function __construct(private array $scheme, private array $clrMap = [])
    {
        $this->clrMap += ['bg1' => 'lt1', 'tx1' => 'dk1', 'bg2' => 'lt2', 'tx2' => 'dk2', 'accent1' => 'accent1', 'accent2' => 'accent2', 'accent3' => 'accent3', 'accent4' => 'accent4', 'accent5' => 'accent5', 'accent6' => 'accent6', 'hlink' => 'hlink', 'folHlink' => 'folHlink'];
    }

    /** @param array<string, string> $map */
    public function withClrMap(array $map): static
    {
        $c = clone $this;
        $c->clrMap = $map + $this->clrMap;

        return $c;
    }

    /** The first colour element inside $holder (a:solidFill, a:fgClr, a:ln ...) as a spec colour. */
    public function spec(?DOMElement $holder, ?string $phClr = null): ?string
    {
        if ($holder === null) {
            return null;
        }
        foreach (Xml::children($holder) as $c) {
            if (in_array($c->localName, ['srgbClr', 'schemeClr', 'sysClr', 'prstClr', 'scrgbClr', 'hslClr'], true)) {
                return $this->fromElement($c, $phClr);
            }
        }

        return null;
    }

    public function fromElement(DOMElement $c, ?string $phClr = null): ?string
    {
        $mods = Xml::children($c);
        $hasMods = false;
        $alpha = 1.0;
        foreach ($mods as $m) {
            if ($m->localName === 'alpha') {
                $alpha = max(0.0, min(1.0, Xml::int($m, 'val', 100000) / 100000));
            } else {
                $hasMods = true;
            }
        }
        $token = null;
        switch ($c->localName) {
            case 'srgbClr':
                $hex = $this->hex6(Xml::attr($c, 'val', '000000'));
                break;
            case 'sysClr':
                $hex = $this->hex6(Xml::attr($c, 'lastClr') ?? (Xml::attr($c, 'val') === 'window' ? 'FFFFFF' : '000000'));
                break;
            case 'prstClr':
                $hex = self::PRESET[Xml::attr($c, 'val', 'black')] ?? '000000';
                break;
            case 'scrgbClr':
                $f = fn (string $k) => (int) round(max(0, min(1, Xml::int($c, $k, 0) / 100000)) * 255);
                $hex = sprintf('%02X%02X%02X', $f('r'), $f('g'), $f('b'));
                break;
            case 'hslClr':
                $hex = $this->hslToHex(Xml::int($c, 'hue') / 60000, Xml::int($c, 'sat') / 100000, Xml::int($c, 'lum') / 100000);
                break;
            default: // schemeClr
                $val = Xml::attr($c, 'val', 'tx1');
                if ($val === 'phClr') {
                    if ($phClr === null) {
                        return null;
                    }
                    if (! $hasMods && $alpha >= 0.999) {
                        return $phClr;
                    }
                    $hex = $this->hex6(ltrim($this->resolveSpec($phClr), '#'));
                    break;
                }
                $slot = $this->clrMap[$val] ?? $val;
                $hex = $this->scheme[$slot] ?? null;
                if ($hex === null) {
                    return null;
                }
                $token = self::TOKENS[$slot] ?? null;
        }
        if (! $hasMods && $alpha >= 0.999 && $token !== null) {
            return $token;
        }
        $hex = $this->applyMods($hex, $mods);

        return '#'.strtolower($hex).($alpha < 0.999 ? sprintf('%02x', (int) round($alpha * 255)) : '');
    }

    /** A spec colour (token or hex) back to six hex digits, for modifiers applied to a placeholder colour. */
    private function resolveSpec(string $spec): string
    {
        if (str_starts_with($spec, '@')) {
            foreach (self::TOKENS as $slot => $tok) {
                if ($tok === $spec && isset($this->scheme[$slot])) {
                    return $this->scheme[$slot];
                }
            }

            return '000000';
        }

        return substr($spec, 1, 6);
    }

    private function hex6(string $h): string
    {
        $h = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $h) ?? '');

        return strlen($h) === 6 ? $h : str_pad(substr($h, 0, 6), 6, '0');
    }

    /** @param list<DOMElement> $mods */
    private function applyMods(string $hex, array $mods): string
    {
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        foreach ($mods as $m) {
            $v = Xml::int($m, 'val', 0) / 100000;
            switch ($m->localName) {
                case 'lumMod':
                case 'lumOff':
                case 'lum':
                case 'satMod':
                case 'satOff':
                case 'sat':
                case 'hueMod':
                case 'hueOff':
                case 'hue':
                    [$h, $s, $l] = $this->rgbToHsl($r, $g, $b);
                    match ($m->localName) {
                        'lumMod' => $l *= $v,
                        'lumOff' => $l += $v,
                        'lum' => $l = $v,
                        'satMod' => $s *= $v,
                        'satOff' => $s += $v,
                        'sat' => $s = $v,
                        'hueMod' => $h *= $v,
                        'hueOff' => $h += Xml::int($m, 'val', 0) / 60000,
                        default => $h = Xml::int($m, 'val', 0) / 60000,
                    };
                    $h = fmod(fmod($h, 360) + 360, 360);
                    [$r, $g, $b] = $this->hslToRgb($h, max(0, min(1, $s)), max(0, min(1, $l)));
                    break;
                case 'tint':
                    $r = 255 - (255 - $r) * $v;
                    $g = 255 - (255 - $g) * $v;
                    $b = 255 - (255 - $b) * $v;
                    break;
                case 'shade':
                    $r *= $v;
                    $g *= $v;
                    $b *= $v;
                    break;
                case 'inv':
                    [$r, $g, $b] = [255 - $r, 255 - $g, 255 - $b];
                    break;
                case 'gray':
                    $y = 0.299 * $r + 0.587 * $g + 0.114 * $b;
                    [$r, $g, $b] = [$y, $y, $y];
                    break;
                case 'comp':
                    [$h, $s, $l] = $this->rgbToHsl($r, $g, $b);
                    [$r, $g, $b] = $this->hslToRgb(fmod($h + 180, 360), $s, $l);
                    break;
            }
        }

        return sprintf('%02X%02X%02X', (int) round(max(0, min(255, $r))), (int) round(max(0, min(255, $g))), (int) round(max(0, min(255, $b))));
    }

    /** @return array{0: float, 1: float, 2: float} */
    private function rgbToHsl(float $r, float $g, float $b): array
    {
        $r /= 255;
        $g /= 255;
        $b /= 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        if ($max == $min) {
            return [0.0, 0.0, $l];
        }
        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        $h = match (true) {
            $max == $r => (($g - $b) / $d + ($g < $b ? 6 : 0)),
            $max == $g => (($b - $r) / $d + 2),
            default => (($r - $g) / $d + 4),
        };

        return [$h * 60, $s, $l];
    }

    /** @return array{0: float, 1: float, 2: float} */
    private function hslToRgb(float $h, float $s, float $l): array
    {
        if ($s == 0) {
            return [$l * 255, $l * 255, $l * 255];
        }
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        $f = function (float $t) use ($p, $q): float {
            $t = fmod($t + 1, 1);
            if ($t < 1 / 6) {
                return $p + ($q - $p) * 6 * $t;
            }
            if ($t < 1 / 2) {
                return $q;
            }
            if ($t < 2 / 3) {
                return $p + ($q - $p) * (2 / 3 - $t) * 6;
            }

            return $p;
        };
        $h /= 360;

        return [$f($h + 1 / 3) * 255, $f($h) * 255, $f($h - 1 / 3) * 255];
    }

    private function hslToHex(float $h, float $s, float $l): string
    {
        [$r, $g, $b] = $this->hslToRgb($h, $s, $l);

        return sprintf('%02X%02X%02X', (int) round($r), (int) round($g), (int) round($b));
    }
}
