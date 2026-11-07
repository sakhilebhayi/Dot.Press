<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Services\Press\ActivityRecorder;
use App\Services\Press\JsonStream;
use App\Services\Press\VersionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeckVersionController extends Controller
{
    public const MAX_NAMED = 200;

    public function index(Request $request, Deck $deck, VersionService $versions): JsonResponse
    {
        $this->authorize('view', $deck);

        $v = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'before' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json($versions->list($deck, (int) ($v['limit'] ?? 50), isset($v['before']) ? (int) $v['before'] : null));
    }

    public function store(Request $request, Deck $deck, VersionService $versions): JsonResponse
    {
        $this->authorize('update', $deck);

        $v = $request->validate(['label' => ['nullable', 'string', 'max:120']]);

        // Named versions are kept for good, so their number is bounded (the 40 newest automatic ones prune themselves).
        if (DeckVersion::query()->where('deck_id', $deck->id)->where('kind', 'manual')->count() >= self::MAX_NAMED) {
            throw ValidationException::withMessages(['label' => 'A deck keeps up to '.self::MAX_NAMED.' saved versions. Restore the one you need or keep working: the automatic history continues.']);
        }

        $version = $versions->create($deck, $request->user(), 'manual', $v['label'] ?? null);
        ActivityRecorder::record($deck, $request->user(), 'version.saved', ['version' => $version->id, 'label' => $version->label]);

        return response()->json($versions->present($version->load('user:id,name')), 201);
    }

    /**
     * One version with its snapshot. The snapshot is stored as JSON text, so it is passed on as it is instead of being
     * decoded and encoded again (52 MB of PHP memory for a 300 slide deck, none now).
     */
    public function show(Request $request, Deck $deck, int $version, VersionService $versions): StreamedResponse
    {
        $this->authorize('view', $deck);

        $found = $versions->find($deck, $version);
        $meta = json_encode($versions->present($found), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return JsonStream::response($request, [substr((string) $meta, 0, -1).',"snapshot":', $found->snapshot ?? 'null', '}']);
    }

    public function restore(Request $request, Deck $deck, int $version, VersionService $versions): JsonResponse
    {
        $this->authorize('update', $deck);

        return response()->json($versions->restore($deck, $versions->find($deck, $version), $request->user()));
    }
}
