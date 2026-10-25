<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deck;
use App\Models\Project;
use Illuminate\Http\Request;

class DeckController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * No explicit user_id filter needed: Deck's HasUserScopeThroughOwner
     * trait applies it automatically to every query against this model.
     */
    public function index()
    {
        $this->authorize('viewAny', Deck::class);

        $projectId = request()->query('project_id');

        if ($projectId !== null) {
            $project = Project::findOrFail($projectId);
            $this->authorize('view', $project);

            return $project->decks()->orderBy('sort_order')->get();
        }

        return Deck::query()
            ->with('project')
            ->orderByDesc('id')
            ->paginate(15);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $this->authorize('create', Deck::class);

        $payload = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'title' => ['required', 'string', 'max:255'],
            'theme' => ['nullable', 'array'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_template' => ['nullable', 'boolean'],
        ]);

        $project = Project::findOrFail($payload['project_id']);
        $this->authorize('create', [Deck::class, $project]);

        $deck = $project->decks()->create($payload);

        return response()->json($deck, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Deck $deck)
    {
        $this->authorize('view', $deck);

        return $deck->load('slides');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Deck $deck)
    {
        $this->authorize('update', $deck);

        $payload = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'theme' => ['sometimes', 'nullable', 'array'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_template' => ['sometimes', 'boolean'],
        ]);

        $deck->update($payload);

        return $deck->fresh();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Deck $deck)
    {
        $this->authorize('delete', $deck);

        $deck->delete();

        return response()->noContent();
    }
}
