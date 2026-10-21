<?php

namespace App\Http\Controllers;

use App\Models\Deck;
use App\Models\Project;
use App\Models\Slide;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SlideEditorController extends Controller
{
    public function start(Request $request): RedirectResponse
    {
        $user = $request->user();
        $team = $user->currentTeam;

        if (! $user->can('create', Project::class)) {
            return $this->openExistingWorkspace($user->current_team_id);
        }

        $project = Project::firstOrCreate(
            [
                'team_id' => $team->id,
                'name' => 'Dot.Press Workspace',
            ],
            [
                'user_id' => $user->id,
                'slug' => 'dot-press-workspace',
                'settings' => ['theme' => 'default'],
            ],
        );

        $deck = $project->decks()->firstOrCreate(
            ['title' => 'Main Deck'],
            [
                'sort_order' => 0,
                'theme' => ['background' => '#ffffff'],
            ],
        );

        $slide = $deck->slides()->orderBy('sort_order')->first();

        if ($slide === null) {
            $slide = $deck->slides()->create([
                'title' => 'Slide 1',
                'layout' => 'blank',
                'sort_order' => 0,
                'canvas_state' => [
                    'meta' => ['version' => 1],
                ],
            ]);
        }

        return redirect()->route('editor.slides.show', [$deck, $slide]);
    }

    /**
     * Someone who may only read (a viewer) never creates the workspace, deck or
     * first slide that start() would otherwise make: they are sent to the first
     * slide that already exists, or told there is nothing to view yet.
     */
    private function openExistingWorkspace(?int $teamId): RedirectResponse
    {
        $project = $teamId ? Project::where('team_id', $teamId)->where('name', 'Dot.Press Workspace')->first() : null;
        $deck = $project ? Deck::where('project_id', $project->id)->where('title', 'Main Deck')->first() : null;
        $slide = $deck ? Slide::where('deck_id', $deck->id)->orderBy('sort_order')->first() : null;

        abort_if($slide === null, 403, 'You can view presentations here but not create them, and this workspace has none yet.');

        return redirect()->route('editor.slides.show', [$deck, $slide]);
    }

    public function show(Deck $deck, Slide $slide): Response
    {
        $this->authorize('view', $deck);
        $this->authorize('view', $slide);

        abort_unless($slide->deck_id === $deck->id, 404);

        $deck->load([
            'project',
            'slides' => fn ($query) => $query->orderBy('sort_order'),
        ]);

        return Inertia::render('Canvas/Editor', [
            'canEdit' => request()->user()->can('update', $slide),
            'deck' => [
                'id' => $deck->id,
                'title' => $deck->title,
                'project' => [
                    'id' => $deck->project->id,
                    'name' => $deck->project->name,
                ],
            ],
            'slides' => $deck->slides->map(fn (Slide $slideItem) => [
                'id' => $slideItem->id,
                'title' => $slideItem->title,
                'sort_order' => $slideItem->sort_order,
            ]),
            'slide' => [
                'id' => $slide->id,
                'title' => $slide->title,
                'layout' => $slide->layout,
                'sort_order' => $slide->sort_order,
                'revision' => $slide->revision,
                'canvas_state' => $slide->canvasStatePayload(),
            ],
        ]);
    }

    public function present(Deck $deck, Slide $slide): Response
    {
        $this->authorize('view', $deck);
        $this->authorize('view', $slide);

        abort_unless($slide->deck_id === $deck->id, 404);

        $deck->load([
            'project',
            'slides' => fn ($query) => $query->orderBy('sort_order'),
        ]);

        return Inertia::render('Canvas/Presentation', [
            'deck' => [
                'id' => $deck->id,
                'title' => $deck->title,
                'project' => [
                    'id' => $deck->project->id,
                    'name' => $deck->project->name,
                ],
            ],
            'slides' => $deck->slides->map(fn (Slide $slideItem) => [
                'id' => $slideItem->id,
                'title' => $slideItem->title,
                'sort_order' => $slideItem->sort_order,
            ]),
            'slide' => [
                'id' => $slide->id,
                'title' => $slide->title,
                'layout' => $slide->layout,
                'sort_order' => $slide->sort_order,
                'revision' => $slide->revision,
                'canvas_state' => $slide->canvasStatePayload(),
            ],
        ]);
    }
}
