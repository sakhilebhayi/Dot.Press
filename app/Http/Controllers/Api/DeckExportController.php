<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunDeckExport;
use App\Models\Deck;
use App\Models\DeckExport;
use App\Services\Press\Export\ExportPreflight;
use App\Services\Press\Export\ExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeckExportController extends Controller
{
    /** Most exports one person may have waiting or running at the same time. */
    private const MAX_ACTIVE = 3;

    /** What an export looks like to the client. @return array<string, mixed> */
    public static function present(DeckExport $e): array
    {
        $live = $e->status === 'done' && ($e->expires_at === null || $e->expires_at->isFuture()) && $e->path !== null;

        return [
            'id' => $e->id,
            'deck_id' => $e->deck_id,
            'format' => $e->format,
            'status' => $e->status,
            'options' => $e->options ?: new \stdClass,
            'size' => (int) $e->size,
            'error' => $e->error,
            'created_at' => optional($e->created_at)->toIso8601String(),
            'finished_at' => optional($e->finished_at)->toIso8601String(),
            'expires_at' => optional($e->expires_at)->toIso8601String(),
            'download_url' => $live ? route('press.exports.download', ['export' => $e->id], false) : null,
        ];
    }

    /** POST /api/decks/{deck}/exports */
    public function store(Request $request, Deck $deck): JsonResponse
    {
        $this->authorize('view', $deck);
        $data = $request->validate([
            'format' => ['required', Rule::in(ExportService::FORMATS)],
            'options' => ['nullable', 'array'],
            'options.include_hidden' => ['nullable', 'boolean'],
            'options.notes' => ['nullable', 'boolean'],
            'options.slides' => ['nullable', 'array', 'max:600'],
            'options.slides.*' => ['string', 'regex:/^[A-Za-z0-9_-]{1,40}$/'],
        ]);
        $user = $request->user();

        $active = DeckExport::where('user_id', $user->id)->whereIn('status', ['queued', 'running'])->where('updated_at', '>', now()->subHour())->count();
        if ($active >= self::MAX_ACTIVE) {
            return response()->json(['message' => 'You already have exports in progress. Wait for one to finish.'], 429);
        }

        $doc = ExportService::document($deck);
        if (count($doc['slides']) > (int) config('press_export.max_export_slides', 600)) {
            return response()->json(['message' => 'This deck has too many slides to export.'], 422);
        }
        $export = DeckExport::create([
            'deck_id' => $deck->id,
            'user_id' => $user->id,
            'format' => $data['format'],
            'status' => 'queued',
            'options' => ExportService::cleanOptions($data['format'], $data['options'] ?? []),
            'attempts' => 0,
        ]);

        if (ExportService::isSmall($doc)) {
            // Small decks are done before the response, so the page does not wait for the scheduler.
            app(ExportService::class)->run($export);
            $export->refresh();

            return response()->json(self::present($export), $export->status === 'failed' ? 200 : 201);
        }
        RunDeckExport::dispatch($export->id);

        return response()->json(self::present($export), 202);
    }

    /** GET /api/decks/{deck}/exports */
    public function index(Deck $deck): JsonResponse
    {
        $this->authorize('view', $deck);
        $rows = DeckExport::where('deck_id', $deck->id)->latest('id')->limit(10)->get();

        return response()->json(['data' => $rows->map(fn ($e) => self::present($e))->all()]);
    }

    /** GET /api/exports/{export} */
    public function show(Request $request, DeckExport $export): JsonResponse
    {
        $this->mayView($request, $export);

        return response()->json(self::present($export));
    }

    /** GET /api/exports/{export}/download */
    public function download(Request $request, DeckExport $export): StreamedResponse|JsonResponse
    {
        $deck = $this->mayView($request, $export);
        if ($export->status !== 'done' || $export->path === null) {
            return response()->json(['message' => 'This export is not ready.'], 409);
        }
        if ($export->expires_at !== null && $export->expires_at->isPast()) {
            return response()->json(['message' => 'This export has expired. Create a new one.'], 410);
        }
        $disk = Storage::disk($export->disk ?: (string) config('press_export.export_disk', 'local'));
        if (! $disk->exists($export->path)) {
            return response()->json(['message' => 'This export file is no longer available. Create a new one.'], 410);
        }
        $name = ExportService::downloadName($deck, $export->format);

        return $disk->download($export->path, $name, [
            'Content-Type' => ExportService::MIME[$export->format] ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** POST /api/decks/{deck}/exports/preflight */
    public function preflight(Request $request, Deck $deck): JsonResponse
    {
        $this->authorize('view', $deck);
        $data = $request->validate([
            'format' => ['required', Rule::in(ExportService::FORMATS)],
            'options' => ['nullable', 'array'],
            'options.include_hidden' => ['nullable', 'boolean'],
        ]);
        $doc = ExportService::document($deck);
        $options = ExportService::cleanOptions($data['format'], $data['options'] ?? []);
        $resolver = ExportService::resolverFor($deck);

        return response()->json(ExportPreflight::analyze($doc, $data['format'], $options, $resolver));
    }

    /** The deck of an export the user may see, or a 404 (a stranger learns nothing about other people's exports). */
    private function mayView(Request $request, DeckExport $export): Deck
    {
        $deck = Deck::with('project')->find($export->deck_id);
        abort_if($deck === null || ! Gate::forUser($request->user())->allows('view', $deck), 404);

        return $deck;
    }
}
