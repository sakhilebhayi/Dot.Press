<?php

namespace Tests\Feature\Sso;

use App\Mail\SsoClaimCodeMail;
use App\Models\Team;
use App\Models\User;
use App\Sso\PressProvisioner;
use Dot\SsoClient\Tests\Support\FakeIdp;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** R-54 rollout: Dot.Press behind DOTSSO_ENABLED. Off, nothing changes; on, every entry goes to InfoDot. */
class CentralSignInRolloutTest extends TestCase
{
    use RefreshDatabase;

    private FakeIdp $idp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->idp = (new FakeIdp)->install();
        config(['dotsso.provisioner' => PressProvisioner::class, 'dotsso.enabled' => false]);
        Mail::fake();
    }

    private function on(): void
    {
        config(['dotsso.enabled' => true]);
    }

    private function signInVia(array $claims = [], array $session = [])
    {
        $response = $this->withSession($session)->get('/sso/login')->assertRedirect();
        $authorize = FakeIdp::authorizeParams($response->headers->get('Location'));
        $code = $this->idp->approve($authorize, $claims);

        return $this->get('/sso/callback?'.http_build_query(['code' => $code, 'state' => $authorize['state']]));
    }

    public function test_the_users_table_has_a_nullable_unique_infodot_user_uuid(): void
    {
        $a = User::factory()->create();
        $this->assertNull($a->infodot_user_uuid);
        User::factory()->create();

        $a->forceFill(['infodot_user_uuid' => 'x'])->save();
        $this->expectException(QueryException::class);
        User::factory()->create()->forceFill(['infodot_user_uuid' => 'x'])->save();
    }

    public function test_with_the_flag_off_nothing_changes(): void
    {
        $this->get('/login')->assertOk();
        $this->get('/sso/login')->assertNotFound();
        $this->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p->where('ssoLogin', null)->where('ssoRegister', null));
    }

    public function test_with_the_flag_on_the_local_pages_go_to_infodot_sign_in(): void
    {
        $this->on();

        $this->get('/login')->assertRedirect(route('dotsso.login'));
        $this->get('/register')->assertRedirect(route('dotsso.login', ['screen' => 'register']));
        $this->get('/forgot-password')->assertRedirect(route('dotsso.login'));
        $this->post('/login', ['email' => 'a@b.test', 'password' => 'x'])->assertRedirect(route('dotsso.login'));
    }

    public function test_the_landing_links_straight_to_the_central_sign_in(): void
    {
        $this->on();

        $this->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('ssoLogin', route('dotsso.login'))
            ->where('ssoRegister', route('dotsso.login', ['screen' => 'register'])));
        $this->get('/does-not-exist')->assertNotFound()->assertSee(route('dotsso.login'), false);
    }

    public function test_the_break_glass_switch_brings_the_local_login_back(): void
    {
        $this->on();
        config(['dotsso.allow_local_login' => true]);

        $this->get('/login')->assertOk();
        $this->get('/')->assertInertia(fn (Assert $p) => $p->where('ssoLogin', null));
    }

    public function test_a_signed_out_deep_link_to_a_deck_survives_the_round_trip(): void
    {
        $this->on();
        $this->get('/decks/12')->assertRedirect('/login');
        $this->get('/login')->assertRedirect(route('dotsso.login'));

        $this->signInVia()->assertRedirect('/decks/12');
        $this->assertAuthenticated();
    }

    public function test_an_expired_session_on_an_inertia_visit_leaves_by_a_full_page_load_and_keeps_the_page(): void
    {
        $this->on();

        $this->get('/decks/12', ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'X-Inertia-Version' => ''])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', route('dotsso.login'));

        $this->signInVia()->assertRedirect('/decks/12');
    }

    public function test_a_first_sign_in_creates_the_account_with_the_infodot_id_and_a_personal_team(): void
    {
        $this->on();

        $this->signInVia(['sub' => 'infodot-uuid-9', 'email' => 'new@example.test', 'name' => 'Nia New'])->assertRedirect('/dashboard');

        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertSame('infodot-uuid-9', $user->infodot_user_uuid);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Team::where('user_id', $user->id)->where('personal_team', true)->exists());
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_unverified_local_user_claims_with_a_code_and_keeps_password_tokens_and_team(): void
    {
        $this->on();
        $user = User::factory()->withPersonalTeam()->create(['email' => 'ada@example.test', 'email_verified_at' => null, 'password' => Hash::make('old-password-1')]);
        $user->createToken('bench', ['*']);
        $team = $user->ownedTeams()->first();
        $hash = $user->password;

        $this->signInVia(['sub' => 'infodot-uuid-5'])->assertRedirect(route('dotsso.claim'));
        $this->assertGuest();

        $sent = null;
        Mail::assertSent(SsoClaimCodeMail::class, function ($m) use (&$sent) {
            $sent = $m->code;

            return $m->hasTo('ada@example.test');
        });

        $this->get('/sso/claim')->assertOk()->assertSee('Confirm it is you')->assertDontSee($sent);
        $this->post('/sso/claim', ['code' => $sent])->assertRedirect('/dashboard');

        $user->refresh();
        $this->assertSame('infodot-uuid-5', $user->infodot_user_uuid);
        $this->assertSame($hash, $user->password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(1, $user->tokens()->count());
        $this->assertTrue($team->fresh()->is($user->ownedTeams()->first()));
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_claim_page_is_one_form_in_the_spine_with_a_labelled_code_field(): void
    {
        $this->on();
        User::factory()->create(['email' => 'ada@example.test', 'email_verified_at' => null]);
        $this->signInVia()->assertRedirect(route('dotsso.claim'));

        $html = $this->get('/sso/claim')->assertOk()->getContent();

        $this->assertStringContainsString('autocomplete="one-time-code"', $html);
        $this->assertStringContainsString('inputmode="numeric"', $html);
        $this->assertMatchesRegularExpression('/<label[^>]+for="code"/', $html);
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('er-form', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_the_sign_in_failure_page_is_the_spine_error_page(): void
    {
        $this->on();

        $html = $this->get('/sso/callback?state=a&code=b')->getContent();

        $this->assertStringContainsString('er-title', $html);
        $this->assertStringContainsString('Reference:', $html);
    }

    // ---- carve-outs: nothing of these is touched by the interception --------------------------------------------

    public function test_the_cookie_session_api_the_editor_uses_still_works_with_the_default_guard(): void
    {
        $this->on();
        $this->assertNotSame('sanctum', config('dotsso.guard'), 'the package default (web) must not be hand-set');
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)->withHeaders(['Referer' => 'http://localhost/dashboard'])->getJson('/api/decks')->assertOk();
        $this->getJson('/api/user')->assertOk()->assertJsonPath('email', $user->email);
    }

    public function test_a_real_sanctum_bearer_token_works_while_sso_is_on(): void
    {
        $this->on();
        $token = User::factory()->create()->createToken('t')->plainTextToken;

        $this->getJson('/api/user')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/user')->assertOk();
    }

    public function test_public_share_and_present_links_and_the_machine_doors_are_never_redirected(): void
    {
        $this->on();
        $token = str_repeat('a', 40);

        foreach (['/s/'.$token, '/s/'.$token.'/document', '/live/'.$token, '/remote/'.$token, '/live/'.$token.'/state', '/sitemap.xml', '/up', '/cookies'] as $path) {
            $r = $this->get($path);
            $this->assertNotSame(302, $r->getStatusCode(), $path.' must not be sent to sign-in');
            $this->assertStringNotContainsString('/sso/login', (string) $r->headers->get('Location'), $path);
        }

        $this->get('/guardian/health')->assertStatus(503); // fails closed with no token configured, as before
        $this->get('/auth/ecosystem')->assertRedirect('/login');
    }
}
