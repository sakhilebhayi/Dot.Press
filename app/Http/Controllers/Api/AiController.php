<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiUsageLog;
use App\Models\Deck;
use App\Models\Slide;
use App\Services\Ai\ContentGenerator;
use App\Services\Ai\SafetyGuard;
use App\Services\Press\DeckDocument;
use App\Services\Press\DocumentSanitizer;
use App\Services\Press\OpApplier;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AiController extends Controller
{
    public function __construct(private readonly OpApplier $applier) {}

    public function generateSlide(Request $request, Deck $deck, ContentGenerator $generator, SafetyGuard $safetyGuard)
    {
        $deck->loadMissing('project');
        // Both actions add slides to the deck, so they need the right to create there.
        $this->authorize('create', [Slide::class, $deck]);

        if (($quotaResponse = $this->ensureQuota($request)) !== null) {
            return $quotaResponse;
        }

        $validated = $request->validate([
            'prompt' => ['required', 'string', 'max:8000'],
        ]);

        $prompt = trim($validated['prompt']);
        $safety = $safetyGuard->evaluate($prompt);

        if ($safety['blocked']) {
            $this->logUsage($request, [
                'deck_id' => $deck->id,
                'action' => 'generate_slide',
                'status' => 'blocked',
                'safety_blocked' => true,
                'safety_reason' => $safety['reason'],
                'prompt' => $this->truncatePrompt($prompt),
            ]);

            return response()->json([
                'message' => $safety['reason'] ?? 'Prompt failed safety checks.',
            ], 422);
        }

        $startedAt = microtime(true);

        try {
            $result = $generator->generateSlide($prompt, (int) $deck->w, (int) $deck->h);
            $elements = $result['els'];

            $created = $this->addSlides($request, $deck, [[
                'title' => $result['title'], 'layout' => $result['layout'], 'notes' => $result['notes'], 'els' => $elements,
            ]]);
            $slide = $created['slides'][0];

            $usage = Arr::get($result, 'usage', []);

            $this->logUsage($request, [
                'deck_id' => $deck->id,
                'slide_id' => $slide->id,
                'action' => 'generate_slide',
                'status' => 'success',
                'safety_blocked' => false,
                'prompt' => $this->truncatePrompt($prompt),
                'response' => $this->truncateResponse(json_encode([
                    'title' => $slide->title,
                    'elements_count' => count($elements),
                ])),
                'input_tokens' => Arr::get($usage, 'input_tokens'),
                'output_tokens' => Arr::get($usage, 'output_tokens'),
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            return response()->json([
                'slide' => DeckDocument::slideToArray($slide) + ['deck_id' => $deck->id],
                'seq' => $created['seq'],
                'deck_rev' => $created['deck_rev'],
                'usage' => [
                    'remaining_today' => $this->remainingQuota($request),
                ],
            ], 201);
        } catch (Throwable $exception) {
            $this->logUsage($request, [
                'deck_id' => $deck->id,
                'action' => 'generate_slide',
                'status' => 'failed',
                'safety_blocked' => false,
                'prompt' => $this->truncatePrompt($prompt),
                'error_message' => Str::limit($exception->getMessage(), 1000, ''),
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            return response()->json([
                'message' => 'AI slide generation failed. Please try again.',
            ], 500);
        }
    }

    /**
     * Generate a full themed deck (title slide + body slides) from one
     * prompt/outline in a single call, instead of the caller looping
     * generate-slide themselves one slide at a time.
     */
    public function generateDeck(Request $request, Deck $deck, ContentGenerator $generator, SafetyGuard $safetyGuard)
    {
        $deck->loadMissing('project');
        // Both actions add slides to the deck, so they need the right to create there.
        $this->authorize('create', [Slide::class, $deck]);

        $maxSlides = (int) config('ai.limits.max_deck_slides', 12);

        $validated = $request->validate([
            'prompt' => ['required', 'string', 'max:8000'],
            'slide_count' => ['nullable', 'integer', 'min:2', 'max:'.$maxSlides],
        ]);

        $slideCount = (int) ($validated['slide_count'] ?? 6);

        // Full-deck generation is charged one quota unit per slide (see
        // config/ai.php), so the quota check needs the deck size up front.
        if (($quotaResponse = $this->ensureQuota($request, $slideCount)) !== null) {
            return $quotaResponse;
        }

        $prompt = trim($validated['prompt']);
        $safety = $safetyGuard->evaluate($prompt);

        if ($safety['blocked']) {
            $this->logUsage($request, [
                'deck_id' => $deck->id,
                'action' => 'generate_deck',
                'status' => 'blocked',
                'safety_blocked' => true,
                'safety_reason' => $safety['reason'],
                'prompt' => $this->truncatePrompt($prompt),
            ]);

            return response()->json([
                'message' => $safety['reason'] ?? 'Prompt failed safety checks.',
            ], 422);
        }

        $startedAt = microtime(true);

        try {
            $result = $generator->generateDeck($prompt, $slideCount, (int) $deck->w, (int) $deck->h);
            $slideSpecs = $result['slides'];
            $usage = Arr::get($result, 'usage', []);

            $created = $this->addSlides($request, $deck, array_map(fn ($spec) => [
                'title' => $spec['title'], 'layout' => $spec['layout'], 'notes' => $spec['notes'], 'els' => $spec['els'],
            ], $slideSpecs));
            $createdSlides = $created['slides'];

            foreach ($createdSlides as $offset => $slide) {
                $this->logUsage($request, [
                    'deck_id' => $deck->id,
                    'slide_id' => $slide->id,
                    'action' => 'generate_deck',
                    'status' => 'success',
                    'safety_blocked' => false,
                    'prompt' => $this->truncatePrompt($prompt),
                    'response' => $this->truncateResponse(json_encode([
                        'title' => $slide->title,
                        'elements_count' => count($slide->els ?? []),
                        'slide_index' => $offset,
                        'deck_slide_count' => count($slideSpecs),
                    ])),
                    'input_tokens' => $offset === 0 ? Arr::get($usage, 'input_tokens') : null,
                    'output_tokens' => $offset === 0 ? Arr::get($usage, 'output_tokens') : null,
                    'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                ]);
            }

            return response()->json([
                'deck_id' => $deck->id,
                'slides' => array_map(fn (Slide $slide) => DeckDocument::slideToArray($slide) + ['deck_id' => $deck->id], $createdSlides),
                'seq' => $created['seq'],
                'deck_rev' => $created['deck_rev'],
                'usage' => [
                    'remaining_today' => $this->remainingQuota($request),
                ],
            ], 201);
        } catch (Throwable $exception) {
            $this->logUsage($request, [
                'deck_id' => $deck->id,
                'action' => 'generate_deck',
                'status' => 'failed',
                'safety_blocked' => false,
                'prompt' => $this->truncatePrompt($prompt),
                'error_message' => Str::limit($exception->getMessage(), 1000, ''),
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            return response()->json([
                'message' => 'AI deck generation failed. Please try again.',
            ], 500);
        }
    }

    /**
     * Proposes a rewrite and applies nothing. Name a text element (`element`, a text or shape element of this slide) to
     * rewrite all of its text, or send `text` alone for a selection. For a whole element the answer carries the
     * ready-made `el.set` operation; the client sends it through POST /api/decks/{deck}/ops if the person accepts.
     */
    public function rewriteText(Request $request, Slide $slide, ContentGenerator $generator, SafetyGuard $safetyGuard)
    {
        $slide->loadMissing('deck.project');
        // Writes nothing itself, but it is an editing tool that spends the AI quota.
        $this->authorize('update', $slide);

        if (($quotaResponse = $this->ensureQuota($request)) !== null) {
            return $quotaResponse;
        }

        $validated = $request->validate([
            'text' => ['nullable', 'string', 'max:8000', 'required_without:element'],
            'element' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/D', 'required_without:text'],
            'mode' => ['required', 'string', 'in:shorten,expand,rephrase,tone'],
            'tone' => ['nullable', 'string', 'max:80'],
        ]);

        $sanitizer = new DocumentSanitizer;
        $elementId = $validated['element'] ?? null;
        $wholeElement = $elementId !== null && ! filled($validated['text'] ?? null);

        if ($elementId !== null) {
            $element = collect($slide->els ?? [])->firstWhere('id', $elementId);
            if (! is_array($element) || ! in_array($element['type'] ?? null, ['text', 'shape'], true) || ! is_array($element['doc'] ?? null)) {
                return response()->json(['message' => 'That element has no text to rewrite.'], 422);
            }
        }

        $text = trim($wholeElement ? $sanitizer->plainText($element['doc']) : (string) $validated['text']);
        if ($text === '') {
            return response()->json(['message' => 'There is no text to rewrite.'], 422);
        }
        $mode = $validated['mode'];
        $tone = $validated['tone'] ?? null;

        $safety = $safetyGuard->evaluate($text);

        if ($safety['blocked']) {
            $this->logUsage($request, [
                'deck_id' => $slide->deck_id,
                'slide_id' => $slide->id,
                'action' => 'rewrite_text',
                'status' => 'blocked',
                'safety_blocked' => true,
                'safety_reason' => $safety['reason'],
                'prompt' => $this->truncatePrompt($text),
                'meta' => ['mode' => $mode, 'tone' => $tone],
            ]);

            return response()->json([
                'message' => $safety['reason'] ?? 'Text failed safety checks.',
            ], 422);
        }

        $startedAt = microtime(true);

        try {
            $rewritten = $generator->rewriteText($text, $mode, $tone);

            $this->logUsage($request, [
                'deck_id' => $slide->deck_id,
                'slide_id' => $slide->id,
                'action' => 'rewrite_text',
                'status' => 'success',
                'safety_blocked' => false,
                'prompt' => $this->truncatePrompt($text),
                'response' => $this->truncateResponse($rewritten),
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'meta' => ['mode' => $mode, 'tone' => $tone],
            ]);

            $proposal = null;
            if ($elementId !== null) {
                $proposal = ['slide' => $slide->id, 'element' => $elementId, 'text' => $rewritten];
                if ($wholeElement) {
                    // The proposal is plain paragraphs; the original's inline formatting is not carried over.
                    $doc = $sanitizer->docFromText($rewritten);
                    $proposal['doc'] = $doc;
                    $proposal['op'] = ['op' => 'el.set', 'slide' => $slide->id, 'id' => $elementId, 'p' => ['doc' => $doc]];
                }
            }

            return response()->json([
                'text' => $rewritten,
                'proposal' => $proposal,
                'usage' => [
                    'remaining_today' => $this->remainingQuota($request),
                ],
            ]);
        } catch (Throwable $exception) {
            $this->logUsage($request, [
                'deck_id' => $slide->deck_id,
                'slide_id' => $slide->id,
                'action' => 'rewrite_text',
                'status' => 'failed',
                'safety_blocked' => false,
                'prompt' => $this->truncatePrompt($text),
                'error_message' => Str::limit($exception->getMessage(), 1000, ''),
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'meta' => ['mode' => $mode, 'tone' => $tone],
            ]);

            return response()->json([
                'message' => 'AI rewrite failed. Please try again.',
            ], 500);
        }
    }

    public function usage(Request $request)
    {
        return response()->json([
            'used_today' => $this->usedToday($request),
            'daily_quota' => $this->dailyQuota(),
            'remaining_today' => $this->remainingQuota($request),
        ]);
    }

    /**
     * Writes generated slides to the end of the deck as slide.add operations (so they are logged, versioned and seen
     * by other editors like any other change) and returns the stored slides in order.
     *
     * @param  list<array<string, mixed>>  $slides  title, layout, notes, els
     * @return array{slides: list<Slide>, seq: int, deck_rev: int}
     */
    private function addSlides(Request $request, Deck $deck, array $slides): array
    {
        $last = Slide::withoutGlobalScopes()->where('deck_id', $deck->id)->orderByDesc('sort_order')->orderByDesc('id')->value('id');

        $ops = [];
        $previous = $last;
        foreach ($slides as $i => $slide) {
            $tid = 'ai'.($i + 1);
            $ops[] = ['op' => 'slide.add', 'tid' => $tid, 'after' => $previous, 'slide' => $slide];
            $previous = $tid;
        }

        $result = $this->applier->apply($deck, $request->user(), 'ai', 'ai-'.Str::uuid(), $ops);

        $created = [];
        foreach (array_keys($slides) as $i) {
            $id = $result['ids']['ai'.($i + 1)] ?? null;
            if ($id === null) {
                $reason = $result['skipped'][0]['reason'] ?? 'unknown';
                throw new RuntimeException("The generated slide could not be stored: {$reason}");
            }
            $created[] = Slide::withoutGlobalScopes()->findOrFail($id);
        }

        return ['slides' => $created, 'seq' => $result['seq'], 'deck_rev' => $result['deck_rev']];
    }

    private function logUsage(Request $request, array $payload): void
    {
        AiUsageLog::create([
            'user_id' => $request->user()->id,
            'provider' => config('ai.provider', 'mock'),
            'model' => config('ai.anthropic.model'),
            ...$payload,
        ]);
    }

    private function usedToday(Request $request): int
    {
        return AiUsageLog::query()
            ->where('user_id', $request->user()->id)
            ->whereDate('created_at', now()->toDateString())
            ->count();
    }

    private function dailyQuota(): int
    {
        return (int) config('ai.limits.daily_quota', 100);
    }

    private function remainingQuota(Request $request): int
    {
        return max(0, $this->dailyQuota() - $this->usedToday($request));
    }

    /**
     * $cost lets a single call reserve more than one quota unit up front --
     * generateDeck() charges one unit per slide it's about to generate,
     * rather than only failing partway through after some slides already
     * consumed quota.
     */
    private function ensureQuota(Request $request, int $cost = 1)
    {
        if ($this->remainingQuota($request) >= $cost) {
            return null;
        }

        return response()->json([
            'message' => 'You reached your daily AI quota. Try again tomorrow.',
        ], 429);
    }

    private function truncatePrompt(string $value): string
    {
        $max = (int) config('ai.logging.max_prompt_chars', 5000);

        return Str::limit($value, $max, '');
    }

    private function truncateResponse(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $max = (int) config('ai.logging.max_response_chars', 5000);

        return Str::limit($value, $max, '');
    }
}
