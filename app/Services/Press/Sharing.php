<?php

namespace App\Services\Press;

use App\Models\Deck;
use App\Models\DeckShare;
use App\Models\Scopes\DeckAccessScope;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Who can open a deck and how: visibility (private, team, link), the public link and the people it was shared with.
 * Every change here is authorised by the caller (the `manage` ability) and recorded in the deck's activity.
 */
class Sharing
{
    public const TOKEN_LENGTH = 40;

    /** @return array<string, mixed> */
    public static function describe(Deck $deck): array
    {
        $isLink = $deck->visibility === 'link' && $deck->share_token;

        return [
            'visibility' => $deck->visibility,
            'link' => [
                'enabled' => (bool) $isLink,
                'token' => $isLink ? $deck->share_token : null,
                'url' => $isLink ? route('shared.show', ['token' => $deck->share_token]) : null,
                'allow_download' => (bool) $deck->share_allow_download,
                'has_password' => $deck->share_password !== null,
                'expires_at' => optional($deck->share_expires_at)->toIso8601String(),
                'expired' => $deck->share_expires_at !== null && $deck->share_expires_at->isPast(),
            ],
            'people' => self::shares($deck),
        ];
    }

    /** @return list<array{user_id: int, name: string, email: string, role: string}> */
    public static function shares(Deck $deck): array
    {
        return $deck->shares()->with('user:id,name,email')->orderBy('id')->get()
            ->filter(fn (DeckShare $share) => $share->user !== null)
            ->map(fn (DeckShare $share) => [
                'user_id' => $share->user_id,
                'name' => $share->user->name,
                'email' => $share->user->email,
                'role' => $share->role,
            ])->values()->all();
    }

    /**
     * Apply visibility and link settings. Keys that are absent are left alone; `password` set to null or an empty
     * string removes the password. Leaving `link` visibility revokes the token, so an old link never wakes up again.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function update(Deck $deck, User $actor, array $settings): Deck
    {
        $before = $deck->visibility;

        if (array_key_exists('visibility', $settings) && $settings['visibility'] !== $deck->visibility) {
            $deck->visibility = $settings['visibility'];

            if ($deck->visibility === 'link') {
                $deck->share_token = self::newToken();
            } else {
                $deck->share_token = null;
            }
        }

        if (array_key_exists('allow_download', $settings)) {
            $deck->share_allow_download = (bool) $settings['allow_download'];
        }

        if (array_key_exists('password', $settings)) {
            $deck->share_password = ($settings['password'] === null || $settings['password'] === '')
                ? null
                : Hash::make((string) $settings['password']);
        }

        if (array_key_exists('expires_at', $settings)) {
            $deck->share_expires_at = $settings['expires_at'];
        }

        $deck->save();

        if ($before !== $deck->visibility) {
            ActivityRecorder::record($deck, $actor, 'visibility', ['from' => $before, 'to' => $deck->visibility]);
        } elseif ($deck->visibility === 'link') {
            ActivityRecorder::record($deck, $actor, 'shared', ['action' => 'link.settings']);
        }

        return $deck;
    }

    public static function rotateLink(Deck $deck, User $actor): Deck
    {
        if ($deck->visibility !== 'link') {
            throw ValidationException::withMessages(['visibility' => 'The deck has no public link to rotate. Turn the link on first.']);
        }

        $deck->share_token = self::newToken();
        $deck->save();

        ActivityRecorder::record($deck, $actor, 'shared', ['action' => 'link.rotated']);

        return $deck;
    }

    public static function newToken(): string
    {
        return Str::random(self::TOKEN_LENGTH);
    }

    /** Find the person to share with: an existing account, by id or e-mail address. */
    public static function findPerson(?int $userId, ?string $email): User
    {
        $user = null;

        if ($userId) {
            $user = User::find($userId);
        } elseif ($email) {
            $user = User::whereRaw('lower(email) = ?', [Str::lower(trim($email))])->first();
        }

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => 'No account matches that person. Accounts are created when someone signs in through InfoDot, so until then use the public link.',
            ]);
        }

        return $user;
    }

    public static function addPerson(Deck $deck, User $actor, User $person, string $role): DeckShare
    {
        self::guardTarget($deck, $person, $role);

        $share = DeckAccessScope::without(fn () => DeckShare::updateOrCreate(
            ['deck_id' => $deck->id, 'user_id' => $person->id],
            ['role' => $role, 'invited_by' => $actor->id],
        ));

        DeckAccess::forgetRemembered();

        ActivityRecorder::record($deck, $actor, 'shared', [
            'action' => $share->wasRecentlyCreated ? 'added' : 'changed',
            'user_id' => $person->id,
            'name' => $person->name,
            'role' => $role,
        ]);

        return $share;
    }

    public static function removePerson(Deck $deck, User $actor, User $person): bool
    {
        $removed = DeckAccessScope::without(fn () => DeckShare::where('deck_id', $deck->id)->where('user_id', $person->id)->delete());
        DeckAccess::forgetRemembered();

        if ($removed) {
            ActivityRecorder::record($deck, $actor, 'shared', ['action' => 'removed', 'user_id' => $person->id, 'name' => $person->name]);
        }

        return (bool) $removed;
    }

    private static function guardTarget(Deck $deck, User $person, string $role): void
    {
        if (! in_array($role, DeckAccess::SHARE_ROLES, true)) {
            throw ValidationException::withMessages(['role' => 'The role must be viewer, commenter or editor.']);
        }

        if ((int) $deck->user_id === $person->id) {
            throw ValidationException::withMessages(['email' => 'That person created the deck and already owns it.']);
        }
    }

    // ---- the public link ------------------------------------------------------------------------------------

    /** The deck behind a public token, or null when the link is wrong, off, expired or the deck is archived. */
    public static function deckForToken(string $token): ?Deck
    {
        return DeckAccessScope::without(function () use ($token): ?Deck {
            $deck = Deck::where('share_token', $token)->first();

            if (! $deck || $deck->visibility !== 'link' || $deck->archived_at !== null) {
                return null;
            }

            if ($deck->share_expires_at !== null && $deck->share_expires_at->isPast()) {
                return null;
            }

            return $deck;
        });
    }

    /** The marker kept in the visitor's session once the password was accepted. It dies with a rotated token or a changed password. */
    public static function unlockMarker(Deck $deck): string
    {
        return hash('sha256', $deck->id.'|'.$deck->share_token.'|'.$deck->share_password);
    }
}
