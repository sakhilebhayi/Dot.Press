<?php

namespace App\Services\Press;

use App\Models\Deck;
use App\Models\DeckReview;
use App\Models\User;
use App\Notifications\ReviewDecided;
use App\Notifications\ReviewRequested;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Review requests and approvals. A decision is bound to the deck's content hash at the moment it is made
 * (DeckHash), so an approval can never be carried over to a deck that has since changed.
 *
 * Deck status: `draft` -> `in_review` when a review is requested -> `approved` when every review that counts for
 * the current content has approved, or back to `draft` when the round ended with a request for changes.
 * Anyone editing an approved deck drops it back to `draft` (see refreshStatus), and while the deck differs from
 * what was approved the API reads `changed_since_approval`.
 */
class Reviews
{
    /**
     * @param  list<int>  $reviewerIds
     * @return list<DeckReview>
     */
    public static function request(Deck $deck, User $requester, array $reviewerIds, ?string $note): array
    {
        $reviewerIds = array_values(array_unique(array_map('intval', $reviewerIds)));

        if ($reviewerIds === []) {
            throw ValidationException::withMessages(['reviewer_ids' => 'Choose at least one reviewer.']);
        }

        $reviewers = User::whereIn('id', $reviewerIds)->get()->keyBy('id');

        foreach ($reviewerIds as $id) {
            $reviewer = $reviewers->get($id);

            if (! $reviewer || ! DeckAccess::can($reviewer, $deck, 'comment')) {
                throw ValidationException::withMessages(['reviewer_ids' => 'Every reviewer must be able to comment on this deck. Share it with them first.']);
            }
        }

        $created = DB::transaction(function () use ($deck, $requester, $reviewerIds, $note): array {
            $created = [];
            $latest = (int) DeckReview::where('deck_id', $deck->id)->max('round');
            // Asking while a round is open adds to it; asking once the deck is settled starts a new round.
            $round = ($deck->status === 'in_review' && $latest > 0) ? $latest : $latest + 1;

            foreach ($reviewerIds as $id) {
                $open = DeckReview::where('deck_id', $deck->id)->where('reviewer_id', $id)->where('status', 'pending')->where('round', $round)->first();

                if ($open) {
                    $open->forceFill(['note' => $note ?? $open->note, 'requested_by' => $requester->id])->save();
                    $created[] = $open;

                    continue;
                }

                $created[] = DeckReview::create([
                    'deck_id' => $deck->id,
                    'requested_by' => $requester->id,
                    'reviewer_id' => $id,
                    'status' => 'pending',
                    'round' => $round,
                    'note' => $note,
                ]);
            }

            $deck->forceFill(['status' => 'in_review'])->save();

            return $created;
        });

        foreach ($created as $review) {
            $reviewers->get($review->reviewer_id)->notify(new ReviewRequested($deck, $review, $requester));
            ActivityRecorder::record($deck, $requester, 'review.requested', ['review_id' => $review->id, 'reviewer' => $reviewers->get($review->reviewer_id)->name]);
        }

        return $created;
    }

    public static function decide(Deck $deck, DeckReview $review, User $reviewer, string $decision, ?string $note): DeckReview
    {
        if (! in_array($decision, ['approved', 'changes_requested'], true)) {
            throw ValidationException::withMessages(['decision' => 'The decision must be approved or changes_requested.']);
        }

        if ($review->status !== 'pending') {
            throw ValidationException::withMessages(['review' => 'This review has already been decided.']);
        }

        $review->forceFill([
            'status' => $decision,
            'decision_note' => $note,
            'version_hash' => DeckHash::compute($deck),
            'decided_at' => now(),
        ])->save();

        ActivityRecorder::record($deck, $reviewer, $decision === 'approved' ? 'review.approved' : 'review.changes_requested', ['review_id' => $review->id]);

        $requester = User::find($review->requested_by);
        if ($requester && $requester->id !== $reviewer->id) {
            $requester->notify(new ReviewDecided($deck, $review, $reviewer));
        }

        self::settle($deck, $reviewer);

        return $review;
    }

    /** Withdraw a request that has not been decided. */
    public static function withdraw(Deck $deck, DeckReview $review, User $actor): void
    {
        if ($review->status !== 'pending') {
            throw ValidationException::withMessages(['review' => 'Only a pending review can be withdrawn.']);
        }

        $review->delete();
        ActivityRecorder::record($deck, $actor, 'review.withdrawn', ['review_id' => $review->id]);
        self::settle($deck, $actor);
    }

    /**
     * Work out the deck's status from its reviews once a round may be complete. Nothing to do while a reviewer
     * is still pending. `approved` needs every counting review approved on the deck as it is right now.
     */
    public static function settle(Deck $deck, ?User $actor = null): void
    {
        $deck->refresh();

        $hash = DeckHash::compute($deck);
        $counting = self::counting($deck);

        if ($counting->isEmpty()) {
            if ($deck->status === 'in_review') {
                $deck->forceFill(['status' => 'draft'])->save();
            }

            return;
        }

        if ($counting->contains('status', 'pending')) {
            if ($deck->status !== 'in_review') {
                $deck->forceFill(['status' => 'in_review'])->save();
            }

            return;
        }

        if ($counting->every(fn (DeckReview $r) => $r->status === 'approved' && $r->version_hash === $hash)) {
            $deck->forceFill(['status' => 'approved', 'approved_hash' => $hash, 'approved_at' => now()])->save();
            self::storeApprovedSnapshot($deck, $actor);
            ActivityRecorder::record($deck, $actor, 'approved', ['reviewers' => $counting->count()]);

            return;
        }

        $deck->forceFill(['status' => 'draft'])->save();
    }

    /**
     * Call after accepted changes: an approved deck whose content moved on goes back to draft.
     * Cheap for every other status (no hashing).
     */
    public static function refreshStatus(Deck $deck): void
    {
        if ($deck->status !== 'approved') {
            return;
        }

        if ($deck->approved_hash === null || DeckHash::current($deck) !== $deck->approved_hash) {
            $deck->forceFill(['status' => 'draft'])->save();
        }
    }

    /**
     * The status to show: the stored one, except that a deck that differs from what was last approved reads
     * `changed_since_approval` (unless a review is under way).
     */
    public static function effectiveStatus(Deck $deck): string
    {
        if ($deck->status === 'in_review' || $deck->approved_hash === null) {
            return $deck->status;
        }

        return DeckHash::current($deck) === $deck->approved_hash ? $deck->status : 'changed_since_approval';
    }

    /** @return array<string, mixed> */
    public static function describe(Deck $deck): array
    {
        $hash = DeckHash::current($deck);
        $reviews = DeckReview::where('deck_id', $deck->id)->orderByDesc('id')->get();
        $users = User::whereIn('id', $reviews->flatMap(fn ($r) => [$r->reviewer_id, $r->requested_by])->unique())->get(['id', 'name'])->keyBy('id');

        return [
            'status' => self::effectiveStatus($deck),
            'stored_status' => $deck->status,
            'approved_at' => optional($deck->approved_at)->toIso8601String(),
            'approved_current' => $deck->approved_hash !== null && $deck->approved_hash === $hash,
            'reviews' => $reviews->map(fn (DeckReview $r) => [
                'id' => $r->id,
                'status' => $r->status,
                'reviewer' => ['id' => $r->reviewer_id, 'name' => $users->get($r->reviewer_id)?->name],
                'requested_by' => ['id' => $r->requested_by, 'name' => $users->get($r->requested_by)?->name],
                'note' => $r->note,
                'decision_note' => $r->decision_note,
                'decided_at' => optional($r->decided_at)->toIso8601String(),
                'round' => $r->round,
                'current' => $r->status === 'pending' || $r->version_hash === $hash,
                'created_at' => optional($r->created_at)->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * The reviews of the deck's latest round, one per reviewer (their latest). A decided review whose hash is not
     * the current one is stale: it is still in the list so it blocks approval until that reviewer looks again.
     *
     * @return Collection<int, DeckReview>
     */
    private static function counting(Deck $deck)
    {
        $round = (int) DeckReview::where('deck_id', $deck->id)->max('round');

        return DeckReview::where('deck_id', $deck->id)->where('round', $round)->get()
            ->sortByDesc('id')->unique('reviewer_id')->values();
    }

    private static function storeApprovedSnapshot(Deck $deck, ?User $actor): void
    {
        try {
            VersionService::snapshot($deck, $actor, 'approved', 'Approved');
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
