<?php

namespace Tests\Feature\Press;

use App\Models\DeckActivity;
use App\Services\Press\ActivityRecorder;
use App\Services\Press\ActivitySummary;

class AccessActivityTest extends AccessTestCase
{
    public function test_newest_first_with_readable_summaries(): void
    {
        ActivityRecorder::record($this->deck, $this->editor, 'created');
        ActivityRecorder::record($this->deck, $this->editor, 'renamed', ['to' => 'Q4 plan']);
        $this->actingAs($this->owner)->postJson("/api/decks/{$this->deck->id}/sharing/people", ['user_id' => $this->outsider->id, 'role' => 'viewer'])->assertCreated();
        $this->actingAs($this->viewer)->postJson("/api/decks/{$this->deck->id}/comments", ['body' => 'hi'])->assertCreated();

        $feed = $this->actingAs($this->admin)->getJson("/api/decks/{$this->deck->id}/activity")->assertOk();

        $summaries = collect($feed->json('data'))->pluck('summary')->all();
        $this->assertSame([
            'Vera Viewer commented',
            'Olive Owner shared the deck with Otto Outsider as viewer',
            'Edie Editor renamed the deck to "Q4 plan"',
            'Edie Editor created the deck',
        ], $summaries);
        $this->assertSame('comment', $feed->json('data.0.event'));
        $this->assertSame($this->viewer->id, $feed->json('data.0.user.id'));
        $this->assertSame(4, $feed->json('total'));
    }

    public function test_paging(): void
    {
        foreach (range(1, 7) as $i) {
            ActivityRecorder::record($this->deck, $this->editor, 'exported', ['format' => 'pdf']);
        }

        $page1 = $this->actingAs($this->viewer)->getJson("/api/decks/{$this->deck->id}/activity?per_page=3")->assertOk();
        $page3 = $this->actingAs($this->viewer)->getJson("/api/decks/{$this->deck->id}/activity?per_page=3&page=3")->assertOk();

        $this->assertCount(3, $page1->json('data'));
        $this->assertCount(1, $page3->json('data'));
        $this->assertSame(3, $page1->json('last_page'));
        $this->assertGreaterThan($page3->json('data.0.id'), $page1->json('data.0.id'));
        $this->actingAs($this->viewer)->getJson("/api/decks/{$this->deck->id}/activity?per_page=500")->assertUnprocessable();
    }

    public function test_an_unknown_event_and_a_missing_user_still_read_sensibly(): void
    {
        $row = new DeckActivity(['event' => 'future.thing', 'meta' => null]);

        $this->assertSame('Someone future thing', ActivitySummary::for($row, null));
        $this->assertSame('The deck was approved', ActivitySummary::for(new DeckActivity(['event' => 'approved']), null));
        $this->assertSame('Ann exported the deck as PDF', ActivitySummary::for(new DeckActivity(['event' => 'exported', 'meta' => ['format' => 'pdf']]), 'Ann'));
    }

    public function test_activity_is_not_visible_to_outsiders_and_not_through_a_link(): void
    {
        ActivityRecorder::record($this->deck, $this->editor, 'created');
        $this->deck->forceFill(['visibility' => 'link', 'share_token' => str_repeat('k', 40)])->save();

        $this->actingAs($this->outsider)->getJson("/api/decks/{$this->deck->id}/activity")->assertNotFound();
        $this->assertSame(404, $this->get('/s/'.str_repeat('k', 40).'/activity')->getStatusCode());
    }
}
