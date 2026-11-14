<?php

namespace Tests\Feature\Press;

use App\Services\InfoDot\DotSwitcherProps;
use Dot\Ui\PlatformLogos;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * R-51 pilot: the header's "Dot Apps" switcher in Dot.Press. The model is built on the server by the shared dot-ui
 * package from InfoDot's answer for the person's CURRENT company, handed to the page as a deferred Inertia prop, and
 * never blocks a page on InfoDot. Every tile is a link to InfoDot's /go with the company; nothing here grants access.
 */
class DotSwitcherPropsTest extends AccessTestCase
{
    private const ACME = '11111111-1111-4111-8111-111111111111';

    private const GLOBEX = '22222222-2222-4222-8222-222222222222';

    private const SECRET = 'press-service-secret-for-tests';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'inertia.testing.ensure_pages_exist' => false,
            'ecosystem.infodot' => [
                'redeem_url' => 'https://infodot.test/api/ecosystem/redeem', 'platform' => 'press', 'secret' => self::SECRET, 'timeout' => 2,
                'provision' => true, 'entitlement_check' => false, 'platforms_url' => null,
            ],
            'ecosystem.platforms.infodot.url' => 'https://infodot.test',
        ]);

        $this->team->forceFill(['personal_team' => false, 'infodot_team_uuid' => self::ACME, 'name' => 'Acme Ltd'])->save();
    }

    private function body(string $uuid = self::ACME): array
    {
        return [
            'platform' => 'press',
            'company' => ['uuid' => $uuid, 'personal' => false],
            'platforms' => [
                ['key' => 'infodot', 'name' => 'InfoDot', 'status' => 'included', 'entitled' => true, 'is_core' => true],
                ['key' => 'files', 'name' => 'Dot.Files', 'status' => 'included', 'entitled' => true, 'is_core' => true],
                ['key' => 'press', 'name' => 'Dot.Press', 'status' => 'active', 'entitled' => true, 'is_core' => false],
                ['key' => 'sheet', 'name' => 'Dot.Sheet', 'status' => 'active', 'entitled' => true, 'is_core' => false],
                ['key' => 'hr', 'name' => 'Dot.HR', 'status' => 'payment_required', 'entitled' => false, 'is_core' => false],
                ['key' => 'mines', 'name' => 'Dot.Mines', 'status' => 'managed_in_platform', 'entitled' => true, 'is_core' => false],
            ],
        ];
    }

    private function fake(mixed $answer): void
    {
        Http::swap(new Factory);
        Http::fake(['https://infodot.test/api/ecosystem/companies/*' => $answer]);
    }

    /** The deferred prop, loaded the way the browser loads it: a partial reload of this one prop. */
    private function deferred($user, string $url = '/dashboard'): ?array
    {
        $this->flushHeaders();
        $version = $this->actingAs($user)->get($url)->viewData('page')['version'] ?? '';
        $this->flushHeaders();
        $response = $this->actingAs($user)->withHeaders([
            'X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Press/Library', 'X-Inertia-Partial-Data' => 'dotSwitcher',
            'X-Inertia-Version' => (string) $version,
        ])->get($url);

        return $response->json('props.dotSwitcher');
    }

    public function test_the_page_does_not_wait_for_infodot_the_prop_is_deferred(): void
    {
        $this->fake(Http::response($this->body()));

        $response = $this->actingAs($this->owner)->get('/dashboard')->assertOk();

        $response->assertInertia(fn (Assert $page) => $page->component('Press/Library')->missing('dotSwitcher'));
        $this->assertSame(['dotui' => ['dotSwitcher']], $response->viewData('page')['deferredProps']);
        Http::assertNothingSent();
    }

    public function test_the_model_lists_what_the_current_company_has_marks_press_and_links_through_go_with_the_company(): void
    {
        $this->fake(Http::response($this->body()));

        $model = $this->deferred($this->owner);

        $this->assertSame('ok', $model['state']);
        $this->assertSame('press', $model['current']);
        $this->assertSame(self::ACME, $model['company']);
        $this->assertSame('Acme Ltd', $model['company_name']);

        $tiles = collect($model['groups'])->flatMap(fn ($g) => $g['tiles'])->keyBy('key');
        $this->assertTrue($tiles['press']['current']);
        $this->assertNull($tiles['press']['href'], 'the current platform is a marker, not a link');
        $this->assertSame('https://infodot.test/go/files?company='.self::ACME, $tiles['files']['href']);
        $this->assertSame('https://infodot.test/go/sheet?company='.self::ACME, $tiles['sheet']['href']);
        $this->assertTrue($tiles['mines']['plain']);
        $this->assertSame('https://infodot.test/go/mines', $tiles['mines']['href'], 'a platform with its own sign-in gets no company');
        $this->assertArrayNotHasKey('hr', $tiles->all(), 'a platform the company has not switched on is not a tile');
        $this->assertSame(['hr'], array_column($model['more'], 'key'));
        $this->assertSame('https://infodot.test/platforms/hr', $model['more'][0]['href']);
    }

    public function test_logos_are_the_packaged_originals_with_a_printed_size(): void
    {
        $this->fake(Http::response($this->body()));

        $tiles = collect($this->deferred($this->owner)['groups'])->flatMap(fn ($g) => $g['tiles']);

        foreach ($tiles as $tile) {
            $this->assertSame($tile['key'].'.png', $tile['logo']);
            $this->assertSame(PlatformLogos::size($tile['key']), $tile['logo_size']);
            $this->assertFileExists(public_path('dot-ui/img/platforms/'.$tile['logo']));
            $this->assertFileEquals(base_path('dot-ui/../public/dot-ui/img/platforms/'.$tile['logo']), public_path('dot-ui/img/platforms/'.$tile['logo']));
        }
    }

    public function test_the_request_to_infodot_carries_the_service_secret_this_platform_and_the_current_company_only(): void
    {
        $this->fake(Http::response($this->body()));

        $this->deferred($this->owner);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/companies/'.self::ACME.'/platforms')
            && $request->hasHeader('Authorization', 'Bearer '.self::SECRET)
            && $request->hasHeader('X-Dot-Platform', 'press')
            && ($request['platform'] ?? null) === 'press');
    }

    public function test_the_model_follows_the_current_company_when_a_person_switches_team(): void
    {
        $globex = $this->owner->ownedTeams()->create(['name' => 'Globex', 'personal_team' => false]);
        $globex->forceFill(['infodot_team_uuid' => self::GLOBEX])->save();
        $this->fake(fn ($request) => Http::response(str_contains($request->url(), self::GLOBEX) ? $this->body(self::GLOBEX) : $this->body()));

        $this->assertSame(self::ACME, $this->deferred($this->owner)['company']);

        $this->owner->switchTeam($globex);
        $model = $this->deferred($this->owner->fresh());

        $this->assertSame(self::GLOBEX, $model['company']);
        $this->assertSame('Globex', $model['company_name']);
    }

    public function test_a_failed_infodot_gives_the_degraded_model_never_active_and_never_an_error_page(): void
    {
        Log::spy();
        $this->fake(Http::response('boom', 500));

        $model = $this->deferred($this->owner);

        $this->assertSame('degraded', $model['state']);
        $this->assertSame('Could not check your platforms just now', $model['notice']);
        $keys = collect($model['groups'])->flatMap(fn ($g) => $g['tiles'])->pluck('key')->all();
        // the core set, plus Press itself (not core) marked as where the person is
        $this->assertEqualsCanonicalizing(['infodot', 'files', 'docs', 'tasks', 'finance', 'press'], $keys);
        $press = collect($model['groups'])->flatMap(fn ($g) => $g['tiles'])->firstWhere('key', 'press');
        $this->assertTrue($press['current']);
        $this->assertNull($press['href']);
        $this->assertStringNotContainsString('Active', json_encode($model));
        collect($model['groups'])->flatMap(fn ($g) => $g['tiles'])->reject(fn ($tile) => $tile['current'])->each(function ($tile) {
            // every core tile goes through InfoDot (which decides again for the person); InfoDot's own goes to its home
            $this->assertStringStartsWith('https://infodot.test/', $tile['href']);
            $this->assertTrue($tile['key'] === 'infodot' || $tile['via_infodot']);
        });

        // and the library itself still renders
        $this->actingAs($this->owner)->get('/dashboard')->assertOk();
    }

    public function test_an_unreachable_infodot_is_degraded_too(): void
    {
        $this->fake(fn () => Http::failedConnection());

        $this->assertSame('degraded', $this->deferred($this->owner)['state']);
    }

    public function test_the_answer_is_cached_so_the_second_page_does_not_ask_infodot_again(): void
    {
        $this->fake(Http::response($this->body()));

        $this->deferred($this->owner);
        $this->deferred($this->owner);

        Http::assertSentCount(1);
    }

    public function test_a_personal_workspace_gets_the_personal_state_and_infodot_is_not_asked(): void
    {
        $this->fake(Http::response($this->body()));
        $personal = $this->outsider; // a person whose team has no InfoDot company

        $model = $this->deferred($personal);

        $this->assertSame('personal', $model['state']);
        $this->assertNull($model['company']);
        $this->assertSame(0, $model['count']);
        $this->assertNotEmpty($model['message']);
        Http::assertNothingSent();
    }

    public function test_a_guest_gets_no_model(): void
    {
        $this->assertNull(app(DotSwitcherProps::class)->for(null));
    }

    public function test_the_model_carries_no_secret_and_no_token(): void
    {
        $this->fake(Http::response($this->body()));

        $json = json_encode($this->deferred($this->owner));

        $this->assertStringNotContainsString(self::SECRET, $json);
        $this->assertStringNotContainsString('token', strtolower($json));
    }

    public function test_the_shell_loads_the_shared_css_from_its_own_origin_and_the_csp_allows_it(): void
    {
        $this->fake(Http::response($this->body()));

        $response = $this->actingAs($this->owner)->get('/dashboard')->assertOk();
        $html = $response->getContent();

        foreach (['dot-fonts', 'dot-tokens', 'dot-search', 'dot-switcher-fonts', 'dot-switcher'] as $css) {
            $this->assertStringContainsString('href="/dot-ui/css/'.$css.'.css"', $html);
            $this->assertFileExists(public_path('dot-ui/css/'.$css.'.css'));
        }
        $policy = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("style-src 'self'", $policy);
        $this->assertStringContainsString("font-src 'self'", $policy);
        $this->assertStringContainsString("img-src 'self'", $policy);
        $this->assertStringNotContainsString('infodot.co.za', $policy.'', 'nothing is loaded from InfoDot in the browser');
    }
}
