<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeckExport extends Model
{
    protected $fillable = ['deck_id', 'user_id', 'format', 'status', 'options', 'disk', 'path', 'size', 'error', 'attempts', 'started_at', 'finished_at', 'expires_at'];

    protected function casts(): array
    {
        return ['options' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'expires_at' => 'datetime'];
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
