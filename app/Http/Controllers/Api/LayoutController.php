<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesPressTeam;
use App\Http\Controllers\Controller;
use App\Models\Layout;
use App\Services\Press\LayoutDefinition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Custom slide layouts of a team (the built-in layouts live in the editor). A slide uses one as `custom:<id>`.
 */
class LayoutController extends Controller
{
    use ResolvesPressTeam;

    public function index(Request $request): JsonResponse
    {
        $team = $this->resolveTeam($request);
        $this->requireTeamRole($request, $team, 'read');

        $layouts = Layout::query()->where('team_id', $team->id)->orderBy('name')->orderBy('id')->get();

        return response()->json([
            'team_id' => $team->id,
            'data' => $layouts->map(fn (Layout $layout): array => $layout->toPayload())->all(),
            'limits' => ['layouts' => Layout::MAX_PER_TEAM, 'elements' => LayoutDefinition::MAX_ELEMENTS],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $team = $this->resolveTeam($request);
        $this->requireTeamRole($request, $team, 'create');

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:80'],
            'kind' => ['sometimes', 'string', Rule::in(LayoutDefinition::KINDS)],
        ]);
        $definition = $this->validatedDefinition($request->input('definition'));

        if (Layout::query()->where('team_id', $team->id)->count() >= Layout::MAX_PER_TEAM) {
            throw ValidationException::withMessages(['name' => 'A team can keep up to '.Layout::MAX_PER_TEAM.' custom layouts. Delete one first.']);
        }

        $layout = Layout::create([
            'team_id' => $team->id,
            'user_id' => $request->user()->id,
            'name' => $this->cleanName($validated['name']),
            'kind' => $validated['kind'] ?? 'custom',
            'definition' => $definition,
        ]);

        return response()->json(['layout' => $layout->toPayload()], 201);
    }

    public function show(Request $request, Layout $layout): JsonResponse
    {
        $this->requireTeamRole($request, $layout->team, 'read');

        return response()->json(['layout' => $layout->toPayload()]);
    }

    public function update(Request $request, Layout $layout): JsonResponse
    {
        $this->requireTeamRole($request, $layout->team, 'update');

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:1', 'max:80'],
            'kind' => ['sometimes', 'string', Rule::in(LayoutDefinition::KINDS)],
        ]);

        if (array_key_exists('name', $validated)) {
            $layout->name = $this->cleanName($validated['name']);
        }
        if (array_key_exists('kind', $validated)) {
            $layout->kind = $validated['kind'];
        }
        if ($request->has('definition')) {
            $layout->definition = $this->validatedDefinition($request->input('definition'));
        }
        $layout->save();

        return response()->json(['layout' => $layout->toPayload()]);
    }

    public function destroy(Request $request, Layout $layout): Response
    {
        $this->requireTeamRole($request, $layout->team, 'update');

        $layout->delete();

        return response()->noContent();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validatedDefinition(mixed $input): array
    {
        $result = LayoutDefinition::validate($input);

        if ($result['errors'] !== []) {
            throw ValidationException::withMessages($result['errors']);
        }

        return $result['definition'];
    }

    private function cleanName(string $name): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name));

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'The name cannot be empty.']);
        }

        return $name;
    }
}
