<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Deck;
use App\Models\Project;
use App\Models\Slide;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A viewer (the read-only team role) gets 403 on every write in the app and can
 * still read everything; editors and admins keep working. One test per family
 * of write paths, each run as viewer, editor and admin against the same team.
 */
class ViewerWriteProtectionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $viewer;

    private User $editor;

    private User $admin;

    private Team $team;

    private Project $project;

    private Deck $deck;

    private Slide $slide;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai.provider', 'mock');
        config()->set('ai.limits.daily_quota', 100);
        config()->set('filesystems.asset_upload_disk', 'local');
        Storage::fake('local');

        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->team = $this->owner->currentTeam;
        $this->viewer = $this->member('viewer');
        $this->editor = $this->member('editor');
        $this->admin = $this->member('admin');

        $this->project = $this->projectIn($this->team);
        $this->deck = Deck::create(['project_id' => $this->project->id, 'title' => 'Deck', 'sort_order' => 0]);
        $this->slide = Slide::create([
            'deck_id' => $this->deck->id, 'title' => 'Slide 1', 'sort_order' => 0, 'canvas_state' => ['elements' => []],
        ]);
    }

    private function member(string $role, ?Team $team = null): User
    {
        $team ??= $this->team;
        $user = User::factory()->create();
        $team->users()->attach($user, ['role' => $role]);
        $user->switchTeam($team);

        return $user->fresh();
    }

    private function projectIn(Team $team, string $name = 'Board'): Project
    {
        return Project::create([
            'user_id' => $team->user_id, 'team_id' => $team->id, 'name' => $name, 'slug' => str($name)->slug().'-'.uniqid(),
        ]);
    }

    private function newDeck(): Deck
    {
        return Deck::create(['project_id' => $this->project->id, 'title' => 'Extra', 'sort_order' => 5]);
    }

    private function newSlide(): Slide
    {
        return Slide::create(['deck_id' => $this->deck->id, 'title' => 'Extra', 'sort_order' => 5, 'canvas_state' => ['elements' => []]]);
    }

    private function newAsset(): Asset
    {
        $path = UploadedFile::fake()->create('a.png', 10, 'image/png')->store('projects/'.$this->project->id.'/assets', 'local');

        return Asset::create([
            'project_id' => $this->project->id, 'uploaded_by' => $this->owner->id, 'disk' => 'local', 'path' => $path,
            'original_name' => 'a.png', 'mime_type' => 'image/png', 'size' => 10, 'metadata' => [],
        ]);
    }

    // ---- projects -------------------------------------------------------

    public function test_project_writes(): void
    {
        $this->actingAs($this->viewer)->postJson('/api/projects', ['name' => 'Nope'])->assertForbidden();
        $this->actingAs($this->viewer)->putJson('/api/projects/'.$this->project->id, ['name' => 'Nope'])->assertForbidden();
        $this->actingAs($this->viewer)->deleteJson('/api/projects/'.$this->project->id)->assertForbidden();
        $this->assertDatabaseMissing('projects', ['name' => 'Nope']);
        $this->assertDatabaseHas('projects', ['id' => $this->project->id, 'name' => 'Board']);

        foreach ([$this->editor, $this->admin] as $writer) {
            $this->actingAs($writer)->postJson('/api/projects', ['name' => 'Ok '.$writer->id])->assertCreated();
            $this->actingAs($writer)->putJson('/api/projects/'.$this->project->id, ['name' => 'Renamed'])->assertOk();
        }

        $this->actingAs($this->editor)->deleteJson('/api/projects/'.$this->project->id)->assertNoContent();
    }

    // ---- decks ----------------------------------------------------------

    public function test_deck_writes(): void
    {
        $this->actingAs($this->viewer)->postJson('/api/decks', ['project_id' => $this->project->id, 'title' => 'Nope'])->assertForbidden();
        $this->actingAs($this->viewer)->patchJson('/api/decks/'.$this->deck->id, ['title' => 'Nope'])->assertForbidden();
        $this->actingAs($this->viewer)->putJson('/api/decks/'.$this->deck->id, ['title' => 'Nope'])->assertForbidden();
        $this->actingAs($this->viewer)->deleteJson('/api/decks/'.$this->deck->id)->assertForbidden();
        $this->assertDatabaseMissing('decks', ['title' => 'Nope']);
        $this->assertDatabaseHas('decks', ['id' => $this->deck->id, 'title' => 'Deck']);

        foreach ([$this->editor, $this->admin] as $writer) {
            $this->actingAs($writer)->postJson('/api/decks', ['project_id' => $this->project->id, 'title' => 'Ok'])->assertCreated();
            $this->actingAs($writer)->patchJson('/api/decks/'.$this->deck->id, ['title' => 'Renamed'])->assertOk();
        }

        $this->actingAs($this->editor)->deleteJson('/api/decks/'.$this->newDeck()->id)->assertNoContent();
    }

    // ---- slides ---------------------------------------------------------

    public function test_slide_writes(): void
    {
        $canvas = ['canvas_state' => ['elements' => [['id' => 'el-1', 'type' => 'rect', 'x' => 1, 'y' => 1]]]];

        $this->actingAs($this->viewer)->postJson('/api/slides', ['deck_id' => $this->deck->id, 'title' => 'Nope'])->assertForbidden();
        $this->actingAs($this->viewer)->putJson('/api/slides/'.$this->slide->id, $canvas)->assertForbidden();
        $this->actingAs($this->viewer)->patchJson('/api/slides/'.$this->slide->id, ['title' => 'Nope'])->assertForbidden();
        $this->actingAs($this->viewer)->deleteJson('/api/slides/'.$this->slide->id)->assertForbidden();
        $this->assertDatabaseMissing('slides', ['title' => 'Nope']);
        $this->assertSame(0, $this->slide->fresh()->elements()->count());

        foreach ([$this->editor, $this->admin] as $writer) {
            $this->actingAs($writer)->postJson('/api/slides', ['deck_id' => $this->deck->id, 'title' => 'Ok'])->assertCreated();
            $this->actingAs($writer)->putJson('/api/slides/'.$this->slide->id, $canvas)->assertOk();
        }

        $this->actingAs($this->editor)->deleteJson('/api/slides/'.$this->newSlide()->id)->assertNoContent();
    }

    // ---- assets ---------------------------------------------------------

    public function test_asset_writes_and_reads(): void
    {
        $asset = $this->newAsset();
        $upload = fn () => ['project_id' => $this->project->id, 'file' => UploadedFile::fake()->create('slide.png', 20, 'image/png')];

        $this->actingAs($this->viewer)->postJson('/api/assets', $upload())->assertForbidden();
        $this->actingAs($this->viewer)->deleteJson('/api/assets/'.$asset->id)->assertForbidden();
        $this->assertDatabaseCount('assets', 1);

        // Reading the library is fine.
        $this->actingAs($this->viewer)->getJson('/api/assets?project_id='.$this->project->id)->assertOk();
        $this->actingAs($this->viewer)->getJson('/api/assets/'.$asset->id)->assertOk();

        $this->actingAs($this->editor)->postJson('/api/assets', $upload())->assertCreated();
        $this->actingAs($this->admin)->postJson('/api/assets', $upload())->assertCreated();
        $this->actingAs($this->editor)->deleteJson('/api/assets/'.$asset->id)->assertNoContent();
    }

    // ---- AI -------------------------------------------------------------

    public function test_ai_writes(): void
    {
        $slidesBefore = Slide::count();

        $this->actingAs($this->viewer)->postJson("/api/ai/decks/{$this->deck->id}/generate-slide", ['prompt' => 'Roadmap for launch'])->assertForbidden();
        $this->actingAs($this->viewer)->postJson("/api/ai/decks/{$this->deck->id}/generate-deck", ['prompt' => 'Roadmap for launch'])->assertForbidden();
        $this->actingAs($this->viewer)->postJson("/api/ai/slides/{$this->slide->id}/rewrite-text", ['text' => 'Hello there', 'mode' => 'shorten'])->assertForbidden();
        $this->assertSame($slidesBefore, Slide::count());
        $this->assertDatabaseCount('ai_usage_logs', 0);

        foreach ([$this->editor, $this->admin] as $writer) {
            $this->actingAs($writer)->postJson("/api/ai/decks/{$this->deck->id}/generate-slide", ['prompt' => 'Roadmap for launch'])->assertCreated();
            $this->actingAs($writer)->postJson("/api/ai/decks/{$this->deck->id}/generate-deck", ['prompt' => 'Roadmap for launch', 'slide_count' => 2])->assertSuccessful();
            $this->actingAs($writer)->postJson("/api/ai/slides/{$this->slide->id}/rewrite-text", ['text' => 'Hello there', 'mode' => 'shorten'])->assertOk();
        }

        // The usage counter is a read.
        $this->actingAs($this->viewer)->getJson('/api/ai/usage')->assertOk();
    }

    // ---- the editor entry point (a GET that used to create content) -----

    public function test_opening_the_editor_never_creates_content_for_a_viewer(): void
    {
        $fresh = Team::forceCreate(['user_id' => $this->owner->id, 'name' => 'Empty Co', 'personal_team' => false]);
        $viewer = $this->member('viewer', $fresh);
        $projects = Project::withoutGlobalScopes()->count();
        $decks = Deck::withoutGlobalScopes()->count();
        $slides = Slide::withoutGlobalScopes()->count();

        $this->actingAs($viewer)->get(route('editor.start'))->assertForbidden();

        $this->assertSame($projects, Project::withoutGlobalScopes()->count());
        $this->assertSame($decks, Deck::withoutGlobalScopes()->count());
        $this->assertSame($slides, Slide::withoutGlobalScopes()->count());

        // An editor in the same team still gets the workspace made for them.
        $editor = $this->member('editor', $fresh);
        $this->actingAs($editor)->get(route('editor.start'))->assertRedirect();
        $this->assertSame($projects + 1, Project::withoutGlobalScopes()->count());

        // Now it exists, the viewer is sent to the existing first slide.
        $workspaceDeck = Deck::withoutGlobalScopes()->where('title', 'Main Deck')->firstOrFail();
        $workspaceSlide = $workspaceDeck->slides()->firstOrFail();
        $this->actingAs($viewer)->get(route('editor.start'))
            ->assertRedirect(route('editor.slides.show', [$workspaceDeck, $workspaceSlide]));
        $this->assertSame($projects + 1, Project::withoutGlobalScopes()->count());
    }

    // ---- team management ------------------------------------------------

    public function test_team_management_is_closed_to_viewers(): void
    {
        Mail::fake();
        $other = $this->member('editor');

        $this->actingAs($this->viewer)->post('/teams/'.$this->team->id.'/members', ['email' => 'x@example.com', 'role' => 'admin'])->assertForbidden();
        $this->actingAs($this->viewer)->put('/teams/'.$this->team->id.'/members/'.$other->id, ['role' => 'admin'])->assertForbidden();
        $this->actingAs($this->viewer)->put('/teams/'.$this->team->id.'/members/'.$this->viewer->id, ['role' => 'admin'])->assertForbidden();
        $this->actingAs($this->viewer)->delete('/teams/'.$this->team->id.'/members/'.$other->id)->assertForbidden();
        $this->actingAs($this->viewer)->put('/teams/'.$this->team->id, ['name' => 'Hijacked'])->assertForbidden();
        $this->actingAs($this->viewer)->delete('/teams/'.$this->team->id)->assertForbidden();

        $invitation = $this->team->teamInvitations()->create(['email' => 'y@example.com', 'role' => 'viewer']);
        $this->actingAs($this->viewer)->delete('/team-invitations/'.$invitation->id)->assertForbidden();

        $this->assertTrue($this->viewer->fresh()->hasTeamRole($this->team->fresh(), 'viewer'));
        $this->assertTrue($other->fresh()->hasTeamRole($this->team->fresh(), 'editor'));
        $this->assertNotSame('Hijacked', $this->team->fresh()->name);
        $this->assertCount(1, $this->team->fresh()->teamInvitations);

        // Unchanged: only the owner manages the team, whatever role the member holds.
        $this->actingAs($this->owner)->put('/teams/'.$this->team->id.'/members/'.$other->id, ['role' => 'viewer'])->assertSessionHasNoErrors();
        $this->assertTrue($other->fresh()->hasTeamRole($this->team->fresh(), 'viewer'));
    }

    public function test_a_viewer_can_still_leave_the_team_and_switch_teams(): void
    {
        $this->actingAs($this->viewer)->put('/current-team', ['team_id' => $this->viewer->personalTeam()?->id ?? $this->team->id])->assertRedirect();
        $this->actingAs($this->viewer)->delete('/teams/'.$this->team->id.'/members/'.$this->viewer->id)->assertRedirect();

        $this->assertFalse($this->viewer->fresh()->belongsToTeam($this->team->fresh()));
    }

    // ---- reads still work -----------------------------------------------

    public function test_a_viewer_can_read_everything(): void
    {
        $this->actingAs($this->viewer);

        $this->getJson('/api/projects')->assertOk();
        $this->getJson('/api/projects/'.$this->project->id)->assertOk();
        $this->getJson('/api/decks')->assertOk();
        $this->getJson('/api/decks/'.$this->deck->id)->assertOk();
        $this->getJson('/api/slides?deck_id='.$this->deck->id)->assertOk();
        $this->getJson('/api/slides/'.$this->slide->id)->assertOk();
        $this->getJson('/api/collab/slides/'.$this->slide->id.'/participants')->assertOk();
        $this->postJson('/api/collab/slides/'.$this->slide->id.'/heartbeat', ['cursor' => ['x' => 1, 'y' => 1]])->assertOk();
        $this->get(route('dashboard'))->assertOk();
        $this->get(route('presentation.slides.show', [$this->deck, $this->slide]))->assertOk();
        $this->get(route('export.decks.pdf', [$this->deck]))->assertSuccessful();
        $this->get(route('export.decks.pptx', [$this->deck]))->assertSuccessful();
        $this->get(route('editor.slides.show', [$this->deck, $this->slide]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Canvas/Editor')->where('canEdit', false));
    }

    public function test_editors_and_admins_get_the_editable_canvas(): void
    {
        foreach ([$this->editor, $this->admin, $this->owner] as $writer) {
            $this->actingAs($writer)
                ->get(route('editor.slides.show', [$this->deck, $this->slide]))
                ->assertInertia(fn ($page) => $page->where('canEdit', true));
        }
    }

    public function test_the_dashboard_tells_the_page_which_projects_a_person_may_change(): void
    {
        $own = Project::create(['user_id' => $this->viewer->id, 'team_id' => $this->team->id, 'name' => 'Mine', 'slug' => 'mine']);

        $this->actingAs($this->viewer)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('projects.0.can_edit', false));
        $this->actingAs($this->editor)->get(route('dashboard'))->assertOk();

        $this->assertNotNull($own);
    }

    // ---- roles are per team ---------------------------------------------

    public function test_a_viewer_in_one_team_is_still_an_editor_in_another(): void
    {
        $other = Team::forceCreate(['user_id' => $this->owner->id, 'name' => 'Other Co', 'personal_team' => false]);
        $otherProject = $this->projectIn($other, 'Other');
        $other->users()->attach($this->viewer, ['role' => 'editor']);
        $viewer = $this->viewer->fresh();

        // Current team is where the viewer is read-only: new projects are refused.
        $this->actingAs($viewer)->postJson('/api/projects', ['name' => 'Nope'])->assertForbidden();
        // Their editor role in the other team still works there.
        $this->actingAs($viewer)->putJson('/api/projects/'.$otherProject->id, ['name' => 'Renamed'])->assertOk();
        // And the viewer team's content is still closed to them.
        $this->actingAs($viewer)->putJson('/api/projects/'.$this->project->id, ['name' => 'Nope'])->assertForbidden();

        $viewer->switchTeam($other);
        $this->actingAs($viewer->fresh())->postJson('/api/projects', ['name' => 'Fine'])->assertCreated();
    }

    public function test_someone_outside_the_team_still_cannot_reach_its_content(): void
    {
        $outsider = User::factory()->withPersonalTeam()->create();

        $this->actingAs($outsider)->putJson('/api/slides/'.$this->slide->id, ['title' => 'Nope'])->assertNotFound();
    }
}
