<?php

namespace App\Services\Press\Export\Support;

/**
 * Reads the editor's icon set from resources/js/press/icons.js without running any JavaScript.
 *
 * The file lists icons as `I('id', 'Label', ['tags'], 'path', 'path', circle(cx, cy, r), box(x, y, w, h, r))` on a
 * 24 x 24 grid; the two helpers are evaluated here exactly as the JavaScript does. As a fallback it also reads plain
 * `name: 'path data'` or `name: '<path d="..."/>'` entries. An icon the file does not name is unknown, and the exporters
 * draw a placeholder square for it and say so.
 */
final class IconLibrary
{
    /** @var array<string, list<string>>|null name => path data strings */
    private ?array $icons = null;

    public function __construct(private ?string $file = null)
    {
        $this->file ??= resource_path('js/press/icons.js');
    }

    public function has(string $name): bool
    {
        return isset($this->load()[$name]);
    }

    /** @return list<string>|null SVG path data strings on a 24 grid */
    public function paths(string $name): ?array
    {
        return $this->load()[$name] ?? null;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->load());
    }

    /** Complete SVG for the icon at the given pixel size, stroked in $hex. Null when the icon is unknown. */
    public function svg(string $name, string $hex, float $strokeWidth, float $w, float $h): ?string
    {
        $paths = $this->paths($name);
        if ($paths === null) {
            return null;
        }
        $body = '';
        foreach ($paths as $d) {
            $body .= '<path d="'.htmlspecialchars($d, ENT_QUOTES).'" fill="none" stroke="'.$hex.'" stroke-width="'.$strokeWidth.'" stroke-linecap="round" stroke-linejoin="round"/>';
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$w.'" height="'.$h.'" viewBox="0 0 24 24">'.$body.'</svg>';
    }

    /**
     * The icon drawn at w by h in its own coordinates (viewBox 0 0 w h), scaled evenly and centred like an SVG viewBox
     * would. PDF renderers that ignore the viewBox of a differently sized <svg> (DomPDF draws it at 24 by 24) get the
     * right size from this form.
     */
    public function svgFitted(string $name, string $hex, float $strokeWidth, float $w, float $h): ?string
    {
        $paths = $this->paths($name);
        if ($paths === null) {
            return null;
        }
        $k = max(0.01, min($w, $h) / 24);
        $tx = round(($w - 24 * $k) / 2, 3);
        $ty = round(($h - 24 * $k) / 2, 3);
        $body = '';
        foreach ($paths as $d) {
            $body .= '<path d="'.htmlspecialchars($d, ENT_QUOTES).'" fill="none" stroke="'.$hex.'" stroke-width="'.$strokeWidth.'" stroke-linecap="round" stroke-linejoin="round"/>';
        }
        $w = round($w, 3);
        $h = round($h, 3);

        return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'"><g transform="translate('.$tx.' '.$ty.') scale('.round($k, 5).')">'.$body.'</g></svg>';
    }

    /** @return array<string, list<string>> */
    private function load(): array
    {
        if ($this->icons !== null) {
            return $this->icons;
        }
        $this->icons = [];
        if (! is_file($this->file) || filesize($this->file) > 2_000_000) {
            return $this->icons;
        }
        $src = (string) file_get_contents($this->file);
        $this->readCalls($src);
        if ($this->icons !== []) {
            return $this->icons;
        }
        if (preg_match_all('/[\'"]?([A-Za-z][A-Za-z0-9_-]{0,40})[\'"]?\s*:\s*([\'"`])((?:\\\\.|(?!\2).)*)\2/s', $src, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $paths = self::extractPaths($hit[3]);
                if ($paths !== []) {
                    $this->icons[$hit[1]] = $paths;
                }
            }
        }

        return $this->icons;
    }

    /** The `I('id', label, tags, ...paths)` form. */
    private function readCalls(string $src): void
    {
        if (! preg_match_all('/^[ \t]*I\(\s*([\'"])([A-Za-z][A-Za-z0-9_-]{0,40})\1\s*,/m', $src, $m, PREG_OFFSET_CAPTURE)) {
            return;
        }
        foreach ($m[0] as $i => [$text, $offset]) {
            $open = strpos($src, '(', $offset);
            if ($open === false) {
                continue;
            }
            $args = self::splitArgs($src, $open);
            if ($args === null || count($args) < 4) {
                continue;
            }
            $paths = [];
            foreach (array_slice($args, 3) as $arg) {
                $d = self::evaluate(trim($arg));
                if ($d !== null && $d !== '') {
                    $paths[] = $d;
                }
            }
            if ($paths !== []) {
                $this->icons[$m[2][$i][0]] = $paths;
            }
        }
    }

    /** The top level arguments of the call whose "(" is at $open. @return list<string>|null */
    private static function splitArgs(string $src, int $open): ?array
    {
        $depth = 0;
        $args = [];
        $cur = '';
        $quote = null;
        $len = strlen($src);
        for ($i = $open; $i < $len && $i < $open + 4000; $i++) {
            $ch = $src[$i];
            if ($quote !== null) {
                $cur .= $ch;
                if ($ch === '\\' && $i + 1 < $len) {
                    $cur .= $src[++$i];
                } elseif ($ch === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
                $cur .= $ch;

                continue;
            }
            if ($ch === '(' || $ch === '[') {
                if (++$depth > 1) {
                    $cur .= $ch;
                }

                continue;
            }
            if ($ch === ')' || $ch === ']') {
                if (--$depth === 0) {
                    $args[] = $cur;

                    return $args;
                }
                $cur .= $ch;

                continue;
            }
            if ($ch === ',' && $depth === 1) {
                $args[] = $cur;
                $cur = '';

                continue;
            }
            $cur .= $ch;
        }

        return null;
    }

    /** A string literal, or circle(...) and box(...) evaluated like the JavaScript helpers. */
    private static function evaluate(string $arg): ?string
    {
        if ($arg === '') {
            return null;
        }
        if (preg_match('/^([\'"`])(.*)\1$/s', $arg, $mm)) {
            return $mm[2];
        }
        if (! preg_match('/^(circle|box)\(\s*([^)]*)\)$/', $arg, $mm)) {
            return null;
        }
        $n = array_map(fn ($x) => (float) trim($x), explode(',', $mm[2]));
        $f = static fn (float $v): string => rtrim(rtrim(sprintf('%.10F', round($v, 10)), '0'), '.') ?: '0';
        $r2 = static fn (float $v): float => round($v, 2);
        if ($mm[1] === 'circle' && count($n) === 3) {
            [$cx, $cy, $r] = $n;

            return 'M'.$f($r2($cx - $r)).' '.$f($cy).'a'.$f($r).' '.$f($r).' 0 1 0 '.$f($r2(2 * $r)).' 0a'.$f($r).' '.$f($r).' 0 1 0 '.$f($r2(-2 * $r)).' 0Z';
        }
        if ($mm[1] === 'box' && count($n) >= 4) {
            [$x, $y, $w, $h] = $n;
            $r = $n[4] ?? 0.0;
            if ($r == 0.0) {
                return 'M'.$f($x).' '.$f($y).'h'.$f($w).'v'.$f($h).'h'.$f(-$w).'Z';
            }

            return 'M'.$f($x + $r).' '.$f($y).'h'.$f($w - 2 * $r).'a'.$f($r).' '.$f($r).' 0 0 1 '.$f($r).' '.$f($r).'v'.$f($h - 2 * $r).'a'.$f($r).' '.$f($r).' 0 0 1 '.$f(-$r).' '.$f($r).'h'.$f(-($w - 2 * $r)).'a'.$f($r).' '.$f($r).' 0 0 1 '.$f(-$r).' '.$f(-$r).'v'.$f(-($h - 2 * $r)).'a'.$f($r).' '.$f($r).' 0 0 1 '.$f($r).' '.$f(-$r).'Z';
        }

        return null;
    }

    /** @return list<string> */
    private static function extractPaths(string $value): array
    {
        $value = trim($value);
        if (preg_match('/^[Mm][\s\d.,eE+\-A-Za-z]*$/', $value) && strlen($value) > 4) {
            return [$value];
        }
        $paths = [];
        if (preg_match_all('/<path\b[^>]*\sd\s*=\s*"([^"]+)"/i', $value, $mm)) {
            foreach ($mm[1] as $d) {
                $paths[] = $d;
            }
        }
        if (preg_match_all('/<circle\b[^>]*?\scx="([\d.\-]+)"[^>]*?\scy="([\d.\-]+)"[^>]*?\sr="([\d.\-]+)"/i', $value, $mm, PREG_SET_ORDER)) {
            foreach ($mm as [, $cx, $cy, $r]) {
                $paths[] = sprintf('M %1$s %2$s m -%3$s 0 a %3$s %3$s 0 1 0 %4$s 0 a %3$s %3$s 0 1 0 -%4$s 0', $cx, $cy, $r, (float) $r * 2);
            }
        }
        if (preg_match_all('/<line\b[^>]*?\sx1="([\d.\-]+)"[^>]*?\sy1="([\d.\-]+)"[^>]*?\sx2="([\d.\-]+)"[^>]*?\sy2="([\d.\-]+)"/i', $value, $mm, PREG_SET_ORDER)) {
            foreach ($mm as [, $x1, $y1, $x2, $y2]) {
                $paths[] = "M $x1 $y1 L $x2 $y2";
            }
        }
        if (preg_match_all('/<(polyline|polygon)\b[^>]*?\spoints="([^"]+)"/i', $value, $mm, PREG_SET_ORDER)) {
            foreach ($mm as [, $tag, $pts]) {
                $nums = preg_split('/[\s,]+/', trim($pts)) ?: [];
                $d = '';
                for ($i = 0; $i + 1 < count($nums); $i += 2) {
                    $d .= ($i === 0 ? 'M ' : ' L ').$nums[$i].' '.$nums[$i + 1];
                }
                $paths[] = $d.(strtolower($tag) === 'polygon' ? ' Z' : '');
            }
        }
        if (preg_match_all('/<rect\b[^>]*?\sx="([\d.\-]+)"[^>]*?\sy="([\d.\-]+)"[^>]*?\swidth="([\d.\-]+)"[^>]*?\sheight="([\d.\-]+)"/i', $value, $mm, PREG_SET_ORDER)) {
            foreach ($mm as [, $x, $y, $w, $h]) {
                $paths[] = sprintf('M %1$s %2$s h %3$s v %4$s h -%3$s Z', $x, $y, $w, $h);
            }
        }

        return $paths;
    }
}
