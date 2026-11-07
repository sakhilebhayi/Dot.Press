<?php

namespace Tests\Feature\Press;

use App\Models\Asset;
use App\Models\BrandKit;
use App\Models\Project;
use App\Services\Press\Assets\AssetStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BrandKitsApiTest extends TestCase
{
    use AssetsFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssets();
    }

    private function logo(string $name = 'logo.png'): Asset
    {
        return AssetStore::storeBytes($this->project, $this->owner, $this->pngBytes(30, 30, true), $name);
    }

    private function kit(array $overrides = []): array
    {
        return [
            'name' => 'Acme',
            'tokens' => [
                'palette' => [
                    ['name' => 'Acme red', 'hex' => '#D71920', 'role' => 'accent'],
                    ['name' => 'Sand', 'hex' => '#f2e8d5'],
                ],
                'fonts' => [['family' => 'Playfair Display', 'role' => 'heading'], ['family' => 'Source Sans 3', 'role' => 'body']],
                'logos' => [],
            ],
        ] + $overrides;
    }

    public function test_a_kit_is_created_with_palette_fonts_logos_and_its_theme(): void
    {
        $logo = $this->logo();
        $payload = $this->kit();
        $payload['tokens']['logos'] = [$logo->id];

        $response = $this->actingAs($this->owner)->postJson('/api/brand-kits', $payload)->assertCreated();

        $response->assertJsonPath('brand_kit.name', 'Acme')
            ->assertJsonPath('brand_kit.is_default', false)
            ->assertJsonPath('brand_kit.tokens.palette.0.hex', '#d71920')
            ->assertJsonPath('brand_kit.tokens.logos', [$logo->id])
            ->assertJsonPath('brand_kit.logo_assets.0.id', $logo->id)
            ->assertJsonPath('brand_kit.theme.c.accent', '#d71920')
            ->assertJsonPath('brand_kit.theme.t.heading', 'Playfair Display');
        $this->assertStringContainsString('signature=', $response->json('brand_kit.logo_assets.0.url'));
        $this->assertDatabaseHas('brand_kits', ['team_id' => $this->owner->currentTeam->id, 'name' => 'Acme']);
    }

    public function test_only_one_kit_per_team_is_the_default(): void
    {
        $first = $this->actingAs($this->owner)->postJson('/api/brand-kits', $this->kit(['name' => 'One', 'is_default' => true]))->assertCreated()->json('brand_kit.id');
        $second = $this->actingAs($this->owner)->postJson('/api/brand-kits', $this->kit(['name' => 'Two', 'is_default' => true]))->assertCreated()->json('brand_kit.id');

        $this->assertFalse(BrandKit::findOrFail($first)->is_default);
        $this->assertTrue(BrandKit::findOrFail($second)->is_default);

        $this->actingAs($this->owner)->patchJson("/api/brand-kits/{$first}", ['is_default' => true])->assertOk()->assertJsonPath('brand_kit.is_default', true);
        $this->assertFalse(BrandKit::findOrFail($second)->is_default);
        $this->assertSame(1, BrandKit::where('is_default', true)->count());

        $this->actingAs($this->owner)->patchJson("/api/brand-kits/{$first}", ['is_default' => false])->assertOk();
        $this->assertSame(0, BrandKit::where('is_default', true)->count());

        $list = $this->actingAs($this->owner)->getJson('/api/brand-kits')->assertOk();
        $list->assertJsonCount(2, 'data')->assertJsonPath('limits.palette', 12)->assertJsonPath('limits.fonts', 3);
    }

    public function test_a_default_kit_in_another_team_is_untouched(): void
    {
        $mine = $this->actingAs($this->owner)->postJson('/api/brand-kits', $this->kit(['is_default' => true]))->assertCreated()->json('brand_kit.id');
        $stranger = $this->outsider();
        $theirs = $this->actingAs($stranger)->postJson('/api/brand-kits', $this->kit(['is_default' => true]))->assertCreated()->json('brand_kit.id');

        $this->assertTrue(BrandKit::withoutGlobalScopes()->findOrFail($mine)->is_default);
        $this->assertTrue(BrandKit::withoutGlobalScopes()->findOrFail($theirs)->is_default);
    }

    public function test_tokens_are_replaced_as_a_whole_on_update(): void
    {
        $id = $this->actingAs($this->owner)->postJson('/api/brand-kits', $this->kit())->assertCreated()->json('brand_kit.id');

        $this->actingAs($this->owner)->patchJson("/api/brand-kits/{$id}", ['name' => 'Renamed', 'tokens' => ['palette' => [['name' => 'Only', 'hex' => '#112233']], 'fonts' => [], 'logos' => []]])
            ->assertOk()
            ->assertJsonPath('brand_kit.name', 'Renamed')
            ->assertJsonCount(1, 'brand_kit.tokens.palette')
            ->assertJsonCount(0, 'brand_kit.tokens.fonts');

        $this->actingAs($this->owner)->patchJson("/api/brand-kits/{$id}", ['name' => 'Third'])->assertOk()->assertJsonCount(1, 'brand_kit.tokens.palette');
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidTokens(): array
    {
        $red = ['name' => 'Red', 'hex' => '#ff0000'];

        return [
            'thirteen colours' => [['palette' => array_fill(0, 13, $red)], 'tokens.palette'],
            'colour without a name' => [['palette' => [['hex' => '#ff0000']]], 'tokens.palette.0.name'],
            'colour with a bad hex' => [['palette' => [['name' => 'Red', 'hex' => 'red']]], 'tokens.palette.0.hex'],
            'unknown role' => [['palette' => [$red + ['role' => 'primary']]], 'tokens.palette.0.role'],
            'role used twice' => [['palette' => [$red + ['role' => 'accent'], $red + ['role' => 'accent']]], 'tokens.palette.1.role'],
            'name too long' => [['palette' => [['name' => str_repeat('n', 41), 'hex' => '#ff0000']]], 'tokens.palette.0.name'],
            'four fonts' => [['fonts' => [['family' => 'A', 'role' => 'heading'], ['family' => 'B', 'role' => 'body'], ['family' => 'C', 'role' => 'accent'], ['family' => 'D', 'role' => 'accent']]], 'tokens.fonts'],
            'font role used twice' => [['fonts' => [['family' => 'A', 'role' => 'body'], ['family' => 'B', 'role' => 'body']]], 'tokens.fonts.1.role'],
            'font with markup' => [['fonts' => [['family' => 'x<y>', 'role' => 'body']]], 'tokens.fonts.0.family'],
            'font with a bad role' => [['fonts' => [['family' => 'Inter', 'role' => 'mono']]], 'tokens.fonts.0.role'],
            'logos not integers' => [['logos' => ['abc']], 'tokens.logos.0'],
            'seven logos' => [['logos' => [1, 2, 3, 4, 5, 6, 7]], 'tokens.logos'],
            'unknown key' => [['palette' => [], 'secrets' => 'x'], 'tokens.secrets'],
        ];
    }

    #[DataProvider('invalidTokens')]
    public function test_invalid_tokens_are_a_422_naming_the_field(array $tokens, string $field): void
    {
        $this->actingAs($this->owner)->postJson('/api/brand-kits', ['name' => 'Bad', 'tokens' => $tokens])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('brand_kits', 0);
    }

    public function test_logos_must_be_images_in_this_teams_library(): void
    {
        $mine = $this->logo();
        $video = AssetStore::storeBytes($this->project, $this->owner, $this->mp4Bytes(), 'clip.mp4');

        $stranger = $this->outsider();
        $theirProject = Project::create(['user_id' => $stranger->id, 'team_id' => $stranger->currentTeam->id, 'name' => 'Theirs', 'slug' => 'theirs']);
        $theirs = AssetStore::storeBytes($theirProject, $stranger, $this->pngBytes(31, 31), 'theirs.png');

        $try = fn (array $logos) => $this->actingAs($this->owner)->postJson('/api/brand-kits', ['name' => 'K', 'tokens' => ['logos' => $logos]]);

        $try([$theirs->id])->assertUnprocessable()->assertJsonValidationErrors('tokens.logos.0');
        $try([$video->id])->assertUnprocessable()->assertJsonValidationErrors('tokens.logos.0');
        $try([999999])->assertUnprocessable();
        $try([$mine->id, $theirs->id])->assertUnprocessable()->assertJsonValidationErrors('tokens.logos.1');
        $try([$mine->id, $mine->id])->assertCreated()->assertJsonPath('brand_kit.tokens.logos', [$mine->id]);
    }

    public function test_roles_editors_and_admins_write_viewers_read(): void
    {
        $id = $this->actingAs($this->owner)->postJson('/api/brand-kits', $this->kit())->assertCreated()->json('brand_kit.id');
        $viewer = $this->memberWithRole('viewer');
        $editor = $this->memberWithRole('editor');

        $this->actingAs($viewer)->getJson('/api/brand-kits')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($viewer)->getJson("/api/brand-kits/{$id}")->assertOk();
        $this->actingAs($viewer)->postJson('/api/brand-kits', $this->kit())->assertForbidden();
        $this->actingAs($viewer)->patchJson("/api/brand-kits/{$id}", ['name' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->deleteJson("/api/brand-kits/{$id}")->assertForbidden();

        $this->actingAs($editor)->patchJson("/api/brand-kits/{$id}", ['name' => 'Edited', 'is_default' => true])->assertOk();
        $this->actingAs($editor)->postJson('/api/brand-kits', $this->kit(['name' => 'Second']))->assertCreated();
        $this->actingAs($editor)->deleteJson("/api/brand-kits/{$id}")->assertNoContent();
        $this->assertDatabaseCount('brand_kits', 1);
    }

    public function test_another_team_cannot_see_or_change_a_kit(): void
    {
        $id = $this->actingAs($this->owner)->postJson('/api/brand-kits', $this->kit())->assertCreated()->json('brand_kit.id');
        $stranger = $this->outsider();

        $this->actingAs($stranger)->getJson("/api/brand-kits/{$id}")->assertNotFound();
        $this->actingAs($stranger)->patchJson("/api/brand-kits/{$id}", ['name' => 'x'])->assertNotFound();
        $this->actingAs($stranger)->deleteJson("/api/brand-kits/{$id}")->assertNotFound();
        $this->actingAs($stranger)->getJson('/api/brand-kits')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($stranger)->getJson('/api/brand-kits?team_id='.$this->owner->currentTeam->id)->assertNotFound();
    }

    public function test_a_team_keeps_at_most_twenty_kits(): void
    {
        for ($i = 0; $i < BrandKit::MAX_PER_TEAM; $i++) {
            BrandKit::create(['team_id' => $this->owner->currentTeam->id, 'name' => "K{$i}", 'tokens' => ['palette' => [], 'fonts' => [], 'logos' => []]]);
        }

        $this->actingAs($this->owner)->postJson('/api/brand-kits', $this->kit())->assertUnprocessable()->assertJsonValidationErrors('name');
    }
}
