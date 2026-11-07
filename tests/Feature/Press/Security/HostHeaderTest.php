<?php

namespace Tests\Feature\Press\Security;

use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Links in mail are built from the request unless the app is told where it lives. A request that arrives with a Host
 * header of the attacker's choosing must not put that host into a password reset mail.
 */
class HostHeaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_password_reset_mail_points_at_the_configured_address_whatever_the_host_header_says(): void
    {
        config(['app.url' => 'https://pres.infodot.co.za', 'security.force_root_url' => true]);
        (new AppServiceProvider($this->app))->boot();
        Notification::fake();
        $user = User::factory()->create(['email' => 'victim@example.test']);

        $this->call('POST', 'http://evil.example/forgot-password', ['email' => 'victim@example.test'], [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_FORWARDED_HOST' => 'evil.example']);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user) {
            $url = (string) $n->toMail($user)->actionUrl;
            $this->assertStringStartsWith('https://pres.infodot.co.za/', $url);
            $this->assertStringNotContainsString('evil.example', $url);

            return true;
        });
    }

    public function test_the_provider_pins_the_root_url_when_the_setting_is_on(): void
    {
        config(['app.url' => 'https://pres.infodot.co.za', 'security.force_root_url' => true]);
        (new AppServiceProvider($this->app))->boot();

        $this->assertSame('https://pres.infodot.co.za/dashboard', url('/dashboard'));
    }
}
