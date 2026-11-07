<?php

namespace Tests\Feature\Press;

use App\Models\Asset;
use App\Models\Deck;
use App\Models\PresentSession;
use App\Models\Project;
use App\Models\Slide;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Live present sessions: a presenter starts one, a phone remote and followers use it by short polling.
 */
class PresentSessionTest extends TestCase
{
    use RefreshDatabase;

    private const NOTE = 'SPEAKER-ONLY-NOTE-7f3a';

    private User $owner;

    private Deck $deck;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('x');
        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->project = Project::create(['user_id' => $this->owner->id, 'team_id' => $this->owner->currentTeam->id, 'name' => 'P', 'slug' => 'p']);
        $this->deck = Deck::create(['project_id' => $this->project->id, 'user_id' => $this->owner->id, 'title' => 'Board deck', 'sort_order' => 0]);
        foreach (['One', 'Two', 'Three'] as $i => $title) {
            Slide::create([
                'deck_id' => $this->deck->id,
                'title' => $title,
                'notes' => self::NOTE.' '.$title,
                'sort_order' => $i,
                'canvas_state' => ['meta' => ['version' => 1]],
                'els' => [['id' => 'e'.$i, 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 50, 'doc' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Body '.$title]]]]]]],
                'hidden' => $i === 1,
            ]);
        }
    }

    private function start(?User $as = null, ?Deck $deck = null): array
    {
        return $this->actingAs($as ?? $this->owner)
            ->postJson('/api/decks/'.($deck ?? $this->deck)->id.'/present-sessions', ['slide_index' => 0, 'step' => 0])
            ->assertCreated()
            ->json();
    }

    private function asGuest(): void
    {
        $this->app['auth']->forgetGuards();
    }

    private function key(array $session): string
    {
        return explode('k=', $session['remote_url'])[1];
    }

    public function test_a_session_has_a_long_random_token_and_two_links(): void
    {
        $session = $this->start();

        $this->assertSame(40, strlen($session['token']));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $session['token']);
        $this->assertStringEndsWith('/live/'.$session['token'], $session['audience_url']);
        $this->assertStringContainsString('/remote/'.$session['token'].'?k=', $session['remote_url']);
        $this->assertSame(32, strlen($this->key($session)));
        $this->assertTrue(now()->addHours(12)->diffInMinutes($session['expires_at'], true) < 2);
    }

    public function test_tokens_do_not_repeat_and_use_the_whole_alphabet(): void
    {
        $tokens = [];
        for ($i = 0; $i < 15; $i++) {
            $tokens[] = $this->start()['token'];
        }
        $this->assertCount(15, array_unique($tokens));
        $chars = count_chars(implode('', $tokens), 3);
        $this->assertGreaterThan(40, strlen($chars), 'a token built from a tiny alphabet would be guessable');
        // only one session per person and deck stays live
        $this->assertSame(1, PresentSession::whereNull('ended_at')->count());
    }

    public function test_only_people_who_can_view_the_deck_can_start_a_session(): void
    {
        $outsider = User::factory()->withPersonalTeam()->create();
        $this->actingAs($outsider)->postJson('/api/decks/'.$this->deck->id.'/present-sessions')->assertNotFound();
        $this->assertSame(0, PresentSession::count());

        $this->actingAs($this->owner)->postJson('/api/decks/'.$this->deck->id.'/present-sessions')->assertCreated();
    }

    public function test_guests_cannot_start_or_push(): void
    {
        $this->postJson('/api/decks/'.$this->deck->id.'/present-sessions')->assertUnauthorized();
        $this->putJson('/api/present-sessions/'.str_repeat('a', 40), ['slide_index' => 0, 'step' => 0])->assertUnauthorized();
    }

    public function test_a_teammate_with_read_access_may_present_a_shared_deck(): void
    {
        $viewer = User::factory()->create();
        $this->owner->currentTeam->users()->attach($viewer, ['role' => 'viewer']);
        $viewer->switchTeam($this->owner->currentTeam);

        $this->start($viewer)['token'];
        $this->assertSame(1, PresentSession::count());
    }

    public function test_only_the_presenter_updates_or_ends_a_session(): void
    {
        $session = $this->start();
        $mate = User::factory()->create();
        $this->owner->currentTeam->users()->attach($mate, ['role' => 'editor']);
        $mate->switchTeam($this->owner->currentTeam);

        $this->actingAs($mate)->putJson('/api/present-sessions/'.$session['token'], ['slide_index' => 2, 'step' => 0])->assertNotFound();
        $this->actingAs($mate)->deleteJson('/api/present-sessions/'.$session['token'])->assertNotFound();
        $this->assertNull(PresentSession::first()->ended_at);

        $this->actingAs($this->owner)->putJson('/api/present-sessions/'.$session['token'], ['slide_index' => 2, 'step' => 1])->assertOk();
        $this->assertSame(2, PresentSession::first()->slide_index);
        $this->assertSame(1, PresentSession::first()->step);
    }

    public function test_updates_are_validated(): void
    {
        $session = $this->start();
        $this->actingAs($this->owner);
        foreach ([['slide_index' => -1, 'step' => 0], ['slide_index' => 'x', 'step' => 0], ['slide_index' => 0], ['slide_index' => 0, 'step' => 0, 'cover' => 'red'], ['slide_index' => 99999, 'step' => 0]] as $bad) {
            $this->putJson('/api/present-sessions/'.$session['token'], $bad)->assertUnprocessable();
        }
        $this->putJson('/api/present-sessions/short', ['slide_index' => 0, 'step' => 0])->assertNotFound();
    }

    public function test_the_public_state_has_only_index_step_cover_and_count_and_never_notes(): void
    {
        $session = $this->start();
        $this->actingAs($this->owner)->putJson('/api/present-sessions/'.$session['token'], ['slide_index' => 2, 'step' => 1, 'cover' => 'black'])->assertOk();
        $this->asGuest();

        $response = $this->getJson('/live/'.$session['token'].'/state')->assertOk();
        $response->assertExactJson(['slide' => 2, 'step' => 1, 'cover' => 'black', 'ended' => false, 'rev' => 2, 'count' => 3]);
        $this->assertStringNotContainsString(self::NOTE, $response->getContent());

        // a wrong key never unlocks more than the public state
        $wrong = $this->getJson('/live/'.$session['token'].'/state?k='.str_repeat('z', 32))->assertOk();
        $this->assertArrayNotHasKey('notes', $wrong->json());
        $this->assertStringNotContainsString(self::NOTE, $wrong->getContent());
    }

    public function test_the_remote_key_adds_title_and_notes_for_the_phone_only(): void
    {
        $session = $this->start();
        $r = $this->getJson('/live/'.$session['token'].'/state?k='.$this->key($session))->assertOk();
        $this->assertSame('One', $r->json('title'));
        $this->assertSame(self::NOTE.' One', $r->json('notes'));
        $this->assertSame('Two', $r->json('next_title'));
    }

    public function test_followers_get_the_slides_but_never_notes_and_hidden_slides_are_blank(): void
    {
        $session = $this->start();
        $page = $this->get('/live/'.$session['token'])->assertOk();
        $props = $page->viewData('page')['props'];

        $this->assertStringNotContainsString(self::NOTE, json_encode($props));
        $this->assertSame('One', $props['slides'][0]['title']);
        $this->assertSame([], $props['slides'][1]['els'], 'a hidden slide keeps its place but not its content');
        $this->assertNull($props['slides'][1]['title']);
        $this->assertSame($session['token'], $props['follow']['token']);
        $this->assertStringNotContainsString(self::NOTE, $this->get('/remote/'.$session['token'])->getContent());
    }

    public function test_the_audience_window_never_gets_notes_but_the_presenter_pages_do(): void
    {
        $page = $this->actingAs($this->owner)->get('/decks/'.$this->deck->id.'/audience?c=abc123')->assertOk();
        $this->assertStringNotContainsString(self::NOTE, json_encode($page->viewData('page')['props']));
        $this->assertSame('abc123', $page->viewData('page')['props']['channelKey']);

        $pres = $this->get('/decks/'.$this->deck->id.'/presenter')->assertOk();
        $this->assertStringContainsString(self::NOTE, json_encode($pres->viewData('page')['props']));
        $this->assertSame('Press/Presenter', $pres->viewData('page')['component']);
        $this->assertSame('Press/Player', $this->get('/decks/'.$this->deck->id.'/present')->viewData('page')['component']);
    }

    public function test_presenter_pages_need_a_login_and_team_access(): void
    {
        $this->get('/decks/'.$this->deck->id.'/present')->assertRedirect('/login');
        $this->actingAs(User::factory()->withPersonalTeam()->create())->get('/decks/'.$this->deck->id.'/presenter')->assertNotFound();
    }

    public function test_the_state_uses_etags_so_polling_stays_cheap(): void
    {
        $session = $this->start();
        $first = $this->get('/live/'.$session['token'].'/state')->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $this->get('/live/'.$session['token'].'/state', ['If-None-Match' => $etag])->assertStatus(304);

        $this->actingAs($this->owner)->putJson('/api/present-sessions/'.$session['token'], ['slide_index' => 1, 'step' => 0])->assertOk();
        $this->asGuest();
        $this->get('/live/'.$session['token'].'/state', ['If-None-Match' => $etag])->assertOk()->assertJsonPath('slide', 1);
    }

    public function test_the_public_poll_does_not_start_a_session_or_set_cookies(): void
    {
        $session = $this->start();
        $this->asGuest();
        $response = $this->get('/live/'.$session['token'].'/state');
        $this->assertEmpty($response->headers->getCookies());
    }

    public function test_unknown_and_malformed_tokens_are_not_found(): void
    {
        $this->getJson('/live/'.str_repeat('a', 40).'/state')->assertNotFound();
        $this->getJson('/live/short/state')->assertNotFound();
        $this->get('/live/'.str_repeat('a', 40))->assertNotFound();
        $this->postJson('/live/'.str_repeat('a', 40).'/command', ['action' => 'next', 'k' => 'x'])->assertNotFound();
    }

    public function test_a_session_expires_after_twelve_hours(): void
    {
        $session = $this->start();
        $this->travel(11)->hours();
        $this->getJson('/live/'.$session['token'].'/state')->assertOk();

        $this->travel(61)->minutes();
        $this->getJson('/live/'.$session['token'].'/state')->assertStatus(410)->assertJson(['ended' => true]);
        $this->postJson('/live/'.$session['token'].'/command', ['action' => 'next', 'k' => $this->key($session)])->assertStatus(410);
        $this->get('/live/'.$session['token'])->assertNotFound();
        $this->actingAs($this->owner)->putJson('/api/present-sessions/'.$session['token'], ['slide_index' => 0, 'step' => 0])->assertStatus(410);
    }

    public function test_ending_a_session_stops_it_everywhere(): void
    {
        $session = $this->start();
        $this->actingAs($this->owner)->deleteJson('/api/present-sessions/'.$session['token'])->assertNoContent();
        $this->asGuest();
        $this->getJson('/live/'.$session['token'].'/state')->assertStatus(410)->assertJson(['ended' => true]);
        $this->postJson('/live/'.$session['token'].'/command', ['action' => 'next', 'k' => $this->key($session)])->assertStatus(410);
        $this->get('/remote/'.$session['token'])->assertNotFound();
    }

    public function test_remote_commands_need_the_key_and_reach_the_presenter_once_in_order(): void
    {
        $session = $this->start();
        $url = '/live/'.$session['token'].'/command';

        $this->postJson($url, ['action' => 'next'])->assertUnprocessable();
        $this->postJson($url, ['action' => 'next', 'k' => str_repeat('q', 32)])->assertForbidden();
        $this->postJson($url, ['action' => 'drop-database', 'k' => $this->key($session)])->assertUnprocessable();
        $this->assertSame([], PresentSession::first()->command['queue']);

        foreach (['next', 'next', 'blackout', 'prev'] as $action) {
            $this->postJson($url, ['action' => $action, 'k' => $this->key($session)])->assertStatus(202);
        }

        $push = $this->actingAs($this->owner)->putJson('/api/present-sessions/'.$session['token'], ['slide_index' => 0, 'step' => 0])->assertOk();
        $this->assertSame(['next', 'next', 'blackout', 'prev'], array_column($push->json('commands'), 'action'));

        $again = $this->actingAs($this->owner)->putJson('/api/present-sessions/'.$session['token'], ['slide_index' => 0, 'step' => 0])->assertOk();
        $this->assertSame([], $again->json('commands'), 'a command is delivered once');
    }

    public function test_the_command_queue_is_capped(): void
    {
        $session = $this->start();
        for ($i = 0; $i < 30; $i++) {
            RateLimiter::clear('cmd|'.$session['token'].'|127.0.0.1');
            $this->postJson('/live/'.$session['token'].'/command', ['action' => 'next', 'k' => $this->key($session)])->assertStatus(202);
        }
        $this->assertCount(20, PresentSession::first()->command['queue']);
    }

    public function test_polling_is_rate_limited_per_session_and_address(): void
    {
        $session = $this->start();
        $this->asGuest();
        $url = '/live/'.$session['token'].'/state';
        // 1200 a minute from one address: a room of 20 people polling every second (R-40 security pass).
        for ($i = 0; $i < 1200; $i++) {
            $this->get($url)->assertOk();
        }
        $this->get($url)->assertStatus(429);

        // a different session has its own budget
        $other = $this->start();
        $this->asGuest();
        $this->get('/live/'.$other['token'].'/state')->assertOk();
    }

    public function test_commands_are_rate_limited(): void
    {
        $session = $this->start();
        $this->asGuest();
        for ($i = 0; $i < 90; $i++) {
            $this->postJson('/live/'.$session['token'].'/command', ['action' => 'next', 'k' => $this->key($session)])->assertStatus(202);
        }
        $this->postJson('/live/'.$session['token'].'/command', ['action' => 'next', 'k' => $this->key($session)])->assertStatus(429);
    }

    public function test_starting_sessions_is_rate_limited_per_person(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($this->owner)->postJson('/api/decks/'.$this->deck->id.'/present-sessions')->assertCreated();
        }
        $this->actingAs($this->owner)->postJson('/api/decks/'.$this->deck->id.'/present-sessions')->assertStatus(429);
    }

    public function test_signed_asset_urls_work_without_a_session_and_unsigned_ones_do_not(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('a/photo.png', 'PNGDATA');
        $asset = Asset::create(['project_id' => $this->project->id, 'uploaded_by' => $this->owner->id, 'disk' => 'local', 'path' => 'a/photo.png', 'original_name' => 'photo.png', 'mime_type' => 'image/png', 'size' => 7, 'width' => 10, 'height' => 5]);
        $this->deck->slides()->first()->update(['els' => [['id' => 'i', 'type' => 'image', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'src' => ['asset' => $asset->id]]]]);

        $page = $this->actingAs($this->owner)->get('/decks/'.$this->deck->id.'/present');
        $url = $page->viewData('page')['props']['assets'][(string) $asset->id]['url'];
        $this->assertStringStartsWith('/present-asset/'.$asset->id.'?', $url);
        $this->asGuest();

        $res = $this->get($url)->assertOk();
        $this->assertSame('PNGDATA', file_get_contents($res->baseResponse->getFile()->getPathname()));
        $this->assertStringContainsString('sandbox', $res->headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertEmpty($res->headers->getCookies());

        $this->get('/present-asset/'.$asset->id)->assertForbidden();
        $this->get(preg_replace('/signature=[a-f0-9]+/', 'signature=deadbeef', $url))->assertForbidden();
        $this->get(URL::temporarySignedRoute('press.asset', now()->subMinute(), ['asset' => $asset->id, 'v' => 'web'], absolute: false))->assertForbidden();
    }

    public function test_assets_from_another_project_are_never_put_in_the_map(): void
    {
        Storage::fake('local');
        $stranger = User::factory()->withPersonalTeam()->create();
        $theirs = Project::create(['user_id' => $stranger->id, 'team_id' => $stranger->currentTeam->id, 'name' => 'Theirs', 'slug' => 'theirs']);
        $secret = Asset::create(['project_id' => $theirs->id, 'uploaded_by' => $stranger->id, 'disk' => 'local', 'path' => 'x.png', 'original_name' => 'secret.png', 'mime_type' => 'image/png', 'size' => 1]);
        $this->deck->slides()->first()->update(['els' => [['id' => 'i', 'type' => 'image', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'src' => ['asset' => $secret->id]]]]);

        $page = $this->actingAs($this->owner)->get('/decks/'.$this->deck->id.'/present');
        $this->assertSame([], $page->viewData('page')['props']['assets']);
    }
}
