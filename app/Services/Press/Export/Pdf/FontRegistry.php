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
            self::$manifest = is_array($data) ? $data : [];
        }

        return self::$manifest;
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

    public static function options(): Options
    {
        $dir = (string) config('press_export.font_dir');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
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
        foreach ($families as $family) {
            $name = self::canonical((string) $family);
            if ($name === null) {
                continue;
            }
            if (! isset(self::$installed[$name])) {
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

        return $ready;
    }

    /** Test hook: forget what was installed in this process (for example after pointing font_dir elsewhere). */
    public static function reset(): void
    {
        self::$installed = [];
        self::$dompdf = null;
    }
}
