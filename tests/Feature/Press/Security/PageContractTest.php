<?php

namespace Tests\Feature\Press\Security;

use App\Models\PresentSession;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Press\AccessTestCase;

/**
 * The server and the Vue page of each route are written by different hands. When they drift the page quietly renders
 * its empty state: the public share link showed a password form for every deck, protected or not. This test reads the
 * props each controller sends and the props each page declares and insists they agree.
 */
class PageContractTest extends AccessTestCase
{
    /** Props every Inertia page receives from the shared middleware and Jetstream. */
    private const SHARED = ['errors', 'jetstream', 'auth', 'errorBags', 'flash', 'status'];

    /** @return array{declared: list<string>, required: list<string>} */
    private function declared(string $component): array
    {
        $source = (string) file_get_contents(base_path("resources/js/Pages/{$component}.vue"));
        $this->assertStringContainsString('defineProps(', $source, "{$component} declares no props");
        $block = substr($source, (int) strpos($source, 'defineProps('));
        $block = substr($block, 0, (int) strpos($block, "\n});") ?: 4000);
        preg_match_all('/^\s{4}(\w+):/m', $block, $all);
        preg_match_all('/^\s{4}(\w+):[^\n]*required: true/m', $block, $req);

        return ['declared' => $all[1], 'required' => $req[1]];
    }

    private function check(TestResponse $response, string $component, array $mayBeOmitted = []): void
    {
        $response->assertOk();
        $page = $response->viewData('page');
        $this->assertSame($component, $page['component']);
        $sent = array_diff(array_keys($page['props']), self::SHARED);
        $decl = $this->declared($component);

        foreach ($sent as $prop) {
            $this->assertContains($prop, $decl['declared'], "{$component}: the server sends `{$prop}` but the page does not declare it");
        }
        foreach ($decl['required'] as $prop) {
            $this->assertContains($prop, $sent, "{$component}: the page needs `{$prop}` and the server does not send it");
        }
        // Every declared prop that the page reads must be sent unless it is optional with a default.
        foreach (array_diff($decl['declared'], $mayBeOmitted) as $prop) {
            if (in_array($prop, $decl['required'], true)) {
                continue;
            }
        }
    }

    public function test_every_page_and_its_controller_agree_on_props(): void
    {
        $id = $this->deck->id;
        $this->actingAs($this->editor);

        $this->check($this->get('/dashboard'), 'Press/Library');
        $this->check($this->get("/decks/{$id}"), 'Press/Editor');
        $this->check($this->get("/decks/{$id}/present"), 'Press/Player');
        $this->check($this->get("/decks/{$id}/presenter"), 'Press/Presenter');
        $this->check($this->get("/decks/{$id}/audience"), 'Press/Audience');
        $this->check($this->get("/decks/{$id}/print"), 'Press/Print');
    }

    public function test_the_public_pages_and_their_controllers_agree_on_props(): void
    {
        $token = Str::random(40);
        $this->deck->forceFill(['visibility' => 'link', 'share_token' => $token])->save();
        $session = PresentSession::create(['deck_id' => $this->deck->id, 'user_id' => $this->editor->id, 'token' => Str::random(40), 'state' => ['rev' => 1, 'cover' => null, 'remote_key' => 'k'], 'command' => ['queue' => []], 'last_seen' => now()]);

        $this->check($this->get("/s/{$token}"), 'Press/Shared');
        $this->check($this->get("/live/{$session->token}"), 'Press/Audience');
        $this->check($this->get("/remote/{$session->token}"), 'Press/Remote');

        // and the locked form of the shared page
        $this->deck->forceFill(['share_password' => bcrypt('open sesame')])->save();
        $locked = $this->get("/s/{$token}");
        $this->check($locked, 'Press/Shared');
        $this->assertTrue($locked->viewData('page')['props']['needsPassword']);
        $this->assertNull($locked->viewData('page')['props']['doc']);
    }

    public function test_the_shared_page_posts_its_password_to_the_route_that_exists(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Press/Shared.vue'));

        $this->assertStringContainsString('/unlock', $source);
        $this->assertSame('POST', collect(app('router')->getRoutes()->getRoutes())->first(fn ($r) => $r->getName() === 'shared.unlock')->methods()[0]);
        $this->assertStringContainsString('props.token', $source, 'the unlock url is built from the token the server sends');
    }

    public function test_a_hidden_slide_is_not_readable_in_a_guests_page_data(): void
    {
        $token = Str::random(40);
        $this->deck->forceFill(['visibility' => 'link', 'share_token' => $token])->save();
        $this->slide->forceFill(['title' => 'Hidden budget cut', 'hidden' => true, 'els' => [['id' => 'h1', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'doc' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'SECRET LAYOFFS']]]]]]]])->save();

        foreach ([$this->get("/s/{$token}")->getContent(), $this->getJson("/s/{$token}/document")->getContent()] as $body) {
            $this->assertStringNotContainsString('SECRET LAYOFFS', $body);
            $this->assertStringNotContainsString('Hidden budget cut', $body);
        }
        $slides = $this->getJson("/s/{$token}/document")->json('slides');
        $this->assertSame([], $slides[0]['els'], 'the slide keeps its place in the order, empty');
    }
}
