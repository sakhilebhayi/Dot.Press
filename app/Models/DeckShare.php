<?php

namespace App\Models;

use App\Models\Concerns\HasScopeThroughDeck;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeckShare extends Model
{
    use HasScopeThroughDeck;

    protected $fillable = ['deck_id', 'user_id', 'role', 'invited_by'];

    protected function casts(): array
    {
        return [];
    }

    public function deck(): BelongsTo
    {
        return $this->belongsTo(Deck::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
