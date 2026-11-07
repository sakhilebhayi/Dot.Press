<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PresentSession extends Model
{
    protected $fillable = ['deck_id', 'user_id', 'token', 'slide_index', 'step', 'state', 'command', 'last_seen', 'ended_at'];

    protected function casts(): array
    {
        return ['state' => 'array', 'command' => 'array', 'last_seen' => 'datetime', 'ended_at' => 'datetime'];
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
