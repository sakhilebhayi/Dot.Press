<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandKit extends Model
{
    use HasTeamScope;

    protected $fillable = ['team_id', 'name', 'tokens', 'is_default'];

    protected function casts(): array
    {
        return ['tokens' => 'array', 'is_default' => 'boolean'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
