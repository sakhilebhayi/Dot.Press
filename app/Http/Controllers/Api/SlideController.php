<?php

namespace App\Http\Controllers\Api;

use App\Events\SlideElementsUpdated;
use App\Http\Controllers\Controller;
use App\Models\Deck;
use App\Models\Slide;
use App\Services\CanvasElementSyncService;
use Illuminate\Http\Request;

class SlideController extends Controller
{
    public function __construct(private readonly CanvasElementSyncService $canvasElementSync) {}

    /**
     * Display a listing of the resource.
     *
     * No explicit user_id filter needed: Slide's HasUserScopeThroughOwner
     * trait applies it automatically to every query against this model.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Slide::class);

        $validated = $request->validate([
            'deck_id' => ['nullable', 'integer', 'exists:decks,id'],
        ]);

        if (isset($validated['deck_id'])) {
            $deck = Deck::with('project')->findOrFail($validated['deck_id']);
            $this->authorize('view', $deck);

            return $deck->slides()->orderBy('sort_order')->get();
        }

        return Slide::query()
            ->with('deck')
            ->orderByDesc('id')
            ->paginate(15);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $this->authorize('create', Slide::class);

        $payload = $request->validate([
            'deck_id' => ['required', 'integer', 'exists:decks,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'layout' => ['nullable', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'canvas_state' => ['nullable', 'array'],
        ]);

        $deck = Deck::with('project')->findOrFail($payload['deck_id']);
        $this->authorize('create', [Slide::class, $deck]);

        $canvasState = $payload['canvas_state'] ?? ['elements' => []];
        $elements = $canvasState['elements'] ?? [];

        $slide = $deck->slides()->create([
            'title' => $payload['title'] ?? 'Untitled Slide',
            'notes' => $payload['notes'] ?? null,
            'layout' => $payload['layout'] ?? 'blank',
            'sort_order' => $payload['sort_order'] ?? ((int) $deck->slides()->max('sort_order') + 1),
            'canvas_state' => ['meta' => $canvasState['meta'] ?? ['version' => 1]],
        ]);

        if (! empty($elements)) {
            $this->canvasElementSync->sync($slide, $elements);
        }

        $slide->canvas_state = $slide->canvasStatePayload();

        return response()->json($slide, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Slide $slide)
    {
        $this->authorize('view', $slide);

        $slide->load('deck');
        $slide->canvas_state = $slide->canvasStatePayload();

        return $slide;
    }

    /**
     * Update the specified resource in storage.
     *
     * canvas_state.elements is now persisted through the normalized
     * Element table (via CanvasElementSyncService), not stored as JSON --
     * canvas_state on the Slide row itself only ever holds non-element
     * meta (e.g. version/revision) from here on.
     */
    public function update(Request $request, Slide $slide)
    {
        $this->authorize('update', $slide);

        $payload = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'layout' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'canvas_state' => ['sometimes', 'nullable', 'array'],
            'expected_revision' => ['sometimes', 'integer', 'min:0'],
        ]);

        $incomingElements = null;

        if (array_key_exists('canvas_state', $payload) && array_key_exists('expected_revision', $payload)) {
            $expected = (int) $payload['expected_revision'];

            if ($slide->revision !== $expected) {
                return response()->json([
                    'message' => 'Slide changed in another session. Please reload latest state.',
                    'conflict' => true,
                    'server_revision' => $slide->revision,
                    'server_canvas_state' => $slide->canvasStatePayload(),
                ], 409);
            }

            $nextRevision = $slide->revision + 1;
            $canvasState = $payload['canvas_state'] ?? [];
            $incomingElements = $canvasState['elements'] ?? [];
            $meta = is_array($canvasState['meta'] ?? null) ? $canvasState['meta'] : [];
            $meta['revision'] = $nextRevision;

            $payload['canvas_state'] = ['meta' => $meta];
            $payload['revision'] = $nextRevision;
        } elseif (array_key_exists('canvas_state', $payload)) {
            $canvasState = $payload['canvas_state'] ?? [];
            $incomingElements = $canvasState['elements'] ?? [];
            $payload['canvas_state'] = ['meta' => $canvasState['meta'] ?? ($slide->canvas_state['meta'] ?? ['version' => 1])];
        }

        unset($payload['expected_revision']);

        $slide->update($payload);

        if ($incomingElements !== null) {
            $this->canvasElementSync->sync($slide, $incomingElements);
        }

        $slide = $slide->fresh();
        $slide->canvas_state = $slide->canvasStatePayload();

        if ($incomingElements !== null) {
            // ->toOthers() reads the X-Socket-Id header the saving
            // client's own Echo connection sends, so it doesn't receive
            // an echo of the change it just made itself.
            broadcast(new SlideElementsUpdated($slide, $request->user()->id, $request->user()->name))->toOthers();
        }

        return $slide;
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Slide $slide)
    {
        $this->authorize('delete', $slide);

        $slide->delete();

        return response()->noContent();
    }
}
