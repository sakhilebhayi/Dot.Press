<?php

use App\Sso\PressProvisioner;

/*
 * Dot.Press' own choices for the shared sign-in package (packages/dot-sso-client). Everything else, including
 * DOTSSO_ENABLED (default false), comes from the package's defaults and the host .env.
 */
return [
    'provisioner' => PressProvisioner::class,

    // Press has always created an account for a person InfoDot vouches for (INFODOT_PROVISION_USERS), so that stays.
    'auto_provision' => (bool) env('DOTSSO_AUTO_PROVISION', env('INFODOT_PROVISION_USERS', true)),

    'landing' => '/dashboard',

    // A deep link may open any signed-in page of the app (the package default allows any same-origin path), and never the
    // sign-in doors, the credential endpoints or the JSON API. Every signed-in page is behind auth anyway; this only
    // keeps the "where was I going" record from pointing at something that is not a page.
    'deep_link' => [
        'allow' => ['#^/#'],
        'deny' => ['#^/(sso|login|register|logout|forgot-password|reset-password|auth|oauth|two-factor-challenge|user/confirm|api|sanctum|broadcasting|guardian|csp-report)(/|\?|$)#i'],
    ],

    // Never intercepted. Press's machine and outside-party doors are all outside the pages the package takes over
    // (login, register, forgot/reset password): /api/* (cookie editor API and bearer API), /s/{token} share links,
    // /live and /remote presenting links, /present-asset, /guardian/health, /up, /sitemap.xml, /csp-report.
    'allow_paths' => [],
];
