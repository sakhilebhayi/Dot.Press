<?php

namespace App\Services\Press;

use App\Models\Deck;
use Illuminate\Support\Facades\DB;

/**
 * The library card of a deck: its first slide's background and at most 60 KB of that slide's visible elements.
 *
 * The first version of the library read the first slide of every deck on the page, one query each, with the slide's
 * whole element JSON, and re-cut it on every request (24 queries and 24 JSON decodes per page). The cut is now kept on
 * the deck row (`preview`) against the `rev` it was made at (`preview_rev`); every accepted batch, restore and import
 * bumps `rev`, so a card is cut again only after the deck changed, and all the decks of a page that need it are cut
 * from two queries.
 */
final class DeckPreview
{
    /** At most this many bytes of the first slide's elements go into the card. */
    public const BYTES = 60000;

    /**
     * @param  iterable<Deck>  $decks
     * @return array<int, array{first_slide: int|null, bg: mixed, els: list<array<string, mixed>>}> deck id => card
     */
    public static function forDecks(iterable $decks): array
    {
        $out = [];
        $missing = [];
        foreach ($decks as $deck) {
            $cached = $deck->getAttribute('preview');
            if ($cached !== null && $deck->getAttribute('preview_rev') !== null && (int) $deck->getAttribute('preview_rev') === (int) $deck->rev) {
                $card = is_string($cached) ? json_decode($cached, true) : $cached;
                if (is_array($card)) {
                    $out[$deck->id] = $card;

                    continue;
                }
            }
            $missing[$deck->id] = (int) $deck->rev;
        }

        if ($missing === []) {
            return $out;
        }

        $firstIds = DB::table('decks')->whereIn('id', array_keys($missing))
            ->select('id', DB::raw('(select s.id from slides s where s.deck_id = decks.id order by s.sort_order, s.id limit 1) as first_id'))
            ->pluck('first_id', 'id');
        $slides = DB::table('slides')->whereIn('id', $firstIds->filter()->values()->all())->get(['id', 'bg', 'els'])->keyBy('id');

        foreach ($missing as $deckId => $rev) {
            $slide = $slides->get($firstIds[$deckId] ?? 0);
            $card = $slide === null
                ? ['first_slide' => null, 'bg' => null, 'els' => []]
                : ['first_slide' => (int) $slide->id, 'bg' => self::decode($slide->bg), 'els' => self::cut(self::decode($slide->els) ?? [])];
            $out[$deckId] = $card;
            DB::table('decks')->where('id', $deckId)->update(['preview' => json_encode($card, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'preview_rev' => $rev]);
        }

        return $out;
    }

    /**
     * The visible elements that fit the byte budget; one that would overflow it is skipped, later smaller ones may still fit.
     *
     * @param  list<array<string, mixed>>  $els
     * @return list<array<string, mixed>>
     */
    public static function cut(array $els): array
    {
        $out = [];
        $bytes = 2;
        foreach ($els as $el) {
            if (! is_array($el) || ! empty($el['hide'])) {
                continue;
            }
            $size = strlen((string) json_encode($el)) + 1;
            if ($bytes + $size > self::BYTES) {
                continue;
            }
            $bytes += $size;
            $out[] = $el;
        }

        return $out;
    }

    private static function decode(mixed $raw): mixed
    {
        return is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    }
}
