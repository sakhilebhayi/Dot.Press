<?php

return [
    /*
    | Exports and imports (R-40). Everything is overridable by environment for tests and for the host.
    */

    // Writable directory DomPDF installs the bundled fonts into (UFM metrics and copies of the TTFs).
    'font_dir' => env('PRESS_FONT_DIR', storage_path('app/press/fonts')),

    // Where finished export files live (on the "local" private disk) and how long they are kept.
    'export_disk' => env('PRESS_EXPORT_DISK', 'local'),
    'export_dir' => 'press/exports',
    'export_ttl_hours' => (int) env('PRESS_EXPORT_TTL_HOURS', 24),

    // Decks up to this many slides, without video, are rendered inside the request.
    'fast_path_slides' => 12,
    'max_export_slides' => 600,
    'job_attempts' => 2,
    'job_timeout' => 280,

    // PPTX import limits.
    'import' => [
        'max_bytes' => 60 * 1024 * 1024,
        'max_slides' => 300,
        'max_entry_bytes' => 40 * 1024 * 1024,
        'max_xml_bytes' => 8 * 1024 * 1024,
        'max_total_bytes' => 400 * 1024 * 1024,
        'max_entries' => 6000,
        'max_ratio' => 200,
    ],
];
