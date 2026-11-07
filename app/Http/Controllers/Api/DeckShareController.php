<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deck;
use App\Models\DeckShare;
use App\Models\User;
use App\Services\Press\DeckAccess;
use App\Services\Press\Sharing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Visibility, the public link and the people a deck is shared with. Everything here needs the `manage` ability. */
class DeckShareController extends Controller
{
    public function show(Deck $deck): array
    {
        $this->authorize('manage', $deck);

        return Sharing::describe($deck);
    }

    public function update(Request $request, Deck $deck): array
    {
        $this->authorize('manage', $deck);

        $data = $request->validate([
            'visibility' => ['sometimes', Rule::in(Deck::VISIBILITIES)],
            'allow_download' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'nullable', 'string', 'min:6', 'max:200'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ]);

        if (! empty($data['expires_at'])) {
            $data['expires_at'] = Carbon::parse($data['expires_at']);
        }

        Sharing::update($deck, $request->user(), $data);

        return Sharing::describe($deck->refresh());
    }

    public function rotate(Request $request, Deck $deck): array
    {
        $this->authorize('manage', $deck);

        Sharing::rotateLink($deck, $request->user());

        return Sharing::describe($deck->refresh());
    }

    public function people(Deck $deck): array
    {
        $this->authorize('manage', $deck);

        return ['people' => Sharing::shares($deck)];
    }

    public function addPerson(Request $request, Deck $deck): JsonResponse
    {
        $this->authorize('manage', $deck);

        $data = $request->validate([
            'user_id' => ['nullable', 'integer', 'required_without:email'],
            'email' => ['nullable', 'string', 'email', 'max:255', 'required_without:user_id'],
            'role' => ['required', Rule::in(DeckAccess::SHARE_ROLES)],
        ]);

        $person = Sharing::findPerson($data['user_id'] ?? null, $data['email'] ?? null);
        Sharing::addPerson($deck, $request->user(), $person, $data['role']);

        return response()->json(['people' => Sharing::shares($deck)], 201);
    }

    public function updatePerson(Request $request, Deck $deck, User $user): array
    {
        $this->authorize('manage', $deck);
        abort_unless(DeckShare::where('deck_id', $deck->id)->where('user_id', $user->id)->exists(), 404);

        $data = $request->validate(['role' => ['required', Rule::in(DeckAccess::SHARE_ROLES)]]);
        Sharing::addPerson($deck, $request->user(), $user, $data['role']);

        return ['people' => Sharing::shares($deck)];
    }

    public function removePerson(Request $request, Deck $deck, User $user)
    {
        $this->authorize('manage', $deck);
        abort_unless(Sharing::removePerson($deck, $request->user(), $user), 404);

        return response()->noContent();
    }
}
