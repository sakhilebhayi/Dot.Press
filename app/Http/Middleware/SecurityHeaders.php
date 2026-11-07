<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser protections on every response (fleet baseline, R-22 Priority 5.2; same shape as InfoDot's
 * middleware). A header the route already set is left alone.
 *
 * ENFORCED now: nosniff, X-Frame-Options SAMEORIGIN, a strict-origin-when-cross-origin referrer policy, a
 * Permissions-Policy that denies the powerful features this app never uses, and HSTS on secure requests only.
 * HSTS deliberately omits includeSubDomains and preload because sibling subdomains are not all on HTTPS-only.
 *
 * The Content-Security-Policy is REPORT-ONLY: the browser reports what it would have blocked to /csp-report and
 * blocks nothing, so the policy can be tightened from real traffic before anything is enforced. Since R-40 the pages
 * load nothing from other origins: the interface fonts, the icons and every picture are served from this host. The
 * only outside origins are the ones a slide can embed by the author's choice (YouTube and Vimeo frames, videos
 * played from a typed https address). 'unsafe-inline' stays on script-src: the Ziggy route table and the theme
 * script in the page head are inline scripts, and the report log decides what can go. 'unsafe-eval' is gone: the
 * Vue templates are compiled at build time.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Content-Security-Policy-Report-Only' => $this->policy(),
        ];

        $hsts = (int) config('security.hsts_seconds', 0);

        if ($request->isSecure() && $hsts > 0) {
            $headers['Strict-Transport-Security'] = 'max-age='.$hsts;
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    private function policy(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline'",
            // Inline styles: Vue style bindings (slide geometry, theme variables) are style attributes.
            "style-src 'self' 'unsafe-inline'",
            "font-src 'self'",
            // Slide images are same-origin signed asset URLs; blob: and data: are previews and inline icons.
            "img-src 'self' data: blob:",
            // Slide "direct" video elements play a URL the author typed, so media is allowed from any https origin.
            "media-src 'self' blob: https:",
            // Same-origin requests and the Reverb websocket.
            "connect-src 'self' wss:",
            // Slide video embeds (the R-40 player uses the privacy friendly youtube-nocookie.com host, after a click).
            "frame-src 'self' https://www.youtube.com https://player.vimeo.com https://www.youtube-nocookie.com",
            "frame-ancestors 'self'",
            "form-action 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "manifest-src 'self'",
            'report-uri /csp-report',
        ]);
    }
}
