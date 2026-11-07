<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Project;
use App\Services\Press\Assets\AssetLimits;
use App\Services\Press\Assets\AssetRejected;
use App\Services\Press\Assets\AssetStore;
use App\Services\Press\Assets\AssetStreamer;
use App\Services\Press\Assets\AssetUrls;
use App\Services\Press\Assets\AssetUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The asset library of a project. Team scoping comes from Asset's global scope (another team's asset is a 404),
 * roles from AssetPolicy. Files are only ever read through the signed `assets.file` route.
 */
class AssetController extends Controller
{
    public function __construct(private AssetUsage $usage) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Asset::class);

        $validated = $request->validate([
            'project_id' => ['nullable', 'integer'],
            'kind' => ['nullable', 'in:image,video,audio,poster'],
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'in:newest,oldest,name,size'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $project = $this->resolveProject($request, $validated['project_id'] ?? null, create: false);

        if ($project === null) {
            return response()->json(['data' => [], 'next_cursor' => null, 'has_more' => false, 'per_page' => 0]);
        }

        $query = Asset::query()->where('project_id', $project->id);

        // Posters are derivatives of videos: listed only when asked for.
        isset($validated['kind']) ? $query->where('kind', $validated['kind']) : $query->where('kind', '!=', 'poster');

        if (($term = trim($validated['q'] ?? '')) !== '') {
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
            $query->whereRaw("original_name LIKE ? ESCAPE '!'", ['%'.$escaped.'%']);
        }

        match ($validated['sort'] ?? 'newest') {
            'oldest' => $query->orderBy('id'),
            'name' => $query->orderBy('original_name')->orderBy('id'),
            'size' => $query->orderByDesc('size')->orderByDesc('id'),
            default => $query->orderByDesc('id'),
        };

        $page = $query->cursorPaginate((int) ($validated['limit'] ?? 30))->withQueryString();

        $items = collect($page->items());
        $counts = $this->usage->countsFor($items);
        $posters = Asset::query()->whereIn('id', $items->map(fn (Asset $a) => $a->metadata['poster_asset_id'] ?? null)->filter()->all())->get()->keyBy('id');

        return response()->json([
            'data' => $items->map(fn (Asset $asset): array => AssetUrls::payload(
                $asset,
                $counts[$asset->id] ?? ['decks' => 0, 'slides' => 0, 'brand_kits' => 0],
                $posters->get($asset->metadata['poster_asset_id'] ?? 0),
            ))->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
            'has_more' => $page->hasMorePages(),
            'per_page' => $page->perPage(),
            'expires_in_seconds' => AssetUrls::ttlMinutes() * 60,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Asset::class);

        $request->validate(['project_id' => ['nullable', 'integer']]);

        $project = $this->resolveProject($request, $request->input('project_id'), create: true);
        abort_if($project === null, 404, 'There is no workspace to upload into.');
        $this->authorize('create', [Asset::class, $project]);

        $file = $request->file('file');
        if ($file === null) {
            $declared = (int) $request->server('CONTENT_LENGTH', 0);
            $postMax = AssetLimits::iniBytes((string) ini_get('post_max_size'));

            throw ValidationException::withMessages([
                'file' => $postMax > 0 && $declared > $postMax ? AssetLimits::serverLimitMessage() : 'Choose a file to upload.',
            ]);
        }

        try {
            $asset = AssetStore::storeUpload($project, $request->user(), $file);
        } catch (AssetRejected $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return response()->json($this->envelope($asset, ! $asset->wasRecentlyCreated), $asset->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Asset $asset): JsonResponse
    {
        $this->authorize('view', $asset);

        return response()->json($this->envelope($asset));
    }

    public function update(Request $request, Asset $asset): JsonResponse
    {
        $this->authorize('update', $asset);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:200'],
            'alt' => ['sometimes', 'nullable', 'string', 'max:240'],
        ]);

        if (array_key_exists('name', $validated)) {
            $name = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '', $validated['name']));
            if ($name === '') {
                throw ValidationException::withMessages(['name' => 'The name cannot be empty.']);
            }
            $asset->original_name = $name;
        }

        if (array_key_exists('alt', $validated)) {
            $alt = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', (string) $validated['alt']));
            $asset->alt = $alt === '' ? null : $alt;
        }

        $asset->save();

        return response()->json($this->envelope($asset));
    }

    public function destroy(Request $request, Asset $asset): Response
    {
        $this->authorize('delete', $asset);

        $usage = $this->usage->forAsset($asset, $request->user());

        if ($usage['in_use']) {
            if (! $request->boolean('force')) {
                return response()->json([
                    'message' => 'This asset is used in '.$usage['deck_count'].' deck(s)'.($usage['brand_kit_count'] ? ' and '.$usage['brand_kit_count'].' brand kit(s)' : '').'. Remove it from them first, or delete it anyway.',
                    'decks' => $usage['decks'],
                    'brand_kits' => $usage['brand_kits'],
                ], 409);
            }

            abort_unless(
                $request->user()->hasTeamPermission($asset->project->team, 'delete'),
                403,
                'Only a team admin can delete an asset that is still in use.',
            );
        }

        AssetStore::purge($asset);

        return response()->noContent();
    }

    public function usage(Request $request, Asset $asset): JsonResponse
    {
        $this->authorize('view', $asset);

        return response()->json($this->usage->forAsset($asset, $request->user()));
    }

    public function poster(Request $request, Asset $asset): JsonResponse
    {
        $this->authorize('update', $asset);

        $frame = $request->file('file');
        if ($frame === null) {
            throw ValidationException::withMessages(['file' => 'Send the captured frame as a JPEG or PNG file.']);
        }

        try {
            $poster = app(AssetStore::class)->storePoster($asset->project, $request->user(), $asset, $frame);
        } catch (AssetRejected $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return response()->json([
            'poster' => AssetUrls::payload($poster),
            'asset' => AssetUrls::payload($asset->refresh(), null, $poster),
        ], 201);
    }

    public function file(Request $request, Asset $asset): Response
    {
        $this->authorize('view', $asset);

        $variant = in_array($request->query('v'), AssetUrls::VARIANTS, true) ? $request->query('v') : 'orig';

        return app(AssetStreamer::class)->respond($request, $asset, $variant);
    }

    public function download(Request $request, Asset $asset): Response
    {
        $this->authorize('view', $asset);

        return app(AssetStreamer::class)->respond($request, $asset, 'orig', attachment: true);
    }

    /**
     * @return array<string, mixed>
     */
    private function envelope(Asset $asset, ?bool $deduplicated = null): array
    {
        $counts = $this->usage->countsFor([$asset])[$asset->id] ?? null;
        $payload = AssetUrls::payload($asset, $counts);

        return [
            'asset' => $payload,
            'delivery_url' => $payload['url'],
            'expires_in_seconds' => AssetUrls::ttlMinutes() * 60,
        ] + ($deduplicated === null ? [] : ['deduplicated' => $deduplicated]);
    }

    /**
     * The project a request is about: the one named (which must be visible to the person), else the current team's
     * workspace project. With $create, a team member who may create projects gets the workspace made on first use.
     */
    private function resolveProject(Request $request, mixed $projectId, bool $create): ?Project
    {
        if ($projectId !== null && $projectId !== '') {
            $project = Project::findOrFail((int) $projectId);
            $this->authorize('view', $project);

            return $project;
        }

        $user = $request->user();
        $teamId = $user->currentTeam?->id;
        if ($teamId === null) {
            return null;
        }

        $project = Project::query()->where('team_id', $teamId)->where('name', 'Dot.Press Workspace')->first()
            ?? Project::query()->where('team_id', $teamId)->orderBy('id')->first();

        if ($project === null && $create && $user->can('create', Project::class)) {
            $project = Project::create([
                'team_id' => $teamId,
                'user_id' => $user->id,
                'name' => 'Dot.Press Workspace',
                'slug' => 'dot-press-workspace',
                'settings' => ['theme' => 'default'],
            ]);
        }

        return $project;
    }
}
