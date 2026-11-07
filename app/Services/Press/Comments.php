<?php

namespace App\Services\Press;

use App\Models\Deck;
use App\Models\DeckComment;
use App\Models\Slide;
use App\Models\User;
use App\Notifications\DeckMention;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Threaded comments on a deck, a slide or one element. Mentions are written in the body as `@[Name](id)`; only
 * people who can open the deck keep their mention, anyone else is reduced to plain text and never notified.
 */
class Comments
{
    public const BODY_MAX = 4000;

    private const MENTION = '/@\[([^\]\r\n]{1,80})\]\((\d{1,12})\)/u';

    /**
     * @param  array{slide_id?: ?int, element_id?: ?string, anchor?: ?array<string, mixed>, parent_id?: ?int}  $target
     */
    public static function create(Deck $deck, User $author, string $body, array $target = []): DeckComment
    {
        $parent = null;

        if (! empty($target['parent_id'])) {
            $parent = DeckComment::where('deck_id', $deck->id)->whereKey($target['parent_id'])->first();

            if (! $parent || $parent->parent_id !== null) {
                throw ValidationException::withMessages(['parent_id' => 'Reply to a top level comment of this deck.']);
            }
        }

        if ($parent) {
            $slideId = $parent->slide_id;
            $elementId = $parent->element_id;
            $anchor = $parent->anchor;
        } else {
            $slideId = $target['slide_id'] ?? null;
            $elementId = $target['element_id'] ?? null;
            $anchor = self::cleanAnchor($target['anchor'] ?? null);
            self::assertTarget($deck, $slideId, $elementId);
        }

        [$cleanBody, $mentionIds] = self::parseMentions($deck, $body);

        $comment = DeckComment::create([
            'deck_id' => $deck->id,
            'slide_id' => $slideId,
            'element_id' => $elementId,
            'parent_id' => $parent?->id,
            'user_id' => $author->id,
            'body' => $cleanBody,
            'mentions' => $mentionIds,
            'anchor' => $anchor,
        ]);

        self::notifyMentions($deck, $comment, $author, $mentionIds);

        ActivityRecorder::record($deck, $author, 'comment', [
            'action' => $parent ? 'reply' : 'created',
            'comment_id' => $comment->id,
            'slide_id' => $slideId,
        ]);

        return $comment;
    }

    public static function edit(Deck $deck, DeckComment $comment, User $editor, string $body): DeckComment
    {
        [$cleanBody, $mentionIds] = self::parseMentions($deck, $body);
        $fresh = array_values(array_diff($mentionIds, $comment->mentions ?? []));

        $comment->forceFill(['body' => $cleanBody, 'mentions' => $mentionIds])->save();

        self::notifyMentions($deck, $comment, $editor, $fresh);

        return $comment;
    }

    public static function setResolved(Deck $deck, DeckComment $comment, User $user, bool $resolved): DeckComment
    {
        if ($comment->parent_id !== null) {
            throw ValidationException::withMessages(['comment' => 'Resolve the thread, not a single reply.']);
        }

        $comment->forceFill([
            'resolved_at' => $resolved ? now() : null,
            'resolved_by' => $resolved ? $user->id : null,
        ])->save();

        ActivityRecorder::record($deck, $user, 'comment', [
            'action' => $resolved ? 'resolved' : 'reopened',
            'comment_id' => $comment->id,
            'slide_id' => $comment->slide_id,
        ]);

        return $comment;
    }

    public static function delete(Deck $deck, DeckComment $comment, User $user): void
    {
        $replies = DeckComment::where('deck_id', $deck->id)->where('parent_id', $comment->id)->delete();
        $comment->delete();

        ActivityRecorder::record($deck, $user, 'comment', [
            'action' => 'deleted',
            'comment_id' => $comment->id,
            'slide_id' => $comment->slide_id,
            'replies' => $replies,
        ]);
    }

    /**
     * Threads (top level comments with their replies), newest thread first.
     *
     * @param  'open'|'resolved'|'all'  $state
     * @return Collection<int, array<string, mixed>>
     */
    public static function threads(Deck $deck, User $viewer, string $state = 'all', ?int $slideId = null): Collection
    {
        $roots = DeckComment::where('deck_id', $deck->id)->whereNull('parent_id')
            ->when($state === 'open', fn ($q) => $q->whereNull('resolved_at'))
            ->when($state === 'resolved', fn ($q) => $q->whereNotNull('resolved_at'))
            ->when($slideId !== null, fn ($q) => $q->where('slide_id', $slideId))
            ->orderByDesc('id')->get();

        $replies = DeckComment::where('deck_id', $deck->id)->whereIn('parent_id', $roots->pluck('id'))
            ->orderBy('id')->get()->groupBy('parent_id');

        $userIds = $roots->concat($replies->flatten())->flatMap(fn (DeckComment $c) => [$c->user_id, $c->resolved_by, ...($c->mentions ?? [])])->filter()->unique();
        $users = User::whereIn('id', $userIds)->get(['id', 'name'])->keyBy('id');
        $canManage = DeckAccess::can($viewer, $deck, 'manage');

        $shape = fn (DeckComment $c) => self::present($c, $users, $viewer, $canManage);

        return $roots->map(fn (DeckComment $root) => $shape($root) + [
            'replies' => ($replies->get($root->id) ?? collect())->map($shape)->values()->all(),
        ])->values();
    }

    /** @return array<string, mixed> */
    public static function present(DeckComment $c, Collection $users, User $viewer, bool $canManage): array
    {
        return [
            'id' => $c->id,
            'parent_id' => $c->parent_id,
            'slide_id' => $c->slide_id,
            'element_id' => $c->element_id,
            'anchor' => $c->anchor,
            'body' => $c->body,
            'author' => ['id' => $c->user_id, 'name' => $users->get($c->user_id)?->name ?? 'Former member'],
            'mentions' => collect($c->mentions ?? [])->map(fn ($id) => ['id' => $id, 'name' => $users->get($id)?->name])->filter(fn ($m) => $m['name'])->values()->all(),
            'resolved' => $c->resolved_at !== null,
            'resolved_at' => optional($c->resolved_at)->toIso8601String(),
            'resolved_by' => $c->resolved_by ? ['id' => $c->resolved_by, 'name' => $users->get($c->resolved_by)?->name] : null,
            'created_at' => optional($c->created_at)->toIso8601String(),
            'updated_at' => optional($c->updated_at)->toIso8601String(),
            'can_edit' => $c->user_id === $viewer->id,
            'can_delete' => $c->user_id === $viewer->id || $canManage,
        ];
    }

    /**
     * Validate the mentions in $body against deck access. Returns the cleaned body and the ids that kept their mention.
     *
     * @return array{0: string, 1: list<int>}
     */
    public static function parseMentions(Deck $deck, string $body): array
    {
        $body = trim($body);

        preg_match_all(self::MENTION, $body, $found);
        $ids = array_unique(array_map('intval', $found[2]));
        $users = User::whereIn('id', $ids)->get()->keyBy('id');
        $allowed = [];

        foreach ($users as $user) {
            if (DeckAccess::can($user, $deck, 'view')) {
                $allowed[$user->id] = $user;
            }
        }

        $clean = preg_replace_callback(self::MENTION, function (array $m) use ($allowed): string {
            $user = $allowed[(int) $m[2]] ?? null;
            $name = str_replace(['[', ']', '(', ')'], '', $user?->name ?? $m[1]);

            // A kept mention is rewritten with the real name so a typed label can never impersonate someone else.
            return $user ? "@[{$name}]({$user->id})" : '@'.$name;
        }, $body);

        return [$clean, array_values(array_keys($allowed))];
    }

    /** @param  list<int>  $ids */
    private static function notifyMentions(Deck $deck, DeckComment $comment, User $author, array $ids): void
    {
        foreach (User::whereIn('id', array_diff($ids, [$author->id]))->get() as $user) {
            $user->notify(new DeckMention($deck, $comment, $author));
        }
    }

    /** @return array{x: float, y: float}|null */
    private static function cleanAnchor(?array $anchor): ?array
    {
        if (! $anchor || ! isset($anchor['x'], $anchor['y']) || ! is_numeric($anchor['x']) || ! is_numeric($anchor['y'])) {
            return null;
        }

        return ['x' => round((float) $anchor['x'], 2), 'y' => round((float) $anchor['y'], 2)];
    }

    private static function assertTarget(Deck $deck, ?int $slideId, ?string $elementId): void
    {
        if ($slideId === null) {
            if ($elementId !== null) {
                throw ValidationException::withMessages(['element_id' => 'An element comment needs its slide.']);
            }

            return;
        }

        $slide = Slide::where('deck_id', $deck->id)->whereKey($slideId)->first();

        if (! $slide) {
            throw ValidationException::withMessages(['slide_id' => 'That slide is not in this deck.']);
        }

        if ($elementId !== null && ! collect($slide->els ?? [])->contains(fn ($el) => ($el['id'] ?? null) === $elementId)) {
            throw ValidationException::withMessages(['element_id' => 'That element is not on the slide.']);
        }
    }
}
