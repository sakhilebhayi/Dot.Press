<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeckPresence extends Model
{
    protected $table = 'deck_presence';

    public $timestamps = false;

    protected $fillable = ['deck_id', 'user_id', 'client', 'name', 'color', 'slide_id', 'selection', 'cursor', 'seen_at'];

    protected function casts(): array
    {
        return ['selection' => 'array', 'cursor' => 'array', 'seen_at' => 'datetime'];
    }

    public function deck(): BelongsTo
    {
        return $this->belongsTo(Deck::class);
    }
}
