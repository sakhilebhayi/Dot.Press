<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesPressTeam;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\BrandKit;
use App\Models\Project;
use App\Models\Theme;
use App\Services\Press\ThemeLibrary;
use App\Services\Press\ThemeTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Themes of a team: the built-in library first, then the team's own. Decks keep a snapshot of the theme they use
 * (`deck.theme`), so changing or deleting a custom theme never alters a deck that already uses it.
 */
class ThemeController extends Controller
{
    use ResolvesPressTeam;

    public function index(Request $request): JsonResponse
    {
        $team = $this->resolveTeam($request);
        $this->requireTeamRole($request, $team, 'read');

        $builtin = array_map(
            fn (array $theme): array => $theme + ['builtin' => true, 'warnings' => ThemeTokens::validate($theme)['warnings']],
            ThemeLibrary::all(),
        );

        $custom = Theme::query()->where('team_id', $team->id)->orderBy('name')->orderBy('id')->get()
            ->map(fn (Theme $theme): array => $theme->toPayload())->all();

        $kit = BrandKit::query()->where('team_id', $team->id)->where('is_default', true)->first();

        return response()->json([
            'team_id' => $team->id,
            'data' => array_merge($builtin, $custom),
            'default_brand_kit' => $kit?->toPayload(),
            'limits' => ['custom_themes' => Theme::MAX_PER_TEAM],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $team = $this->resolveTeam($request);
        $this->requireTeamRole($request, $team, 'create');

        $name = $this->validatedName($request);
        $tokens = $this->validatedTokens($request->only(['c', 't', 's', 'bg']), $team->id);

        if (Theme::query()->where('team_id', $team->id)->count() >= Theme::MAX_PER_TEAM) {
            throw ValidationException::withMessages(['name' => 'A team can keep up to '.Theme::MAX_PER_TEAM.' custom themes. Delete one first.']);
        }

        $theme = Theme::create([
            'team_id' => $team->id,
            'user_id' => $request->user()->id,
            'name' => $name,
            'tokens' => $tokens['tokens'],
        ]);

        return response()->json(['theme' => $theme->toPayload()], 201);
    }

    public function show(Request $request, Theme $theme): JsonResponse
    {
        $this->requireTeamRole($request, $theme->team, 'read');

        return response()->json(['theme' => $theme->toPayload()]);
    }

    public function update(Request $request, Theme $theme): JsonResponse
    {
        $this->requireTeamRole($request, $theme->team, 'update');

        $current = $theme->tokens ?? [];
        $merged = $current;
        foreach (['c', 't', 's'] as $key) {
            if ($request->has($key)) {
                $given = $request->input($key);
                $merged[$key] = is_array($given) && ! array_is_list($given) ? array_merge($current[$key] ?? [], $given) : $given;
            }
        }
        if ($request->has('bg')) {
            $merged['bg'] = $request->input('bg');
        }

        $tokens = $this->validatedTokens($merged, $theme->team_id);

        if ($request->has('name')) {
            $theme->name = $this->validatedName($request);
        }
        $theme->tokens = $tokens['tokens'];
        $theme->save();

        return response()->json(['theme' => $theme->toPayload()]);
    }

    public function destroy(Request $request, Theme $theme): Response
    {
        $this->requireTeamRole($request, $theme->team, 'update');

        $theme->delete();

        return response()->noContent();
    }

    private function validatedName(Request $request): string
    {
        $name = $request->validate(['name' => ['required', 'string', 'min:1', 'max:80']])['name'];
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name));

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'The name cannot be empty.']);
        }

        return $name;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{tokens: array<string, mixed>}
     */
    private function validatedTokens(array $input, int $teamId): array
    {
        $result = ThemeTokens::validate($input);

        if ($result['errors'] !== []) {
            throw ValidationException::withMessages($result['errors']);
        }

        // A background picture must be in this team's own library, like a brand kit logo.
        $asset = $input['bg']['img']['asset'] ?? null;
        if ($asset !== null) {
            $own = Asset::withoutGlobalScopes()
                ->whereKey((int) $asset)
                ->whereIn('project_id', Project::withoutGlobalScopes()->where('team_id', $teamId)->select('id'))
                ->exists();
            if (! $own) {
                throw ValidationException::withMessages(['bg' => 'That background picture does not exist in this team\'s asset library.']);
            }
        }

        return ['tokens' => ThemeTokens::normalise($input)];
    }
}
