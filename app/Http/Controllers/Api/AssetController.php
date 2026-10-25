<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AssetController extends Controller
{
    /**
     * No explicit user_id filter needed: Asset's HasUserScopeThroughOwner
     * trait applies it automatically to every query against this model.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Asset::class);

        $validated = $request->validate([
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
        ]);

        $query = Asset::query()->with('project');

        if (isset($validated['project_id'])) {
            $project = Project::findOrFail($validated['project_id']);
            $this->authorize('view', $project);
            $query->where('project_id', $project->id);
        }

        $paginator = $query->latest('id')->paginate(15);

        $paginator->getCollection()->transform(function (Asset $asset): array {
            return [
                'id' => $asset->id,
                'project_id' => $asset->project_id,
                'uploaded_by' => $asset->uploaded_by,
                'disk' => $asset->disk,
                'path' => $asset->path,
                'original_name' => $asset->original_name,
                'mime_type' => $asset->mime_type,
                'size' => $asset->size,
                'metadata' => $asset->metadata,
                'created_at' => $asset->created_at,
                'updated_at' => $asset->updated_at,
                'delivery_url' => $this->deliveryUrl($asset),
            ];
        });

        return $paginator;
    }

    public function store(Request $request)
    {
        $this->authorize('create', Asset::class);

        $payload = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'file' => [
                'required',
                'file',
                'max:51200',
                'mimes:jpg,jpeg,png,gif,webp,svg,mp4,mov,webm,mp3,wav,ogg,pdf',
            ],
        ]);

        $project = Project::findOrFail($payload['project_id']);
        $this->authorize('create', [Asset::class, $project]);

        $disk = $this->resolveUploadDisk();
        $file = $request->file('file');
        $path = $file->store("projects/{$project->id}/assets", $disk);

        $asset = Asset::create([
            'project_id' => $project->id,
            'uploaded_by' => $request->user()->id,
            'disk' => $disk,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'metadata' => [
                'extension' => $file->getClientOriginalExtension(),
            ],
        ]);

        return response()->json([
            'asset' => $asset,
            'delivery_url' => $this->deliveryUrl($asset),
            'expires_in_seconds' => config('filesystems.asset_signed_url_ttl', 10) * 60,
        ], 201);
    }

    public function show(Asset $asset)
    {
        $this->authorize('view', $asset);

        return [
            'asset' => $asset,
            'delivery_url' => $this->deliveryUrl($asset),
            'expires_in_seconds' => config('filesystems.asset_signed_url_ttl', 10) * 60,
        ];
    }

    public function destroy(Asset $asset)
    {
        $this->authorize('delete', $asset);

        Storage::disk($asset->disk)->delete($asset->path);
        $asset->delete();

        return response()->noContent();
    }

    public function download(Request $request, Asset $asset): StreamedResponse
    {
        $this->authorize('view', $asset);

        $disk = Storage::disk($asset->disk);

        abort_unless($disk->exists($asset->path), 404);

        return $disk->download($asset->path, $asset->original_name);
    }

    private function resolveUploadDisk(): string
    {
        $configuredDisk = config('filesystems.asset_upload_disk', 'local');
        $disks = array_keys(config('filesystems.disks', []));

        if (in_array($configuredDisk, $disks, true)) {
            return $configuredDisk;
        }

        return (string) config('filesystems.default', 'local');
    }

    private function deliveryUrl(Asset $asset): string
    {
        $ttl = now()->addMinutes((int) config('filesystems.asset_signed_url_ttl', 10));

        $driver = config("filesystems.disks.{$asset->disk}.driver");

        if ($driver !== 's3') {
            return URL::temporarySignedRoute('assets.download', $ttl, [
                'asset' => $asset->id,
            ]);
        }

        try {
            return Storage::disk($asset->disk)->temporaryUrl($asset->path, $ttl);
        } catch (Throwable) {
            return URL::temporarySignedRoute('assets.download', $ttl, [
                'asset' => $asset->id,
            ]);
        }
    }
}
