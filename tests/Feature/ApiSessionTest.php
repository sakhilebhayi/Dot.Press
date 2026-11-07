<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * After a good sign-in (InfoDot hands over, the app signs the person in) the browser calls /api with the session
 * cookie. A host .env that still lists an old SANCTUM_STATEFUL_DOMAINS must not turn that into 401 "Unauthenticated".
 */
class ApiSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_same_origin_call_gets_a_session_even_when_the_env_lists_other_stateful_domains(): void
    {
        config(['sanctum.stateful' => ['pres.infodot.app'], 'app.url' => 'https://pres.infodot.co.za']);

        $response = $this->withServerVariables(['HTTP_HOST' => 'pres.infodot.co.za', 'HTTPS' => 'on'])
            ->getJson('https://pres.infodot.co.za/api/decks', ['Referer' => 'https://pres.infodot.co.za/dashboard']);

        $response->assertUnauthorized();
        $this->assertNotEmpty(collect($response->headers->getCookies())->map->getName()->filter(fn ($n) => str_contains($n, 'session'))->all(), 'the API answer started a session, so a signed in browser would be recognised');
    }

    public function test_a_signed_in_browser_call_with_the_session_cookie_is_recognised(): void
    {
        config(['sanctum.stateful' => ['pres.infodot.app']]);
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)->withHeaders(['Referer' => 'http://localhost/dashboard'])->getJson('/api/decks')->assertOk();
    }

    public function test_another_site_naming_itself_in_the_referer_does_not_get_a_session(): void
    {
        config(['sanctum.stateful' => ['pres.infodot.app'], 'app.url' => 'https://pres.infodot.co.za']);

        $response = $this->getJson('https://pres.infodot.co.za/api/decks', ['Referer' => 'https://evil.example/page']);

        $response->assertUnauthorized();
        $this->assertEmpty(collect($response->headers->getCookies())->map->getName()->filter(fn ($n) => str_contains($n, 'session'))->all());
    }
}
