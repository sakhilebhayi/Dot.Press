<?php

namespace App\Models;

use App\Models\Concerns\HasDeckAccessScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Deck extends Model
{
    use HasDeckAccessScope;

    public const STATUSES = ['draft', 'in_review', 'approved'];

    public const VISIBILITIES = ['private', 'team', 'link'];

    protected $fillable = [
        'project_id',
        'user_id',
        'title',
        'theme',
        'sort_order',
        'is_template',
        'w',
        'h',
        'status',
        'visibility',
        'rev',
        'sections',
        'archived_at',
        'template_category',
        'template_blurb',
    ];

    /** Never mass assigned: the sharing secrets and the approval stamp are only set by their own services. */
    protected $hidden = ['share_password', 'share_token', 'preview', 'content_hash'];

    protected function casts(): array
    {
        return [
            'theme' => 'array',
            'sections' => 'array',
            'is_template' => 'boolean',
            'share_allow_download' => 'boolean',
            'share_expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'archived_at' => 'datetime',
            'w' => 'integer',
            'h' => 'integer',
            'rev' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function slides(): HasMany
    {
        return $this->hasMany(Slide::class)->orderBy('sort_order');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(DeckChange::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DeckVersion::class);
    }

    public function shares(): HasMany
    {
        return $this->hasMany(DeckShare::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(DeckComment::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(DeckReview::class);
    }

    public function activity(): HasMany
    {
        return $this->hasMany(DeckActivity::class);
    }

    public function presence(): HasMany
    {
        return $this->hasMany(DeckPresence::class);
    }

    public function exports(): HasMany
    {
        return $this->hasMany(DeckExport::class);
    }

    public function presentSessions(): HasMany
    {
        return $this->hasMany(PresentSession::class);
    }
}
