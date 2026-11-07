<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Press\Assets\AssetLimits;
use App\Services\Press\Import\ImportedDeckBuilder;
use App\Services\Press\Import\ImportException;
use App\Services\Press\Import\PptxImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DeckImportController extends Controller
{
    /** POST /api/imports/pptx (multipart, field "file") */
    public function store(Request $request): JsonResponse
    {
        $limit = (int) config('press_export.import.max_bytes', 60 * 1048576);
        $request->validate([
            'file' => ['required', 'file', 'max:'.(int) ($limit / 1024), 'extensions:pptx'],
        ], [
            'file.max' => 'The file is larger than '.(int) ($limit / 1048576).' MB, which is the most that can be imported.',
            'file.extensions' => 'Choose a PowerPoint file ending in .pptx.',
        ]);
        $file = $request->file('file');
        $path = $file->getRealPath();
        if ($path === false) {
            throw ValidationException::withMessages(['file' => 'The upload could not be read.']);
        }
        @set_time_limit(120);
        // Up to 150 MB of pictures are held in memory while a file is read; the default limit of a shared host (128 MB)
        // would end the request in a fatal error with no message. Exports raise it the same way.
        $limit = AssetLimits::iniBytes((string) ini_get('memory_limit'));
        if ($limit > 0 && $limit < 512 * 1048576) {
            @ini_set('memory_limit', '512M');
        }

        try {
            $result = PptxImporter::import($path, ['name' => pathinfo((string) $file->getClientOriginalName(), PATHINFO_FILENAME)]);
        } catch (ImportException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['file' => [$e->getMessage()]]], 422);
        }

        $built = ImportedDeckBuilder::build($request->user(), $result, (string) $file->getClientOriginalName());
        $deck = $built['deck'];
        $first = $deck->slides()->orderBy('sort_order')->first();
        $report = $built['report'];

        return response()->json([
            'deck' => [
                'id' => $deck->id,
                'title' => $deck->title,
                'w' => (int) $deck->w,
                'h' => (int) $deck->h,
                'slides' => $deck->slides()->count(),
                'editor_url' => $first ? route('editor.slides.show', [$deck->id, $first->id], false) : null,
            ],
            'report' => $report,
            'summary' => [
                'unsupported' => count(array_filter($report, fn ($r) => $r['kind'] === 'unsupported')),
                'approximated' => count(array_filter($report, fn ($r) => $r['kind'] === 'approximated')),
            ],
        ], 201);
    }
}
