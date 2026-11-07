<?php

namespace Tests\Feature\Press\Security;

use App\Models\DeckShare;
use App\Models\User;
use Tests\Feature\Press\AccessTestCase;

/**
 * Sharing by number must not be a directory of every account on the platform: a user id outside the people you
 * already work with answers exactly like an id that does not exist.
 */
class SharingEnumerationTest extends AccessTestCase
{
    public function test_a_user_id_from_another_team_is_not_resolved_and_leaks_nothing(): void
    {
        $stranger = User::factory()->withPersonalTeam()->create(['name' => 'Private Person', 'email' => 'private@elsewhere.test']);

        $hit = $this->actingAs($this->owner)->postJson("/api/decks/{$this->deck->id}/sharing/people", ['user_id' => $stranger->id, 'role' => 'viewer']);
        $miss = $this->actingAs($this->owner)->postJson("/api/decks/{$this->deck->id}/sharing/people", ['user_id' => 999999, 'role' => 'viewer']);

        $hit->assertStatus(422);
        $miss->assertStatus(422);
        $this->assertSame($miss->json(), $hit->json(), 'a real id and a missing id must be indistinguishable');
        $this->assertStringNotContainsString('Private Person', $hit->getContent());
        $this->assertStringNotContainsString('private@elsewhere.test', $hit->getContent());
        $this->assertSame(0, DeckShare::where('user_id', $stranger->id)->count());
    }

    public function test_a_teammate_can_still_be_shared_with_by_id_and_anyone_by_email(): void
    {
        $other = $this->member('viewer', 'Team Mate');
        $this->actingAs($this->owner)->postJson("/api/decks/{$this->deck->id}/sharing/people", ['user_id' => $other->id, 'role' => 'commenter'])->assertCreated();

        $stranger = User::factory()->withPersonalTeam()->create(['email' => 'friend@elsewhere.test']);
        $this->actingAs($this->owner)->postJson("/api/decks/{$this->deck->id}/sharing/people", ['email' => 'friend@elsewhere.test', 'role' => 'viewer'])->assertCreated();
        $this->assertSame(1, DeckShare::where('user_id', $stranger->id)->count());
    }

    public function test_adding_people_by_email_is_rate_limited_per_person(): void
    {
        $last = null;
        for ($i = 0; $i < 40; $i++) {
            $last = $this->actingAs($this->owner)->postJson("/api/decks/{$this->deck->id}/sharing/people", ['email' => "nobody{$i}@elsewhere.test", 'role' => 'viewer']);
        }
        $this->assertSame(429, $last->status(), 'guessing addresses must hit a limit well under 40 a minute');
    }
}
