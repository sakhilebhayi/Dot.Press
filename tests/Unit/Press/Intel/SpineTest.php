<?php

namespace Tests\Unit\Press\Intel;

use App\Services\Press\Intel\Spine;
use InvalidArgumentException;
use Tests\TestCase;

class SpineTest extends TestCase
{
    private function slide(string $id, string $title, array $body = ['A supporting point about the topic'], array $extra = []): array
    {
        return DeckFixture::slide($id, [DeckFixture::title($id.'t', $title), DeckFixture::text($id.'b', $body, ['bullets' => 1])], ['title' => $title] + $extra);
    }

    /** Eight slides in three sections. */
    private function sectioned(): array
    {
        $sections = [['id' => 'sec1', 'title' => 'Production is up'], ['id' => 'sec2', 'title' => 'Safety is improving'], ['id' => 'sec3', 'title' => 'Costs are under control']];
        $slides = [
            $this->slide('a1', 'Production reached 1.2 million tonnes', ['Up 8% on Q2'], ['section_id' => 'sec1']),
            $this->slide('a2', 'North pit had its best month', ['September output'], ['section_id' => 'sec1']),
            $this->slide('b1', 'Two lost time injuries this quarter', ['Compared with five'], ['section_id' => 'sec2']),
            $this->slide('b2', 'Conveyor failure caused downtime', ['36 hours lost'], ['section_id' => 'sec2']),
            $this->slide('b3', 'Training completion at 96%', ['All new starters trained'], ['section_id' => 'sec2']),
            $this->slide('c1', 'Operating cost per tonne was R412', ['3% below budget'], ['section_id' => 'sec3']),
            $this->slide('c2', 'Diesel is the main risk', ['A weaker rand raises costs'], ['section_id' => 'sec3']),
        ];

        return DeckFixture::doc($slides, ['sections' => $sections]);
    }

    private function claims(array $beats): array
    {
        return array_column($beats, 'claim');
    }

    public function test_beats_follow_the_sections_and_use_their_titles_as_claims(): void
    {
        $r = (new Spine)->extract($this->sectioned());
        $this->assertTrue($r['uses_sections']);
        $this->assertCount(3, $r['beats']);
        $this->assertSame(['Production is up', 'Safety is improving', 'Costs are under control'], $this->claims($r['beats']));
        $this->assertSame(['a1', 'a2'], $r['beats'][0]['slides']);
        $this->assertSame(['b1', 'b2', 'b3'], $r['beats'][1]['slides']);
        $this->assertSame('sec2', $r['beats'][1]['section_id']);
        $this->assertStringStartsWith('b_', $r['beats'][0]['id']);
    }

    public function test_without_sections_runs_of_related_slides_become_beats(): void
    {
        $slides = [
            $this->slide('s1', 'Quarterly review for the board', ['Welcome'], ['layout' => 'title']),
            $this->slide('s2', 'Production reached 1.2 million tonnes', ['Tonnes processed at the plant rose', 'Production was strongest in September']),
            $this->slide('s3', 'Production recovery averaged 91.4%', ['Plant recovery held steady while production rose']),
            $this->slide('s4', 'Safety record improved at both sites', ['Injuries fell and safety training rose', 'Safety audits passed']),
            $this->slide('s5', 'Safety training completion reached 96%', ['Safety induction for new starters']),
            $this->slide('s6', 'Next steps', ['Approve the programme'], ['layout' => 'closing']),
        ];
        $r = (new Spine)->extract(DeckFixture::doc($slides));
        $this->assertFalse($r['uses_sections']);
        $this->assertSame([['s1'], ['s2', 's3'], ['s4', 's5'], ['s6']], array_column($r['beats'], 'slides'));
        $this->assertSame('Production reached 1.2 million tonnes', $r['beats'][1]['claim'] ?? null, 'the claim is a title that states a point');
    }

    public function test_a_section_layout_slide_starts_a_new_beat(): void
    {
        $slides = [
            $this->slide('s1', 'Production reached a record', ['Production tonnes mill plant output']),
            $this->slide('s2', 'Safety', ['Divider'], ['layout' => 'section']),
            $this->slide('s3', 'Safety record improved', ['Safety injuries fell']),
        ];
        $r = (new Spine)->extract(DeckFixture::doc($slides));
        $this->assertSame([['s1'], ['s2', 's3']], array_column($r['beats'], 'slides'));
    }

    public function test_a_beat_with_no_evidence_is_hollow(): void
    {
        $slides = [
            $this->slide('a', 'We lead the market', ['Customers love it', 'The team is excellent']),
            $this->slide('b', 'We lead the market in service', ['Customers love the service', 'The team is excellent'], ['section_id' => null]),
            DeckFixture::slide('c', [DeckFixture::title('ct', 'Output rose in Q3'), DeckFixture::chart('cc')], ['title' => 'Output rose in Q3', 'layout' => 'data']),
        ];
        $r = (new Spine)->extract(DeckFixture::doc($slides));
        $hollow = array_column($r['beats'], 'hollow');
        $this->assertTrue($hollow[0]);
        $this->assertFalse(end($hollow));
        $this->assertSame(1, end($r['beats'])['evidence']['charts']);
        $this->assertFalse($this->sectionedBeat('hollow'), 'a beat with figures in its text is not hollow');
    }

    private function sectionedBeat(string $key): mixed
    {
        return (new Spine)->extract($this->sectioned())['beats'][0][$key];
    }

    public function test_the_beat_id_survives_reordering(): void
    {
        $doc = $this->sectioned();
        $before = (new Spine)->extract($doc)['beats'];
        $reordered = $doc;
        $reordered['slides'] = array_merge(array_slice($doc['slides'], 2, 3), array_slice($doc['slides'], 0, 2), array_slice($doc['slides'], 5));
        $after = (new Spine)->extract($reordered)['beats'];
        $this->assertSame($before[0]['id'], $after[1]['id']);
    }

    // ---------------------------------------------------------------- edits

    private function ids(array $beats): array
    {
        return array_column($beats, 'id');
    }

    public function test_reorder_moves_all_slides_of_a_beat(): void
    {
        $doc = $this->sectioned();
        $beats = (new Spine)->extract($doc)['beats'];
        $r = (new Spine)->applyEdit($doc, ['type' => 'reorder', 'beat' => $beats[2]['id'], 'after' => null]);
        $after = OpsApplier::apply($doc, $r['ops']);
        $this->assertSame(['c1', 'c2', 'a1', 'a2', 'b1', 'b2', 'b3'], OpsApplier::order($after));
        $this->assertSame($r['order'], OpsApplier::order($after));
        $this->assertFalse($r['adopted_sections']);

        $r2 = (new Spine)->applyEdit($doc, ['type' => 'reorder', 'beat' => $beats[0]['id'], 'after' => $beats[1]['id']]);
        $this->assertSame(['b1', 'b2', 'b3', 'a1', 'a2', 'c1', 'c2'], OpsApplier::order(OpsApplier::apply($doc, $r2['ops'])));

        // re-extracting the edited deck gives the beats the edit promised
        $again = (new Spine)->extract(OpsApplier::apply($doc, $r2['ops']))['beats'];
        $this->assertSame(array_column($r2['beats'], 'slides'), array_column($again, 'slides'));
        $this->assertSame($this->claims($r2['beats']), $this->claims($again));
    }

    public function test_the_moves_are_few_when_little_changes(): void
    {
        $doc = $this->sectioned();
        $beats = (new Spine)->extract($doc)['beats'];
        $r = (new Spine)->applyEdit($doc, ['type' => 'reorder', 'beat' => $beats[2]['id'], 'after' => $beats[0]['id']]);
        $moves = array_filter($r['ops'], fn ($o) => $o['op'] === 'slide.move');
        $this->assertLessThanOrEqual(5, count($moves));
        $this->assertSame(['a1', 'a2', 'c1', 'c2', 'b1', 'b2', 'b3'], OpsApplier::order(OpsApplier::apply($doc, $r['ops'])));
    }

    public function test_merge_joins_a_beat_into_another_and_removes_its_section(): void
    {
        $doc = $this->sectioned();
        $beats = (new Spine)->extract($doc)['beats'];
        $r = (new Spine)->applyEdit($doc, ['type' => 'merge', 'beat' => $beats[2]['id'], 'into' => $beats[0]['id']]);
        $after = OpsApplier::apply($doc, $r['ops']);
        $this->assertSame(['a1', 'a2', 'c1', 'c2', 'b1', 'b2', 'b3'], OpsApplier::order($after));
        $this->assertSame(['sec1', 'sec2'], array_column($after['deck']['sections'], 'id'));
        $again = (new Spine)->extract($after)['beats'];
        $this->assertCount(2, $again);
        $this->assertSame(['a1', 'a2', 'c1', 'c2'], $again[0]['slides']);
    }

    public function test_split_starts_a_new_beat_with_its_own_claim(): void
    {
        $doc = $this->sectioned();
        $beats = (new Spine)->extract($doc)['beats'];
        $r = (new Spine)->applyEdit($doc, ['type' => 'split', 'beat' => $beats[1]['id'], 'at' => 'b3', 'claim' => 'Training is complete']);
        $after = OpsApplier::apply($doc, $r['ops']);
        $again = (new Spine)->extract($after)['beats'];
        $this->assertCount(4, $again);
        $this->assertSame(['b1', 'b2'], $again[1]['slides']);
        $this->assertSame(['b3'], $again[2]['slides']);
        $this->assertSame('Training is complete', $again[2]['claim']);
        $this->assertSame(OpsApplier::order($doc), OpsApplier::order($after), 'a split does not reorder');
    }

    public function test_rename_changes_only_the_claim(): void
    {
        $doc = $this->sectioned();
        $beats = (new Spine)->extract($doc)['beats'];
        $r = (new Spine)->applyEdit($doc, ['type' => 'rename', 'beat' => $beats[0]['id'], 'claim' => 'Output hit a record']);
        $this->assertSame([['op' => 'section.set', 'id' => 'sec1', 'title' => 'Output hit a record']], $r['ops']);
        $after = OpsApplier::apply($doc, $r['ops']);
        $this->assertSame('Output hit a record', (new Spine)->extract($after)['beats'][0]['claim']);
    }

    public function test_the_first_edit_on_a_deck_without_sections_adopts_them(): void
    {
        $slides = [
            $this->slide('s2', 'Production reached 1.2 million tonnes', ['Tonnes processed at the plant rose', 'Production was strongest in September']),
            $this->slide('s3', 'Production recovery averaged 91.4%', ['Plant recovery held steady while production rose']),
            $this->slide('s4', 'Safety record improved at both sites', ['Injuries fell and safety training rose']),
            $this->slide('s5', 'Safety training completion reached 96%', ['Safety induction for new starters']),
        ];
        $doc = DeckFixture::doc($slides);
        $spine = new Spine;
        $beats = $spine->extract($doc)['beats'];
        $this->assertCount(2, $beats);
        $r = $spine->applyEdit($doc, ['type' => 'reorder', 'beat' => $beats[1]['id'], 'after' => null]);
        $this->assertTrue($r['adopted_sections']);
        $after = OpsApplier::apply($doc, $r['ops']);
        $this->assertSame(['s4', 's5', 's2', 's3'], OpsApplier::order($after));
        $this->assertCount(2, $after['deck']['sections']);
        $again = $spine->extract($after);
        $this->assertTrue($again['uses_sections']);
        $this->assertSame([['s4', 's5'], ['s2', 's3']], array_column($again['beats'], 'slides'));
        $this->assertSame('Safety record improved at both sites', $again['beats'][0]['claim']);
    }

    public function test_invalid_edits_are_refused(): void
    {
        $doc = $this->sectioned();
        $beats = (new Spine)->extract($doc)['beats'];
        $spine = new Spine;
        foreach ([
            ['type' => 'reorder', 'beat' => 'b_nope'],
            ['type' => 'reorder', 'beat' => $beats[0]['id'], 'after' => 'b_nope'],
            ['type' => 'reorder', 'beat' => $beats[0]['id'], 'after' => $beats[0]['id']],
            ['type' => 'merge', 'beat' => $beats[0]['id'], 'into' => $beats[0]['id']],
            ['type' => 'merge', 'beat' => $beats[0]['id'], 'into' => 'nope'],
            ['type' => 'split', 'beat' => $beats[1]['id'], 'at' => 'b1'],
            ['type' => 'split', 'beat' => $beats[1]['id'], 'at' => 'a1'],
            ['type' => 'rename', 'beat' => $beats[1]['id'], 'claim' => '   '],
            ['type' => 'explode', 'beat' => $beats[1]['id']],
        ] as $edit) {
            try {
                $spine->applyEdit($doc, $edit);
                $this->fail('accepted '.json_encode($edit));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_numeric_slide_ids_keep_their_type_in_the_operations(): void
    {
        $slides = [$this->slide('1', 'Production up now', ['Tonnes rose'], ['id' => 1]), $this->slide('2', 'Safety up now', ['Injuries fell'], ['id' => 2, 'layout' => 'section'])];
        $doc = DeckFixture::doc($slides);
        $beats = (new Spine)->extract($doc)['beats'];
        $this->assertSame([1], $beats[0]['slides']);
        $r = (new Spine)->applyEdit($doc, ['type' => 'reorder', 'beat' => $beats[1]['id'], 'after' => null]);
        $moves = array_values(array_filter($r['ops'], fn ($o) => $o['op'] === 'slide.move'));
        $this->assertSame(2, $moves[0]['id']);
        $this->assertSame(['2', '1'], OpsApplier::order(OpsApplier::apply($doc, $r['ops'])));
    }
}
