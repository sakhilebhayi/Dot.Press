<?php

namespace App\Models;

use App\Models\Concerns\HasScopeThroughDeck;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Slide extends Model
{
    use HasScopeThroughDeck;

    protected $fillable = [
        'deck_id',
        'title',
        'notes',
        'layout',
        'sort_order',
        'canvas_state',
        'revision',
        'els',
        'bg',
        'transition',
        'section_id',
        'hidden',
    ];

    protected function casts(): array
    {
        return [
            'canvas_state' => 'array',
            'revision' => 'integer',
            'els' => 'array',
            'bg' => 'array',
            'transition' => 'array',
            'hidden' => 'boolean',
        ];
    }

    /**
     * True while DeckBatch writes a whole batch: OpApplier bumps the deck's `rev` once at the end, which is what
     * invalidates the cached hash and library preview, so the per slide hook below stays quiet.
     */
    public static bool $quiet = false;

    protected static function booted(): void
    {
        $forget = function (Slide $slide): void {
            if (! self::$quiet && $slide->deck_id) {
                self::forgetDeckCaches((int) $slide->deck_id);
            }
        };
        static::saved($forget);
        static::deleted($forget);
    }

    /**
     * The deck's content hash and library preview are remembered against its `rev` (DeckHash::current, DeckPreview).
     * A slide written without going through an operations batch, which does not bump `rev`, forgets both here.
     */
    public static function forgetDeckCaches(int $deckId): void
    {
        DB::table('decks')->where('id', $deckId)->where(fn ($q) => $q->whereNotNull('hash_rev')->orWhereNotNull('preview_rev'))->update(['hash_rev' => null, 'preview_rev' => null]);
    }

    public function deck(): BelongsTo
    {
        return $this->belongsTo(Deck::class);
    }

    public function elements(): HasMany
    {
        return $this->hasMany(Element::class);
    }

    /**
     * The canvas_state the frontend actually reads: elements assembled
     * live from the Element table (the source of truth) merged with
     * whatever non-element meta (e.g. version/revision) lives in the
     * canvas_state column. Callers should use this instead of the raw
     * canvas_state attribute wherever elements are read.
     *
     * @return array{elements: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function canvasStatePayload(): array
    {
        return [
            'elements' => $this->elements()
                ->orderBy('sort_order')
                ->get()
                ->map(fn (Element $element) => $element->toCanvasElement())
                ->all(),
            'meta' => $this->canvas_state['meta'] ?? ['version' => 1],
        ];
    }
}
