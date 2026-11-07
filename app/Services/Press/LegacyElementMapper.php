<?php

namespace App\Services\Press;

use Illuminate\Support\Str;

/**
 * Converts one element of the old canvas (the flat Konva shape that Element::toCanvasElement returns: x y width height
 * rotation, fill stroke strokeWidth, fontSize textAlign richContent, assetId videoUrl and so on) into a spec element.
 *
 * Positions are kept. The old canvas rotated around the top left corner, the document rotates around the centre, so a
 * rotated element keeps its visual place by moving its x and y. Defaults that the old canvas applied at draw time (a
 * rectangle with no fill was light blue) are written into the new element so it looks the same.
 */
class LegacyElementMapper
{
    public function __construct(private readonly DocumentSanitizer $san = new DocumentSanitizer) {}

    /**
     * @param  array<string, mixed>  $legacy
     * @param  array<string, true>  $usedIds  ids already taken on this slide; the new id is added
     * @return array<string, mixed>|null null when the element holds nothing worth keeping
     */
    public function map(array $legacy, array &$usedIds): ?array
    {
        $type = (string) ($legacy['type'] ?? 'rect');

        $w = max(0.0, $this->number($legacy['width'] ?? null, $this->defaultWidth($type)) * $this->number($legacy['scaleX'] ?? null, 1.0));
        $h = max(0.0, $this->number($legacy['height'] ?? null, $this->defaultHeight($type)) * $this->number($legacy['scaleY'] ?? null, 1.0));
        $x = $this->number($legacy['x'] ?? null, 0.0);
        $y = $this->number($legacy['y'] ?? null, 0.0);
        $rot = $this->number($legacy['rotation'] ?? null, 0.0);

        $newH = in_array($type, ['line', 'arrow'], true) ? 0.0 : $h;
        $angle = deg2rad($rot);
        $cx = $x + ($w / 2) * cos($angle) - ($h / 2) * sin($angle);
        $cy = $y + ($w / 2) * sin($angle) + ($h / 2) * cos($angle);

        $el = [
            'id' => $this->uniqueId($legacy['id'] ?? null, $usedIds),
            'x' => round($cx - $w / 2, 2),
            'y' => round($cy - $newH / 2, 2),
            'w' => round($w, 2),
            'h' => round($newH, 2),
        ];
        if (abs($rot) > 0.001) {
            $el['rot'] = round($rot, 2);
        }
        if (is_string($legacy['name'] ?? null) && $legacy['name'] !== '' && $type !== 'icon') {
            $el['name'] = $legacy['name'];
        }
        if (! empty($legacy['locked'])) {
            $el['lock'] = true;
        }
        if (isset($legacy['opacity']) && is_numeric($legacy['opacity']) && (float) $legacy['opacity'] < 1) {
            $el['op'] = (float) $legacy['opacity'];
        }
        if ($shadow = $this->shadow($legacy)) {
            $el['shadow'] = $shadow;
        }

        $body = match ($type) {
            'text' => $this->text($legacy),
            'icon' => $this->icon($legacy),
            'image' => $this->image($legacy),
            'video' => $this->video($legacy),
            'rect' => $this->shape($legacy, 'rect', '#dbeafe', '#2563eb', 2, ['radius' => $this->number($legacy['radius'] ?? $legacy['cornerRadius'] ?? null, 8.0)]),
            'circle' => $this->shape($legacy, 'ellipse', '#fee2e2', '#dc2626', 2),
            'polygon' => $this->shape($legacy, 'triangle', '#dcfce7', '#15803d', 2),
            'group' => $this->shape($legacy, 'rect', '#eef2ff', '#4338ca', 2, ['dash' => 'dash', 'radius' => 10]),
            'line' => $this->stroke($legacy, 'line', '#0f172a'),
            'arrow' => $this->stroke($legacy, 'arrow', '#1d4ed8'),
            default => null,
        };

        if ($body === null) {
            return null;
        }

        try {
            return $this->san->element($el + $body);
        } catch (InvalidDocument) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $legacy
     * @return array<string, mixed>
     */
    private function text(array $legacy): array
    {
        $style = strtolower((string) ($legacy['fontStyle'] ?? 'normal'));
        $decoration = strtolower((string) ($legacy['textDecoration'] ?? ''));

        $marks = [];
        if (str_contains($style, 'italic')) {
            $marks[] = ['type' => 'italic'];
        }
        if (str_contains($decoration, 'underline')) {
            $marks[] = ['type' => 'underline'];
        }
        if (str_contains($decoration, 'line-through')) {
            $marks[] = ['type' => 'strike'];
        }

        $doc = is_array($legacy['richContent'] ?? null) && ($legacy['richContent']['type'] ?? null) === 'doc'
            ? $legacy['richContent']
            : $this->san->docFromText((string) ($legacy['text'] ?? ''));

        // A rich document that is empty while the plain text is not (an older save) falls back to the plain text.
        if ($this->san->plainText($doc) === '' && trim((string) ($legacy['text'] ?? '')) !== '') {
            $doc = $this->san->docFromText((string) $legacy['text']);
        }

        $out = [
            'type' => 'text',
            'doc' => $marks === [] ? $doc : $this->addMarks($doc, $marks),
            'font' => $this->fontFamily($legacy['fontFamily'] ?? null),
            'size' => $this->number($legacy['fontSize'] ?? null, 32.0),
            'weight' => str_contains($style, 'bold') ? 700 : 400,
            'color' => $this->colour($legacy['fill'] ?? null) ?? '#111827',
            'align' => in_array($legacy['textAlign'] ?? null, ['left', 'center', 'right', 'justify'], true) ? $legacy['textAlign'] : 'left',
            'valign' => 'top',
            'lh' => $this->number($legacy['lineHeight'] ?? null, 1.3),
            'pad' => $this->number($legacy['textIndent'] ?? null, 0.0),
        ];

        return $out;
    }

    /**
     * The old icon was a single glyph drawn as text, so it becomes a one-glyph text element: the look is exactly kept.
     *
     * @param  array<string, mixed>  $legacy
     * @return array<string, mixed>
     */
    private function icon(array $legacy): array
    {
        return [
            'type' => 'text',
            'doc' => $this->san->docFromText((string) ($legacy['icon'] ?? '★')),
            'font' => 'Arial',
            'size' => $this->number($legacy['fontSize'] ?? null, 72.0),
            'color' => $this->colour($legacy['fill'] ?? null) ?? '#111827',
            'align' => 'center',
            'valign' => 'middle',
            'lh' => 1,
            'alt' => 'Icon',
        ];
    }

    /**
     * @param  array<string, mixed>  $legacy
     * @return array<string, mixed>
     */
    private function image(array $legacy): array
    {
        $out = ['type' => 'image'];
        $asset = $legacy['assetId'] ?? null;
        if (is_numeric($asset) && (int) $asset > 0) {
            $out['src'] = ['asset' => (int) $asset];
        }
        $out['fit'] = in_array($legacy['objectFit'] ?? null, ['cover', 'contain', 'fill'], true) ? $legacy['objectFit'] : 'contain';
        if ($fill = $this->colour($legacy['fill'] ?? null)) {
            $out['fill'] = $fill;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $legacy
     * @return array<string, mixed>|null
     */
    private function video(array $legacy): ?array
    {
        $out = [
            'type' => 'video',
            'autoplay' => (bool) ($legacy['videoAutoplay'] ?? false),
            'muted' => (bool) ($legacy['videoMuted'] ?? true),
            'loop' => (bool) ($legacy['videoLoop'] ?? false),
            'controls' => true,
        ];

        $asset = $legacy['assetId'] ?? null;
        $url = trim((string) ($legacy['videoUrl'] ?? ''));

        if (is_numeric($asset) && (int) $asset > 0) {
            $out['src'] = ['asset' => (int) $asset];

            return $out;
        }
        if ($url === '') {
            return null;
        }
        if ($embed = $this->embed($url)) {
            $out['embed'] = $embed;

            return $out;
        }
        $out['src'] = ['url' => $url];

        return $out;
    }

    /**
     * @return array{provider: string, id: string}|null
     */
    private function embed(string $url): ?array
    {
        if (preg_match('~(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{6,20})~i', $url, $m)) {
            return ['provider' => 'youtube', 'id' => $m[1]];
        }
        if (preg_match('~vimeo\.com/(?:video/)?(\d{4,12})~i', $url, $m)) {
            return ['provider' => 'vimeo', 'id' => $m[1]];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $legacy
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function shape(array $legacy, string $kind, string $fill, string $stroke, int $sw, array $extra = []): array
    {
        return [
            'type' => 'shape',
            'kind' => $kind,
            'fill' => $this->colour($legacy['fill'] ?? null) ?? $fill,
            'stroke' => $this->colour($legacy['stroke'] ?? null) ?? $stroke,
            'sw' => $this->number($legacy['strokeWidth'] ?? null, (float) $sw),
        ] + $extra;
    }

    /**
     * @param  array<string, mixed>  $legacy
     * @return array<string, mixed>
     */
    private function stroke(array $legacy, string $kind, string $default): array
    {
        $out = [
            'type' => 'shape',
            'kind' => $kind,
            'stroke' => $this->colour($legacy['stroke'] ?? null) ?? $default,
            'sw' => $this->number($legacy['strokeWidth'] ?? null, 4.0),
        ];
        if ($kind === 'arrow') {
            $out['ends'] = ['s' => 'none', 'e' => 'arrow'];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $legacy
     * @return array<string, mixed>|null
     */
    private function shadow(array $legacy): ?array
    {
        $colour = $this->colour($legacy['shadowColor'] ?? null);
        if ($colour === null || (float) ($legacy['shadowBlur'] ?? 0) <= 0) {
            return null;
        }

        // The old canvas drew shadows at half opacity.
        return [
            'x' => $this->number($legacy['shadowOffsetX'] ?? null, 0.0),
            'y' => $this->number($legacy['shadowOffsetY'] ?? null, 0.0),
            'blur' => $this->number($legacy['shadowBlur'] ?? null, 0.0),
            'c' => strlen($colour) === 7 ? $colour.'80' : $colour,
        ];
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  list<array<string, string>>  $marks
     * @return array<string, mixed>
     */
    private function addMarks(array $doc, array $marks): array
    {
        if (($doc['type'] ?? null) === 'text') {
            $have = array_column($doc['marks'] ?? [], 'type');
            foreach ($marks as $mark) {
                if (! in_array($mark['type'], $have, true)) {
                    $doc['marks'][] = $mark;
                }
            }

            return $doc;
        }
        if (isset($doc['content']) && is_array($doc['content'])) {
            $doc['content'] = array_map(fn ($child) => is_array($child) ? $this->addMarks($child, $marks) : $child, $doc['content']);
        }

        return $doc;
    }

    private function fontFamily(mixed $family): string
    {
        return is_string($family) && preg_match('/^[A-Za-z0-9][A-Za-z0-9 _.\-]{0,59}$/', trim($family)) ? trim($family) : 'Arial';
    }

    private function colour(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $value = trim($value);

        if (preg_match('/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*([\d.]+)\s*)?\)$/i', $value, $m)) {
            $hex = sprintf('#%02x%02x%02x', min(255, (int) $m[1]), min(255, (int) $m[2]), min(255, (int) $m[3]));

            return isset($m[4]) && (float) $m[4] < 1 ? $hex.sprintf('%02x', (int) round(max(0, (float) $m[4]) * 255)) : $hex;
        }

        try {
            return $this->san->colour($value);
        } catch (InvalidDocument) {
            return null;
        }
    }

    private function number(mixed $value, float $default): float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : $default;
    }

    private function defaultWidth(string $type): float
    {
        return match ($type) {
            'text' => 280, 'image' => 320, 'group' => 320, 'circle' => 180, 'line', 'arrow' => 260, 'polygon' => 220, 'icon' => 96, 'video' => 420, default => 220,
        };
    }

    private function defaultHeight(string $type): float
    {
        return match ($type) {
            'text' => 84, 'image' => 220, 'group' => 180, 'circle' => 180, 'line' => 18, 'arrow' => 30, 'polygon' => 180, 'icon' => 96, 'video' => 240, default => 120,
        };
    }

    /**
     * A usable, unique element id: the old client id when it fits the pattern, a cleaned copy when it nearly does.
     *
     * @param  array<string, true>  $used
     */
    private function uniqueId(mixed $legacyId, array &$used): string
    {
        $base = is_scalar($legacyId) ? preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $legacyId) : '';
        $base = substr((string) $base, 0, 32);
        if ($base === '') {
            $base = 'e'.Str::lower(Str::random(8));
        }

        $id = $base;
        $n = 2;
        while (isset($used[$id])) {
            $id = substr($base, 0, 36).'-'.$n++;
        }
        $used[$id] = true;

        return $id;
    }
}
