<?php

namespace Tests\Feature\Press;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * alt="" is how a picture is marked decorative. The ops endpoint must keep the empty string instead of letting
 * Laravel turn it into null, because el.set reads null as "delete the key".
 */
class OpsEmptyAltTest extends TestCase
{
    use MakesDecks;
    use RefreshDatabase;

    public function test_an_empty_alt_is_stored_and_null_still_removes_it(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user);
        $slide = $deck->slides()->first();
        $image = ['id' => 'pic1', 'type' => 'image', 'x' => 10, 'y' => 10, 'w' => 200, 'h' => 100, 'src' => ['url' => 'https://example.com/a.png']];
        $this->ops($user, $deck, [['op' => 'el.add', 'slide' => $slide->id, 'el' => $image]])->assertOk();

        $this->ops($user, $deck, [['op' => 'el.set', 'slide' => $slide->id, 'id' => 'pic1', 'p' => ['alt' => '']]])->assertOk();
        $stored = collect($slide->fresh()->els)->firstWhere('id', 'pic1');
        $this->assertArrayHasKey('alt', $stored);
        $this->assertSame('', $stored['alt']);

        $this->ops($user, $deck, [['op' => 'el.set', 'slide' => $slide->id, 'id' => 'pic1', 'p' => ['alt' => null]]])->assertOk();
        $this->assertArrayNotHasKey('alt', collect($slide->fresh()->els)->firstWhere('id', 'pic1'));
    }

    public function test_spaces_at_the_edges_of_a_text_run_are_kept(): void
    {
        $user = $this->owner();
        $deck = $this->deckFor($user);
        $slide = $deck->slides()->first();
        $doc = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Typography ', 'marks' => [['type' => 'bold']]],
            ['type' => 'text', 'text' => 'and ', 'marks' => [['type' => 'italic']]],
            ['type' => 'text', 'text' => ' lists'],
        ]]]];
        $el = ['id' => 'tx1', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 800, 'h' => 100, 'doc' => $doc];
        $this->ops($user, $deck, [['op' => 'el.add', 'slide' => $slide->id, 'el' => $el]])->assertOk();

        $runs = collect($slide->fresh()->els)->firstWhere('id', 'tx1')['doc']['content'][0]['content'];
        $this->assertSame(['Typography ', 'and ', ' lists'], array_column($runs, 'text'));

        // and a later patch of the same element keeps them too
        $doc['content'][0]['content'][2]['text'] = ' lists, ';
        $this->ops($user, $deck, [['op' => 'el.set', 'slide' => $slide->id, 'id' => 'tx1', 'p' => ['doc' => $doc]]])->assertOk();
        $runs = collect($slide->fresh()->els)->firstWhere('id', 'tx1')['doc']['content'][0]['content'];
        $this->assertSame(' lists, ', $runs[2]['text']);
    }
}
