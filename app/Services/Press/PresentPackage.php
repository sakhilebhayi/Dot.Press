<?php

namespace App\Services\Press;

use App\Models\Asset;
use App\Models\Deck;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Builds the offline present package: one self contained HTML file that runs a presentation with no network.
 *
 * The file holds the document as JSON, every asset the slides use as a data URI (images are downscaled to the web
 * variant), the fonts the deck uses as base64 woff2 (only those families) and the standalone player bundle
 * (`npm run build` makes it, see vite.standalone.config.js).
 *
 * Nothing in the file reaches the network. Content that can only come from the network is replaced by a plain link
 * the audience can open on purpose: YouTube and Vimeo embeds, and images or videos that point at an address instead
 * of an uploaded file. A link mark on text stays a link, because the author chose it, but it loads nothing.
 */
class PresentPackage
{
    /** A single inlined file larger than this is left out and reported. */
    public const MAX_FILE_BYTES = 40 * 1024 * 1024;

    /** All inlined files together. */
    public const MAX_TOTAL_BYTES = 160 * 1024 * 1024;

    /** Images larger than this many pixels on the long side are scaled down when GD is available. */
    public const MAX_IMAGE_PX = 2560;

    public function __construct(
        private readonly ?string $bundleDir = null,
        private readonly ?string $fontsDir = null,
    ) {}

    /**
     * @return array{html: string, warnings: list<string>}
     */
    public function build(Deck $deck, bool $notes = true): array
    {
        $js = $this->readBundle('press-player.iife.js');
        $css = $this->readBundle('press-player.css');

        $warnings = [];
        $slides = PresentPayload::slides($deck, notes: $notes);
        $header = PresentPayload::deckHeader($deck);

        $slides = array_map(fn (array $slide): array => $this->stripExternal($slide), $slides);
        $assets = $this->inlineAssets($deck, $slides, $warnings);

        $fontCss = $this->fontCss($this->familiesUsed($header, $slides), $warnings);

        $data = ['deck' => $header, 'slides' => $slides, 'assets' => $assets, 'generated_at' => now()->toIso8601String()];
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        $title = htmlspecialchars((string) $deck->title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        // A script or style element ends at its first closing tag, so the bundle must never contain one.
        $js = str_replace('<!--', '<\\!--', str_ireplace('</script', '<\\/script', $js));
        $css = str_ireplace('</style', '<\\/style', $css);
        $fontCss = str_ireplace('</style', '<\\/style', $fontCss);

        $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data: blob:; media-src data: blob:; font-src data:; base-uri 'none'; form-action 'none'">
<title>{$title}</title>
<style>html,body{margin:0;background:#07080a}</style>
<style>{$fontCss}</style>
<style>{$css}</style>
</head>
<body>
<div id="press-app"></div>
<script type="application/json" id="press-data">{$json}</script>
<script>{$js}</script>
</body>
</html>
HTML;

        return ['html' => $html, 'warnings' => $warnings];
    }

    // ---- bundle -----------------------------------------------------------------------------------------------

    private function bundlePath(string $file): string
    {
        $dir = $this->bundleDir ?? base_path('public/build/standalone');

        return rtrim($dir, '/').'/'.$file;
    }

    private function readBundle(string $file): string
    {
        $path = $this->bundlePath($file);
        if (! is_file($path)) {
            throw new RuntimeException('The offline player has not been built on this server. Run "npm run build" and try again.');
        }

        return (string) file_get_contents($path);
    }

    // ---- external content -------------------------------------------------------------------------------------

    /**
     * Replace anything that needs the network with a plain, inert link. Geometry and id stay, so the layout holds.
     *
     * @param  array<string, mixed>  $slide
     * @return array<string, mixed>
     */
    public function stripExternal(array $slide): array
    {
        $els = [];
        foreach ($slide['els'] ?? [] as $el) {
            if (! is_array($el)) {
                continue;
            }
            $url = null;
            $label = null;
            if (($el['type'] ?? null) === 'video' && is_array($el['embed'] ?? null)) {
                $embed = $el['embed'];
                $id = (string) ($embed['id'] ?? '');
                if (($embed['provider'] ?? null) === 'youtube' && preg_match('/^[A-Za-z0-9_-]{6,20}$/', $id)) {
                    $url = 'https://www.youtube.com/watch?v='.$id;
                    $label = 'Video on YouTube';
                } elseif (($embed['provider'] ?? null) === 'vimeo' && preg_match('/^\d{4,12}$/', $id)) {
                    $url = 'https://vimeo.com/'.$id;
                    $label = 'Video on Vimeo';
                } else {
                    continue;
                }
            } elseif (in_array($el['type'] ?? null, ['image', 'video', 'audio'], true) && is_array($el['src'] ?? null) && isset($el['src']['url']) && ! isset($el['src']['asset'])) {
                $url = is_string($el['src']['url']) ? $el['src']['url'] : null;
                $label = match ($el['type']) {
                    'image' => 'Image',
                    'audio' => 'Audio',
                    default => 'Video',
                };
                if ($url === null || ! str_starts_with($url, 'https://')) {
                    continue;
                }
            }

            if ($url !== null) {
                $els[] = $this->linkElement($el, $label, $url);

                continue;
            }

            // A poster that is not an uploaded file cannot be inlined either.
            if (isset($el['poster']) && is_array($el['poster']) && ! isset($el['poster']['asset'])) {
                unset($el['poster']);
            }
            $els[] = $el;
        }
        $slide['els'] = $els;

        $bg = $slide['bg'] ?? null;
        if (is_array($bg) && isset($bg['img']) && is_array($bg['img']) && ! isset($bg['img']['asset'])) {
            $slide['bg'] = null;
        }

        return $slide;
    }

    /** @param  array<string, mixed>  $el */
    private function linkElement(array $el, string $label, string $url): array
    {
        $size = (int) max(18, min(40, (float) ($el['h'] ?? 60) * 0.18));

        return [
            'id' => $el['id'] ?? 'link',
            'type' => 'text',
            'x' => $el['x'] ?? 0,
            'y' => $el['y'] ?? 0,
            'w' => $el['w'] ?? 400,
            'h' => $el['h'] ?? 80,
            'rot' => $el['rot'] ?? 0,
            'font' => '@body',
            'size' => $size,
            'align' => 'center',
            'valign' => 'middle',
            'fill' => '@surface',
            'pad' => 16,
            'radius' => 8,
            'doc' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => $label.' (not included offline): '],
                        ['type' => 'text', 'text' => $url, 'marks' => [['type' => 'link', 'attrs' => ['href' => $url]]]],
                    ],
                ]],
            ],
        ];
    }

    // ---- assets -----------------------------------------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $slides
     * @param  list<string>  $warnings
     * @return array<string, array{url: string, w: int|null, h: int|null, mime: string|null, name: string|null}>
     */
    private function inlineAssets(Deck $deck, array $slides, array &$warnings): array
    {
        $map = [];
        $total = 0;
        foreach (PresentPayload::assets($deck, PresentPayload::assetIds($slides)) as $asset) {
            $loaded = $this->readAsset($asset);
            if ($loaded === null) {
                $warnings[] = 'Asset "'.$asset->original_name.'" could not be read and was left out.';

                continue;
            }
            [$bytes, $mime] = $loaded;
            if (strlen($bytes) > self::MAX_FILE_BYTES || $total + strlen($bytes) > self::MAX_TOTAL_BYTES) {
                $warnings[] = 'Asset "'.$asset->original_name.'" is too large for an offline package and was left out.';

                continue;
            }
            $total += strlen($bytes);
            $map[(string) $asset->id] = [
                'url' => 'data:'.$mime.';base64,'.base64_encode($bytes),
                'w' => $asset->width ? (int) $asset->width : null,
                'h' => $asset->height ? (int) $asset->height : null,
                'mime' => $mime,
                'name' => $asset->original_name,
            ];
        }

        return $map;
    }

    /** @return array{0: string, 1: string}|null bytes and mime type */
    private function readAsset(Asset $asset): ?array
    {
        $disk = Storage::disk($asset->disk);
        $isImage = str_starts_with((string) $asset->mime_type, 'image/');
        $path = $isImage ? ($asset->web_path ?: $asset->path) : $asset->path;
        if (! $path || ! $disk->exists($path)) {
            return null;
        }
        $bytes = $disk->get($path);
        if ($bytes === null || $bytes === '') {
            return null;
        }
        $mime = $path === $asset->path ? (string) ($asset->mime_type ?: 'application/octet-stream') : ($disk->mimeType($path) ?: 'image/jpeg');
        if ($isImage) {
            [$bytes, $mime] = $this->downscale($bytes, $mime);
        }

        return [$bytes, $mime];
    }

    /**
     * Shrink a big raster image so the file stays a sensible size. Without GD, or for formats GD cannot write
     * back (svg, gif with animation), the bytes are used as they are.
     *
     * @return array{0: string, 1: string}
     */
    public function downscale(string $bytes, string $mime): array
    {
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || ! function_exists('imagecreatefromstring') || strlen($bytes) < 400 * 1024) {
            return [$bytes, $mime];
        }
        $info = @getimagesizefromstring($bytes);
        if (! $info || max($info[0], $info[1]) <= self::MAX_IMAGE_PX) {
            return [$bytes, $mime];
        }
        $src = @imagecreatefromstring($bytes);
        if (! $src) {
            return [$bytes, $mime];
        }
        $scale = self::MAX_IMAGE_PX / max($info[0], $info[1]);
        $w = max(1, (int) round($info[0] * $scale));
        $h = max(1, (int) round($info[1] * $scale));
        $dst = imagecreatetruecolor($w, $h);
        if ($mime !== 'image/jpeg') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $info[0], $info[1]);
        ob_start();
        $ok = match ($mime) {
            'image/png' => imagepng($dst, null, 7),
            'image/webp' => function_exists('imagewebp') && imagewebp($dst, null, 85),
            default => imagejpeg($dst, null, 86),
        };
        $out = (string) ob_get_clean();

        return $ok && $out !== '' && strlen($out) < strlen($bytes) ? [$out, $mime] : [$bytes, $mime];
    }

    // ---- fonts ------------------------------------------------------------------------------------------------

    /**
     * Family names a deck uses: the theme fonts, element fonts and font marks inside text documents.
     *
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $slides
     * @return list<string>
     */
    public function familiesUsed(array $header, array $slides): array
    {
        $theme = is_array($header['theme'] ?? null) ? $header['theme'] : [];
        $heading = $theme['t']['heading'] ?? 'Public Sans';
        $body = $theme['t']['body'] ?? 'Public Sans';
        $found = ['Public Sans' => true];
        $note = function ($name) use (&$found, $heading, $body): void {
            if ($name === '@heading') {
                $name = $heading;
            } elseif ($name === '@body') {
                $name = $body;
            }
            if (is_string($name) && $name !== '') {
                $found[$name] = true;
            }
        };
        $note($heading);
        $note($body);
        $walk = function ($node) use (&$walk, $note): void {
            if (! is_array($node)) {
                return;
            }
            foreach ($node as $key => $value) {
                if (($key === 'font' || $key === 'fontFamily') && is_string($value)) {
                    $note($value);
                } elseif (is_array($value)) {
                    $walk($value);
                }
            }
        };
        $walk(array_map(fn ($s) => $s['els'] ?? [], $slides));

        $known = array_keys($this->fontManifest());

        return array_values(array_filter(array_keys($found), fn ($f) => in_array($f, $known, true)));
    }

    /** @return array<string, list<array{file: string, css: string}>> family => faces */
    private function fontManifest(): array
    {
        static $cache = [];
        $dir = $this->fontsDir ?? public_path('fonts');
        if (isset($cache[$dir])) {
            return $cache[$dir];
        }
        $css = is_file($dir.'/fonts.css') ? (string) file_get_contents($dir.'/fonts.css') : '';
        $out = [];
        foreach (preg_split('/\r?\n/', $css) as $line) {
            if (preg_match("/font-family:'([^']+)'.*url\(([^)]+\.woff2)\)/", $line, $m)) {
                $out[$m[1]][] = ['file' => $m[2], 'css' => $line];
            }
        }

        return $cache[$dir] = $out;
    }

    /**
     * @param  list<string>  $families
     * @param  list<string>  $warnings
     */
    private function fontCss(array $families, array &$warnings): string
    {
        $dir = rtrim($this->fontsDir ?? public_path('fonts'), '/');
        $manifest = $this->fontManifest();
        $css = '';
        foreach ($families as $family) {
            foreach ($manifest[$family] ?? [] as $face) {
                $path = $dir.'/'.basename($face['file']);
                if (! is_file($path)) {
                    $warnings[] = 'Font file '.basename($face['file']).' is missing.';

                    continue;
                }
                $css .= str_replace('url('.$face['file'].')', 'url(data:font/woff2;base64,'.base64_encode((string) file_get_contents($path)).')', $face['css'])."\n";
            }
        }

        return $css;
    }
}
