<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser protections on every response (fleet baseline, R-22 Priority 5.2; same shape as InfoDot's
 * middleware). A header the route already set is left alone.
 *
 * Always enforced: nosniff, X-Frame-Options SAMEORIGIN, a strict-origin-when-cross-origin referrer policy, a
 * Permissions-Policy that denies the powerful features this app never uses, and HSTS on secure requests only.
 * HSTS deliberately omits includeSubDomains and preload because sibling subdomains are not all on HTTPS-only.
 *
 * The Content-Security-Policy is ENFORCED since the R-40 security pass. Scripts run only from this origin or with the
 * per-request nonce (Vite::useCspNonce(): the Vite tags, Ziggy's route table and the theme script in the page head carry
 * it), so an injected script element or attribute cannot run: there is no 'unsafe-inline' and no 'unsafe-eval' for
 * scripts. Styles keep 'unsafe-inline' because Vue writes slide geometry as style attributes, which a nonce cannot cover.
 * Pictures may come from any https address because an author can place an image by URL (the offline package turns those
 * into links); media likewise. The only frames are YouTube and Vimeo. Nothing is fetched from another origin.
 *
 * Kill switch: SECURITY_CSP_REPORT_ONLY=true sends the same policy as Content-Security-Policy-Report-Only (it blocks
 * nothing and reports to /csp-report), for the day a page is found that the policy breaks.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Before the controller runs, so the views that render inside it can print the nonce.
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            (config('security.csp_report_only') ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy') => $this->policy($nonce, $request->isSecure()),
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

    private function policy(string $nonce, bool $secure): string
    {
        return implode('; ', array_filter([
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            // Inline styles: Vue style bindings (slide geometry, theme variables) are style attributes.
            "style-src 'self' 'unsafe-inline'",
            "font-src 'self'",
            // Same-origin signed asset URLs, blob: and data: for previews and inline icons, and https for a picture the
            // author placed by address.
            "img-src 'self' data: blob: https:",
            // Slide "direct" video elements play a URL the author typed, so media is allowed from any https origin.
            "media-src 'self' blob: https:",
            // Same-origin requests only: there is no websocket server on this host.
            "connect-src 'self'",
            "worker-src 'self' blob:",
            // Slide video embeds (the R-40 player uses the privacy friendly youtube-nocookie.com host, after a click).
            "frame-src 'self' https://www.youtube.com https://player.vimeo.com https://www.youtube-nocookie.com",
            "frame-ancestors 'self'",
            "form-action 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "manifest-src 'self'",
            $secure ? 'upgrade-insecure-requests' : null,
            'report-uri /csp-report',
        ]));
    }
}
