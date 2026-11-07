<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Deck;
use App\Models\PresentSession;
use App\Models\Slide;
use App\Services\Press\PresentPackage;
use App\Services\Press\PresentPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Presenting a deck.
 *
 * Signed-in pages: the full screen player, the presenter console and the audience window (a second window of the
 * same browser). Present sessions: a presenter starts a live session and gets two links, an audience link (slides
 * only) and a remote link (a phone used as a clicker, which also shows the speaker notes). Everything live is short
 * polling: the presenter console pushes its position and picks up remote commands in the same request about once a
 * second, followers poll the state with ETag support. There are no websockets on this hosting.
 *
 * What a session exposes: the slide index, the step, the screen cover and the slide count. A follower's page carries
 * the slides (never notes, and hidden slides without content). Notes, the slide title and the next title reach only
 * a caller that holds the session's remote key.
 */
class PresentSessionController extends Controller
{
    public const TTL_HOURS = 12;

    public const MAX_QUEUE = 20;

    public const ACTIONS = ['next', 'prev', 'first', 'last', 'blackout', 'whiteout'];

    // ---- signed-in pages -------------------------------------------------------------------------------------

    public function player(Request $request, Deck $deck)
    {
        $this->authorize('view', $deck);

        return Inertia::render('Press/Player', $this->pageProps($request, $deck, notes: true) + [
            'mode' => 'present',
            'exitUrl' => $this->exitUrl($request),
        ]);
    }

    public function presenter(Request $request, Deck $deck)
    {
        $this->authorize('view', $deck);

        return Inertia::render('Press/Presenter', $this->pageProps($request, $deck, notes: true) + [
            'exitUrl' => $this->exitUrl($request),
            'audienceUrl' => route('press.audience', $deck, absolute: false),
        ]);
    }

    /** The second window on another display. It never receives speaker notes. */
    public function audienceWindow(Request $request, Deck $deck)
    {
        $this->authorize('view', $deck);

        $key = (string) $request->query('c', '');

        return Inertia::render('Press/Audience', $this->pageProps($request, $deck, notes: false) + [
            'follow' => null,
            'channelKey' => preg_match('/^[a-z0-9]{6,24}$/', $key) === 1 ? $key : null,
        ]);
    }

    // ---- sessions (signed-in, API) ---------------------------------------------------------------------------

    public function store(Request $request, Deck $deck): JsonResponse
    {
        $this->authorize('view', $deck);

        $data = $request->validate([
            'slide_index' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'step' => ['nullable', 'integer', 'min:0', 'max:5000'],
        ]);

        // One live session per person and deck: starting a new one ends the old one. Old rows are dropped.
        PresentSession::where('user_id', $request->user()->id)->where('deck_id', $deck->id)->whereNull('ended_at')->update(['ended_at' => now()]);
        PresentSession::where('user_id', $request->user()->id)->where('created_at', '<', now()->subDay())->delete();

        $remoteKey = Str::random(32);
        $session = PresentSession::create([
            'deck_id' => $deck->id,
            'user_id' => $request->user()->id,
            'token' => $this->newToken(),
            'slide_index' => (int) ($data['slide_index'] ?? 0),
            'step' => (int) ($data['step'] ?? 0),
            'state' => ['rev' => 1, 'cover' => null, 'remote_key' => $remoteKey],
            'command' => ['queue' => []],
            'last_seen' => now(),
        ]);

        return response()->json([
            'token' => $session->token,
            'audience_url' => route('press.live', $session->token),
            'remote_url' => route('press.remote', $session->token).'?k='.$remoteKey,
            'expires_at' => $this->expiresAt($session)->toIso8601String(),
        ], 201);
    }

    /** The presenter console pushes its position and receives the remote commands queued since last time. */
    public function update(Request $request, string $token): JsonResponse
    {
        $session = $this->ownSession($request, $token);

        $data = $request->validate([
            'slide_index' => ['required', 'integer', 'min:0', 'max:5000'],
            'step' => ['required', 'integer', 'min:0', 'max:5000'],
            'cover' => ['nullable', 'string', 'in:black,white'],
        ]);

        $commands = DB::transaction(function () use ($session, $data): array {
            $row = PresentSession::whereKey($session->id)->lockForUpdate()->first();
            $state = $row->state ?? [];
            $cover = $data['cover'] ?? null;
            $changed = (int) $row->slide_index !== (int) $data['slide_index']
                || (int) $row->step !== (int) $data['step']
                || ($state['cover'] ?? null) !== $cover;
            if ($changed) {
                $state['rev'] = (int) ($state['rev'] ?? 0) + 1;
            }
            $state['cover'] = $cover;
            $queue = $row->command['queue'] ?? [];
            $row->forceFill([
                'slide_index' => (int) $data['slide_index'],
                'step' => (int) $data['step'],
                'state' => $state,
                'command' => ['queue' => []],
                'last_seen' => now(),
            ])->save();

            return $queue;
        });

        return response()->json(['commands' => array_values($commands), 'expires_at' => $this->expiresAt($session)->toIso8601String()]);
    }

    public function destroy(Request $request, string $token): Response
    {
        $session = PresentSession::where('token', $token)->where('user_id', $request->user()->id)->first();
        abort_if($session === null, 404);
        $session->forceFill(['ended_at' => $session->ended_at ?? now()])->save();

        return response()->noContent();
    }

    // ---- public: followers and the phone remote ----------------------------------------------------------------

    /** The audience link. */
    public function follow(Request $request, string $token)
    {
        $session = $this->liveSession($token);
        $deck = $this->deckOf($session);
        $slides = PresentPayload::slides($deck, notes: false, blankHidden: true);
        $expires = $this->expiresAt($session);

        return Inertia::render('Press/Audience', [
            'deck' => PresentPayload::deckHeader($deck),
            'slides' => $slides,
            'assets' => PresentPayload::assetMap($deck, $slides, $expires),
            'follow' => ['token' => $session->token],
            'channelKey' => null,
        ]);
    }

    /** The phone remote page. It needs the remote key from the link; without it the page says so. */
    public function remote(Request $request, string $token)
    {
        $session = $this->liveSession($token);
        $deck = $this->deckOf($session);

        return Inertia::render('Press/Remote', [
            'token' => $session->token,
            'deckTitle' => (string) $deck->title,
            'count' => Slide::withoutGlobalScopes()->where('deck_id', $deck->id)->count(),
        ]);
    }

    public function state(Request $request, string $token): SymfonyResponse
    {
        $session = PresentSession::where('token', $token)->first();
        abort_if($session === null, 404);
        if (! $this->isLive($session)) {
            return response()->json(['ended' => true], 410, ['Cache-Control' => 'no-store']);
        }

        $count = $this->slideCount($session);
        $body = [
            'slide' => $count > 0 ? min((int) $session->slide_index, $count - 1) : 0,
            'step' => (int) $session->step,
            'cover' => $session->state['cover'] ?? null,
            'ended' => false,
            'rev' => (int) ($session->state['rev'] ?? 1),
            'count' => $count,
        ];

        $key = (string) $request->query('k', '');
        $remoteKey = (string) ($session->state['remote_key'] ?? '');
        if ($key !== '' && $remoteKey !== '' && hash_equals($remoteKey, $key)) {
            $slides = $this->orderedSlides($session);
            $current = $slides[$body['slide']] ?? null;
            $next = $slides[$body['slide'] + 1] ?? null;
            $body['title'] = (string) ($current->title ?? '');
            $body['notes'] = (string) ($current->notes ?? '');
            $body['next_title'] = $next ? (string) $next->title : '';
        }

        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $etag = '"'.md5($json).'"';
        $headers = ['ETag' => $etag, 'Cache-Control' => 'no-cache, private', 'Content-Type' => 'application/json'];
        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response('', 304, $headers);
        }

        return response($json, 200, $headers);
    }

    public function command(Request $request, string $token): JsonResponse
    {
        $session = PresentSession::where('token', $token)->first();
        abort_if($session === null, 404);
        if (! $this->isLive($session)) {
            return response()->json(['ended' => true], 410);
        }

        $data = $request->validate([
            'action' => ['required', 'string', 'in:'.implode(',', self::ACTIONS)],
            'k' => ['required', 'string', 'max:64'],
        ]);
        $remoteKey = (string) ($session->state['remote_key'] ?? '');
        abort_unless($remoteKey !== '' && hash_equals($remoteKey, $data['k']), 403, 'This remote link is not valid.');

        DB::transaction(function () use ($session, $data): void {
            $row = PresentSession::whereKey($session->id)->lockForUpdate()->first();
            $queue = $row->command['queue'] ?? [];
            $queue[] = ['id' => (string) Str::uuid(), 'action' => $data['action']];
            $queue = array_slice($queue, -self::MAX_QUEUE);
            $row->forceFill(['command' => ['queue' => $queue]])->save();
        });

        return response()->json(['queued' => true], 202);
    }

    /** A signed asset URL (see PresentPayload::assetMap). No session, no cookie: the signature is the permission. */
    public function asset(Request $request, int $asset): SymfonyResponse
    {
        $asset = Asset::withoutGlobalScopes()->findOrFail($asset);
        $variant = (string) $request->query('v', 'web');
        $path = match ($variant) {
            'thumb' => $asset->thumb_path ?: ($asset->web_path ?: $asset->path),
            'original' => $asset->path,
            default => $asset->web_path ?: $asset->path,
        };
        $disk = Storage::disk($asset->disk);
        abort_unless($path && $disk->exists($path), 404);

        $mime = $variant === 'original' || $path === $asset->path ? ($asset->mime_type ?: 'application/octet-stream') : ($disk->mimeType($path) ?: 'application/octet-stream');
        $headers = [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            // An uploaded SVG is a document; as an <img> its scripts never run, opened on its own they must not.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'Content-Disposition' => 'inline',
        ];

        $driver = config("filesystems.disks.{$asset->disk}.driver");
        if ($driver === 'local') {
            return response()->file($disk->path($path), $headers);
        }

        return $disk->response($path, null, $headers);
    }

    // ---- offline package ---------------------------------------------------------------------------------------

    public function package(Request $request, Deck $deck, PresentPackage $package): SymfonyResponse
    {
        $this->authorize('view', $deck);

        try {
            $built = $package->build($deck, notes: $request->boolean('notes', true));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        $name = Str::slug($deck->title ?: 'presentation') ?: 'presentation';

        return response()->streamDownload(function () use ($built): void {
            echo $built['html'];
        }, $name.'.html', [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'private, no-store',
            'X-Press-Warnings' => (string) count($built['warnings']),
        ]);
    }

    // ---- helpers -----------------------------------------------------------------------------------------------

    private function pageProps(Request $request, Deck $deck, bool $notes): array
    {
        $slides = PresentPayload::slides($deck, notes: $notes, blankHidden: false);
        $startIndex = max(0, (int) $request->query('from', 0));
        if ($request->filled('slide')) {
            foreach ($slides as $i => $slide) {
                if ((string) $slide['id'] === (string) $request->query('slide')) {
                    $startIndex = $i;
                    break;
                }
            }
        }

        return [
            'deck' => PresentPayload::deckHeader($deck),
            'slides' => $slides,
            'assets' => PresentPayload::assetMap($deck, $slides, now()->addHours(self::TTL_HOURS)),
            'startIndex' => min($startIndex, max(0, count($slides) - 1)),
        ];
    }

    private function exitUrl(Request $request): string
    {
        return route('dashboard', absolute: false);
    }

    private function newToken(): string
    {
        do {
            $token = Str::random(40);
        } while (PresentSession::where('token', $token)->exists());

        return $token;
    }

    private function expiresAt(PresentSession $session): Carbon
    {
        return $session->created_at->copy()->addHours(self::TTL_HOURS);
    }

    private function isLive(PresentSession $session): bool
    {
        return $session->ended_at === null && $this->expiresAt($session)->isFuture();
    }

    private function ownSession(Request $request, string $token): PresentSession
    {
        $session = PresentSession::where('token', $token)->where('user_id', $request->user()->id)->first();
        abort_if($session === null, 404);
        abort_unless($this->isLive($session), 410, 'This live session has ended.');

        return $session;
    }

    private function liveSession(string $token): PresentSession
    {
        $session = PresentSession::where('token', $token)->first();
        abort_if($session === null || ! $this->isLive($session), 404);

        return $session;
    }

    private function deckOf(PresentSession $session): Deck
    {
        $deck = Deck::withoutGlobalScopes()->find($session->deck_id);
        abort_if($deck === null, 404);

        return $deck;
    }

    private function slideCount(PresentSession $session): int
    {
        return Slide::withoutGlobalScopes()->where('deck_id', $session->deck_id)->count();
    }

    /** @return list<Slide> */
    private function orderedSlides(PresentSession $session): array
    {
        return Slide::withoutGlobalScopes()->where('deck_id', $session->deck_id)->orderBy('sort_order')->orderBy('id')->get(['id', 'title', 'notes'])->all();
    }
}
