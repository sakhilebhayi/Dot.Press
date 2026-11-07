<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deck;
use App\Services\Press\DeckAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Everyone who can open the deck, with their role: the source for @mention suggestions and reviewer pickers. */
class DeckPeopleController extends Controller
{
    public function index(Request $request, Deck $deck): array
    {
        $this->authorize('view', $deck);

        $query = Str::lower(trim((string) $request->query('q', '')));
        $canSeeEmails = $request->user()->can('manage', $deck);

        $people = collect(DeckAccess::people($deck))
            ->when($query !== '', fn ($c) => $c->filter(fn ($p) => str_contains(Str::lower($p['name']), $query)))
            ->map(function (array $person) use ($canSeeEmails, $request) {
                if (! $canSeeEmails) {
                    unset($person['email']);
                }

                $person['you'] = $person['id'] === $request->user()->id;

                return $person;
            })
            ->values()
            ->all();

        return ['people' => $people, 'you' => DeckAccess::grantFor($request->user(), $deck)?->abilities()];
    }
}
