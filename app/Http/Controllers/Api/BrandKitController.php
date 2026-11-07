<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesPressTeam;
use App\Http\Controllers\Controller;
use App\Models\BrandKit;
use App\Services\Press\BrandKitTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Brand kits of a team: a named palette, up to three font families and logo assets. At most one kit is the
 * team's default; the theme list returns it so the editor can offer "Apply brand kit". Every kit carries its
 * conversion to a document theme (`theme`, built by BrandKit::toTheme()).
 */
class BrandKitController extends Controller
{
    use ResolvesPressTeam;

    public function index(Request $request): JsonResponse
    {
        $team = $this->resolveTeam($request);
        $this->requireTeamRole($request, $team, 'read');

        $kits = BrandKit::query()->where('team_id', $team->id)->orderByDesc('is_default')->orderBy('name')->orderBy('id')->get();

        return response()->json([
            'team_id' => $team->id,
            'data' => $kits->map(fn (BrandKit $kit): array => $kit->toPayload())->all(),
            'limits' => ['kits' => BrandKit::MAX_PER_TEAM, 'palette' => BrandKitTokens::MAX_PALETTE, 'fonts' => BrandKitTokens::MAX_FONTS, 'logos' => BrandKitTokens::MAX_LOGOS],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $team = $this->resolveTeam($request);
        $this->requireTeamRole($request, $team, 'create');

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:80'],
            'is_default' => ['sometimes', 'boolean'],
        ]);
        $tokens = $this->validatedTokens($request->input('tokens', []), $team->id);

        if (BrandKit::query()->where('team_id', $team->id)->count() >= BrandKit::MAX_PER_TEAM) {
            throw ValidationException::withMessages(['name' => 'A team can keep up to '.BrandKit::MAX_PER_TEAM.' brand kits. Delete one first.']);
        }

        $kit = DB::transaction(function () use ($team, $validated, $tokens): BrandKit {
            $makeDefault = (bool) ($validated['is_default'] ?? false);
            if ($makeDefault) {
                BrandKit::query()->where('team_id', $team->id)->update(['is_default' => false]);
            }

            return BrandKit::create([
                'team_id' => $team->id,
                'name' => $this->cleanName($validated['name']),
                'tokens' => $tokens,
                'is_default' => $makeDefault,
            ]);
        });

        return response()->json(['brand_kit' => $kit->toPayload()], 201);
    }

    public function show(Request $request, BrandKit $brandKit): JsonResponse
    {
        $this->requireTeamRole($request, $brandKit->team, 'read');

        return response()->json(['brand_kit' => $brandKit->toPayload()]);
    }

    public function update(Request $request, BrandKit $brandKit): JsonResponse
    {
        $this->requireTeamRole($request, $brandKit->team, 'update');

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:1', 'max:80'],
            'is_default' => ['sometimes', 'boolean'],
        ]);
        $tokens = $request->has('tokens') ? $this->validatedTokens($request->input('tokens'), $brandKit->team_id) : null;

        DB::transaction(function () use ($brandKit, $validated, $tokens): void {
            if (array_key_exists('name', $validated)) {
                $brandKit->name = $this->cleanName($validated['name']);
            }
            if ($tokens !== null) {
                $brandKit->tokens = $tokens;
            }
            if (array_key_exists('is_default', $validated)) {
                if ($validated['is_default']) {
                    BrandKit::query()->where('team_id', $brandKit->team_id)->where('id', '!=', $brandKit->id)->update(['is_default' => false]);
                }
                $brandKit->is_default = (bool) $validated['is_default'];
            }
            $brandKit->save();
        });

        return response()->json(['brand_kit' => $brandKit->refresh()->toPayload()]);
    }

    public function destroy(Request $request, BrandKit $brandKit): Response
    {
        $this->requireTeamRole($request, $brandKit->team, 'update');

        $brandKit->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedTokens(mixed $input, int $teamId): array
    {
        $result = BrandKitTokens::validate($input, $teamId);

        if ($result['errors'] !== []) {
            throw ValidationException::withMessages($result['errors']);
        }

        return $result['tokens'];
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
