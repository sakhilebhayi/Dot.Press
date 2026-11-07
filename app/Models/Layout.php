<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Layout extends Model
{
    use HasTeamScope;

    protected $fillable = ['team_id', 'user_id', 'name', 'kind', 'definition'];

    protected function casts(): array
    {
        return ['definition' => 'array'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
