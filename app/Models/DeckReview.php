<?php

namespace App\Models;

use App\Models\Concerns\HasScopeThroughDeck;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeckReview extends Model
{
    use HasScopeThroughDeck;

    protected $fillable = ['deck_id', 'requested_by', 'reviewer_id', 'status', 'round', 'note', 'decision_note', 'version_hash', 'decided_at'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function deck(): BelongsTo
    {
        return $this->belongsTo(Deck::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
