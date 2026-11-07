<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EcosystemErrorPagesTest extends TestCase
{
    #[DataProvider('errorCodes')]
    public function test_error_page_renders_branded_content(int $code, string $expectedHeading): void
    {
        $response = $this->get("/_test-only-error-render/{$code}");

        $response->assertStatus($code);
        $response->assertSee($expectedHeading);
        $response->assertSee('Dot.Press', false);
        $response->assertSee('part of the Dot ecosystem', false);
        // Standalone: the page carries its own styles and loads nothing from the build or from another origin.
        $response->assertSee('--paper:', false);
        $response->assertDontSee('fonts.googleapis.com', false);
        $response->assertDontSee('/build/assets/', false);
    }

    public static function errorCodes(): array
    {
        return [
            '404 not found' => [404, 'Nothing on this line yet.'],
            '403 forbidden' => [403, 'This is not open to you.'],
            '419 page expired' => [419, 'This page timed out.'],
            '429 too many requests' => [429, 'Slow down a little.'],
            '500 server error' => [500, 'Something broke on our side.'],
            '503 unavailable' => [503, 'Back in a moment.'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['router']->get('/_test-only-error-render/{code}', function (int $code) {
            return response()->view("errors.{$code}", [], $code);
        });
    }
}
