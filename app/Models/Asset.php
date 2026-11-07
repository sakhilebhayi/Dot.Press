<?php

namespace App\Models;

use App\Models\Concerns\HasUserScopeThroughOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Asset extends Model
{
    use HasUserScopeThroughOwner;

    protected $fillable = [
        'project_id',
        'uploaded_by',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'metadata',
        'kind',
        'width',
        'height',
        'thumb_path',
        'web_path',
        'sha256',
        'alt',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    protected static function userOwnershipRelation(): string
    {
        return 'project';
    }
}
