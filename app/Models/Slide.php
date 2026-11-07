<?php

namespace App\Models;

use App\Models\Concerns\HasScopeThroughDeck;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
