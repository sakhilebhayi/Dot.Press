<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeckVersion extends Model
{
    /** Marks a snapshot stored as base64 of gzip. A plain snapshot is JSON and always starts with a brace. */
    public const PACKED = 'gz1:';

    public $timestamps = false;

    protected $fillable = ['deck_id', 'user_id', 'kind', 'label', 'snapshot', 'hash', 'slide_count', 'size', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /**
     * The snapshot as JSON text. It is stored gzipped (base64) when that saves more than 60 percent, which a deck
     * snapshot always does (repeated keys: a 4 MB snapshot of 300 slides packs to 190 KB); reading undoes it, and rows
     * written before this existed are plain JSON and read the same way. `size` stays the length of the JSON text.
     */
    protected function snapshot(): Attribute
    {
        return Attribute::make(
            get: fn (?string $stored) => self::unpack($stored),
            set: fn (?string $json) => self::pack($json),
        );
    }

    public static function pack(?string $json): ?string
    {
        if ($json === null || ! config('press_perf.version_compress', true) || strlen($json) < (int) config('press_perf.version_compress_min_bytes', 8192) || ! function_exists('gzencode')) {
            return $json;
        }

        $packed = self::PACKED.base64_encode((string) gzencode($json, 6));

        return strlen($packed) < strlen($json) * 0.4 ? $packed : $json;
    }

    public static function unpack(?string $stored): ?string
    {
        if ($stored === null || ! str_starts_with($stored, self::PACKED)) {
            return $stored;
        }

        $json = gzdecode((string) base64_decode(substr($stored, strlen(self::PACKED)), true));

        return $json === false ? null : $json;
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
