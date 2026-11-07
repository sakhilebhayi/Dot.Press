<?php

namespace App\Services\Press\Export\Pdf;

use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Dompdf\Options;

/**
 * Installs the bundled open licence TTFs (resources/fonts/ttf, manifest.json) into DomPDF's writable font
 * directory the first time a family is needed. Later runs find the families already installed and cost nothing.
 */
final class FontRegistry
{
    public const FALLBACK_FAMILY = 'Public Sans';

    /** @var array<string, array<string, string>>|null */
    private static ?array $manifest = null;

    /** @var array<string, array{asc: float, desc: float}> */
    private static array $metrics = [];

    /** @var array<string, true> */
    private static array $installed = [];

    private static ?Dompdf $dompdf = null;

    public static function ttfDir(): string
    {
        return resource_path('fonts/ttf');
    }

    /** @return array<string, array<string, string>> family => style => file */
    public static function manifest(): array
    {
        if (self::$manifest === null) {
            $path = self::ttfDir().'/manifest.json';
            $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            self::$manifest = is_array($data['families'] ?? null) ? $data['families'] : [];
            self::$metrics = is_array($data['metrics'] ?? null) ? $data['metrics'] : [];
        }

        return self::$manifest;
    }

    /**
     * Ascent and descent of a family in em (hhea values), used to put a text baseline where a browser puts it.
     *
     * @return array{asc: float, desc: float}
     */
    public static function vmetrics(string $family): array
    {
        self::manifest();
        $name = self::canonical($family) ?? self::FALLBACK_FAMILY;
        $m = self::$metrics[$name] ?? ['asc' => 0.95, 'desc' => 0.225];

        return ['asc' => (float) $m['asc'], 'desc' => (float) $m['desc']];
    }

    /** The natural line height of a family in em (ascent plus descent): what "100 percent" line spacing means in PowerPoint. */
    public static function naturalLineHeight(string $family): float
    {
        if (self::canonical($family) === null) {
            return 1.2;
        }
        $m = self::vmetrics($family);

        return $m['asc'] + $m['desc'];
    }

    /**
     * DomPDF multiplies every line height by 1.1 x (ascent + descent) of the font. To get a line pitch of $lh x $size
     * (what a browser does) the CSS value has to be divided by that factor.
     */
    public static function lineHeightPx(string $family, float $lh, float $size): float
    {
        $m = self::vmetrics($family);

        return $lh * $size / (1.1 * ($m['asc'] + $m['desc']));
    }

    /**
     * How far (in em) DomPDF puts the first baseline of a line below where a browser would, for the given line height,
     * when the line height has been set with lineHeightPx. DomPDF's baseline sits at 0.8 x the line pitch from the top of the
     * line, a browser's at half the leading plus the ascent. The text is moved by the difference (negative moves it up).
     */
    public static function baselineShift(string $family, float $lh): float
    {
        $m = self::vmetrics($family);
        $h = $m['asc'] + $m['desc'];

        return (($lh - $h) / 2 + $m['asc']) - 0.8 * $lh;
    }

    /** The exact bundled family name for any capitalisation ("inter" to "Inter"), or null when the font is not bundled. */
    public static function canonical(string $family): ?string
    {
        $needle = strtolower(trim($family));
        foreach (array_keys(self::manifest()) as $name) {
            if (strtolower($name) === $needle) {
                return $name;
            }
        }

        return null;
    }

    /** The writable directory the fonts are installed into: the configured one, or a temporary one when that cannot be written. */
    public static function fontDir(): string
    {
        $dir = (string) config('press_export.font_dir');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (! is_dir($dir) || ! is_writable($dir)) {
            $dir = sys_get_temp_dir().'/press-fonts-'.substr(md5(__DIR__), 0, 8);
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }

        return $dir;
    }

    public static function options(): Options
    {
        $dir = self::fontDir();
        $options = new Options;
        $options->set('fontDir', $dir);
        $options->set('fontCache', $dir);
        $tmp = storage_path('app/press/tmp');
        if (! is_dir($tmp)) {
            @mkdir($tmp, 0775, true);
        }
        $options->set('tempDir', $tmp);
        $options->set('chroot', [self::ttfDir(), $tmp]);
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isFontSubsettingEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', self::FALLBACK_FAMILY);
        $options->set('dpi', 96);
        $options->set('defaultMediaType', 'print');

        return $options;
    }

    /** One Dompdf per process, used only for its FontMetrics (installing and measuring). */
    public static function dompdf(): Dompdf
    {
        return self::$dompdf ??= new Dompdf(self::options());
    }

    public static function metrics(): FontMetrics
    {
        return self::dompdf()->getFontMetrics();
    }

    /**
     * Make sure each family is installed. Unknown families are skipped (the stylesheet falls back to the default).
     *
     * @param  iterable<string>  $families
     * @return list<string> the canonical families now available
     */
    public static function ensure(iterable $families): array
    {
        $ready = [];
        $metrics = self::metrics();
        $lock = null;
        foreach ($families as $family) {
            $name = self::canonical((string) $family);
            if ($name === null) {
                continue;
            }
            if (! isset(self::$installed[$name])) {
                // Two exports installing fonts at once must not write the font list over each other.
                $lock ??= self::lock();
                foreach (self::manifest()[$name] as $style => $file) {
                    $path = self::ttfDir().'/'.$file;
                    if (! is_file($path)) {
                        continue;
                    }
                    $metrics->registerFont([
                        'family' => strtolower($name),
                        'weight' => str_contains($style, 'Bold') ? 'bold' : 'normal',
                        'style' => str_contains($style, 'Italic') ? 'italic' : 'normal',
                    ], 'file://'.$path);
                }
                self::$installed[$name] = true;
            }
            $ready[] = $name;
        }
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $ready;
    }

    /** @return resource|null */
    private static function lock()
    {
        $h = @fopen(self::fontDir().'/.install.lock', 'c');
        if ($h !== false) {
            @flock($h, LOCK_EX);
        }

        return $h !== false ? $h : null;
    }

    /** Test hook: forget what was installed in this process (for example after pointing font_dir elsewhere). */
    public static function reset(): void
    {
        self::$installed = [];
        self::$dompdf = null;
    }
}
