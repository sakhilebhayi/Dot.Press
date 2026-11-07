<?php

namespace App\Services\Press\Bench;

use App\Models\Asset;
use App\Models\Deck;
use App\Models\Project;
use App\Models\Slide;
use App\Models\User;
use App\Services\Press\ThemeLibrary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Builds large, realistic decks for the performance benchmark (php artisan press:bench) and for the query-count
 * regression tests. Deterministic: the same arguments always give the same content. Development tool only; nothing
 * in the application uses it.
 *
 * A generated slide carries 30 elements: 11 text boxes (titles, bullet lists, captions), 8 shapes, 4 images, 2 charts,
 * 2 tables and 3 icons, which is a dense slide by any standard.
 */
final class DeckGenerator
{
    public const ELEMENTS_PER_SLIDE = 30;

    /** @var list<int> */
    private array $assetIds;

    /**
     * @param  list<int>  $assetIds  images the generated slides point at (reused round robin, like a real deck reuses a logo)
     */
    public function __construct(array $assetIds = [1, 2, 3, 4])
    {
        $this->assetIds = array_values($assetIds) ?: [1];
    }

    /**
     * @return array<string, mixed>
     */
    public function slide(int $index, int $w = 1920, int $h = 1080): array
    {
        $els = [];
        $n = 0;
        $id = function () use (&$n, $index): string {
            return 's'.$index.'e'.(++$n);
        };

        // 11 text boxes
        $els[] = $this->text($id(), 'Quarter '.(($index % 4) + 1).': what the numbers say about slide '.$index, 80, 50, 1760, 120, 64, 'title');
        for ($i = 0; $i < 3; $i++) {
            $els[] = $this->bullets($id(), 80 + $i * 590, 200, 560, 260, $index + $i);
        }
        for ($i = 0; $i < 7; $i++) {
            $els[] = $this->text($id(), 'Caption '.$i.' for slide '.$index.': a short sentence that explains the figure next to it.', 80 + ($i % 4) * 440, 900 + intdiv($i, 4) * 70, 420, 60, 22, 'caption');
        }

        // 8 shapes
        $kinds = ['rect', 'round', 'ellipse', 'triangle', 'diamond', 'hexagon', 'star', 'chevron'];
        foreach ($kinds as $i => $kind) {
            $els[] = ['id' => $id(), 'type' => 'shape', 'kind' => $kind, 'x' => 100 + $i * 220, 'y' => 480 + ($i % 2) * 20, 'w' => 180, 'h' => 130, 'rot' => ($i * 7) % 30,
                'fill' => $i % 3 === 0 ? '@accent' : ($i % 3 === 1 ? '@accent2' : ['g' => 'linear', 'a' => 90, 'stops' => [[0, '#2563eb'], [1, '#7c3aed']]]),
                'stroke' => '@ink', 'sw' => 2, 'radius' => 16, 'op' => 0.9, 'name' => 'Shape '.$i, 'shadow' => ['x' => 0, 'y' => 6, 'blur' => 18, 'c' => '#00000033']];
        }

        // 4 images
        for ($i = 0; $i < 4; $i++) {
            $els[] = ['id' => $id(), 'type' => 'image', 'x' => 100 + $i * 440, 'y' => 650, 'w' => 400, 'h' => 230, 'src' => ['asset' => $this->assetIds[($index + $i) % count($this->assetIds)]],
                'fit' => 'cover', 'alt' => 'Photo '.$i.' on slide '.$index, 'crop' => ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1], 'adj' => ['br' => 0, 'ct' => 0.05, 'sat' => 0, 'blur' => 0, 'gray' => 0, 'sepia' => 0, 'hue' => 0]];
        }

        // 2 charts
        $els[] = $this->chart($id(), 'column', 1000, 480, 12, 3, $index);
        $els[] = $this->chart($id(), 'line', 1000, 700, 12, 3, $index + 1);

        // 2 tables
        $els[] = $this->table($id(), 1500, 200, 7, 5, $index);
        $els[] = $this->table($id(), 1500, 480, 5, 4, $index + 3);

        // 3 icons
        foreach (['check', 'plus', 'chevron-right'] as $i => $name) {
            $els[] = ['id' => $id(), 'type' => 'icon', 'name' => $name, 'x' => 1760, 'y' => 60 + $i * 70, 'w' => 56, 'h' => 56, 'color' => '@accent', 'sw' => 2];
        }

        return [
            'title' => 'Slide '.$index.': results',
            'notes' => 'Speaker notes for slide '.$index.'. Say the headline first, then the two supporting numbers, then ask for the decision.',
            'layout' => 'blank',
            'bg' => $index % 5 === 0 ? '@surface' : null,
            'transition' => $index % 3 === 0 ? ['type' => 'fade', 'dir' => 'l', 'dur' => 500, 'ease' => 'out'] : null,
            'els' => $els,
        ];
    }

    /**
     * Creates a deck with $slides generated slides straight in the tables (fast; no ops).
     */
    public function createDeck(Project $project, User $user, string $title, int $slides, int $w = 1920, int $h = 1080): Deck
    {
        $deck = Deck::create([
            'project_id' => $project->id, 'user_id' => $user->id, 'title' => $title, 'theme' => ThemeLibrary::find('slate'),
            'w' => $w, 'h' => $h, 'sections' => [['id' => 'sec1', 'title' => 'Opening'], ['id' => 'sec2', 'title' => 'Evidence']],
            'sort_order' => (int) $project->decks()->max('sort_order') + 1,
        ]);

        $rows = [];
        for ($i = 0; $i < $slides; $i++) {
            $s = $this->slide($i + 1, $w, $h);
            $rows[] = [
                'deck_id' => $deck->id, 'title' => $s['title'], 'notes' => $s['notes'], 'layout' => 'blank', 'sort_order' => $i, 'revision' => 0,
                'section_id' => $i < max(1, intdiv($slides, 5)) ? 'sec1' : 'sec2', 'hidden' => 0,
                'bg' => $s['bg'] === null ? null : json_encode($s['bg']), 'transition' => $s['transition'] === null ? null : json_encode($s['transition']),
                'els' => json_encode($s['els'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(), 'updated_at' => now(),
            ];
            if (count($rows) >= 50) {
                DB::table('slides')->insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            DB::table('slides')->insert($rows);
        }

        return $deck->fresh();
    }

    /**
     * Real, small JPEGs on the local disk plus asset rows, so exports and the signed URLs have something to read.
     *
     * @return list<int> asset ids
     */
    public static function createAssets(Project $project, User $user, int $count = 4, int $width = 800, int $height = 450): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $im = imagecreatetruecolor($width, $height);
            for ($y = 0; $y < $height; $y += 3) {
                imagefilledrectangle($im, 0, $y, $width, $y + 2, imagecolorallocate($im, (int) (($i * 40 + $y / $height * 180) % 255), (int) (60 + $y / 4 % 160), 140 + ($i * 11) % 100));
            }
            imagefilledellipse($im, (int) ($width / 2), (int) ($height / 2), (int) ($height * 0.7), (int) ($height * 0.7), imagecolorallocate($im, 250, 250, 250));
            ob_start();
            imagejpeg($im, null, 85);
            $bytes = (string) ob_get_clean();
            unset($im);

            $path = 'projects/'.$project->id.'/assets/bench-'.$i.'.jpg';
            Storage::disk('local')->put($path, $bytes);
            $ids[] = Asset::create([
                'project_id' => $project->id, 'uploaded_by' => $user->id, 'disk' => 'local', 'path' => $path, 'original_name' => 'bench-'.$i.'.jpg',
                'mime_type' => 'image/jpeg', 'size' => strlen($bytes), 'width' => $width, 'height' => $height, 'kind' => 'image', 'sha256' => hash('sha256', $bytes),
            ])->id;
        }

        return $ids;
    }

    /**
     * ops that add $count elements of about $bytesEach bytes each, spread over the given slide ids.
     *
     * @param  list<int>  $slideIds
     * @return list<array<string, mixed>>
     */
    public function addOps(array $slideIds, int $count, int $bytesEach, string $prefix = 'bulk'): array
    {
        $ops = [];
        for ($i = 0; $i < $count; $i++) {
            $rows = max(2, (int) round($bytesEach / 230));
            $ops[] = ['op' => 'el.add', 'slide' => $slideIds[$i % count($slideIds)], 'el' => $this->table($prefix.$i.'x'.substr(md5((string) $i.$prefix), 0, 6), 100 + ($i % 10) * 50, 100, $rows, 5, $i)];
        }

        return $ops;
    }

    /**
     * A drag of $count elements on one slide: one el.set each, as the editor sends after a multi-select move.
     *
     * @param  list<string>  $elementIds
     * @return list<array<string, mixed>>
     */
    public static function dragOps(int $slideId, array $elementIds, int $count, int $dx = 12): array
    {
        $ops = [];
        foreach (array_slice($elementIds, 0, $count) as $i => $elId) {
            $ops[] = ['op' => 'el.set', 'slide' => $slideId, 'id' => $elId, 'p' => ['x' => 100 + $i * 10 + $dx, 'y' => 120 + $i * 5 + $dx]];
        }

        return $ops;
    }

    // -------------------------------------------------------------------------------------------- element makers

    /** @return array<string, mixed> */
    private function text(string $id, string $text, int $x, int $y, int $w, int $h, int $size, string $role): array
    {
        return ['id' => $id, 'type' => 'text', 'role' => $role, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'font' => $role === 'title' ? '@heading' : '@body', 'size' => $size, 'weight' => $role === 'title' ? 700 : 400,
            'color' => '@ink', 'lh' => 1.2, 'align' => 'left', 'valign' => 'top',
            'doc' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]]];
    }

    /** @return array<string, mixed> */
    private function bullets(string $id, int $x, int $y, int $w, int $h, int $seed): array
    {
        $items = [];
        for ($i = 0; $i < 5; $i++) {
            $items[] = ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Point '.($seed + $i).' with '],
                ['type' => 'text', 'text' => 'a bold figure of '.(($seed * 7 + $i * 13) % 97).'%', 'marks' => [['type' => 'bold']]],
                ['type' => 'text', 'text' => ' and a sentence that carries a little more detail than a headline would.'],
            ]]]];
        }

        return ['id' => $id, 'type' => 'text', 'role' => 'body', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'font' => '@body', 'size' => 24, 'weight' => 400, 'color' => '@ink', 'lh' => 1.3, 'ps' => 0.3,
            'doc' => ['type' => 'doc', 'content' => [['type' => 'bulletList', 'content' => $items]]]];
    }

    /** @return array<string, mixed> */
    private function chart(string $id, string $type, int $x, int $y, int $cats, int $series, int $seed): array
    {
        $categories = [];
        for ($c = 0; $c < $cats; $c++) {
            $categories[] = 'M'.($c + 1);
        }
        $s = [];
        for ($k = 0; $k < $series; $k++) {
            $values = [];
            for ($c = 0; $c < $cats; $c++) {
                $values[] = (($seed + 3) * ($k + 2) * ($c + 5)) % 200 + 20;
            }
            $s[] = ['name' => 'Series '.$k, 'values' => $values];
        }

        return ['id' => $id, 'type' => 'chart', 'x' => $x, 'y' => $y, 'w' => 460, 'h' => 200, 'chart' => [
            'type' => $type, 'data' => ['categories' => $categories, 'series' => $s],
            'opt' => ['title' => 'Trend '.$seed, 'legend' => 'bottom', 'labels' => 'none', 'grid' => true], 'src' => ['kind' => 'manual']]];
    }

    /** @return array<string, mixed> */
    private function table(string $id, int $x, int $y, int $rows, int $cols, int $seed): array
    {
        $data = [];
        for ($r = 0; $r < $rows; $r++) {
            $row = [];
            for ($c = 0; $c < $cols; $c++) {
                $cell = ['t' => $r === 0 ? 'Col '.$c : (string) (($seed + $r * 31 + $c * 17) % 1000)];
                if ($r === 0) {
                    $cell['b'] = true;
                } elseif ($c > 0) {
                    $cell['al'] = 'r';
                }
                $row[] = $cell;
            }
            $data[] = $row;
        }

        return ['id' => $id, 'type' => 'table', 'x' => $x, 'y' => $y, 'w' => 360, 'h' => min(900, $rows * 36), 'header' => true, 'band' => true, 'lines' => 'rows', 'size' => 16,
            'colw' => array_fill(0, $cols, round(1 / $cols, 4)), 'rowh' => array_fill(0, $rows, 36), 'rows' => $data];
    }
}
