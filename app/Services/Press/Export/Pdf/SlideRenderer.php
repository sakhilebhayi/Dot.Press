<?php

namespace App\Services\Press\Export\Pdf;

use App\Services\Press\Export\AssetResolver;
use App\Services\Press\Export\Support\Els;
use App\Services\Press\Export\Support\IconLibrary;
use App\Services\Press\Export\Support\ImageComposer;
use App\Services\Press\Export\Support\Raster;
use App\Services\Press\Export\Support\ShapeGeometry;
use App\Services\Press\Export\Support\TableGrid;
use App\Services\Press\Export\Support\TextDoc;
use App\Services\Press\Export\Support\TextMeasurer;
use App\Services\Press\Export\Support\TextStyle;
use App\Services\Press\Export\Support\Theme;

/**
 * Builds the HTML of one slide for DomPDF. Every element is an absolutely positioned box; text is real text,
 * shapes and charts are generated SVG, gradients and masked pictures are bitmaps prepared with GD.
 */
final class SlideRenderer
{
    /** @var list<array{slide: int, element: ?string, note: string}> things the PDF could not draw as the editor does */
    public array $notes = [];

    /** @var array<string, array{bytes: string, mime: string, ext: string}|null> */
    private array $composeCache = [];

    private int $slideIndex = 0;

    /** @var array<string, string> gradient PNGs by their parameters */
    private array $gradientCache = [];

    public function __construct(
        private Theme $theme,
        private AssetResolver $assets,
        private IconLibrary $icons,
    ) {}

    /**
     * @param  array<string, mixed>  $slide
     * @return array{bg: string, body: string} background layers (inside the page box) and the elements
     */
    public function render(array $slide, int $index, float $W, float $H): array
    {
        $this->slideIndex = $index;
        $bg = $this->background($slide['bg'] ?? null, $W, $H);
        $body = '';
        foreach (Els::visible($slide) as $el) {
            $html = match ($el['type']) {
                'text' => $this->text($el),
                'shape' => $this->shape($el),
                'image' => $this->image($el),
                'video' => $this->video($el),
                'audio' => $this->audio($el),
                'icon' => $this->icon($el),
                'chart' => $this->chart($el),
                'table' => $this->table($el),
                default => '',
            };
            $body .= $html;
        }

        return ['bg' => $bg, 'body' => $body];
    }

    private function note(?string $element, string $note): void
    {
        $this->notes[] = ['slide' => $this->slideIndex, 'element' => $element, 'note' => $note];
    }

    // ------------------------------------------------------------------ backgrounds

    private function background(mixed $bg, float $W, float $H): string
    {
        $bg ??= $this->theme->bg ?? '@bg';
        if (is_string($bg)) {
            $css = $this->theme->css($bg) ?? $this->theme->css('@bg');

            return '<div style="position:absolute;left:0;top:0;width:'.self::n($W).'px;height:'.self::n($H).'px;background-color:'.$css.';"></div>';
        }
        if (is_array($bg) && Theme::isGradient($bg)) {
            return $this->gradientImg($bg, 0, 0, $W, $H, 'rect', 0);
        }
        if (is_array($bg) && isset($bg['img']) && is_array($bg['img'])) {
            $html = '<div style="position:absolute;left:0;top:0;width:'.self::n($W).'px;height:'.self::n($H).'px;background-color:'.$this->theme->css('@bg').';"></div>';
            $asset = Els::shortAssetId($bg['img']);
            $composed = $asset !== null ? $this->composed($asset, ['fit' => 'cover'], $W, $H) : null;
            if ($composed === null) {
                $this->note(null, 'The slide background image is not available, so the page shows the theme background.');

                return $html;
            }
            $html .= $this->imgTag($composed, 0, 0, $W, $H);
            $dim = Els::num($bg['dim'] ?? 0, 0, 0, 1);
            if ($dim > 0) {
                $html .= '<div style="position:absolute;left:0;top:0;width:'.self::n($W).'px;height:'.self::n($H).'px;background-color:rgba(0,0,0,'.self::n($dim).');"></div>';
            }

            return $html;
        }

        return '<div style="position:absolute;left:0;top:0;width:'.self::n($W).'px;height:'.self::n($H).'px;background-color:'.$this->theme->css('@bg').';"></div>';
    }

    // ------------------------------------------------------------------ common

    /** The absolutely positioned wrapper with rotation, opacity and link. */
    private function wrap(array $el, string $inner, ?float $extraH = null): string
    {
        $b = Els::box($el);
        $css = 'position:absolute;left:'.self::n($b['x']).'px;top:'.self::n($b['y']).'px;width:'.self::n($b['w']).'px;height:'.self::n($b['h'] + ($extraH ?? 0)).'px;';
        if (abs(fmod($b['rot'], 360)) > 0.001) {
            $css .= 'transform:rotate('.self::n($b['rot']).'deg);transform-origin:'.self::n($b['w'] / 2).'px '.self::n($b['h'] / 2).'px;';
        }
        if ($b['op'] < 0.999) {
            $css .= 'opacity:'.self::n($b['op']).';';
        }
        $link = Els::link($el['link'] ?? null);
        if ($link !== null) {
            $href = str_starts_with($link, '#slide:') ? '#s'.substr($link, 7) : $link;
            $inner = '<a href="'.htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" style="display:block;width:'.self::n($b['w']).'px;height:'.self::n($b['h']).'px;text-decoration:none;">'.$inner.'</a>';
        }

        return '<div style="'.$css.'">'.$inner.'</div>';
    }

    /** @param array{bytes: string, mime: string, ext?: string} $img */
    private function imgTag(array $img, float $x, float $y, float $w, float $h): string
    {
        return '<img src="data:'.$img['mime'].';base64,'.base64_encode($img['bytes']).'" width="'.self::n($w).'" height="'.self::n($h).'" style="position:absolute;left:'.self::n($x).'px;top:'.self::n($y).'px;width:'.self::n($w).'px;height:'.self::n($h).'px;"/>';
    }

    private function svgImg(string $svg, float $x, float $y, float $w, float $h): string
    {
        return '<img src="data:image/svg+xml;base64,'.base64_encode($svg).'" width="'.self::n($w).'" height="'.self::n($h).'" style="position:absolute;left:'.self::n($x).'px;top:'.self::n($y).'px;width:'.self::n($w).'px;height:'.self::n($h).'px;"/>';
    }

    /** A gradient painted with GD, clipped to the shape's outline. */
    private function gradientImg(array $fill, float $x, float $y, float $w, float $h, string $kind, float $radius): string
    {
        $stops = $this->theme->stops($fill);
        if ($stops === []) {
            return '';
        }
        $masked = ! ($kind === 'rect' && $radius <= 0);
        // A smooth gradient needs few pixels; an outline needs enough to look clean.
        [$pw, $ph, $d] = $masked ? Raster::pixelSize($w, $h, 1.5, 1400) : Raster::pixelSize($w, $h, 1.0, 640);
        $key = md5(json_encode([$stops, $fill['a'] ?? 180, $pw, $ph, $kind, round($radius, 1)]));
        if (! isset($this->gradientCache[$key])) {
            $im = Raster::gradient($pw, $ph, $stops, (float) ($fill['a'] ?? 180));
            if ($masked) {
                Raster::applyMask($im, Raster::shapeMask($kind, $pw, $ph, $radius * $d));
            }
            $this->gradientCache[$key] = Raster::png($im);
        }

        return $this->imgTag(['bytes' => $this->gradientCache[$key], 'mime' => 'image/png'], $x, $y, $w, $h);
    }

    /**
     * A soft shadow drawn as stacked copies of the outline: the offset is exact, the blur is approximated with
     * a few translucent layers (DomPDF has no blur).
     */
    private function shadowLayers(array $el, float $w, float $h, string $kind, float $radius): string
    {
        $s = $el['shadow'] ?? null;
        if (! is_array($s)) {
            return '';
        }
        $hex = $this->theme->hex($s['c'] ?? '#000000') ?? '#000000';
        $alpha = $this->theme->alpha($s['c'] ?? '#00000066');
        if (! isset($s['c'])) {
            $alpha = 0.3;
        }
        $dx = Els::num($s['x'] ?? 0, 0, -500, 500);
        $dy = Els::num($s['y'] ?? 0, 0, -500, 500);
        $blur = Els::num($s['blur'] ?? 0, 0, 0, 200);
        $k = 1 + (int) min(5, floor($blur / 4));
        $html = '';
        for ($i = $k; $i >= 1; $i--) {
            $grow = $blur * ($i / $k) / 2;
            $layerAlpha = $alpha / $k * ($k > 1 ? 1.2 : 1);
            if (in_array($kind, ['rect', 'round', 'ellipse'], true) || $kind === 'box') {
                $sh = '<svg xmlns="http://www.w3.org/2000/svg" width="'.self::n($w + 2 * $grow).'" height="'.self::n($h + 2 * $grow).'" viewBox="0 0 '.self::n($w + 2 * $grow).' '.self::n($h + 2 * $grow).'">';
                $r = min($radius + $grow, ($w + 2 * $grow) / 2, ($h + 2 * $grow) / 2);
                if ($kind === 'ellipse') {
                    $sh .= '<ellipse cx="'.self::n(($w + 2 * $grow) / 2).'" cy="'.self::n(($h + 2 * $grow) / 2).'" rx="'.self::n(($w + 2 * $grow) / 2).'" ry="'.self::n(($h + 2 * $grow) / 2).'" fill="'.$hex.'" fill-opacity="'.self::n($layerAlpha).'"/>';
                } else {
                    $sh .= '<rect x="0" y="0" width="'.self::n($w + 2 * $grow).'" height="'.self::n($h + 2 * $grow).'" rx="'.self::n($r).'" fill="'.$hex.'" fill-opacity="'.self::n($layerAlpha).'"/>';
                }
                $sh .= '</svg>';
            } else {
                $sh = ShapeSvg::render(array_merge($el, ['kind' => $kind]), $w, $h, $this->theme, ['color' => $hex, 'extraStroke' => 2 * $grow, 'fill' => true]);
                $sh = preg_replace('/(fill|stroke)="'.preg_quote($hex, '/').'"/', '$1="'.$hex.'" $1-opacity="'.self::n($layerAlpha).'"', $sh) ?? $sh;
            }
            $html .= $this->svgImg($sh, $dx - $grow, $dy - $grow, $w + 2 * $grow, $h + 2 * $grow);
        }

        return $html;
    }

    // ------------------------------------------------------------------ text

    /** @param array<string, mixed> $el */
    private function text(array $el): string
    {
        $b = Els::box($el);
        $style = TextStyle::of($el, $this->theme);
        $paras = TextDoc::paragraphs(is_array($el['doc'] ?? null) ? $el['doc'] : null, is_string($el['text'] ?? null) ? $el['text'] : null);
        $inner = '';
        $fill = $el['fill'] ?? null;
        $hasBox = $fill !== null || ($el['stroke'] ?? null) !== null;
        $radius = $style['radius'];
        $kind = $radius > 0 ? 'round' : 'rect';
        if ($hasBox) {
            $inner .= $this->shadowLayers($el, $b['w'], $b['h'], 'box', $radius);
            $inner .= $this->boxBackground($el, $b['w'], $b['h'], $kind, $radius);
        }

        $inner .= $this->textOverlay($paras, $style, 0, 0, $b['w'], $b['h'], $el);

        return $this->wrap($el, $inner);
    }

    /** Fill and outline of a text box, drawn the same way as a shape. */
    private function boxBackground(array $el, float $w, float $h, string $kind, float $radius): string
    {
        $shapeEl = ['kind' => $kind, 'fill' => $el['fill'] ?? null, 'stroke' => $el['stroke'] ?? null, 'sw' => $el['sw'] ?? null, 'radius' => $radius, 'dash' => $el['dash'] ?? 'solid'];
        $html = '';
        if (Theme::isGradient($shapeEl['fill'])) {
            $html .= $this->gradientImg($shapeEl['fill'], 0, 0, $w, $h, $radius > 0 ? 'round' : 'rect', $radius);
        }
        $svg = ShapeSvg::render($shapeEl, $w, $h, $this->theme);

        return $html.$this->svgImg($svg, 0, 0, $w, $h);
    }

    /**
     * The text inside a box: padding, shrink fit and vertical alignment.
     *
     * @param  list<array<string, mixed>>  $paras
     */
    private function textOverlay(array $paras, array $style, float $x, float $y, float $w, float $h, array $el): string
    {
        $pad = $style['pad'];
        $iw = max(1.0, $w - 2 * $pad);
        $ih = max(1.0, $h - 2 * $pad);
        $scale = 1.0;
        if ($style['fit'] === 'shrink') {
            $scale = TextMeasurer::fitScale($paras, $style, $iw, $ih);
        }
        $b = Els::box($el);
        $html = RichTextHtml::render($paras, $style, $this->theme, $scale, $b['op'] >= 0.999 ? 1.0 : 1.0);
        // DomPDF seats the first baseline lower in the line box than a browser does: move the text up to match the editor.
        $shift = FontRegistry::baselineShift($style['font'], $style['lh']) * $style['size'] * $scale;
        $pos = 'position:absolute;left:'.self::n($x + $pad).'px;top:'.self::n($y + $pad + $shift).'px;width:'.self::n($iw).'px;height:'.self::n($ih).'px;';
        if ($style['valign'] === 'top') {
            return '<div style="'.$pos.'">'.$html.'</div>';
        }
        $va = $style['valign'] === 'middle' ? 'middle' : 'bottom';

        return '<div style="'.$pos.'"><table cellspacing="0" cellpadding="0" style="width:'.self::n($iw).'px;height:'.self::n($ih).'px;border-collapse:collapse;table-layout:fixed;"><tr><td style="width:'.self::n($iw).'px;height:'.self::n($ih).'px;vertical-align:'.$va.';padding:0;">'.$html.'</td></tr></table></div>';
    }

    // ------------------------------------------------------------------ shapes

    /** @param array<string, mixed> $el */
    private function shape(array $el): string
    {
        $b = Els::box($el);
        $kind = in_array($el['kind'] ?? null, ShapeGeometry::KINDS, true) ? $el['kind'] : 'rect';
        $w = max(1.0, $b['w']);
        $h = max(1.0, $b['h']);
        $radius = Els::num($el['radius'] ?? ($kind === 'round' ? $this->theme->radius : 0), 0, 0, 5000);
        $inner = '';
        $isLine = $kind === 'line' || $kind === 'arrow';
        if (! $isLine) {
            $inner .= $this->shadowLayers($el, $w, $h, in_array($kind, ['rect', 'round'], true) ? 'box' : $kind, $radius);
            if (Theme::isGradient($el['fill'] ?? null)) {
                $inner .= $this->gradientImg($el['fill'], 0, 0, $w, $h, $kind === 'round' ? 'round' : $kind, $kind === 'round' ? ($radius ?: $this->theme->radius) : $radius);
            }
        }
        $inner .= $this->svgImg(ShapeSvg::render($el, $w, $h, $this->theme), 0, 0, $w, $h);
        if (is_array($el['doc'] ?? null)) {
            $style = TextStyle::of($el, $this->theme, ['align' => 'center', 'valign' => 'middle', 'pad' => 10]);
            $paras = TextDoc::paragraphs($el['doc']);
            [$tx, $ty, $tw, $th] = $this->textRect($kind, $w, $h);
            $inner .= $this->textOverlay($paras, $style, $tx, $ty, $tw, $th, $el);
        }

        return $this->wrap($el, $inner);
    }

    /** The part of a shape that holds text. @return array{0: float, 1: float, 2: float, 3: float} */
    private function textRect(string $kind, float $w, float $h): array
    {
        return match ($kind) {
            'ellipse' => [$w * 0.146, $h * 0.146, $w * 0.707, $h * 0.707],
            'diamond' => [$w * 0.25, $h * 0.25, $w * 0.5, $h * 0.5],
            'triangle' => [$w * 0.25, $h * 0.5, $w * 0.5, $h * 0.5],
            'hexagon' => [$w * 0.125, 0, $w * 0.75, $h],
            'pentagon', 'star' => [$w * 0.2, $h * 0.25, $w * 0.6, $h * 0.55],
            'heart' => [$w * 0.15, $h * 0.12, $w * 0.7, $h * 0.55],
            'cross' => [$w / 3, $h / 3, $w / 3, $h / 3],
            'callout' => [0, 0, $w, $h * ShapeGeometry::CALLOUT_BODY],
            'chevron' => [ShapeGeometry::chevronDepth($w, $h), 0, max(1.0, $w - 2 * ShapeGeometry::chevronDepth($w, $h)), $h],
            'arrow-r' => [0, $h * 0.25, max(1.0, $w - ShapeGeometry::arrowHead($w, $h)), $h * 0.5],
            'arrow-l' => [ShapeGeometry::arrowHead($w, $h), $h * 0.25, max(1.0, $w - ShapeGeometry::arrowHead($w, $h)), $h * 0.5],
            default => [0, 0, $w, $h],
        };
    }

    // ------------------------------------------------------------------ images

    /** @return array{bytes: string, mime: string, ext: string}|null */
    private function composed(int $assetId, array $el, float $w, float $h): ?array
    {
        $key = $assetId.'|'.json_encode([$el['crop'] ?? null, $el['fit'] ?? null, $el['mask'] ?? null, $el['adj'] ?? null, $el['flipH'] ?? null, $el['flipV'] ?? null, $el['fill'] ?? null]).'|'.round($w).'x'.round($h);
        if (array_key_exists($key, $this->composeCache)) {
            return $this->composeCache[$key];
        }
        $asset = $this->assets->find($assetId);
        if ($asset === null) {
            return $this->composeCache[$key] = null;
        }
        if ($asset['mime'] === 'image/svg+xml' || str_contains(substr($asset['bytes'], 0, 2048), '<svg')) {
            $svg = $asset['bytes'];
            if (preg_match('/<\s*(script|image|use|foreignObject)\b/i', $svg) || preg_match('/(href|src)\s*=\s*["\']\s*(https?:|file:|data:)/i', $svg)) {
                return $this->composeCache[$key] = null;
            }

            return $this->composeCache[$key] = ['bytes' => $svg, 'mime' => 'image/svg+xml', 'ext' => 'svg'];
        }
        $out = ImageComposer::compose($asset['bytes'], $el, $w, $h, $this->theme);

        return $this->composeCache[$key] = $out;
    }

    /** @param array<string, mixed> $el */
    private function image(array $el): string
    {
        $b = Els::box($el);
        $w = max(1.0, $b['w']);
        $h = max(1.0, $b['h']);
        $asset = Els::shortAssetId($el['src'] ?? null);
        $img = $asset !== null ? $this->composed($asset, $el, $w, $h) : null;
        $inner = '';
        $mask = $el['mask'] ?? 'none';
        $maskKind = $mask === 'round' ? 'round' : ($mask === 'circle' || $mask === 'ellipse' ? 'ellipse' : (in_array($mask, ['triangle', 'star', 'hexagon'], true) ? $mask : 'rect'));
        $inner .= $this->shadowLayers($el, $w, $h, in_array($maskKind, ['rect', 'round', 'ellipse'], true) ? ($maskKind === 'ellipse' ? 'ellipse' : 'box') : $maskKind, $maskKind === 'round' ? min($w, $h) * 0.12 : 0);
        if ($img === null) {
            $reason = $asset === null ? 'This image points at an external address, which the export does not download.' : 'This image file could not be read.';
            $this->note($el['id'] ?? null, $reason);
            $inner .= $this->missingBox($w, $h, 'Image');
        } elseif ($img['mime'] === 'image/svg+xml') {
            $dim = ImageComposer::svgDimensions($img['bytes']) ?? [(int) $w, (int) $h];
            $plan = ImageComposer::plan($dim[0], $dim[1], array_merge($el, ['fit' => ($el['fit'] ?? 'contain') === 'cover' ? 'contain' : ($el['fit'] ?? 'contain')]), $w, $h);
            [$dx, $dy, $dw, $dh] = $plan['dest'];
            if (($el['crop'] ?? null) || ($mask !== 'none') || ImageComposer::hasAdj($el['adj'] ?? null)) {
                $this->note($el['id'] ?? null, 'Crop, mask and adjustments are not applied to an SVG picture in the PDF.');
            }
            $inner .= $this->svgImgRaw($img['bytes'], $dx, $dy, $dw, $dh);
        } else {
            $inner .= $this->imgTag($img, 0, 0, $w, $h);
        }
        $extra = null;
        if (isset($el['cap']) && is_string($el['cap']) && trim($el['cap']) !== '') {
            $size = max(14.0, min(28.0, $w * 0.04));
            $cap = htmlspecialchars(mb_substr($el['cap'], 0, 300), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $inner .= '<div style="position:absolute;left:0;top:'.self::n($h + 8).'px;width:'.self::n($w).'px;font-size:'.self::n($size).'px;line-height:1.3;color:'.$this->theme->css('@muted').';font-family:'.RichTextHtml::family($this->theme->body).';">'.$cap.'</div>';
        }

        return $this->wrap($el, $inner, $extra);
    }

    private function svgImgRaw(string $svg, float $x, float $y, float $w, float $h): string
    {
        return $this->svgImg($svg, $x, $y, $w, $h);
    }

    private function missingBox(float $w, float $h, string $label): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.self::n($w).'" height="'.self::n($h).'" viewBox="0 0 '.self::n($w).' '.self::n($h).'"><rect x="1" y="1" width="'.self::n($w - 2).'" height="'.self::n($h - 2).'" fill="'.$this->theme->hex('@surface').'" stroke="'.$this->theme->hex('@line').'" stroke-width="2" stroke-dasharray="8,6"/></svg>';
        $size = max(12.0, min(28.0, min($w, $h) * 0.12));

        return $this->svgImg($svg, 0, 0, $w, $h).$this->labelDiv($label.' not available', 0, $h / 2 - $size, $w, $size * 1.4, $size, '@muted', 'center');
    }

    private function labelDiv(string $text, float $x, float $y, float $w, float $h, float $size, string $color, string $align = 'left', bool $bold = false): string
    {
        return '<div style="position:absolute;left:'.self::n($x).'px;top:'.self::n($y).'px;width:'.self::n($w).'px;height:'.self::n($h).'px;font-size:'.self::n($size).'px;line-height:1.3;text-align:'.$align.';color:'.$this->theme->css($color).';font-family:'.RichTextHtml::family($this->theme->body).';font-weight:'.($bold ? 'bold' : 'normal').';">'.htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</div>';
    }

    // ------------------------------------------------------------------ video, audio, icon

    /** @param array<string, mixed> $el */
    private function video(array $el): string
    {
        $b = Els::box($el);
        $w = max(1.0, $b['w']);
        $h = max(1.0, $b['h']);
        $poster = Els::shortAssetId($el['poster'] ?? null);
        $img = $poster !== null ? $this->composed($poster, ['fit' => 'cover'], $w, $h) : null;
        $label = 'Video';
        $url = null;
        if (is_array($el['embed'] ?? null) && isset($el['embed']['provider'], $el['embed']['id'])) {
            $id = preg_replace('/[^A-Za-z0-9_-]/', '', Els::str($el['embed']['id']));
            $url = match ($el['embed']['provider']) {
                'youtube' => 'https://www.youtube.com/watch?v='.$id,
                'vimeo' => 'https://vimeo.com/'.$id,
                default => null,
            };
            $label = 'Video on '.ucfirst(Els::str($el['embed']['provider']));
        } elseif (($u = Els::url($el['src'] ?? null)) !== null) {
            $url = $u;
        }
        $inner = '';
        if ($img !== null && $img['mime'] !== 'image/svg+xml') {
            $inner .= $this->imgTag($img, 0, 0, $w, $h);
        } else {
            $inner .= $this->svgImg('<svg xmlns="http://www.w3.org/2000/svg" width="'.self::n($w).'" height="'.self::n($h).'" viewBox="0 0 '.self::n($w).' '.self::n($h).'"><rect width="'.self::n($w).'" height="'.self::n($h).'" fill="'.$this->theme->hex('@ink').'"/></svg>', 0, 0, $w, $h);
        }
        $r = min($w, $h) * 0.14;
        $cx = $w / 2;
        $cy = $h / 2;
        $badge = '<svg xmlns="http://www.w3.org/2000/svg" width="'.self::n($r * 2).'" height="'.self::n($r * 2).'" viewBox="0 0 '.self::n($r * 2).' '.self::n($r * 2).'"><circle cx="'.self::n($r).'" cy="'.self::n($r).'" r="'.self::n($r).'" fill="#000000" fill-opacity="0.6"/><polygon points="'.self::n($r * 0.78).','.self::n($r * 0.6).' '.self::n($r * 1.4).','.self::n($r).' '.self::n($r * 0.78).','.self::n($r * 1.4).'" fill="#ffffff"/></svg>';
        $inner .= $this->svgImg($badge, $cx - $r, $cy - $r, $r * 2, $r * 2);
        $size = max(12.0, min(24.0, $h * 0.07));
        $inner .= $this->labelDiv($label.($img === null ? ' (not shown in a PDF)' : ''), 12, $h - $size * 1.8, $w - 24, $size * 1.4, $size, '#ffffff', 'left', true);
        $this->note($el['id'] ?? null, 'Video cannot play in a PDF, so the page shows '.($img === null ? 'a labelled box' : 'its poster frame').'.');
        if ($url !== null) {
            $el['link'] = $url;
        }

        return $this->wrap($el, $inner);
    }

    /** @param array<string, mixed> $el */
    private function audio(array $el): string
    {
        $b = Els::box($el);
        $w = max(1.0, $b['w']);
        $h = max(1.0, $b['h']);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.self::n($w).'" height="'.self::n($h).'" viewBox="0 0 '.self::n($w).' '.self::n($h).'"><rect x="1" y="1" width="'.self::n($w - 2).'" height="'.self::n($h - 2).'" rx="'.self::n(min($h / 2, 16)).'" fill="'.$this->theme->hex('@surface').'" stroke="'.$this->theme->hex('@line').'" stroke-width="2"/></svg>';
        $size = max(12.0, min(26.0, $h * 0.3));
        $inner = $this->svgImg($svg, 0, 0, $w, $h).$this->labelDiv('Audio clip (plays in the presentation)', 16, ($h - $size * 1.3) / 2, $w - 32, $size * 1.4, $size, '@ink');
        $this->note($el['id'] ?? null, 'Audio cannot play in a PDF, so the page shows a labelled box.');

        return $this->wrap($el, $inner);
    }

    /** @param array<string, mixed> $el */
    private function icon(array $el): string
    {
        $b = Els::box($el);
        $w = max(1.0, $b['w']);
        $h = max(1.0, $b['h']);
        $name = is_string($el['name'] ?? null) ? $el['name'] : '';
        $hex = $this->theme->hex($el['color'] ?? '@ink') ?? '#000000';
        $sw = Els::num($el['sw'] ?? 2, 2, 0.25, 8);
        $svg = $this->icons->svg($name, $hex, $sw, $w, $h);
        if ($svg === null) {
            $this->note($el['id'] ?? null, 'The icon "'.mb_substr($name, 0, 40).'" is not in the icon set the exporter reads, so a placeholder square is drawn.');
            $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.self::n($w).'" height="'.self::n($h).'" viewBox="0 0 '.self::n($w).' '.self::n($h).'"><rect x="'.self::n($w * 0.15).'" y="'.self::n($h * 0.15).'" width="'.self::n($w * 0.7).'" height="'.self::n($h * 0.7).'" fill="none" stroke="'.$hex.'" stroke-width="'.self::n(max(1, $sw)).'" stroke-dasharray="4,3"/></svg>';
        }

        return $this->wrap($el, $this->svgImg($svg, 0, 0, $w, $h));
    }

    // ------------------------------------------------------------------ chart

    /** @param array<string, mixed> $el */
    private function chart(array $el): string
    {
        $b = Els::box($el);
        $w = max(20.0, $b['w']);
        $h = max(20.0, $b['h']);
        $chart = is_array($el['chart'] ?? null) ? $el['chart'] : [];
        if (($chart['type'] ?? '') === 'map') {
            $this->note($el['id'] ?? null, 'A map chart is shown as a table of region values in the PDF.');

            return $this->wrap($el, $this->mapTable($chart, $w, $h));
        }
        $layers = ChartSvg::layers($chart, $w, $h, $this->theme);
        $inner = $this->svgImg($layers['svg'], 0, 0, $w, $h);
        foreach ($layers['texts'] as $t) {
            $inner .= $this->chartText($t);
        }

        return $this->wrap($el, $inner);
    }

    /** @param array<string, mixed> $t */
    private function chartText(array $t): string
    {
        $size = (float) $t['size'];
        $boxW = (float) ($t['maxw'] ?? 600);
        $anchor = $t['anchor'] ?? 'start';
        $left = match ($anchor) {
            'middle' => $t['x'] - $boxW / 2,
            'end' => $t['x'] - $boxW,
            default => $t['x'],
        };
        $m = FontRegistry::vmetrics($this->theme->body);
        // The line box is 1.25 x size tall and DomPDF puts the baseline at 0.88 x (ascent + descent) x line height.
        $top = $t['y'] - 0.88 * ($m['asc'] + $m['desc']) * 1.25 * $size;
        $css = 'position:absolute;white-space:nowrap;left:'.self::n($left).'px;top:'.self::n($top).'px;width:'.self::n($boxW).'px;font-size:'.self::n($size).'px;line-height:'.self::n($size * 1.25).'px;text-align:'.($anchor === 'middle' ? 'center' : ($anchor === 'end' ? 'right' : 'left')).';color:'.($this->theme->css($t['color'] ?? '@ink') ?? '#000').';font-family:'.RichTextHtml::family($this->theme->body).';font-weight:'.(! empty($t['bold']) ? 'bold' : 'normal').';';
        if (! empty($t['rot'])) {
            $ox = $anchor === 'middle' ? $boxW / 2 : ($anchor === 'end' ? $boxW : 0);
            $css .= 'transform:rotate('.self::n((float) $t['rot']).'deg);transform-origin:'.self::n($ox).'px '.self::n(($t['y'] - $size * 0.35) - $top).'px;';
        }

        return '<div style="'.$css.'">'.htmlspecialchars((string) $t['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</div>';
    }

    /** @param array<string, mixed> $chart */
    private function mapTable(array $chart, float $w, float $h): string
    {
        $rows = [[['t' => 'Region', 'b' => true], ['t' => 'Value', 'b' => true, 'al' => 'r']]];
        foreach (array_slice((array) ($chart['data']['regions'] ?? []), 0, 40) as $r) {
            if (is_array($r)) {
                $rows[] = [['t' => Els::str($r['code'] ?? null)], ['t' => Els::str($r['value'] ?? null), 'al' => 'r']];
            }
        }

        return $this->tableHtml(['rows' => $rows, 'colw' => [0.6, 0.4], 'header' => true, 'band' => true, 'lines' => 'rows', 'size' => max(12, min(22, $h / max(1, count($rows)) * 0.5)), 'w' => $w, 'h' => $h], $w, $h);
    }

    // ------------------------------------------------------------------ table

    /** @param array<string, mixed> $el */
    private function table(array $el): string
    {
        $b = Els::box($el);

        return $this->wrap($el, $this->tableHtml($el, max(1.0, $b['w']), max(1.0, $b['h'])));
    }

    /** @param array<string, mixed> $el */
    private function tableHtml(array $el, float $w, float $h): string
    {
        $grid = TableGrid::build($el + ['w' => $w, 'h' => $h]);
        $style = TextStyle::of($el, $this->theme, ['size' => 24]);
        $lines = in_array($el['lines'] ?? null, ['none', 'rows', 'grid'], true) ? $el['lines'] : 'rows';
        $header = ! empty($el['header']);
        $band = ! empty($el['band']);
        $line = $this->theme->css('@line');
        $pad = max(4.0, $style['size'] * 0.35);
        $byRow = [];
        foreach ($grid['cells'] as $c) {
            $byRow[$c['r']][] = $c;
        }
        $html = '<table cellspacing="0" cellpadding="0" style="position:absolute;left:0;top:0;width:'.self::n($w).'px;border-collapse:collapse;table-layout:fixed;font-family:'.RichTextHtml::family($style['font']).';font-size:'.self::n($style['size']).'px;">';
        foreach ($grid['colw'] as $cw) {
            $html .= '<col style="width:'.self::n($cw * $w).'px;"/>';
        }
        for ($r = 0; $r < $grid['rows']; $r++) {
            $isHead = $header && $r === 0;
            $html .= '<tr style="height:'.self::n($grid['rowh'][$r]).'px;">';
            foreach ($byRow[$r] ?? [] as $c) {
                $cell = $c['cell'];
                $bold = $isHead || ! empty($cell['b']) || $style['bold'];
                $fill = $cell['fill'] ?? null;
                if ($fill === null) {
                    if ($isHead) {
                        $fill = '@surface';
                    } elseif ($band && $r % 2 === ($header ? 0 : 1)) {
                        $fill = '@surface';
                    }
                }
                $bg = $fill !== null ? $this->theme->css($fill) : null;
                $color = $this->theme->css($cell['color'] ?? $style['color']);
                $al = match ($cell['al'] ?? 'l') {
                    'c' => 'center',
                    'r' => 'right',
                    default => 'left',
                };
                $border = '';
                if ($lines === 'grid') {
                    $border = 'border:1px solid '.$line.';';
                } elseif ($lines === 'rows') {
                    $border = 'border-bottom:'.($isHead ? '2px solid '.$this->theme->css('@ink') : '1px solid '.$line).';';
                } elseif ($isHead) {
                    $border = 'border-bottom:2px solid '.$this->theme->css('@ink').';';
                }
                $css = 'padding:'.self::n($pad).'px '.self::n($pad * 1.3).'px;vertical-align:middle;text-align:'.$al.';font-weight:'.($bold ? 'bold' : 'normal').';'.($bg !== null ? 'background-color:'.$bg.';' : '').'color:'.$color.';'.$border.(! empty($cell['i']) ? 'font-style:italic;' : '');
                $span = ($c['rs'] > 1 ? ' rowspan="'.$c['rs'].'"' : '').($c['cs'] > 1 ? ' colspan="'.$c['cs'].'"' : '');
                $html .= '<td'.$span.' style="'.$css.'">'.htmlspecialchars(Els::str($cell['t'] ?? null), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</table>';

        return $html;
    }

    private static function n(float $v): string
    {
        $s = number_format($v, 2, '.', '');

        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }
}
