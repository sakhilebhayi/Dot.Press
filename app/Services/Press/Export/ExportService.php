<?php

namespace App\Services\Press\Export;

use App\Models\Deck;
use App\Models\DeckExport;
use App\Models\Project;
use App\Services\Press\DeckDocument;
use App\Services\Press\Export\Pdf\PdfExporter;
use App\Services\Press\Export\Pptx\PptxExporter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Runs one export: reads the document, renders PDF or PPTX, stores the file on the private disk (never a partial one)
 * and records the outcome on the DeckExport row. A failure leaves the deck untouched and a readable message on the row.
 */
final class ExportService
{
    public const FORMATS = ['pdf', 'pptx'];

    public const MIME = [
        'pdf' => 'application/pdf',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    /** Keeps only the options an export understands. @return array<string, mixed> */
    public static function cleanOptions(string $format, mixed $in): array
    {
        $in = is_array($in) ? $in : [];
        $out = [];
        if (array_key_exists('include_hidden', $in)) {
            $out['include_hidden'] = filter_var($in['include_hidden'], FILTER_VALIDATE_BOOLEAN);
        }
        if ($format === 'pdf') {
            if (array_key_exists('notes', $in)) {
                $out['notes'] = filter_var($in['notes'], FILTER_VALIDATE_BOOLEAN);
            }
            if (is_array($in['slides'] ?? null)) {
                $ids = [];
                foreach (array_slice($in['slides'], 0, 600) as $id) {
                    if ((is_string($id) || is_int($id)) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', (string) $id)) {
                        $ids[] = (string) $id;
                    }
                }
                if ($ids !== []) {
                    $out['slides'] = $ids;
                }
            }
        }

        return $out;
    }

    /** The document of a deck as the exporters read it. @return array{deck: array<string, mixed>, slides: list<array<string, mixed>>} */
    public static function document(Deck $deck): array
    {
        $doc = DeckDocument::toArray($deck);

        return ['deck' => $doc['deck'], 'slides' => $doc['slides']];
    }

    /** Assets of the deck's team only: a document naming a foreign asset id gets nothing. */
    public static function resolverFor(Deck $deck): AssetResolver
    {
        $teamId = $deck->project?->team_id ?? Project::withoutGlobalScopes()->whereKey($deck->project_id)->value('team_id');
        $projects = Project::withoutGlobalScopes()->where('team_id', $teamId)->pluck('id')->map(fn ($i) => (int) $i)->all();

        return new DatabaseAssetResolver($projects ?: [(int) $deck->project_id]);
    }

    /** True when the deck is small enough to be exported inside the request. */
    public static function isSmall(array $doc): bool
    {
        return count($doc['slides']) <= (int) config('press_export.fast_path_slides', 12) && ! ExportPreflight::hasVideo($doc);
    }

    public function run(DeckExport $export): void
    {
        $export->forceFill(['status' => 'running', 'started_at' => now(), 'attempts' => $export->attempts + 1, 'error' => null])->save();
        $disk = (string) config('press_export.export_disk', 'local');
        $path = null;
        $tmp = null;
        try {
            $this->raiseMemory();
            $deck = Deck::withoutGlobalScopes()->with('project')->find($export->deck_id);
            if ($deck === null) {
                throw new RuntimeException('The deck no longer exists.');
            }
            $doc = self::document($deck);
            if (count($doc['slides']) > (int) config('press_export.max_export_slides', 600)) {
                throw new RuntimeException('This deck has too many slides to export.');
            }
            $format = $export->format;
            $options = self::cleanOptions($format, $export->options);
            $resolver = self::resolverFor($deck);
            $ext = $format === 'pptx' ? 'pptx' : 'pdf';
            $path = trim((string) config('press_export.export_dir', 'press/exports'), '/').'/'.$export->id.'-'.Str::lower(Str::random(20)).'.'.$ext;
            $storage = Storage::disk($disk);
            $part = $path.'.part';

            if ($format === 'pdf') {
                $bytes = (new PdfExporter($resolver))->render($doc, $options);
                $storage->put($part, $bytes);
                $size = strlen($bytes);
                unset($bytes);
            } else {
                $dir = storage_path('app/press/tmp');
                if (! is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
                $tmp = $dir.'/'.Str::uuid().'.pptx';
                (new PptxExporter($resolver))->render($doc, $tmp, $options);
                $size = (int) filesize($tmp);
                $stream = fopen($tmp, 'rb');
                $storage->put($part, $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            if (! $storage->exists($part) || $storage->size($part) < 4) {
                throw new RuntimeException('The export file could not be written.');
            }
            $storage->move($part, $path);
            $export->forceFill([
                'status' => 'done', 'disk' => $disk, 'path' => $path, 'size' => $size, 'error' => null,
                'finished_at' => now(), 'expires_at' => now()->addHours((int) config('press_export.export_ttl_hours', 24)),
            ])->save();
        } catch (Throwable $e) {
            if ($path !== null) {
                try {
                    Storage::disk($disk)->delete([$path, $path.'.part']);
                } catch (Throwable) {
                    // nothing more to clean
                }
            }
            $this->fail($export, $e);
        } finally {
            if ($tmp !== null) {
                @unlink($tmp);
                @unlink($tmp.'.part');
            }
        }
    }

    public function fail(DeckExport $export, Throwable $e): void
    {
        Log::warning('Dot.Press export failed', ['export' => $export->id, 'deck' => $export->deck_id, 'format' => $export->format, 'exception' => $e::class, 'message' => $e->getMessage()]);
        $message = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'The export could not be finished. Try again, or try the other format.';
        $export->forceFill(['status' => 'failed', 'error' => mb_substr($message, 0, 500), 'finished_at' => now(), 'path' => null, 'size' => 0])->save();
    }

    private function raiseMemory(): void
    {
        $limit = ini_get('memory_limit');
        if ($limit === false || $limit === '-1') {
            return;
        }
        $bytes = self::bytes($limit);
        if ($bytes > 0 && $bytes < 512 * 1048576) {
            @ini_set('memory_limit', '512M');
        }
        @set_time_limit((int) config('press_export.job_timeout', 280));
    }

    private static function bytes(string $v): int
    {
        $v = trim($v);
        $n = (int) $v;
        $u = strtolower(substr($v, -1));

        return match ($u) {
            'g' => $n * 1073741824,
            'm' => $n * 1048576,
            'k' => $n * 1024,
            default => $n,
        };
    }

    /** A file name for the download: the deck title as a safe slug. */
    public static function downloadName(Deck $deck, string $format): string
    {
        $slug = Str::slug(Str::limit((string) $deck->title, 80, ''), '-');

        return ($slug !== '' ? $slug : 'presentation').'.'.($format === 'pptx' ? 'pptx' : 'pdf');
    }
}
