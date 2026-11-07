<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deck;
use App\Models\DeckChange;
use App\Models\DeckPresence;
use App\Models\Slide;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Who else is in the deck. A browser tab posts its slide, selection and pointer every couple of seconds and gets back
 * the other tabs seen in the last 20 seconds plus the current seq, which doubles as a cheap "has anything changed"
 * check. Rows nobody refreshed for 2 minutes are deleted on the way.
 */
class PresenceController extends Controller
{
    public const VISIBLE_SECONDS = 20;

    public const KEEP_SECONDS = 120;

    private const PALETTE = ['#e5484d', '#f76b15', '#ca8a04', '#30a46c', '#0891b2', '#3e63dd', '#8e4ec6', '#d6409f'];

    public function store(Request $request, Deck $deck): JsonResponse
    {
        $this->authorize('view', $deck);

        $v = $request->validate([
            'client' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_.:-]+$/'],
            'slide' => ['nullable', 'integer', 'min:1'],
            'selection' => ['nullable', 'array', 'max:50'],
            'selection.*' => ['string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'cursor' => ['nullable', 'array'],
            'cursor.x' => ['required_with:cursor', 'numeric', 'between:-100000,100000'],
            'cursor.y' => ['required_with:cursor', 'numeric', 'between:-100000,100000'],
        ]);

        $user = $request->user();

        // Look before deleting: a read does not queue behind the other writers, and a delete that removes nothing still
        // takes the database write lock, which every tab would otherwise do every few seconds.
        $stale = DeckPresence::query()->where('deck_id', $deck->id)->where('seen_at', '<', now()->subSeconds(self::KEEP_SECONDS));
        if ($stale->exists()) {
            $stale->delete();
        }

        $row = DeckPresence::query()->where('deck_id', $deck->id)->where('client', $v['client'])->first();
        if ($row !== null && $row->user_id !== $user->id) {
            return response()->json(['message' => 'That client id belongs to someone else.'], 409);
        }

        // The slide must belong to this deck; anything else is stored as "no slide".
        // A slide id this tab already sent and we already checked is not looked up again.
        $slideId = isset($v['slide']) && ($row?->slide_id === $v['slide'] || Slide::withoutGlobalScopes()->where('deck_id', $deck->id)->whereKey($v['slide'])->exists()) ? $v['slide'] : null;

        $attributes = [
            'user_id' => $user->id,
            'name' => $user->name,
            'color' => $this->colour($user->id),
            'slide_id' => $slideId,
            'selection' => array_values($v['selection'] ?? []),
            'cursor' => isset($v['cursor']) ? ['x' => round((float) $v['cursor']['x'], 1), 'y' => round((float) $v['cursor']['y'], 1)] : null,
            'seen_at' => now(),
        ];

        if ($row === null) {
            DeckPresence::query()->create($attributes + ['deck_id' => $deck->id, 'client' => $v['client']]);
        } else {
            $row->update($attributes);
        }

        $others = DeckPresence::query()
            ->where('deck_id', $deck->id)
            ->where('client', '!=', $v['client'])
            ->where('seen_at', '>=', now()->subSeconds(self::VISIBLE_SECONDS))
            ->orderBy('id')
            ->get();

        return response()->json([
            'seq' => (int) (DeckChange::query()->where('deck_id', $deck->id)->max('id') ?? 0),
            'others' => $others->map(fn (DeckPresence $p) => [
                'client' => $p->client,
                'user_id' => $p->user_id,
                'name' => $p->name,
                'color' => $p->color,
                'slide' => $p->slide_id,
                'selection' => $p->selection ?? [],
                'cursor' => $p->cursor,
                'seen_at' => $p->seen_at->toIso8601String(),
            ])->values(),
        ]);
    }

    private function colour(int $userId): string
    {
        return self::PALETTE[$userId % count(self::PALETTE)];
    }
}
