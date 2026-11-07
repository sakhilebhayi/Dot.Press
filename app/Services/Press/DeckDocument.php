<?php

namespace App\Services\Press;

use App\Models\Deck;
use App\Models\DeckChange;
use App\Models\Slide;
use Generator;
use Illuminate\Support\Facades\DB;

/**
 * Assembles the presentation document (docs/programme/R40_DOCUMENT_SPEC.md sections 4, 5 and 9) from the models.
 * Read only: every change goes through OpApplier.
 *
 * Two ways to get the same document:
 *  - toArray(): PHP arrays, for services that read and analyse it (exporters, review, intelligence).
 *  - chunks() / json(): the JSON text, one slide at a time, with each slide's `els`, `bg` and `transition` passed through
 *    exactly as stored. Nothing is decoded, so memory stays at about one slide and a 300 slide deck costs a few
 *    milliseconds instead of 60 MB and 60 ms. Use it for everything that only sends the document on.
 */
class DeckDocument
{
    private const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * The status to show: the review flow decides when an approval no longer holds (Reviews, when present).
     */
    public static function status(Deck $deck): string
    {
        if (class_exists(Reviews::class) && method_exists(Reviews::class, 'effectiveStatus')) {
            return (string) Reviews::effectiveStatus($deck);
        }

        return (string) $deck->status;
    }

    /**
     * @return array{deck: array<string, mixed>, slides: list<array<string, mixed>>, seq: int}
     */
    public static function toArray(Deck $deck): array
    {
        $slides = $deck->slides()->orderBy('sort_order')->orderBy('id')->get();

        return [
            'deck' => self::deckBlock($deck, $slides->pluck('id')->all()),
            'slides' => $slides->map(fn ($slide) => self::slideToArray($slide))->all(),
            'seq' => self::seq($deck),
        ];
    }

    /**
     * One slide as spec section 5 describes it.
     *
     * @return array<string, mixed>
     */
    public static function slideToArray(Slide $slide): array
    {
        return [
            'id' => $slide->id,
            'title' => $slide->title ?? '',
            'notes' => $slide->notes ?? '',
            'section_id' => $slide->section_id,
            'hidden' => (bool) $slide->hidden,
            'layout' => $slide->layout ?: 'blank',
            'bg' => $slide->bg,
            'transition' => $slide->transition,
            'rev' => (int) $slide->revision,
            'els' => $slide->els ?? [],
        ];
    }

    /**
     * The document as JSON text in pieces, in the key order of toArray(): deck, slides, seq, then `assets` when a
     * callback is given. The callback receives the asset ids found in the slides and returns the `assets` map.
     *
     * The caller must already have proved the person may read the deck: this reads the tables directly, without the
     * per-user global scopes (the same reason PresentPayload does).
     *
     * Options: `notes` false writes null for every slide's notes (a guest never gets them), `deck_keys` keeps only those
     * keys of the deck block, `seq` writes that number instead of the log position.
     *
     * @param  (callable(list<int>): array<int|string, mixed>)|null  $assets
     * @param  array{notes?: bool, deck_keys?: list<string>, seq?: int}  $options
     * @return Generator<int, string>
     */
    public static function chunks(Deck $deck, ?callable $assets = null, array $options = []): Generator
    {
        $ids = DB::table('slides')->where('deck_id', $deck->id)->orderBy('sort_order')->orderBy('id')->pluck('id')->all();

        $deckBlock = self::deckBlock($deck, $ids);
        if (isset($options['deck_keys'])) {
            $deckBlock = array_intersect_key($deckBlock, array_flip($options['deck_keys']));
        }
        $notes = $options['notes'] ?? true;
        $assetIds = [];
        self::collectAssetIds(json_encode($deck->theme ?: null), $assetIds);

        yield '{"deck":'.json_encode($deckBlock, self::FLAGS).',"slides":[';

        $first = true;
        $rows = DB::table('slides')->where('deck_id', $deck->id)->orderBy('sort_order')->orderBy('id')
            ->select(['id', 'title', 'notes', 'section_id', 'hidden', 'layout', 'bg', 'transition', 'revision', 'els'])->cursor();
        foreach ($rows as $row) {
            $els = self::rawJson($row->els, '[]');
            $bg = self::rawJson($row->bg, 'null');
            $transition = self::rawJson($row->transition, 'null');
            self::collectAssetIds($els, $assetIds);
            self::collectAssetIds($bg, $assetIds);

            yield ($first ? '' : ',').'{"id":'.(int) $row->id
                .',"title":'.json_encode((string) ($row->title ?? ''), self::FLAGS)
                .',"notes":'.($notes ? json_encode((string) ($row->notes ?? ''), self::FLAGS) : 'null')
                .',"section_id":'.json_encode($row->section_id, self::FLAGS)
                .',"hidden":'.($row->hidden ? 'true' : 'false')
                .',"layout":'.json_encode($row->layout ?: 'blank', self::FLAGS)
                .',"bg":'.$bg
                .',"transition":'.$transition
                .',"rev":'.(int) $row->revision
                .',"els":'.$els.'}';
            $first = false;
        }

        yield '],"seq":'.($options['seq'] ?? self::seq($deck));

        if ($assets !== null) {
            yield ',"assets":'.(($map = $assets(array_keys($assetIds))) === [] ? '{}' : json_encode($map, self::FLAGS));
        }

        yield '}';
    }

    /** The whole document as one JSON string (see chunks()). */
    public static function json(Deck $deck, ?callable $assets = null, array $options = []): string
    {
        $out = '';
        foreach (self::chunks($deck, $assets, $options) as $piece) {
            $out .= $piece;
        }

        return $out;
    }

    /**
     * Bytes of element JSON in the deck: a cheap measure of how heavy the document is, used to decide whether a page
     * may carry it inline.
     */
    public static function weight(Deck $deck): int
    {
        return (int) DB::table('slides')->where('deck_id', $deck->id)->selectRaw('coalesce(sum(length(els)), 0) as n')->value('n');
    }

    /** True when the whole deck is small enough to write into a page (config press_perf.inline_bytes). */
    public static function isInlineSized(Deck $deck): bool
    {
        return self::weight($deck) <= (int) config('press_perf.inline_bytes', 400_000);
    }

    public static function seq(Deck $deck): int
    {
        return (int) (DeckChange::where('deck_id', $deck->id)->max('id') ?? 0);
    }

    /**
     * @param  list<int>  $slideOrder
     * @return array<string, mixed>
     */
    private static function deckBlock(Deck $deck, array $slideOrder): array
    {
        return [
            'id' => $deck->id,
            'title' => $deck->title,
            'w' => (int) $deck->w,
            'h' => (int) $deck->h,
            'theme' => $deck->theme ?: null,
            'rev' => (int) $deck->rev,
            'status' => self::status($deck),
            'visibility' => $deck->visibility,
            'sections' => $deck->sections ?? [],
            'slide_order' => array_map('intval', $slideOrder),
            'updated_at' => optional($deck->updated_at)->toIso8601String(),
        ];
    }

    /** A stored JSON column as it can be spliced into the output: itself when it is valid JSON, else $fallback. */
    private static function rawJson(mixed $value, string $fallback): string
    {
        if (! is_string($value) || $value === '' || $value === 'null') {
            return $fallback;
        }

        return json_validate($value) ? $value : $fallback;
    }

    /**
     * Asset references look like `"asset":12` in the stored JSON. A text that merely contains that phrase is escaped
     * (`\"asset\":12`) and never matches; a stray match only adds one id, and the asset lookup is team scoped anyway.
     *
     * @param  array<int, true>  $into
     */
    private static function collectAssetIds(?string $json, array &$into): void
    {
        if ($json === null || ! str_contains($json, '"asset"')) {
            return;
        }
        if (preg_match_all('/"asset":(\d+)/', $json, $m) > 0) {
            foreach ($m[1] as $id) {
                $into[(int) $id] = true;
            }
        }
    }
}
