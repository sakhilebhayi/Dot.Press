<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Deck;
use App\Models\Scopes\DeckAccessScope;
use App\Services\Press\DeckAccess;
use App\Services\Press\DeckDocument;
use App\Services\Press\PresentPayload;
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

        return Inertia::render('Press/Shared', [
            'title' => $locked ? 'Protected presentation' : $deck->title,
            'needsPassword' => $locked,
            'error' => $request->session()->pull('share_error'),
            'doc' => $locked ? null : $this->buildDocument($deck, $token),
            'allowDownload' => (bool) $deck->share_allow_download,
            'token' => $token,
        ]);
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

    public function document(Request $request, string $token): JsonResponse
    {
        $deck = $this->deck($token);
        abort_if($this->locked($request, $deck), 403, 'This presentation needs its password.');

        return response()->json($this->buildDocument($deck, $token));
    }

    /**
     * The print view for a link visitor, only when the owner allowed downloads. It is the same page as the signed in
     * print view, fed from the same guest safe data as the player: no speaker notes, no hidden slides, no comments.
     * The pictures come through this token's own asset route. A visitor who has not opened a protected link yet is
     * sent back to the password page.
     */
    public function print(Request $request, string $token): InertiaResponse|RedirectResponse
    {
        $deck = $this->deck($token);
        abort_unless($deck->share_allow_download, 404);

        if ($this->locked($request, $deck)) {
            return redirect()->route('shared.show', ['token' => $token], 303);
        }

        $slides = array_values(array_filter(
            PresentPayload::slides($deck, notes: false),
            fn (array $slide): bool => ! $slide['hidden'],
        ));

        return Inertia::render('Press/Print', [
            'deck' => PresentPayload::deckHeader($deck),
            'slides' => $slides,
            'assets' => $this->assetMap($deck, $token, $this->guestAssetIds($deck)),
            'options' => ['hidden' => false, 'notes' => false],
            'backUrl' => route('shared.show', ['token' => $token], false),
            'backLabel' => 'Back to the presentation',
        ]);
    }

    public function asset(Request $request, string $token, int $asset): Response
    {
        $deck = $this->deck($token);
        abort_if($this->locked($request, $deck), 403, 'This presentation needs its password.');

        $model = Asset::withoutGlobalScopes()->where('project_id', $deck->project_id)->find($asset);

        // Only what a slide of this very deck uses is ever served, never the rest of the project's library.
        // A picture that only a hidden slide uses is not served either: hidden means hidden for a guest.
        abort_unless($model && in_array($model->id, $this->guestAssetIds($deck), true), 404);

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
        $slides = array_values($slides);

        return [
            'deck' => collect($doc['deck'])->only(['id', 'title', 'w', 'h', 'theme', 'sections', 'slide_order', 'updated_at'])->all(),
            'slides' => $slides,
            'seq' => 0,
            'assets' => $this->assetMap($deck, $token, $this->guestAssetIds($deck)),
        ];
    }

    /**
     * Asset ids a guest may fetch: what the visible slides and the theme use.
     *
     * @return list<int>
     */
    private function guestAssetIds(Deck $deck): array
    {
        $slides = array_filter(PresentPayload::slides($deck, notes: false), fn (array $slide): bool => ! $slide['hidden']);

        return array_values(array_unique([...PresentPayload::assetIds($slides), ...DeckAccess::assetIdsIn($deck->theme ?? [])]));
    }

    /**
     * The asset map of spec section 9 for a link visitor: urls on this token's own asset route.
     *
     * @param  list<int>  $ids
     * @return array<string, array<string, mixed>>|object an empty map is an object so it encodes as {}
     */
    private function assetMap(Deck $deck, string $token, array $ids): array|object
    {
        $assets = [];

        foreach (Asset::withoutGlobalScopes()->where('project_id', $deck->project_id)->whereIn('id', $ids)->get() as $asset) {
            $url = route('shared.asset', ['token' => $token, 'asset' => $asset->id]);
            $assets[(string) $asset->id] = [
                'url' => $url,
                'thumb' => $asset->thumb_path ? $url.'?size=thumb' : null,
                'w' => $asset->width,
                'h' => $asset->height,
                'mime' => $asset->mime_type,
                'name' => $asset->original_name,
            ];
        }

        return $assets === [] ? (object) [] : $assets;
    }
}
