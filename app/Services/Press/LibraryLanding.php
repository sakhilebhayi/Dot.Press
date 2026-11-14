<?php

namespace App\Services\Press;

use App\Models\Deck;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What the library says to the person who has just arrived from InfoDot: who they are, whose presentations these
 * are, the one presentation worth showing them (the one they opened last, else the newest they may see) and the
 * single primary action their role has. Everything here is read from real records; nothing is counted or guessed.
 */
final class LibraryLanding
{
    /** The original logo artwork, on a light ground and on a dark one. */
    public const LOGO = ['light' => '/images/logo.png', 'dark' => '/images/logo-light.png'];

    /**
     * @return array{firstName: string, company: string|null, logo: array{light: string, dark: string}, infodot: array{home: string, platforms: string}, featured: array<string, mixed>|null, primary: array{kind: string, label: string, href: string}|null}
     */
    public static function for(User $user, bool $canCreate): array
    {
        $featured = self::featured($user);
        $infodot = rtrim((string) config('ecosystem.platforms.infodot.url', 'https://infodot.co.za'), '/');

        return [
            'firstName' => Str::of((string) $user->name)->trim()->before(' ')->toString(),
            'company' => $user->currentTeam?->getAttribute('name'),
            'logo' => self::LOGO,
            'infodot' => ['home' => $infodot.'/home', 'platforms' => $infodot.'/platforms'],
            'featured' => $featured,
            'primary' => self::primary($canCreate, $featured),
        ];
    }

    /**
     * People who may create start a new presentation. People who may only read open the presentation shown to them;
     * with nothing to open there is honestly nothing to do yet, so there is no primary action.
     *
     * @param  array<string, mixed>|null  $featured
     * @return array{kind: string, label: string, href: string}|null
     */
    private static function primary(bool $canCreate, ?array $featured): ?array
    {
        if ($canCreate) {
            return ['kind' => 'new', 'label' => 'New presentation', 'href' => route('dashboard', ['new' => 1])];
        }

        if ($featured !== null) {
            return ['kind' => 'open', 'label' => 'Open the presentation', 'href' => route('press.editor', ['deck' => $featured['id']])];
        }

        return null;
    }

    /**
     * The same visibility rule as the library list (GET /api/decks): team scoping comes from Deck's global scope,
     * and a private deck only shows for its owner and the people it was shared with.
     *
     * @return array<string, mixed>|null
     */
    private static function featured(User $user): ?array
    {
        $sharedWithMe = DB::table('deck_shares')->select('deck_id')->where('user_id', $user->id);

        $deck = Deck::query()
            ->leftJoin('deck_user as du', function ($join) use ($user): void {
                $join->on('du.deck_id', '=', 'decks.id')->where('du.user_id', '=', $user->id);
            })
            ->select('decks.*', 'du.last_opened_at')
            ->withCount(['slides' => fn ($q) => $q->withoutGlobalScopes()])
            ->with('owner:id,name')
            ->where(function ($q) use ($user, $sharedWithMe): void {
                $q->where('decks.visibility', '!=', 'private')
                    ->orWhere('decks.user_id', $user->id)
                    ->orWhereIn('decks.id', $sharedWithMe);
            })
            ->whereNull('decks.archived_at')
            ->where('decks.is_template', false)
            ->orderByRaw('case when du.last_opened_at is null then 1 else 0 end')
            ->orderByDesc('du.last_opened_at')
            ->orderByDesc('decks.updated_at')
            ->orderByDesc('decks.id')
            ->first();

        if ($deck === null) {
            return null;
        }

        $preview = DeckPreview::forDecks([$deck])[$deck->id];
        $opened = $deck->getAttribute('last_opened_at');

        return [
            'id' => $deck->id,
            'title' => $deck->title,
            'w' => (int) $deck->w,
            'h' => (int) $deck->h,
            'status' => DeckDocument::status($deck),
            'theme' => $deck->theme,
            'slides' => (int) $deck->slides_count,
            'owner' => $deck->owner?->getAttribute('name'),
            'last_opened_at' => $opened === null ? null : Carbon::parse($opened)->toIso8601String(),
            'updated_at' => $deck->updated_at?->toIso8601String(),
            'first_slide' => $preview['first_slide'],
            'preview' => ['bg' => $preview['bg'], 'els' => $preview['els']],
        ];
    }
}
