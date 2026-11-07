<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Baseline browser protections are on every response, the policy is enforced with a nonce, and violation reports are accepted safely. */
class SecurityHeadersTest extends TestCase
{
    public function test_every_response_carries_the_enforced_baseline_headers(): void
    {
        $r = $this->get('/');

        $r->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $permissions = (string) $r->headers->get('Permissions-Policy');
        foreach (['camera=()', 'microphone=()', 'geolocation=()', 'payment=()', 'usb=()'] as $denied) {
            $this->assertStringContainsString($denied, $permissions);
        }
        $this->get('/up')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/does-not-exist')->assertNotFound()->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_hsts_is_sent_on_https_only_and_can_be_switched_off(): void
    {
        $this->assertFalse($this->get('/')->headers->has('Strict-Transport-Security'), 'plain http never gets HSTS');

        $hsts = (string) $this->get('https://localhost/')->headers->get('Strict-Transport-Security');
        $this->assertSame('max-age=15552000', $hsts, 'no includeSubDomains and no preload: sibling subdomains are not all HTTPS-only');

        config(['security.hsts_seconds' => 0]);
        $this->assertFalse($this->get('https://localhost/')->headers->has('Strict-Transport-Security'));
    }

    public function test_a_header_set_by_the_route_is_not_overwritten(): void
    {
        Route::get('/__strict', fn () => response('x')->header('X-Frame-Options', 'DENY'));

        $this->get('/__strict')->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_the_content_security_policy_is_enforced_and_scripts_need_the_nonce(): void
    {
        $r = $this->get('/');

        $this->assertFalse($r->headers->has('Content-Security-Policy-Report-Only'), 'enforced, not just reported');
        $policy = (string) $r->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString('report-uri /csp-report', $policy);
        $this->assertStringContainsString("frame-ancestors 'self'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("base-uri 'self'", $policy);
        $this->assertStringContainsString("font-src 'self'", $policy);
        $this->assertStringContainsString("form-action 'self'", $policy);
        $this->assertStringContainsString("connect-src 'self'; ", $policy, 'no websocket and no other origin to talk to');
        $this->assertStringContainsString("img-src 'self' data: blob: https:", $policy);
        $this->assertStringContainsString('https://www.youtube.com https://player.vimeo.com', $policy);
        foreach (['bunny.net', 'googleapis.com', 'gstatic.com', 'unsplash.com', 'ui-avatars.com', 'unsafe-eval', 'wss:'] as $gone) {
            $this->assertStringNotContainsString($gone, $policy, "{$gone} is not loaded by any page any more");
        }

        // Scripts: self and one nonce, never 'unsafe-inline'. The style rule is the only place unsafe-inline remains.
        $this->assertSame(1, preg_match("/script-src 'self' 'nonce-([A-Za-z0-9]{40})'(;|$)/", $policy, $m), $policy);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $policy);

        // Every inline script the page prints carries that nonce, and the Vite tags too.
        $html = $r->getContent();
        preg_match_all('/<script\b([^>]*)>/i', $html, $tags);
        $executable = array_filter($tags[1], fn ($attrs) => ! str_contains($attrs, 'application/ld+json') && ! str_contains($attrs, 'application/json'));
        $this->assertNotEmpty($executable);
        foreach ($executable as $attrs) {
            $this->assertStringContainsString('nonce="'.$m[1].'"', $attrs, "a script tag without the nonce would be blocked: <script{$attrs}>");
        }
    }

    public function test_the_nonce_changes_on_every_request(): void
    {
        $one = (string) $this->get('/')->headers->get('Content-Security-Policy');
        $two = (string) $this->get('/')->headers->get('Content-Security-Policy');

        preg_match('/nonce-([A-Za-z0-9]+)/', $one, $a);
        preg_match('/nonce-([A-Za-z0-9]+)/', $two, $b);
        $this->assertNotSame($a[1], $b[1]);
    }

    public function test_the_kill_switch_turns_the_same_policy_into_report_only(): void
    {
        config(['security.csp_report_only' => true]);
        $r = $this->get('/');

        $this->assertFalse($r->headers->has('Content-Security-Policy'));
        $this->assertStringContainsString("script-src 'self' 'nonce-", (string) $r->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_error_pages_carry_the_policy_and_their_inline_script_the_nonce(): void
    {
        $r = $this->get('/does-not-exist')->assertNotFound();

        $policy = (string) $r->headers->get('Content-Security-Policy');
        preg_match('/nonce-([A-Za-z0-9]+)/', $policy, $m);
        $this->assertNotEmpty($m);
        $this->assertStringContainsString('<script nonce="'.$m[1].'">', $r->getContent());
    }

    public function test_upgrade_insecure_requests_is_only_asked_for_on_https(): void
    {
        $this->assertStringNotContainsString('upgrade-insecure-requests', (string) $this->get('/')->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('upgrade-insecure-requests', (string) $this->get('https://localhost/')->headers->get('Content-Security-Policy'));
    }

    public function test_violation_reports_are_accepted_without_a_session_and_keep_only_the_directive_and_host(): void
    {
        Log::spy();
        $body = json_encode(['csp-report' => ['effective-directive' => 'script-src-elem', 'blocked-uri' => 'https://evil.example/steal?token=SECRET123', 'document-uri' => 'https://press.example/x?code=SECRET456']]);

        $first = $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], $body);
        $first->assertNoContent();
        $this->assertGuest();
        $this->assertCount(0, $first->headers->getCookies(), 'no session cookie is started for a report');
        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], $body)->assertNoContent();   // the same pair is logged once a day

        Log::shouldHaveReceived('info')->withArgs(fn ($m, $ctx = []) => $m === 'csp.violation' && $ctx === ['directive' => 'script-src-elem', 'blocked_host' => 'evil.example'])->once();
        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/reports+json'], 'not json at all')->assertNoContent();
        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/reports+json'], json_encode([['type' => 'csp-violation', 'body' => ['effectiveDirective' => 'img-src', 'blockedURL' => 'inline']]]))->assertNoContent();
    }

    public function test_the_report_post_needs_no_csrf_token(): void
    {
        // CSRF verification is skipped when the app runs unit tests, which would hide a 419 that only production
        // shows (a browser posts the report itself and has no token): run the request in production mode.
        $this->app['env'] = 'production';

        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], json_encode(['csp-report' => ['effective-directive' => 'img-src', 'blocked-uri' => 'inline']]))
            ->assertNoContent();
    }
}
