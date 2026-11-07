<?php

namespace App\Services\Press;

use App\Models\Asset;
use App\Models\Deck;
use App\Models\DeckChange;
use App\Models\Project;
use App\Models\Slide;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Applies an operations batch (spec section 8) to the latest stored document in one transaction. On SQLite the
 * connection runs transactions in IMMEDIATE mode (config/database.php), which serialises writers; on other databases the
 * deck row is locked. The same key never applies twice: a repeat returns the stored result.
 */
class OpApplier
{
    public function __construct(
        private readonly DocumentSanitizer $sanitizer,
        private readonly VersionService $versions,
    ) {}

    /**
     * @param  list<mixed>  $ops
     * @return array{seq: int, deck_rev: int, slides: array<int, int>, ids: array<string, int>, skipped: list<array<string, mixed>>}
     */
    public function apply(Deck $deck, ?User $user, string $client, string $key, array $ops): array
    {
        // The deck's `rev` is bumped once per batch and that is what makes the cached hash and library preview stale;
        // the per slide hook (Slide::booted) would only repeat it for every slide written.
        $quiet = Slide::$quiet;
        Slide::$quiet = true;
        try {
            return DB::transaction(fn () => $this->applyLocked($deck, $user, $client, $key, $ops));
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent request with the same key committed first: answer with what it stored.
            $stored = DeckChange::query()->where('deck_id', $deck->id)->where('key', $key)->first();
            if ($stored === null) {
                throw $e;
            }

            return $this->replay($stored, $user);
        } finally {
            Slide::$quiet = $quiet;
        }
    }

    /**
     * @param  list<mixed>  $ops
     * @return array<string, mixed>
     */
    private function applyLocked(Deck $deck, ?User $user, string $client, string $key, array $ops): array
    {
        $locked = Deck::withoutGlobalScopes()->lockForUpdate()->findOrFail($deck->id);

        $stored = DeckChange::query()->where('deck_id', $locked->id)->where('key', $key)->first();
        if ($stored !== null) {
            return $this->replay($stored, $user);
        }

        $teamId = Project::withoutGlobalScopes()->whereKey($locked->project_id)->value('team_id');
        $batch = new DeckBatch($locked, $this->sanitizer, function (array $ids) use ($teamId): bool {
            return Asset::withoutGlobalScopes()
                ->whereIn('id', $ids)
                ->whereIn('project_id', Project::withoutGlobalScopes()->where('team_id', $teamId)->select('id'))
                ->count() === count($ids);
        });
        $batch->run($ops);

        if ($batch->applied === []) {
            return [
                'seq' => (int) (DeckChange::query()->where('deck_id', $locked->id)->max('id') ?? 0),
                'deck_rev' => (int) $locked->rev,
                'slides' => [],
                'ids' => [],
                'skipped' => $batch->skipped,
            ];
        }

        $revs = $batch->save();
        $locked->rev = (int) $locked->rev + 1;
        $locked->save();

        $change = DeckChange::create([
            'deck_id' => $locked->id,
            'user_id' => $user?->id,
            'client' => $client,
            'key' => $key,
            'ops' => $batch->applied,
            'created_at' => now(),
        ]);

        $result = [
            'seq' => $change->id,
            'deck_rev' => (int) $locked->rev,
            'slides' => $revs,
            'ids' => $batch->ids(),
            'skipped' => $batch->skipped,
        ];
        $change->update(['result' => $result]);

        foreach ($batch->activity as [$event, $meta]) {
            ActivityRecorder::record($locked, $user, $event, $meta);
        }

        $this->versions->autoIfDue($locked, $user);

        // An approval is bound to the content it approved; the review flow withdraws it when the deck has changed.
        if (class_exists(Reviews::class) && method_exists(Reviews::class, 'refreshStatus')) {
            Reviews::refreshStatus($locked);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function replay(DeckChange $stored, ?User $user): array
    {
        if ($stored->user_id !== $user?->id) {
            throw new HttpException(409, 'That key was already used.');
        }

        return $stored->result ?? ['seq' => $stored->id, 'deck_rev' => 0, 'slides' => [], 'ids' => [], 'skipped' => []];
    }
}
