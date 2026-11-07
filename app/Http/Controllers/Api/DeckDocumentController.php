<?php

namespace App\Http\Controllers\Api;

use App\Console\Commands\PruneDeckChanges;
use App\Http\Controllers\Controller;
use App\Models\Deck;
use App\Models\DeckChange;
use App\Services\Press\DeckDocument;
use App\Services\Press\DocumentAssets;
use App\Services\Press\DocumentSanitizer;
use App\Services\Press\OpApplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The presentation document and its operations (docs/programme/R40_DOCUMENT_SPEC.md sections 8 and 9).
 */
class DeckDocumentController extends Controller
{
    /** Changes returned per request; a longer gap comes back in pages (`more`). */
    private const PAGE = 500;

    public function show(Deck $deck, DocumentAssets $assets): JsonResponse
    {
        $this->authorize('view', $deck);

        $document = DeckDocument::toArray($deck);
        $document['assets'] = (object) $assets->forDocument($document);

        return response()->json($document);
    }

    public function ops(Request $request, Deck $deck, OpApplier $applier): JsonResponse
    {
        $this->authorize('update', $deck);

        if (strlen($request->getContent()) > DocumentSanitizer::MAX_REQUEST_BYTES) {
            return response()->json(['message' => 'The request is larger than 8 MB.'], 413);
        }

        $v = $request->validate([
            'client' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_.:-]+$/D'],
            'key' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.:-]+$/D'],
            'ops' => ['required', 'array', 'min:1', 'max:'.DocumentSanitizer::MAX_OPS],
        ]);

        if ($deck->archived_at !== null) {
            return response()->json(['message' => 'This deck is archived. Restore it from the archive to edit it.'], 409);
        }

        $result = $applier->apply($deck, $request->user(), $v['client'], $v['key'], array_values($v['ops']));

        $result['slides'] = (object) $result['slides'];
        $result['ids'] = (object) $result['ids'];

        return response()->json($result);
    }

    /**
     * What others changed after `after`. `{"stale": true}` when the log no longer reaches back that far (or the client
     * names a seq the deck never had): the client reloads the document.
     */
    public function changes(Request $request, Deck $deck): JsonResponse
    {
        $this->authorize('view', $deck);

        $v = $request->validate(['after' => ['nullable', 'integer', 'min:0']]);
        $after = (int) ($v['after'] ?? 0);

        $head = (int) (DeckChange::query()->where('deck_id', $deck->id)->max('id') ?? 0);
        $tombstone = DeckChange::query()->where('deck_id', $deck->id)->where('key', PruneDeckChanges::TOMBSTONE_KEY)->max('id');

        if ($after > $head || ($tombstone !== null && $after < $tombstone)) {
            return response()->json(['stale' => true, 'seq' => $head]);
        }

        $rows = DeckChange::query()
            ->where('deck_id', $deck->id)
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit(self::PAGE + 1)
            ->with('user:id,name')
            ->get();

        $more = $rows->count() > self::PAGE;
        $rows = $rows->take(self::PAGE);

        return response()->json([
            'seq' => $rows->isEmpty() ? $head : $rows->last()->id,
            'more' => $more,
            'changes' => $rows->map(fn (DeckChange $c) => [
                'seq' => $c->id,
                'client' => $c->client,
                'user' => $c->user ? ['id' => $c->user->id, 'name' => $c->user->name] : null,
                'ops' => $c->ops,
                'at' => $c->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }
}
