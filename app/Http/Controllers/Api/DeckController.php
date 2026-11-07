<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deck;
use App\Models\Project;
use App\Models\Slide;
use App\Services\Press\ActivityRecorder;
use App\Services\Press\DeckAccess;
use App\Services\Press\DeckCloner;
use App\Services\Press\DeckDocument;
use App\Services\Press\DocumentSanitizer;
use App\Services\Press\InvalidDocument;
use App\Services\Press\OpApplier;
use App\Services\Press\TemplateLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DeckController extends Controller
{
    public const SIZES = ['16:9' => [1920, 1080], '4:3' => [1440, 1080], 'portrait' => [1080, 1920]];

    public const FILTERS = ['mine', 'team', 'shared', 'favourites', 'recent', 'archived', 'templates'];

    /** The library card thumbnail: at most this many bytes of the first slide's elements. */
    private const PREVIEW_BYTES = 60000;

    /**
     * The library. Team scoping comes from Deck's own global scope (every team the person belongs to); the filters
     * narrow it further. Private decks only show for their owner and for people they were shared with.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Deck::class);

        $v = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'filter' => ['nullable', Rule::in(self::FILTERS)],
            'sort' => ['nullable', Rule::in(['updated', 'title', 'created'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:60'],
            'project_id' => ['nullable', 'integer'],
        ]);

        $user = $request->user();
        $filter = $v['filter'] ?? null;

        $query = Deck::query()
            ->leftJoin('deck_user as du', function ($join) use ($user): void {
                $join->on('du.deck_id', '=', 'decks.id')->where('du.user_id', '=', $user->id);
            })
            ->select('decks.*', 'du.favourite', 'du.last_opened_at')
            ->withCount(['slides' => fn ($q) => $q->withoutGlobalScopes()])
            ->with('owner:id,name');

        $sharedWithMe = DB::table('deck_shares')->select('deck_id')->where('user_id', $user->id);
        $query->where(function ($q) use ($user, $sharedWithMe): void {
            $q->where('decks.visibility', '!=', 'private')
                ->orWhere('decks.user_id', $user->id)
                ->orWhereIn('decks.id', $sharedWithMe);
        });

        if ($filter === 'archived') {
            $query->whereNotNull('decks.archived_at');
        } elseif ($filter === 'templates') {
            $query->whereNull('decks.archived_at')->where('decks.is_template', true);
        } else {
            $query->whereNull('decks.archived_at')->where('decks.is_template', false);
        }

        match ($filter) {
            'mine' => $query->where('decks.user_id', $user->id),
            'team' => $query->whereIn('decks.project_id', Project::withoutGlobalScopes()->where('team_id', $user->current_team_id)->select('id')),
            'shared' => $query->where(function ($q) use ($user, $sharedWithMe): void {
                $q->whereNull('decks.user_id')->orWhere('decks.user_id', '!=', $user->id)->orWhereIn('decks.id', $sharedWithMe);
            }),
            'favourites' => $query->where('du.favourite', true),
            'recent' => $query->whereNotNull('du.last_opened_at'),
            default => null,
        };

        if (isset($v['project_id'])) {
            $project = Project::findOrFail($v['project_id']);
            $this->authorize('view', $project);
            $query->where('decks.project_id', $project->id);
        }

        if (filled($v['q'] ?? null)) {
            $needle = '%'.addcslashes(trim($v['q']), '\\%_').'%';
            $query->whereRaw("decks.title like ? escape '\\'", [$needle]);
        }

        if ($filter === 'recent') {
            $query->orderByDesc('du.last_opened_at');
        } else {
            match ($v['sort'] ?? 'updated') {
                'title' => $query->selectRaw('lower(decks.title) as title_sort')->orderBy('title_sort'),
                'created' => $query->orderByDesc('decks.created_at'),
                default => $query->orderByDesc('decks.updated_at'),
            };
        }
        $ascending = ($v['sort'] ?? null) === 'title' && $filter !== 'recent';
        $query->orderBy('decks.id', $ascending ? 'asc' : 'desc');

        $page = $query->cursorPaginate((int) ($v['per_page'] ?? 24))->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn (Deck $deck) => $this->row($deck))->values(),
            'next_cursor' => $page->nextCursor()?->encode(),
            'per_page' => $page->perPage(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Deck::class);

        $v = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'project_id' => ['nullable', 'integer'],
            'size' => ['nullable', Rule::in([...array_keys(self::SIZES), 'custom'])],
            'w' => ['nullable', 'integer', 'min:320', 'max:7680', 'required_if:size,custom'],
            'h' => ['nullable', 'integer', 'min:320', 'max:7680', 'required_if:size,custom'],
            'theme' => ['nullable', 'array'],
            'template' => ['nullable', 'string', 'max:60'],
        ]);

        $user = $request->user();

        if (isset($v['project_id'])) {
            $project = Project::findOrFail($v['project_id']);
            $this->authorize('create', [Deck::class, $project]);
        } else {
            $this->authorize('create', Project::class);
            abort_if($user->currentTeam === null, 422, 'Pick a team first.');
            $project = Project::firstOrCreate(
                ['team_id' => $user->currentTeam->id, 'name' => 'Dot.Press Workspace'],
                ['user_id' => $user->id, 'slug' => 'dot-press-workspace', 'settings' => ['theme' => 'default']],
            );
        }

        [$w, $h] = ($v['size'] ?? '16:9') === 'custom' ? [(int) $v['w'], (int) $v['h']] : self::SIZES[$v['size'] ?? '16:9'];

        $sanitizer = new DocumentSanitizer;
        try {
            $theme = isset($v['theme']) ? $sanitizer->theme($v['theme']) : DocumentSanitizer::defaultTheme();
        } catch (InvalidDocument $e) {
            return response()->json(['message' => 'The theme is not valid: '.$e->getMessage()], 422);
        }

        $built = null;
        if (isset($v['template']) && class_exists(TemplateLibrary::class) && method_exists(TemplateLibrary::class, 'build')) {
            $built = app(TemplateLibrary::class)->build($v['template'], $w, $h);
            if (is_array($built['theme'] ?? null) && ! isset($v['theme'])) {
                $theme = $sanitizer->theme($built['theme']);
            }
        }

        $deck = DB::transaction(function () use ($project, $user, $v, $w, $h, $theme, $built, $sanitizer): Deck {
            $deck = Deck::create([
                'project_id' => $project->id,
                'user_id' => $user->id,
                'title' => trim($v['title']),
                'theme' => $theme,
                'sort_order' => (int) $project->decks()->max('sort_order') + 1,
                'w' => $w,
                'h' => $h,
                'sections' => [],
            ]);

            $slides = is_array($built['slides'] ?? null) && $built['slides'] !== [] ? $built['slides'] : [[]];
            foreach (array_values($slides) as $position => $raw) {
                $fields = $sanitizer->slideFields($raw);
                $slide = new Slide;
                $slide->deck_id = $deck->id;
                $slide->title = $fields['title'] ?? null;
                $slide->notes = $fields['notes'] ?? null;
                $slide->layout = $fields['layout'] ?? 'blank';
                $slide->bg = $fields['bg'] ?? null;
                $slide->transition = $fields['transition'] ?? null;
                $slide->hidden = (bool) ($fields['hidden'] ?? false);
                $slide->sort_order = $position;
                $slide->revision = 0;
                $slide->els = $fields['els'] ?? [];
                $slide->save();
            }

            ActivityRecorder::record($deck, $user, 'created', array_filter(['template' => $v['template'] ?? null]));

            return $deck;
        });

        return response()->json($this->detail($deck->fresh(), $user->id), 201);
    }

    public function show(Request $request, Deck $deck): JsonResponse
    {
        $this->authorize('view', $deck);

        return response()->json($this->detail($deck, $request->user()->id));
    }

    /**
     * The title is the only deck field with its own endpoint; everything else changes through operations. The rename
     * itself is an operation, so other editors see it in the change log.
     */
    public function update(Request $request, Deck $deck, OpApplier $applier): JsonResponse
    {
        $this->authorizeDeck($request, $deck, 'rename', 'update');

        $v = $request->validate(['title' => ['required', 'string', 'max:255']]);

        $applier->apply($deck, $request->user(), 'api', 'api-'.Str::uuid(), [['op' => 'deck.set', 'title' => $v['title']]]);

        return response()->json($this->detail($deck->fresh(), $request->user()->id));
    }

    public function duplicate(Request $request, Deck $deck, DeckCloner $cloner): JsonResponse
    {
        $this->authorize('view', $deck);
        $this->authorize('create', [Deck::class, $deck->project]);

        $copy = $cloner->duplicate($deck, $request->user());

        return response()->json($this->detail($copy->fresh(), $request->user()->id), 201);
    }

    /**
     * Archives by default. `?permanent=1` deletes for good and only works on a deck that is already archived.
     */
    public function destroy(Request $request, Deck $deck)
    {
        $this->authorizeDeck($request, $deck, 'manage', 'delete');

        if ($request->boolean('permanent')) {
            if ($deck->archived_at === null) {
                return response()->json(['message' => 'Archive the deck first, then delete it for good.'], 422);
            }
            $deck->delete();

            return response()->noContent();
        }

        if ($deck->archived_at === null) {
            $deck->archived_at = now();
            $deck->save();
            ActivityRecorder::record($deck, $request->user(), 'archived');
        }

        return response()->noContent();
    }

    public function unarchive(Request $request, Deck $deck): JsonResponse
    {
        $this->authorize('update', $deck);

        if ($deck->archived_at !== null) {
            $deck->archived_at = null;
            $deck->save();
            ActivityRecorder::record($deck, $request->user(), 'unarchived');
        }

        return response()->json($this->detail($deck->fresh(), $request->user()->id));
    }

    /**
     * Favourites are a personal list, so anyone who may read the deck may star it.
     */
    public function favourite(Request $request, Deck $deck): JsonResponse
    {
        $this->authorize('view', $deck);

        $current = (bool) DB::table('deck_user')->where('deck_id', $deck->id)->where('user_id', $request->user()->id)->value('favourite');
        $next = $request->has('favourite') ? $request->boolean('favourite') : ! $current;

        DB::table('deck_user')->updateOrInsert(
            ['deck_id' => $deck->id, 'user_id' => $request->user()->id],
            ['favourite' => $next],
        );

        return response()->json(['favourite' => $next]);
    }

    /**
     * Records that the person opened the deck (the Recent list).
     */
    public function touch(Request $request, Deck $deck): JsonResponse
    {
        $this->authorize('view', $deck);

        DB::table('deck_user')->updateOrInsert(
            ['deck_id' => $deck->id, 'user_id' => $request->user()->id],
            ['last_opened_at' => now()],
        );

        return response()->json(['ok' => true]);
    }

    /**
     * Deck-level abilities come from DeckAccess when the access layer is present (rename, manage); until then the
     * team policy's update and delete abilities stand in.
     */
    private function authorizeDeck(Request $request, Deck $deck, string $ability, string $fallback): void
    {
        if (class_exists(DeckAccess::class) && method_exists(DeckAccess::class, 'can')) {
            abort_unless(DeckAccess::can($request->user(), $deck, $ability), 403);

            return;
        }

        $this->authorize($fallback, $deck);
    }

    // ------------------------------------------------------------------ presenters

    /**
     * @return array<string, mixed>
     */
    private function row(Deck $deck): array
    {
        $first = Slide::withoutGlobalScopes()->where('deck_id', $deck->id)->orderBy('sort_order')->orderBy('id')->first(['id', 'bg', 'els']);

        return [
            'id' => $deck->id,
            'title' => $deck->title,
            'w' => (int) $deck->w,
            'h' => (int) $deck->h,
            'status' => DeckDocument::status($deck),
            'visibility' => $deck->visibility,
            'theme' => $deck->theme,
            'slides' => (int) $deck->slides_count,
            'owner' => $deck->owner?->name,
            'favourite' => (bool) $deck->getAttribute('favourite'),
            'last_opened_at' => $this->iso($deck->getAttribute('last_opened_at')),
            'updated_at' => $deck->updated_at?->toIso8601String(),
            'archived_at' => $deck->archived_at?->toIso8601String(),
            'is_template' => (bool) $deck->is_template,
            'first_slide' => $first?->id,
            'preview' => $first ? ['bg' => $first->bg, 'els' => $this->previewElements($first->els ?? [])] : ['bg' => null, 'els' => []],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $els
     * @return list<array<string, mixed>>
     */
    private function previewElements(array $els): array
    {
        $out = [];
        $bytes = 2;
        foreach ($els as $el) {
            if (! empty($el['hide'])) {
                continue;
            }
            $size = strlen((string) json_encode($el)) + 1;
            if ($bytes + $size > self::PREVIEW_BYTES) {
                continue;
            }
            $bytes += $size;
            $out[] = $el;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Deck $deck, int $userId): array
    {
        $deck->loadMissing('owner:id,name');
        $slides = Slide::withoutGlobalScopes()->where('deck_id', $deck->id)->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'title', 'layout', 'hidden', 'section_id', 'revision']);
        $pivot = DB::table('deck_user')->where('deck_id', $deck->id)->where('user_id', $userId)->first();

        return [
            'id' => $deck->id,
            'project_id' => $deck->project_id,
            'title' => $deck->title,
            'w' => (int) $deck->w,
            'h' => (int) $deck->h,
            'theme' => $deck->theme,
            'rev' => (int) $deck->rev,
            'status' => DeckDocument::status($deck),
            'visibility' => $deck->visibility,
            'sections' => $deck->sections ?? [],
            'is_template' => (bool) $deck->is_template,
            'owner' => $deck->owner ? ['id' => $deck->owner->id, 'name' => $deck->owner->name] : null,
            'favourite' => (bool) ($pivot->favourite ?? false),
            'last_opened_at' => $this->iso($pivot->last_opened_at ?? null),
            'archived_at' => $deck->archived_at?->toIso8601String(),
            'created_at' => $deck->created_at?->toIso8601String(),
            'updated_at' => $deck->updated_at?->toIso8601String(),
            'slides' => $slides->map(fn (Slide $s) => [
                'id' => $s->id, 'title' => $s->title, 'layout' => $s->layout, 'hidden' => (bool) $s->hidden,
                'section_id' => $s->section_id, 'rev' => (int) $s->revision,
            ])->all(),
        ];
    }

    private function iso(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->toIso8601String();
    }
}
