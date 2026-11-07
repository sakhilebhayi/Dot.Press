<?php

namespace Tests\Feature\Press;

use App\Models\Deck;
use App\Models\Element;
use App\Models\Project;
use App\Models\Slide;
use App\Services\Press\Backfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillTest extends TestCase
{
    use MakesDecks;
    use RefreshDatabase;

    /**
     * A deck exactly as the app stored it before R-40: 1280 x 720, a colour-only theme, no owner, elements in their table.
     *
     * @return array{0: Deck, 1: Slide, 2: Slide}
     */
    private function legacyDeck(): array
    {
        $user = $this->owner();
        $project = Project::create(['user_id' => $user->id, 'team_id' => $user->currentTeam->id, 'name' => 'Old', 'slug' => 'old']);
        $deck = Deck::create(['project_id' => $project->id, 'title' => 'Legacy', 'sort_order' => 0, 'theme' => ['background' => '#ffeedd']]);
        $deck->forceFill(['w' => 1280, 'h' => 720, 'user_id' => null])->save();

        $one = Slide::create(['deck_id' => $deck->id, 'title' => 'One', 'sort_order' => 0, 'revision' => 7, 'canvas_state' => ['meta' => ['version' => 1]]]);
        $two = Slide::create(['deck_id' => $deck->id, 'title' => 'Two', 'sort_order' => 1, 'canvas_state' => ['elements' => [
            ['id' => 'from-json', 'type' => 'rect', 'x' => 10, 'y' => 20, 'width' => 30, 'height' => 40],
        ]]]);

        $elements = [
            ['id' => 'el-title', 'type' => 'text', 'x' => 90, 'y' => 80, 'width' => 1100, 'height' => 120, 'fontSize' => 54, 'fill' => '#0f172a', 'fontStyle' => 'bold italic', 'textAlign' => 'center', 'lineHeight' => 1.2, 'textIndent' => 4,
                'text' => 'Plain', 'richContent' => ['type' => 'doc', 'content' => [['type' => 'heading', 'content' => [['type' => 'text', 'text' => 'Quarterly results']]]]]],
            ['id' => 'el-plain', 'type' => 'text', 'x' => 90, 'y' => 235, 'width' => 780, 'height' => 320, 'fontSize' => 26, 'text' => "First line\nSecond line"],
            ['id' => 'el-rect', 'type' => 'rect', 'x' => 900, 'y' => 235, 'width' => 290, 'height' => 320, 'fill' => '#dbeafe', 'stroke' => '#1d4ed8', 'strokeWidth' => 3, 'radius' => 14, 'opacity' => 0.5, 'shadowColor' => '#000000', 'shadowBlur' => 6, 'shadowOffsetX' => 2, 'shadowOffsetY' => 3],
            ['id' => 'el-circle', 'type' => 'circle', 'x' => 10, 'y' => 10, 'width' => 100, 'height' => 100],
            ['id' => 'el-poly', 'type' => 'polygon', 'x' => 10, 'y' => 10, 'width' => 100, 'height' => 100, 'fill' => '#dcfce7'],
            ['id' => 'el-line', 'type' => 'line', 'x' => 100, 'y' => 300, 'width' => 260, 'height' => 18, 'stroke' => '#0f172a', 'strokeWidth' => 4],
            ['id' => 'el-arrow', 'type' => 'arrow', 'x' => 100, 'y' => 400, 'width' => 260, 'height' => 30],
            ['id' => 'el-image', 'type' => 'image', 'x' => 50, 'y' => 60, 'width' => 320, 'height' => 220, 'assetId' => 5, 'assetUrl' => 'https://signed.example/expiring?sig=1', 'objectFit' => 'cover', 'fill' => '#f8fafc', 'dash' => [8, 4]],
            ['id' => 'el-empty-image', 'type' => 'image', 'x' => 50, 'y' => 60, 'width' => 320, 'height' => 220, 'assetId' => null],
            ['id' => 'el-yt', 'type' => 'video', 'x' => 0, 'y' => 0, 'width' => 420, 'height' => 240, 'videoUrl' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'videoType' => 'youtube', 'videoMuted' => true],
            ['id' => 'el-file', 'type' => 'video', 'x' => 0, 'y' => 0, 'width' => 420, 'height' => 240, 'assetId' => 9, 'videoUrl' => 'https://signed.example/v', 'videoLoop' => true],
            ['id' => 'el-no-video', 'type' => 'video', 'x' => 0, 'y' => 0, 'width' => 420, 'height' => 240, 'videoUrl' => ''],
            ['id' => 'el-icon', 'type' => 'icon', 'x' => 700, 'y' => 20, 'width' => 96, 'height' => 96, 'icon' => '⚡', 'fill' => '#b45309', 'fontSize' => 72],
            ['id' => 'el-group', 'type' => 'group', 'x' => 30, 'y' => 30, 'width' => 320, 'height' => 180],
            ['id' => 'el-rot', 'type' => 'rect', 'x' => 200, 'y' => 100, 'width' => 100, 'height' => 40, 'rotation' => 90, 'locked' => true, 'name' => 'Spinner'],
            ['id' => 'bad id!', 'type' => 'rect', 'x' => 1, 'y' => 1],
            ['id' => 'bad id?', 'type' => 'rect', 'x' => 2, 'y' => 2],
            ['id' => 'el-weird', 'type' => 'hologram', 'x' => 1, 'y' => 1],
        ];
        foreach ($elements as $i => $canvas) {
            $one->elements()->create(Element::attributesFromCanvasElement($canvas) + ['sort_order' => $i]);
        }

        return [$deck->fresh(), $one->fresh(), $two->fresh()];
    }

    public function test_it_is_safe_on_an_empty_database(): void
    {
        $this->assertSame(['owners' => 0, 'themes' => 0, 'slides' => 0, 'elements' => 0, 'dropped' => 0, 'failed' => 0], (new Backfill)->run());
    }

    public function test_a_legacy_deck_becomes_a_document_keeping_positions_and_order(): void
    {
        [$deck, $one, $two] = $this->legacyDeck();

        $stats = (new Backfill)->run();

        $this->assertSame(2, $stats['slides']);
        $this->assertSame(0, $stats['failed']);
        $this->assertSame(2, $stats['dropped']); // the empty video and the unknown type
    }

    public function test_deck_owner_theme_and_slide_backgrounds(): void
    {
        [$deck, $one, $two] = $this->legacyDeck();

        (new Backfill)->run();

        $deck->refresh();
        $this->assertSame($deck->project->user_id, $deck->user_id);
        $this->assertSame([1280, 720], [$deck->w, $deck->h]);
        $this->assertSame('slate', $deck->theme['id']);
        $this->assertSame('#ffeedd', $deck->theme['bg']);
        $this->assertSame('#ffeedd', $one->fresh()->bg);
        $this->assertSame(7, $one->fresh()->revision, 'slides.revision is left alone');
        $this->assertSame(0, $two->fresh()->revision);
    }

    public function test_each_element_type_is_mapped_to_the_spec(): void
    {
        [$deck, $one, $two] = $this->legacyDeck();
        (new Backfill)->run();
        $els = collect($one->fresh()->els)->keyBy('id');

        // Text: rich document kept (heading pruned to a paragraph), style carried over.
        $title = $els['el-title'];
        $this->assertSame(['text', 90, 80, 1100, 120], [$title['type'], $title['x'], $title['y'], $title['w'], $title['h']]);
        $this->assertSame('Quarterly results', $title['doc']['content'][0]['content'][0]['text']);
        $this->assertSame('paragraph', $title['doc']['content'][0]['type']);
        $this->assertSame([['type' => 'italic']], $title['doc']['content'][0]['content'][0]['marks']);
        $this->assertSame([54, 700, '#0f172a', 'center', 1.2, 4], [$title['size'], $title['weight'], $title['color'], $title['align'], $title['lh'], $title['pad']]);

        $plain = $els['el-plain'];
        $this->assertSame(['First line', 'Second line'], array_map(fn ($p) => $p['content'][0]['text'], $plain['doc']['content']));
        $this->assertSame(400, $plain['weight']);

        $rect = $els['el-rect'];
        $this->assertSame(['shape', 'rect', '#dbeafe', '#1d4ed8', 3, 14, 0.5], [$rect['type'], $rect['kind'], $rect['fill'], $rect['stroke'], $rect['sw'], $rect['radius'], $rect['op']]);
        $this->assertSame(['x' => 2, 'y' => 3, 'blur' => 6, 'c' => '#00000080'], $rect['shadow']);

        $this->assertSame('ellipse', $els['el-circle']['kind']);
        $this->assertSame('#fee2e2', $els['el-circle']['fill'], 'the colour the old canvas drew by default is written down');
        $this->assertSame('triangle', $els['el-poly']['kind']);

        $line = $els['el-line'];
        $this->assertSame(['line', 4, '#0f172a', 0], [$line['kind'], $line['sw'], $line['stroke'], $line['h']]);
        $this->assertSame(309, $line['y'], 'the old line was drawn at mid height');
        $this->assertSame(['s' => 'none', 'e' => 'arrow'], $els['el-arrow']['ends']);

        $image = $els['el-image'];
        $this->assertSame(['image', ['asset' => 5], 'cover', '#f8fafc'], [$image['type'], $image['src'], $image['fit'], $image['fill']]);
        $this->assertArrayNotHasKey('src', $els['el-empty-image']);

        $this->assertSame(['provider' => 'youtube', 'id' => 'dQw4w9WgXcQ'], $els['el-yt']['embed']);
        $this->assertSame(['asset' => 9], $els['el-file']['src']);
        $this->assertTrue($els['el-file']['loop']);
        $this->assertArrayNotHasKey('el-no-video', $els->all());

        $icon = $els['el-icon'];
        $this->assertSame(['text', '⚡', 72, 'center', 'middle', '#b45309'], [$icon['type'], $icon['doc']['content'][0]['content'][0]['text'], $icon['size'], $icon['align'], $icon['valign'], $icon['color']]);

        $group = $els['el-group'];
        $this->assertSame(['rect', 'dash'], [$group['kind'], $group['dash']]);
    }

    public function test_rotation_moves_from_the_corner_to_the_centre_without_moving_the_element(): void
    {
        [$deck, $one] = $this->legacyDeck();
        (new Backfill)->run();
        $rot = collect($one->fresh()->els)->firstWhere('id', 'el-rot');

        // Old: top left (200,100), 100x40, rotated 90 degrees clockwise about that corner, so the centre is (180,150).
        $this->assertSame(90, $rot['rot']);
        $this->assertEqualsWithDelta(180, $rot['x'] + $rot['w'] / 2, 0.01);
        $this->assertEqualsWithDelta(150, $rot['y'] + $rot['h'] / 2, 0.01);
        $this->assertTrue($rot['lock']);
        $this->assertSame('Spinner', $rot['name']);
    }

    public function test_ids_are_cleaned_and_made_unique_and_z_order_is_kept(): void
    {
        [$deck, $one] = $this->legacyDeck();
        (new Backfill)->run();
        $ids = array_column($one->fresh()->els, 'id');

        $this->assertSame(['el-title', 'el-plain', 'el-rect', 'el-circle', 'el-poly', 'el-line', 'el-arrow', 'el-image', 'el-empty-image', 'el-yt', 'el-file', 'el-icon', 'el-group', 'el-rot', 'bad_id_', 'bad_id_-2'], $ids);
        foreach ($ids as $id) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,40}$/', $id);
        }
    }

    public function test_elements_that_only_live_in_canvas_state_are_picked_up(): void
    {
        [$deck, $one, $two] = $this->legacyDeck();
        (new Backfill)->run();

        $els = $two->fresh()->els;
        $this->assertSame('from-json', $els[0]['id']);
        $this->assertSame([10, 20, 30, 40], [$els[0]['x'], $els[0]['y'], $els[0]['w'], $els[0]['h']]);
    }

    public function test_running_it_again_changes_nothing(): void
    {
        [$deck, $one, $two] = $this->legacyDeck();
        (new Backfill)->run();
        $before = [$one->fresh()->els, $two->fresh()->els, $deck->fresh()->theme, $one->fresh()->updated_at];

        $stats = (new Backfill)->run();

        $this->assertSame(['owners' => 0, 'themes' => 0, 'slides' => 0, 'elements' => 0, 'dropped' => 0, 'failed' => 0], $stats);
        $this->assertEquals($before, [$one->fresh()->els, $two->fresh()->els, $deck->fresh()->theme, $one->fresh()->updated_at]);
    }

    public function test_slides_converted_earlier_and_later_edits_are_left_alone(): void
    {
        [$deck, $one, $two] = $this->legacyDeck();
        $two->update(['els' => [$this->shape('mine')]]);

        (new Backfill)->run();

        $this->assertSame(['mine'], array_column($two->fresh()->els, 'id'));
        $this->assertNotEmpty($one->fresh()->els);
    }

    public function test_the_converted_deck_opens_in_the_document_api_and_accepts_operations(): void
    {
        [$deck, $one] = $this->legacyDeck();
        (new Backfill)->run();
        $user = $deck->project->user;

        $doc = $this->actingAs($user)->getJson("/api/decks/{$deck->id}/document")->assertOk();
        $doc->assertJsonPath('deck.w', 1280)->assertJsonPath('deck.h', 720)->assertJsonPath('slides.0.els.0.id', 'el-title');

        $this->ops($user, $deck, [['op' => 'el.set', 'slide' => $one->id, 'id' => 'el-rect', 'p' => ['x' => 1]]])->assertOk()->assertJsonPath('skipped', []);
    }

    public function test_the_command_runs_the_same_conversion(): void
    {
        [$deck, $one] = $this->legacyDeck();

        $this->artisan('press:backfill')->assertSuccessful();

        $this->assertNotNull($one->fresh()->els);
    }

    public function test_the_data_migration_does_the_same_on_a_populated_database(): void
    {
        [$deck, $one] = $this->legacyDeck();

        $migration = require database_path('migrations/2026_11_08_100100_press_backfill_documents.php');
        $migration->up();
        $migration->up();

        $this->assertCount(14, array_filter($one->fresh()->els, fn ($el) => $el['id'] !== 'bad_id_' && $el['id'] !== 'bad_id_-2'));
        $this->assertSame($deck->project->user_id, $deck->fresh()->user_id);
    }
}
