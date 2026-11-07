<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Theme extends Model
{
    use HasTeamScope;

    protected $fillable = ['team_id', 'user_id', 'name', 'tokens'];

    protected function casts(): array
    {
        return ['tokens' => 'array'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
