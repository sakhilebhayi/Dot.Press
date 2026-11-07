<?php

namespace App\Services\Press\Assets;

use App\Models\Asset;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The one door every file goes through on its way into a project's asset library.
 *
 *   AssetStore::storeUpload($project, $user, $uploadedFile);
 *   AssetStore::storeBytes($project, $user, $bytes, 'logo.svg');
 *
 * Both detect the real type from the content, enforce the limits, sanitise or re-encode, write the files and
 * return the Asset row. A file already in the project (same SHA-256) returns the existing row, which is then not
 * freshly created ($asset->wasRecentlyCreated is false). Authorisation is the caller's job.
 *
 * Throws AssetRejected, with a message fit for the end user, for anything that is refused.
 */
class AssetStore
{
    public function __construct(private ImageProcessor $images, private SvgSanitizer $svg) {}

    public static function storeUpload(Project $project, User $user, UploadedFile $file): Asset
    {
        if (! $file->isValid()) {
            throw new AssetRejected(self::uploadErrorMessage($file->getError()));
        }

        return app(self::class)->ingest($project, $user, (string) $file->getRealPath(), $file->getClientOriginalName());
    }

    /**
     * @param  ?string  $mime  The caller's idea of the type. Ignored on purpose: the content decides.
     */
    public static function storeBytes(Project $project, User $user, string $bytes, string $name, ?string $mime = null): Asset
    {
        $temporary = tempnam(sys_get_temp_dir(), 'press-asset-');
        if ($temporary === false || file_put_contents($temporary, $bytes) === false) {
            throw new RuntimeException('A temporary file could not be written.');
        }

        try {
            return app(self::class)->ingest($project, $user, $temporary, $name);
        } finally {
            @unlink($temporary);
        }
    }

    /**
     * Stores a browser captured frame as the poster of a video asset and links it in the video's metadata.
     * A previous poster of the same video is removed.
     */
    public function storePoster(Project $project, User $user, Asset $video, UploadedFile $frame): Asset
    {
        if ($video->kind !== 'video') {
            throw new AssetRejected('Only video assets can have a poster.');
        }
        if (! $frame->isValid()) {
            throw new AssetRejected(self::uploadErrorMessage($frame->getError()));
        }

        $poster = $this->ingest($project, $user, (string) $frame->getRealPath(), 'Poster of '.$video->original_name, poster: true);

        $previousId = $video->metadata['poster_asset_id'] ?? null;
        $video->metadata = array_merge($video->metadata ?? [], ['poster_asset_id' => $poster->id]);
        $video->save();

        if ($previousId && (int) $previousId !== $poster->id) {
            $previous = Asset::withoutGlobalScopes()->find($previousId);
            if ($previous !== null && $previous->kind === 'poster') {
                self::purge($previous);
            }
        }

        return $poster;
    }

    /** Removes the files and the row, and the poster that belongs to a video. Does not check usage. */
    public static function purge(Asset $asset): void
    {
        $posterId = $asset->kind === 'video' ? ($asset->metadata['poster_asset_id'] ?? null) : null;

        self::deleteFiles($asset);
        $asset->delete();

        if ($posterId) {
            $poster = Asset::withoutGlobalScopes()->find($posterId);
            if ($poster !== null && $poster->kind === 'poster') {
                self::deleteFiles($poster);
                $poster->delete();
            }
        }

        if ($asset->kind === 'poster') {
            foreach (Asset::withoutGlobalScopes()->where('project_id', $asset->project_id)->where('kind', 'video')->get() as $video) {
                if (($video->metadata['poster_asset_id'] ?? null) === $asset->id) {
                    $metadata = $video->metadata;
                    unset($metadata['poster_asset_id']);
                    $video->metadata = $metadata;
                    $video->save();
                }
            }
        }
    }

    public static function deleteFiles(Asset $asset): void
    {
        $paths = array_values(array_filter([$asset->path, $asset->web_path, $asset->thumb_path]));

        if ($paths !== []) {
            Storage::disk($asset->disk)->delete($paths);
        }
    }

    public static function uploadDisk(): string
    {
        $configured = config('filesystems.asset_upload_disk', 'local');

        return array_key_exists($configured, config('filesystems.disks', []))
            ? $configured
            : (string) config('filesystems.default', 'local');
    }

    public static function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => AssetLimits::serverLimitMessage(),
            UPLOAD_ERR_PARTIAL => 'The upload was interrupted before it finished. Try again.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server has no temporary folder for uploads. Ask the administrator to fix it.',
            default => 'The server could not save the upload. Try again, and ask the administrator if it keeps failing.',
        };
    }

    public function ingest(Project $project, User $user, string $path, string $clientName, bool $poster = false): Asset
    {
        $size = @filesize($path);
        if ($size === false || ! is_file($path)) {
            throw new AssetRejected('The uploaded file could not be read.');
        }

        $type = MediaSniffer::detect($path);

        if ($poster && ! in_array($type['format'], ['jpeg', 'png'], true)) {
            throw new AssetRejected('A poster frame must be a JPEG or PNG image.');
        }

        $label = $poster ? 'poster' : $type['kind'];
        $limit = $type['format'] === 'svg' ? AssetLimits::SVG_BYTES : AssetLimits::bytesFor($label);
        if ($size > $limit) {
            $name = match ($label) {
                'video' => 'Video files',
                'audio' => 'Audio files',
                'poster' => 'Poster frames',
                default => $type['format'] === 'svg' ? 'SVG files' : 'Images',
            };

            throw new AssetRejected("{$name} can be up to ".AssetLimits::human($limit).'. This file is '.AssetLimits::human((int) $size).'.');
        }

        MediaSniffer::assertComplete($path, $type['format']);

        $sha256 = hash_file('sha256', $path);

        $existing = $this->findDuplicate($project, $sha256, $poster);
        if ($existing !== null) {
            return $existing;
        }

        $files = $this->prepare($path, $type);
        $disk = self::uploadDisk();
        $storage = Storage::disk($disk);
        $stem = "projects/{$project->id}/assets/".strtolower((string) Str::ulid());
        $written = [];

        try {
            $paths = ['path' => "{$stem}.{$files['orig']['ext']}", 'web_path' => null, 'thumb_path' => null];
            $this->write($storage, $paths['path'], $files['orig'], $path, $written);

            if (($files['web'] ?? null) !== null) {
                $paths['web_path'] = "{$stem}.web.{$files['web']['ext']}";
                $this->write($storage, $paths['web_path'], $files['web'], null, $written);
            }
            if (($files['thumb'] ?? null) !== null) {
                $paths['thumb_path'] = "{$stem}.thumb.{$files['thumb']['ext']}";
                $this->write($storage, $paths['thumb_path'], $files['thumb'], null, $written);
            }

            return DB::transaction(fn (): Asset => Asset::create([
                'project_id' => $project->id,
                'uploaded_by' => $user->id,
                'disk' => $disk,
                'path' => $paths['path'],
                'web_path' => $paths['web_path'],
                'thumb_path' => $paths['thumb_path'],
                'original_name' => $this->cleanName($clientName),
                'mime_type' => $files['orig']['mime'],
                'size' => $files['orig']['size'],
                'kind' => $poster ? 'poster' : $type['kind'],
                'width' => $files['width'] ?? null,
                'height' => $files['height'] ?? null,
                'sha256' => $sha256,
                'metadata' => array_filter([
                    'extension' => $files['orig']['ext'],
                    'format' => $type['format'],
                    'animated' => ($files['animated'] ?? false) ?: null,
                    'upload_bytes' => (int) $size,
                ], fn ($value): bool => $value !== null),
            ]));
        } catch (Throwable $e) {
            if ($written !== []) {
                $storage->delete($written);
            }

            throw $e;
        }
    }

    /**
     * @param  array{kind: string, format: string, mime: string, ext: string}  $type
     * @return array{orig: array{ext: string, mime: string, size: int, bytes?: string}, web?: ?array{bytes: string, ext: string, mime: string}, thumb?: ?array{bytes: string, ext: string, mime: string}, width?: int, height?: int, animated?: bool}
     */
    private function prepare(string $path, array $type): array
    {
        if ($type['kind'] !== 'image') {
            return ['orig' => ['ext' => $type['ext'], 'mime' => $type['mime'], 'size' => (int) filesize($path)]];
        }

        if ($type['format'] === 'svg') {
            $clean = $this->svg->sanitise((string) file_get_contents($path));

            return [
                'orig' => ['bytes' => $clean['svg'], 'ext' => 'svg', 'mime' => 'image/svg+xml', 'size' => strlen($clean['svg'])],
                'width' => $clean['width'],
                'height' => $clean['height'],
            ];
        }

        $processed = $this->images->process($path, $type['format']);

        return [
            'orig' => $processed['orig'] + ['size' => strlen($processed['orig']['bytes'])],
            'web' => $processed['web'],
            'thumb' => $processed['thumb'],
            'width' => $processed['width'],
            'height' => $processed['height'],
            'animated' => $processed['animated'],
        ];
    }

    /**
     * @param  array{bytes?: string}  $file
     * @param  list<string>  $written
     */
    private function write(Filesystem $storage, string $target, array $file, ?string $source, array &$written): void
    {
        if (isset($file['bytes'])) {
            $ok = $storage->put($target, $file['bytes']);
        } else {
            $stream = fopen((string) $source, 'rb');
            $ok = $stream !== false && $storage->writeStream($target, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! $ok) {
            throw new RuntimeException('The file could not be written to storage.');
        }

        $written[] = $target;
    }

    private function findDuplicate(Project $project, string $sha256, bool $poster): ?Asset
    {
        $query = Asset::withoutGlobalScopes()->where('project_id', $project->id)->where('sha256', $sha256);
        $poster ? $query->where('kind', 'poster') : $query->where('kind', '!=', 'poster');

        $existing = $query->orderBy('id')->first();

        if ($existing === null) {
            return null;
        }

        if (! Storage::disk($existing->disk)->exists($existing->path)) {
            self::purge($existing);

            return null;
        }

        return $existing;
    }

    private function cleanName(string $name): string
    {
        $name = str_replace(['\\', '/'], '/', $name);
        $name = basename($name);
        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name);
        $name = trim($name);

        return $name === '' ? 'upload' : mb_substr($name, 0, 200);
    }
}
