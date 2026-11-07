<?php

/*
| Cross-origin requests. Dot.Press has one front end, on its own origin, and no outside API clients, so no other
| origin is allowed (the framework default would answer `Access-Control-Allow-Origin: *` on every /api route).
| Set CORS_ALLOWED_ORIGINS to a comma separated list only if a separate front end is ever added. Credentials are
| never allowed across origins.
*/
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
