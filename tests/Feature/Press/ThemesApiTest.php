<?php

namespace Tests\Feature\Press;

use App\Models\BrandKit;
use App\Models\Theme;
use App\Services\Press\ThemeLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ThemesApiTest extends TestCase
{
    use AssetsFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssets();
    }

    private function make(array $overrides = []): int
    {
        return $this->actingAs($this->owner)->postJson('/api/themes', $overrides + ['name' => 'Ocean', 'c' => ['accent' => '#0891b2']])->assertCreated()->json('theme.db_id');
    }

    public function test_the_list_has_the_built_ins_first_then_the_teams_own(): void
    {
        $this->make(['name' => 'Zebra']);
        $this->make(['name' => 'Alpha']);
        $stranger = $this->outsider();
        $this->actingAs($stranger)->postJson('/api/themes', ['name' => 'Not ours'])->assertCreated();

        $response = $this->actingAs($this->owner)->getJson('/api/themes')->assertOk();

        $builtIns = count(ThemeLibrary::all());
        $names = array_column($response->json('data'), 'name');
        $this->assertSame(array_column(ThemeLibrary::all(), 'name'), array_slice($names, 0, $builtIns));
        $this->assertSame(['Alpha', 'Zebra'], array_slice($names, $builtIns));
        $response->assertJsonPath('data.0.id', 'slate')->assertJsonPath('data.0.builtin', true)->assertJsonPath("data.{$builtIns}.builtin", false);
        $response->assertJsonPath('team_id', $this->owner->currentTeam->id)->assertJsonPath('default_brand_kit', null);
    }

    public function test_the_default_brand_kit_rides_along_with_the_list(): void
    {
        $team = $this->owner->currentTeam;
        BrandKit::create(['team_id' => $team->id, 'name' => 'Side kit', 'tokens' => ['palette' => [], 'fonts' => [], 'logos' => []], 'is_default' => false]);
        $kit = BrandKit::create(['team_id' => $team->id, 'name' => 'Main kit', 'tokens' => ['palette' => [['name' => 'Red', 'hex' => '#d71920']], 'fonts' => [], 'logos' => []], 'is_default' => true]);

        $response = $this->actingAs($this->owner)->getJson('/api/themes')->assertOk();

        $response->assertJsonPath('default_brand_kit.id', $kit->id)
            ->assertJsonPath('default_brand_kit.theme.id', 'brand-kit:'.$kit->id)
            ->assertJsonPath('default_brand_kit.theme.c.accent', '#d71920');
    }

    public function test_creating_a_theme_fills_in_the_rest_from_slate(): void
    {
        $response = $this->actingAs($this->owner)->postJson('/api/themes', [
            'name' => '  Ocean ',
            'c' => ['accent' => '#0891B2', 'chart' => ['#0891b2', '#f59e0b']],
            't' => ['heading' => 'Lora', 'hw' => 600],
            'bg' => ['g' => 'linear', 'a' => 90, 'stops' => [[0, '#ffffff'], [1, '@surface']]],
        ])->assertCreated();

        $id = $response->json('theme.db_id');
        $response->assertJsonPath('theme.id', "custom:{$id}")
            ->assertJsonPath('theme.name', 'Ocean')
            ->assertJsonPath('theme.builtin', false)
            ->assertJsonPath('theme.c.accent', '#0891b2')
            ->assertJsonPath('theme.c.ink', '#111827')
            ->assertJsonPath('theme.t.heading', 'Lora')
            ->assertJsonPath('theme.t.body', 'Public Sans')
            ->assertJsonPath('theme.t.hw', 600)
            ->assertJsonPath('theme.s.radius', 12)
            ->assertJsonPath('theme.bg.g', 'linear')
            ->assertJsonCount(8, 'theme.c.chart')
            ->assertJsonPath('theme.warnings', []);

        $this->assertSame($this->owner->currentTeam->id, Theme::findOrFail($id)->team_id);
        $this->assertSame($this->owner->id, Theme::findOrFail($id)->user_id);
    }

    public function test_invalid_tokens_are_a_422_naming_the_field(): void
    {
        $this->actingAs($this->owner)->postJson('/api/themes', ['name' => 'Bad', 'c' => ['accent' => 'blue']])->assertUnprocessable()->assertJsonValidationErrors('c.accent');
        $this->actingAs($this->owner)->postJson('/api/themes', ['name' => 'Bad', 't' => ['heading' => '<b>x</b>']])->assertUnprocessable()->assertJsonValidationErrors('t.heading');
        $this->actingAs($this->owner)->postJson('/api/themes', ['name' => 'Bad', 'bg' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors('bg');
        $this->actingAs($this->owner)->postJson('/api/themes', ['c' => ['accent' => '#000000']])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->actingAs($this->owner)->postJson('/api/themes', ['name' => str_repeat('n', 81)])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertDatabaseCount('themes', 0);
    }

    public function test_poor_contrast_is_reported_as_a_warning_and_the_theme_is_saved(): void
    {
        $response = $this->actingAs($this->owner)->postJson('/api/themes', ['name' => 'Pastel', 'c' => ['ink' => '#dddddd']])->assertCreated();

        $this->assertNotEmpty($response->json('theme.warnings'));
        $this->assertStringContainsString('Body text', $response->json('theme.warnings.0'));
        $this->assertDatabaseCount('themes', 1);
    }

    public function test_updating_merges_into_the_stored_tokens(): void
    {
        $id = $this->make(['t' => ['heading' => 'Lora']]);

        $this->actingAs($this->owner)->patchJson("/api/themes/{$id}", ['c' => ['bg' => '#0b1020', 'ink' => '#f9fafb'], 'name' => 'Ocean night'])
            ->assertOk()
            ->assertJsonPath('theme.name', 'Ocean night')
            ->assertJsonPath('theme.c.bg', '#0b1020')
            ->assertJsonPath('theme.c.accent', '#0891b2', 'earlier colours survive a partial update')
            ->assertJsonPath('theme.t.heading', 'Lora');

        $assetId = $this->upload($this->owner, $this->pngBytes(), 'bg.png')->assertCreated()->json('asset.id');
        $this->actingAs($this->owner)->patchJson("/api/themes/{$id}", ['bg' => ['img' => ['asset' => $assetId], 'fit' => 'cover', 'dim' => 0.4]])->assertOk()->assertJsonPath('theme.bg.img.asset', $assetId);
        $this->actingAs($this->owner)->patchJson("/api/themes/{$id}", ['bg' => null])->assertOk()->assertJsonPath('theme.bg', null);
        $this->actingAs($this->owner)->patchJson("/api/themes/{$id}", ['c' => ['ink' => 'dark']])->assertUnprocessable()->assertJsonValidationErrors('c.ink');
        $this->actingAs($this->owner)->getJson("/api/themes/{$id}")->assertOk()->assertJsonPath('theme.c.ink', '#f9fafb');
    }

    public function test_roles_editors_and_admins_write_viewers_read(): void
    {
        $id = $this->make();
        $viewer = $this->memberWithRole('viewer');
        $editor = $this->memberWithRole('editor');
        $admin = $this->memberWithRole('admin');

        $this->actingAs($viewer)->getJson('/api/themes')->assertOk();
        $this->actingAs($viewer)->getJson("/api/themes/{$id}")->assertOk();
        $this->actingAs($viewer)->postJson('/api/themes', ['name' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->patchJson("/api/themes/{$id}", ['name' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->deleteJson("/api/themes/{$id}")->assertForbidden();

        $editorTheme = $this->actingAs($editor)->postJson('/api/themes', ['name' => 'By editor'])->assertCreated()->json('theme.db_id');
        $this->actingAs($editor)->patchJson("/api/themes/{$editorTheme}", ['name' => 'Edited'])->assertOk();
        $this->actingAs($admin)->postJson('/api/themes', ['name' => 'By admin'])->assertCreated();
        $this->actingAs($admin)->deleteJson("/api/themes/{$editorTheme}")->assertNoContent();
        $this->actingAs($editor)->deleteJson("/api/themes/{$id}")->assertNoContent();
        $this->assertDatabaseCount('themes', 1);
    }

    public function test_another_team_sees_and_changes_nothing(): void
    {
        $id = $this->make();
        $stranger = $this->outsider();

        $this->actingAs($stranger)->getJson("/api/themes/{$id}")->assertNotFound();
        $this->actingAs($stranger)->patchJson("/api/themes/{$id}", ['name' => 'taken'])->assertNotFound();
        $this->actingAs($stranger)->deleteJson("/api/themes/{$id}")->assertNotFound();
        $this->actingAs($stranger)->getJson('/api/themes?team_id='.$this->owner->currentTeam->id)->assertNotFound();
        $this->actingAs($stranger)->postJson('/api/themes?team_id='.$this->owner->currentTeam->id, ['name' => 'inject'])->assertNotFound();

        $this->assertSame('Ocean', Theme::withoutGlobalScopes()->findOrFail($id)->name);
        $this->assertDatabaseCount('themes', 1);
    }

    public function test_a_member_of_two_teams_can_choose_which_one(): void
    {
        $other = $this->outsider();
        $second = $other->currentTeam;
        $second->users()->attach($this->owner, ['role' => 'viewer']);

        $this->actingAs($other)->postJson('/api/themes', ['name' => 'Their theme'])->assertCreated();

        $this->actingAs($this->owner)->getJson('/api/themes?team_id='.$second->id)->assertOk()->assertJsonPath('data.'.count(ThemeLibrary::all()).'.name', 'Their theme');
        $this->actingAs($this->owner)->postJson('/api/themes?team_id='.$second->id, ['name' => 'nope'])->assertForbidden();
        $this->actingAs($this->owner)->getJson('/api/themes')->assertOk()->assertJsonCount(count(ThemeLibrary::all()), 'data');
        $this->actingAs($this->owner)->getJson('/api/themes?team_id=abc')->assertNotFound();
    }

    public function test_a_team_keeps_at_most_one_hundred_custom_themes(): void
    {
        $rows = [];
        for ($i = 0; $i < Theme::MAX_PER_TEAM; $i++) {
            $rows[] = ['team_id' => $this->owner->currentTeam->id, 'name' => "T{$i}", 'tokens' => json_encode([]), 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('themes')->insert($rows);

        $this->actingAs($this->owner)->postJson('/api/themes', ['name' => 'One too many'])->assertUnprocessable()->assertJsonValidationErrors('name');
    }
}
