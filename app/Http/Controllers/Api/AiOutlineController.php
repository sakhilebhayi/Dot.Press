<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\ContentGenerator;
use App\Services\Press\Intel\Frameworks;
use App\Services\Press\Intel\IntelGate;
use App\Services\Press\Intel\OutlineGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * POST /api/ai/outline: a brief (and optional source text) to an editable outline. Nothing is
 * created: the person reviews and edits the outline, then asks for slides. The answer says whether
 * local rules or a language model produced it (`mode`) and whether a model call failed (`degraded`).
 */
class AiOutlineController extends Controller
{
    public function __invoke(Request $request, ContentGenerator $generator, OutlineGenerator $outline, IntelGate $gate): JsonResponse
    {
        $cfg = config('press-ai.outline');
        $validated = $request->validate([
            'brief' => ['required', 'string', 'min:3', 'max:'.$cfg['max_brief_chars']],
            'source' => ['nullable', 'string', 'max:'.$cfg['max_source_chars']],
            'framework' => ['nullable', 'string', Rule::in(['auto', ...Frameworks::ids()])],
            'audience' => ['nullable', 'string', 'max:200'],
            'length' => ['nullable', 'integer', 'min:'.$cfg['min_slides'], 'max:'.$cfg['max_slides']],
            'tone' => ['nullable', 'string', 'max:40'],
        ]);

        $user = $request->user();
        $cost = (int) config('press-ai.quota_cost.outline', 3);
        $allowModel = $generator->modelAvailable();
        $quotaNote = null;

        if ($allowModel) {
            $reason = $gate->unsafe($validated['brief'], $validated['source'] ?? '');
            if ($reason !== null) {
                $gate->blocked($user, null, 'press_outline', $reason, $validated['brief']);

                return response()->json(['message' => $reason], 422);
            }
            if (! $gate->affords($user, $cost)) {
                $allowModel = false;
                $quotaNote = 'The daily AI quota is used up, so local rules built this outline.';
            }
        }

        $result = $outline->generate($validated, $allowModel);

        if ($result['model_called']) {
            $ok = ! $result['degraded'];
            $gate->record($user, null, 'press_outline', $validated['brief'], $ok, $result['usage'], $result['degraded_reason'], 0, $cost, ['mode' => $result['mode'], 'slides' => $result['length_delivered']]);
        }
        if ($quotaNote !== null) {
            $result['degraded'] = true;
            $result['degraded_reason'] = $quotaNote;
        }
        unset($result['usage'], $result['model_called']);
        $result['quota'] = ['remaining_today' => $gate->remaining($user)];

        return response()->json($result);
    }
}
