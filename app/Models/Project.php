<?php

namespace App\Models;

use App\Models\Scopes\ProjectAccessScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $fillable = [
        'user_id',
        'team_id',
        'name',
        'slug',
        'description',
        'settings',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('team', new ProjectAccessScope);
    }

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function decks(): HasMany
    {
        return $this->hasMany(Deck::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }
}
