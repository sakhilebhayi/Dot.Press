<?php

namespace Tests\Feature\Press;

use App\Models\Asset;
use App\Models\DeckComment;
use App\Models\DeckReview;
use App\Models\Project;
use App\Models\Slide;
use App\Services\Press\Sharing;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

class AccessPublicLinkTest extends AccessTestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        config(['inertia.testing.ensure_pages_exist' => false]);
        $this->token = Str::random(40);
        $this->deck->forceFill(['visibility' => 'link', 'share_token' => $this->token])->save();
        $this->slide->forceFill(['notes' => 'secret speaker notes'])->save();
    }

    private function lock(string $password = 'open sesame'): void
    {
        $this->deck->forceFill(['share_password' => Hash::make($password)])->save();
    }

    public function test_the_page_renders_with_the_deck_for_a_guest(): void
    {
        $this->get("/s/{$this->token}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Press/Shared')
                ->where('title', 'Quarterly deck')
                ->where('needsPassword', false)
                ->where('error', null)
                ->where('allowDownload', false)
                ->where('token', $this->token)
                ->where('doc.deck.title', 'Quarterly deck')
                ->has('doc.slides', 1)
                ->where('doc.slides.0.title', 'Slide one'));
    }

    public function test_the_response_is_never_cached_or_indexed(): void
    {
        foreach (["/s/{$this->token}", "/s/{$this->token}/document"] as $url) {
            $response = $this->get($url)->assertOk();
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('noindex', $response->headers->get('X-Robots-Tag'));
            $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        }

        $this->get('/s/'.str_repeat('z', 40))->assertNotFound();
    }

    public function test_the_document_has_no_comments_people_notes_or_internal_state(): void
    {
        DeckComment::create(['deck_id' => $this->deck->id, 'user_id' => $this->owner->id, 'body' => 'internal remark']);
        DeckReview::create(['deck_id' => $this->deck->id, 'requested_by' => $this->owner->id, 'reviewer_id' => $this->admin->id]);
        $this->sharedWith('viewer');

        $body = $this->getJson("/s/{$this->token}/document")->assertOk()->getContent();

        foreach (['internal remark', 'secret speaker notes', 'comments', 'people', 'reviews', 'Olive Owner', '@example', 'share_token', 'share_password', 'approved_hash'] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "document leaked {$leak}");
        }

        $page = $this->get("/s/{$this->token}")->getContent();
        $this->assertStringNotContainsString('internal remark', $page);
        $this->assertStringNotContainsString('secret speaker notes', $page);

        $this->getJson("/s/{$this->token}/document")->assertJsonStructure(['deck' => ['id', 'title', 'w', 'h', 'slide_order'], 'slides' => [['id', 'els']], 'assets']);
    }

    public function test_wrong_off_rotated_and_expired_links_all_answer_404(): void
    {
        $this->get('/s/'.str_repeat('a', 40))->assertNotFound();
        $this->get('/s/short')->assertNotFound();

        $this->deck->forceFill(['share_expires_at' => now()->subMinute()])->save();
        foreach (['', '/document', '/asset/1'] as $suffix) {
            $this->get("/s/{$this->token}{$suffix}")->assertNotFound();
        }
        $this->post("/s/{$this->token}/unlock", ['password' => 'x'])->assertNotFound();

        $this->deck->forceFill(['share_expires_at' => now()->addHour()])->save();
        $this->get("/s/{$this->token}")->assertOk();

        $this->deck->forceFill(['visibility' => 'team'])->save();
        $this->get("/s/{$this->token}")->assertNotFound();

        $this->deck->forceFill(['visibility' => 'link', 'archived_at' => now()])->save();
        $this->get("/s/{$this->token}")->assertNotFound();
    }

    public function test_a_dead_link_looks_exactly_like_a_link_that_never_existed(): void
    {
        $dead = $this->get('/s/'.str_repeat('b', 40));
        $this->deck->forceFill(['share_expires_at' => now()->subMinute()])->save();
        $expired = $this->get("/s/{$this->token}");

        $this->assertSame($dead->getStatusCode(), $expired->getStatusCode());
        $this->assertSame(404, $expired->getStatusCode());
    }

    public function test_a_password_protected_link_gates_the_page_the_document_and_the_assets(): void
    {
        $this->lock();

        $this->get("/s/{$this->token}")->assertInertia(fn (Assert $page) => $page
            ->where('needsPassword', true)->where('doc', null)->where('title', 'Protected presentation'));
        $this->getJson("/s/{$this->token}/document")->assertForbidden();
        $this->get("/s/{$this->token}/asset/1")->assertForbidden();
    }

    public function test_the_right_password_unlocks_this_visitor_only(): void
    {
        $this->lock();

        $this->postJson("/s/{$this->token}/unlock", ['password' => 'open sesame'])->assertOk()->assertJson(['unlocked' => true]);
        $this->getJson("/s/{$this->token}/document")->assertOk();
        $this->get("/s/{$this->token}")->assertInertia(fn (Assert $page) => $page->where('needsPassword', false)->where('doc.deck.title', 'Quarterly deck'));

        // a different visitor (fresh session) is still locked
        $this->flushSession();
        $this->getJson("/s/{$this->token}/document")->assertForbidden();
    }

    public function test_a_wrong_password_is_refused_and_a_browser_post_redirects_with_the_error(): void
    {
        $this->lock();

        $this->postJson("/s/{$this->token}/unlock", ['password' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->post("/s/{$this->token}/unlock", ['password' => 'nope'])->assertRedirect("/s/{$this->token}");
        $this->get("/s/{$this->token}")->assertInertia(fn (Assert $page) => $page->where('error', 'That password is not correct.')->where('needsPassword', true));
        $this->get("/s/{$this->token}")->assertInertia(fn (Assert $page) => $page->where('error', null));
    }

    public function test_password_guessing_is_throttled_per_link_and_visitor(): void
    {
        $this->lock();
        RateLimiter::clear('press-share-unlock:'.sha1($this->token).'|127.0.0.1');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson("/s/{$this->token}/unlock", ['password' => "wrong {$i}"])->assertUnprocessable();
        }

        $locked = $this->postJson("/s/{$this->token}/unlock", ['password' => 'open sesame'])->assertStatus(429);
        $this->assertNotNull($locked->headers->get('Retry-After'));
        $this->getJson("/s/{$this->token}/document")->assertForbidden();

        // another link is not affected
        $other = $this->makeDeck('link', 'Other');
        $otherToken = Str::random(40);
        $other->forceFill(['share_token' => $otherToken, 'share_password' => Hash::make('pw-other')])->save();
        $this->postJson("/s/{$otherToken}/unlock", ['password' => 'pw-other'])->assertOk();
    }

    public function test_rotating_the_token_or_changing_the_password_drops_existing_unlocks(): void
    {
        $this->lock();
        $this->postJson("/s/{$this->token}/unlock", ['password' => 'open sesame'])->assertOk();
        $this->getJson("/s/{$this->token}/document")->assertOk();

        $this->deck->forceFill(['share_password' => Hash::make('new password')])->save();
        $this->getJson("/s/{$this->token}/document")->assertForbidden();

        $marker = Sharing::unlockMarker($this->deck->refresh());
        $this->deck->forceFill(['share_token' => Str::random(40)])->save();
        $this->assertNotSame($marker, Sharing::unlockMarker($this->deck->refresh()));
    }

    public function test_the_unlock_endpoint_validates_the_body(): void
    {
        $this->lock();
        $this->postJson("/s/{$this->token}/unlock", [])->assertUnprocessable();
    }

    public function test_download_flag_is_passed_to_the_page(): void
    {
        $this->deck->forceFill(['share_allow_download' => true])->save();

        $this->get("/s/{$this->token}")->assertInertia(fn (Assert $page) => $page->where('allowDownload', true));
    }

    public function test_a_signed_in_outsider_can_use_a_link_like_a_guest(): void
    {
        $this->actingAs($this->outsider)->get("/s/{$this->token}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('doc.slides', 1));
        $this->actingAs($this->outsider)->getJson("/s/{$this->token}/document")->assertOk()->assertJsonCount(1, 'slides');
        // and still has no access to the deck itself
        $this->getJson("/api/decks/{$this->deck->id}")->assertNotFound();
    }

    // ---- assets -----------------------------------------------------------------------------------------------

    private function storeAsset(string $contents = 'abcdefghij', string $name = 'pic.png'): Asset
    {
        Storage::fake('local');
        $asset = $this->asset($name);
        Storage::disk('local')->put($asset->path, $contents);

        return $asset;
    }

    private function useAsset(Asset $asset): void
    {
        $this->slide->forceFill(['els' => [['id' => 'im', 'type' => 'image', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'src' => ['asset' => $asset->id]]]])->save();
    }

    public function test_the_asset_map_points_at_the_token_routes(): void
    {
        $asset = $this->storeAsset();
        $this->useAsset($asset);

        $map = $this->getJson("/s/{$this->token}/document")->assertOk()->json("assets.{$asset->id}");

        $this->assertSame(route('shared.asset', ['token' => $this->token, 'asset' => $asset->id]), $map['url']);
        $this->assertSame('image/png', $map['mime']);
        $this->assertSame('pic.png', $map['name']);
        $this->assertStringNotContainsString('projects/', json_encode($map), 'no storage path leaks');
    }

    public function test_an_asset_referenced_by_a_slide_streams_and_others_do_not(): void
    {
        $asset = $this->storeAsset('abcdefghij');
        $unused = $this->asset('unused.png');
        Storage::disk('local')->put($unused->path, 'zzz');
        $this->useAsset($asset);

        $response = $this->get("/s/{$this->token}/asset/{$asset->id}")->assertOk();
        $this->assertSame('abcdefghij', $response->streamedContent());
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame('bytes', $response->headers->get('Accept-Ranges'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));

        $this->get("/s/{$this->token}/asset/{$unused->id}")->assertNotFound();
        $this->get("/s/{$this->token}/asset/999999")->assertNotFound();
    }

    public function test_an_asset_from_another_project_is_never_served_even_when_referenced(): void
    {
        Storage::fake('local');
        $project = Project::create(['user_id' => $this->outsider->id, 'team_id' => $this->otherTeam->id, 'name' => 'Theirs', 'slug' => 'theirs']);
        $foreign = $this->asset('theirs.png', $project);
        Storage::disk('local')->put($foreign->path, 'foreign');
        $this->useAsset($foreign);

        $this->get("/s/{$this->token}/asset/{$foreign->id}")->assertNotFound();
        $this->assertArrayNotHasKey((string) $foreign->id, $this->getJson("/s/{$this->token}/document")->json('assets'));
    }

    public function test_range_requests_are_honoured(): void
    {
        $asset = $this->storeAsset('abcdefghij');
        $this->useAsset($asset);
        $url = "/s/{$this->token}/asset/{$asset->id}";

        $part = $this->get($url, ['Range' => 'bytes=2-5'])->assertStatus(206);
        $this->assertSame('cdef', $part->streamedContent());
        $this->assertSame('bytes 2-5/10', $part->headers->get('Content-Range'));
        $this->assertSame('4', $part->headers->get('Content-Length'));

        $this->assertSame('hij', $this->get($url, ['Range' => 'bytes=-3'])->assertStatus(206)->streamedContent());
        $this->assertSame('ghij', $this->get($url, ['Range' => 'bytes=6-'])->assertStatus(206)->streamedContent());
        $this->get($url, ['Range' => 'bytes=50-60'])->assertStatus(416)->assertHeader('Content-Range', 'bytes */10');
        $this->assertSame('abcdefghij', $this->get($url, ['Range' => 'nonsense'])->assertOk()->streamedContent());
    }

    public function test_the_asset_route_is_locked_behind_the_password(): void
    {
        $asset = $this->storeAsset();
        $this->useAsset($asset);
        $this->lock();

        $this->get("/s/{$this->token}/asset/{$asset->id}")->assertForbidden();
        $this->postJson("/s/{$this->token}/unlock", ['password' => 'open sesame'])->assertOk();
        $this->get("/s/{$this->token}/asset/{$asset->id}")->assertOk();
    }

    // ---- allow download (the print view for link visitors) and hidden slides ---------------------------------

    private function addHiddenAndSecondSlides(): void
    {
        Slide::create([
            'deck_id' => $this->deck->id, 'title' => 'Hidden backup', 'notes' => 'HIDDEN-NOTE', 'sort_order' => 1, 'hidden' => true,
            'canvas_state' => ['elements' => []], 'els' => [['id' => 'hx', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'doc' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'HIDDEN-CONTENT']]]]]]],
        ]);
        Slide::create(['deck_id' => $this->deck->id, 'title' => 'Third', 'notes' => '', 'sort_order' => 2, 'canvas_state' => ['elements' => []], 'els' => []]);
    }

    public function test_hidden_slides_are_not_sent_to_a_guest_at_all(): void
    {
        $this->addHiddenAndSecondSlides();

        $page = $this->get("/s/{$this->token}");
        $page->assertInertia(fn (Assert $p) => $p->has('doc.slides', 2)->where('doc.deck.slide_order', fn ($ids) => count($ids) === 2));
        $this->assertStringNotContainsString('HIDDEN-CONTENT', $page->getContent());
        $this->assertStringNotContainsString('HIDDEN-NOTE', $page->getContent());
        $this->assertStringNotContainsString('HIDDEN-CONTENT', $this->getJson("/s/{$this->token}/document")->getContent());
    }

    public function test_a_picture_only_a_hidden_slide_uses_is_not_served_to_a_guest(): void
    {
        $asset = $this->storeAsset('abcdefghij');
        Slide::create([
            'deck_id' => $this->deck->id, 'title' => 'Hidden', 'sort_order' => 1, 'hidden' => true, 'canvas_state' => ['elements' => []],
            'els' => [['id' => 'im', 'type' => 'image', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'src' => ['asset' => $asset->id]]],
        ]);

        $this->get("/s/{$this->token}/asset/{$asset->id}")->assertNotFound();
        $this->assertArrayNotHasKey((string) $asset->id, $this->getJson("/s/{$this->token}/document")->json('assets'));
    }

    public function test_the_print_view_is_404_unless_downloads_are_allowed(): void
    {
        $this->get("/s/{$this->token}/print")->assertNotFound();

        $this->deck->forceFill(['share_allow_download' => true])->save();
        $this->get("/s/{$this->token}/print")->assertOk();
    }

    public function test_the_guest_print_view_has_no_notes_hidden_slides_or_internal_state(): void
    {
        $this->deck->forceFill(['share_allow_download' => true])->save();
        $this->addHiddenAndSecondSlides();
        DeckComment::create(['deck_id' => $this->deck->id, 'user_id' => $this->owner->id, 'body' => 'internal remark']);

        $response = $this->get("/s/{$this->token}/print")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Press/Print')
            ->has('slides', 2)
            ->where('slides.0.notes', '')
            ->where('options.notes', false)
            ->where('options.hidden', false)
            ->where('backUrl', "/s/{$this->token}"));

        foreach (['secret speaker notes', 'HIDDEN-NOTE', 'HIDDEN-CONTENT', 'internal remark', 'share_password', 'Olive Owner'] as $leak) {
            $this->assertStringNotContainsString($leak, $response->getContent(), "print view leaked {$leak}");
        }
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        // the print view never takes ?notes=1 or ?hidden=1 from a guest
        $this->get("/s/{$this->token}/print?notes=1&hidden=1")->assertInertia(fn (Assert $p) => $p->has('slides', 2)->where('options.notes', false));
    }

    public function test_the_guest_print_view_uses_this_links_asset_route_and_respects_the_password(): void
    {
        $asset = $this->storeAsset('abcdefghij');
        $this->useAsset($asset);
        $this->deck->forceFill(['share_allow_download' => true])->save();
        $this->lock();

        $this->get("/s/{$this->token}/print")->assertRedirect("/s/{$this->token}");

        $this->postJson("/s/{$this->token}/unlock", ['password' => 'open sesame'])->assertOk();
        $this->get("/s/{$this->token}/print")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where("assets.{$asset->id}.url", route('shared.asset', ['token' => $this->token, 'asset' => $asset->id])));
    }

    public function test_the_print_view_of_a_dead_link_is_404(): void
    {
        $this->deck->forceFill(['share_allow_download' => true, 'share_expires_at' => now()->subMinute()])->save();
        $this->get("/s/{$this->token}/print")->assertNotFound();
        $this->get('/s/'.str_repeat('q', 40).'/print')->assertNotFound();
    }
}
