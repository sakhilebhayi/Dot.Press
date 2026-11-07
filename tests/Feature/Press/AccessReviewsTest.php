<?php

namespace Tests\Feature\Press;

use App\Models\DeckReview;
use App\Models\User;
use App\Notifications\ReviewDecided;
use App\Notifications\ReviewRequested;
use App\Services\Press\DeckHash;
use App\Services\Press\Reviews;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

class AccessReviewsTest extends AccessTestCase
{
    private User $reviewerA;

    private User $reviewerB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reviewerA = $this->member('editor', 'Reviewer A');
        $this->reviewerB = $this->member('viewer', 'Reviewer B');   // a team viewer may review: viewers can comment
    }

    private function url(string $suffix = ''): string
    {
        return "/api/decks/{$this->deck->id}/reviews{$suffix}";
    }

    private function request(array $ids, ?User $by = null, ?string $note = 'Please check'): TestResponse
    {
        return $this->actingAs($by ?? $this->owner)->postJson($this->url(), ['reviewer_ids' => $ids, 'note' => $note]);
    }

    private function decide(DeckReview $review, User $by, string $decision = 'approved', ?string $note = null): TestResponse
    {
        return $this->actingAs($by)->postJson($this->url("/{$review->id}/decision"), ['decision' => $decision, 'note' => $note]);
    }

    private function edit(): void
    {
        $this->slide->forceFill(['title' => 'Changed '.uniqid()])->save();
    }

    public function test_requesting_a_review_moves_the_deck_to_in_review_and_notifies(): void
    {
        Notification::fake();

        $this->request([$this->reviewerA->id, $this->reviewerB->id])->assertCreated()
            ->assertJsonPath('status', 'in_review')->assertJsonCount(2, 'reviews');

        $this->assertSame('in_review', $this->deck->refresh()->status);
        Notification::assertSentTo($this->reviewerA, ReviewRequested::class);
        Notification::assertSentTo($this->reviewerB, ReviewRequested::class);
        $this->assertDatabaseHas('deck_activity', ['deck_id' => $this->deck->id, 'event' => 'review.requested']);
        $this->assertSame(2, DeckReview::where('status', 'pending')->count());
    }

    public function test_only_managers_can_request_and_reviewers_must_be_able_to_comment(): void
    {
        $this->request([$this->reviewerA->id], $this->viewer)->assertForbidden();
        $this->request([$this->reviewerA->id], $this->reviewerA)->assertForbidden();

        $this->request([$this->outsider->id])->assertUnprocessable()->assertJsonValidationErrors('reviewer_ids');
        $this->request([$this->reviewerA->id, 987654])->assertUnprocessable();
        $shareViewer = $this->sharedWith('viewer');
        $this->request([$shareViewer->id])->assertUnprocessable();
        $this->request([])->assertUnprocessable();

        $commenter = $this->sharedWith('commenter', null, 'Commenter');
        $this->request([$commenter->id])->assertCreated();
        $this->assertSame(1, DeckReview::count());
    }

    public function test_requesting_again_for_a_pending_reviewer_does_not_duplicate(): void
    {
        $this->request([$this->reviewerA->id])->assertCreated();
        $this->request([$this->reviewerA->id], null, 'Second note')->assertCreated();

        $this->assertSame(1, DeckReview::count());
        $this->assertSame('Second note', DeckReview::first()->note);
    }

    public function test_only_the_named_reviewer_can_decide(): void
    {
        $this->request([$this->reviewerA->id]);
        $review = DeckReview::first();

        $this->decide($review, $this->reviewerB)->assertForbidden();
        $this->decide($review, $this->owner)->assertForbidden();
        $this->decide($review, $this->outsider)->assertNotFound();
        $this->assertSame('pending', $review->refresh()->status);

        $this->decide($review, $this->reviewerA)->assertOk();
        $this->decide($review, $this->reviewerA)->assertUnprocessable();
        $this->actingAs($this->reviewerA)->postJson($this->url("/{$review->id}/decision"), ['decision' => 'maybe'])->assertUnprocessable();
    }

    public function test_the_decision_is_bound_to_the_content_hash_at_that_moment(): void
    {
        $this->request([$this->reviewerA->id]);
        $review = DeckReview::first();
        $this->assertNull($review->version_hash);

        $this->edit();
        $hashAtDecision = DeckHash::compute($this->deck->refresh());
        $this->decide($review, $this->reviewerA, 'approved', 'Looks good')->assertOk();

        $review->refresh();
        $this->assertSame($hashAtDecision, $review->version_hash);
        $this->assertSame('Looks good', $review->decision_note);
        $this->assertNotNull($review->decided_at);
    }

    public function test_all_reviewers_approving_approves_the_deck_and_stamps_the_hash(): void
    {
        Notification::fake();
        $this->request([$this->reviewerA->id, $this->reviewerB->id]);
        [$a, $b] = [DeckReview::where('reviewer_id', $this->reviewerA->id)->first(), DeckReview::where('reviewer_id', $this->reviewerB->id)->first()];

        $this->decide($a, $this->reviewerA)->assertOk()->assertJsonPath('status', 'in_review');
        $this->assertNull($this->deck->refresh()->approved_hash);

        $this->decide($b, $this->reviewerB)->assertOk()->assertJsonPath('status', 'approved')->assertJsonPath('approved_current', true);

        $deck = $this->deck->refresh();
        $this->assertSame('approved', $deck->status);
        $this->assertSame(DeckHash::compute($deck), $deck->approved_hash);
        $this->assertNotNull($deck->approved_at);
        $this->assertDatabaseHas('deck_activity', ['deck_id' => $deck->id, 'event' => 'approved']);
        Notification::assertSentTo($this->owner, ReviewDecided::class);
    }

    public function test_changes_requested_ends_the_round_and_returns_to_draft_once_nobody_is_pending(): void
    {
        $this->request([$this->reviewerA->id, $this->reviewerB->id]);
        $a = DeckReview::where('reviewer_id', $this->reviewerA->id)->first();
        $b = DeckReview::where('reviewer_id', $this->reviewerB->id)->first();

        $this->decide($a, $this->reviewerA, 'changes_requested', 'Fix the title')->assertOk()->assertJsonPath('status', 'in_review');
        $this->decide($b, $this->reviewerB, 'approved')->assertOk()->assertJsonPath('status', 'draft');

        $this->assertSame('draft', $this->deck->refresh()->status);
        $this->assertNull($this->deck->approved_hash);
    }

    public function test_an_approval_on_an_older_version_does_not_count_for_the_new_round(): void
    {
        $this->request([$this->reviewerA->id, $this->reviewerB->id]);
        $a = DeckReview::where('reviewer_id', $this->reviewerA->id)->first();
        $b = DeckReview::where('reviewer_id', $this->reviewerB->id)->first();
        $this->decide($a, $this->reviewerA);

        // the author keeps working; B approves the newer content, A's approval was of the older content
        $this->edit();
        $this->decide($b, $this->reviewerB)->assertOk()->assertJsonPath('status', 'draft');
        $this->assertNull($this->deck->refresh()->approved_hash);

        // asking A again makes a fresh pending row and the deck approves once A signs off on this version
        $this->request([$this->reviewerA->id])->assertCreated();
        $fresh = DeckReview::where('reviewer_id', $this->reviewerA->id)->where('status', 'pending')->first();
        $this->decide($fresh, $this->reviewerA)->assertOk()->assertJsonPath('status', 'approved');
    }

    public function test_editing_an_approved_deck_reads_changed_since_approval_and_refresh_returns_it_to_draft(): void
    {
        $this->request([$this->reviewerA->id]);
        $this->decide(DeckReview::first(), $this->reviewerA)->assertJsonPath('status', 'approved');

        $this->assertSame('approved', Reviews::effectiveStatus($this->deck->refresh()));

        $this->edit();
        $this->assertSame('approved', $this->deck->refresh()->status, 'stored status only moves when refreshStatus is called');
        $this->assertSame('changed_since_approval', Reviews::effectiveStatus($this->deck));
        $this->actingAs($this->viewer)->getJson($this->url())->assertOk()->assertJsonPath('status', 'changed_since_approval')->assertJsonPath('approved_current', false);

        Reviews::refreshStatus($this->deck);
        $this->assertSame('draft', $this->deck->refresh()->status);
        $this->assertSame('changed_since_approval', Reviews::effectiveStatus($this->deck), 'it still differs from the last approval');
    }

    public function test_refresh_status_leaves_an_unchanged_approved_deck_and_other_statuses_alone(): void
    {
        $this->request([$this->reviewerA->id]);
        $this->decide(DeckReview::first(), $this->reviewerA);

        Reviews::refreshStatus($this->deck->refresh());
        $this->assertSame('approved', $this->deck->refresh()->status);

        $this->deck->forceFill(['status' => 'in_review'])->save();
        $this->edit();
        Reviews::refreshStatus($this->deck->refresh());
        $this->assertSame('in_review', $this->deck->refresh()->status);

        $this->deck->forceFill(['status' => 'draft'])->save();
        Reviews::refreshStatus($this->deck);
        $this->assertSame('draft', $this->deck->refresh()->status);
    }

    public function test_requesting_a_new_review_of_an_approved_deck_puts_it_back_in_review(): void
    {
        $this->request([$this->reviewerA->id]);
        $this->decide(DeckReview::first(), $this->reviewerA);
        $this->assertSame('approved', $this->deck->refresh()->status);

        $this->request([$this->reviewerB->id])->assertCreated()->assertJsonPath('status', 'in_review');
    }

    public function test_withdrawing_the_last_pending_review_returns_to_draft_and_can_complete_a_round(): void
    {
        $this->request([$this->reviewerA->id, $this->reviewerB->id]);
        $a = DeckReview::where('reviewer_id', $this->reviewerA->id)->first();
        $b = DeckReview::where('reviewer_id', $this->reviewerB->id)->first();

        $this->decide($a, $this->reviewerA);
        $this->actingAs($this->viewer)->deleteJson($this->url("/{$b->id}"))->assertForbidden();
        $this->actingAs($this->owner)->deleteJson($this->url("/{$b->id}"))->assertNoContent();

        $this->assertSame('approved', $this->deck->refresh()->status, 'the only remaining review approved the current content');
        $this->actingAs($this->owner)->deleteJson($this->url("/{$a->id}"))->assertUnprocessable();
    }

    public function test_a_reviewer_who_lost_comment_access_cannot_decide(): void
    {
        $this->request([$this->reviewerA->id]);
        $review = DeckReview::first();
        $this->team->users()->detach($this->reviewerA);

        $this->decide($review, $this->reviewerA->fresh())->assertNotFound();
    }

    public function test_a_review_of_another_deck_is_not_decidable_through_this_one(): void
    {
        $other = $this->makeDeck('team', 'Other');
        $foreign = DeckReview::create(['deck_id' => $other->id, 'requested_by' => $this->owner->id, 'reviewer_id' => $this->reviewerA->id]);

        $this->decide($foreign, $this->reviewerA)->assertNotFound();
    }

    public function test_the_review_state_is_readable_by_anyone_who_can_view(): void
    {
        $this->request([$this->reviewerA->id]);
        $sharee = $this->sharedWith('viewer');

        $this->actingAs($sharee)->getJson($this->url())->assertOk()
            ->assertJsonPath('status', 'in_review')->assertJsonPath('reviews.0.reviewer.name', 'Reviewer A')->assertJsonPath('reviews.0.status', 'pending');
        $this->actingAs($this->outsider)->getJson($this->url())->assertNotFound();
    }

    public function test_the_review_notifications_use_mail_and_database(): void
    {
        $this->request([$this->reviewerA->id]);
        $review = DeckReview::first();
        $requested = new ReviewRequested($this->deck, $review, $this->owner);

        $this->assertSame(['mail', 'database'], $requested->via($this->reviewerA));
        $this->assertStringContainsString('asked you to review', $requested->toMail($this->reviewerA)->subject);
        $this->assertSame(1, $this->reviewerA->notifications()->count());

        $this->decide($review, $this->reviewerA, 'changes_requested', 'Fix it');
        $this->assertSame('review.decided', $this->owner->notifications()->first()->data['kind']);
        $this->assertSame('changes_requested', $this->owner->notifications()->first()->data['decision']);
    }
}
