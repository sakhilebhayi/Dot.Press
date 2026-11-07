<?php

namespace Tests\Feature\Press\Security;

use App\Models\PresentSession;
use App\Models\User;
use Tests\Feature\Press\AccessTestCase;

/**
 * A live session link is a bearer secret. It must not be cached, indexed or leaked in a Referer; it must stop
 * working when the person who made it loses the right to see the deck; and a room of people behind one address must
 * be able to follow a talk.
 */
class LiveSessionExposureTest extends AccessTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['security.live_access_cache_seconds' => 0]);
    }

    private function start(User $as): array
    {
        return $this->actingAs($as)->postJson("/api/decks/{$this->deck->id}/present-sessions", ['slide_index' => 0, 'step' => 0])->assertCreated()->json();
    }

    private function guest(): void
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_live_and_remote_pages_are_private_responses(): void
    {
        $s = $this->start($this->editor);
        $this->guest();

        foreach (['/live/'.$s['token'], $s['remote_url']] as $url) {
            $r = $this->get($url)->assertOk();
            $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'), $url);
            $this->assertStringContainsString('noindex', (string) $r->headers->get('X-Robots-Tag'), $url);
            $this->assertSame('no-referrer', $r->headers->get('Referrer-Policy'), $url);
        }
        $state = $this->getJson('/live/'.$s['token'].'/state')->assertOk();
        $this->assertStringContainsString('noindex', (string) $state->headers->get('X-Robots-Tag'));
    }

    public function test_the_link_dies_when_the_presenter_leaves_the_team(): void
    {
        $s = $this->start($this->viewer);
        $this->guest();
        $this->getJson('/live/'.$s['token'].'/state')->assertOk();

        $this->team->users()->detach($this->viewer->id);
        $this->guest();

        $this->get('/live/'.$s['token'])->assertNotFound();
        $this->getJson('/live/'.$s['token'].'/state')->assertStatus(410);
        $this->postJson('/live/'.$s['token'].'/command', ['action' => 'next', 'k' => explode('k=', $s['remote_url'])[1]])->assertStatus(410);
        $this->assertNotNull(PresentSession::first()->ended_at, 'the session is closed for good, not just hidden');
    }

    public function test_the_link_dies_when_the_deck_is_archived_or_made_private_to_others(): void
    {
        $s = $this->start($this->viewer);
        $this->guest();
        $this->getJson('/live/'.$s['token'].'/state')->assertOk();

        $this->deck->forceFill(['visibility' => 'private'])->save();
        $this->guest();
        $this->getJson('/live/'.$s['token'].'/state')->assertStatus(410);

        $again = $this->start($this->editor);
        $this->guest();
        $this->getJson('/live/'.$again['token'].'/state')->assertOk();
        $this->deck->forceFill(['archived_at' => now()])->save();
        $this->guest();
        $this->getJson('/live/'.$again['token'].'/state')->assertStatus(410);
    }

    public function test_a_session_of_a_person_who_keeps_access_keeps_working(): void
    {
        $s = $this->start($this->editor);
        $this->guest();
        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/live/'.$s['token'].'/state')->assertOk();
        }
    }

    public function test_a_room_of_followers_behind_one_address_is_not_locked_out(): void
    {
        $s = $this->start($this->editor);
        $this->guest();

        // 40 people, each opening the page once and then polling every 2 seconds for 15 seconds, all from one NAT address.
        $codes = [];
        for ($i = 0; $i < 40; $i++) {
            $codes[] = $this->get('/live/'.$s['token'])->status();
        }
        for ($tick = 0; $tick < 8; $tick++) {
            for ($i = 0; $i < 40; $i++) {
                $codes[] = $this->getJson('/live/'.$s['token'].'/state')->status();
            }
        }
        $this->assertSame([200], array_values(array_unique($codes)), 'nobody in the room may be told to slow down');
    }
}
