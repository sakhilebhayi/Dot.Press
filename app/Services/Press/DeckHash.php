<?php

namespace App\Services\Press;

use App\Models\Deck;
use Illuminate\Support\Facades\DB;

/**
 * A stable fingerprint of what a deck says: its size, theme, sections and every slide's content in order.
 * Approvals are bound to this value, so an approved deck stops being approved the moment it changes.
 * Presence, comments, activity and revision counters are not part of it.
 *
 * compute() reads one slide at a time and feeds the hash incrementally, so a 600 slide deck needs the memory of one
 * slide (the first version built the whole deck as arrays: 134 ms and about 60 MB for 300 slides). The value is the same
 * as before byte for byte: the canonical JSON is written in the same key order, in pieces.
 *
 * current() is compute() remembered on the deck row against its `rev`. Every accepted batch, restore and import bumps
 * `rev`, so a remembered hash that was computed at the same `rev` is the hash of the deck as it is. The library and the
 * document answer read the status of every deck they show, and that needs this hash for any deck that was ever
 * approved.
 */
class DeckHash
{
    /** The fingerprint of the deck as it is stored now. Never cached. */
    public static function compute(Deck $deck): string
    {
        $ctx = hash_init('sha256');

        hash_update($ctx, '{"h":'.(int) $deck->h
            .',"sections":'.self::json(self::sorted($deck->sections ?? []))
            .',"slides":[');

        $first = true;
        foreach (DB::table('slides')->where('deck_id', $deck->id)->orderBy('sort_order')->orderBy('id')
            ->select(['title', 'notes', 'hidden', 'section_id', 'layout', 'bg', 'transition', 'els'])->cursor() as $row) {
            $slide = [
                'title' => (string) $row->title,
                'notes' => (string) $row->notes,
                'hidden' => (bool) $row->hidden,
                'section' => $row->section_id,
                'layout' => $row->layout,
                'bg' => self::decode($row->bg),
                'transition' => self::decode($row->transition),
                'els' => self::decode($row->els) ?? [],
            ];
            hash_update($ctx, ($first ? '' : ',').self::canonical($slide));
            $first = false;
        }

        hash_update($ctx, '],"theme":'.self::json(self::sorted($deck->theme))
            .',"title":'.self::json($deck->title)
            .',"w":'.(int) $deck->w.'}');

        return hash_final($ctx);
    }

    /**
     * compute(), kept on the deck row until the deck's `rev` changes. Safe when a hash is computed from content newer
     * than the `rev` the caller holds: it is then stored under the older `rev`, which no longer matches, so the next
     * reader computes it again.
     */
    public static function current(Deck $deck): string
    {
        $rev = (int) $deck->rev;
        if ($deck->content_hash !== null && $deck->hash_rev !== null && (int) $deck->hash_rev === $rev) {
            return (string) $deck->content_hash;
        }

        $hash = self::compute($deck);

        if ($deck->exists) {
            DB::table('decks')->where('id', $deck->id)->update(['content_hash' => $hash, 'hash_rev' => $rev]);
            $deck->setAttribute('content_hash', $hash);
            $deck->setAttribute('hash_rev', $rev);
            $deck->syncOriginalAttributes(['content_hash', 'hash_rev']);
        }

        return $hash;
    }

    public static function canonical(mixed $value): string
    {
        return self::json(self::sorted($value));
    }

    private static function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function decode(mixed $raw): mixed
    {
        return is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    }

    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(fn ($item) => self::sorted($item), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
