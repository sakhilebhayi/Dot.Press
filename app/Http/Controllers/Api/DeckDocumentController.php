<?php

namespace App\Http\Controllers\Api;

use App\Console\Commands\PruneDeckChanges;
use App\Http\Controllers\Controller;
use App\Models\Deck;
use App\Models\DeckChange;
use App\Services\Press\DeckDocument;
use App\Services\Press\DocumentAssets;
use App\Services\Press\DocumentSanitizer;
use App\Services\Press\JsonStream;
use App\Services\Press\OpApplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The presentation document and its operations (docs/programme/R40_DOCUMENT_SPEC.md sections 8 and 9).
 */
class DeckDocumentController extends Controller
{
    /** Changes returned per request; a longer gap comes back in pages (`more`). */
    private const PAGE = 500;

    /**
     * The whole document, streamed: the stored JSON of every slide is passed through without being decoded, so a 600
     * slide deck needs a few megabytes of memory instead of more than the host's limit, and is gzipped on the way out.
     */
    public function show(Request $request, Deck $deck, DocumentAssets $assets): StreamedResponse
    {
        $this->authorize('view', $deck);

        return JsonStream::response($request, DeckDocument::chunks($deck, fn (array $ids) => $assets->forIds($ids)));
    }

    public function ops(Request $request, Deck $deck, OpApplier $applier): JsonResponse
    {
        $this->authorize('update', $deck);

        $budget = DocumentSanitizer::requestBudget();
        if (strlen($request->getContent()) > $budget) {
            return response()->json(['message' => $budget < DocumentSanitizer::MAX_REQUEST_BYTES
                ? 'The request is larger than this server can take at once ('.round($budget / 1048576, 1).' MB). Send fewer changes at a time.'
                : 'The request is larger than 8 MB.'], 413);
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

        // The common poll: nothing happened since the tab last looked. One probe of the (deck_id, id) index answered it.
        if ($after === $head) {
            return response()->json(['seq' => $head, 'more' => false, 'changes' => []]);
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
