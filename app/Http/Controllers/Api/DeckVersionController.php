<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deck;
use App\Services\Press\ActivityRecorder;
use App\Services\Press\VersionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeckVersionController extends Controller
{
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

        $version = $versions->create($deck, $request->user(), 'manual', $v['label'] ?? null);
        ActivityRecorder::record($deck, $request->user(), 'version.saved', ['version' => $version->id, 'label' => $version->label]);

        return response()->json($versions->present($version->load('user:id,name')), 201);
    }

    public function show(Deck $deck, int $version, VersionService $versions): JsonResponse
    {
        $this->authorize('view', $deck);

        return response()->json($versions->present($versions->find($deck, $version), true));
    }

    public function restore(Request $request, Deck $deck, int $version, VersionService $versions): JsonResponse
    {
        $this->authorize('update', $deck);

        return response()->json($versions->restore($deck, $versions->find($deck, $version), $request->user()));
    }
}
