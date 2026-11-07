<?php

return [
    /*
    | Dot.Press performance settings (R-40). Measured defaults for shared hosting: PHP-FPM with about 128 MB per
    | request, SQLite, no Node. See docs/programme/R40_PERFORMANCE.md for the numbers behind each value.
    */

    // Gzip JSON answers and streamed documents when the browser accepts it. Apache's mod_deflate is often off on cPanel
    // accounts; when the web server compresses already, the Content-Encoding header makes it skip the response.
    'compress' => (bool) env('PRESS_COMPRESS', true),
    'compress_min_bytes' => 4096,
    'compress_level' => 5,

    // A page that needs the whole deck (editor, player, presenter, print, shared link) writes it into the HTML only when
    // the slides' elements are at most this many bytes. A bigger deck is fetched by the page from a streaming endpoint,
    // because building a page from an 8 MB document in PHP needs more than 120 MB.
    'inline_bytes' => (int) env('PRESS_INLINE_DOC_BYTES', 400_000),

    // Version snapshots are stored gzipped (base64) when that saves most of the space; reads undo it transparently.
    'version_compress' => (bool) env('PRESS_VERSION_COMPRESS', true),
    'version_compress_min_bytes' => 8192,
];
