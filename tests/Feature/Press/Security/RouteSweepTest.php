<?php

namespace Tests\Feature\Press\Security;

use App\Models\Asset;
use App\Models\BrandKit;
use App\Models\Deck;
use App\Models\DeckComment;
use App\Models\DeckExport;
use App\Models\DeckReview;
use App\Models\DeckVersion;
use App\Models\Layout;
use App\Models\PresentSession;
use App\Models\Project;
use App\Models\Slide;
use App\Models\Theme;
use App\Services\Press\VersionService;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Feature\Press\AccessTestCase;

/**
 * Every route that names something of a team (a deck, a slide, an asset, a comment, an export, a version, a theme...) is
 * called with a real id of the victim team by a signed-in person from another team and by a guest. The answer must be
 * a refusal. A route added later is swept too, because the list comes from the router: a new route with an id that has
 * no fixture here fails the test until someone decides what it should do.
 */
class RouteSweepTest extends AccessTestCase
{
    /** @return array<string, mixed> */
    private function victim(): array
    {
        $private = $this->makeDeck('private', 'Private plans', $this->owner);
        $comment = DeckComment::create(['deck_id' => $this->deck->id, 'user_id' => $this->owner->id, 'body' => 'internal remark', 'mentions' => []]);
        $review = DeckReview::create(['deck_id' => $this->deck->id, 'requested_by' => $this->owner->id, 'reviewer_id' => $this->admin->id, 'status' => 'pending', 'round' => 1]);
        $version = VersionService::snapshot($this->deck, $this->owner, 'manual', 'v1');
        $export = DeckExport::create(['deck_id' => $this->deck->id, 'user_id' => $this->owner->id, 'format' => 'pdf', 'status' => 'done', 'path' => 'press/exports/x.pdf', 'disk' => 'local', 'options' => [], 'expires_at' => now()->addDay()]);
        $theme = Theme::create(['team_id' => $this->team->id, 'user_id' => $this->owner->id, 'name' => 'T', 'tokens' => ['c' => ['accent' => '#112233']]]);
        $kit = BrandKit::create(['team_id' => $this->team->id, 'name' => 'K', 'tokens' => [], 'is_default' => false]);
        $layout = Layout::create(['team_id' => $this->team->id, 'user_id' => $this->owner->id, 'name' => 'L', 'kind' => 'custom', 'definition' => ['elements' => []]]);
        $session = PresentSession::create(['deck_id' => $this->deck->id, 'user_id' => $this->owner->id, 'token' => Str::random(40), 'state' => ['rev' => 1, 'cover' => null, 'remote_key' => 'k'], 'command' => ['queue' => []], 'last_seen' => now()]);

        return [
            'deck' => $this->deck->id, 'slide' => $this->slide->id, 'asset' => $this->asset('secret.png')->id, 'project' => $this->project->id,
            'comment' => $comment->id, 'review' => $review->id, 'version' => $version->id, 'export' => $export->id, 'theme' => $theme->id,
            'brandKit' => $kit->id, 'layout' => $layout->id, 'user' => $this->editor->id, 'token' => $session->token, 'private' => $private->id,
        ];
    }

    /** @return list<LaravelRoute> */
    private function sweepable(): array
    {
        $skip = ['s/', 'live/', 'remote/', 'present-asset/', 'api/templates/', 'operator/', 'editor/decks/', 'present/decks/'];
        $out = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_contains($uri, '{') || ! (str_starts_with($uri, 'api/') || str_starts_with($uri, 'decks/'))) {
                continue;
            }
            foreach ($skip as $prefix) {
                if (str_starts_with($uri, $prefix)) {
                    continue 2;
                }
            }
            $out[] = $route;
        }

        return $out;
    }

    private function fill(string $uri, array $ids, ?string $deck = null): string
    {
        return '/'.preg_replace_callback('/\{(\w+)\??\}/', function (array $m) use ($ids, $deck) {
            if ($m[1] === 'deck' && $deck !== null) {
                return $deck;
            }
            $this->assertArrayHasKey($m[1], $ids, "a route parameter {$m[1]} has no fixture in the sweep: decide what protects it, then add it");

            return (string) $ids[$m[1]];
        }, $uri);
    }

    private function body(string $method): array
    {
        return in_array($method, ['POST', 'PUT', 'PATCH'], true) ? ['title' => 'x', 'role' => 'viewer', 'email' => 'a@b.test', 'body' => 'x', 'client' => 'c', 'key' => 'k', 'ops' => [['op' => 'deck.set', 'title' => 'Hacked']], 'format' => 'pdf', 'name' => 'x'] : [];
    }

    public function test_a_person_from_another_team_is_refused_on_every_route_that_names_something(): void
    {
        $ids = $this->victim();
        $before = [
            'title' => $this->deck->fresh()->title, 'slides' => Slide::withoutGlobalScopes()->where('deck_id', $this->deck->id)->count(),
            'shares' => DB::table('deck_shares')->count(), 'comments' => DeckComment::withoutGlobalScopes()->count(), 'versions' => DeckVersion::withoutGlobalScopes()->count(),
        ];
        $swept = 0;

        foreach ($this->sweepable() as $route) {
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                foreach ([$ids['deck'], $ids['private']] as $deckId) {
                    $url = $this->fill($route->uri(), $ids, (string) $deckId);
                    $this->actingAs($this->outsider);
                    $response = $this->json($method, $url, $this->body($method));
                    $this->assertContains($response->status(), [401, 403, 404], "{$method} {$url} answered {$response->status()} to a person from another team");
                    $this->assertStringNotContainsString('Private plans', $response->getContent(), "{$method} {$url} leaked the private deck");
                    $this->assertStringNotContainsString('internal remark', $response->getContent(), "{$method} {$url} leaked a comment");
                    $swept++;
                }
            }
        }

        $this->assertGreaterThan(60, $swept, 'the sweep must cover the API');
        $this->assertSame($before['title'], Deck::withoutGlobalScopes()->find($this->deck->id)->title);
        $this->assertSame($before['slides'], Slide::withoutGlobalScopes()->where('deck_id', $this->deck->id)->count());
        $this->assertSame($before['shares'], DB::table('deck_shares')->count());
        $this->assertSame($before['comments'], DeckComment::withoutGlobalScopes()->count());
        $this->assertSame($before['versions'], DeckVersion::withoutGlobalScopes()->count());
    }

    public function test_a_guest_is_refused_on_every_route_that_names_something(): void
    {
        $ids = $this->victim();

        foreach ($this->sweepable() as $route) {
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                $url = $this->fill($route->uri(), $ids);
                $response = $this->json($method, $url, $this->body($method));
                $this->assertSame(401, $response->status(), "{$method} {$url} answered {$response->status()} to a guest");
            }
        }
    }

    public function test_the_private_deck_is_invisible_even_to_a_team_admin_on_every_deck_route(): void
    {
        $ids = $this->victim();
        $swept = 0;

        foreach ($this->sweepable() as $route) {
            if (! str_contains($route->uri(), '{deck}')) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                $url = $this->fill($route->uri(), $ids, (string) $ids['private']);
                $this->actingAs($this->admin);
                $response = $this->json($method, $url, $this->body($method));
                // sub-resources of the deck (comment, review...) carry the victim team's other ids: any refusal will do
                $this->assertContains($response->status(), [401, 403, 404], "{$method} {$url} answered {$response->status()} to a team admin for a private deck");
                $swept++;
            }
        }
        $this->assertGreaterThan(40, $swept);
    }

    public function test_lists_of_another_team_never_contain_the_victims_things(): void
    {
        $ids = $this->victim();
        $this->deck->forceFill(['title' => 'VICTIM-QUARTERLY-DECK'])->save();
        Project::withoutGlobalScopes()->whereKey($this->project->id)->update(['name' => 'VICTIM-PROJECT']);
        Asset::withoutGlobalScopes()->whereKey($ids['asset'])->update(['original_name' => 'VICTIM-SECRET.png']);
        $this->actingAs($this->outsider);

        $urls = [
            '/api/decks', '/api/decks?filter=shared', '/api/decks?filter=team', '/api/decks?filter=recent', '/api/decks?filter=archived', '/api/decks?filter=templates',
            '/api/decks?q=VICTIM', '/api/decks?project_id='.$ids['project'], '/api/projects', '/api/assets', '/api/assets?q=VICTIM', '/api/assets?project_id='.$ids['project'],
            '/api/themes', '/api/themes?team_id='.$this->team->id, '/api/brand-kits', '/api/layouts', '/api/user', '/api/ai/usage',
            '/api/decks/'.$ids['deck'].'/changes?after=0', '/dashboard',
        ];
        foreach ($urls as $url) {
            $response = $this->getJson($url);
            $this->assertNotSame(500, $response->status(), $url);
            foreach (['VICTIM-QUARTERLY-DECK', 'VICTIM-PROJECT', 'VICTIM-SECRET', 'internal remark', 'Olive Owner'] as $needle) {
                $this->assertStringNotContainsString($needle, $response->getContent(), "{$url} leaked {$needle} to another team");
            }
        }
    }
}
