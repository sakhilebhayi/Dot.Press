<?php

namespace App\Services\Press\Export\Support;

/**
 * Reads the editor's icon set from resources/js/press/icons.js without running any JavaScript. It looks for
 * `name: 'path data'` or `name: '<path d="..."/>...'` entries (single, double or backtick quotes) and keeps the path
 * data per icon on a 24 x 24 grid. An icon the file does not name is unknown, and the exporters draw a labelled
 * placeholder square for it and say so.
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
                $paths[] = sprintf('M %1$s %2$s m -%3$s 0 a %3$s %3$s 0 1 0 %4$s 0 a %3$s %3$s 0 1 0 -%4$s 0', $cx, $cy, $r, $r * 2);
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
