<?php

namespace Tests\Feature\Press;

use App\Http\Middleware\EnsureCompanyEntitled;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Deck;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * R-50: Dot.Press honours what InfoDot says a company holds. At sign-in only entitled companies are admitted and
 * synced; during a session the current company is re-checked (cached), a company that does not hold the platform
 * gets a plain 403 with no data, and any failure at InfoDot lets people carry on.
 */
class CompanyEntitlementTest extends AccessTestCase
{
    private const REDEEM = 'https://infodot.test/api/ecosystem/redeem';

    private const ACME = '11111111-1111-4111-8111-111111111111';

    private const GLOBEX = '22222222-2222-4222-8222-222222222222';

    private string $bearer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bearer = Str::random(40);

        config([
            'inertia.testing.ensure_pages_exist' => false,
            'ecosystem.infodot' => [
                'redeem_url' => self::REDEEM, 'platform' => 'press', 'secret' => $this->bearer, 'timeout' => 2,
                'provision' => true, 'entitlement_check' => true, 'platforms_url' => null,
            ],
            'ecosystem.platforms.infodot.url' => 'https://infodot.test',
        ]);

        // The team every AccessTestCase person works in is the InfoDot company "Acme".
        $this->team->forceFill(['personal_team' => false, 'infodot_team_uuid' => self::ACME, 'name' => 'Acme Ltd'])->save();
    }

    private static function platformsUrl(string $uuid): string
    {
        return 'https://infodot.test/api/ecosystem/companies/'.$uuid.'/platforms*';
    }

    /** The read endpoint's body, as InfoDot's CompanyPlatformsEndpointTest asserts it. */
    private static function holds(string $uuid, bool $entitled, string $status): array
    {
        return [
            'platform' => 'press',
            'company' => ['uuid' => $uuid, 'personal' => false],
            'platforms' => [
                ['key' => 'finance', 'name' => 'Dot.Finance', 'status' => 'included', 'entitled' => true, 'is_core' => true],
                ['key' => 'press', 'name' => 'Dot.Press', 'status' => $status, 'entitled' => $entitled, 'is_core' => false, 'access_mode' => 'everyone'],
                ['key' => 'hr', 'name' => 'Dot.HR', 'status' => 'free_while_pricing_finalised', 'entitled' => false, 'is_core' => false],
            ],
        ];
    }

    private function infodotSays(string $uuid, bool $entitled, string $status = 'active'): void
    {
        Http::fake([self::platformsUrl($uuid) => Http::response(self::holds($uuid, $entitled, $status))]);
    }

    private function browser(User $user): static
    {
        return $this->actingAs($user)->withHeaders(['Referer' => 'http://localhost/dashboard']);
    }

    // ---- sign-in (redeem) -------------------------------------------------------------------------------------

    private function fromInfodot(): array
    {
        return ['Origin' => 'https://infodot.test'];
    }

    private function company(string $uuid, string $name, string $role, array $extra = []): array
    {
        return ['id' => random_int(1, 999), 'uuid' => $uuid, 'name' => $name, 'personal' => false, 'role' => $role, 'role_label' => ucfirst($role)] + $extra;
    }

    private function signIn(string $email, array $teams, ?string $current = null)
    {
        Http::swap(new Factory);
        Http::fake([
            self::REDEEM => Http::response([
                'platform' => 'press',
                'user' => ['id' => 1, 'uuid' => (string) Str::uuid(), 'name' => ucfirst(strstr($email, '@', true)), 'email' => $email, 'email_verified' => true],
                'teams' => $teams,
                'current_team_id' => 1,
                'current_team_uuid' => $current,
            ]),
            // Any session check that follows gets a clear "holds it".
            'https://infodot.test/api/ecosystem/companies/*' => fn (Request $r) => Http::response(self::holds('x', true, 'active')),
        ]);

        auth()->logout();

        return $this->post(route('ecosystem.auth'), ['token' => 'one-time'], $this->fromInfodot());
    }

    public function test_sign_in_admits_a_company_marked_entitled(): void
    {
        $this->signIn('ann@globex.test', [$this->company(self::GLOBEX, 'Globex', 'owner', ['entitled' => true, 'access' => null])], self::GLOBEX)->assertRedirect(route('dashboard'));

        $team = Team::where('infodot_team_uuid', self::GLOBEX)->firstOrFail();
        $ann = User::where('email', 'ann@globex.test')->firstOrFail();

        $this->assertAuthenticatedAs($ann);
        $this->assertSame($team->id, $ann->current_team_id);
    }

    public function test_sign_in_does_not_create_update_or_enter_a_company_that_is_not_entitled(): void
    {
        $this->team->forceFill(['name' => 'Acme before'])->save();
        $before = $this->team->users()->count();

        $this->signIn('ann@globex.test', [
            $this->company(self::GLOBEX, 'Globex', 'owner', ['entitled' => true, 'access' => null]),
            $this->company(self::ACME, 'Acme renamed in InfoDot', 'admin', ['entitled' => false, 'access' => 'not_activated']),
        ], self::ACME)->assertRedirect(route('dashboard'));

        $ann = User::where('email', 'ann@globex.test')->firstOrFail();
        $acme = $this->team->fresh();

        $this->assertSame('Acme before', $acme->name, 'a company that does not hold the platform is not updated');
        $this->assertSame($before, $acme->users()->count());
        $this->assertFalse($ann->belongsToTeam($acme));
        $this->assertSame(Team::where('infodot_team_uuid', self::GLOBEX)->value('id'), $ann->current_team_id, 'InfoDot named the refused company as active; it is not entered');

        // A company nobody has here yet is not created either.
        $other = (string) Str::uuid();
        $this->signIn('ann@globex.test', [
            $this->company(self::GLOBEX, 'Globex', 'owner', ['entitled' => true, 'access' => null]),
            $this->company($other, 'Initech', 'owner', ['entitled' => false, 'access' => 'suspended']),
        ], $other);
        $this->assertNull(Team::where('infodot_team_uuid', $other)->first());
    }

    public function test_a_person_whose_current_company_lost_the_platform_is_moved_to_one_that_holds_it_and_keeps_their_membership(): void
    {
        $editor = $this->editor;
        $this->assertSame($this->team->id, $editor->current_team_id);

        $this->signIn($editor->email, [
            $this->company(self::ACME, 'Acme Ltd', 'editor', ['entitled' => false, 'access' => 'payment_required']),
            $this->company(self::GLOBEX, 'Globex', 'owner', ['entitled' => true, 'access' => null]),
        ], self::ACME)->assertRedirect(route('dashboard'));

        $editor = $editor->fresh();
        $this->assertSame(Team::where('infodot_team_uuid', self::GLOBEX)->value('id'), $editor->current_team_id);
        $this->assertTrue($editor->belongsToTeam($this->team->fresh()), 'the membership and the data stay untouched');
        $this->assertSame('editor', $editor->teamRole($this->team->fresh())->key);
    }

    public function test_sign_in_without_the_entitled_key_behaves_as_before(): void
    {
        $this->signIn('ann@globex.test', [$this->company(self::GLOBEX, 'Globex', 'owner')], self::GLOBEX)->assertRedirect(route('dashboard'));

        $ann = User::where('email', 'ann@globex.test')->firstOrFail();
        $this->assertAuthenticatedAs($ann);
        $this->assertSame(Team::where('infodot_team_uuid', self::GLOBEX)->value('id'), $ann->current_team_id);
    }

    public function test_sign_in_with_no_entitled_company_shows_the_plain_page_and_signs_nobody_in(): void
    {
        $response = $this->signIn('new@globex.test', [
            ['id' => 5, 'uuid' => (string) Str::uuid(), 'name' => 'New Person', 'personal' => true, 'role' => 'owner', 'entitled' => false, 'access' => 'no_personal_access'],
            $this->company(self::GLOBEX, 'Globex', 'owner', ['entitled' => false, 'access' => 'suspended']),
        ], self::GLOBEX);

        $response->assertStatus(403)
            ->assertSee('Dot.Press is suspended for your company.')
            ->assertSee('Globex cannot open or change anything here')
            ->assertSee('href="https://infodot.test/platforms/press"', false)
            ->assertSee('Open Platforms in InfoDot')
            ->assertDontSee('Stack trace')
            ->assertDontSee('one-time');

        $this->assertGuest();
        $this->assertNull(User::where('email', 'new@globex.test')->first(), 'nobody is created for a company that does not hold the platform');
        $this->assertNull(Team::where('infodot_team_uuid', self::GLOBEX)->first());
    }

    // ---- during a session -------------------------------------------------------------------------------------

    public function test_an_entitled_company_carries_on_and_infodot_is_asked_with_the_platform_named(): void
    {
        $this->infodotSays(self::ACME, true);

        $this->browser($this->editor)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Press/Library'));

        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && str_starts_with($r->url(), 'https://infodot.test/api/ecosystem/companies/'.self::ACME.'/platforms')
            && $r['platform'] === 'press'
            && $r->hasHeader('X-Dot-Platform', 'press')
            && $r->hasHeader('Authorization', 'Bearer '.$this->bearer));
    }

    public function test_a_company_that_does_not_hold_the_platform_gets_a_plain_403_with_no_data(): void
    {
        $this->infodotSays(self::ACME, false, 'free_while_pricing_finalised');

        $page = $this->browser($this->editor)->get('/dashboard');
        $page->assertStatus(403)
            ->assertSee('Dot.Press is not switched on for your company.')
            ->assertSee('href="https://infodot.test/platforms/press"', false)
            ->assertSee('Open Platforms in InfoDot')
            ->assertDontSee('Quarterly deck')
            ->assertDontSee('data-page', false);
        $this->assertStringContainsString('no-store', (string) $page->headers->get('Cache-Control'));

        // The editor and the print view are closed too.
        $this->browser($this->editor)->get('/decks/'.$this->deck->id)->assertStatus(403)->assertDontSee('Quarterly deck');
        $this->browser($this->editor)->get('/decks/'.$this->deck->id.'/print')->assertStatus(403)->assertDontSee('Quarterly deck');
    }

    public function test_an_inertia_visit_is_sent_to_the_refusal_page_with_a_full_page_load(): void
    {
        $this->infodotSays(self::ACME, false, 'suspended');

        $this->browser($this->editor)->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(HttpRequest::create('/'))])->get('/dashboard')
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', route('entitlement.show'))
            ->assertDontSee('Quarterly deck');

        $this->browser($this->editor)->get(route('entitlement.show'))
            ->assertStatus(403)
            ->assertSee('Dot.Press is suspended for your company.')
            ->assertDontSee('Quarterly deck');
    }

    public function test_the_refusal_address_sends_an_entitled_person_on_to_the_library(): void
    {
        $this->infodotSays(self::ACME, true);

        $this->browser($this->editor)->get(route('entitlement.show'))->assertRedirect(route('dashboard'));
    }

    public function test_the_browsers_api_calls_are_refused_and_nothing_is_read_or_changed(): void
    {
        $this->infodotSays(self::ACME, false, 'payment_required');
        $decks = Deck::count();

        $this->browser($this->editor)->getJson('/api/decks')
            ->assertStatus(403)
            ->assertExactJson([
                'message' => 'A payment is needed to keep using Dot.Press.',
                'error' => 'platform_not_entitled',
                'status' => 'payment_required',
                'platforms_url' => 'https://infodot.test/platforms/press',
            ]);

        $this->browser($this->editor)->getJson('/api/decks/'.$this->deck->id.'/document')->assertStatus(403)->assertDontSee('Quarterly deck');
        $this->browser($this->editor)->postJson('/api/decks', ['project_id' => $this->project->id, 'title' => 'Written while refused'])->assertStatus(403);
        $this->browser($this->editor)->patchJson('/api/decks/'.$this->deck->id, ['title' => 'Renamed while refused'])->assertStatus(403);

        $this->assertSame($decks, Deck::count());
        $this->assertSame('Quarterly deck', $this->deck->fresh()->title);
    }

    public function test_an_inertia_form_post_and_a_patch_or_delete_by_a_refused_company_change_nothing(): void
    {
        // It worked while the company held the platform; once the cached answer expires and InfoDot says no, it does not.
        $this->infodotSays(self::ACME, true);
        $this->browser($this->owner)->putJson('/api/decks/'.$this->deck->id, ['title' => 'Renamed while allowed'])->assertOk();

        $this->travel(11)->minutes();
        Http::swap(new Factory);
        $this->infodotSays(self::ACME, false, 'suspended');
        $inertia = ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(HttpRequest::create('/'))];
        $teams = Team::count();
        $name = $this->team->name;

        // A plain form POST and the browser's own PATCH and DELETE calls.
        $this->browser($this->owner)->post('/teams', ['name' => 'Made while refused'])->assertStatus(403);
        $this->browser($this->owner)->patchJson('/api/decks/'.$this->deck->id, ['title' => 'Patched while refused'])->assertStatus(403);
        $this->browser($this->owner)->deleteJson('/api/decks/'.$this->deck->id)->assertStatus(403);
        // An Inertia form POST, PUT and DELETE: sent to the refusal page with a full page load.
        $this->browser($this->owner)->withHeaders($inertia)->post('/teams', ['name' => 'Made while refused'])->assertStatus(409)->assertHeader('X-Inertia-Location');
        $this->browser($this->owner)->withHeaders($inertia)->put('/teams/'.$this->team->id, ['name' => 'Renamed while refused'])->assertStatus(409);
        $this->browser($this->owner)->withHeaders($inertia)->delete('/teams/'.$this->team->id)->assertStatus(409);

        $this->assertSame($teams, Team::count());
        $this->assertSame($name, $this->team->fresh()->name);
        $this->assertSame('Renamed while allowed', $this->deck->fresh()->title, 'the deck is exactly as the allowed update left it');
        $this->assertNull($this->deck->fresh()->deleted_at ?? null);
    }

    #[DataProvider('statuses')]
    public function test_the_page_is_worded_from_the_status(string $status, string $heading): void
    {
        $this->infodotSays(self::ACME, false, $status);

        $this->browser($this->editor)->get('/dashboard')->assertStatus(403)->assertSee($heading);
    }

    public static function statuses(): array
    {
        return [
            'not switched on' => ['free_to_activate', 'Dot.Press is not switched on for your company.'],
            'available to buy' => ['available_for_purchase', 'Dot.Press is not switched on for your company.'],
            'suspended' => ['suspended', 'Dot.Press is suspended for your company.'],
            'payment needed' => ['payment_required', 'A payment is needed to keep using Dot.Press.'],
            'ended' => ['subscription_expired', 'Dot.Press has ended for your company.'],
            'pending' => ['activation_pending', 'Dot.Press is still being switched on.'],
            'a status this app has never seen' => ['something_new', 'Dot.Press is not switched on for your company.'],
        ];
    }

    #[DataProvider('failures')]
    public function test_any_failure_at_infodot_lets_the_person_carry_on(callable $answer, int $calls = 1): void
    {
        if ($this->dataName() === 'secret not set') {
            config(['ecosystem.infodot.secret' => null]);
        }

        Http::fake([self::platformsUrl(self::ACME) => $answer]);
        Log::spy();

        $this->browser($this->editor)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Press/Library'));
        $this->browser($this->editor)->getJson('/api/decks')->assertOk()->assertDontSee('platform_not_entitled');

        // Remembered for the short window: asked at most once, logged once.
        Http::assertSentCount($calls);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains((string) $message, 'Could not confirm'))->once();
        $this->assertNull(Cache::get('press:entitlement:press:'.sha1(self::ACME))['answer']);
    }

    public static function failures(): array
    {
        $body = self::holds(self::ACME, false, 'suspended');

        return [
            // A request that never got an answer is not in the fake's record; the single log line shows it was tried once.
            'connection error or timeout' => [fn () => throw new ConnectionException('timed out'), 0],
            '401 wrong secret' => [fn () => Http::response(['error' => 'invalid_request'], 401)],
            '403 platform switched off' => [fn () => Http::response(['error' => 'invalid_request'], 403)],
            '404 unknown company' => [fn () => Http::response(['error' => 'not_found'], 404)],
            '500' => [fn () => Http::response('Server Error', 500)],
            '503' => [fn () => Http::response('', 503)],
            'not json' => [fn () => Http::response('<html>maintenance</html>', 200)],
            'no platforms list' => [fn () => Http::response(['platform' => 'press', 'company' => ['uuid' => self::ACME]])],
            'own row missing' => [fn () => Http::response(['platforms' => array_values(array_filter($body['platforms'], fn ($p) => $p['key'] !== 'press'))] + $body)],
            'entitled is not a boolean' => [fn () => Http::response(['platforms' => [['key' => 'press', 'status' => 'suspended', 'entitled' => 'no']]] + $body)],
            'secret not set' => [fn () => Http::response($body), 0],
        ];
    }

    public function test_the_answer_is_cached_per_company(): void
    {
        $this->infodotSays(self::ACME, true);

        $this->browser($this->editor)->get('/dashboard')->assertOk();
        $this->browser($this->editor)->getJson('/api/decks')->assertOk();
        $this->browser($this->viewer)->get('/dashboard')->assertOk();

        Http::assertSentCount(1);

        // Ten minutes for an answer: gone after that, and asked again.
        $this->travel(11)->minutes();
        $this->browser($this->editor)->get('/dashboard')->assertOk();
        Http::assertSentCount(2);
    }

    public function test_a_failure_is_remembered_for_a_minute_only(): void
    {
        Http::fake([self::platformsUrl(self::ACME) => Http::sequence()->pushStatus(500)->push(self::holds(self::ACME, false, 'suspended'))]);

        $this->browser($this->editor)->get('/dashboard')->assertOk();
        $this->browser($this->editor)->get('/dashboard')->assertOk();
        Http::assertSentCount(1);

        $this->travel(61)->seconds();
        $this->browser($this->editor)->get('/dashboard')->assertStatus(403);
    }

    public function test_the_flag_off_asks_nothing(): void
    {
        config(['ecosystem.infodot.entitlement_check' => false]);
        $this->infodotSays(self::ACME, false, 'suspended');

        $this->browser($this->editor)->get('/dashboard')->assertOk();
        $this->browser($this->editor)->getJson('/api/decks')->assertOk();
        $this->browser($this->editor)->get(route('entitlement.show'))->assertRedirect(route('dashboard'));

        Http::assertNothingSent();
    }

    public function test_exempt_routes_ask_nothing_and_stay_open(): void
    {
        $this->infodotSays(self::ACME, false, 'suspended');

        $token = Str::random(40);
        $this->deck->forceFill(['visibility' => 'link', 'share_token' => $token])->save();

        $this->get('/login')->assertOk();

        // Public pages, a shared presentation link, health and sign-in, for a guest and for a signed-in person alike.
        foreach ([fn () => $this, fn () => $this->browser($this->editor)] as $as) {
            $as()->get('/')->assertOk();
            $as()->get('/s/'.$token)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Press/Shared')->where('doc.deck.title', 'Quarterly deck'));
            $as()->get('/s/'.$token.'/document')->assertOk();
            $as()->get('/up')->assertOk();
            $as()->get('/cookies')->assertOk();
        }
        // A machine call with a bearer token is never asked about a company.
        $plain = $this->editor->createToken('machine')->plainTextToken;
        auth()->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$plain])->getJson('/api/user')->assertOk();

        // Signing out works for a refused person.
        $this->browser($this->editor)->post('/logout')->assertRedirect();

        Http::assertNothingSent();
    }

    private function personal(?bool $entitled): array
    {
        return ['id' => 5, 'uuid' => (string) Str::uuid(), 'name' => 'Pat Team', 'personal' => true, 'role' => 'owner', 'role_label' => 'Owner']
            + ($entitled === null ? [] : ['entitled' => $entitled, 'access' => $entitled ? null : 'no_personal_access']);
    }

    public function test_a_personal_workspace_infodot_refused_at_sign_in_is_closed_and_the_way_out_is_a_company(): void
    {
        // Pat belongs to Globex (holds Dot.Press) and has a personal workspace (does not).
        $this->signIn('pat@globex.test', [$this->personal(false), $this->company(self::GLOBEX, 'Globex', 'owner', ['entitled' => true, 'access' => null])], self::GLOBEX)
            ->assertRedirect(route('dashboard'));

        $pat = User::where('email', 'pat@globex.test')->firstOrFail();
        $globex = Team::where('infodot_team_uuid', self::GLOBEX)->firstOrFail();
        $own = $pat->personalTeam();
        $this->assertSame(false, session(EnsureCompanyEntitled::PERSONAL));
        $this->get('/dashboard')->assertOk();

        // Switching to the local personal team is not a way round the check.
        $this->put(route('current-team.update'), ['team_id' => $own->id])->assertRedirect();
        Http::swap(new Factory);
        Http::fake();

        $this->get('/dashboard')
            ->assertStatus(403)
            ->assertSee('Dot.Press needs a company that has switched it on.')
            ->assertSee('href="https://infodot.test/platforms/press"', false)
            ->assertSee('name="team_id" value="'.$globex->id.'"', false)
            ->assertDontSee('data-page', false);
        $this->withHeaders(['Referer' => 'http://localhost/dashboard'])->getJson('/api/decks')->assertStatus(403)->assertJsonPath('status', 'company_required');
        $this->get(route('entitlement.show'))->assertStatus(403);

        // A team with no InfoDot company is never asked about, and nothing in the workspace was touched.
        Http::assertNothingSent();
        $this->assertNotNull($own->fresh());

        // Back in the company that holds it, the person works again.
        $this->put(route('current-team.update'), ['team_id' => $globex->id])->assertRedirect();
        $this->infodotSays(self::GLOBEX, true);
        $this->get('/dashboard')->assertOk();
    }

    public function test_a_personal_workspace_infodot_allowed_at_sign_in_carries_on(): void
    {
        $this->signIn('pat@globex.test', [$this->personal(true)])->assertRedirect(route('dashboard'));
        $this->assertSame(true, session(EnsureCompanyEntitled::PERSONAL));

        Http::swap(new Factory);
        Http::fake();
        $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Press/Library'));
        $this->withHeaders(['Referer' => 'http://localhost/dashboard'])->getJson('/api/decks')->assertOk();
        Http::assertNothingSent();
    }

    public function test_a_personal_workspace_infodot_said_nothing_about_carries_on_and_forgets_an_older_answer(): void
    {
        $this->signIn('pat@globex.test', [$this->personal(false), $this->company(self::GLOBEX, 'Globex', 'owner', ['entitled' => true, 'access' => null])], self::GLOBEX);
        $this->assertSame(false, session(EnsureCompanyEntitled::PERSONAL));

        // The next sign-in comes from an InfoDot that does not send the key: nothing is remembered.
        $this->signIn('pat@globex.test', [$this->personal(null)])->assertRedirect(route('dashboard'));
        $this->assertFalse(session()->has(EnsureCompanyEntitled::PERSONAL));

        $pat = User::where('email', 'pat@globex.test')->firstOrFail();
        $pat->switchTeam($pat->personalTeam());
        auth()->forgetGuards();

        Http::swap(new Factory);
        Http::fake();
        $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Press/Library'));
        Http::assertNothingSent();
    }

    public function test_a_personal_or_local_team_is_never_asked_about(): void
    {
        Http::fake();
        $solo = User::factory()->withPersonalTeam()->create();

        $this->browser($solo)->get('/dashboard')->assertOk();
        $this->browser($this->outsider)->getJson('/api/decks')->assertOk();

        Http::assertNothingSent();
    }

    public function test_a_refused_person_can_switch_to_another_company_that_holds_the_platform(): void
    {
        $globex = Team::forceCreate(['user_id' => $this->outsider->id, 'name' => 'Globex', 'personal_team' => false, 'infodot_team_uuid' => self::GLOBEX]);
        $globex->users()->attach($this->editor, ['role' => 'editor']);

        Http::fake([
            self::platformsUrl(self::ACME) => Http::response(self::holds(self::ACME, false, 'suspended')),
            self::platformsUrl(self::GLOBEX) => Http::response(self::holds(self::GLOBEX, true, 'active')),
        ]);

        // The page offers the other company, as a form that posts the switch.
        $this->browser($this->editor)->get('/dashboard')
            ->assertStatus(403)
            ->assertSee('Or work in another company:')
            ->assertSee('Globex')
            ->assertSee('name="team_id" value="'.$globex->id.'"', false);

        $this->browser($this->editor)->put(route('current-team.update'), ['team_id' => $globex->id])->assertRedirect();
        $this->assertSame($globex->id, $this->editor->fresh()->current_team_id);

        $this->browser($this->editor->fresh())->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Press/Library'));

        // And Acme's data was left exactly as it was.
        $this->assertSame('Quarterly deck', $this->deck->fresh()->title);
    }
}
