<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * No explicit team filter needed: Project's HasTeamScope trait
     * already restricts every query to teams the authenticated user
     * belongs to, so this lists every project the user's teams own
     * (not just projects they personally created).
     */
    public function index()
    {
        $this->authorize('viewAny', Project::class);

        return Project::query()
            ->latest('id')
            ->paginate(15);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $this->authorize('create', Project::class);

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'settings' => ['nullable', 'array'],
        ]);

        $project = $request->user()->projects()->create([
            ...$payload,
            'team_id' => $request->user()->currentTeam->id,
        ]);

        return response()->json($project, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Project $project)
    {
        $this->authorize('view', $project);

        return $project->load('decks');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        $payload = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'settings' => ['sometimes', 'nullable', 'array'],
        ]);

        $project->update($payload);

        return $project->fresh();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Project $project)
    {
        $this->authorize('delete', $project);

        // One request must not erase a team's whole library: archive or delete the decks first, on purpose.
        if ($project->decks()->withoutGlobalScopes()->exists()) {
            return response()->json(['message' => 'This project still holds decks. Delete or archive them first.'], 409);
        }

        $project->delete();

        return response()->noContent();
    }
}
