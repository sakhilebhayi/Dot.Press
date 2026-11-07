<?php

namespace Tests\Support;

use App\Services\Press\Export\ArrayAssetResolver;

/**
 * A document that uses every element type, for the exporter and importer tests and for looking at real output.
 */
final class PressSampleDeck
{
    public static function doc(int $w = 1920, int $h = 1080): array
    {
        $doc = fn (array $paras) => ['type' => 'doc', 'content' => $paras];
        $p = fn (string $t, array $marks = []) => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $t] + ($marks ? ['marks' => $marks] : [])]];

        $slides = [];
        $slides[] = [
            'id' => 's1', 'title' => 'Title slide', 'notes' => "Open warm.\nName the three asks.", 'section_id' => 'sec1', 'hidden' => false, 'layout' => 'title',
            'bg' => ['g' => 'linear', 'a' => 135, 'stops' => [[0, '#0b1f4d'], [1, '#2563eb']]], 'transition' => ['type' => 'fade', 'dir' => 'l', 'dur' => 600, 'ease' => 'out'], 'rev' => 1,
            'els' => [
                ['id' => 'e1', 'type' => 'text', 'role' => 'title', 'x' => 120, 'y' => 300, 'w' => 1500, 'h' => 240, 'font' => '@heading', 'size' => 120, 'weight' => 700, 'color' => '#ffffff', 'lh' => 1.05, 'ls' => -0.01,
                    'doc' => $doc([$p('Quarterly review, Q3')])],
                ['id' => 'e2', 'type' => 'text', 'role' => 'subtitle', 'x' => 120, 'y' => 580, 'w' => 1200, 'h' => 120, 'size' => 44, 'color' => '#dbe7ff', 'doc' => $doc([$p('What moved, what stalled, what we do next')])],
                ['id' => 'e3', 'type' => 'shape', 'kind' => 'rect', 'x' => 120, 'y' => 250, 'w' => 160, 'h' => 12, 'fill' => '@accent2'],
            ],
        ];
        $slides[] = [
            'id' => 's2', 'title' => 'Bullets', 'notes' => '', 'section_id' => 'sec1', 'hidden' => false, 'layout' => 'bullets', 'bg' => null, 'transition' => ['type' => 'push', 'dir' => 'l', 'dur' => 500], 'rev' => 1,
            'els' => [
                ['id' => 'b1', 'type' => 'text', 'role' => 'title', 'x' => 100, 'y' => 80, 'w' => 1700, 'h' => 140, 'font' => '@heading', 'size' => 72, 'weight' => 700, 'doc' => $doc([$p('Three things to know')])],
                ['id' => 'b2', 'type' => 'text', 'role' => 'body', 'x' => 100, 'y' => 260, 'w' => 1000, 'h' => 600, 'size' => 40, 'lh' => 1.3, 'ps' => 0.4, 'doc' => $doc([
                    ['type' => 'bulletList', 'content' => [
                        ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Revenue grew ', 'marks' => []], ['type' => 'text', 'text' => '18%', 'marks' => [['type' => 'bold']]], ['type' => 'text', 'text' => ' quarter on quarter']]]]],
                        ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Churn fell to 2.1%, the lowest in two years', 'marks' => [['type' => 'italic']]]]],
                            ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Driven by onboarding changes']]]]]]]]],
                        ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Read the ', 'marks' => []], ['type' => 'text', 'text' => 'full report', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com/report']]]]]]]],
                    ]],
                    ['type' => 'orderedList', 'content' => [
                        ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Ship the pricing page']]]]],
                        ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hire two engineers', 'marks' => [['type' => 'textStyle', 'attrs' => ['color' => '#b91c1c']]]]]]]],
                    ]],
                ])],
                ['id' => 'b3', 'type' => 'image', 'x' => 1200, 'y' => 260, 'w' => 600, 'h' => 600, 'src' => ['asset' => 1], 'fit' => 'cover', 'mask' => 'circle', 'alt' => 'A test picture', 'shadow' => ['x' => 8, 'y' => 12, 'blur' => 24, 'c' => '#00000066']],
            ],
        ];
        $slides[] = [
            'id' => 's3', 'title' => 'Shapes', 'notes' => 'Shapes and icons.', 'section_id' => 'sec2', 'hidden' => false, 'layout' => 'blank', 'bg' => '@surface', 'transition' => ['type' => 'wipe', 'dir' => 'r', 'dur' => 700], 'rev' => 1,
            'els' => [
                ['id' => 'h1', 'type' => 'shape', 'kind' => 'rect', 'x' => 100, 'y' => 100, 'w' => 300, 'h' => 200, 'fill' => '@accent', 'radius' => 24],
                ['id' => 'h2', 'type' => 'shape', 'kind' => 'ellipse', 'x' => 450, 'y' => 100, 'w' => 300, 'h' => 200, 'fill' => ['g' => 'linear', 'a' => 90, 'stops' => [[0, '#f59e0b'], [1, '#db2777']]], 'stroke' => '@ink', 'sw' => 4],
                ['id' => 'h3', 'type' => 'shape', 'kind' => 'triangle', 'x' => 800, 'y' => 100, 'w' => 240, 'h' => 200, 'fill' => '@good', 'rot' => 12],
                ['id' => 'h4', 'type' => 'shape', 'kind' => 'star', 'x' => 1100, 'y' => 100, 'w' => 220, 'h' => 220, 'fill' => '@accent2', 'op' => 0.8],
                ['id' => 'h5', 'type' => 'shape', 'kind' => 'hexagon', 'x' => 1380, 'y' => 100, 'w' => 260, 'h' => 220, 'fill' => '#7c3aed'],
                ['id' => 'h6', 'type' => 'shape', 'kind' => 'chevron', 'x' => 100, 'y' => 380, 'w' => 300, 'h' => 140, 'fill' => '@bad'],
                ['id' => 'h7', 'type' => 'shape', 'kind' => 'arrow-r', 'x' => 450, 'y' => 380, 'w' => 300, 'h' => 140, 'fill' => '@accent'],
                ['id' => 'h8', 'type' => 'shape', 'kind' => 'callout', 'x' => 800, 'y' => 380, 'w' => 340, 'h' => 200, 'fill' => '#ffffff', 'stroke' => '@ink', 'sw' => 3,
                    'doc' => $doc([$p('Say this out loud')]), 'size' => 32, 'color' => '@ink'],
                ['id' => 'h9', 'type' => 'shape', 'kind' => 'heart', 'x' => 1200, 'y' => 380, 'w' => 180, 'h' => 160, 'fill' => '#e11d48'],
                ['id' => 'h10', 'type' => 'shape', 'kind' => 'line', 'x' => 100, 'y' => 650, 'w' => 500, 'h' => 0, 'stroke' => '@ink', 'sw' => 6, 'dash' => 'dash'],
                ['id' => 'h11', 'type' => 'shape', 'kind' => 'arrow', 'x' => 700, 'y' => 620, 'w' => 400, 'h' => 100, 'stroke' => '@accent', 'sw' => 6, 'ends' => ['s' => 'dot', 'e' => 'arrow']],
                ['id' => 'h12', 'type' => 'shape', 'kind' => 'cross', 'x' => 1250, 'y' => 620, 'w' => 120, 'h' => 120, 'fill' => '@ink'],
                ['id' => 'h13', 'type' => 'icon', 'name' => 'check', 'x' => 1450, 'y' => 620, 'w' => 120, 'h' => 120, 'color' => '@good', 'sw' => 2],
                ['id' => 'h14', 'type' => 'shape', 'kind' => 'diamond', 'x' => 100, 'y' => 800, 'w' => 200, 'h' => 200, 'fill' => '@accent'],
                ['id' => 'h15', 'type' => 'shape', 'kind' => 'pentagon', 'x' => 350, 'y' => 800, 'w' => 200, 'h' => 200, 'fill' => '@accent2'],
                ['id' => 'h16', 'type' => 'shape', 'kind' => 'arrow-l', 'x' => 600, 'y' => 830, 'w' => 300, 'h' => 120, 'fill' => '@muted'],
            ],
        ];
        $slides[] = [
            'id' => 's4', 'title' => 'Charts', 'notes' => '', 'section_id' => 'sec2', 'hidden' => false, 'layout' => 'data', 'bg' => null, 'transition' => null, 'rev' => 1,
            'els' => [
                ['id' => 'c1', 'type' => 'chart', 'x' => 80, 'y' => 80, 'w' => 880, 'h' => 460, 'chart' => [
                    'type' => 'column', 'data' => ['categories' => ['Q1', 'Q2', 'Q3', 'Q4'], 'series' => [['name' => 'Revenue', 'values' => [120, 180, 150, 240]], ['name' => 'Cost', 'values' => [80, 90, 110, 130]]]],
                    'opt' => ['title' => 'Revenue and cost', 'legend' => 'bottom', 'labels' => 'value', 'grid' => true, 'ax' => ['y' => ['title' => 'Rand (thousands)', 'show' => true], 'x' => ['show' => true]]]]],
                ['id' => 'c2', 'type' => 'chart', 'x' => 1000, 'y' => 80, 'w' => 840, 'h' => 460, 'chart' => [
                    'type' => 'line', 'data' => ['categories' => ['Jan', 'Feb', 'Mar', 'Apr', 'May'], 'series' => [['name' => 'Users', 'values' => [10, 14, 13, 21, 25]], ['name' => 'Trials', 'values' => [6, 9, 12, 11, 17]]]],
                    'opt' => ['title' => 'Growth', 'legend' => 'top', 'grid' => true, 'smooth' => false]]],
                ['id' => 'c3', 'type' => 'chart', 'x' => 80, 'y' => 580, 'w' => 500, 'h' => 440, 'chart' => [
                    'type' => 'pie', 'data' => ['categories' => ['North', 'South', 'East', 'West'], 'series' => [['name' => 'Share', 'values' => [40, 25, 20, 15]]]], 'opt' => ['legend' => 'right', 'labels' => 'percent']]],
                ['id' => 'c4', 'type' => 'chart', 'x' => 620, 'y' => 580, 'w' => 600, 'h' => 440, 'chart' => [
                    'type' => 'doughnut', 'data' => ['categories' => ['A', 'B', 'C'], 'series' => [['name' => 'Mix', 'values' => [50, 30, 20]]]], 'opt' => ['legend' => 'bottom', 'labels' => 'percent']]],
                ['id' => 'c5', 'type' => 'chart', 'x' => 1260, 'y' => 580, 'w' => 580, 'h' => 440, 'chart' => [
                    'type' => 'kpi', 'data' => ['kpis' => [['label' => 'MRR', 'value' => 48200, 'delta' => 0.12, 'unit' => 'R'], ['label' => 'Churn', 'value' => 2.1, 'delta' => -0.3, 'unit' => '%']]], 'opt' => []]],
            ],
        ];
        $slides[] = [
            'id' => 's5', 'title' => 'Table and media', 'notes' => 'Hidden backup.', 'section_id' => 'sec2', 'hidden' => true, 'layout' => 'data', 'bg' => null, 'transition' => null, 'rev' => 1,
            'els' => [
                ['id' => 't1', 'type' => 'table', 'x' => 100, 'y' => 100, 'w' => 1100, 'h' => 400, 'header' => true, 'band' => true, 'lines' => 'rows', 'size' => 28, 'colw' => [0.4, 0.3, 0.3], 'rowh' => [80, 80, 80, 80, 80], 'rows' => [
                    [['t' => 'Region', 'b' => true], ['t' => 'Q2', 'b' => true, 'al' => 'r'], ['t' => 'Q3', 'b' => true, 'al' => 'r']],
                    [['t' => 'North'], ['t' => '120', 'al' => 'r'], ['t' => '150', 'al' => 'r']],
                    [['t' => 'South', 'fill' => '#fff3cd'], ['t' => '90', 'al' => 'r'], ['t' => '110', 'al' => 'r']],
                    [['t' => 'Merged label', 'span' => [2, 1]], ['t' => '70', 'al' => 'r'], ['t' => '85', 'al' => 'r']],
                    [['t' => ''], ['t' => '30', 'al' => 'r'], ['t' => '40', 'al' => 'r']],
                ]],
                ['id' => 'v1', 'type' => 'video', 'x' => 1300, 'y' => 100, 'w' => 500, 'h' => 280, 'src' => ['url' => 'https://example.com/v.mp4'], 'embed' => ['provider' => 'youtube', 'id' => 'dQw4w9WgXcQ'], 'poster' => ['asset' => 1]],
                ['id' => 'a1', 'type' => 'audio', 'x' => 1300, 'y' => 420, 'w' => 500, 'h' => 80, 'src' => ['asset' => 9], 'controls' => true],
            ],
        ];

        return [
            'deck' => ['id' => 1, 'title' => 'Sample deck', 'w' => $w, 'h' => $h, 'theme' => null, 'rev' => 3, 'status' => 'draft', 'visibility' => 'private',
                'sections' => [['id' => 'sec1', 'title' => 'Opening'], ['id' => 'sec2', 'title' => 'Detail']], 'slide_order' => array_column($slides, 'id')],
            'slides' => $slides,
        ];
    }

    /** A resolver holding a gradient JPEG as asset 1 and a transparent PNG as asset 2. */
    public static function assets(): ArrayAssetResolver
    {
        $r = new ArrayAssetResolver;
        $im = imagecreatetruecolor(800, 600);
        for ($y = 0; $y < 600; $y++) {
            for ($x = 0; $x < 800; $x++) {
                imagesetpixel($im, $x, $y, imagecolorallocate($im, (int) ($x / 800 * 255), (int) ($y / 600 * 200), 120 + (($x + $y) % 40)));
            }
        }
        $white = imagecolorallocate($im, 255, 255, 255);
        imagefilledellipse($im, 400, 300, 300, 300, $white);
        imagestring($im, 5, 380, 292, 'TEST', imagecolorallocate($im, 0, 0, 0));
        ob_start();
        imagejpeg($im, null, 90);
        $r->add(1, (string) ob_get_clean(), 'image/jpeg', 800, 600, 'photo.jpg');

        $png = imagecreatetruecolor(200, 200);
        imagealphablending($png, false);
        imagesavealpha($png, true);
        imagefill($png, 0, 0, imagecolorallocatealpha($png, 0, 0, 0, 127));
        imagefilledellipse($png, 100, 100, 180, 180, imagecolorallocatealpha($png, 220, 38, 38, 20));
        ob_start();
        imagepng($png);
        $r->add(2, (string) ob_get_clean(), 'image/png', 200, 200, 'dot.png');

        return $r;
    }
}
