<?php

namespace Tests\Feature\Press;

use Inertia\Testing\AssertableInertia as Assert;

/**
 * R-52: Roboto is the one interface face, served from this origin; every signed-in page wears the shared Dot header
 * (logo, nav, search, Dot Apps switcher, account menu, phone drawer); the library search is unchanged and still scoped.
 */
class R52HeaderTypographyTest extends AccessTestCase
{
    private function root(string $path = ''): string
    {
        return base_path($path);
    }

    private function src(string $path): string
    {
        return (string) file_get_contents($this->root($path));
    }

    public function test_the_page_shell_declares_roboto_and_loads_the_header_from_this_origin(): void
    {
        $html = $this->actingAs($this->editor)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('class="dot-roboto"', $html);
        foreach (['dot-roboto.css', 'dot-type.css', 'dot-tokens.css', 'dot-search.css', 'dot-switcher.css', 'dot-header.css'] as $css) {
            $this->assertStringContainsString('href="/dot-ui/css/'.$css.'"', $html);
        }
        $this->assertStringContainsString('src="/dot-ui/js/dot-nav.js"', $html);
        $this->assertStringContainsString('href="/dot-ui/fonts/roboto/roboto-latin-wght-normal.woff2"', $html);
        foreach (['fonts.googleapis.com', 'fonts.gstatic.com', 'dot-fonts.css', 'dot-switcher-fonts.css', 'Archivo'] as $gone) {
            $this->assertStringNotContainsString($gone, $html);
        }
    }

    public function test_the_roboto_files_exist_and_the_old_interface_fonts_are_gone(): void
    {
        foreach (['roboto-latin-wght-normal.woff2', 'roboto-latin-ext-wght-normal.woff2', 'OFL.txt'] as $f) {
            $this->assertFileExists(public_path('dot-ui/fonts/roboto/'.$f));
        }
        $this->assertDirectoryDoesNotExist(public_path('fonts/ui'));
        $this->assertFileDoesNotExist($this->root('resources/css/press/fonts-ui.css'));

        foreach (glob($this->root('resources/css/press/*.css')) as $file) {
            $this->assertDoesNotMatchRegularExpression('/Bricolage|Instrument|PlexMono|Figtree|https?:\/\/fonts\./', (string) file_get_contents($file), basename($file));
        }
        $this->assertStringContainsString('var(--dot-font-sans', $this->src('resources/css/press/tokens.css'));
        $this->assertStringContainsString('--pp-font: var(--dot-font-sans', $this->src('resources/css/press-player.css'));
        $this->assertStringContainsString('tailwind-theme.css', $this->src('resources/css/app.css'));
    }

    public function test_the_error_pages_use_roboto_from_this_origin(): void
    {
        $html = $this->get('/no-such-page-r52')->assertNotFound()->getContent();
        $this->assertStringContainsString('class="dot-roboto"', $html);
        $this->assertStringContainsString('href="/dot-ui/css/dot-roboto.css"', $html);
        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
    }

    public function test_the_font_files_are_allowed_by_the_content_security_policy(): void
    {
        $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("font-src 'self'", $csp);
        $this->assertStringContainsString("script-src 'self'", $csp);
    }

    public function test_every_signed_in_page_type_wears_the_shared_header_with_search(): void
    {
        // The pages are Vue: what the server can prove is which component renders and that the layouts wear PressHeader.
        $this->actingAs($this->editor)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->component('Press/Library'));
        $this->actingAs($this->editor)->get("/decks/{$this->deck->id}")->assertInertia(fn (Assert $p) => $p->component('Press/Editor'));
        $this->actingAs($this->editor)->get('/user/profile')->assertInertia(fn (Assert $p) => $p->component('Profile/Show'));
        $this->actingAs($this->owner)->get('/teams/'.$this->team->id)->assertInertia(fn (Assert $p) => $p->component('Teams/Show'));
        $this->actingAs($this->owner)->get('/teams/create')->assertInertia(fn (Assert $p) => $p->component('Teams/Create'));

        $this->assertStringContainsString('PressHeader', $this->src('resources/js/Layouts/PressLayout.vue'));
        $this->assertStringContainsString('<PressHeader', $this->src('resources/js/Pages/Press/Editor.vue'));
        $this->assertStringContainsString('PressLayout', $this->src('resources/js/Layouts/AppLayout.vue'));
        $header = $this->src('resources/js/press/ui/PressHeader.vue');
        $this->assertStringContainsString('<DotSearch', $header);
        $this->assertStringContainsString('<DotSwitcher', $header);
        $this->assertStringContainsString('drawer-title', $header);
    }

    public function test_the_header_has_no_inline_style_or_script(): void
    {
        foreach (['resources/js/press/ui/PressHeader.vue', 'resources/js/dot-ui/DotHeader.vue', 'resources/js/dot-ui/DotDrawer.vue', 'resources/js/dot-ui/DotAccountMenu.vue', 'resources/js/dot-ui/DotNavMenu.vue'] as $file) {
            $this->assertDoesNotMatchRegularExpression('/\sstyle=|<script(?![^>]*setup)[^>]*>/', $this->src($file), $file);
        }
    }

    public function test_library_search_stays_scoped_to_the_team_and_to_what_the_person_may_see(): void
    {
        $mine = $this->makeDeck('team', 'Zebra roadmap');
        $private = $this->makeDeck('private', 'Zebra private notes', $this->owner);

        $titles = fn ($user) => collect($this->actingAs($user)->getJson('/api/decks?q=zebra')->assertOk()->json('data'))->pluck('title')->all();

        $this->assertSame(['Zebra roadmap'], $titles($this->editor));
        $this->assertSame(['Zebra roadmap'], $titles($this->viewer));
        $this->assertContains('Zebra private notes', $titles($this->owner));
        $this->assertSame([], $titles($this->outsider), 'another team never sees these decks');
        $this->assertSame([], collect($this->actingAs($this->editor)->getJson('/api/decks?q=nomatchxyz')->assertOk()->json('data'))->all());
        $this->assertNotNull($mine);
        $this->assertNotNull($private);
    }

    public function test_the_library_opens_from_another_page_with_the_query_in_the_address(): void
    {
        $this->actingAs($this->editor)->get('/dashboard?q=zebra')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Press/Library'));
    }
}
