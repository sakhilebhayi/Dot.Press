<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use App\Services\Press\ThemeTokens;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Theme extends Model
{
    use HasTeamScope;

    /** Custom themes a team may keep. */
    public const MAX_PER_TEAM = 100;

    protected $fillable = ['team_id', 'user_id', 'name', 'tokens'];

    protected function casts(): array
    {
        return ['tokens' => 'array'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** The id decks store in `deck.theme.id` for this theme. */
    public function documentId(): string
    {
        return 'custom:'.$this->id;
    }

    /**
     * The document-shaped theme (spec section 3) plus API fields. Warnings are recomputed, never stored.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $theme = ThemeTokens::toDeckTheme($this->documentId(), $this->name, $this->tokens ?? []);

        return $theme + [
            'builtin' => false,
            'db_id' => $this->id,
            'warnings' => ThemeTokens::validate($theme)['warnings'],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
