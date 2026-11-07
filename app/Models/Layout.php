<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Layout extends Model
{
    use HasTeamScope;

    /** Custom layouts a team may keep. */
    public const MAX_PER_TEAM = 60;

    protected $fillable = ['team_id', 'user_id', 'name', 'kind', 'definition'];

    protected function casts(): array
    {
        return ['definition' => 'array'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** The value a slide stores in `slide.layout` for this layout. */
    public function documentId(): string
    {
        return 'custom:'.$this->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->documentId(),
            'db_id' => $this->id,
            'name' => $this->name,
            'kind' => $this->kind,
            'definition' => $this->definition ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
