<?php

namespace Tests\Unit\Press;

use App\Services\Press\DocumentSanitizer;
use App\Services\Press\InvalidDocument;
use PHPUnit\Framework\TestCase;

class DocumentSanitizerTest extends TestCase
{
    private DocumentSanitizer $san;

    protected function setUp(): void
    {
        $this->san = new DocumentSanitizer;
    }

    public function test_colours_are_normalised_or_refused(): void
    {
        $this->assertSame('#aabbcc', $this->san->colour('#ABC'));
        $this->assertSame('#aabbccdd', $this->san->colour('#AABBCCDD'));
        $this->assertSame('@chart8', $this->san->colour('@chart8'));
        foreach (['red', '#12', '#12345g', '@chart9', '@nope', 'rgb(1,2,3)', '', 12, ['#fff']] as $bad) {
            try {
                $this->san->colour($bad);
                $this->fail('accepted '.json_encode($bad));
            } catch (InvalidDocument) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_fills_accept_a_linear_gradient_with_valid_stops(): void
    {
        $fill = $this->san->fill(['g' => 'linear', 'a' => '45', 'stops' => [[0, '#112233'], [1, '@accent']], 'x' => 1]);

        $this->assertSame(['g' => 'linear', 'a' => 45, 'stops' => [[0, '#112233'], [1, '@accent']]], $fill);
        $this->expectException(InvalidDocument::class);
        $this->san->fill(['g' => 'linear', 'stops' => [[0, '#112233']]]);
    }

    public function test_urls_must_be_https_or_a_slide_link(): void
    {
        $this->assertSame('https://example.com/a?b=1#c', $this->san->url('HTTPS://example.com/a?b=1#c', false, 'url'));
        $this->assertSame('#slide:12', $this->san->url('#slide:12', true, 'link'));
        foreach (['javascript:alert(1)', 'data:text/html,hi', 'http://example.com', '//example.com', 'https://', 'ftp://x.test', "https://x.test/\n", 'https://u:p@x.test', str_repeat('a', 2001)] as $bad) {
            try {
                $this->san->url($bad, true, 'link');
                $this->fail('accepted '.$bad);
            } catch (InvalidDocument) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidDocument::class);
        $this->san->url('#slide:12', false, 'url');
    }

    public function test_numbers_are_coerced_and_clamped(): void
    {
        $this->assertSame(5, $this->san->num('5', 0, 10, 'n'));
        $this->assertSame(10, $this->san->num(11, 0, 10, 'n'));
        $this->assertSame(0.123, $this->san->num(0.12345, 0, 1, 'n'));
        $this->expectException(InvalidDocument::class);
        $this->san->num('1e999', 0, 10, 'n');
    }

    public function test_plain_text_and_doc_round_trip(): void
    {
        $doc = $this->san->docFromText("One\n\nTwo");

        $this->assertCount(3, $doc['content']);
        $this->assertSame("One\n\nTwo", $this->san->plainText($doc));
    }

    public function test_theme_ids_accept_the_ids_the_theme_api_hands_out(): void
    {
        foreach (['slate', 'night-briefing', 'custom:12', 'brand-kit:3'] as $id) {
            $this->assertSame($id, $this->san->theme(['id' => $id, 'name' => 'X'])['id']);
        }
        foreach (['custom:', ':1', 'custom:a', 'a:1:2', 'x y', 'custom:12/../'] as $bad) {
            try {
                $this->san->theme(['id' => $bad]);
                $this->fail('accepted '.$bad);
            } catch (InvalidDocument) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_themes_fall_back_to_the_default_for_missing_tokens(): void
    {
        $theme = $this->san->theme(['c' => ['accent' => '#010203', 'chart' => ['#111111']], 't' => ['hw' => 5000], 's' => ['radius' => -4]]);

        $this->assertSame('#010203', $theme['c']['accent']);
        $this->assertSame('#111111', $theme['c']['chart'][0]);
        $this->assertCount(8, $theme['c']['chart']);
        $this->assertSame(900, $theme['t']['hw']);
        $this->assertSame(0, $theme['s']['radius']);
        $this->assertSame('#111827', $theme['c']['ink']);
        $this->assertNull($theme['bg']);
    }

    public function test_slide_fields_validate_layout_bg_and_transition(): void
    {
        $fields = $this->san->slideFields([
            'title' => 'T', 'layout' => 'custom:abc', 'bg' => ['img' => ['url' => 'https://x.test/a.jpg'], 'dim' => 3],
            'transition' => ['type' => 'push', 'dir' => 'r'], 'section_id' => 's1', 'junk' => 1,
        ]);

        $this->assertSame(['title', 'section_id', 'layout', 'bg', 'transition'], array_keys($fields));
        $this->assertSame(1, $fields['bg']['dim']);
        $this->assertSame(500, $fields['transition']['dur']);

        foreach ([['layout' => 'fancy'], ['bg' => 'blue'], ['transition' => ['type' => 'explode']], ['section_id' => 'bad id']] as $bad) {
            try {
                $this->san->slideFields($bad);
                $this->fail('accepted '.json_encode($bad));
            } catch (InvalidDocument) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_animation_steps_are_checked(): void
    {
        $el = $this->san->element(['id' => 'a', 'type' => 'shape', 'anim' => [['fx' => 'fade', 'on' => 'click'], ['id' => 's2', 'fx' => 'zoom', 'ph' => 'out', 'on' => 'after', 'delay' => 100, 'dur' => '300', 'ease' => 'spring']]]);

        $this->assertSame('in', $el['anim'][0]['ph']);
        $this->assertSame(400, $el['anim'][0]['dur']);
        $this->assertSame('s2', $el['anim'][1]['id']);
        $this->assertSame(300, $el['anim'][1]['dur']);

        $this->expectException(InvalidDocument::class);
        $this->san->element(['id' => 'a', 'type' => 'shape', 'anim' => [['fx' => 'explode']]]);
    }

    public function test_an_icon_keeps_its_icon_id_in_name(): void
    {
        $this->assertSame('chevron-right', $this->san->element(['id' => 'i', 'type' => 'icon', 'name' => 'chevron-right', 'x' => 1, 'y' => 1, 'w' => 4, 'h' => 4])['name']);
        $this->expectException(InvalidDocument::class);
        $this->san->element(['id' => 'i', 'type' => 'icon', 'name' => '<b>', 'x' => 1, 'y' => 1, 'w' => 4, 'h' => 4]);
    }
}
