<?php

namespace Tests\Feature\Press\Intel;

use App\Services\Ai\ContentGenerator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntelCompleteJsonTest extends TestCase
{
    private array $schema = ['type' => 'object', 'required' => ['answer'], 'properties' => ['answer' => ['type' => 'string', 'maxLength' => 20]]];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ai.provider', 'anthropic');
        config()->set('ai.anthropic.api_key', 'sk-test');
    }

    private function reply(string $text, int $status = 200): mixed
    {
        return Http::response(['content' => [['type' => 'text', 'text' => $text]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 4]], $status);
    }

    private function ask(): array
    {
        return app(ContentGenerator::class)->completeJson('You answer.', 'Question', $this->schema);
    }

    public function test_a_valid_reply_is_returned_parsed_with_usage(): void
    {
        Http::fake(['api.anthropic.com/*' => $this->reply('{"answer":"yes"}')]);
        $r = $this->ask();
        $this->assertTrue($r['ok']);
        $this->assertSame(['answer' => 'yes'], $r['data']);
        $this->assertSame(1, $r['attempts']);
        $this->assertSame(['input_tokens' => 10, 'output_tokens' => 4], $r['usage']);
        Http::assertSent(function ($req) {
            $body = $req->data();

            return $req->hasHeader('x-api-key', 'sk-test') && str_contains($body['system'], 'JSON schema') && $body['max_tokens'] === 4000;
        });
    }

    public function test_json_wrapped_in_prose_or_fences_is_extracted(): void
    {
        Http::fake(['api.anthropic.com/*' => $this->reply("Here you go:\n```json\n{\"answer\":\"ok\"}\n```")]);
        $this->assertSame(['answer' => 'ok'], $this->ask()['data']);
    }

    public function test_a_reply_that_breaks_the_schema_is_retried_once_with_the_problem_named(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()->push(['content' => [['type' => 'text', 'text' => '{"answer":"far too long to be allowed here"}']], 'usage' => ['input_tokens' => 10, 'output_tokens' => 4]])->push(['content' => [['type' => 'text', 'text' => '{"answer":"short"}']], 'usage' => ['input_tokens' => 11, 'output_tokens' => 3]])]);
        $r = $this->ask();
        $this->assertTrue($r['ok']);
        $this->assertSame(2, $r['attempts']);
        $this->assertSame(21, $r['usage']['input_tokens']);
        Http::assertSent(fn ($req) => str_contains((string) json_encode($req->data()['messages']), 'did not fit the schema'));
    }

    public function test_two_bad_replies_fail_without_throwing_or_returning_data(): void
    {
        Http::fake(['api.anthropic.com/*' => $this->reply('not json at all')]);
        $r = $this->ask();
        $this->assertFalse($r['ok']);
        $this->assertNull($r['data']);
        $this->assertSame(2, $r['attempts']);
        $this->assertNotNull($r['error']);
        Http::assertSentCount(2);
    }

    public function test_a_server_error_is_retried_once(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response('x', 529)]);
        $this->assertFalse($this->ask()['ok']);
        Http::assertSentCount(2);
    }

    public function test_a_client_error_is_not_retried(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'bad key'], 401)]);
        $r = $this->ask();
        $this->assertFalse($r['ok']);
        $this->assertSame(1, $r['attempts']);
        $this->assertStringContainsString('401', $r['error']);
    }

    public function test_a_timeout_is_retried_once(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()->pushFailedConnection('timed out')->push(['content' => [['type' => 'text', 'text' => '{"answer":"late"}']], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]])]);
        $r = $this->ask();
        $this->assertTrue($r['ok']);
        $this->assertSame(2, $r['attempts']);

    }

    public function test_internal_error_text_is_not_passed_on(): void
    {
        Http::fake(['api.anthropic.com/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);
        $r = $this->ask();
        $this->assertFalse($r['ok']);
        $this->assertStringNotContainsString('cURL', $r['error']);
    }

    public function test_the_reply_must_be_an_object(): void
    {
        Http::fake(['api.anthropic.com/*' => $this->reply('[1,2,3]')]);
        $this->assertFalse($this->ask()['ok']);
    }

    public function test_it_refuses_to_run_without_a_configured_model(): void
    {
        config()->set('ai.provider', 'mock');
        Http::fake();
        $r = $this->ask();
        $this->assertFalse($r['ok']);
        $this->assertSame(0, $r['attempts']);
        Http::assertNothingSent();
    }
}
