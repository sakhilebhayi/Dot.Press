<?php

namespace Tests\Feature;

use App\Actions\Ecosystem\SyncTeamsFromInfoDot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Jetstream\Jetstream;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The read-only "viewer" role: what it is, which InfoDot roles map to it, and
 * that the team screen offers it. What a viewer can and cannot do across the
 * app is in ViewerWriteProtectionTest.
 */
class ViewerRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_viewer_role_holds_read_and_nothing_else(): void
    {
        $role = Jetstream::findRole('viewer');

        $this->assertNotNull($role);
        $this->assertSame('Viewer', $role->name);
        $this->assertSame(['read'], $role->permissions);
        foreach (['create', 'update', 'delete', '*'] as $permission) {
            $this->assertNotContains($permission, $role->permissions);
        }
    }

    public function test_the_existing_roles_are_unchanged(): void
    {
        $this->assertSame(['create', 'read', 'update', 'delete'], Jetstream::findRole('admin')->permissions);
        $this->assertSame(['read', 'create', 'update'], Jetstream::findRole('editor')->permissions);
        $this->assertSame(['admin', 'editor', 'viewer'], array_keys(Jetstream::$roles));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function infodotRoles(): array
    {
        return [
            'owner' => ['owner', 'admin'],
            'admin' => ['admin', 'admin'],
            'editor' => ['editor', 'editor'],
            'viewer' => ['viewer', 'viewer'],
            'unknown' => ['billing-contact', 'viewer'],
            'empty' => ['', 'viewer'],
            'case differs' => ['Admin', 'viewer'],
        ];
    }

    #[DataProvider('infodotRoles')]
    public function test_infodot_roles_map_to_local_roles_with_least_privilege_for_anything_else(string $infodot, string $local): void
    {
        $this->assertSame($local, SyncTeamsFromInfoDot::localRole($infodot));
        $this->assertNotNull(Jetstream::findRole($local), 'the mapped role must exist in this app');
    }

    public function test_a_team_member_with_the_viewer_role_may_read_but_not_create_or_update(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $team->users()->attach($viewer = User::factory()->create(), ['role' => 'viewer']);

        $this->assertTrue($viewer->hasTeamPermission($team, 'read'));
        $this->assertFalse($viewer->hasTeamPermission($team, 'create'));
        $this->assertFalse($viewer->hasTeamPermission($team, 'update'));
        $this->assertFalse($viewer->hasTeamPermission($team, 'delete'));
    }

    public function test_admins_can_pick_the_viewer_role_when_managing_the_team(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();

        $this->actingAs($owner)
            ->get('/teams/'.$owner->currentTeam->id)
            ->assertInertia(fn ($page) => $page
                ->where('availableRoles', fn ($roles) => collect($roles)->pluck('key')->all() === ['admin', 'editor', 'viewer']));
    }

    public function test_the_owner_can_invite_add_and_change_someone_to_the_viewer_role(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $team->users()->attach($member = User::factory()->create(), ['role' => 'editor']);

        $this->actingAs($owner)
            ->put('/teams/'.$team->id.'/members/'.$member->id, ['role' => 'viewer'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($member->fresh()->hasTeamRole($team->fresh(), 'viewer'));

        $this->actingAs($owner)
            ->post('/teams/'.$team->id.'/members', ['email' => 'new@example.com', 'role' => 'viewer'])
            ->assertSessionHasNoErrors();

        $this->assertSame('viewer', $team->fresh()->teamInvitations()->where('email', 'new@example.com')->value('role'));
    }
}
