<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deck;
use App\Models\DeckComment;
use App\Models\User;
use App\Services\Press\Comments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeckCommentController extends Controller
{
    public function index(Request $request, Deck $deck): array
    {
        $this->authorize('view', $deck);

        $data = $request->validate([
            'filter' => ['sometimes', Rule::in(['open', 'resolved', 'all'])],
            'slide' => ['sometimes', 'integer'],
        ]);

        return ['threads' => Comments::threads($deck, $request->user(), $data['filter'] ?? 'all', $data['slide'] ?? null)->all()];
    }

    public function store(Request $request, Deck $deck): JsonResponse
    {
        $this->authorize('comment', $deck);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:'.Comments::BODY_MAX],
            'slide_id' => ['nullable', 'integer'],
            'element_id' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,40}$/D'],
            'anchor' => ['nullable', 'array'],
            'anchor.x' => ['required_with:anchor', 'numeric'],
            'anchor.y' => ['required_with:anchor', 'numeric'],
            'parent_id' => ['nullable', 'integer'],
        ]);

        $comment = Comments::create($deck, $request->user(), $data['body'], $data);

        return response()->json($this->one($deck, $comment, $request->user()), 201);
    }

    public function update(Request $request, Deck $deck, DeckComment $comment): array
    {
        $this->authorize('comment', $deck);
        $this->belongs($deck, $comment);
        abort_unless($comment->user_id === $request->user()->id, 403, 'You can only edit your own comments.');

        $data = $request->validate(['body' => ['required', 'string', 'max:'.Comments::BODY_MAX]]);

        return $this->one($deck, Comments::edit($deck, $comment, $request->user(), $data['body']), $request->user());
    }

    public function destroy(Request $request, Deck $deck, DeckComment $comment)
    {
        $this->authorize('view', $deck);
        $this->belongs($deck, $comment);
        abort_unless($comment->user_id === $request->user()->id || $request->user()->can('manage', $deck), 403);

        Comments::delete($deck, $comment, $request->user());

        return response()->noContent();
    }

    public function resolve(Request $request, Deck $deck, DeckComment $comment): array
    {
        return $this->setResolved($request, $deck, $comment, true);
    }

    public function reopen(Request $request, Deck $deck, DeckComment $comment): array
    {
        return $this->setResolved($request, $deck, $comment, false);
    }

    private function setResolved(Request $request, Deck $deck, DeckComment $comment, bool $resolved): array
    {
        $this->authorize('comment', $deck);
        $this->belongs($deck, $comment);

        return $this->one($deck, Comments::setResolved($deck, $comment, $request->user(), $resolved), $request->user());
    }

    private function belongs(Deck $deck, DeckComment $comment): void
    {
        abort_unless($comment->deck_id === $deck->id, 404);
    }

    /** @return array<string, mixed> */
    private function one(Deck $deck, DeckComment $comment, User $viewer): array
    {
        $users = User::whereIn('id', array_filter([$comment->user_id, $comment->resolved_by, ...($comment->mentions ?? [])]))->get(['id', 'name'])->keyBy('id');

        return Comments::present($comment, $users, $viewer, $viewer->can('manage', $deck));
    }
}
