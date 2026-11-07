<?php

namespace App\Services\Press;

use App\Models\Deck;
use App\Models\Slide;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use TypeError;

/**
 * One ops request, applied in memory to the latest stored document and then written in one go by OpApplier's
 * transaction. Every handler validates before it mutates, so a skipped op leaves no trace. Slide element lists are
 * loaded only for the slides an op touches.
 */
final class DeckBatch
{
    /** @var list<int> slide ids in deck order */
    private array $order = [];

    /** @var array<int, Slide> */
    private array $slides = [];

    /** @var array<int, list<array<string, mixed>>> */
    private array $els = [];

    /** @var array<int, int> */
    private array $bytes = [];

    /** @var array<int, true> */
    private array $touched = [];

    /** @var list<int> */
    private array $removed = [];

    /** @var array<string, int> */
    private array $tids = [];

    /** @var list<array<string, mixed>> sections as stored on the deck */
    private array $sections;

    private bool $deckChanged = false;

    private int $index = 0;

    /** Bytes of slide content this request has added so far (see charge()). */
    private int $grown = 0;

    /** Bytes of slide content the deck held when the request began, read once and only when something grows. */
    private ?int $deckBytes = null;

    /** @var array<int, bool> */
    private array $assetChecks = [];

    /** @var list<array{op: int|string, index: int, reason: string}> */
    public array $skipped = [];

    /** @var list<array<string, mixed>> the sanitised ops others replay */
    public array $applied = [];

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $activity = [];

    /**
     * @param  Closure(list<int>): bool  $assetsAllowed  true when every id is an asset of the deck's own team
     */
    public function __construct(
        private readonly Deck $deck,
        private readonly DocumentSanitizer $san,
        private readonly Closure $assetsAllowed,
    ) {
        $rows = Slide::withoutGlobalScopes()
            ->where('deck_id', $deck->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'deck_id', 'title', 'notes', 'layout', 'sort_order', 'revision', 'bg', 'transition', 'section_id', 'hidden']);

        foreach ($rows as $row) {
            $this->order[] = $row->id;
            $this->slides[$row->id] = $row;
        }
        $this->sections = array_values($deck->sections ?? []);
    }

    /**
     * @param  list<mixed>  $ops
     */
    public function run(array $ops): void
    {
        foreach ($ops as $i => $op) {
            $this->index = $i;
            $name = is_array($op) && is_string($op['op'] ?? null) ? $op['op'] : '?';

            try {
                if (! is_array($op) || $name === '?') {
                    throw new InvalidDocument('malformed op');
                }
                $this->dispatch($name, $op);
            } catch (InvalidDocument $e) {
                $this->skip($name, $e->getMessage());
            } catch (TypeError) {
                $this->skip($name, 'malformed op');
            }
        }
    }

    /**
     * @return array<string, int> temporary id => stored slide id
     */
    public function ids(): array
    {
        return $this->tids;
    }

    public function changedDeck(): bool
    {
        return $this->deckChanged;
    }

    /**
     * @return array<int, int> slide id => new revision, for the slides this request touched
     */
    public function save(): array
    {
        if ($this->removed !== []) {
            Slide::withoutGlobalScopes()->whereIn('id', $this->removed)->delete();
        }

        $revs = [];
        $reordered = [];
        foreach ($this->order as $position => $id) {
            $slide = $this->slides[$id];
            $slide->sort_order = $position;

            if (isset($this->touched[$id])) {
                if (isset($this->els[$id])) {
                    $slide->els = $this->els[$id];
                }
                $slide->revision = (int) $slide->revision + 1;
                $revs[$id] = $slide->revision;
            }

            if (! $slide->isDirty()) {
                continue;
            }

            // A slide that only moved is written together with the others that moved: inserting one slide near the top
            // of a 300 slide deck shifts the sort_order of every slide below it, and that was 299 UPDATE statements.
            if (array_keys($slide->getDirty()) === ['sort_order']) {
                $reordered[$id] = $position;
                $slide->syncOriginal();
            } else {
                $slide->save();
            }
        }
        $this->writeOrder($reordered);

        $this->deck->sections = $this->sections;

        return $revs;
    }

    /**
     * @param  array<int, int>  $positions  slide id => sort_order
     */
    private function writeOrder(array $positions): void
    {
        foreach (array_chunk($positions, 400, true) as $chunk) {
            $cases = '';
            $bindings = [];
            foreach ($chunk as $id => $position) {
                $cases .= ' when ? then ?';
                $bindings[] = $id;
                $bindings[] = $position;
            }
            $ids = array_keys($chunk);
            DB::update('update slides set sort_order = case id'.$cases.' end where id in ('.implode(',', array_fill(0, count($ids), '?')).')', [...$bindings, ...$ids]);
        }
    }

    // ------------------------------------------------------------------ dispatch

    /**
     * @param  array<string, mixed>  $op
     */
    private function dispatch(string $name, array $op): void
    {
        match ($name) {
            'deck.set' => $this->deckSet($op),
            'deck.resize' => $this->deckResize($op),
            'section.add' => $this->sectionAdd($op),
            'section.set' => $this->sectionSet($op),
            'section.remove' => $this->sectionRemove($op),
            'slide.add' => $this->slideAdd($op),
            'slide.set' => $this->slideSet($op),
            'slide.move' => $this->slideMove($op),
            'slide.remove' => $this->slideRemove($op),
            'slide.dup' => $this->slideDup($op),
            'el.add' => $this->elAdd($op),
            'el.set' => $this->elSet($op),
            'el.remove' => $this->elRemove($op),
            'el.order' => $this->elOrder($op),
            'el.move' => $this->elMove($op),
            'deck.reload' => throw new InvalidDocument('deck.reload is written by the server only'),
            default => throw new InvalidDocument('unknown op'),
        };
    }

    private function skip(string $name, string $reason): void
    {
        $this->skipped[] = ['op' => $name, 'index' => $this->index, 'reason' => $reason];
    }

    /**
     * @param  array<string, mixed>  $op
     */
    private function log(array $op): void
    {
        $this->applied[] = $op;
    }

    // ------------------------------------------------------------------ deck

    /**
     * @param  array<string, mixed>  $op
     */
    private function deckSet(array $op): void
    {
        if (array_key_exists('w', $op) || array_key_exists('h', $op)) {
            throw new InvalidDocument('size changes go through deck.resize');
        }
        if (($op['status'] ?? null) === 'approved') {
            throw new InvalidDocument('a deck is approved by a reviewer, not by an operation');
        }

        $fields = $this->san->deckSet($op);

        if ($fields === []) {
            throw new InvalidDocument('no changes');
        }
        if (isset($fields['theme'])) {
            $this->assertAssets(isset($fields['theme']['bg']['img']['asset']) ? [(int) $fields['theme']['bg']['img']['asset']] : []);
        }

        $changes = [];
        foreach ($fields as $key => $value) {
            if ($this->deck->{$key} === $value) {
                continue;
            }
            if ($key === 'title') {
                $this->activity[] = ['renamed', ['from' => $this->deck->title, 'to' => $value]];
            }
            $this->deck->{$key} = $value;
            $changes[$key] = $value;
        }

        if ($changes !== []) {
            $this->deckChanged = true;
            $this->log(['op' => 'deck.set', ...$changes]);
        }
    }

    /**
     * @param  array<string, mixed>  $op
     */
    private function deckResize(array $op): void
    {
        // A slide size outside 320..7680 is refused, not clamped: a resize rewrites every element.
        $w = (int) $this->san->num($op['w'] ?? null, -1e9, 1e9, 'w');
        $h = (int) $this->san->num($op['h'] ?? null, -1e9, 1e9, 'h');
        if ($w < 320 || $w > 7680) {
            throw new InvalidDocument('invalid w');
        }
        if ($h < 320 || $h > 7680) {
            throw new InvalidDocument('invalid h');
        }
        $mode = $this->san->enum($op['mode'] ?? null, Resize::MODES, 'mode');
        $ow = (int) $this->deck->w;
        $oh = (int) $this->deck->h;

        if ($w === $ow && $h === $oh) {
            throw new InvalidDocument('size unchanged');
        }

        foreach ($this->order as $id) {
            $this->loadEls($id);
            $this->els[$id] = Resize::elements($this->els[$id], $ow, $oh, $w, $h, $mode);
            $this->bytes[$id] = strlen((string) json_encode($this->els[$id]));
            $this->touched[$id] = true;
        }

        $this->deck->theme = Resize::theme($this->deck->theme, $mode, $ow, $oh, $w, $h);
        $this->deck->w = $w;
        $this->deck->h = $h;
        $this->deckChanged = true;
        $this->activity[] = ['resized', ['from' => "{$ow}x{$oh}", 'to' => "{$w}x{$h}", 'mode' => $mode]];

        // Clients do not repeat the geometry: they reload the document after a resize.
        $this->log(['op' => 'deck.resize', 'w' => $w, 'h' => $h, 'mode' => $mode]);
        $this->log(['op' => 'deck.reload']);
    }

    // ------------------------------------------------------------------ sections

    /**
     * @param  array<string, mixed>  $op
     */
    private function sectionAdd(array $op): void
    {
        $section = $this->san->section($op + ['title' => 'Untitled section']);
        if ($this->sectionIndex($section['id']) !== null) {
            throw new InvalidDocument('section exists');
        }
        if (count($this->sections) >= DocumentSanitizer::MAX_SECTIONS) {
            throw new InvalidDocument('too many sections');
        }

        $at = count($this->sections);
        if (array_key_exists('after', $op)) {
            $at = 0;
            if ($op['after'] !== null) {
                $after = $this->sectionIndex($this->san->id($op['after'], 'section reference', 24));
                if ($after === null) {
                    throw new InvalidDocument('unknown section');
                }
                $at = $after + 1;
            }
        }

        array_splice($this->sections, $at, 0, [$section]);
        $this->deckChanged = true;
        $this->log(['op' => 'section.add', 'id' => $section['id'], 'title' => $section['title'], 'after' => $at === 0 ? null : $this->sections[$at - 1]['id']]);
    }

    /**
     * @param  array<string, mixed>  $op
     */
    private function sectionSet(array $op): void
    {
        $section = $this->san->section($op);
        $at = $this->sectionIndex($section['id']);
        if ($at === null) {
            throw new InvalidDocument('unknown section');
        }

        $this->sections[$at]['title'] = $section['title'];
        $this->deckChanged = true;
        $this->log(['op' => 'section.set', 'id' => $section['id'], 'title' => $section['title']]);
    }

    /**
     * @param  array<string, mixed>  $op
     */
    private function sectionRemove(array $op): void
    {
        $id = $this->san->id($op['id'] ?? null, 'section id', 24);
        $at = $this->sectionIndex($id);
        if ($at === null) {
            throw new InvalidDocument('unknown section');
        }

        array_splice($this->sections, $at, 1);
        foreach ($this->slides as $slideId => $slide) {
            if ($slide->section_id === $id) {
                $slide->section_id = null;
                $this->touched[$slideId] = true;
            }
        }
        $this->deckChanged = true;
        $this->log(['op' => 'section.remove', 'id' => $id]);
    }

    private function sectionIndex(string $id): ?int
    {
        foreach ($this->sections as $i => $section) {
            if (($section['id'] ?? null) === $id) {
                return $i;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ slides

    /**
     * @param  array<string, mixed>  $op
     */
    private function slideAdd(array $op): void
    {
        if (count($this->order) >= DocumentSanitizer::MAX_SLIDES) {
            throw new InvalidDocument('deck is full');
        }

        $tid = $this->san->tempId($op['tid'] ?? null);
        if (isset($this->tids[$tid])) {
            throw new InvalidDocument('duplicate tid');
        }

        $fields = $this->san->slideFieldsLenient($op['slide'] ?? []);
        $this->assertSection($fields['section_id'] ?? null);
        $this->assertSlideAssets($fields);
        $position = $this->positionAfter($op);
        $els = $fields['els'] ?? [];
        unset($fields['els']);

        $size = strlen((string) json_encode($els));
        if ($size > DocumentSanitizer::MAX_SLIDE_BYTES) {
            throw new InvalidDocument('slide too large');
        }
        $this->charge($size);

        $slide = $this->createSlide($fields, $els);
        $this->tids[$tid] = $slide->id;
        array_splice($this->order, $position, 0, [$slide->id]);

        $this->log([
            'op' => 'slide.add', 'id' => $slide->id, 'tid' => $tid,
            'after' => $position === 0 ? null : $this->order[$position - 1],
            'slide' => [...$fields, 'els' => $els],
        ]);
    }

    /**
     * @param  array<string, mixed>  $op
     */
    private function slideSet(array $op): void
    {
        $id = $this->slideId($op['id'] ?? null);
        $fields = $this->san->slideFields($op, false);
        if ($fields === []) {
            throw new InvalidDocument('no fields');
        }
        $this->assertSection($fields['section_id'] ?? null);
        $this->assertSlideAssets($fields);

        $slide = $this->slides[$id];
        foreach ($fields as $key => $value) {
            $slide->{$key} = $value;
        }
        $this->touched[$id] = true;
        $this->log(['op' => 'slide.set', 'id' => $id, ...$fields]);
    }

    /**
     * @param  array<string, mixed>  $op
     */
    private function slideMove(array $op): void
    {
        $id = $this->slideId($op['id'] ?? null);
        if (! array_key_exists('after', $op)) {
            throw new InvalidDocument('after is required');
        }
        $after = $op['after'] === null ? null : $this->knownSlideId($op['after']);
        if ($after === $id) {
            throw new InvalidDocument('cannot move a slide after itself');
        }

        $this->order = array_values(array_filter($this->order, fn ($v) => $v !== $id));
        $position = $op['after'] === null ? 0 : ($after === null ? count($this->order) : array_search($after, $this->order, true) + 1);
        array_splice($this->order, $position, 0, [$id]);

        $this->log(['op' => 'slide.move', 'id' => $id, 'after' => $after]);
    }

    /**
     * @param  array<string, mixed>  $op
     */
    private function slideRemove(array $op): void
    {
        $id = $this->slideId($op['id'] ?? null);
        if (count($this->order) <= 1) {
            throw new InvalidDocument('a deck keeps at least one slide');
        }

        $this->order = array_values(array_filter($this->order, fn ($v) => $v !== $id));
        $this->removed[] = $id;
        unset($this->slides[$id], $this->els[$id], $this->touched[$id], $this->bytes[$id]);

        $this->log(['op' => 'slide.remove', 'id' => $id]);
    }

    /**
     * @param  array<string, mixed>  $op
     */
    private function slideDup(array $op): void
    {
        if (count($this->order) >= DocumentSanitizer::MAX_SLIDES) {
            throw new InvalidDocument('deck is full');
        }

        $source = $this->slideId($op['id'] ?? null);
        $tid = $this->san->tempId($op['tid'] ?? null);
        if (isset($this->tids[$tid])) {
            throw new InvalidDocument('duplicate tid');
        }

        if (array_key_exists('after', $op)) {
            $position = $this->positionAfter($op);
        } else {
            $position = array_search($source, $this->order, true) + 1;
        }

        $this->loadEls($source);
        [$els, $groupMap] = [[], []];
        $used = [];
        foreach ($this->els[$source] as $el) {
            do {
                $newId = Str::lower(Str::random(10));
            } while (isset($used[$newId]));
            $used[$newId] = true;

            // The copy keeps morphing with the original: its morph key becomes the original's key.
            $el['morph'] ??= $el['id'];
            $el['id'] = $newId;
            if (isset($el['group'])) {
                $groupMap[$el['group']] ??= Str::lower(Str::random(10));
                $el['group'] = $groupMap[$el['group']];
            }
            $els[] = $el;
        }

        $this->charge(strlen((string) json_encode($els)));

        $from = $this->slides[$source];
        $fields = [
            'title' => $from->title, 'notes' => $from->notes, 'layout' => $from->layout, 'bg' => $from->bg,
            'transition' => $from->transition, 'section_id' => $from->section_id, 'hidden' => (bool) $from->hidden,
        ];

        $slide = $this->createSlide($fields, $els);
        $this->tids[$tid] = $slide->id;
        array_splice($this->order, $position, 0, [$slide->id]);

        $this->log([
            'op' => 'slide.add', 'id' => $slide->id, 'tid' => $tid,
            'after' => $position === 0 ? null : $this->order[$position - 1],
            'slide' => [...$fields, 'els' => $els],
        ]);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  list<array<string, mixed>>  $els
     */
    private function createSlide(array $fields, array $els): Slide
    {
        $slide = new Slide;
        $slide->deck_id = $this->deck->id;
        $slide->title = $fields['title'] ?? null;
        $slide->notes = $fields['notes'] ?? null;
        $slide->layout = $fields['layout'] ?? 'blank';
        $slide->bg = $fields['bg'] ?? null;
        $slide->transition = $fields['transition'] ?? null;
        $slide->section_id = $fields['section_id'] ?? null;
        $slide->hidden = (bool) ($fields['hidden'] ?? false);
        $slide->sort_order = count($this->order);
        $slide->revision = 0;
        $slide->els = $els;
        $slide->save();

        $this->slides[$slide->id] = $slide;
        $this->els[$slide->id] = $els;
        $this->bytes[$slide->id] = strlen((string) json_encode($els));
        $this->touched[$slide->id] = true;

        return $slide;
    }

    /**
     * Where a new or moved slide goes: absent `after` means the end, null means the start.
     *
     * @param  array<string, mixed>  $op
     */
    private function positionAfter(array $op): int
    {
        if (! array_key_exists('after', $op)) {
            return count($this->order);
        }
        if ($op['after'] === null) {
            return 0;
        }

        // A slide that is gone (removed by someone else meanwhile) is not a reason to lose the new one: it goes last.
        $found = $this->knownSlideId($op['after']);

        return $found === null ? count($this->order) : array_search($found, $this->order, true) + 1;
    }

    /**
     * The stored id a reference points at, or null when it names nothing (a malformed reference still throws).
     */
    private function knownSlideId(mixed $ref): ?int
    {
        $ref = $this->san->slideRef($ref);
        $id = is_string($ref) ? ($this->tids[$ref] ?? null) : $ref;

        return $id !== null && isset($this->slides[$id]) ? $id : null;
    }

    private function slideId(mixed $ref): int
    {
        $ref = $this->san->slideRef($ref);
        $id = is_string($ref) ? ($this->tids[$ref] ?? null) : $ref;

        if ($id === null || ! isset($this->slides[$id])) {
            throw new InvalidDocument('unknown slide');
        }

        return $id;
    }

    private function assertSection(?string $id): void
    {
        if ($id !== null && $this->sectionIndex($id) === null) {
            throw new InvalidDocument('unknown section');
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function assertSlideAssets(array $fields): void
    {
        $ids = [];
        if (isset($fields['bg']['img']['asset'])) {
            $ids[] = (int) $fields['bg']['img']['asset'];
        }
        foreach ($fields['els'] ?? [] as $el) {
            array_push($ids, ...$this->san->assetIds($el));
        }
        $this->assertAssets($ids);
    }

    /**
     * @param  list<int>  $ids
     */
    private function assertAssets(array $ids): void
    {
        $ids = array_values(array_unique($ids));
        $unknown = array_values(array_filter($ids, fn ($id) => ! isset($this->assetChecks[$id])));

        if ($unknown !== []) {
            $ok = ($this->assetsAllowed)($unknown);
            foreach ($unknown as $id) {
                $this->assetChecks[$id] = $ok;
            }
            if (! $ok && count($unknown) > 1) {
                // One id of the group is foreign; check them singly so the good ones stay usable later.
                foreach ($unknown as $id) {
                    $this->assetChecks[$id] = ($this->assetsAllowed)([$id]);
                }
            }
        }

        foreach ($ids as $id) {
            if (! $this->assetChecks[$id]) {
                throw new InvalidDocument('unknown asset');
            }
        }
    }

    // ------------------------------------------------------------------ elements

    /**
     * @param  array<string, mixed>  $op
     */
    private function elAdd(array $op): void
    {
        $slideId = $this->slideId($op['slide'] ?? null);
        $this->loadEls($slideId);

        if (count($this->els[$slideId]) >= DocumentSanitizer::MAX_ELEMENTS) {
            throw new InvalidDocument('slide is full');
        }

        $el = $this->san->element($op['el'] ?? null);
        if ($this->elementIndex($slideId, $el['id']) !== null) {
            throw new InvalidDocument('duplicate element id');
        }
        $this->assertAssets($this->san->assetIds($el));
        $this->grow($slideId, strlen((string) json_encode($el)) + 1);

        $at = count($this->els[$slideId]);
        if (isset($op['index'])) {
            $at = (int) $this->san->num($op['index'], 0, $at, 'index');
        }
        array_splice($this->els[$slideId], $at, 0, [$el]);
        $this->touched[$slideId] = true;

        $this->log(['op' => 'el.add', 'slide' => $slideId, 'el' => $el, 'index' => $at]);
    }

    /**
     * @param  array<string, mixed>  $op
     */
    private function elSet(array $op): void
    {
        $slideId = $this->slideId($op['slide'] ?? null);
        $this->loadEls($slideId);

        $id = $this->san->id($op['id'] ?? null, 'element id');
        $at = $this->elementIndex($slideId, $id);
        if ($at === null) {
            throw new InvalidDocument('element not found');
        }

        $old = $this->els[$slideId][$at];
        $patch = $this->san->patch($old['type'], $op['p'] ?? null);
        $new = $patch['set'] + $old;
        foreach ($patch['unset'] as $key) {
            unset($new[$key]);
        }
        $new = $this->san->finish($new);

        $this->assertAssets($this->san->assetIds($new));
        $this->grow($slideId, strlen((string) json_encode($new)) - strlen((string) json_encode($old)));

        $this->els[$slideId][$at] = $new;
        $this->touched[$slideId] = true;

        $this->log([
            'op' => 'el.set', 'slide' => $slideId, 'id' => $id,
            'p' => $patch['set'] + array_fill_keys($patch['unset'], null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $op
     */
    private function elRemove(array $op): void
    {
        $slideId = $this->slideId($op['slide'] ?? null);
        $this->loadEls($slideId);

        $id = $this->san->id($op['id'] ?? null, 'element id');
        $at = $this->elementIndex($slideId, $id);
        if ($at === null) {
            throw new InvalidDocument('element not found');
        }

        $this->grow($slideId, -strlen((string) json_encode($this->els[$slideId][$at])), false);
        array_splice($this->els[$slideId], $at, 1);
        $this->touched[$slideId] = true;

        $this->log(['op' => 'el.remove', 'slide' => $slideId, 'id' => $id]);
    }

    /**
     * @param  array<string, mixed>  $op
     */
    private function elOrder(array $op): void
    {
        $slideId = $this->slideId($op['slide'] ?? null);
        $this->loadEls($slideId);

        $ids = $op['ids'] ?? null;
        if (! is_array($ids) || ! array_is_list($ids) || count($ids) > DocumentSanitizer::MAX_ELEMENTS) {
            throw new InvalidDocument('ids must be a list');
        }

        $byId = [];
        foreach ($this->els[$slideId] as $el) {
            $byId[$el['id']] = $el;
        }

        $new = [];
        foreach ($ids as $id) {
            if (is_string($id) && isset($byId[$id])) {
                $new[$id] = $byId[$id];
            }
        }
        foreach ($byId as $id => $el) {
            $new[$id] ??= $el;
        }

        $newOrder = array_keys($new);
        if ($newOrder === array_column($this->els[$slideId], 'id')) {
            return;
        }

        $this->els[$slideId] = array_values($new);
        $this->touched[$slideId] = true;
        $this->log(['op' => 'el.order', 'slide' => $slideId, 'ids' => $newOrder]);
    }

    /**
     * @param  array<string, mixed>  $op
     */
    private function elMove(array $op): void
    {
        $from = $this->slideId($op['from'] ?? null);
        $to = $this->slideId($op['to'] ?? null);
        if ($from === $to) {
            $this->elMoveWithin($from, $op);

            return;
        }
        $this->loadEls($from);
        $this->loadEls($to);

        $id = $this->san->id($op['id'] ?? null, 'element id');
        $at = $this->elementIndex($from, $id);
        if ($at === null) {
            throw new InvalidDocument('element not found');
        }
        if ($this->elementIndex($to, $id) !== null) {
            throw new InvalidDocument('duplicate element id on target');
        }
        if (count($this->els[$to]) >= DocumentSanitizer::MAX_ELEMENTS) {
            throw new InvalidDocument('slide is full');
        }

        $el = $this->els[$from][$at];
        $size = strlen((string) json_encode($el)) + 1;
        $this->grow($to, $size);

        $position = count($this->els[$to]);
        if (isset($op['index'])) {
            $position = (int) $this->san->num($op['index'], 0, $position, 'index');
        }

        array_splice($this->els[$from], $at, 1);
        array_splice($this->els[$to], $position, 0, [$el]);
        $this->grow($from, -$size, false);
        $this->touched[$from] = true;
        $this->touched[$to] = true;

        $this->log(['op' => 'el.move', 'from' => $from, 'id' => $id, 'to' => $to, 'index' => $position]);
    }

    /**
     * el.move with the same slide for both ends is a z-order move; the index counts after the element is taken out.
     *
     * @param  array<string, mixed>  $op
     */
    private function elMoveWithin(int $slideId, array $op): void
    {
        $this->loadEls($slideId);
        $id = $this->san->id($op['id'] ?? null, 'element id');
        $at = $this->elementIndex($slideId, $id);
        if ($at === null) {
            throw new InvalidDocument('element not found');
        }

        $el = $this->els[$slideId][$at];
        array_splice($this->els[$slideId], $at, 1);
        $position = count($this->els[$slideId]);
        if (isset($op['index'])) {
            $position = (int) $this->san->num($op['index'], 0, $position, 'index');
        }
        array_splice($this->els[$slideId], $position, 0, [$el]);

        if ($position !== $at) {
            $this->touched[$slideId] = true;
            $this->log(['op' => 'el.move', 'from' => $slideId, 'id' => $id, 'to' => $slideId, 'index' => $position]);
        }
    }

    // ------------------------------------------------------------------ element storage

    private function loadEls(int $id): void
    {
        if (isset($this->els[$id])) {
            return;
        }

        $raw = DB::table('slides')->where('id', $id)->value('els');
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        $this->bytes[$id] = is_string($raw) ? strlen($raw) : 2;
        $this->els[$id] = is_array($decoded) ? array_values($decoded) : [];
    }

    private function elementIndex(int $slideId, string $elementId): ?int
    {
        foreach ($this->els[$slideId] as $i => $el) {
            if (($el['id'] ?? null) === $elementId) {
                return $i;
            }
        }

        return null;
    }

    private function grow(int $slideId, int $delta, bool $enforce = true): void
    {
        $next = ($this->bytes[$slideId] ?? 2) + $delta;
        if ($enforce && $delta > 0 && $next > DocumentSanitizer::MAX_SLIDE_BYTES) {
            throw new InvalidDocument('slide too large');
        }
        if ($enforce && $delta > 0) {
            $this->charge($delta);
        }
        $this->bytes[$slideId] = max(2, $next);
    }

    /**
     * Content added by one request is budgeted, and so is the deck: a few hundred bytes of operations (200 copies of one
     * fat slide) must not write hundreds of megabytes or exhaust the memory of the process. Removing and shrinking is
     * never charged, so a deck that hit its cap can always be cut down again. The ops that were not paid for are skipped
     * and reported like any other refused op.
     */
    private function charge(int $bytes): void
    {
        if ($bytes <= 0) {
            return;
        }

        if ($this->grown + $bytes > (int) config('security.press_batch_growth_bytes', 8 * 1024 * 1024)) {
            throw new InvalidDocument('batch too large');
        }

        $this->deckBytes ??= (int) DB::table('slides')->where('deck_id', $this->deck->id)->sum(DB::raw('length(els)'));

        if ($this->deckBytes + $this->grown + $bytes > (int) config('security.press_deck_bytes', 32 * 1024 * 1024)) {
            throw new InvalidDocument('deck too large');
        }

        $this->grown += $bytes;
    }
}
