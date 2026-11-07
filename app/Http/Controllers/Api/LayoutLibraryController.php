<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Press\LayoutLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The built-in slide layouts (title, section, bullets ...), read only. A team's own layouts are served by
 * LayoutController (GET /api/layouts) and are not affected by this one.
 *
 * Geometry depends on the deck's aspect family (see App\Services\Press\LayoutLibrary): pass the deck size as
 * ?w=&h= and every layout comes back with placeholders for that size. Without a size the 16:9 layouts are returned.
 */
class LayoutLibraryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'w' => ['nullable', 'integer', 'min:320', 'max:7680'],
            'h' => ['nullable', 'integer', 'min:320', 'max:7680'],
        ]);

        $w = (int) ($v['w'] ?? 1920);
        $h = (int) ($v['h'] ?? 1080);

        return response()->json([
            'w' => $w,
            'h' => $h,
            'family' => LayoutLibrary::family($w, $h),
            'data' => LayoutLibrary::forSize($w, $h),
        ]);
    }
}
