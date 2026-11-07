<?php

namespace App\Services\Press\Intel;

use App\Models\AiUsageLog;
use App\Models\User;
use App\Services\Ai\SafetyGuard;

/**
 * Quota, safety and usage log for the Dot.Press intelligence endpoints. Only calls that reach a
 * language model count: local rules are free, so they never touch the quota and never log.
 * The quota is the same daily number of AiUsageLog rows the older AI endpoints use.
 */
final class IntelGate
{
    public function __construct(private readonly SafetyGuard $safety) {}

    public function remaining(User $user): int
    {
        $used = AiUsageLog::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->whereDate('created_at', now()->toDateString())
            ->count();

        return max(0, (int) config('ai.limits.daily_quota', 100) - $used);
    }

    public function affords(User $user, int $cost): bool
    {
        return $this->remaining($user) >= $cost;
    }

    /** The reason text would be refused by the safety guard, or null. Long texts are checked in chunks. */
    public function unsafe(string ...$texts): ?string
    {
        foreach ($texts as $text) {
            foreach (mb_str_split($text, 7500) ?: [] as $chunk) {
                if (trim($chunk) === '') {
                    continue;
                }
                $r = $this->safety->evaluate($chunk);
                if ($r['blocked']) {
                    return $r['reason'] ?? 'The text failed safety checks.';
                }
            }
        }

        return null;
    }

    /**
     * Record one model call. `$cost` quota units are charged (one row each, the first carries the detail).
     *
     * @param  array<string, mixed>  $meta
     */
    public function record(User $user, ?int $deckId, string $action, string $prompt, bool $ok, array $usage, ?string $error, int $latencyMs, int $cost, array $meta = []): void
    {
        $max = (int) config('ai.logging.max_prompt_chars', 5000);
        for ($i = 0; $i < max(1, $cost); $i++) {
            AiUsageLog::create([
                'user_id' => $user->id,
                'deck_id' => $deckId,
                'action' => $action,
                'provider' => (string) config('ai.provider', 'mock'),
                'model' => config('ai.anthropic.model'),
                'status' => $ok ? ($i === 0 ? 'success' : 'charge') : ($i === 0 ? 'failed' : 'charge'),
                'safety_blocked' => false,
                'prompt' => $i === 0 ? mb_substr($prompt, 0, $max) : null,
                'input_tokens' => $i === 0 ? ($usage['input_tokens'] ?? null) : null,
                'output_tokens' => $i === 0 ? ($usage['output_tokens'] ?? null) : null,
                'latency_ms' => $i === 0 ? $latencyMs : null,
                'error_message' => $i === 0 && ! $ok ? mb_substr((string) $error, 0, 1000) : null,
                'meta' => $i === 0 ? $meta : null,
            ]);
        }
    }

    public function blocked(User $user, ?int $deckId, string $action, string $reason, string $prompt): void
    {
        AiUsageLog::create([
            'user_id' => $user->id,
            'deck_id' => $deckId,
            'action' => $action,
            'provider' => (string) config('ai.provider', 'mock'),
            'status' => 'blocked',
            'safety_blocked' => true,
            'safety_reason' => $reason,
            'prompt' => mb_substr($prompt, 0, (int) config('ai.logging.max_prompt_chars', 5000)),
        ]);
    }
}
