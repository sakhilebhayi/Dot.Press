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
 * blocks nothing, so the policy can be tightened from real traffic before anything is enforced. The origins listed
 * are the ones the pages really load (Bunny and Google Fonts, the Unsplash sign-in photo, ui-avatars profile
 * pictures, YouTube and Vimeo embeds in slides). 'unsafe-inline' and 'unsafe-eval' stay on script-src for now:
 * the Ziggy route table is an inline script, and the report log decides what can go.
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
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
            // fonts.bunny.net: the app shell font; fonts.googleapis.com: the sign-in, welcome, policy and error pages.
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net https://fonts.googleapis.com",
            "font-src 'self' data: https://fonts.bunny.net https://fonts.gstatic.com",
            // images.unsplash.com: the sign-in and welcome photo. ui-avatars.com: Jetstream's default profile picture
            // (profile_photo_url) in the app layout and profile forms. Slide images are same-origin signed asset URLs.
            "img-src 'self' data: blob: https://images.unsplash.com https://ui-avatars.com",
            // Slide "direct" video elements play a URL the author typed, so media is allowed from any https origin.
            "media-src 'self' blob: https:",
            "connect-src 'self' wss: https:",
            // Slide video embeds (resources/js/Pages/Canvas/Presentation.vue).
            "frame-src 'self' https://www.youtube.com https://player.vimeo.com",
            "frame-ancestors 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            'report-uri /csp-report',
        ]);
    }
}
