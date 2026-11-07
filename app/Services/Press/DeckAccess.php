<?php

namespace App\Services\Press;

use App\Models\Asset;
use App\Models\Deck;
use App\Models\DeckShare;
use App\Models\Project;
use App\Models\Scopes\DeckAccessScope;
use App\Models\Slide;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Jetstream;

/**
 * The one place that decides what a person may do with a deck. Policies, controllers and the global scopes all
 * derive from it.
 *
 * Roles, weakest to strongest: viewer, commenter, editor, owner.
 *
 *  - The deck's creator is `owner` while they are still a member of the project's team.
 *  - On a `team` or `link` deck the team owner and team admins are `owner`, team editors `editor`, team viewers
 *    `viewer` (who may also comment).
 *  - On a `private` deck the team grants nothing: only the creator and the people it was shared with have a role.
 *  - A share (viewer, commenter, editor) raises a role and never lowers a team role. It may name any existing user,
 *    inside the team or not.
 *
 * Abilities: view, comment, update, manage (share, visibility, review requests, rename, delete), present, export.
 */
class DeckAccess
{
    public const RANK = ['viewer' => 1, 'commenter' => 2, 'editor' => 3, 'owner' => 4];

    public const SHARE_ROLES = ['viewer', 'commenter', 'editor'];

    public const ABILITIES = ['view', 'comment', 'update', 'manage', 'present', 'export'];

    public static function roleFor(User $user, Deck $deck): ?string
    {
        return self::grantFor($user, $deck)?->role;
    }

    public static function can(User $user, Deck $deck, string $ability): bool
    {
        return self::grantFor($user, $deck)?->can($ability) ?? false;
    }

    public static function grantFor(User $user, Deck $deck): ?DeckAccessGrant
    {
        $team = self::teamOf($deck);
        $teamKey = $team ? self::teamRoleKey($user, $team) : null;
        $shareRole = DB::table('deck_shares')
            ->where('deck_id', $deck->id)->where('user_id', $user->id)->value('role');

        return self::combine($deck->visibility, (int) $deck->user_id === $user->id, $teamKey, $shareRole);
    }

    /**
     * Pure combination of the three sources. $teamKey is null (not in the team), 'owner' (owns the team) or the
     * Jetstream role key.
     */
    public static function combine(?string $visibility, bool $isCreator, ?string $teamKey, ?string $shareRole): ?DeckAccessGrant
    {
        if ($isCreator && $teamKey !== null) {
            return new DeckAccessGrant('owner');
        }

        $teamRole = null;

        if ($teamKey !== null && $visibility !== 'private') {
            $teamRole = self::roleForTeamKey($teamKey);
        }

        $shareRole = in_array($shareRole, self::SHARE_ROLES, true) ? $shareRole : null;

        $role = collect([$teamRole, $shareRole])
            ->filter()
            ->sortByDesc(fn (string $candidate) => self::RANK[$candidate])
            ->first();

        if ($role === null) {
            return null;
        }

        return new DeckAccessGrant($role, $teamRole === 'viewer');
    }

    /** The team that owns the deck, found without any access scope (the caller may be outside it). */
    public static function teamOf(Deck $deck): ?Team
    {
        $teamId = Project::withoutGlobalScopes()->whereKey($deck->project_id)->value('team_id');

        return $teamId ? Team::find($teamId) : null;
    }

    public static function deckOf(Slide $slide): ?Deck
    {
        return Deck::withoutGlobalScopes()->find($slide->deck_id);
    }

    public static function teamRoleKey(User $user, Team $team): ?string
    {
        if ((int) $team->user_id === $user->id) {
            return 'owner';
        }

        return DB::table('team_user')->where('team_id', $team->id)->where('user_id', $user->id)->value('role');
    }

    public static function roleForTeamKey(string $key): ?string
    {
        if ($key === 'owner' || $key === 'admin') {
            return 'owner';
        }

        $permissions = Jetstream::findRole($key)?->permissions ?? [];

        return match (true) {
            in_array('update', $permissions, true) || in_array('*', $permissions, true) => 'editor',
            in_array('read', $permissions, true) => 'viewer',
            default => null,
        };
    }

    /**
     * Everyone who can open the deck, with their role and where it comes from. Used for mention suggestions and the
     * people panel.
     *
     * @return list<array{id: int, name: string, email: string, role: string, source: string, can_comment: bool}>
     */
    public static function people(Deck $deck): array
    {
        $team = self::teamOf($deck);
        $teamKeys = [];

        if ($team) {
            $teamKeys[$team->user_id] = 'owner';
            foreach (DB::table('team_user')->where('team_id', $team->id)->get(['user_id', 'role']) as $row) {
                $teamKeys[$row->user_id] ??= $row->role;
            }
        }

        $shares = DB::table('deck_shares')->where('deck_id', $deck->id)->pluck('role', 'user_id')->all();
        $ids = array_unique([...array_keys($teamKeys), ...array_keys($shares)]);
        $users = User::whereIn('id', $ids)->get()->keyBy('id');

        $people = [];

        foreach ($ids as $id) {
            $user = $users->get($id);
            $grant = self::combine($deck->visibility, (int) $deck->user_id === (int) $id, $teamKeys[$id] ?? null, $shares[$id] ?? null);

            if (! $user || ! $grant) {
                continue;
            }

            $people[] = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $grant->role,
                'source' => self::sourceOf($deck, (int) $id, $teamKeys[$id] ?? null),
                'can_comment' => $grant->can('comment'),
            ];
        }

        usort($people, fn ($a, $b) => [DeckAccess::RANK[$b['role']], $a['name']] <=> [DeckAccess::RANK[$a['role']], $b['name']]);

        return $people;
    }

    private static function sourceOf(Deck $deck, int $userId, ?string $teamKey): string
    {
        if ($teamKey !== null && (int) $deck->user_id === $userId) {
            return 'creator';
        }

        return $teamKey !== null && $deck->visibility !== 'private' ? 'team' : 'share';
    }

    /** True when a slide (or the deck theme) of the deck uses the asset: an image, video, audio file or a background. */
    public static function deckUsesAsset(Deck $deck, int $assetId): bool
    {
        return in_array($assetId, self::assetIdsOfDeck($deck), true);
    }

    /** @return list<int> Every asset id the deck's slides and theme point at (`{asset: id}` references). */
    public static function assetIdsOfDeck(Deck $deck): array
    {
        $ids = self::assetIdsIn($deck->theme ?? []);

        DeckAccessScope::without(function () use ($deck, &$ids): void {
            foreach (Slide::where('deck_id', $deck->id)->get(['id', 'els', 'bg']) as $slide) {
                $ids = [...$ids, ...self::assetIdsIn($slide->els ?? []), ...self::assetIdsIn($slide->bg ?? [])];
            }
        });

        return array_values(array_unique($ids));
    }

    /** @return list<int> */
    public static function assetIdsIn(mixed $node): array
    {
        if (! is_array($node)) {
            return [];
        }

        $ids = [];

        foreach ($node as $key => $value) {
            if ($key === 'asset' && (is_int($value) || (is_string($value) && ctype_digit($value)))) {
                $ids[] = (int) $value;
            } elseif (is_array($value)) {
                $ids = [...$ids, ...self::assetIdsIn($value)];
            }
        }

        return $ids;
    }

    /** May this person fetch the asset because a deck shared with them uses it? Team members reach assets through the team. */
    public static function canViewAssetThroughDeck(User $user, Asset $asset): bool
    {
        $deckIds = DB::table('deck_shares')->where('user_id', $user->id)->pluck('deck_id');

        foreach (Deck::withoutGlobalScopes()->whereIn('id', $deckIds)->get() as $deck) {
            if (self::can($user, $deck, 'view') && self::deckUsesAsset($deck, $asset->id)) {
                return true;
            }
        }

        return false;
    }

    public static function shareFor(User $user, Deck $deck): ?DeckShare
    {
        return DeckShare::withoutGlobalScopes()->where('deck_id', $deck->id)->where('user_id', $user->id)->first();
    }
}
