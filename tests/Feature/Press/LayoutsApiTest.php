<?php

namespace Tests\Feature\Press;

use App\Models\Layout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LayoutsApiTest extends TestCase
{
    use AssetsFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssets();
    }

    private function definition(): array
    {
        return [
            ['id' => 'title', 'type' => 'text', 'role' => 'title', 'x' => 0.08, 'y' => 0.1, 'w' => 0.84, 'h' => 0.2, 'size' => 80, 'weight' => 700, 'font' => '@heading', 'color' => '@ink', 'align' => 'left', 'valign' => 'middle', 'hint' => 'Title'],
            ['id' => 'pic', 'type' => 'image', 'role' => 'media', 'x' => 0.08, 'y' => 0.35, 'w' => 0.84, 'h' => 0.55, 'fit' => 'cover', 'mask' => 'none'],
        ];
    }

    public function test_a_layout_is_saved_listed_shown_updated_and_deleted(): void
    {
        $response = $this->actingAs($this->owner)->postJson('/api/layouts', ['name' => 'Picture and title', 'definition' => $this->definition()])->assertCreated();

        $id = $response->json('layout.db_id');
        $response->assertJsonPath('layout.id', "custom:{$id}")->assertJsonPath('layout.kind', 'custom')->assertJsonCount(2, 'layout.definition')->assertJsonPath('layout.definition.0.units', 'fraction');

        $this->actingAs($this->owner)->getJson('/api/layouts')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('limits.layouts', 60);
        $this->actingAs($this->owner)->getJson("/api/layouts/{$id}")->assertOk()->assertJsonPath('layout.name', 'Picture and title');

        $changed = $this->definition();
        $changed[1]['x'] = 0.5;
        $changed[1]['w'] = 0.4;
        $this->actingAs($this->owner)->patchJson("/api/layouts/{$id}", ['name' => 'Renamed', 'kind' => 'media', 'definition' => $changed])
            ->assertOk()->assertJsonPath('layout.name', 'Renamed')->assertJsonPath('layout.kind', 'media')->assertJsonPath('layout.definition.1.x', 0.5);

        $this->actingAs($this->owner)->patchJson("/api/layouts/{$id}", ['name' => 'Only name'])->assertOk()->assertJsonCount(2, 'layout.definition');

        $this->actingAs($this->owner)->deleteJson("/api/layouts/{$id}")->assertNoContent();
        $this->assertDatabaseCount('layouts', 0);
    }

    /**
     * @return array<string, array{callable(): array<int, mixed>, string}>
     */
    public static function invalidDefinitions(): array
    {
        $ok = ['id' => 'a', 'type' => 'text', 'x' => 0.1, 'y' => 0.1, 'w' => 0.5, 'h' => 0.5];

        return [
            'not a list' => [fn () => ['id' => 'a'], 'definition'],
            'empty' => [fn () => [], 'definition'],
            'forty-one elements' => [fn () => array_map(fn ($i) => ['id' => "e{$i}"] + $ok, range(1, 41)), 'definition'],
            'element not an object' => [fn () => ['text'], 'definition.0'],
            'unknown type' => [fn () => [['type' => 'script'] + $ok], 'definition.0.type'],
            'missing type' => [fn () => [['id' => 'a', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1]], 'definition.0.type'],
            'unknown role' => [fn () => [$ok + ['role' => 'hero']], 'definition.0.role'],
            'unknown key' => [fn () => [$ok + ['onclick' => 'x']], 'definition.0.onclick'],
            'bad id' => [fn () => [['id' => 'has space'] + $ok], 'definition.0.id'],
            'duplicate ids' => [fn () => [$ok, $ok], 'definition.1.id'],
            'fraction above one' => [fn () => [['x' => 1.2] + $ok], 'definition.0.x'],
            'negative x' => [fn () => [['x' => -0.1] + $ok], 'definition.0.x'],
            'box leaves the slide' => [fn () => [['x' => 0.8, 'w' => 0.5] + $ok], 'definition.0.x'],
            'zero width' => [fn () => [['w' => 0] + $ok], 'definition.0.w'],
            'string geometry' => [fn () => [['y' => '0.1'] + $ok], 'definition.0.y'],
            'missing height' => [fn () => [['id' => 'a', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 1]], 'definition.0.h'],
            'slide units too large' => [fn () => [['units' => 'slide', 'x' => 30000, 'w' => 10] + $ok], 'definition.0.x'],
            'bad units' => [fn () => [['units' => 'em'] + $ok], 'definition.0.units'],
            'bad colour' => [fn () => [['fill' => 'red'] + $ok], 'definition.0.fill'],
            'bad font' => [fn () => [['font' => '<x>'] + $ok], 'definition.0.font'],
            'bad align' => [fn () => [['align' => 'middle'] + $ok], 'definition.0.align'],
            'weight not a step' => [fn () => [['weight' => 'bold'] + $ok], 'definition.0.weight'],
            'opacity above one' => [fn () => [['op' => 1.5] + $ok], 'definition.0.op'],
            'rotation too large' => [fn () => [['rot' => 720] + $ok], 'definition.0.rot'],
            'hint too long' => [fn () => [['hint' => str_repeat('h', 121)] + $ok], 'definition.0.hint'],
            'bad shape kind' => [fn () => [['kind' => 'blob'] + $ok], 'definition.0.kind'],
        ];
    }

    #[DataProvider('invalidDefinitions')]
    public function test_invalid_layouts_are_a_422_naming_the_field(callable $definition, string $field): void
    {
        $this->actingAs($this->owner)->postJson('/api/layouts', ['name' => 'Bad', 'definition' => $definition()])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('layouts', 0);
    }

    public function test_slide_unit_geometry_and_the_name_and_kind_rules(): void
    {
        $this->actingAs($this->owner)->postJson('/api/layouts', ['name' => 'Units', 'definition' => [['type' => 'text', 'units' => 'slide', 'x' => 120, 'y' => 80, 'w' => 1680, 'h' => 200]]])
            ->assertCreated()->assertJsonPath('layout.definition.0.units', 'slide')->assertJsonPath('layout.definition.0.w', 1680);

        $this->actingAs($this->owner)->postJson('/api/layouts', ['definition' => $this->definition()])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->actingAs($this->owner)->postJson('/api/layouts', ['name' => 'K', 'kind' => 'weird', 'definition' => $this->definition()])->assertUnprocessable()->assertJsonValidationErrors('kind');
        $this->actingAs($this->owner)->postJson('/api/layouts', ['name' => 'No definition'])->assertUnprocessable()->assertJsonValidationErrors('definition');
    }

    public function test_roles_editors_and_admins_write_viewers_read(): void
    {
        $id = $this->actingAs($this->owner)->postJson('/api/layouts', ['name' => 'L', 'definition' => $this->definition()])->assertCreated()->json('layout.db_id');
        $viewer = $this->memberWithRole('viewer');
        $editor = $this->memberWithRole('editor');
        $admin = $this->memberWithRole('admin');

        $this->actingAs($viewer)->getJson('/api/layouts')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($viewer)->getJson("/api/layouts/{$id}")->assertOk();
        $this->actingAs($viewer)->postJson('/api/layouts', ['name' => 'x', 'definition' => $this->definition()])->assertForbidden();
        $this->actingAs($viewer)->patchJson("/api/layouts/{$id}", ['name' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->deleteJson("/api/layouts/{$id}")->assertForbidden();

        $this->actingAs($editor)->postJson('/api/layouts', ['name' => 'By editor', 'definition' => $this->definition()])->assertCreated();
        $this->actingAs($editor)->patchJson("/api/layouts/{$id}", ['name' => 'Changed'])->assertOk();
        $this->actingAs($admin)->deleteJson("/api/layouts/{$id}")->assertNoContent();
    }

    public function test_another_team_cannot_see_or_change_a_layout(): void
    {
        $id = $this->actingAs($this->owner)->postJson('/api/layouts', ['name' => 'L', 'definition' => $this->definition()])->assertCreated()->json('layout.db_id');
        $stranger = $this->outsider();

        $this->actingAs($stranger)->getJson("/api/layouts/{$id}")->assertNotFound();
        $this->actingAs($stranger)->patchJson("/api/layouts/{$id}", ['name' => 'x'])->assertNotFound();
        $this->actingAs($stranger)->deleteJson("/api/layouts/{$id}")->assertNotFound();
        $this->actingAs($stranger)->getJson('/api/layouts')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($stranger)->postJson('/api/layouts?team_id='.$this->owner->currentTeam->id, ['name' => 'x', 'definition' => $this->definition()])->assertNotFound();
        $this->assertDatabaseCount('layouts', 1);
    }

    public function test_a_team_keeps_at_most_sixty_layouts(): void
    {
        for ($i = 0; $i < Layout::MAX_PER_TEAM; $i++) {
            Layout::create(['team_id' => $this->owner->currentTeam->id, 'name' => "L{$i}", 'kind' => 'custom', 'definition' => $this->definition()]);
        }

        $this->actingAs($this->owner)->postJson('/api/layouts', ['name' => 'Sixty-one', 'definition' => $this->definition()])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertDatabaseCount('layouts', 60);

        $other = $this->outsider();
        $this->actingAs($other)->postJson('/api/layouts', ['name' => 'Fresh team is fine', 'definition' => $this->definition()])->assertCreated();
    }
}
