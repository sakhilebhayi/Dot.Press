<?php

namespace Tests\Unit\Press;

use App\Services\Press\Resize;
use PHPUnit\Framework\TestCase;

class ResizeTest extends TestCase
{
    private function el(array $extra = []): array
    {
        return array_merge(['id' => 'a', 'type' => 'shape', 'x' => 100, 'y' => 50, 'w' => 200, 'h' => 100], $extra);
    }

    public function test_scale_doubles_everything_when_the_ratio_is_kept(): void
    {
        $out = Resize::elements([$this->el(['size' => 20, 'sw' => 2, 'radius' => 4, 'shadow' => ['x' => 1, 'y' => 2, 'blur' => 3, 'c' => '#000000']])], 1000, 500, 2000, 1000, 'scale')[0];

        $this->assertSame([200, 100, 400, 200], [$out['x'], $out['y'], $out['w'], $out['h']]);
        $this->assertSame([40, 4, 8], [$out['size'], $out['sw'], $out['radius']]);
        $this->assertSame(['x' => 2, 'y' => 4, 'blur' => 6, 'c' => '#000000'], $out['shadow']);
    }

    public function test_fit_letterboxes_and_scale_fills(): void
    {
        // 1000x500 (2:1) into 1000x1000: fit scales by 1 and centres vertically; scale scales by 2 and centres horizontally.
        $fit = Resize::elements([$this->el()], 1000, 500, 1000, 1000, 'fit')[0];
        $this->assertSame([100, 300, 200, 100], [$fit['x'], $fit['y'], $fit['w'], $fit['h']]);

        $fill = Resize::elements([$this->el()], 1000, 500, 1000, 1000, 'scale')[0];
        $this->assertSame([-300, 100, 400, 200], [$fill['x'], $fill['y'], $fill['w'], $fill['h']]);
    }

    public function test_a_resize_there_and_back_restores_the_geometry(): void
    {
        $start = [$this->el(['rot' => 30, 'size' => 24])];
        $there = Resize::elements($start, 1000, 500, 1500, 750, 'scale');
        $back = Resize::elements($there, 1500, 750, 1000, 500, 'scale');

        $this->assertEqualsWithDelta(100, $back[0]['x'], 0.05);
        $this->assertEqualsWithDelta(200, $back[0]['w'], 0.05);
        $this->assertEqualsWithDelta(24, $back[0]['size'], 0.05);
        $this->assertSame(30, $back[0]['rot']);
    }

    public function test_a_rotated_element_keeps_its_centre_and_angle_under_scaling(): void
    {
        $el = $this->el(['x' => 300, 'y' => 100, 'w' => 100, 'h' => 40, 'rot' => 45]);
        $out = Resize::elements([$el], 1000, 500, 1600, 800, 'scale')[0];

        $this->assertSame(45, $out['rot']);
        $this->assertEqualsWithDelta((300 + 50) * 1.6, $out['x'] + $out['w'] / 2, 0.02);
        $this->assertEqualsWithDelta((100 + 20) * 1.6, $out['y'] + $out['h'] / 2, 0.02);
        $this->assertEqualsWithDelta(160, $out['w'], 0.001);
    }

    public function test_anchor_keeps_sizes_and_pins_each_element_to_its_nearest_edge(): void
    {
        $els = [
            $this->el(['id' => 'left', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100]),
            $this->el(['id' => 'right', 'x' => 900, 'y' => 0, 'w' => 100, 'h' => 100]),
            $this->el(['id' => 'mid', 'x' => 450, 'y' => 0, 'w' => 100, 'h' => 100]),
        ];

        $out = Resize::elements($els, 1000, 500, 1400, 500, 'anchor');

        $this->assertSame(100, $out[0]['w']);
        $this->assertEqualsWithDelta(0.05 * 400 * 1, $out[0]['x'], 25.1); // centre fraction 0.05 moves it by 20
        $this->assertEqualsWithDelta(20, $out[0]['x'], 0.01);
        $this->assertEqualsWithDelta(900 + 380, $out[1]['x'], 0.01);
        // The centred element is still centred: (1400 - 100) / 2.
        $this->assertEqualsWithDelta(650, $out[2]['x'], 0.01);
    }

    public function test_anchor_moves_a_group_as_one_block(): void
    {
        $els = [
            $this->el(['id' => 'a', 'x' => 100, 'y' => 100, 'w' => 100, 'h' => 100, 'group' => 'g']),
            $this->el(['id' => 'b', 'x' => 700, 'y' => 100, 'w' => 100, 'h' => 100, 'group' => 'g']),
            $this->el(['id' => 'c', 'x' => 100, 'y' => 300, 'w' => 100, 'h' => 100]),
        ];

        $out = Resize::elements($els, 1000, 500, 2000, 500, 'anchor');

        // Same shift for both members, so their distance stays 600.
        $this->assertEqualsWithDelta(600, $out[1]['x'] - $out[0]['x'], 0.001);
        $this->assertEqualsWithDelta(450, $out[0]['x'] - 100, 0.001);
        // The loose element has its own, smaller shift.
        $this->assertLessThan($out[0]['x'] - 100, $out[2]['x'] - 100);
    }

    public function test_anchor_uses_the_rotated_bounds_for_the_block_centre(): void
    {
        $rotated = $this->el(['x' => 0, 'y' => 0, 'w' => 200, 'h' => 20, 'rot' => 90]);
        $box = Resize::bounds($rotated);

        $this->assertEqualsWithDelta(90, $box[0], 0.001);
        $this->assertEqualsWithDelta(110, $box[2], 0.001);
        $this->assertEqualsWithDelta(-90, $box[1], 0.001);
        $this->assertEqualsWithDelta(110, $box[3], 0.001);
    }

    public function test_type_inside_text_documents_and_table_rows_scales_too(): void
    {
        $doc = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'textStyle', 'attrs' => ['fontSize' => 10]]]]]]]];
        $out = Resize::elements([$this->el(['doc' => $doc, 'rowh' => [10, 20]])], 100, 100, 300, 300, 'fit')[0];

        $this->assertSame(30, $out['doc']['content'][0]['content'][0]['marks'][0]['attrs']['fontSize']);
        $this->assertSame([30, 60], $out['rowh']);
    }

    public function test_theme_measures_follow_scaling_resizes_only(): void
    {
        $theme = ['s' => ['radius' => 10, 'stroke' => 2]];

        $this->assertSame(['s' => ['radius' => 20, 'stroke' => 4]], Resize::theme($theme, 'scale', 100, 100, 200, 200));
        $this->assertSame($theme, Resize::theme($theme, 'anchor', 100, 100, 200, 200));
    }
}
