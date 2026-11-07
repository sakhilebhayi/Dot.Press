<?php

namespace Tests\Feature\Press\Security;

use Illuminate\Support\Str;
use Tests\Feature\Press\AccessTestCase;

/**
 * Silent data loss: Laravel's TrimStrings middleware strips the spaces at both ends of every string in a request,
 * and a run of rich text that ends in a space ("This is ", then a bold word) is such a string.
 */
class OpsTextIntegrityTest extends AccessTestCase
{
    public function test_spaces_at_the_edges_of_text_runs_survive_an_ops_request(): void
    {
        $doc = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'This is '],
            ['type' => 'text', 'text' => 'important', 'marks' => [['type' => 'bold']]],
            ['type' => 'text', 'text' => ' and '],
            ['type' => 'text', 'text' => "line\n"],
        ]]]];

        $this->actingAs($this->editor)->postJson("/api/decks/{$this->deck->id}/ops", [
            'client' => 'tab-1', 'key' => 'k-'.Str::uuid(), 'ops' => [
                ['op' => 'el.add', 'slide' => $this->slide->id, 'el' => ['id' => 't1', 'type' => 'text', 'x' => 1, 'y' => 1, 'w' => 10, 'h' => 10, 'doc' => $doc]],
                ['op' => 'el.add', 'slide' => $this->slide->id, 'el' => ['id' => 'tb', 'type' => 'table', 'x' => 1, 'y' => 1, 'w' => 10, 'h' => 10, 'rows' => [[['t' => '  indented'], ['t' => 'trailing ']]]]],
                ['op' => 'slide.set', 'id' => $this->slide->id, 'notes' => "  Say hello \n  then pause  "],
            ],
        ])->assertOk()->assertJsonPath('skipped', []);

        $slide = $this->slide->fresh();
        $texts = array_column($slide->els[1]['doc']['content'][0]['content'], 'text');
        $this->assertSame(['This is ', 'important', ' and ', "line\n"], $texts);
        $this->assertSame(['  indented', 'trailing '], array_column($slide->els[2]['rows'][0], 't'));
        $this->assertSame("  Say hello \n  then pause  ", $slide->notes);
    }

    public function test_the_changes_feed_replays_the_same_spaces_to_other_editors(): void
    {
        $doc = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'a '], ['type' => 'text', 'text' => 'b', 'marks' => [['type' => 'italic']]]]]]];
        $this->actingAs($this->editor)->postJson("/api/decks/{$this->deck->id}/ops", [
            'client' => 'tab-1', 'key' => 'k-'.Str::uuid(),
            'ops' => [['op' => 'el.add', 'slide' => $this->slide->id, 'el' => ['id' => 't1', 'type' => 'text', 'x' => 1, 'y' => 1, 'w' => 10, 'h' => 10, 'doc' => $doc]]],
        ])->assertOk();

        $feed = $this->actingAs($this->owner)->getJson("/api/decks/{$this->deck->id}/changes?after=0")->assertOk()->json('changes.0.ops.0.el.doc.content.0.content.0.text');
        $this->assertSame('a ', $feed);
    }
}
