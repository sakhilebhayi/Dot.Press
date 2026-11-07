<?php

namespace App\Services\Press\Import;

use App\Models\Deck;
use App\Models\Project;
use App\Models\User;
use App\Services\Press\ActivityRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Throwable;

/**
 * Turns an ImportResult into a Deck and its Slides, owned by the importing user, in their current team's workspace project.
 * Pictures go through the asset pipeline (App\Services\Press\Assets\AssetStore) when it exists; without it they are
 * skipped and the report says so.
 */
final class ImportedDeckBuilder
{
    private const ASSET_STORE = 'App\\Services\\Press\\Assets\\AssetStore';

    /**
     * @return array{deck: Deck, report: list<array{slide: ?int, element: ?string, kind: string, message: string}>}
     */
    public static function build(User $user, ImportResult $result, ?string $fileName = null): array
    {
        $team = $user->currentTeam;
        if ($team === null) {
            throw new RuntimeException('Choose a team before importing a presentation.');
        }
        $project = Project::firstOrCreate(
            ['team_id' => $team->id, 'name' => 'Dot.Press Workspace'],
            ['user_id' => $user->id, 'slug' => 'dot-press-workspace', 'settings' => ['theme' => 'default']],
        );
        Gate::forUser($user)->authorize('create', [Deck::class, $project]);

        self::storeMedia($user, $project, $result);

        $report = [];
        $deck = DB::transaction(function () use ($user, $project, $result, $fileName, &$report): Deck {
            $d = $result->deck;
            $title = trim((string) ($d['title'] ?? ''));
            if ($title === '' || $title === 'Imported presentation') {
                $title = $fileName !== null && $fileName !== '' ? self::titleFromFile($fileName) : 'Imported presentation';
            }
            $deck = Deck::create([
                'project_id' => $project->id,
                'user_id' => $user->id,
                'title' => mb_substr($title, 0, 255),
                'theme' => $d['theme'] ?? null,
                'sort_order' => (int) ($project->decks()->max('sort_order') ?? -1) + 1,
                'w' => (int) ($d['w'] ?? 1920),
                'h' => (int) ($d['h'] ?? 1080),
                'status' => 'draft',
                'visibility' => 'private',
                'rev' => 0,
                'sections' => $d['sections'] ?? [],
            ]);
            $ids = [];
            $slideModels = [];
            foreach ($result->slides as $i => $s) {
                $slide = $deck->slides()->create([
                    'title' => mb_substr((string) ($s['title'] ?? 'Slide '.($i + 1)), 0, 255),
                    'notes' => $s['notes'] !== '' ? $s['notes'] : null,
                    'layout' => 'blank',
                    'sort_order' => $i,
                    'els' => [],
                    'bg' => $s['bg'] ?? null,
                    'transition' => $s['transition'] ?? null,
                    'section_id' => $s['section_id'] ?? null,
                    'hidden' => (bool) ($s['hidden'] ?? false),
                ]);
                $ids[(string) $s['id']] = (string) $slide->id;
                $slideModels[] = [$slide, $s];
            }
            // Second pass: slide links point at the new ids, and the elements are stored.
            foreach ($slideModels as $n => [$slide, $s]) {
                $els = self::sanitize(self::remapLinks($s['els'], $ids), $n + 1, $report);
                if (strlen((string) json_encode($els)) > 2 * 1048576) {
                    $els = array_slice($els, 0, 150);
                }
                $slide->forceFill(['els' => $els])->save();
            }

            return $deck;
        });

        ActivityRecorder::record($deck, $user, 'imported', ['slides' => count($result->slides), 'from' => 'pptx']);

        return ['deck' => $deck, 'report' => array_merge($result->report, $report)];
    }

    /**
     * The document rules (App\Services\Press\DocumentSanitizer, when it is there) have the last word: an element they
     * refuse is left out and reported, one they accept is stored in their cleaned form.
     *
     * @param  list<array<string, mixed>>  $els
     * @param  list<array{slide: ?int, element: ?string, kind: string, message: string}>  $report
     * @return list<array<string, mixed>>
     */
    private static function sanitize(array $els, int $slideNo, array &$report): array
    {
        $class = 'App\\Services\\Press\\DocumentSanitizer';
        if (! class_exists($class)) {
            return $els;
        }
        $san = new $class;
        $out = [];
        foreach ($els as $el) {
            try {
                $out[] = $san->element($el);
            } catch (Throwable $e) {
                $report[] = ['slide' => $slideNo, 'element' => $el['id'] ?? null, 'kind' => 'unsupported', 'message' => 'An element was left out because it breaks the document rules ('.mb_substr($e->getMessage(), 0, 80).').'];
            }
        }

        return $out;
    }

    private static function titleFromFile(string $name): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[\x00-\x1F]+/', ' ', $base));

        return $base !== '' ? $base : 'Imported presentation';
    }

    private static function storeMedia(User $user, Project $project, ImportResult $result): void
    {
        if ($result->media === []) {
            return;
        }
        if (! class_exists(self::ASSET_STORE)) {
            // No asset pipeline: drop the picture elements and say so.
            foreach ($result->slides as $si => &$slide) {
                $slide['els'] = array_values(array_filter($slide['els'], fn ($el) => ! (in_array($el['type'] ?? null, ['image'], true) && isset($el['src']['import']))));
                if (is_array($slide['bg'] ?? null) && isset($slide['bg']['img'])) {
                    $slide['bg'] = null;
                }
            }
            unset($slide);
            $result->report[] = ['slide' => null, 'element' => null, 'kind' => 'unsupported', 'message' => 'Pictures were not imported because asset storage is not available.'];
            $result->media = [];

            return;
        }
        $store = self::ASSET_STORE;
        $result->withAssets(function (string $bytes, string $mime, string $name) use ($store, $user, $project): ?int {
            try {
                return (int) $store::storeBytes($project, $user, $bytes, $name, $mime)->id;
            } catch (Throwable) {
                return null;
            }
        });
    }

    /**
     * @param  list<array<string, mixed>>  $els
     * @param  array<string, string>  $ids
     * @return list<array<string, mixed>>
     */
    private static function remapLinks(array $els, array $ids): array
    {
        $map = function (?string $href) use ($ids): ?string {
            if ($href !== null && str_starts_with($href, '#slide:')) {
                $old = substr($href, 7);

                return isset($ids[$old]) ? '#slide:'.$ids[$old] : null;
            }

            return $href;
        };
        $walk = function (array $node) use (&$walk, $map): array {
            if (($node['type'] ?? null) === 'text' && isset($node['marks'])) {
                $marks = [];
                foreach ($node['marks'] as $m) {
                    if (($m['type'] ?? null) === 'link') {
                        $h = $map($m['attrs']['href'] ?? null);
                        if ($h === null) {
                            continue;
                        }
                        $m['attrs']['href'] = $h;
                    }
                    $marks[] = $m;
                }
                $node['marks'] = $marks;
                if ($marks === []) {
                    unset($node['marks']);
                }
            }
            if (isset($node['content']) && is_array($node['content'])) {
                $node['content'] = array_map($walk, $node['content']);
            }

            return $node;
        };
        foreach ($els as &$el) {
            if (isset($el['link'])) {
                $h = $map($el['link']);
                if ($h === null) {
                    unset($el['link']);
                } else {
                    $el['link'] = $h;
                }
            }
            if (isset($el['doc']) && is_array($el['doc'])) {
                $el['doc'] = $walk($el['doc']);
            }
        }
        unset($el);

        return $els;
    }
}
