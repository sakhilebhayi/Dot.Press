<?php

namespace Tests\Unit\Press\Intel;

use App\Services\Press\Intel\DeckAnalyzer;
use App\Services\Press\Intel\DocText;
use Tests\TestCase;

class DeckAnalyzerTest extends TestCase
{
    private function analyze(array $slides, array $deck = []): array
    {
        return (new DeckAnalyzer)->analyze(DeckFixture::doc($slides, $deck));
    }

    private function kinds(array $result): array
    {
        return array_values(array_unique(array_column($result['issues'], 'kind')));
    }

    /** Apply the fix of the first issue of $kind and return the new document. */
    private function applyFix(array $slides, string $kind, array $deck = [], int $nth = 0): array
    {
        $doc = DeckFixture::doc($slides, $deck);
        $r = (new DeckAnalyzer)->analyze($doc);
        $issues = DeckFixture::issues($r, $kind);
        $this->assertNotEmpty($issues, "no {$kind} issue to fix");
        $fix = $issues[$nth]['fix'] ?? null;
        $this->assertNotNull($fix, "{$kind} issue has no fix");
        $this->assertNotEmpty($fix['ops']);

        return OpsApplier::apply($doc, $fix['ops']);
    }

    /** A clean slide that should raise nothing. */
    private function clean(string $id = 'a', string $title = 'Costs fell 3% below budget in the quarter'): array
    {
        return DeckFixture::slide($id, [
            DeckFixture::title($id.'t', $title),
            DeckFixture::text($id.'b', ['Diesel prices remain the main risk in '.$id, 'The mill relining is on track at site '.$id], ['bullets' => 1]),
        ], ['title' => $title, 'notes' => 'Source: finance report']);
    }

    public function test_a_clean_deck_raises_nothing(): void
    {
        $r = $this->analyze([$this->clean('a'), $this->clean('b', 'Safety performance improved across both sites')]);
        $this->assertSame([], $r['issues'], json_encode($r['issues']));
        $this->assertSame('local', $r['mode']);
        $this->assertSame(0, $r['summary']['total']);
    }

    public function test_analysis_is_deterministic(): void
    {
        $slides = [DeckFixture::slide('a', [DeckFixture::text('x', 'TBD', ['size' => 12])])];
        $this->assertSame($this->analyze($slides), $this->analyze($slides));
    }

    // ---------------------------------------------------------------- consistency

    public function test_more_than_three_font_families_is_flagged_and_fixable(): void
    {
        $fonts = ['Arial', 'Georgia', 'Verdana', 'Courier New'];
        $els = [];
        foreach ($fonts as $i => $f) {
            $els[] = DeckFixture::text('t'.$i, 'Line '.$i, ['font' => $f, 'y' => 100 + $i * 200]);
        }
        $slides = [DeckFixture::slide('a', $els)];
        $r = $this->analyze($slides);
        $this->assertContains('font-families', $this->kinds($r));
        $doc = $this->applyFix($slides, 'font-families');
        $this->assertSame('Arial', $doc['slides'][0]['els'][3]['font'] ?? null);
        $this->assertSame([], DeckFixture::issues((new DeckAnalyzer)->analyze($doc), 'font-families'));

        $three = [DeckFixture::slide('a', array_slice($els, 0, 3))];
        $this->assertNotContains('font-families', $this->kinds($this->analyze($three)));
    }

    public function test_heading_and_body_theme_fonts_count_as_two_families(): void
    {
        $els = [DeckFixture::text('a', 'One', ['font' => '@heading', 'y' => 100]), DeckFixture::text('b', 'Two', ['font' => '@body', 'y' => 400])];
        $this->assertNotContains('font-families', $this->kinds($this->analyze([DeckFixture::slide('a', $els)])));
    }

    public function test_more_than_six_type_sizes_is_flagged_and_snapped(): void
    {
        $els = [];
        foreach ([30, 32, 36, 40, 44, 48, 52, 56] as $i => $size) {
            $els[] = DeckFixture::text('t'.$i, 'Text '.$i, ['size' => $size, 'y' => 20 + $i * 120, 'h' => 100]);
        }
        $slides = [DeckFixture::slide('a', $els)];
        $this->assertContains('type-scale', $this->kinds($this->analyze($slides)));
        $doc = $this->applyFix($slides, 'type-scale');
        $sizes = array_unique(array_map(fn ($e) => $e['size'], $doc['slides'][0]['els']));
        $this->assertLessThanOrEqual(6, count($sizes));

        $six = [DeckFixture::slide('a', array_slice($els, 0, 6))];
        $this->assertNotContains('type-scale', $this->kinds($this->analyze($six)));
    }

    public function test_the_same_role_with_different_sizes_is_flagged(): void
    {
        $slides = [];
        foreach ([60, 60, 60, 48] as $i => $size) {
            $slides[] = DeckFixture::slide('s'.$i, [DeckFixture::title('t'.$i, 'Title number '.$i.' states a point', ['size' => $size, 'h' => 150]), DeckFixture::text('b'.$i, 'Body '.$i, ['size' => 36])]);
        }
        $r = $this->analyze($slides);
        $hits = DeckFixture::issues($r, 'role-size');
        $this->assertCount(1, $hits);
        $this->assertSame('s3', $hits[0]['slide']);
        $doc = $this->applyFix($slides, 'role-size');
        $this->assertSame(60.0, (float) $doc['slides'][3]['els'][0]['size']);

        $same = [];
        foreach ([60, 60, 60] as $i => $size) {
            $same[] = DeckFixture::slide('s'.$i, [DeckFixture::title('t'.$i, 'Title number '.$i.' states a point', ['size' => $size])]);
        }
        $this->assertSame([], DeckFixture::issues($this->analyze($same), 'role-size'));
    }

    public function test_off_theme_colours_get_the_nearest_token_as_a_fix(): void
    {
        $slides = [DeckFixture::slide('a', [DeckFixture::text('x', 'Coloured text here', ['color' => '#7a808a'])])];
        $hits = DeckFixture::issues($this->analyze($slides), 'off-theme-colour');
        $this->assertCount(1, $hits);
        $this->assertSame('info', $hits[0]['severity']);
        $this->assertSame('@muted', $hits[0]['fix']['ops'][0]['p']['color']);
        $doc = $this->applyFix($slides, 'off-theme-colour');
        $this->assertSame('@muted', $doc['slides'][0]['els'][0]['color']);
    }

    public function test_on_theme_colours_are_not_flagged(): void
    {
        foreach (['@accent', '#ffffff', '#000', '#2563eb', '#2563ec', '#111827'] as $c) {
            $slides = [DeckFixture::slide('a', [DeckFixture::text('x', 'Coloured text here', ['color' => $c, 'fill' => '#ffffff'])])];
            $this->assertSame([], DeckFixture::issues($this->analyze($slides), 'off-theme-colour'), $c);
        }
    }

    public function test_near_miss_alignment_is_flagged_and_snapped(): void
    {
        $slides = [DeckFixture::slide('a', [
            DeckFixture::text('a1', 'First block', ['x' => 120, 'y' => 100, 'h' => 150]),
            DeckFixture::text('a2', 'Second block', ['x' => 124, 'y' => 400, 'h' => 150]),
            DeckFixture::text('a3', 'Third block', ['x' => 120, 'y' => 700, 'h' => 150]),
        ])];
        $hits = DeckFixture::issues($this->analyze($slides), 'alignment');
        $this->assertCount(1, $hits);
        $this->assertStringContainsString('Left edges', $hits[0]['message']);
        $doc = $this->applyFix($slides, 'alignment');
        $this->assertSame([120.0, 120.0, 120.0], array_map(fn ($e) => (float) $e['x'], $doc['slides'][0]['els']));
        $this->assertSame([], DeckFixture::issues((new DeckAnalyzer)->analyze($doc), 'alignment'));
    }

    public function test_exact_and_wide_apart_edges_are_not_near_misses(): void
    {
        $exact = [DeckFixture::slide('a', [DeckFixture::text('a1', 'A', ['x' => 120, 'y' => 100, 'h' => 150]), DeckFixture::text('a2', 'B', ['x' => 120, 'y' => 400, 'h' => 150])])];
        $this->assertSame([], DeckFixture::issues($this->analyze($exact), 'alignment'));
        $apart = [DeckFixture::slide('a', [DeckFixture::text('a1', 'A', ['x' => 120, 'y' => 100, 'h' => 150, 'w' => 500]), DeckFixture::text('a2', 'B', ['x' => 160, 'y' => 400, 'h' => 150, 'w' => 500])])];
        $this->assertSame([], DeckFixture::issues($this->analyze($apart), 'alignment'));
    }

    public function test_uneven_left_margin_against_the_deck_is_flagged(): void
    {
        $slides = [];
        foreach ([120, 120, 120, 150] as $i => $x) {
            $slides[] = DeckFixture::slide('s'.$i, [DeckFixture::title('t'.$i, 'Title '.$i.' says something', ['x' => $x, 'w' => 1400]), DeckFixture::text('b'.$i, 'Body '.$i, ['x' => $x, 'w' => 1400])]);
        }
        $hits = DeckFixture::issues($this->analyze($slides), 'margins');
        $this->assertCount(1, $hits);
        $this->assertSame('s3', $hits[0]['slide']);
        $doc = $this->applyFix($slides, 'margins');
        $this->assertSame(120.0, (float) $doc['slides'][3]['els'][0]['x']);
    }

    public function test_uneven_spacing_in_a_stack_is_flagged_and_equalised(): void
    {
        $y = 100;
        $els = [];
        foreach ([0, 40, 40, 58, 40] as $i => $gap) {
            $y += $gap;
            $els[] = DeckFixture::text('i'.$i, 'Item '.$i, ['y' => $y, 'h' => 100, 'w' => 800]);
            $y += 100;
        }
        $slides = [DeckFixture::slide('a', $els)];
        $hits = DeckFixture::issues($this->analyze($slides), 'spacing');
        $this->assertCount(1, $hits);
        $doc = $this->applyFix($slides, 'spacing');
        $ys = array_map(fn ($e) => $e['y'], $doc['slides'][0]['els']);
        $gaps = [];
        for ($i = 1; $i < count($ys); $i++) {
            $gaps[] = $ys[$i] - ($ys[$i - 1] + 100);
        }
        $this->assertLessThanOrEqual(1.0, max($gaps) - min($gaps));
    }

    public function test_deliberately_different_gaps_are_not_flagged(): void
    {
        $els = [
            DeckFixture::text('i0', 'A', ['y' => 100, 'h' => 100, 'w' => 800]),
            DeckFixture::text('i1', 'B', ['y' => 240, 'h' => 100, 'w' => 800]),
            DeckFixture::text('i2', 'C', ['y' => 700, 'h' => 100, 'w' => 800]),
        ];
        $this->assertSame([], DeckFixture::issues($this->analyze([DeckFixture::slide('a', $els)]), 'spacing'));
    }

    public function test_elements_outside_the_slide_are_flagged_and_moved_in(): void
    {
        $slides = [DeckFixture::slide('a', [DeckFixture::text('x', 'Cut off text', ['x' => 1500, 'w' => 800])])];
        $hits = DeckFixture::issues($this->analyze($slides), 'out-of-bounds');
        $this->assertCount(1, $hits);
        $this->assertContains($hits[0]['severity'], ['warn', 'issue']);
        $doc = $this->applyFix($slides, 'out-of-bounds');
        $this->assertSame(1120.0, (float) $doc['slides'][0]['els'][0]['x']);
    }

    public function test_full_bleed_backgrounds_and_tiny_overhangs_are_not_out_of_bounds(): void
    {
        $bleed = DeckFixture::image('bg', ['x' => -20, 'y' => -20, 'w' => 1960, 'h' => 1120, 'alt' => '']);
        $slight = DeckFixture::text('x', 'Fine', ['x' => 120, 'w' => 1801, 'y' => 300, 'h' => 100]);
        $this->assertSame([], DeckFixture::issues($this->analyze([DeckFixture::slide('a', [$bleed, $slight])]), 'out-of-bounds'));
    }

    public function test_overlapping_text_boxes_are_flagged(): void
    {
        $slides = [DeckFixture::slide('a', [DeckFixture::text('a1', 'One box', ['x' => 100, 'y' => 300, 'w' => 600, 'h' => 200]), DeckFixture::text('a2', 'Another box', ['x' => 300, 'y' => 350, 'w' => 600, 'h' => 200])])];
        $this->assertCount(1, DeckFixture::issues($this->analyze($slides), 'overlap'));
        $apart = [DeckFixture::slide('a', [DeckFixture::text('a1', 'One box', ['x' => 100, 'y' => 300, 'w' => 600, 'h' => 200]), DeckFixture::text('a2', 'Another box', ['x' => 800, 'y' => 300, 'w' => 600, 'h' => 200])])];
        $this->assertSame([], DeckFixture::issues($this->analyze($apart), 'overlap'));
    }

    // ---------------------------------------------------------------- text fit

    public function test_text_that_overflows_its_box_is_flagged_with_a_fix(): void
    {
        $long = str_repeat('Production reached a record level this quarter. ', 12);
        $slides = [DeckFixture::slide('a', [DeckFixture::text('x', $long, ['w' => 800, 'h' => 150, 'size' => 40, 'y' => 200])])];
        $hits = DeckFixture::issues($this->analyze($slides), 'text-fit');
        $this->assertCount(1, $hits);
        $this->assertNotEmpty($hits[0]['alternatives']);
        $this->assertStringContainsString('estimated', $hits[0]['message']);
        $doc = $this->applyFix($slides, 'text-fit');
        $this->assertNotSame(150, $doc['slides'][0]['els'][0]['h'] ?? null);
    }

    public function test_text_that_fits_is_not_flagged_and_shrink_boxes_only_warn_when_tiny(): void
    {
        $slides = [DeckFixture::slide('a', [DeckFixture::text('x', 'Short line', ['w' => 800, 'h' => 150, 'size' => 40])])];
        $this->assertSame([], DeckFixture::issues($this->analyze($slides), 'text-fit'));
        $long = str_repeat('Production reached a record level this quarter. ', 4);
        $shrink = [DeckFixture::slide('a', [DeckFixture::text('x', $long, ['w' => 800, 'h' => 200, 'size' => 40, 'fit' => 'shrink'])])];
        $this->assertSame([], DeckFixture::issues($this->analyze($shrink), 'text-fit'));
        $tiny = [DeckFixture::slide('a', [DeckFixture::text('x', str_repeat($long, 8), ['w' => 600, 'h' => 80, 'size' => 40, 'fit' => 'shrink'])])];
        $this->assertCount(1, DeckFixture::issues($this->analyze($tiny), 'text-fit'));
    }

    // ---------------------------------------------------------------- readability

    public function test_small_text_is_flagged_relative_to_slide_height(): void
    {
        $slides = [DeckFixture::slide('a', [DeckFixture::text('x', 'Tiny body text', ['size' => 18])])];
        $hits = DeckFixture::issues($this->analyze($slides), 'min-type-size');
        $this->assertCount(1, $hits);
        $doc = $this->applyFix($slides, 'min-type-size');
        $this->assertGreaterThanOrEqual(24, $doc['slides'][0]['els'][0]['size']);

        $ok = [DeckFixture::slide('a', [DeckFixture::text('x', 'Fine body text', ['size' => 30])])];
        $this->assertSame([], DeckFixture::issues($this->analyze($ok), 'min-type-size'));
        // The same size on a 720 high legacy deck is large.
        $legacy = $this->analyze([DeckFixture::slide('a', [DeckFixture::text('x', 'Legacy', ['size' => 18, 'w' => 800, 'h' => 100])])], ['w' => 1280, 'h' => 720]);
        $this->assertSame([], DeckFixture::issues($legacy, 'min-type-size'));
    }

    public function test_footers_get_a_lower_threshold(): void
    {
        $slides = [DeckFixture::slide('a', [DeckFixture::text('x', 'Page footer', ['size' => 20, 'role' => 'footer', 'y' => 1000, 'h' => 40])])];
        $this->assertSame([], DeckFixture::issues($this->analyze($slides), 'min-type-size'));
    }

    public function test_low_contrast_text_is_flagged_and_gets_a_passing_colour(): void
    {
        $slides = [DeckFixture::slide('a', [DeckFixture::text('x', 'Pale grey on white', ['color' => '#cccccc'])])];
        $hits = DeckFixture::issues($this->analyze($slides), 'contrast');
        $this->assertCount(1, $hits);
        $doc = $this->applyFix($slides, 'contrast');
        $this->assertSame([], DeckFixture::issues((new DeckAnalyzer)->analyze($doc), 'contrast'));

        $ok = [DeckFixture::slide('a', [DeckFixture::text('x', 'Dark on white', ['color' => '#111827'])])];
        $this->assertSame([], DeckFixture::issues($this->analyze($ok), 'contrast'));
    }

    public function test_contrast_uses_the_worst_stop_of_a_gradient_background(): void
    {
        $gradient = ['g' => 'linear', 'a' => 90, 'stops' => [[0, '#112233'], [1, '#f0f0f0']]];
        $slides = [DeckFixture::slide('a', [DeckFixture::text('x', 'White text over a gradient', ['color' => '#ffffff'])], ['bg' => $gradient])];
        $hits = DeckFixture::issues($this->analyze($slides), 'contrast');
        $this->assertCount(1, $hits, 'white text fails on the light end of the gradient');

        $dark = ['g' => 'linear', 'a' => 90, 'stops' => [[0, '#112233'], [1, '#223344']]];
        $ok = [DeckFixture::slide('a', [DeckFixture::text('x', 'White text over a dark gradient', ['color' => '#ffffff'])], ['bg' => $dark])];
        $this->assertSame([], DeckFixture::issues($this->analyze($ok), 'contrast'));
    }

    public function test_contrast_looks_at_the_shape_behind_the_text(): void
    {
        $slides = [DeckFixture::slide('a', [
            DeckFixture::shape('card', ['x' => 100, 'y' => 250, 'w' => 900, 'h' => 300, 'fill' => '#0b1020']),
            DeckFixture::text('x', 'Dark text on a dark card', ['x' => 120, 'y' => 300, 'w' => 800, 'h' => 100, 'color' => '#222222']),
        ])];
        $this->assertCount(1, DeckFixture::issues($this->analyze($slides), 'contrast'));
    }

    public function test_images_behind_text_are_not_guessed(): void
    {
        $slides = [DeckFixture::slide('a', [DeckFixture::text('x', 'Text on a picture', ['color' => '#cccccc'])], ['bg' => ['img' => ['url' => 'https://example.com/x.jpg'], 'fit' => 'cover']])];
        $this->assertSame([], DeckFixture::issues($this->analyze($slides), 'contrast'));
    }

    public function test_long_lines_are_flagged(): void
    {
        $text = str_repeat('Operating cost per tonne was below budget in every month of the year. ', 5);
        $slides = [DeckFixture::slide('a', [DeckFixture::text('x', $text, ['w' => 1700, 'h' => 400, 'size' => 30])])];
        $this->assertCount(1, DeckFixture::issues($this->analyze($slides), 'long-lines'));
        $narrow = [DeckFixture::slide('a', [DeckFixture::text('x', $text, ['w' => 800, 'h' => 900, 'size' => 30])])];
        $this->assertSame([], DeckFixture::issues($this->analyze($narrow), 'long-lines'));
    }

    // ---------------------------------------------------------------- density

    public function test_a_slide_with_too_many_bullets_is_flagged_and_can_be_split(): void
    {
        $bullets = [];
        for ($i = 1; $i <= 12; $i++) {
            $bullets[] = "Point number {$i} about the programme";
        }
        $slides = [DeckFixture::slide('a', [DeckFixture::title('t', 'Everything we do'), DeckFixture::text('b', $bullets, ['bullets' => 1, 'h' => 700])], ['title' => 'Everything we do'])];
        $hits = DeckFixture::issues($this->analyze($slides), 'density');
        $this->assertCount(1, $hits);
        $this->assertSame('issue', $hits[0]['severity']);
        $doc = $this->applyFix($slides, 'density');
        $this->assertCount(2, $doc['slides']);
        $first = DocText::blocks($doc['slides'][0]['els'][1]['doc']);
        $second = DocText::blocks($doc['slides'][1]['els'][1]['doc']);
        $this->assertCount(6, $first);
        $this->assertCount(6, $second);
        $this->assertSame('Everything we do (continued)', $doc['slides'][1]['title']);
        $this->assertSame('Point number 7 about the programme', $second[0]['text']);
    }

    public function test_a_light_slide_is_not_dense(): void
    {
        $this->assertSame([], DeckFixture::issues($this->analyze([$this->clean()]), 'density'));
    }

    public function test_a_long_paragraph_is_flagged(): void
    {
        $para = str_repeat('The plant processed ore at a steady rate through the whole month. ', 8);
        $slides = [DeckFixture::slide('a', [DeckFixture::text('x', $para, ['h' => 700, 'w' => 1400])])];
        $this->assertNotEmpty(DeckFixture::issues($this->analyze($slides), 'long-paragraph'));
    }

    // ---------------------------------------------------------------- hierarchy

    public function test_a_slide_without_a_title_is_flagged(): void
    {
        $slides = [DeckFixture::slide('a', [DeckFixture::text('x', 'Just body text', ['size' => 32, 'y' => 500])])];
        $this->assertCount(1, DeckFixture::issues($this->analyze($slides), 'missing-title'));
        $this->assertSame([], DeckFixture::issues($this->analyze([$this->clean()]), 'missing-title'));
    }

    public function test_several_title_sized_blocks_are_flagged(): void
    {
        $slides = [DeckFixture::slide('a', [
            DeckFixture::title('t1', 'First big thing', ['size' => 72]),
            DeckFixture::text('t2', 'Second big thing', ['size' => 70, 'y' => 250, 'h' => 120]),
            DeckFixture::text('b', 'Small body copy that explains it', ['size' => 28, 'y' => 450]),
        ])];
        $this->assertCount(1, DeckFixture::issues($this->analyze($slides), 'title-competition'));
        $this->assertSame([], DeckFixture::issues($this->analyze([$this->clean()]), 'title-competition'));
    }

    public function test_topic_titles_are_flagged_and_claim_titles_are_not(): void
    {
        $body = DeckFixture::text('b', ['Revenue rose across all three product lines during the quarter', 'Costs were controlled through better purchasing'], ['bullets' => 1]);
        $topic = [DeckFixture::slide('a', [DeckFixture::title('t', 'Financial results'), $body], ['title' => 'Financial results'])];
        $this->assertCount(1, DeckFixture::issues($this->analyze($topic), 'topic-title'));
        $claim = [DeckFixture::slide('a', [DeckFixture::title('t', 'Revenue rose in every product line'), $body], ['title' => 'Revenue rose in every product line'])];
        $this->assertSame([], DeckFixture::issues($this->analyze($claim), 'topic-title'));
        $agenda = [DeckFixture::slide('a', [DeckFixture::title('t', 'Agenda'), $body], ['title' => 'Agenda'])];
        $this->assertSame([], DeckFixture::issues($this->analyze($agenda), 'topic-title'));
    }

    // ---------------------------------------------------------------- terminology

    private function textSlides(array $texts): array
    {
        $slides = [];
        foreach ($texts as $i => $t) {
            $slides[] = DeckFixture::slide('s'.$i, [DeckFixture::title('t'.$i, 'Title '.$i.' says a thing'), DeckFixture::text('b'.$i, $t, ['bullets' => 1])], ['title' => 'Title '.$i.' says a thing']);
        }

        return $slides;
    }

    public function test_email_and_e_mail_are_flagged_and_unified(): void
    {
        $slides = $this->textSlides([['Send email to the team', 'Every email is logged'], ['Reply by e-mail']]);
        $hits = DeckFixture::issues($this->analyze($slides), 'spelling-variants');
        $this->assertCount(1, $hits);
        $doc = $this->applyFix($slides, 'spelling-variants');
        $this->assertSame('Reply by email', DocText::blocks($doc['slides'][1]['els'][1]['doc'])[0]['text']);
        $this->assertSame([], DeckFixture::issues((new DeckAnalyzer)->analyze($doc), 'spelling-variants'));
        $this->assertSame([], DeckFixture::issues($this->analyze($this->textSlides([['Send email to the team'], ['Reply by email']])), 'spelling-variants'));
    }

    public function test_mixed_uk_and_us_spelling_is_flagged_and_follows_the_majority(): void
    {
        $slides = $this->textSlides([['We organise the colour programme', 'The centre analyses behaviour'], ['A neighbouring programme', 'Please organize it']]);
        $hits = DeckFixture::issues($this->analyze($slides), 'spelling-variants');
        $this->assertNotEmpty($hits);
        $doc = $this->applyFix($slides, 'spelling-variants');
        $this->assertSame('Please organise it', DocText::blocks($doc['slides'][1]['els'][1]['doc'])[1]['text']);
    }

    public function test_consistent_spelling_in_either_variety_is_not_flagged(): void
    {
        $uk = $this->textSlides([['We organise the colour programme'], ['The centre analyses behaviour']]);
        $this->assertSame([], DeckFixture::issues($this->analyze($uk), 'spelling-variants'));
        $us = $this->textSlides([['We organize the color program'], ['The center analyzes behavior']]);
        $this->assertSame([], DeckFixture::issues($this->analyze($us), 'spelling-variants'));
    }

    public function test_capitalisation_variants_of_one_term_are_flagged(): void
    {
        $slides = $this->textSlides([['Track the KPI weekly and share the KPI', 'We review the kpi monthly'], ['Our JavaScript code', 'Some Javascript tests', 'More JavaScript']]);
        $hits = DeckFixture::issues($this->analyze($slides), 'case-variants');
        $this->assertCount(2, $hits);
        $doc = $this->applyFix($slides, 'case-variants');
        $this->assertNotEmpty($doc['slides']);
    }

    public function test_ordinary_capitalisation_is_not_flagged(): void
    {
        $slides = $this->textSlides([['The Board met and the board approved it', 'Press the button, then Press play'], ['Safety is first. Safety matters']]);
        $this->assertSame([], DeckFixture::issues($this->analyze($slides), 'case-variants'));
    }

    public function test_inconsistent_thousands_separators_are_flagged(): void
    {
        $slides = $this->textSlides([['Revenue was 1,200,000 in total', 'Costs were 45,000'], ['Profit reached 350000']]);
        $hits = DeckFixture::issues($this->analyze($slides), 'number-format');
        $this->assertCount(1, $hits);
        $doc = $this->applyFix($slides, 'number-format');
        $this->assertSame('Profit reached 350,000', DocText::blocks($doc['slides'][1]['els'][1]['doc'])[0]['text']);
        $this->assertSame([], DeckFixture::issues($this->analyze($this->textSlides([['Revenue was 1,200,000'], ['Costs were 45,000 in 2025']])), 'number-format'));
    }

    public function test_mixed_percent_and_currency_styles_are_flagged(): void
    {
        $slides = $this->textSlides([['Margin was 12% last year', 'Growth of 5 % expected'], ['Cost R 1200 and ZAR 900']]);
        $r = $this->analyze($slides);
        $this->assertNotEmpty(DeckFixture::issues($r, 'number-format'));
        $this->assertNotEmpty(DeckFixture::issues($r, 'currency-format'));
        $doc = $this->applyFix($slides, 'number-format');
        $this->assertSame('Growth of 5% expected', DocText::blocks($doc['slides'][0]['els'][1]['doc'])[1]['text']);
        $consistent = $this->analyze($this->textSlides([['Margin was 12% last year', 'Growth of 5% expected'], ['Cost R1200 and R900']]));
        $this->assertSame([], DeckFixture::issues($consistent, 'number-format'));
        $this->assertSame([], DeckFixture::issues($consistent, 'currency-format'));
    }

    public function test_decimal_point_and_comma_together_are_flagged(): void
    {
        $slides = $this->textSlides([['Recovery was 91.4 on average'], ['The ratio was 0,8']]);
        $this->assertNotEmpty(DeckFixture::issues($this->analyze($slides), 'number-format'));
    }

    public function test_acronyms_used_before_definition_or_never_defined_are_flagged(): void
    {
        $slides = $this->textSlides([['LTIFR fell to 0.8 this year'], ['Lost Time Injury Frequency Rate (LTIFR) is our measure'], ['We also track ROM and NPV and the KPI']]);
        $hits = DeckFixture::issues($this->analyze($slides), 'acronym');
        $messages = implode(' ', array_column($hits, 'message'));
        $this->assertStringContainsString('LTIFR is used on slide 1 but only defined on slide 2', $messages);
        $this->assertStringContainsString('ROM is used but never defined', $messages);
        $this->assertStringContainsString('NPV', $messages);
        $this->assertStringNotContainsString('KPI', $messages, 'common acronyms are not flagged');
    }

    public function test_an_acronym_defined_where_it_first_appears_is_fine(): void
    {
        $slides = $this->textSlides([['Lost Time Injury Frequency Rate (LTIFR) fell to 0.8'], ['LTIFR fell again']]);
        $this->assertSame([], DeckFixture::issues($this->analyze($slides), 'acronym'));
    }

    // ---------------------------------------------------------------- repetition

    public function test_a_bullet_repeated_on_two_slides_is_flagged(): void
    {
        $slides = $this->textSlides([['The mill relining project is on track', 'Diesel is the main risk'], ['Diesel is the main risk', 'The mill relining project is on track']]);
        $hits = DeckFixture::issues($this->analyze($slides), 'repeated-bullet');
        $this->assertCount(2, $hits);
        $this->assertSame([], DeckFixture::issues($this->analyze($this->textSlides([['Safety is first for everyone'], ['Cost per tonne fell this quarter']])), 'repeated-bullet'));
    }

    public function test_near_duplicate_slides_are_flagged_with_a_removal_fix(): void
    {
        $body = ['Production reached 1.2 million tonnes this quarter', 'Recovery averaged 91.4% across the plant', 'Safety performance improved at both sites', 'The conveyor failure cost 36 hours of downtime in August'];
        $slides = [
            DeckFixture::slide('a', [DeckFixture::title('t1', 'Quarter in review'), DeckFixture::text('b1', $body, ['bullets' => 1])], ['title' => 'Quarter in review']),
            DeckFixture::slide('b', [DeckFixture::title('t2', 'Quarter in review'), DeckFixture::text('b2', $body, ['bullets' => 1])], ['title' => 'Quarter in review']),
        ];
        $hits = DeckFixture::issues($this->analyze($slides), 'duplicate-slide');
        $this->assertCount(1, $hits);
        $this->assertSame('b', $hits[0]['slide']);
        $this->assertSame('slide.remove', $hits[0]['fix']['ops'][0]['op']);

        $other = [$slides[0], DeckFixture::slide('c', [DeckFixture::title('t3', 'Risks ahead'), DeckFixture::text('b3', ['Diesel prices could rise further next quarter', 'A weaker rand raises imported spares cost', 'Rain delays are likely in the north pit during summer', 'Skills shortages remain in maintenance planning'], ['bullets' => 1])], ['title' => 'Risks ahead'])];
        $this->assertSame([], DeckFixture::issues($this->analyze($other), 'duplicate-slide'));
    }

    // ---------------------------------------------------------------- claims

    private function claimSlide(array $extraEls = [], array $extra = [], string $text = 'Production grew 40% year on year'): array
    {
        return DeckFixture::slide('a', [DeckFixture::title('t', 'Growth is strong'), DeckFixture::text('b', $text), ...$extraEls], ['title' => 'Growth is strong'] + $extra);
    }

    public function test_unsupported_percentage_money_and_comparative_claims_are_flagged_with_a_question(): void
    {
        foreach (['Production grew 40% year on year', 'We saved R1.2 million last year', 'We are the fastest in the region', 'Output is twice as high as the industry'] as $text) {
            $hits = DeckFixture::issues($this->analyze([$this->claimSlide([], [], $text)]), 'unsupported-claim');
            $this->assertCount(1, $hits, $text);
            $this->assertNotEmpty($hits[0]['claims'][0]['question']);
            $this->assertStringContainsString('Unsupported claim', $hits[0]['message']);
            $this->assertStringNotContainsStringIgnoringCase('false', $hits[0]['message']);
        }
    }

    public function test_claims_with_a_source_chart_or_footnote_are_not_flagged(): void
    {
        $this->assertSame([], DeckFixture::issues($this->analyze([$this->claimSlide([DeckFixture::text('s', 'Source: Annual report 2025', ['y' => 900, 'size' => 24])])]), 'unsupported-claim'));
        $this->assertSame([], DeckFixture::issues($this->analyze([$this->claimSlide([], ['notes' => 'Source: finance team model'])]), 'unsupported-claim'));
        $chart = DeckFixture::chart('c');
        $chart['chart']['data']['series'][0]['values'] = [40, 55];
        $this->assertSame([], DeckFixture::issues($this->analyze([$this->claimSlide([$chart])]), 'unsupported-claim'));
        $this->assertSame([], DeckFixture::issues($this->analyze([$this->claimSlide([], [], 'We rebuilt the process this year')]), 'unsupported-claim'));
    }

    // ---------------------------------------------------------------- placeholders

    public function test_placeholder_text_and_empty_boxes_are_flagged(): void
    {
        foreach (['Lorem ipsum dolor sit amet', 'Revenue: TBD', 'Margin XX%', 'Click to add text', 'Your text here', 'Needs your evidence'] as $text) {
            $r = $this->analyze([DeckFixture::slide('a', [DeckFixture::title('t', 'A title that says something'), DeckFixture::text('x', $text)])]);
            $this->assertCount(1, DeckFixture::issues($r, 'placeholder'), $text);
        }
        $empty = [DeckFixture::slide('a', [DeckFixture::title('t', 'A title that says something'), DeckFixture::text('x', '')])];
        $hits = DeckFixture::issues($this->analyze($empty), 'placeholder');
        $this->assertCount(1, $hits);
        $doc = $this->applyFix($empty, 'placeholder');
        $this->assertCount(1, $doc['slides'][0]['els']);
    }

    public function test_real_text_is_not_a_placeholder(): void
    {
        $r = $this->analyze([DeckFixture::slide('a', [DeckFixture::title('t', 'Index of mines in operation'), DeckFixture::text('x', 'The Excel sheet lists exactly two mines')])]);
        $this->assertSame([], DeckFixture::issues($r, 'placeholder'));
    }

    // ---------------------------------------------------------------- accessibility

    public function test_images_without_alt_text_are_flagged_unless_decorative(): void
    {
        $slides = [DeckFixture::slide('a', [DeckFixture::image('i')])];
        $this->assertCount(1, DeckFixture::issues($this->analyze($slides), 'alt-text'));
        $decorative = [DeckFixture::slide('a', [DeckFixture::image('i', ['alt' => ''])])];
        $this->assertSame([], DeckFixture::issues($this->analyze($decorative), 'alt-text'));
        $described = [DeckFixture::slide('a', [DeckFixture::image('i', ['alt' => 'The north pit at sunrise'])])];
        $this->assertSame([], DeckFixture::issues($this->analyze($described), 'alt-text'));
        $file = [DeckFixture::slide('a', [DeckFixture::image('i', ['alt' => 'IMG_2231.jpg'])])];
        $this->assertCount(1, DeckFixture::issues($this->analyze($file), 'alt-text'));
    }

    public function test_a_chart_without_alt_text_gets_a_description_from_its_data(): void
    {
        $slides = [DeckFixture::slide('a', [DeckFixture::chart('c')])];
        $hits = DeckFixture::issues($this->analyze($slides), 'alt-text');
        $this->assertCount(1, $hits);
        $this->assertStringContainsString('Q1 10', $hits[0]['fix']['ops'][0]['p']['alt']);
        $this->assertStringContainsString('Q2 12', $hits[0]['fix']['ops'][0]['p']['alt']);
    }

    public function test_a_caption_can_become_the_alt_text(): void
    {
        $slides = [DeckFixture::slide('a', [DeckFixture::image('i', ['cap' => 'The conveyor after repair'])])];
        $hits = DeckFixture::issues($this->analyze($slides), 'alt-text');
        $this->assertSame('The conveyor after repair', $hits[0]['fix']['ops'][0]['p']['alt']);
    }

    public function test_slides_without_a_title_property_are_flagged_with_a_fix(): void
    {
        $slides = [DeckFixture::slide('a', [DeckFixture::title('t', 'Costs fell 3% below budget'), DeckFixture::text('b', 'Body', ['y' => 500])], ['title' => ''])];
        $hits = DeckFixture::issues($this->analyze($slides), 'slide-title');
        $this->assertCount(1, $hits);
        $doc = $this->applyFix($slides, 'slide-title');
        $this->assertSame('Costs fell 3% below budget', $doc['slides'][0]['title']);
        $this->assertSame([], DeckFixture::issues($this->analyze([$this->clean()]), 'slide-title'));
    }

    public function test_reading_order_that_differs_from_the_visual_order_is_flagged_and_fixable(): void
    {
        $slides = [DeckFixture::slide('a', [
            DeckFixture::text('body', 'Body text goes second', ['y' => 500, 'h' => 100]),
            DeckFixture::title('title', 'Title goes first visually'),
            DeckFixture::text('foot', 'Footer text last', ['y' => 900, 'h' => 80]),
        ])];
        $hits = DeckFixture::issues($this->analyze($slides), 'reading-order');
        $this->assertCount(1, $hits);
        $doc = $this->applyFix($slides, 'reading-order');
        $this->assertSame(['title', 'body', 'foot'], array_column($doc['slides'][0]['els'], 'id'));
        $this->assertSame([], DeckFixture::issues($this->analyze([$this->clean()]), 'reading-order'));
    }

    public function test_motion_heavy_slides_are_flagged(): void
    {
        $anim = fn ($n, $fx = 'spin') => array_map(fn ($i) => ['id' => 'a'.$i, 'ph' => 'in', 'fx' => $fx, 'on' => 'click', 'delay' => 0, 'dur' => 400, 'ease' => 'out'], range(1, $n));
        $heavy = [DeckFixture::slide('a', [DeckFixture::text('x', 'Spinning', ['anim' => $anim(4)])])];
        $this->assertCount(1, DeckFixture::issues($this->analyze($heavy), 'motion-heavy'));
        $calm = [DeckFixture::slide('a', [DeckFixture::text('x', 'Fading', ['anim' => $anim(3, 'fade')])])];
        $this->assertSame([], DeckFixture::issues($this->analyze($calm), 'motion-heavy'));
    }

    // ---------------------------------------------------------------- animation

    public function test_more_than_twelve_animation_steps_is_flagged(): void
    {
        $anim = fn ($n) => array_map(fn ($i) => ['id' => 'a'.$i, 'ph' => 'in', 'fx' => 'fade', 'on' => 'click', 'delay' => 0, 'dur' => 300, 'ease' => 'out'], range(1, $n));
        $this->assertCount(1, DeckFixture::issues($this->analyze([DeckFixture::slide('a', [DeckFixture::text('x', 'Many', ['anim' => $anim(13)])])]), 'animation-steps'));
        $this->assertSame([], DeckFixture::issues($this->analyze([DeckFixture::slide('a', [DeckFixture::text('x', 'Twelve', ['anim' => $anim(12)])])]), 'animation-steps'));
    }

    // ---------------------------------------------------------------- hostile and large input

    public function test_malformed_documents_do_not_crash(): void
    {
        $doc = ['deck' => ['w' => 'abc', 'h' => null, 'theme' => 'nope'], 'slides' => [
            'not an array',
            ['id' => ['x'], 'els' => 'nope'],
            ['id' => 'ok', 'title' => ['array'], 'els' => [
                'string element',
                ['type' => 'text', 'doc' => ['type' => 'doc', 'content' => 'nope'], 'x' => 'a', 'w' => 'b', 'size' => 'big', 'color' => ['x'], 'fill' => 12],
                ['type' => 'text', 'doc' => 'plain string doc', 'w' => 400, 'h' => 100, 'x' => 0, 'y' => 0],
                ['type' => 'chart', 'chart' => 'nope'],
                ['type' => 'table', 'rows' => [[['t' => 'a'], 'x'], 'y']],
                ['type' => 'image', 'alt' => 12, 'anim' => 'nope'],
            ]],
        ]];
        $r = (new DeckAnalyzer)->analyze($doc);
        $this->assertSame('local', $r['mode']);
        $this->assertIsArray($r['issues']);
    }

    public function test_hostile_text_is_handled(): void
    {
        $evil = str_repeat('a', 200000)."\0\u{202E}<script>alert(1)</script> ".str_repeat('1,', 50000).str_repeat('(', 5000).str_repeat('word ', 20000);
        $slides = [DeckFixture::slide('a', [DeckFixture::text('x', $evil, ['w' => 200, 'h' => 100]), DeckFixture::text('y', [$evil, $evil])])];
        $start = microtime(true);
        $r = $this->analyze($slides);
        $this->assertLessThan(20, microtime(true) - $start, 'analysis of hostile text must stay fast');
        $this->assertSame('local', $r['mode']);
        // Messages never echo more than a short excerpt of the text.
        foreach ($r['issues'] as $i) {
            $this->assertLessThan(2000, strlen(json_encode($i['message'])));
        }
    }

    public function test_a_six_hundred_slide_deck_is_analysed_in_reasonable_time(): void
    {
        $slides = [];
        for ($i = 0; $i < 600; $i++) {
            $slides[] = DeckFixture::slide('s'.$i, [DeckFixture::title('t', "Slide {$i} makes a distinct point number {$i}"), DeckFixture::text('b', ['Unique bullet '.$i.' about topic '.($i * 7), 'Second bullet '.($i * 3).' for the programme'], ['bullets' => 1])], ['title' => "Slide {$i} makes a distinct point number {$i}"]);
        }
        $start = microtime(true);
        $r = $this->analyze($slides);
        $this->assertLessThan(30, microtime(true) - $start);
        $this->assertSame(600, $r['summary']['slides']);
    }

    public function test_issue_counts_per_kind_are_capped_but_still_summarised(): void
    {
        config()->set('press-ai.analyze.max_issues_per_kind', 5);
        $slides = [];
        for ($i = 0; $i < 20; $i++) {
            $slides[] = DeckFixture::slide('s'.$i, [DeckFixture::image('i')], ['title' => 'Slide '.$i]);
        }
        $r = $this->analyze($slides);
        $this->assertCount(5, DeckFixture::issues($r, 'alt-text'));
        $this->assertSame(15, $r['summary']['truncated']['alt-text']);
        $this->assertSame(20, $r['summary']['by_kind']['alt-text']);
    }

    public function test_a_locked_element_never_gets_a_fix(): void
    {
        $slides = [DeckFixture::slide('a', [DeckFixture::text('x', 'Tiny', ['size' => 12, 'lock' => true])])];
        $hits = DeckFixture::issues($this->analyze($slides), 'min-type-size');
        $this->assertCount(1, $hits);
        $this->assertArrayNotHasKey('fix', $hits[0]);
    }

    public function test_every_issue_has_the_documented_shape(): void
    {
        $r = $this->analyze([DeckFixture::slide('a', [DeckFixture::text('x', 'TBD 40% e-mail email', ['size' => 12]), DeckFixture::image('i')])]);
        $this->assertNotEmpty($r['issues']);
        foreach ($r['issues'] as $i) {
            $this->assertContains($i['severity'], ['info', 'warn', 'issue']);
            foreach (['id', 'kind', 'category', 'message', 'why'] as $k) {
                $this->assertNotSame('', (string) $i[$k], $k);
            }
            $this->assertArrayHasKey('slide', $i);
            if (isset($i['fix'])) {
                $this->assertNotSame('', $i['fix']['label']);
                $this->assertLessThanOrEqual(200, count($i['fix']['ops']));
                foreach ($i['fix']['ops'] as $op) {
                    $this->assertArrayHasKey('op', $op);
                }
            }
        }
        $this->assertSame(count(array_unique(array_column($r['issues'], 'id'))), count($r['issues']), 'issue ids are unique');
    }
}
