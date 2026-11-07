<?php

namespace Tests\Feature\Press;

use App\Models\Asset;
use App\Models\DeckActivity;
use App\Models\DeckChange;
use App\Models\Slide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpsApplyTest extends TestCase
{
    use MakesDecks;
    use RefreshDatabase;

    public function test_an_element_can_be_added_changed_ordered_and_removed(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user);
        $slide = $deck->slides()->first();

        $response = $this->ops($user, $deck, [
            ['op' => 'el.add', 'slide' => $slide->id, 'el' => $this->shape('a')],
            ['op' => 'el.add', 'slide' => $slide->id, 'el' => $this->shape('b', ['x' => 5])],
            ['op' => 'el.add', 'slide' => $slide->id, 'el' => $this->shape('c'), 'index' => 0],
        ])->assertOk();

        $response->assertJsonPath('skipped', [])->assertJsonPath('deck_rev', 1)->assertJsonPath('slides.'.$slide->id, 1);
        $this->assertSame(['c', 'a', 'b'], array_column($this->els($slide), 'id'));

        $this->ops($user, $deck, [
            ['op' => 'el.set', 'slide' => $slide->id, 'id' => 'a', 'p' => ['x' => '250.5', 'fill' => '@accent']],
            ['op' => 'el.order', 'slide' => $slide->id, 'ids' => ['b', 'a']],
        ])->assertOk()->assertJsonPath('skipped', []);

        // Ids missing from the list stay on top in their old order: c was below, so it ends up last.
        $this->assertSame(['b', 'a', 'c'], array_column($this->els($slide), 'id'));
        $a = collect($this->els($slide))->firstWhere('id', 'a');
        $this->assertEquals(250.5, $a['x']);
        $this->assertSame('@accent', $a['fill']);

        $this->ops($user, $deck, [['op' => 'el.remove', 'slide' => $slide->id, 'id' => 'b']])->assertOk();
        $this->assertSame(['a', 'c'], array_column($this->els($slide), 'id'));
    }

    public function test_el_set_with_null_deletes_a_key_and_keeps_others(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user);
        $slide = $deck->slides()->first();
        $this->ops($user, $deck, [['op' => 'el.add', 'slide' => $slide->id, 'el' => $this->shape('a', ['stroke' => '#000000', 'sw' => 4])]]);

        $this->ops($user, $deck, [['op' => 'el.set', 'slide' => $slide->id, 'id' => 'a', 'p' => ['stroke' => null]]])->assertOk();

        $el = $this->els($slide)[0];
        $this->assertArrayNotHasKey('stroke', $el);
        $this->assertSame(4, $el['sw']);
    }

    public function test_two_clients_changing_different_elements_or_different_keys_of_one_element_do_not_conflict(): void
    {
        $user = $this->owner();
        $other = $this->teammate($user);
        $deck = $this->deckFor($user);
        $slide = $deck->slides()->first();
        $this->ops($user, $deck, [
            ['op' => 'el.add', 'slide' => $slide->id, 'el' => $this->shape('a')],
            ['op' => 'el.add', 'slide' => $slide->id, 'el' => $this->shape('b')],
        ]);

        // Both clients started from the same document; neither sees the other's change before sending.
        $this->ops($user, $deck, [['op' => 'el.set', 'slide' => $slide->id, 'id' => 'a', 'p' => ['x' => 1]]], 'k1', 'tab-1')->assertOk();
        $this->ops($other, $deck, [['op' => 'el.set', 'slide' => $slide->id, 'id' => 'b', 'p' => ['x' => 2]]], 'k2', 'tab-2')->assertOk();
        $this->ops($user, $deck, [['op' => 'el.set', 'slide' => $slide->id, 'id' => 'a', 'p' => ['w' => 77]]], 'k3', 'tab-1')->assertOk();
        $this->ops($other, $deck, [['op' => 'el.set', 'slide' => $slide->id, 'id' => 'a', 'p' => ['h' => 66, 'x' => 9]]], 'k4', 'tab-2')->assertOk();

        $els = collect($this->els($slide))->keyBy('id');
        $this->assertSame(2, $els['b']['x']);
        $this->assertSame(77, $els['a']['w']);
        $this->assertSame(66, $els['a']['h']);
        // The same key changed twice: the later operation wins.
        $this->assertSame(9, $els['a']['x']);
    }

    public function test_a_repeated_key_applies_nothing_and_returns_the_stored_result(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user);
        $slide = $deck->slides()->first();
        $ops = [['op' => 'el.add', 'slide' => $slide->id, 'el' => $this->shape('a')]];

        $first = $this->ops($user, $deck, $ops, 'same-key')->assertOk()->json();
        $second = $this->ops($user, $deck, $ops, 'same-key')->assertOk()->json();

        $this->assertSame($first, $second);
        $this->assertCount(1, $this->els($slide));
        $this->assertSame(1, DeckChange::where('deck_id', $deck->id)->count());
        $this->assertSame(1, $deck->fresh()->rev);
    }

    public function test_someone_else_cannot_reuse_a_key(): void
    {
        $user = $this->owner();
        $other = $this->teammate($user);
        $deck = $this->deckFor($user);
        $slide = $deck->slides()->first();
        $ops = [['op' => 'el.add', 'slide' => $slide->id, 'el' => $this->shape('a')]];

        $this->ops($user, $deck, $ops, 'shared-key')->assertOk();
        $this->ops($other, $deck, $ops, 'shared-key')->assertStatus(409);
    }

    public function test_ops_the_server_cannot_apply_are_skipped_with_a_reason_never_an_error(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user);
        $slide = $deck->slides()->first();

        $response = $this->ops($user, $deck, [
            ['op' => 'el.set', 'slide' => $slide->id, 'id' => 'ghost', 'p' => ['x' => 1]],
            ['op' => 'el.remove', 'slide' => $slide->id, 'id' => 'ghost'],
            ['op' => 'el.add', 'slide' => 999999, 'el' => $this->shape('a')],
            ['op' => 'nope'],
            ['op' => 'deck.reload'],
            'not an op',
            ['op' => 'el.add', 'slide' => $slide->id, 'el' => $this->shape('ok')],
        ])->assertOk();

        $response->assertJsonCount(6, 'skipped');
        $this->assertSame('element not found', $response->json('skipped.0.reason'));
        $this->assertSame('unknown slide', $response->json('skipped.2.reason'));
        $this->assertSame('unknown op', $response->json('skipped.3.reason'));
        $this->assertStringContainsString('server only', $response->json('skipped.4.reason'));
        $this->assertSame(['ok'], array_column($this->els($slide), 'id'));
    }

    public function test_a_batch_with_nothing_applied_logs_nothing_and_keeps_the_revision(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user);

        $this->ops($user, $deck, [['op' => 'el.remove', 'slide' => $deck->slides()->first()->id, 'id' => 'ghost']])
            ->assertOk()->assertJsonPath('deck_rev', 0);

        $this->assertSame(0, DeckChange::where('deck_id', $deck->id)->count());
    }

    public function test_slides_can_be_added_with_temp_ids_chained_moved_and_removed(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user);
        $first = $deck->slides()->first();

        $response = $this->ops($user, $deck, [
            ['op' => 'slide.add', 'tid' => 't1', 'after' => $first->id, 'slide' => ['title' => 'Two', 'layout' => 'bullets', 'els' => [$this->shape('x')]]],
            ['op' => 'slide.add', 'tid' => 't2', 'after' => 't1', 'slide' => ['title' => 'Three']],
            ['op' => 'el.add', 'slide' => 't2', 'el' => $this->shape('y')],
        ])->assertOk();

        $ids = $response->json('ids');
        $this->assertSame([$first->id, $ids['t1'], $ids['t2']], $deck->slides()->pluck('id')->all());
        $this->assertSame(['y'], array_column($this->els(Slide::find($ids['t2'])), 'id'));
        $this->assertSame('bullets', Slide::find($ids['t1'])->layout);

        $this->ops($user, $deck, [['op' => 'slide.move', 'id' => $ids['t2'], 'after' => null]])->assertOk();
        $this->assertSame([$ids['t2'], $first->id, $ids['t1']], $deck->slides()->pluck('id')->all());

        $this->ops($user, $deck, [
            ['op' => 'slide.set', 'id' => $first->id, 'title' => 'Renamed', 'notes' => 'Say hello', 'hidden' => true, 'transition' => ['type' => 'fade', 'dur' => 300]],
            ['op' => 'slide.remove', 'id' => $ids['t1']],
        ])->assertOk()->assertJsonPath('skipped', []);

        $first->refresh();
        $this->assertSame('Renamed', $first->title);
        $this->assertTrue($first->hidden);
        $this->assertSame('fade', $first->transition['type']);
        $this->assertSame(2, $deck->slides()->count());
    }

    public function test_a_deck_always_keeps_one_slide(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user);
        $only = $deck->slides()->first();

        $this->ops($user, $deck, [['op' => 'slide.remove', 'id' => $only->id]])
            ->assertOk()
            ->assertJsonPath('skipped.0.reason', 'a deck keeps at least one slide');

        $this->assertSame(1, $deck->slides()->count());

        // Adding then removing in one batch still leaves one.
        $this->ops($user, $deck, [
            ['op' => 'slide.add', 'tid' => 'n', 'after' => $only->id, 'slide' => []],
            ['op' => 'slide.remove', 'id' => $only->id],
            ['op' => 'slide.remove', 'id' => 'n'],
        ])->assertOk()->assertJsonCount(1, 'skipped');
        $this->assertSame(1, $deck->slides()->count());
    }

    public function test_duplicating_a_slide_gives_new_element_ids_and_fresh_group_ids(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user);
        $slide = $deck->slides()->first();
        $this->ops($user, $deck, [
            ['op' => 'el.add', 'slide' => $slide->id, 'el' => $this->shape('a', ['group' => 'g1'])],
            ['op' => 'el.add', 'slide' => $slide->id, 'el' => $this->shape('b', ['group' => 'g1', 'morph' => 'hero'])],
            ['op' => 'el.add', 'slide' => $slide->id, 'el' => $this->shape('c')],
        ]);

        $response = $this->ops($user, $deck, [['op' => 'slide.dup', 'id' => $slide->id, 'tid' => 'd']])->assertOk();

        $copy = Slide::find($response->json('ids.d'));
        $this->assertSame([$slide->id, $copy->id], $deck->slides()->pluck('id')->all());
        $copyEls = $copy->els;
        $this->assertCount(3, $copyEls);
        $this->assertEmpty(array_intersect(array_column($copyEls, 'id'), ['a', 'b', 'c']));
        $this->assertCount(3, array_unique(array_column($copyEls, 'id')));
        $this->assertNotSame('g1', $copyEls[0]['group']);
        $this->assertSame($copyEls[0]['group'], $copyEls[1]['group']);
        // Morph pairing survives: the copy morphs with the original.
        $this->assertSame('hero', $copyEls[1]['morph']);
        $this->assertSame('a', $copyEls[0]['morph']);
        // The original is untouched.
        $this->assertSame(['a', 'b', 'c'], array_column($this->els($slide), 'id'));
    }

    public function test_elements_move_between_slides(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user, 'D', 2);
        [$one, $two] = $deck->slides()->get()->all();
        $this->ops($user, $deck, [
            ['op' => 'el.add', 'slide' => $one->id, 'el' => $this->shape('a')],
            ['op' => 'el.add', 'slide' => $two->id, 'el' => $this->shape('z')],
        ]);

        $this->ops($user, $deck, [['op' => 'el.move', 'from' => $one->id, 'id' => 'a', 'to' => $two->id, 'index' => 0]])
            ->assertOk()->assertJsonPath('skipped', []);

        $this->assertSame([], $this->els($one));
        $this->assertSame(['a', 'z'], array_column($this->els($two), 'id'));

        $this->ops($user, $deck, [['op' => 'el.move', 'from' => $two->id, 'id' => 'a', 'to' => $two->id]])
            ->assertJsonPath('skipped.0.reason', 'same slide, use el.order');
    }

    public function test_sections_can_be_added_renamed_and_removed(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user, 'D', 2);
        [$one, $two] = $deck->slides()->get()->all();

        $this->ops($user, $deck, [
            ['op' => 'section.add', 'id' => 's1', 'title' => 'Intro'],
            ['op' => 'section.add', 'id' => 's2', 'title' => 'Body', 'after' => 's1'],
            ['op' => 'section.add', 'id' => 's0', 'title' => 'Cover', 'after' => null],
            ['op' => 'slide.set', 'id' => $one->id, 'section_id' => 's1'],
            ['op' => 'slide.set', 'id' => $two->id, 'section_id' => 'nope'],
        ])->assertOk()->assertJsonPath('skipped.0.reason', 'unknown section');

        $this->assertSame(['s0', 's1', 's2'], array_column($deck->fresh()->sections, 'id'));

        $this->ops($user, $deck, [
            ['op' => 'section.set', 'id' => 's1', 'title' => 'Opening'],
            ['op' => 'section.remove', 'id' => 's1'],
        ])->assertOk();

        $this->assertSame(['s0', 's2'], array_column($deck->fresh()->sections, 'id'));
        $this->assertNull($one->fresh()->section_id);
    }

    public function test_deck_set_renames_records_activity_and_refuses_size_and_approval(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user, 'Old');

        $response = $this->ops($user, $deck, [['op' => 'deck.set', 'title' => 'New', 'w' => 100, 'h' => 100, 'status' => 'approved']])->assertOk();

        $this->assertSame('New', $deck->fresh()->title);
        $this->assertSame(1920, $deck->fresh()->w);
        $this->assertSame('draft', $deck->fresh()->status);
        $this->assertCount(2, $response->json('skipped'));
        $this->assertTrue(DeckActivity::where('deck_id', $deck->id)->where('event', 'renamed')->exists());

        $this->ops($user, $deck, [['op' => 'deck.set', 'status' => 'in_review', 'theme' => ['name' => 'Mine', 'c' => ['accent' => '#ff0000']]]])->assertOk();
        $fresh = $deck->fresh();
        $this->assertSame('in_review', $fresh->status);
        $this->assertSame('#ff0000', $fresh->theme['c']['accent']);
        $this->assertSame('#111827', $fresh->theme['c']['ink']);
    }

    public function test_every_batch_gets_a_seq_in_the_log_with_the_applied_ops_and_real_ids(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user);
        $slide = $deck->slides()->first();

        $response = $this->ops($user, $deck, [
            ['op' => 'slide.add', 'tid' => 't', 'after' => $slide->id, 'slide' => ['title' => 'N']],
            ['op' => 'el.add', 'slide' => 't', 'el' => $this->shape('a')],
            ['op' => 'el.remove', 'slide' => $slide->id, 'id' => 'ghost'],
        ], 'logged', 'tab-9')->assertOk();

        $change = DeckChange::where('deck_id', $deck->id)->first();
        $this->assertSame($change->id, $response->json('seq'));
        $this->assertSame('tab-9', $change->client);
        $this->assertSame($user->id, $change->user_id);
        $this->assertCount(2, $change->ops);
        $this->assertSame($response->json('ids.t'), $change->ops[0]['id']);
        $this->assertSame($response->json('ids.t'), $change->ops[1]['slide']);
        $this->assertSame($response->json(), $change->result);
    }

    public function test_foreign_assets_cannot_be_referenced(): void
    {
        $user = $this->owner();
        $stranger = $this->owner();
        $deck = $this->deckFor($user);
        $slide = $deck->slides()->first();

        $mine = $this->assetIn($deck->project);
        $theirs = $this->assetIn($this->deckFor($stranger)->project);

        $response = $this->ops($user, $deck, [
            ['op' => 'el.add', 'slide' => $slide->id, 'el' => ['id' => 'ok', 'type' => 'image', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'src' => ['asset' => $mine->id]]],
            ['op' => 'el.add', 'slide' => $slide->id, 'el' => ['id' => 'bad', 'type' => 'image', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'src' => ['asset' => $theirs->id]]],
            ['op' => 'slide.set', 'id' => $slide->id, 'bg' => ['img' => ['asset' => $theirs->id]]],
        ])->assertOk();

        $this->assertSame(['ok'], array_column($this->els($slide), 'id'));
        $this->assertSame('unknown asset', $response->json('skipped.0.reason'));
        $this->assertSame('unknown asset', $response->json('skipped.1.reason'));
    }

    public function test_the_document_lists_the_assets_it_uses(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user);
        $asset = $this->assetIn($deck->project);
        $this->slideFor($deck, 'Pic', 1, [['id' => 'i', 'type' => 'image', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'src' => ['asset' => $asset->id]]]);

        $this->actingAs($user)->getJson('/api/decks/'.$deck->id.'/document')
            ->assertOk()
            ->assertJsonPath('assets.'.$asset->id.'.name', 'a.png')
            ->assertJsonPath('assets.'.$asset->id.'.mime', 'image/png')
            ->assertJsonStructure(['deck' => ['id', 'w', 'h', 'theme', 'rev', 'slide_order'], 'slides', 'seq', 'assets']);

        $this->actingAs($user)->getJson('/api/decks/'.$this->deckFor($user)->id.'/document')->assertOk()->assertJsonPath('assets', []);
    }

    public function test_revisions_count_per_slide_and_per_deck(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user, 'D', 2);
        [$one, $two] = $deck->slides()->get()->all();

        $this->ops($user, $deck, [
            ['op' => 'el.add', 'slide' => $one->id, 'el' => $this->shape('a')],
            ['op' => 'el.add', 'slide' => $one->id, 'el' => $this->shape('b')],
        ])->assertJsonPath('slides.'.$one->id, 1);
        $this->ops($user, $deck, [['op' => 'el.set', 'slide' => $two->id, 'id' => 'x', 'p' => ['x' => 1]]]);
        $r = $this->ops($user, $deck, [['op' => 'el.set', 'slide' => $one->id, 'id' => 'a', 'p' => ['x' => 1]]])->assertOk();

        $this->assertSame(2, $r->json('slides.'.$one->id));
        $this->assertArrayNotHasKey($two->id, $r->json('slides'));
        $this->assertSame(2, $r->json('deck_rev'));
        $this->assertSame(0, $two->fresh()->revision);
    }

    private function assetIn($project): Asset
    {
        return Asset::create([
            'project_id' => $project->id, 'uploaded_by' => $project->user_id, 'disk' => 'local', 'path' => 'x/a.png',
            'original_name' => 'a.png', 'mime_type' => 'image/png', 'size' => 10, 'metadata' => [],
        ]);
    }
}
