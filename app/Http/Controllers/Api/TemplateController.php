<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Press\TemplateLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The built-in presentation templates, read only. A deck is created from one with POST /api/decks {template: key}.
 */
class TemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'category' => ['nullable', 'string', Rule::in(array_keys(TemplateLibrary::CATEGORIES))],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $all = TemplateLibrary::all();
        $needle = Str::lower(trim((string) ($v['q'] ?? '')));

        $data = array_values(array_filter($all, function (array $t) use ($v, $needle): bool {
            if (isset($v['category']) && $t['category'] !== $v['category']) {
                return false;
            }
            if ($needle === '') {
                return true;
            }
            $haystack = Str::lower($t['name'].' '.$t['blurb'].' '.$t['narrative'].' '.$t['category_name'].' '.implode(' ', $t['tags']));

            return str_contains($haystack, $needle);
        }));

        $categories = [];
        foreach (TemplateLibrary::CATEGORIES as $id => $name) {
            $categories[] = ['id' => $id, 'name' => $name, 'count' => count(array_filter($all, fn (array $t): bool => $t['category'] === $id))];
        }

        return response()->json(['data' => $data, 'categories' => $categories]);
    }

    public function show(string $key): JsonResponse
    {
        $meta = TemplateLibrary::find($key);
        abort_if($meta === null, 404);

        $built = TemplateLibrary::build($key);

        return response()->json([
            'template' => $meta + [
                'theme_data' => $built['theme'],
                'sections' => $built['sections'],
                'outline' => array_map(fn (array $s): array => ['title' => $s['title'], 'layout' => $s['layout'], 'section_id' => $s['section_id']], $built['slides']),
                'preview' => ['w' => 1920, 'h' => 1080, 'slide' => $built['slides'][0]],
            ],
        ]);
    }
}
