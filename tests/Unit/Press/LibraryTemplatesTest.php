<?php

namespace Tests\Unit\Press;

use App\Services\Press\DocumentSanitizer;
use App\Services\Press\LayoutLibrary;
use App\Services\Press\TemplateLibrary;
use App\Services\Press\ThemeLibrary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every built-in template builds at 16:9, 4:3 and portrait, passes the document sanitizer without losing a key,
 * keeps every element on the slide and carries real content with speaker notes.
 */
class LibraryTemplatesTest extends TestCase
{
    private const SIZES = ['16:9' => [1920, 1080], '4:3' => [1440, 1080], 'portrait' => [1080, 1920]];

    /** @return array<string, array{string}> */
    public static function keys(): array
    {
        $out = [];
        foreach (TemplateLibrary::keys() as $key) {
            $out[$key] = [$key];
        }

        return $out;
    }

    public function test_the_library_has_sixteen_to_twenty_templates_in_every_category(): void
    {
        $all = TemplateLibrary::all();

        $this->assertGreaterThanOrEqual(16, count($all));
        $this->assertLessThanOrEqual(20, count($all));
        $this->assertSame(array_unique(array_column($all, 'key')), array_column($all, 'key'));

        $perCategory = array_count_values(array_column($all, 'category'));
        foreach (array_keys(TemplateLibrary::CATEGORIES) as $category) {
            $this->assertGreaterThanOrEqual(3, $perCategory[$category] ?? 0, $category);
        }
        $names = implode('|', array_column($all, 'key'));
        foreach (['executive-summary', 'board-pack', 'quarterly-business-review', 'strategy', 'sales-proposal', 'product-launch', 'marketing-plan', 'investor-pitch', 'financial-review', 'budget-presentation', 'training-deck', 'lesson', 'workshop', 'research-talk', 'mining-production-performance', 'safety-briefing', 'project-status-update', 'engineering-proposal', 'construction-progress', 'manufacturing-performance'] as $expected) {
            $this->assertStringContainsString($expected, $names);
        }
    }

    public function test_the_metadata_is_complete(): void
    {
        foreach (TemplateLibrary::all() as $t) {
            foreach (['key', 'name', 'category', 'category_name', 'blurb', 'narrative', 'slides', 'sections', 'theme', 'aspect', 'tags'] as $field) {
                $this->assertArrayHasKey($field, $t, $t['key']);
            }
            $this->assertGreaterThanOrEqual(8, $t['slides'], $t['key']);
            $this->assertLessThanOrEqual(14, $t['slides'], $t['key']);
            $this->assertGreaterThanOrEqual(3, $t['sections'], $t['key'].' uses sections');
            $this->assertContains($t['theme'], ThemeLibrary::ids());
            $this->assertSame('16:9', $t['aspect']);
            $this->assertNotEmpty($t['tags']);
            $this->assertGreaterThan(60, mb_strlen($t['blurb']));
            $this->assertGreaterThan(20, mb_strlen($t['narrative']), 'names the narrative structure');
            $this->assertSame($t, TemplateLibrary::find($t['key']));
        }
        $this->assertNull(TemplateLibrary::find('nope'));
        $this->assertSame(['theme' => null, 'sections' => [], 'slides' => []], TemplateLibrary::build('nope'));
    }

    public function test_the_library_uses_a_spread_of_themes_and_covers(): void
    {
        $themes = array_unique(array_column(TemplateLibrary::all(), 'theme'));

        $this->assertGreaterThanOrEqual(10, count($themes), 'templates use most of the twelve themes');
        $this->assertNotContains('nonexistent', $themes);
    }

    #[DataProvider('keys')]
    public function test_a_template_builds_and_sanitises_at_every_size_with_nothing_dropped(string $key): void
    {
        $sanitizer = new DocumentSanitizer;

        foreach (self::SIZES as $name => [$w, $h]) {
            $built = TemplateLibrary::build($key, $w, $h);
            $this->assertNotEmpty($built['slides'], "{$key} at {$name}");
            $this->assertIsArray($built['theme']);
            $sections = array_column($built['sections'], 'id');

            foreach ($built['slides'] as $n => $slide) {
                $where = "{$key} {$name} slide ".($n + 1);

                $fields = $sanitizer->slideFields($slide);
                foreach (['title', 'notes', 'layout', 'section_id', 'bg', 'transition', 'els'] as $field) {
                    $this->assertArrayHasKey($field, $fields, "{$where} keeps {$field}");
                }
                $this->assertSame($slide['layout'], $fields['layout']);
                $this->assertSame($slide['bg'], $fields['bg'] ?? null, $where.' background survives');
                $this->assertContains($slide['layout'], LayoutLibrary::ids(), $where);
                $this->assertContains($slide['section_id'], $sections, "{$where} belongs to a section");
                $this->assertCount(count($slide['els']), $fields['els'], "{$where} element count");

                $ids = [];
                foreach ($slide['els'] as $i => $el) {
                    $clean = $fields['els'][$i];
                    $this->assertSame([], array_values(array_diff(array_keys($el), array_keys($clean))), "{$where} element {$el['id']} lost keys");
                    $this->assertSame($el['id'], $clean['id']);
                    $this->assertNotContains($el['id'], $ids, "{$where} unique element ids");
                    $ids[] = $el['id'];
                    $this->assertSame('template', $el['origin'], $where.' marks template content');

                    foreach (['x', 'y', 'w', 'h'] as $k) {
                        $this->assertEqualsWithDelta($el[$k], $clean[$k], 0.001, "{$where} {$el['id']} {$k}");
                    }
                    $this->assertGreaterThanOrEqual(-0.01, $el['x'], "{$where} {$el['id']} inside the slide");
                    $this->assertGreaterThanOrEqual(-0.01, $el['y'], "{$where} {$el['id']} inside the slide");
                    $this->assertLessThanOrEqual($w + 0.5, $el['x'] + $el['w'], "{$where} {$el['id']} right edge");
                    $this->assertLessThanOrEqual($h + 0.5, $el['y'] + $el['h'], "{$where} {$el['id']} bottom edge");

                    if (isset($el['doc'])) {
                        $this->assertSame($el['doc'], $clean['doc'], "{$where} {$el['id']} text document survives unchanged");
                    }
                    if ($el['type'] === 'chart') {
                        foreach (array_keys($el['chart']['opt']) as $opt) {
                            $this->assertArrayHasKey($opt, $clean['chart']['opt'], "{$where} chart option {$opt} survives");
                        }
                        $this->assertSame($el['chart']['data']['categories'], $clean['chart']['data']['categories']);
                        $this->assertSame(array_keys($el['chart']['data']), array_keys(array_intersect_key($clean['chart']['data'], $el['chart']['data'])));
                    }
                }
            }
        }
    }

    #[DataProvider('keys')]
    public function test_every_slide_has_real_text_and_speaker_notes(string $key): void
    {
        $built = TemplateLibrary::build($key);

        foreach ($built['slides'] as $n => $slide) {
            $where = "{$key} slide ".($n + 1);
            $this->assertGreaterThan(80, mb_strlen($slide['notes']), $where.' has speaker notes');
            $this->assertNotSame('', trim($slide['title']), $where.' has a title');
            $this->assertStringNotContainsStringIgnoringCase('lorem', $slide['notes'].json_encode($slide['els']));

            $text = 0;
            foreach ($slide['els'] as $el) {
                if (isset($el['doc'])) {
                    $plain = $this->plain($el['doc']);
                    $this->assertNotSame('', trim($plain), "{$where} {$el['id']} is not empty");
                    $text++;
                }
            }
            $this->assertGreaterThanOrEqual(2, $text, $where.' carries text');
        }
    }

    #[DataProvider('keys')]
    public function test_a_template_has_a_real_chart_or_table_labelled_as_sample_data(string $key): void
    {
        $built = TemplateLibrary::build($key);
        $withData = 0;
        $labelled = 0;

        foreach ($built['slides'] as $slide) {
            $types = array_column($slide['els'], 'type');
            if (in_array('chart', $types, true) || in_array('table', $types, true)) {
                $withData++;
                $all = json_encode($slide['els']);
                if (str_contains($all, 'Sample data, replace with yours')) {
                    $labelled++;
                }
            }
        }

        $this->assertGreaterThanOrEqual(1, $withData, $key.' has data slides');
        $this->assertGreaterThanOrEqual(1, $labelled, $key.' labels sample data');
    }

    #[DataProvider('keys')]
    public function test_a_template_is_consistent_about_transitions_and_layouts(string $key): void
    {
        $built = TemplateLibrary::build($key);
        $slides = $built['slides'];

        $this->assertNull($slides[0]['transition'], 'the cover has no transition');
        $transitions = array_unique(array_map(fn (array $s): string => json_encode($s['transition']), array_slice($slides, 1)));
        $this->assertCount(1, $transitions, 'one transition choice per template');
        $this->assertContains(json_decode($transitions[array_key_first($transitions)], true)['type'], ['fade', 'push']);

        $this->assertSame('title', $slides[0]['layout']);
        $this->assertSame('closing', $slides[array_key_last($slides)]['layout']);
        $layouts = array_unique(array_column($slides, 'layout'));
        $this->assertGreaterThanOrEqual(6, count($layouts), 'a spread of layouts, not one repeated');

        $animated = 0;
        foreach ($slides as $slide) {
            foreach ($slide['els'] as $el) {
                $animated += isset($el['anim']) ? 1 : 0;
            }
        }
        $this->assertLessThanOrEqual(12, $animated, 'animation is used sparingly');

        foreach ($slides as $n => $slide) {
            $placeholders = array_column(LayoutLibrary::placeholders($slide['layout'], 1920, 1080), null, 'key');
            foreach ($slide['els'] as $el) {
                if (isset($el['role']) && isset($placeholders[$el['id']])) {
                    $this->assertSame($placeholders[$el['id']]['role'], $el['role'], "{$key} slide ".($n + 1).' follows its layout');
                }
            }
        }
    }

    #[DataProvider('keys')]
    public function test_the_text_follows_the_house_rules(string $key): void
    {
        $built = TemplateLibrary::build($key);
        $all = $key.json_encode($built, JSON_UNESCAPED_UNICODE);

        $this->assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', $all, 'no en or em dashes');
        $this->assertDoesNotMatchRegularExpression('/\b(seamless|unleash|cutting-edge|elevate|leverage|synergy|revolutionary)\b/i', $all);
        $this->assertSame(1, preg_match('/Replace/', $all), 'fill in prompts are marked');
    }

    public function test_the_build_is_deterministic_and_the_same_at_every_call(): void
    {
        foreach (['executive-summary', 'mining-production-performance'] as $key) {
            $this->assertSame(json_encode(TemplateLibrary::build($key)), json_encode(TemplateLibrary::build($key)));
        }
    }

    public function test_the_templates_reflow_when_the_layout_changes_so_each_slide_names_a_built_in_layout_with_matching_roles(): void
    {
        foreach (TemplateLibrary::keys() as $key) {
            foreach (TemplateLibrary::build($key)['slides'] as $slide) {
                $placeholderRoles = array_column(LayoutLibrary::placeholders($slide['layout']), 'role', 'key');
                foreach ($slide['els'] as $el) {
                    if (isset($el['role'])) {
                        $this->assertContains($el['role'], $placeholderRoles, "{$key} {$slide['layout']} {$el['id']}");
                    }
                }
            }
        }
    }

    /** @param array<string, mixed> $doc */
    private function plain(array $doc): string
    {
        $out = '';
        $walk = function (array $node) use (&$walk, &$out): void {
            if (($node['type'] ?? '') === 'text') {
                $out .= $node['text'];
            }
            foreach ($node['content'] ?? [] as $child) {
                $walk($child);
            }
        };
        $walk($doc);

        return $out;
    }
}
