<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeckComment extends Model
{
    protected $fillable = ['deck_id', 'slide_id', 'element_id', 'parent_id', 'user_id', 'body', 'mentions', 'anchor', 'resolved_at', 'resolved_by'];

    protected function casts(): array
    {
        return ['mentions' => 'array', 'anchor' => 'array', 'resolved_at' => 'datetime'];
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
