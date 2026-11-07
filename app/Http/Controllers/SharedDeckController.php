<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Deck;
use App\Models\Scopes\DeckAccessScope;
use App\Services\Press\DeckAccess;
use App\Services\Press\DeckDocument;
use App\Services\Press\DeferredPage;
use App\Services\Press\JsonStream;
use App\Services\Press\RangeStream;
use App\Services\Press\Sharing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The public, no-login view of a deck behind a share token. A guest sees the slides and their assets and nothing
 * else: never comments, versions, people, activity or speaker notes. A link that is wrong, switched off, rotated,
 * expired or whose deck is archived answers a plain 404, so nobody can tell a dead link from one that never was.
 */
class SharedDeckController extends Controller
{
    private const SESSION_KEY = 'press.share.unlocked';

    public function show(Request $request, string $token): InertiaResponse
    {
        $deck = $this->deck($token);
        $locked = $this->locked($request, $deck);

        $props = [
            'title' => $locked ? 'Protected presentation' : $deck->title,
            'needsPassword' => $locked,
            'error' => $request->session()->pull('share_error'),
            'token' => $token,
        ];

        // A big deck is fetched by the page from the streaming document route instead of being written into the HTML.
        if (! $locked && ! DeckDocument::isInlineSized($deck)) {
            return DeferredPage::render('Shared', $props + ['doc' => null], route('shared.document', ['token' => $token], absolute: false), 'doc');
        }

        return Inertia::render('Press/Shared', $props + ['doc' => $locked ? null : $this->buildDocument($deck, $token)]);
    }

    public function unlock(Request $request, string $token): RedirectResponse|JsonResponse
    {
        $deck = $this->deck($token);

        $data = $request->validate(['password' => ['required', 'string', 'max:200']]);

        $perVisitor = 'press-share-unlock:'.sha1($token).'|'.$request->ip();
        $perLink = 'press-share-unlock:'.sha1($token);

        if (RateLimiter::tooManyAttempts($perVisitor, 5) || RateLimiter::tooManyAttempts($perLink, 60)) {
            $wait = max(RateLimiter::availableIn($perVisitor), RateLimiter::availableIn($perLink));

            return $this->refuse($request, $token, 'Too many attempts. Try again in '.max(1, (int) ceil($wait / 60)).' minutes.', 429, $wait);
        }

        if ($deck->share_password !== null && ! Hash::check($data['password'], $deck->share_password)) {
            RateLimiter::hit($perVisitor, 900);
            RateLimiter::hit($perLink, 3600);

            return $this->refuse($request, $token, 'That password is not correct.', 422);
        }

        RateLimiter::clear($perVisitor);

        $unlocked = (array) $request->session()->get(self::SESSION_KEY, []);
        $request->session()->put(self::SESSION_KEY, array_values(array_unique([...$unlocked, Sharing::unlockMarker($deck)])));

        return $request->expectsJson()
            ? response()->json(['unlocked' => true])
            : redirect()->route('shared.show', ['token' => $token], 303);
    }

    public function document(Request $request, string $token): StreamedResponse
    {
        $deck = $this->deck($token);
        abort_if($this->locked($request, $deck), 403, 'This presentation needs its password.');

        // Streamed: the stored JSON of each slide is passed through, so a big deck costs a few MB of memory.
        $assets = function (array $ids) use ($deck, $token): array {
            $map = [];
            foreach (Asset::withoutGlobalScopes()->where('project_id', $deck->project_id)->whereIn('id', $ids)->get() as $asset) {
                $url = route('shared.asset', ['token' => $token, 'asset' => $asset->id]);
                $map[$asset->id] = ['url' => $url, 'thumb' => $asset->thumb_path ? $url.'?size=thumb' : null, 'w' => $asset->width, 'h' => $asset->height, 'mime' => $asset->mime_type, 'name' => $asset->original_name];
            }

            return $map;
        };

        return JsonStream::response($request, DeckDocument::chunks($deck, $assets, [
            'notes' => false, 'seq' => 0, 'deck_keys' => ['id', 'title', 'w', 'h', 'theme', 'sections', 'slide_order', 'updated_at'],
        ]));
    }

    public function asset(Request $request, string $token, int $asset): Response
    {
        $deck = $this->deck($token);
        abort_if($this->locked($request, $deck), 403, 'This presentation needs its password.');

        $model = Asset::withoutGlobalScopes()->where('project_id', $deck->project_id)->find($asset);

        // Only what a slide of this very deck uses is ever served, never the rest of the project's library.
        abort_unless($model && DeckAccess::deckUsesAsset($deck, $model->id), 404);

        $useThumb = $request->query('size') === 'thumb' && $model->thumb_path;
        $path = $useThumb ? $model->thumb_path : $model->path;
        $disk = Storage::disk($model->disk);

        abort_unless($disk->exists($path), 404);

        $mime = $useThumb ? ($disk->mimeType($path) ?: 'application/octet-stream') : ($model->mime_type ?: 'application/octet-stream');

        return RangeStream::response($request, $disk, $path, $mime, $model->original_name ?: 'asset', [
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }

    private function deck(string $token): Deck
    {
        $deck = Sharing::deckForToken($token);
        abort_if($deck === null, 404);

        return $deck;
    }

    private function locked(Request $request, Deck $deck): bool
    {
        if ($deck->share_password === null) {
            return false;
        }

        return ! in_array(Sharing::unlockMarker($deck), (array) $request->session()->get(self::SESSION_KEY, []), true);
    }

    private function refuse(Request $request, string $token, string $message, int $status, int $retryAfter = 0): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            $headers = $retryAfter > 0 ? ['Retry-After' => (string) $retryAfter] : [];

            return response()->json(['message' => $message, 'errors' => ['password' => [$message]]], $status, $headers);
        }

        return redirect()->route('shared.show', ['token' => $token], 303)->with('share_error', $message);
    }

    /**
     * The deck as a guest may read it: the document of spec section 9 without the change counter, status, review
     * state or speaker notes, plus an asset map whose urls point at this token's own asset route.
     *
     * @return array<string, mixed>
     */
    private function buildDocument(Deck $deck, string $token): array
    {
        $doc = DeckAccessScope::without(fn () => DeckDocument::toArray($deck));

        // No speaker notes, and a hidden slide is sent as an empty place holder: the owner hid it, so a guest must not be
        // able to read its content in the page data even though the player skips it.
        $slides = array_map(function (array $slide): array {
            $slide['notes'] = null;
            if (! empty($slide['hidden'])) {
                $slide['title'] = null;
                $slide['bg'] = null;
                $slide['els'] = [];
            }

            return $slide;
        }, $doc['slides']);

        $ids = DeckAccess::assetIdsOfDeck($deck);
        $assets = [];

        foreach (Asset::withoutGlobalScopes()->where('project_id', $deck->project_id)->whereIn('id', $ids)->get() as $asset) {
            $url = route('shared.asset', ['token' => $token, 'asset' => $asset->id]);
            $assets[$asset->id] = [
                'url' => $url,
                'thumb' => $asset->thumb_path ? $url.'?size=thumb' : null,
                'w' => $asset->width,
                'h' => $asset->height,
                'mime' => $asset->mime_type,
                'name' => $asset->original_name,
            ];
        }

        return [
            'deck' => collect($doc['deck'])->only(['id', 'title', 'w', 'h', 'theme', 'sections', 'slide_order', 'updated_at'])->all(),
            'slides' => $slides,
            'seq' => 0,
            'assets' => (object) $assets,
        ];
    }
}
