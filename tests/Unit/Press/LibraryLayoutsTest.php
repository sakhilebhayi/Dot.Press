<?php

namespace Tests\Unit\Press;

use App\Services\Press\DocumentSanitizer;
use App\Services\Press\LayoutDefinition;
use App\Services\Press\LayoutLibrary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The built-in layouts: the fourteen names of the spec, geometry that stays on the slide at every aspect ratio, and
 * definitions the editor and the team layout validator both accept.
 */
class LibraryLayoutsTest extends TestCase
{
    private const EXPECTED = ['title', 'section', 'bullets', 'two', 'image-left', 'image-right', 'image-full', 'compare', 'timeline', 'quote', 'data', 'kpi', 'closing', 'blank'];

    /** @return array<string, array{int, int}> */
    public static function sizes(): array
    {
        return ['16:9' => [1920, 1080], '4:3' => [1440, 1080], 'portrait' => [1080, 1920], 'legacy 720p' => [1280, 720], '16:10' => [1920, 1200], 'square' => [1080, 1080], 'large 4:3' => [2048, 1536]];
    }

    public function test_the_library_holds_exactly_the_layouts_of_the_spec(): void
    {
        $this->assertSame(self::EXPECTED, LayoutLibrary::ids());
        $this->assertSame(self::EXPECTED, array_values(array_intersect(LayoutLibrary::ids(), DocumentSanitizer::LAYOUTS)));

        foreach (LayoutLibrary::all() as $layout) {
            $this->assertNotSame('', $layout['name']);
            $this->assertGreaterThan(30, mb_strlen($layout['description']), 'says when to use it');
            $this->assertContains($layout['evidence'], ['none', 'image', 'data', 'figures', 'quote', 'steps', 'comparison']);
        }
    }

    public function test_the_aspect_family_follows_the_ratio(): void
    {
        $this->assertSame('wide', LayoutLibrary::family(1920, 1080));
        $this->assertSame('wide', LayoutLibrary::family(1920, 1200));
        $this->assertSame('standard', LayoutLibrary::family(1440, 1080));
        $this->assertSame('standard', LayoutLibrary::family(1620, 1080));
        $this->assertSame('portrait', LayoutLibrary::family(1080, 1920));
        $this->assertSame('portrait', LayoutLibrary::family(1080, 1080));
    }

    #[DataProvider('sizes')]
    public function test_every_placeholder_sits_inside_the_slide_and_keeps_type_readable(int $w, int $h): void
    {
        foreach (LayoutLibrary::ids() as $id) {
            $layout = LayoutLibrary::layout($id, $w, $h);
            $keys = [];
            foreach ($layout['ph'] as $p) {
                $where = "{$id} {$p['key']} at {$w}x{$h}";
                $this->assertGreaterThanOrEqual(0, $p['x'], $where);
                $this->assertGreaterThanOrEqual(0, $p['y'], $where);
                $this->assertGreaterThan(0, $p['w'], $where);
                $this->assertGreaterThan(0, $p['h'], $where);
                $this->assertLessThanOrEqual(1.00001, $p['x'] + $p['w'], $where);
                $this->assertLessThanOrEqual(1.00001, $p['y'] + $p['h'], $where);
                $this->assertContains($p['role'], LayoutDefinition::ROLES, $where);
                $this->assertContains($p['type'], ['text', 'media'], $where);
                $this->assertNotContains($p['key'], $keys, $where.' key is unique');
                $keys[] = $p['key'];

                if ($p['type'] === 'text') {
                    // the smallest type (a footer) stays above 1.6 percent of the short side of the slide
                    $this->assertGreaterThanOrEqual(0.0165, $p['size'] / min($w, $h), $where.' type size');
                    $this->assertEqualsWithDelta($p['size'] / $h, $p['sizeF'], 0.0001);
                    $this->assertContains($p['style']['font'], ['@heading', '@body']);
                    $this->assertMatchesRegularExpression('/^@(ink|muted|accent|bg)$/', $p['style']['color']);
                    $this->assertNotSame('', $p['text']);
                }
            }
        }
    }

    #[DataProvider('sizes')]
    public function test_text_placeholders_do_not_collide_and_respect_the_margins(int $w, int $h): void
    {
        foreach (LayoutLibrary::ids() as $id) {
            $text = array_values(array_filter(LayoutLibrary::layout($id, $w, $h)['ph'], fn (array $p): bool => $p['type'] === 'text'));
            $family = LayoutLibrary::family($w, $h);
            $margin = ['wide' => 120 / 1920, 'standard' => 96 / 1440, 'portrait' => 84 / 1080][$family];

            foreach ($text as $i => $a) {
                if (! in_array($id, ['image-full', 'quote'], true)) {
                    $this->assertGreaterThanOrEqual($margin - 0.0006, $a['x'], "{$id} {$a['key']} left margin at {$w}x{$h}");
                    $this->assertLessThanOrEqual(1 - $margin + 0.0006, $a['x'] + $a['w'], "{$id} {$a['key']} right margin at {$w}x{$h}");
                }
                foreach (array_slice($text, $i + 1) as $b) {
                    $overlapX = min($a['x'] + $a['w'], $b['x'] + $b['w']) - max($a['x'], $b['x']);
                    $overlapY = min($a['y'] + $a['h'], $b['y'] + $b['h']) - max($a['y'], $b['y']);
                    $this->assertFalse($overlapX > 0.001 && $overlapY > 0.001, "{$id}: {$a['key']} overlaps {$b['key']} at {$w}x{$h}");
                }
            }
        }
    }

    public function test_a_family_gets_its_own_composition(): void
    {
        $wide = LayoutLibrary::layout('image-left', 1920, 1080)['ph'][0];
        $portrait = LayoutLibrary::layout('image-left', 1080, 1920)['ph'][0];
        $this->assertSame('media', $wide['role']);
        $this->assertEqualsWithDelta(0.4167, $wide['w'], 0.001, 'a third and a bit of the width on a wide slide');
        $this->assertSame(1.0, $portrait['w'], 'the picture runs the full width on a portrait slide');
        $this->assertSame(0.0, $portrait['y']);

        // the same composition at any size inside a family
        $this->assertSame(LayoutLibrary::layout('bullets', 1920, 1080)['preview'], LayoutLibrary::layout('bullets', 1280, 720)['preview']);
        $this->assertSame(LayoutLibrary::layout('kpi', 1440, 1080)['preview'], LayoutLibrary::layout('kpi', 2048, 1536)['preview']);
    }

    public function test_type_scales_with_the_deck(): void
    {
        $big = LayoutLibrary::layout('title', 1920, 1080)['ph'][0];
        $small = LayoutLibrary::layout('title', 1280, 720)['ph'][0];

        $this->assertEqualsWithDelta($big['size'] * 720 / 1080, $small['size'], 0.5);
        $this->assertEqualsWithDelta($big['sizeF'], $small['sizeF'], 0.0002, 'the same fraction of the height');
    }

    #[DataProvider('sizes')]
    public function test_the_definitions_pass_the_team_layout_validator(int $w, int $h): void
    {
        foreach (LayoutLibrary::ids() as $id) {
            $layout = LayoutLibrary::layout($id, $w, $h);
            if ($layout['definition'] === []) {
                $this->assertSame('blank', $id);

                continue;
            }
            $result = LayoutDefinition::validate($layout['definition']);
            $this->assertSame([], $result['errors'], "{$id} at {$w}x{$h}");
            $this->assertCount(count($layout['ph']), $result['definition']);
        }
    }

    public function test_the_layouts_have_the_placeholders_templates_fill(): void
    {
        $keys = fn (string $id): array => array_column(LayoutLibrary::layout($id)['ph'], 'key');

        $this->assertSame(['title', 'subtitle', 'caption'], $keys('title'));
        $this->assertSame([], $keys('blank'));
        $this->assertContains('number', $keys('section'));
        $this->assertSame(['kpi1', 'cap1', 'kpi2', 'cap2', 'kpi3', 'cap3', 'kpi4', 'cap4'], array_slice($keys('kpi'), 1, 8));
        $this->assertContains('media', $keys('data'));
        $this->assertContains('body2', $keys('two'));
        $this->assertContains('subtitle4', $keys('timeline'));
        $this->assertNull(LayoutLibrary::layout('nope'));
    }

    public function test_the_output_is_deterministic(): void
    {
        $this->assertSame(json_encode(LayoutLibrary::forSize(1920, 1080)), json_encode(LayoutLibrary::forSize(1920, 1080)));
    }
}
