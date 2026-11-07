<?php

namespace Tests\Feature\Press\Security;

use App\Models\Project;
use Tests\Feature\Press\AccessTestCase;

/** Themes, brand kits and layouts belong to one team; a picture from another team's library never becomes part of one. */
class LibraryIsolationTest extends AccessTestCase
{
    private function foreignAsset(): int
    {
        $project = Project::create(['user_id' => $this->outsider->id, 'team_id' => $this->otherTeam->id, 'name' => 'Other', 'slug' => 'o-'.uniqid()]);

        return $this->asset('secret.png', $project)->id;
    }

    public function test_a_theme_background_cannot_point_at_another_teams_picture(): void
    {
        $foreign = $this->foreignAsset();
        $own = $this->asset('mine.png')->id;

        $base = ['name' => 'Branded', 'c' => ['accent' => '#112233']];
        $this->actingAs($this->editor)->postJson('/api/themes', $base + ['bg' => ['img' => ['asset' => $foreign]]])->assertStatus(422)->assertJsonValidationErrors('bg');
        $this->actingAs($this->editor)->postJson('/api/themes', $base + ['bg' => ['img' => ['asset' => 999999]]])->assertStatus(422);
        $created = $this->actingAs($this->editor)->postJson('/api/themes', $base + ['bg' => ['img' => ['asset' => $own]]])->assertCreated();

        $id = $created->json('theme.db_id');
        $this->actingAs($this->editor)->patchJson("/api/themes/{$id}", ['bg' => ['img' => ['asset' => $foreign]]])->assertStatus(422);
    }

    public function test_the_library_routes_never_cross_teams(): void
    {
        $theme = $this->actingAs($this->editor)->postJson('/api/themes', ['name' => 'Mine', 'c' => ['accent' => '#112233']])->assertCreated()->json('theme.db_id');
        $kit = $this->actingAs($this->editor)->postJson('/api/brand-kits', ['name' => 'Kit'])->assertCreated()->json('brand_kit.id');
        $layout = $this->actingAs($this->editor)->postJson('/api/layouts', ['name' => 'Lay', 'definition' => ['elements' => []]]);

        $this->actingAs($this->outsider);
        foreach ([["themes/{$theme}"], ["brand-kits/{$kit}"]] as [$path]) {
            $this->getJson("/api/{$path}")->assertNotFound();
            $this->putJson("/api/{$path}", ['name' => 'x'])->assertNotFound();
            $this->deleteJson("/api/{$path}")->assertNotFound();
        }
        // naming the other team in the query is a 404, and the outsider's own list stays empty of ours
        $this->getJson("/api/themes?team_id={$this->team->id}")->assertNotFound();
        $this->getJson("/api/brand-kits?team_id={$this->team->id}")->assertNotFound();
        $this->getJson("/api/layouts?team_id={$this->team->id}")->assertNotFound();
        $this->postJson('/api/themes', ['name' => 'Intruder', 'team_id' => $this->team->id, 'c' => ['accent' => '#112233']])->assertNotFound();
        $this->assertSame([], array_values(array_filter($this->getJson('/api/themes')->json('data'), fn ($t) => empty($t['builtin']))));

        if ($layout->status() === 201) {
            $this->getJson('/api/layouts/'.$layout->json('layout.id'))->assertNotFound();
        }

        // a team viewer reads and cannot write
        $this->actingAs($this->viewer);
        $this->getJson('/api/themes')->assertOk();
        $this->postJson('/api/themes', ['name' => 'V', 'c' => ['accent' => '#112233']])->assertForbidden();
        $this->deleteJson("/api/themes/{$theme}")->assertForbidden();
    }

    public function test_assets_and_projects_of_another_team_are_invisible_by_id(): void
    {
        $foreignProject = Project::create(['user_id' => $this->outsider->id, 'team_id' => $this->otherTeam->id, 'name' => 'Other', 'slug' => 'o2-'.uniqid()]);
        $foreign = $this->asset('secret.png', $foreignProject);

        $this->actingAs($this->editor);
        $this->getJson("/api/assets/{$foreign->id}")->assertNotFound();
        $this->getJson("/api/assets/{$foreign->id}/usage")->assertNotFound();
        $this->putJson("/api/assets/{$foreign->id}", ['name' => 'x'])->assertNotFound();
        $this->deleteJson("/api/assets/{$foreign->id}")->assertNotFound();
        $this->postJson("/api/assets/{$foreign->id}/poster")->assertNotFound();
        $this->getJson('/api/assets?project_id='.$foreignProject->id)->assertNotFound();
        $this->getJson("/api/projects/{$foreignProject->id}")->assertNotFound();
        $this->postJson('/api/decks', ['title' => 'Planted', 'project_id' => $foreignProject->id])->assertNotFound();
        $this->postJson('/api/assets', ['project_id' => $foreignProject->id])->assertNotFound();
    }
}
