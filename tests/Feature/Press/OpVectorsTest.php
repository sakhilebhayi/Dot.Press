<?php

namespace Tests\Feature\Press;

use App\Services\Press\DeckDocument;
use App\Services\Press\OpApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Runs the shared op vectors (tests/fixtures/press-ops, written for the JS editor core from the same spec) through
 * OpApplier. Vector slide ids are strings; the server's are integers, so ids are translated in both directions and
 * new slides are matched to the vector through their temporary ids.
 */
class OpVectorsTest extends TestCase
{
    use MakesDecks;
    use RefreshDatabase;

    /** Ops fields that hold a slide reference, per op. */
    private const SLIDE_FIELDS = [
        'slide.add' => ['after'],
        'slide.dup' => ['id', 'after'],
        'slide.move' => ['id', 'after'],
        'slide.remove' => ['id'],
        'slide.set' => ['id'],
        'el.add' => ['slide'],
        'el.set' => ['slide'],
        'el.remove' => ['slide'],
        'el.order' => ['slide'],
        'el.move' => ['from', 'to'],
    ];

    /**
     * Vectors the server disagrees with on purpose; each is wrong by the spec and is reported to the JS agent.
     * The test skips them (it never edits the vector files) so a fixed vector starts running by deleting its line.
     */
    private const DISPUTED = [
        'el-set-replaces-nested-whole' => 'uses the colour "#fff"; the spec allows #rrggbb or #rrggbbaa (the server writes #ffffff)',
        'slide-set-several-fields' => 'sets section_id "x1" on a deck whose sections list is empty; the section must exist',
    ];

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function vectors(): array
    {
        $out = [];
        foreach (glob(dirname(__DIR__, 2).'/fixtures/press-ops/*.json') as $file) {
            $vector = json_decode((string) file_get_contents($file), true);
            $out[$vector['name']] = [$vector];
        }
        ksort($out);

        return $out;
    }

    public function test_the_fixture_set_is_present(): void
    {
        $this->assertGreaterThanOrEqual(79, count(self::vectors()));
    }

    #[DataProvider('vectors')]
    public function test_vector(array $vector): void
    {
        if (isset(self::DISPUTED[$vector['name']])) {
            $this->markTestSkipped('Disputed vector: '.self::DISPUTED[$vector['name']]);
        }

        $user = $this->owner();
        $in = $vector['doc'];

        $deck = $this->deckFor($user, $in['deck']['title'] ?? 'Deck', 0);
        $deck->forceFill([
            'w' => $in['deck']['w'], 'h' => $in['deck']['h'], 'theme' => $in['deck']['theme'] ?? null,
            'status' => $in['deck']['status'] ?? 'draft', 'visibility' => $in['deck']['visibility'] ?? 'team',
            'sections' => $in['deck']['sections'] ?? [],
        ])->save();

        $toReal = [];
        foreach ($in['slides'] as $i => $s) {
            $slide = $this->slideFor($deck, (string) ($s['title'] ?? ''), $i, $s['els'] ?? []);
            $slide->forceFill([
                'notes' => $s['notes'] ?? null, 'section_id' => $s['section_id'] ?? null, 'hidden' => $s['hidden'] ?? false,
                'layout' => $s['layout'] ?? 'blank', 'bg' => $s['bg'] ?? null, 'transition' => $s['transition'] ?? null,
            ])->save();
            $toReal[$s['id']] = $slide->id;
        }

        $ops = array_map(fn ($op) => $this->translate($op, $toReal), $vector['ops']);
        $result = app(OpApplier::class)->apply($deck, $user, 'vec', 'vec-'.Str::uuid(), $ops);

        $back = array_flip($toReal);
        foreach ($result['ids'] as $tid => $id) {
            $back[$id] = $tid;
        }

        $actual = $this->present(DeckDocument::toArray($deck->fresh()), $back, $vector['normalize'] ?? []);
        $expected = $this->present($vector['expected']['doc'], [], $vector['normalize'] ?? []);

        $this->assertSame($this->canon($expected), $this->canon($actual), "document differs for {$vector['name']}");

        $skipped = array_values(array_unique(array_column($result['skipped'], 'index')));
        sort($skipped);
        $this->assertSame(array_column($vector['expected']['skipped'], 'index'), $skipped, "skipped ops differ for {$vector['name']}: ".json_encode($result['skipped']));
    }

    /**
     * @param  array<string, mixed>  $op
     * @param  array<string, int>  $toReal
     * @return array<string, mixed>
     */
    private function translate(mixed $op, array $toReal): mixed
    {
        if (! is_array($op) || ! isset(self::SLIDE_FIELDS[$op['op'] ?? ''])) {
            return $op;
        }
        // A tid that equals an existing slide id: the server's slide ids are digits, and a tid of only digits is refused.
        if (in_array($op['op'], ['slide.add', 'slide.dup'], true) && isset($op['tid']) && is_string($op['tid']) && isset($toReal[$op['tid']])) {
            $op['tid'] = (string) $toReal[$op['tid']];
        }
        foreach (self::SLIDE_FIELDS[$op['op']] as $field) {
            if (isset($op[$field]) && is_string($op[$field]) && isset($toReal[$op[$field]])) {
                $op[$field] = $toReal[$op[$field]];
            }
        }

        return $op;
    }

    /**
     * Strips what the server owns, puts vector slide ids back and applies the vector's renaming of generated ids.
     *
     * @param  array<string, mixed>  $doc
     * @param  array<int, string>  $back
     * @param  list<string>  $normalize
     * @return array<string, mixed>
     */
    private function present(array $doc, array $back, array $normalize): array
    {
        unset($doc['seq'], $doc['assets'], $doc['deck']['rev'], $doc['deck']['updated_at'], $doc['deck']['id']);
        $doc['deck']['slide_order'] = array_map(fn ($id) => (string) ($back[$id] ?? $id), $doc['deck']['slide_order']);

        foreach ($doc['slides'] as &$slide) {
            unset($slide['rev']);
            $slide['id'] = (string) ($back[$slide['id']] ?? $slide['id']);
            if (in_array($slide['id'], $normalize, true)) {
                $groups = [];
                foreach ($slide['els'] as $i => &$el) {
                    $el['id'] = 'e'.($i + 1);
                    if (isset($el['group'])) {
                        $groups[$el['group']] ??= 'g'.(count($groups) + 1);
                        $el['group'] = $groups[$el['group']];
                    }
                }
                unset($el);
            }
        }
        unset($slide);

        return $doc;
    }

    private function canon(mixed $value): string
    {
        return json_encode($this->sorted($value), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function sorted(mixed $value): mixed
    {
        if (is_int($value) || is_float($value)) {
            return round((float) $value, 6);
        }
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(fn ($v) => $this->sorted($v), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
