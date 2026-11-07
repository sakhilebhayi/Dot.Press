<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeckChange extends Model
{
    public $timestamps = false;

    protected $fillable = ['deck_id', 'user_id', 'client', 'key', 'ops', 'result', 'created_at'];

    protected function casts(): array
    {
        return ['ops' => 'array', 'result' => 'array', 'created_at' => 'datetime'];
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
