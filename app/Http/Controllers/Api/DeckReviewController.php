<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deck;
use App\Models\DeckReview;
use App\Services\Press\DeckAccess;
use App\Services\Press\Reviews;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeckReviewController extends Controller
{
    public function index(Deck $deck): array
    {
        $this->authorize('view', $deck);

        return Reviews::describe($deck);
    }

    /** Ask one or more people to review. Needs `manage`; reviewers need at least `comment`. */
    public function store(Request $request, Deck $deck): JsonResponse
    {
        $this->authorize('manage', $deck);

        $data = $request->validate([
            'reviewer_ids' => ['required', 'array', 'min:1', 'max:20'],
            'reviewer_ids.*' => ['integer'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        Reviews::request($deck, $request->user(), $data['reviewer_ids'], $data['note'] ?? null);

        return response()->json(Reviews::describe($deck->refresh()), 201);
    }

    /** Only the named reviewer decides, and only on a review that is still pending. */
    public function decide(Request $request, Deck $deck, DeckReview $review): array
    {
        $this->authorize('view', $deck);
        abort_unless($review->deck_id === $deck->id, 404);
        abort_unless($review->reviewer_id === $request->user()->id && DeckAccess::can($request->user(), $deck, 'comment'), 403, 'Only the named reviewer can decide this review.');

        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'changes_requested'])],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        Reviews::decide($deck, $review, $request->user(), $data['decision'], $data['note'] ?? null);

        return Reviews::describe($deck->refresh());
    }

    public function destroy(Request $request, Deck $deck, DeckReview $review)
    {
        $this->authorize('manage', $deck);
        abort_unless($review->deck_id === $deck->id, 404);

        Reviews::withdraw($deck, $review, $request->user());

        return response()->noContent();
    }
}
