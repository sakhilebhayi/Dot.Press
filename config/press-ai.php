<?php

/*
| Dot.Press intelligence (outline, deck analysis, story check, suggestions, data import).
|
| Two modes, always labelled in the response:
|   local  deterministic rules that run on this server with no key. They find structure, numbers
|          and problems in text that already exists. They do not write prose and they never invent
|          a figure; where the source has nothing the slide says "Needs your evidence".
|   ai     a language model through App\Services\Ai\ContentGenerator::completeJson(), only when
|          AI_PROVIDER=anthropic, ANTHROPIC_API_KEY is set and model_mode is true. Counts against
|          the daily AI quota. If a model call fails the answer is local and carries degraded: true.
|
| Nothing here applies a change to a deck. Every answer is a proposal the person accepts.
*/
return [
    'model_mode' => (bool) env('PRESS_AI_MODEL_MODE', true),

    'outline' => [
        'min_slides' => 5,
        'max_slides' => 40,
        'max_brief_chars' => 8000,
        'max_source_chars' => (int) env('PRESS_AI_MAX_SOURCE_CHARS', 60000),
    ],

    'suggest' => [
        'max_text_chars' => 8000,
    ],

    // Model calls cost this many quota units each (the daily quota is in config/ai.php).
    'quota_cost' => [
        'outline' => 3,
        'suggest' => 1,
    ],

    'data' => [
        'max_bytes' => (int) env('PRESS_DATA_MAX_BYTES', 5 * 1024 * 1024),
        'max_rows' => (int) env('PRESS_DATA_MAX_ROWS', 5000),
        'max_cols' => (int) env('PRESS_DATA_MAX_COLS', 100),
    ],

    'analyze' => [
        // Issues of one kind beyond this are counted in the summary but not listed.
        'max_issues_per_kind' => 60,
        'max_fix_ops' => 200,
    ],
];
