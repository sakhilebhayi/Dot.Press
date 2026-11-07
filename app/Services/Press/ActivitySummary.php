<?php

namespace App\Services\Press;

use App\Models\DeckActivity;

/** Turns an activity row into one readable sentence, built on the server so every client says the same thing. */
class ActivitySummary
{
    public static function for(DeckActivity $row, ?string $userName): string
    {
        $who = $userName ?: 'Someone';
        $meta = $row->meta ?? [];

        return match ($row->event) {
            'created' => "{$who} created the deck",
            'renamed' => $who.' renamed the deck'.self::quoted($meta['to'] ?? null, ' to '),
            'resized' => $who.' changed the slide size'.(isset($meta['w'], $meta['h']) ? " to {$meta['w']} by {$meta['h']}" : ''),
            'version.saved' => $who.' saved a version'.self::quoted($meta['label'] ?? null, ': '),
            'version.restored' => $who.' restored an earlier version'.self::quoted($meta['label'] ?? null, ': '),
            'exported' => $who.' exported the deck'.(isset($meta['format']) ? ' as '.strtoupper((string) $meta['format']) : ''),
            'imported' => "{$who} imported a file into the deck",
            'visibility' => "{$who} changed who can open the deck from ".self::visibility($meta['from'] ?? null).' to '.self::visibility($meta['to'] ?? null),
            'shared' => self::shared($who, $meta),
            'comment' => self::comment($who, $meta),
            'review.requested' => $who.' asked '.($meta['reviewer'] ?? 'someone').' to review the deck',
            'review.approved' => "{$who} approved the deck",
            'review.changes_requested' => "{$who} asked for changes",
            'review.withdrawn' => "{$who} withdrew a review request",
            'approved' => 'The deck was approved',
            default => "{$who} ".str_replace(['.', '_'], ' ', (string) $row->event),
        };
    }

    /** @param  array<string, mixed>  $meta */
    private static function shared(string $who, array $meta): string
    {
        $name = $meta['name'] ?? 'someone';

        return match ($meta['action'] ?? null) {
            'added' => "{$who} shared the deck with {$name} as ".($meta['role'] ?? 'viewer'),
            'changed' => "{$who} changed {$name} to ".($meta['role'] ?? 'viewer'),
            'removed' => "{$who} stopped sharing the deck with {$name}",
            'link.rotated' => "{$who} replaced the public link",
            'link.settings' => "{$who} changed the public link settings",
            default => "{$who} changed sharing",
        };
    }

    /** @param  array<string, mixed>  $meta */
    private static function comment(string $who, array $meta): string
    {
        return match ($meta['action'] ?? 'created') {
            'reply' => "{$who} replied to a comment",
            'resolved' => "{$who} resolved a comment thread",
            'reopened' => "{$who} reopened a comment thread",
            'deleted' => "{$who} deleted a comment",
            default => "{$who} commented",
        };
    }

    private static function visibility(?string $value): string
    {
        return match ($value) {
            'private' => 'private (only the people chosen)',
            'team' => 'the whole team',
            'link' => 'anyone with the link',
            default => 'unknown',
        };
    }

    private static function quoted(?string $value, string $joiner): string
    {
        return $value ? $joiner.'"'.$value.'"' : '';
    }
}
