<?php

namespace Tests\Feature\Press;

use App\Models\DeckComment;
use App\Notifications\DeckMention;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

class AccessCommentsTest extends AccessTestCase
{
    private function url(string $suffix = ''): string
    {
        return "/api/decks/{$this->deck->id}/comments{$suffix}";
    }

    public function test_create_a_deck_slide_and_element_comment_with_an_anchor(): void
    {
        $this->actingAs($this->viewer);

        $deckLevel = $this->postJson($this->url(), ['body' => 'On the whole deck'])->assertCreated()->json();
        $slideLevel = $this->postJson($this->url(), ['body' => 'On a slide', 'slide_id' => $this->slide->id])->assertCreated()->json();
        $element = $this->postJson($this->url(), [
            'body' => 'On a box', 'slide_id' => $this->slide->id, 'element_id' => 'title1', 'anchor' => ['x' => 12.345, 'y' => 20, 'junk' => 'x'],
        ])->assertCreated()->json();

        $this->assertNull($deckLevel['slide_id']);
        $this->assertSame($this->slide->id, $slideLevel['slide_id']);
        $this->assertSame('title1', $element['element_id']);
        $this->assertEquals(['x' => 12.35, 'y' => 20], $element['anchor']);
        $this->assertSame('Vera Viewer', $element['author']['name']);
        $this->assertTrue($element['can_edit']);
        $this->assertDatabaseHas('deck_activity', ['deck_id' => $this->deck->id, 'event' => 'comment', 'user_id' => $this->viewer->id]);
    }

    public function test_targets_are_validated_against_the_deck(): void
    {
        $this->actingAs($this->editor);
        $other = $this->makeDeck('team', 'Another deck');

        $this->postJson($this->url(), ['body' => 'x', 'slide_id' => $other->slides()->first()->id])->assertUnprocessable()->assertJsonValidationErrors('slide_id');
        $this->postJson($this->url(), ['body' => 'x', 'slide_id' => $this->slide->id, 'element_id' => 'ghost'])->assertUnprocessable()->assertJsonValidationErrors('element_id');
        $this->postJson($this->url(), ['body' => 'x', 'element_id' => 'title1'])->assertUnprocessable();
        $this->postJson($this->url(), ['body' => ''])->assertUnprocessable();
        $this->postJson($this->url(), ['body' => str_repeat('a', 4001)])->assertUnprocessable();
        $this->postJson($this->url(), ['body' => 'x', 'anchor' => ['x' => 'left']])->assertUnprocessable();
        $this->postJson($this->url(), ['body' => 'x', 'parent_id' => 99999])->assertUnprocessable();
    }

    public function test_replies_make_threads_and_inherit_the_target(): void
    {
        $this->actingAs($this->editor);
        $root = $this->postJson($this->url(), ['body' => 'Root', 'slide_id' => $this->slide->id, 'element_id' => 'title1'])->json();
        $reply = $this->postJson($this->url(), ['body' => 'Reply', 'parent_id' => $root['id'], 'slide_id' => null])->assertCreated()->json();
        $this->assertSame($root['id'], $reply['parent_id']);
        $this->assertSame('title1', $reply['element_id']);

        // replies to replies are refused
        $this->postJson($this->url(), ['body' => 'Deep', 'parent_id' => $reply['id']])->assertUnprocessable();

        $threads = $this->getJson($this->url())->assertOk()->json('threads');
        $this->assertCount(1, $threads);
        $this->assertCount(1, $threads[0]['replies']);
        $this->assertSame('Reply', $threads[0]['replies'][0]['body']);
    }

    public function test_filters_open_resolved_and_slide(): void
    {
        $second = $this->deck->slides()->create(['title' => 'Two', 'sort_order' => 1]);
        $this->actingAs($this->editor);
        $a = $this->postJson($this->url(), ['body' => 'A', 'slide_id' => $this->slide->id])->json();
        $b = $this->postJson($this->url(), ['body' => 'B', 'slide_id' => $second->id])->json();
        $this->postJson($this->url("/{$a['id']}/resolve"))->assertOk()->assertJsonPath('resolved', true);

        $this->assertSame(['B'], collect($this->getJson($this->url('?filter=open'))->json('threads'))->pluck('body')->all());
        $this->assertSame(['A'], collect($this->getJson($this->url('?filter=resolved'))->json('threads'))->pluck('body')->all());
        $this->assertCount(2, $this->getJson($this->url('?filter=all'))->json('threads'));
        $this->assertSame(['B'], collect($this->getJson($this->url("?slide={$second->id}"))->json('threads'))->pluck('body')->all());
        $this->getJson($this->url('?filter=bogus'))->assertUnprocessable();

        $this->postJson($this->url("/{$a['id']}/reopen"))->assertOk()->assertJsonPath('resolved', false);
        $this->assertCount(2, $this->getJson($this->url('?filter=open'))->json('threads'));
        $this->assertDatabaseHas('deck_comments', ['id' => $b['id'], 'resolved_at' => null]);
    }

    public function test_a_single_reply_cannot_be_resolved(): void
    {
        $this->actingAs($this->editor);
        $root = $this->postJson($this->url(), ['body' => 'Root'])->json();
        $reply = $this->postJson($this->url(), ['body' => 'R', 'parent_id' => $root['id']])->json();

        $this->postJson($this->url("/{$reply['id']}/resolve"))->assertUnprocessable();
    }

    public function test_edit_own_only_and_delete_own_or_manager(): void
    {
        $mine = $this->actingAs($this->viewer)->postJson($this->url(), ['body' => 'Mine'])->json();

        $this->actingAs($this->viewer)->patchJson($this->url("/{$mine['id']}"), ['body' => 'Edited'])->assertOk()->assertJsonPath('body', 'Edited');
        // nobody else edits it, not even a manager
        $this->actingAs($this->admin)->patchJson($this->url("/{$mine['id']}"), ['body' => 'Hijack'])->assertForbidden();
        $peer = $this->member('editor', 'Peer Editor');
        $this->actingAs($peer)->patchJson($this->url("/{$mine['id']}"), ['body' => 'Hijack'])->assertForbidden();
        // a non-manager (an editor who did not create the deck) cannot delete someone else's
        $this->actingAs($peer)->deleteJson($this->url("/{$mine['id']}"))->assertForbidden();
        $this->assertDatabaseHas('deck_comments', ['id' => $mine['id'], 'body' => 'Edited']);

        // a manager can
        $this->actingAs($this->admin)->deleteJson($this->url("/{$mine['id']}"))->assertNoContent();
        $this->assertDatabaseMissing('deck_comments', ['id' => $mine['id']]);

        // the author can delete their own, which removes the replies with it
        $own = $this->actingAs($this->viewer)->postJson($this->url(), ['body' => 'Own'])->json();
        $this->actingAs($this->editor)->postJson($this->url(), ['body' => 'Reply', 'parent_id' => $own['id']])->assertCreated();
        $this->actingAs($this->viewer)->deleteJson($this->url("/{$own['id']}"))->assertNoContent();
        $this->assertSame(0, DeckComment::count());
    }

    public function test_a_comment_of_another_deck_is_not_reachable_through_this_one(): void
    {
        $other = $this->makeDeck('team', 'Other');
        $foreign = DeckComment::create(['deck_id' => $other->id, 'user_id' => $this->editor->id, 'body' => 'elsewhere']);

        $this->actingAs($this->admin)->deleteJson($this->url("/{$foreign->id}"))->assertNotFound();
        $this->actingAs($this->editor)->patchJson($this->url("/{$foreign->id}"), ['body' => 'x'])->assertNotFound();
        $this->actingAs($this->admin)->postJson($this->url("/{$foreign->id}/resolve"))->assertNotFound();
    }

    public function test_a_share_viewer_reads_but_cannot_comment(): void
    {
        $sharee = $this->sharedWith('viewer');
        $this->actingAs($this->editor)->postJson($this->url(), ['body' => 'Visible'])->assertCreated();

        $this->actingAs($sharee)->getJson($this->url())->assertOk()->assertJsonCount(1, 'threads');
        $this->actingAs($sharee)->postJson($this->url(), ['body' => 'No'])->assertForbidden();
    }

    // ---- mentions -----------------------------------------------------------------------------------------------

    public function test_a_mention_of_someone_with_access_is_kept_and_notified(): void
    {
        Notification::fake();

        $response = $this->actingAs($this->editor)->postJson($this->url(), ['body' => "Please check @[wrong label]({$this->viewer->id}) now"])->assertCreated();

        $this->assertSame([$this->viewer->id], DeckComment::first()->mentions);
        $this->assertSame("Please check @[Vera Viewer]({$this->viewer->id}) now", $response->json('body'), 'the label is replaced by the real name');
        $this->assertSame('Vera Viewer', $response->json('mentions.0.name'));
        Notification::assertSentTo($this->viewer, DeckMention::class);
        Notification::assertSentToTimes($this->viewer, DeckMention::class, 1);
    }

    public function test_a_mention_of_someone_without_access_is_dropped_and_never_notified(): void
    {
        Notification::fake();

        $response = $this->actingAs($this->editor)->postJson($this->url(), ['body' => "Hi @[Otto Outsider]({$this->outsider->id}) and @[Nobody](987654)"])->assertCreated();

        $this->assertSame([], DeckComment::first()->mentions);
        $this->assertSame('Hi @Otto Outsider and @Nobody', $response->json('body'));
        Notification::assertNothingSent();
    }

    public function test_mentions_follow_private_deck_access(): void
    {
        Notification::fake();
        $private = $this->makeDeck('private', 'Quiet');
        $named = $this->sharedWith('commenter', $private, 'Named One');

        $this->actingAs($this->editor)->postJson("/api/decks/{$private->id}/comments", [
            'body' => "@[x]({$named->id}) @[y]({$this->admin->id})",
        ])->assertCreated();

        $this->assertSame([$named->id], DeckComment::first()->mentions);
        Notification::assertSentTo($named, DeckMention::class);
        Notification::assertNotSentTo($this->admin, DeckMention::class);
    }

    public function test_mentioning_yourself_does_not_notify_and_edits_only_notify_new_mentions(): void
    {
        Notification::fake();
        $this->actingAs($this->editor);

        $comment = $this->postJson($this->url(), ['body' => "me @[Edie Editor]({$this->editor->id}) and @[v]({$this->viewer->id})"])->json();
        Notification::assertNotSentTo($this->editor, DeckMention::class);
        Notification::assertSentToTimes($this->viewer, DeckMention::class, 1);

        $this->patchJson($this->url("/{$comment['id']}"), ['body' => "again @[v]({$this->viewer->id}) and @[a]({$this->admin->id})"])->assertOk();
        Notification::assertSentToTimes($this->viewer, DeckMention::class, 1);
        Notification::assertSentToTimes($this->admin, DeckMention::class, 1);
    }

    public function test_the_mention_notification_has_mail_and_database_channels(): void
    {
        $comment = DeckComment::create(['deck_id' => $this->deck->id, 'user_id' => $this->editor->id, 'body' => "hi @[Vera Viewer]({$this->viewer->id})", 'mentions' => [$this->viewer->id]]);
        $note = new DeckMention($this->deck, $comment, $this->editor);

        $this->assertSame(['mail', 'database'], $note->via($this->viewer));
        $this->assertInstanceOf(ShouldQueue::class, $note);
        $this->assertStringContainsString('mentioned you', $note->toMail($this->viewer)->subject);
        $this->assertSame('deck.mention', $note->toArray($this->viewer)['kind']);
        $this->assertStringContainsString('@Vera Viewer', $note->toArray($this->viewer)['excerpt']);
    }

    public function test_mentions_are_stored_in_the_notifications_table_for_real(): void
    {
        $this->actingAs($this->editor)->postJson($this->url(), ['body' => "@[x]({$this->viewer->id})"])->assertCreated();

        $this->assertSame(1, $this->viewer->notifications()->count());
        $this->assertSame('deck.mention', $this->viewer->notifications()->first()->data['kind']);
    }
}
