<?php

namespace Tests\Unit\Press\Intel;

use App\Services\Press\Intel\ContentModel;
use App\Services\Press\Intel\DeckAnalyzer;
use App\Services\Press\Intel\Ids;
use App\Services\Press\Intel\PlainLayout;
use Tests\TestCase;

class ContentModelTest extends TestCase
{
    public function test_make_normalises_and_bounds_a_hostile_proposal(): void
    {
        $m = ContentModel::make([
            'title' => '<script>alert(1)</script>A title',
            'kind' => 'hologram',
            'origin' => 'root',
            'notes' => "Line one\nLine two <b>x</b>",
            'slots' => [
                'bullets' => array_merge(['<img src=x onerror=1>ok', '', null, ['nested']], array_fill(0, 40, 'many')),
                'kpis' => [['label' => 'A', 'value' => ''], ['label' => 'B', 'value' => '12%', 'delta' => '+1%', 'evil' => 'x'], 'junk'],
                'chart' => ['type' => 'weird', 'data' => ['categories' => ['a', 'b'], 'series' => [['name' => 's', 'values' => [1, 'x', null]]]]],
                'unknownslot' => 'dropped',
            ],
            'needs' => [['slot' => 'bullets', 'question' => 'Why?'], 'junk'],
        ]);
        $this->assertSame('alert(1)A title', $m['title']);
        $this->assertSame('bullets', $m['kind']);
        $this->assertSame('local', $m['origin']);
        $this->assertSame("Line one\nLine two x", $m['notes']);
        $this->assertCount(12, $m['slots']['bullets']);
        $this->assertSame('ok', $m['slots']['bullets'][0]);
        $this->assertCount(1, $m['slots']['kpis']);
        $this->assertArrayNotHasKey('evil', $m['slots']['kpis'][0]);
        $this->assertSame('column', $m['slots']['chart']['type']);
        $this->assertSame([1.0, null, null], $m['slots']['chart']['data']['series'][0]['values']);
        $this->assertArrayNotHasKey('unknownslot', $m['slots']);
        $this->assertTrue($m['needs_evidence']);
        $this->assertSame(ContentModel::NEEDS_LABEL, $m['needs'][0]['label']);
        $this->assertSame('ai', ContentModel::make(['origin' => 'ai'])['origin']);
    }

    public function test_a_slide_becomes_a_content_model(): void
    {
        $slide = DeckFixture::slide('s', [
            DeckFixture::title('t', 'Output rose'),
            DeckFixture::text('b', ['Point one', 'Point two'], ['bullets' => 1]),
            DeckFixture::chart('c'),
            DeckFixture::text('f', 'Footer', ['role' => 'footer']),
        ], ['title' => 'Output rose', 'layout' => 'data']);
        $m = ContentModel::fromSlide($slide);
        $this->assertSame('Output rose', $m['title']);
        $this->assertSame('data', $m['kind']);
        $this->assertSame(['Point one', 'Point two'], $m['slots']['bullets']);
        $this->assertSame(['Q1', 'Q2'], $m['slots']['chart']['data']['categories']);
    }

    /** Every plain layout must produce valid, in-bounds elements on both deck sizes. */
    public function test_plain_layouts_are_valid_for_every_kind_and_deck_size(): void
    {
        $chart = ['type' => 'column', 'data' => ['categories' => ['Q1', 'Q2'], 'series' => [['name' => 'T', 'values' => [1, 2]]]], 'opt' => [], 'src' => ['kind' => 'manual']];
        $slots = ['bullets' => ['One', 'Two', 'Three'], 'left' => ['L1'], 'right' => ['R1'], 'steps' => ['Do', 'Then'], 'quote' => 'A quote', 'attribution' => 'Someone', 'chart' => $chart, 'subtitle' => 'Sub',
            'kpis' => [['label' => 'Output', 'value' => '1.2m', 'delta' => '+8%'], ['label' => 'Cost', 'value' => 'R412']]];
        foreach ([[1920, 1080], [1280, 720], [1080, 1920]] as [$w, $h]) {
            foreach (ContentModel::KINDS as $kind) {
                $model = ContentModel::make(['title' => 'A title that states a point', 'kind' => $kind, 'slots' => $slots, 'needs' => [['slot' => 'bullets', 'label' => ContentModel::NEEDS_LABEL, 'question' => 'What is the evidence?']]]);
                $slide = PlainLayout::slide($model, $w, $h, 'k-'.$kind, 'ai');
                $ids = [];
                foreach ($slide['els'] as $el) {
                    $this->assertTrue(Ids::valid($el['id']), $kind);
                    $this->assertNotContains($el['id'], $ids, "$kind ids are unique");
                    $ids[] = $el['id'];
                    $this->assertSame('ai', $el['origin']);
                    $this->assertGreaterThanOrEqual(0, $el['x'], "$kind x");
                    $this->assertGreaterThanOrEqual(0, $el['y'], "$kind y");
                    $this->assertLessThanOrEqual($w, $el['x'] + $el['w'], "$kind right edge at {$w}x{$h}");
                    $this->assertLessThanOrEqual($h, $el['y'] + $el['h'], "$kind bottom edge at {$w}x{$h}");
                    $this->assertContains($el['type'], ['text', 'shape', 'chart']);
                }
                $this->assertContains($slide['layout'], ContentModel::KINDS);
            }
        }
    }

    public function test_local_proposals_leave_the_origin_at_user_and_ai_ones_do_not(): void
    {
        $model = ContentModel::make(['title' => 'T', 'kind' => 'bullets', 'slots' => ['bullets' => ['a']]]);
        foreach (PlainLayout::elements($model, 1920, 1080, 'x') as $el) {
            $this->assertArrayNotHasKey('origin', $el);
        }
        foreach (PlainLayout::elements($model, 1920, 1080, 'x', 'ai') as $el) {
            $this->assertSame('ai', $el['origin']);
        }
    }

    public function test_a_deck_built_from_proposals_is_clean_apart_from_placeholders(): void
    {
        $slides = [];
        foreach (['bullets', 'two', 'compare', 'kpi', 'timeline', 'quote'] as $i => $kind) {
            $model = ContentModel::make(['title' => 'Output rose across all product lines this quarter', 'kind' => $kind, 'slots' => [
                'bullets' => ['Tonnes processed at the plant rose', 'Recovery held steady at the north pit', 'Safety training reached all new starters', 'Costs stayed under budget', 'Diesel use fell'],
                'left' => ['Low cost'], 'right' => ['Fast install'], 'steps' => ['Notify the control room', 'Isolate the source', 'Apply your lock'],
                'quote' => 'Safety is the first thing we check every shift.', 'kpis' => [['label' => 'Output', 'value' => '1.2m'], ['label' => 'Cost', 'value' => 'R412']],
            ]]);
            $s = PlainLayout::slide($model, 1920, 1080, 'k'.$i);
            $slides[] = $s + ['id' => 's'.$i, 'section_id' => null, 'hidden' => false, 'bg' => null, 'transition' => null];
        }
        $r = (new DeckAnalyzer)->analyze(DeckFixture::doc($slides));
        $structural = array_filter($r['issues'], fn ($i) => in_array($i['kind'], ['out-of-bounds', 'overlap', 'text-fit', 'min-type-size', 'contrast', 'alignment'], true));
        $this->assertSame([], array_values($structural), json_encode(array_map(fn ($i) => $i['kind'].': '.$i['message'], $structural)));
    }
}
