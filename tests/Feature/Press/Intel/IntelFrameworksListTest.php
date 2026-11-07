<?php

namespace Tests\Feature\Press\Intel;

use App\Models\User;
use App\Services\Press\Intel\Frameworks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntelFrameworksListTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_framework_list_needs_a_signed_in_user(): void
    {
        $this->getJson('/api/intel/frameworks')->assertUnauthorized();
    }

    public function test_the_list_names_every_structure_the_story_and_outline_tools_accept(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $res = $this->actingAs($user)->getJson('/api/intel/frameworks')->assertOk();

        $this->assertSame(Frameworks::ids(), array_column($res->json('frameworks'), 'id'));
        $res->assertJsonStructure(['frameworks' => [['id', 'name', 'summary', 'beats' => [['id', 'label', 'optional']]]]]);
        $this->assertContains('free', array_column($res->json('frameworks'), 'id'));
    }
}
