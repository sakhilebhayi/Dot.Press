<?php

return [
    /*
    | Strict-Transport-Security max-age in seconds, sent on HTTPS responses only. 0 turns it off. Subdomains are
    | deliberately not included (no includeSubDomains, no preload): sibling platforms on other subdomains manage
    | their own. Raise it once the site has been stable on HTTPS.
    */
    'hsts_seconds' => (int) env('SECURITY_HSTS_SECONDS', 15552000),   // 180 days

    /*
    | A live present session stops working when the person who started it loses the right to see the deck. The check is
    | remembered this many seconds (followers poll every second). 0 checks on every request.
    */
    'live_access_cache_seconds' => (int) env('SECURITY_LIVE_ACCESS_SECONDS', 15),
];
