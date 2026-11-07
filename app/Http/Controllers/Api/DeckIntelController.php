<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deck;
use App\Services\Ai\ContentGenerator;
use App\Services\Press\DeckDocument;
use App\Services\Press\Intel\DeckAnalyzer;
use App\Services\Press\Intel\Frameworks;
use App\Services\Press\Intel\IntelGate;
use App\Services\Press\Intel\Spine;
use App\Services\Press\Intel\Storyteller;
use App\Services\Press\Intel\Suggestions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Whole-deck intelligence. analyze, story and spine are deterministic local rules (free, no quota);
 * suggest may call a language model, and only then spends quota and logs usage. Every answer is a
 * proposal: operations the client applies after the person accepts. Every answer carries `mode`.
 */
class DeckIntelController extends Controller
{
    /** The narrative structures the story tools and the outline accept: ids, names and beats, for pickers. */
    public function frameworks(): JsonResponse
    {
        return response()->json(['frameworks' => array_values(array_map(fn (array $f) => [
            'id' => $f['id'],
            'name' => $f['name'],
            'summary' => $f['summary'],
            'beats' => array_map(fn (array $b) => ['id' => $b['id'], 'label' => $b['label'], 'optional' => (bool) $b['optional']], $f['beats']),
        ], Frameworks::all()))]);
    }

    public function analyze(Deck $deck, DeckAnalyzer $analyzer): JsonResponse
    {
        $deck->loadMissing('project');
        $this->authorize('view', $deck);

        return response()->json($analyzer->analyze(DeckDocument::toArray($deck)));
    }

    public function story(Request $request, Deck $deck, Storyteller $story): JsonResponse
    {
        $deck->loadMissing('project');
        $this->authorize('view', $deck);
        $validated = $request->validate(['framework' => ['nullable', 'string', Rule::in(['auto', ...Frameworks::ids()])]]);
        $fw = $validated['framework'] ?? null;

        return response()->json($story->analyze(DeckDocument::toArray($deck), $fw === 'auto' ? null : $fw));
    }

    public function spine(Deck $deck, Spine $spine): JsonResponse
    {
        $deck->loadMissing('project');
        $this->authorize('view', $deck);

        return response()->json(['mode' => 'local'] + $spine->extract(DeckDocument::toArray($deck)));
    }

    /** Propose the operations for a spine edit (reorder, merge, split, rename). Nothing is applied. */
    public function spineEdit(Request $request, Deck $deck, Spine $spine): JsonResponse
    {
        $deck->loadMissing('project');
        $this->authorize('update', $deck);
        $validated = $request->validate([
            'type' => ['required', Rule::in(['reorder', 'merge', 'split', 'rename'])],
            'beat' => ['required', 'string', 'max:40'],
            'after' => ['nullable', 'string', 'max:40'],
            'into' => ['nullable', 'string', 'max:40'],
            'at' => ['nullable', 'regex:/^[A-Za-z0-9_-]{1,40}$/'],
            'claim' => ['nullable', 'string', 'max:300'],
        ]);

        try {
            $result = $spine->applyEdit(DeckDocument::toArray($deck), $validated);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['mode' => 'local'] + $result);
    }

    public function suggest(Request $request, Deck $deck, Suggestions $suggestions, ContentGenerator $generator, IntelGate $gate): JsonResponse
    {
        $deck->loadMissing('project');
        // Suggestions edit the deck once accepted and may spend the AI quota, like the other AI editing tools.
        $this->authorize('update', $deck);

        $max = (int) config('press-ai.suggest.max_text_chars', 8000);
        $validated = $request->validate([
            'kind' => ['required', 'string', Rule::in(Suggestions::KINDS)],
            'slide' => ['nullable', 'regex:/^[A-Za-z0-9_-]{1,40}$/'],
            'element' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,40}$/'],
            'text' => ['nullable', 'string', 'max:'.$max],
            'tone' => ['nullable', 'string', 'max:40'],
            'framework' => ['nullable', 'string', Rule::in(['auto', ...Frameworks::ids()])],
        ]);
        if (($validated['framework'] ?? null) === 'auto') {
            $validated['framework'] = null;
        }

        $user = $request->user();
        $cost = (int) config('press-ai.quota_cost.suggest', 1);
        $wantsModel = $suggestions->usesModel($validated['kind']);
        $allowModel = $wantsModel;
        $quotaNote = null;

        if ($wantsModel) {
            $reason = $gate->unsafe($validated['text'] ?? '');
            if ($reason !== null) {
                $gate->blocked($user, $deck->id, 'press_suggest', $reason, $validated['text'] ?? '');

                return response()->json(['message' => $reason], 422);
            }
            if (! $gate->affords($user, $cost)) {
                $allowModel = false;
                $quotaNote = 'The daily AI quota is used up, so local rules answered.';
            }
        }

        try {
            $result = $suggestions->suggest(DeckDocument::toArray($deck), $validated, $allowModel);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($result['model_called']) {
            $gate->record($user, $deck->id, 'press_suggest', $validated['kind'].': '.($validated['text'] ?? ''), ! $result['degraded'], $result['usage'], $result['degraded_reason'], 0, $cost, ['kind' => $validated['kind'], 'mode' => $result['mode']]);
        }
        if ($quotaNote !== null) {
            $result['degraded'] = true;
            $result['degraded_reason'] = $quotaNote;
        }
        unset($result['usage'], $result['model_called']);
        if ($wantsModel) {
            $result['quota'] = ['remaining_today' => $gate->remaining($user)];
        }

        return response()->json($result);
    }
}
