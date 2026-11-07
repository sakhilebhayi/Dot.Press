<?php

namespace App\Services\Press;

use App\Models\Deck;
use App\Models\DeckChange;
use App\Models\DeckVersion;
use App\Models\Slide;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Version history: a snapshot is the DeckDocument::toArray output as JSON. Kinds: `auto` (taken after an accepted
 * batch when no version is newer than 20 minutes), `manual` (named by a person), `restore` (the safety copy taken just
 * before a restore, so a restore can itself be undone) and `approved` (written by the review flow). Manual, approved
 * and restore versions are kept; only the 40 newest `auto` versions are.
 */
class VersionService
{
    public const KINDS = ['auto', 'manual', 'restore', 'approved'];

    public const AUTO_INTERVAL_MINUTES = 20;

    public const KEEP_AUTO = 40;

    /**
     * Entry point for other services (the review flow stores `approved` versions through it).
     */
    public static function snapshot(Deck $deck, ?User $actor, string $kind, ?string $label = null): DeckVersion
    {
        return app(self::class)->create($deck, $actor, $kind, $label);
    }

    public function create(Deck $deck, ?User $user, string $kind, ?string $label = null): DeckVersion
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new RuntimeException("Unknown version kind {$kind}");
        }

        $document = DeckDocument::toArray($deck);
        $snapshot = (string) json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $version = DeckVersion::create([
            'deck_id' => $deck->id,
            'user_id' => $user?->id,
            'kind' => $kind,
            'label' => $label === null ? null : Str::limit(trim($label), 120, ''),
            'snapshot' => $snapshot,
            'hash' => DeckHash::compute($deck),
            'slide_count' => count($document['slides']),
            'size' => strlen($snapshot),
            'created_at' => now(),
        ]);

        $this->prune($deck);

        return $version;
    }

    /**
     * The auto-version rule, run after every accepted batch: no version newer than 20 minutes and a deck that differs
     * from the last stored one.
     */
    public function autoIfDue(Deck $deck, ?User $user): ?DeckVersion
    {
        $last = DeckVersion::query()->where('deck_id', $deck->id)->orderByDesc('id')->first(['id', 'hash', 'created_at']);

        if ($last !== null && $last->created_at->gt(now()->subMinutes(self::AUTO_INTERVAL_MINUTES))) {
            return null;
        }
        if ($last !== null && $last->hash === DeckHash::compute($deck)) {
            return null;
        }

        return $this->create($deck, $user, 'auto');
    }

    /**
     * Newest first, without the snapshot body.
     *
     * @return array{versions: list<array<string, mixed>>, next_before: int|null}
     */
    public function list(Deck $deck, int $limit = 50, ?int $before = null): array
    {
        $limit = max(1, min(200, $limit));

        $rows = DeckVersion::query()
            ->where('deck_id', $deck->id)
            ->when($before !== null, fn ($q) => $q->where('id', '<', $before))
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->with('user:id,name')
            ->get(['id', 'deck_id', 'user_id', 'kind', 'label', 'hash', 'slide_count', 'size', 'created_at']);

        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);

        return [
            'versions' => $rows->map(fn (DeckVersion $v) => $this->present($v))->values()->all(),
            'next_before' => $more ? $rows->last()->id : null,
        ];
    }

    public function find(Deck $deck, int $id): DeckVersion
    {
        return DeckVersion::query()->where('deck_id', $deck->id)->with('user:id,name')->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function present(DeckVersion $version, bool $withSnapshot = false): array
    {
        $out = [
            'id' => $version->id,
            'kind' => $version->kind,
            'label' => $version->label,
            'author' => $version->user ? ['id' => $version->user->id, 'name' => $version->user->name] : null,
            'slide_count' => $version->slide_count,
            'size' => $version->size,
            'hash' => $version->hash,
            'created_at' => $version->created_at?->toIso8601String(),
        ];

        if ($withSnapshot) {
            $out['snapshot'] = json_decode($version->snapshot, true);
        }

        return $out;
    }

    /**
     * Rewrites the deck to a stored snapshot. A `restore` safety copy of the current state is stored first. Slides that
     * the snapshot has keep or get back their original ids; slides it does not have are deleted. The change log gets one
     * row whose only op is deck.reload.
     *
     * @return array{seq: int, deck_rev: int, safety_version: int}
     */
    public function restore(Deck $deck, DeckVersion $version, ?User $user): array
    {
        $document = json_decode($version->snapshot, true);
        if (! is_array($document) || ! is_array($document['deck'] ?? null) || ! is_array($document['slides'] ?? null) || $document['slides'] === []) {
            throw new RuntimeException('This version cannot be restored.');
        }

        return DB::transaction(function () use ($deck, $version, $user, $document): array {
            $locked = Deck::withoutGlobalScopes()->lockForUpdate()->findOrFail($deck->id);
            $safety = $this->create($locked, $user, 'restore', 'Before restoring '.($version->label ?: 'version '.$version->id));

            $existing = Slide::withoutGlobalScopes()->where('deck_id', $locked->id)->get()->keyBy('id');
            $wanted = [];
            foreach ($document['slides'] as $position => $data) {
                $wanted[(int) $data['id']] = true;
            }

            $gone = $existing->keys()->reject(fn ($id) => isset($wanted[$id]))->all();
            if ($gone !== []) {
                Slide::withoutGlobalScopes()->whereIn('id', $gone)->delete();
            }

            $seen = [];
            foreach ($document['slides'] as $position => $data) {
                $id = (int) $data['id'];
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;

                $attributes = [
                    'title' => $data['title'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'layout' => $data['layout'] ?? 'blank',
                    'bg' => $data['bg'] ?? null,
                    'transition' => $data['transition'] ?? null,
                    'section_id' => $data['section_id'] ?? null,
                    'hidden' => (bool) ($data['hidden'] ?? false),
                    'els' => $data['els'] ?? [],
                    'sort_order' => $position,
                ];

                $slide = $existing->get($id);
                if ($slide === null) {
                    // A deleted slide comes back under its own id, unless that id has since been taken elsewhere.
                    $slide = new Slide;
                    $slide->deck_id = $locked->id;
                    if (! Slide::withoutGlobalScopes()->whereKey($id)->exists()) {
                        $slide->id = $id;
                    }
                    $slide->revision = (int) ($data['rev'] ?? 0);
                }

                $slide->forceFill($attributes);
                $slide->revision = (int) $slide->revision + 1;
                $slide->save();
            }

            $theme = $document['deck']['theme'] ?? null;
            $locked->title = (string) ($document['deck']['title'] ?? $locked->title);
            $locked->w = (int) ($document['deck']['w'] ?? $locked->w);
            $locked->h = (int) ($document['deck']['h'] ?? $locked->h);
            $locked->theme = is_array($theme) ? $theme : null;
            $locked->sections = is_array($document['deck']['sections'] ?? null) ? array_values($document['deck']['sections']) : [];
            $locked->rev = (int) $locked->rev + 1;
            $locked->save();

            $change = DeckChange::create([
                'deck_id' => $locked->id,
                'user_id' => $user?->id,
                'client' => 'server',
                'key' => 'restore-'.Str::uuid(),
                'ops' => [['op' => 'deck.reload']],
                'created_at' => now(),
            ]);

            ActivityRecorder::record($locked, $user, 'version.restored', ['version' => $version->id, 'label' => $version->label]);

            return ['seq' => $change->id, 'deck_rev' => $locked->rev, 'safety_version' => $safety->id];
        });
    }

    private function prune(Deck $deck): void
    {
        $cutoff = DeckVersion::query()
            ->where('deck_id', $deck->id)
            ->where('kind', 'auto')
            ->orderByDesc('id')
            ->skip(self::KEEP_AUTO)
            ->take(1)
            ->value('id');

        if ($cutoff !== null) {
            DeckVersion::query()->where('deck_id', $deck->id)->where('kind', 'auto')->where('id', '<=', $cutoff)->delete();
        }
    }
}
