<?php

return [
    // `mock` is the default and the production setting until an API key exists: Dot.Press then runs
    // its deterministic local rules (outline, deck analysis, story check, suggestions) and labels
    // every answer `mode: "local"`. Set `anthropic` together with ANTHROPIC_API_KEY to switch the
    // outline and suggestion features to the model; see config/press-ai.php.
    'provider' => env('AI_PROVIDER', 'mock'),

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-3-5-sonnet-latest'),
        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 1200),
        'timeout_seconds' => (int) env('ANTHROPIC_TIMEOUT_SECONDS', 20),
        // Structured JSON requests (Dot.Press outlines and suggestions, ContentGenerator::completeJson)
        // return far more text than a single rewrite, so they get their own, larger limits.
        'json_max_tokens' => (int) env('ANTHROPIC_JSON_MAX_TOKENS', 4000),
        'json_timeout_seconds' => (int) env('ANTHROPIC_JSON_TIMEOUT_SECONDS', 45),
    ],

    'limits' => [
        'daily_quota' => (int) env('AI_DAILY_QUOTA', 100),
        'per_minute' => (int) env('AI_RATE_LIMIT_PER_MINUTE', 20),
        // Full-deck generation is charged one quota unit per slide (the
        // same cost as generating that many slides one at a time), so this
        // also caps how much quota a single generate-deck call can spend.
        'max_deck_slides' => (int) env('AI_MAX_DECK_SLIDES', 12),
    ],

    'logging' => [
        'max_prompt_chars' => (int) env('AI_LOG_MAX_PROMPT_CHARS', 5000),
        'max_response_chars' => (int) env('AI_LOG_MAX_RESPONSE_CHARS', 5000),
    ],
];
