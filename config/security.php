<?php

return [
    /*
    | Strict-Transport-Security max-age in seconds, sent on HTTPS responses only. 0 turns it off. Subdomains are
    | deliberately not included (no includeSubDomains, no preload): sibling platforms on other subdomains manage
    | their own. Raise it once the site has been stable on HTTPS.
    */
    'hsts_seconds' => (int) env('SECURITY_HSTS_SECONDS', 15552000),   // 180 days

    /*
    | The Content-Security-Policy is enforced. SECURITY_CSP_REPORT_ONLY=true is the kill switch: the same policy is sent as
    | report-only (nothing blocked, violations go to /csp-report) until the page it broke is fixed.
    */
    'csp_report_only' => filter_var(env('SECURITY_CSP_REPORT_ONLY', false), FILTER_VALIDATE_BOOL),

    /*
    | A live present session stops working when the person who started it loses the right to see the deck. The check is
    | remembered this many seconds (followers poll every second). 0 checks on every request.
    */
    'live_access_cache_seconds' => (int) env('SECURITY_LIVE_ACCESS_SECONDS', 15),

    /*
    | Growth limits for the presentation document (ops). One request may add at most this many bytes of slide content
    | (duplicating, adding, enlarging), and a deck may hold at most press_deck_bytes of it in total. Pictures and media
    | are assets, not slide content, so a normal deck is far below both.
    */
    'press_batch_growth_bytes' => (int) env('PRESS_BATCH_GROWTH_BYTES', 8 * 1024 * 1024),
    'press_deck_bytes' => (int) env('PRESS_DECK_BYTES', 32 * 1024 * 1024),
];
