<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Press\Data\DataImportException;
use App\Services\Press\Data\DataParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/data/parse: read a csv, tsv, txt or xlsx file into rows for a chart or table. The upload
 * is parsed from its temporary path and not stored. Limits: config/press-ai.php (5 MB, 5000 rows, 100 columns).
 */
class DataParseController extends Controller
{
    public function __invoke(Request $request, DataParser $parser): JsonResponse
    {
        $maxKb = (int) ceil((int) config('press-ai.data.max_bytes', 5 * 1024 * 1024) / 1024);
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:'.$maxKb, 'extensions:csv,tsv,txt,xlsx'],
            'sheet' => ['nullable', 'string', 'max:120'],
        ]);

        $file = $validated['file'];

        try {
            $result = $parser->parseFile($file->getRealPath(), $file->getClientOriginalName(), $validated['sheet'] ?? null);
        } catch (DataImportException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 422);
        }

        return response()->json($result);
    }
}
