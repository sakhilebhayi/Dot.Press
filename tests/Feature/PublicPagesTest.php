<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public face of Dot.Press: the landing page and its search and share metadata, the legal pages, the sitemap and the
 * icon set. Everything a crawler or a link preview reads comes from the server, not from the JavaScript.
 */
class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_landing_page_carries_its_own_search_and_share_metadata_in_the_html(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<title inertia>Dot.Press: presentations built as a line of claims</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="', $html);
        $this->assertStringContainsString('<link rel="canonical" href="'.url('/').'">', $html);
        $this->assertStringContainsString('<meta property="og:image" content="'.url('/og.png').'">', $html);
        $this->assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $html);
        $this->assertStringContainsString('<meta name="theme-color"', $html);
        $this->assertStringContainsString('<link rel="manifest" href="/site.webmanifest">', $html);
        $this->assertStringContainsString('rel="preload" href="/fonts/ui/bricolage-300_800-normal.woff2"', $html);
        $this->assertStringContainsString('rel="preload" href="/fonts/ui/instrument-400_700-normal.woff2"', $html);
        $this->assertStringContainsString("localStorage.getItem('press:theme')", $html, 'the theme is chosen before first paint');
    }

    public function test_the_structured_data_is_valid_json_with_only_true_fields(): void
    {
        $html = $this->get('/')->getContent();
        $this->assertSame(1, preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m));
        $ld = json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('SoftwareApplication', $ld['@type']);
        $this->assertSame('Dot.Press', $ld['name']);
        $this->assertSame(url('/'), $ld['url']);
        $this->assertArrayNotHasKey('aggregateRating', $ld);
        $this->assertArrayNotHasKey('review', $ld);
        $this->assertArrayNotHasKey('offers', $ld, 'no price is published, so none is claimed');
    }

    public function test_pages_load_nothing_from_another_origin(): void
    {
        foreach (['/', '/login', '/cookies'] as $path) {
            $html = $this->get($path)->getContent();
            foreach (['fonts.bunny.net', 'fonts.googleapis.com', 'fonts.gstatic.com', 'images.unsplash.com'] as $origin) {
                $this->assertStringNotContainsString($origin, $html, "{$path} still names {$origin}");
            }
        }
    }

    public function test_other_pages_do_not_get_the_landing_metadata(): void
    {
        $html = $this->get('/login')->getContent();

        $this->assertStringNotContainsString('application/ld+json', $html);
        $this->assertStringNotContainsString('og:image', $html);
    }

    public function test_the_sitemap_lists_the_public_pages_and_nothing_private(): void
    {
        $response = $this->get('/sitemap.xml')->assertOk();
        $this->assertStringContainsString('application/xml', (string) $response->headers->get('Content-Type'));
        $xml = $response->getContent();

        $this->assertStringContainsString('<loc>'.url('/').'</loc>', $xml);
        $this->assertStringContainsString('<loc>'.route('cookies').'</loc>', $xml);
        $this->assertStringNotContainsString('/dashboard', $xml);
        $this->assertStringNotContainsString('/s/', $xml);
    }

    public function test_robots_txt_keeps_crawlers_off_share_links_and_the_app(): void
    {
        $robots = file_get_contents(public_path('robots.txt'));

        foreach (['Disallow: /s/', 'Disallow: /live/', 'Disallow: /remote/', 'Disallow: /dashboard', 'Disallow: /decks/', 'Sitemap: '] as $line) {
            $this->assertStringContainsString($line, $robots);
        }
    }

    public function test_the_icon_set_and_manifest_exist_and_agree(): void
    {
        foreach (['favicon.svg', 'favicon.ico', 'favicon-16x16.png', 'favicon-32x32.png', 'apple-touch-icon.png', 'icon-192.png', 'icon-512.png', 'icon-maskable-512.png', 'og.png'] as $file) {
            $this->assertFileExists(public_path($file), $file);
        }
        $this->assertSame([180, 180], array_slice(getimagesize(public_path('apple-touch-icon.png')), 0, 2));
        $this->assertSame([1200, 630], array_slice(getimagesize(public_path('og.png')), 0, 2), 'the share image is 1200 by 630');

        $manifest = json_decode(file_get_contents(public_path('site.webmanifest')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('Dot.Press', $manifest['name']);
        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')), $icon['src']);
        }
    }

    public function test_the_legal_pages_render_and_say_the_things_that_are_true_of_the_product(): void
    {
        $this->get('/privacy-policy')->assertOk()->assertSee('Last updated: 2026-11-07', false);
        $this->get('/terms-of-service')->assertOk()->assertSee('Last updated: 2026-11-07', false);
        $this->get('/cookies')->assertOk()->assertSee('Last updated: 2026-11-07', false);

        $policy = file_get_contents(resource_path('markdown/policy.md'));
        foreach (['InfoDot', 'link', 'local rules', '24 hours', '14 days', 'POPIA'] as $needle) {
            $this->assertStringContainsString($needle, $policy);
        }
        $cookies = file_get_contents(resource_path('markdown/cookies.md'));
        foreach (['dot-press-session', 'XSRF-TOKEN', 'press:theme'] as $needle) {
            $this->assertStringContainsString($needle, $cookies);
        }
    }

    public function test_no_visible_copy_in_the_pages_or_documents_uses_em_or_en_dashes(): void
    {
        $files = array_merge(
            glob(resource_path('markdown/*.md')),
            glob(resource_path('views/errors/*.blade.php')),
            [resource_path('views/app.blade.php')],
        );
        foreach ($files as $file) {
            $this->assertSame(0, preg_match('/[\x{2013}\x{2014}]/u', (string) file_get_contents($file)), basename($file).' has a dash');
        }
    }
}
