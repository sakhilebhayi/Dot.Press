<?php

namespace App\Http\Controllers;

use App\Models\Deck;
use App\Services\Press\DeckAccess;
use App\Services\Press\DeckDocument;
use App\Services\Press\DocumentAssets;
use App\Services\Press\ThemeLibrary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The two signed-in pages of Dot.Press: the library and the editor. Both load their data from the JSON API after
 * the first paint, except the editor, which receives the document once so it can draw straight away.
 */
class DeckPageController extends Controller
{
    public function library(Request $request): Response
    {
        $user = $request->user();
        $team = $user->currentTeam;

        return Inertia::render('Press/Library', [
            'canCreate' => $team !== null && $user->hasTeamPermission($team, 'create'),
            'team' => $team ? ['id' => $team->id, 'name' => $team->name] : null,
            'ai' => ['mode' => config('ai.provider', 'mock') === 'anthropic' ? 'ai' : 'local'],
            'themes' => collect(ThemeLibrary::definitions())->map(fn ($theme) => ['id' => $theme['id'], 'name' => $theme['name'], 'c' => $theme['c'] ?? [], 't' => $theme['t'] ?? []])->values(),
        ]);
    }

    public function editor(Request $request, Deck $deck, DocumentAssets $assets): Response|RedirectResponse
    {
        $this->authorize('view', $deck);

        if ($deck->archived_at !== null) {
            return redirect()->route('dashboard')->with('status', 'That presentation is in the archive.');
        }

        $user = $request->user();
        DB::table('deck_user')->upsert([['deck_id' => $deck->id, 'user_id' => $user->id, 'last_opened_at' => now()]], ['deck_id', 'user_id'], ['last_opened_at']);

        $document = DeckDocument::toArray($deck);
        $document['assets'] = (object) $assets->forDocument($document);

        return Inertia::render('Press/Editor', [
            'document' => $document,
            'role' => DeckAccess::roleFor($user, $deck),
            'can' => collect(['update', 'comment', 'manage', 'present', 'export'])
                ->mapWithKeys(fn (string $ability) => [$ability => DeckAccess::can($user, $deck, $ability)])
                ->all(),
            'me' => ['id' => $user->id, 'name' => $user->name],
            'ai' => ['mode' => config('ai.provider', 'mock') === 'anthropic' ? 'ai' : 'local'],
            'startSlide' => $request->query('slide'),
        ]);
    }

    /** Links from before R-40 (one page per slide) land on the new editor. */
    public function legacyEditor(Request $request, ?Deck $deck = null): RedirectResponse
    {
        return $deck
            ? redirect()->route('press.editor', ['deck' => $deck, 'slide' => $request->route('slide')])
            : redirect()->route('dashboard');
    }

    public function legacyPresent(Deck $deck): RedirectResponse
    {
        return redirect()->route('press.player', $deck);
    }
}
