# R-40 Dot.Press performance and backend

Written 2026-11-07 on `feat/r40-perf` (trunk `feat/r40-press` merged in, including the security branch). Brief section 20. The rule was: measure first, fix what the numbers condemn, never guess. Every number below comes from the benchmark described in section 2, which you can run again.

The target is the real host: PHP-FPM with about 128 MB per request, SQLite in WAL mode, no daemons, a database queue worked from a cron minute.

## 1. What the numbers condemned

| Finding | Measured | Consequence on a 128 MB host |
|---|---|---|
| The editor page wrote the whole document into the HTML | 300 dense slides: 8 MB of HTML, 120 MB of PHP memory | Fatal error (white page). Same for player, presenter, audience, print and shared link |
| The automatic version snapshot built the deck as PHP arrays | 300 slides: 141 MB (first save), 192 MB (named version) | Fatal error on the first save of a big deck |
| PDF export drew the whole deck in one DomPDF pass | 3.4 MB per dense slide | 25 dense slides fatal at 128 MB, 100 slides fatal even at 256 MB |
| PPTX export kept every picture in memory | 76 MB for 100 slides | 200 dense slides fatal at 128 MB |
| The offline package was built as one string | 34 MB of photographs needed 177 MB | Fatal at 128 MB |
| Slide look-ups had no index (SQLite does not index foreign keys) | `slides`, `decks`, `projects`, `assets` were scanned for every query | Every request got slower as the database grew |
| The library read each deck's first slide, one query each | 24 queries, 138 ms, plus a full hash of every ever-approved deck | Slow library, worse with approvals |
| Inserting a slide updated every slide below it one by one | 300 slides: 319 queries | 30 ms and a long write lock |
| Every tab polled twice every 3 seconds | 40 requests a minute per open tab, even idle | 20 editors cost 0.22 of a core on a fast laptop |
| Sheets view kept every thumbnail it had drawn | 200,000 DOM nodes and 166 MB of heap after one scroll through 300 slides | Slow tab, 30 frames a second on a slow processor |
| Pruning exports updated rows one by one | 100,000 rows: 6.6 s | A cron minute that overruns |
| Nothing compressed JSON or HTML | 4 MB document, 8 MB editor page | Slow first load on a mobile connection |

Not condemned (measured and left alone): the pure editor engine (applying 30 ops takes 0.02 ms, 500 operations grow the heap by 0.2 MB, the undo stack caps at 200), SQLite write contention (4 parallel writers: no "database is locked", p95 3 ms), presence under 10 concurrent clients (p95 12 ms, no errors), drag of 60 and even 400 elements (60 frames a second), memory growth over 1,000 operations in the browser (0.1 MB), PPTX import of 300 slides (2 s, 93 MB), asset pipeline for a 12 megapixel photograph (0.44 s).

## 2. Method

Everything is repeatable and refuses to touch production.

```
php artisan press:bench                          every server section, 10/100/300 slide decks, in its own SQLite file
php artisan press:bench --only=doc,ops --sizes=100 --runs=5 --json=now.json --compare=before.json
php artisan press:bench --seed-only --use-current-db --sizes=10,100,300 --tokens=20   decks, document files and tokens for the scripts
node --expose-gc scripts/bench/engine.mjs       editor engine: ops, undo stack, heap, sync request rate (pure node)
node scripts/bench/bundle-sizes.mjs             JS and CSS per route from the Vite manifest, fonts (--modules Editor for a per-file breakdown)
node scripts/bench/http.mjs --token T --editors 20 --mode old|new --writers N   cost of one request, and N virtual editors
node scripts/bench/browser.mjs --cpu 1,4        Chrome through playwright-core: load, drag, Spine, thumbnails, leak, library, polling
```

`press:bench` sections: `doc ops changes versions library hash presence locks export import present asset prune`. Requests go through the real HTTP kernel in the same process, so routing, middleware, policies, global scopes and JSON encoding are measured (web server and PHP start-up are not). `DB::listen` counts queries and flags any statement repeated five times or more in one request as N+1. Concurrency (writers, presence clients) and everything memory-limited (exporters, importer, package, asset pipeline) run in child PHP processes started with `-d memory_limit=128M`, as PHP-FPM workers would. The benchmark data are the decks of `DeckGenerator`: 30 elements per slide (11 text boxes including bullet lists, 8 shapes, 4 images, 2 charts of 36 values, 2 tables of 35 and 20 cells, 3 icons), about 13 KB of JSON a slide, so 100 slides is 1.3 MB and 300 slides 4 MB. That is a dense deck; a typical one is a third of it.

`scripts/bench/browser.mjs` needs Chrome and `playwright-core` (neither is a project dependency; set `CHROME_PATH` and `PLAYWRIGHT_CORE`). CPU throttling uses the DevTools protocol (`--cpu 4` is a processor four times slower).

The raw results of the runs in this document are in `docs/programme/bench/` (`server-before.json` and `server-after.json` can be given to `--compare`; `export-after.json` is the 128 MB ladder; the browser, HTTP, engine and bundle numbers are alongside).

Where the numbers mislead:

- Times are from an Apple laptop with opcache on. A shared cPanel core is two to four times slower. Compare before with after, and multiply by three for the host.
- "peak_delta" is PHP memory the request added. A bare Laravel request peaks at 37 MB before any of our data is loaded, so add 37 MB for the real figure against `memory_limit`.
- GD (images) allocates outside PHP's accounting in this build, so the asset rows also give the resident set size.
- Browser timings are noisy to about 15 percent between runs; the large differences are the real ones. The 4x runs are the ones to trust for slow devices.

## 3. Results: server

All server rows are `press:bench` unless stated. "Before" is trunk as it stood before this branch; "after" includes the security branch merged in.

### Reading and writing the document

| Measure | Before | After |
|---|---|---|
| GET document, 100 slides (1.3 MB) | 22.6 ms, 13 queries, 18 MB peak | 8.5 ms, 8 queries, 0.3 MB peak |
| GET document, 300 slides (4 MB) | 67 ms, 15 queries, 54 MB peak | 23 ms, 8 queries, 0.35 MB peak; gzip on the wire 151 KB |
| Editor page HTML, 100 slides | 139 ms, 2.7 MB, 40 MB peak | 3.3 ms, 28 KB, 0.1 MB (document fetched by the page) |
| Editor page HTML, 300 slides | 387 ms, 8 MB, 120 MB peak (fatal at 128 MB) | 5.8 ms, 28 KB, 0.1 MB |
| Player, presenter, audience, print pages, 300 slides | 126 to 397 ms each, 120 MB peak (fatal) | 5 ms each, 27 KB, 0.2 MB |
| Editor page, 10 slides (still inline) | 26 queries | 9 queries |
| Operations: one `el.set` | 20 queries | 14 queries |
| Operations: drag of 30 or 50 elements, 300 slides | 8.6 ms, 24 queries | 8.0 ms, 18 queries |
| Operations: first batch of a deck with no version, 300 slides | 211 ms, 27 queries, 141 MB peak | 112 ms, 23 queries, 9 MB peak |
| Operations: slide insert, 300 slides | 31 ms, 319 queries | 19 ms, 16 queries |
| Operations: slide insert, 100 slides | 12.7 ms, 119 queries | 10.6 ms, 16 queries |
| Operations: slide remove with safety snapshot (new in the security branch), 300 slides | not available | 147 ms, 23 queries, 10.5 MB peak (once per two minutes) |
| Operations: 200 ops, 2 MB request, 100 slides | not comparable (old generator sent 0.4 MB) | 116 ms, 53 queries, 66 MB peak |
| Changes feed, nothing new (every poll) | 1.2 ms, 13 queries | 0.8 ms, 6 queries |
| Changes feed, 500 rows catch-up | 12.5 ms | 12 ms |
| `select max(id)` on a 5,000 row log | scan of the deck's index entries | one probe of the `(deck_id, id)` index (0.14 ms to 0.03 ms) |
| Presence beat | 1.5 ms, 15 queries | 1.15 ms, 8 queries |
| Deck hash, 300 slides | 134 ms, 60 MB | 64 ms, memory of one slide |

### Versions

| Measure (300 slides) | Before | After |
|---|---|---|
| Take a snapshot | 217 ms, 192 MB peak | 104 ms, 59 MB peak in the bench process (3 MB in the unit test) |
| Stored size | 3.98 MB | 190 KB (95 percent smaller, base64 of gzip, read back transparently) |
| 40 automatic versions of one deck | 152 MB | 7.2 MB |
| Version list / show | 12 queries / 52 MB | 6 queries / streamed as stored |
| Restore | 310 ms | 142 ms |
| Auto-version frequency | at most one per 20 minutes of editing, so 3 per active hour; unchanged |

Old rows stay plain JSON and read the same. `php artisan press:versions-compress` (scheduled daily at 03:40, 20 second budget) packs them a few at a time.

### Library

| Measure (200 decks, 24 per page) | Before | After |
|---|---|---|
| List 24 | 138 ms, 26 queries | 5.2 ms, 2 queries |
| List 60 | 170 ms, 62 queries | 11 ms, 2 queries |
| Search / sort by title | 85 ms / 52 ms, 26 queries | 5.1 ms / 7.5 ms, 2 queries |
| Payload | 332 KB for 24 cards (13 KB of preview each) | same bytes, 12 KB gzipped |
| Decks that were ever approved | each costs a full hash of the deck on every view | hash kept on the deck row against its `rev` |

The first view of a card after a change cuts it (two queries for the whole page) and keeps it on the deck row.

### Pruning (cron)

| Measure | Before | After |
|---|---|---|
| Change log, 510,000 rows, 500,000 old | 388 ms | 451 ms (deletes in slices of 5,000 so it never holds the write lock for long) |
| Exports, 100,000 rows | 6.6 s | 1.2 s |
| Sessions, 180,000 expired | 224 ms inside a user's request (lottery) | 226 ms, now hourly from the scheduler, in slices |
| Versions, 3,000 automatic rows | 2 ms | 2 ms |

### Concurrency (SQLite, WAL, child processes)

| Measure | Before | After |
|---|---|---|
| 1 writer, 30 batches of 5 ops | p50 2.6 ms, p95 5.9 ms | p50 2.2, p95 5.0 |
| 4 writers on one deck | p95 5.3 ms, max 366 ms, 0 locked | p95 2.9 ms, max 372 ms, 0 locked |
| 4 writers on 4 decks | p95 5.8 ms, 203 batches a second | p95 1.9 ms, 281 batches a second |
| 10 presence clients, 30 beats each | 161 errors of 300 (the benchmark hit the throttle, not a fault; fixed in the benchmark) | 0 errors, p95 12 ms, 645 requests a second |

The longest wait (about 370 ms) is a writer starting while another holds the lock; `busy_timeout` is 5,000 ms. No "database is locked" error occurred in any run.

### Exports, import, package, assets (128 MB child process)

| Measure | Before | After |
|---|---|---|
| PDF, 100 dense slides, 256 MB | fatal | 7.6 s, 98 MB peak (70 MB at 128 MB) |
| PDF, largest deck at 128 MB | 10 slides (25 fatal) | 200 slides (23 s, 90 MB); 300 refused up front with a message |
| PPTX, 100 slides | 275 ms, 76 MB | 257 ms, 61 MB |
| PPTX, largest deck at 128 MB | 150 slides (200 fatal) | 300 slides; 450 refused up front with a message |
| PPTX import, 100 slides | 0.67 s, 58 MB | 0.7 s, 58 MB |
| PPTX import, 300 slides | not measured | 2.1 s, 93 MB at 128 MB |
| Offline package, 40 photographs (34 MB source) | 177 MB peak; fatal at 128 MB | 35 ms, 40 MB peak at 128 MB; file 46 MB |
| Asset pipeline, 12 MP photograph | 548 ms, 39 MB PHP peak | 441 ms, 39 MB PHP peak, 166 MB resident |
| Asset pipeline, 27 MP | 632 ms | 632 ms, 233 MB resident |

The 100 slide PDF takes seven to ten seconds because DomPDF is slow (about 80 ms per dense slide); it runs on the queue for decks over 12 slides. When a deck does not fit the memory limit, the export row says, in words: "This deck is too large to export as PDF on this server: it would need about 130 MB of memory and 128 MB are available. About 247 of its 300 slides fit; choose fewer slides, or ask the administrator to raise the PHP memory limit." A fatal error is not catchable in PHP, so the check happens before the slides are read.

## 4. Results: client

Chrome 1440 x 900, `php -S` with opcache (no web server compression, so transfer sizes are raw), cold cache.

| Measure | Before | After |
|---|---|---|
| Editor first slide drawn, 100 slides | 342 ms (4x CPU: 1,139 ms) | 262 ms (876 ms) |
| Editor first slide drawn, 300 slides | 750 ms (4x: 1,852 ms) | 273 ms (781 ms) |
| Blocking time while loading, 300 slides, 4x | 760 ms | 305 ms |
| Transfer, 300 slide editor | 9.9 MB | 1.9 MB (the document arrives gzipped from the stream: 151 KB) |
| JavaScript downloaded to open the editor, gzipped | 423 KB | 335 KB |
| Open the editor with a 10 slide deck (inline), first slide drawn | 172 ms (4x: 546 ms) | 185 ms (4x: 649 ms): not better, about 15 percent slower at 4x, which is the noise of the benchmark or the price of more, smaller files |
| Drag of 60 elements (every element on the slide selected) | 60 frames a second, longest frame 17 ms | 60 frames a second, longest frame 17 ms |
| Spine (claims view), 300 slides, scroll the whole list | 60 frames a second at 1x and 4x | unchanged |
| Sheets view, 300 slides, 4x CPU, fling through the list | 27.6 to 29.8 frames a second, 350 to 690 ms blocked | 27.3 to 27.8 frames a second, 141 to 203 ms blocked |
| Sheets view after the scroll, then 30 seconds at the bottom | 200,487 DOM nodes, 300 thumbnails, 166 MB heap, never released | 12,374 nodes, 14 thumbnails, 29 MB heap |
| 500 then 1,000 nudges in the editor | heap +0.07 MB then +0.03 MB, no DOM growth | unchanged |
| Library, 205 decks, first page | 17,432 DOM nodes, 214 ms (4x: 1,167 ms, 603 ms blocked) | 6,688 nodes, 158 ms (670 ms, 149 ms blocked) |
| Requests per minute, one tab with another person in the deck | 40 | 20 to 24 |
| Requests per minute, one tab alone, active | 40 | 14 |
| Requests per minute, one tab idle for a minute | 40 | 8 |
| Requests per minute, hidden tab | 2 to 4 | 2 |

Thumbnail cost has not been reduced: drawing one 30 element thumbnail is about 4 ms of layout at 1x (16 ms at 4x), mostly its charts and tables, about 520 DOM nodes. See section 8.

### Bundles per route (gzipped JS up front, CSS up front)

| Route | Before | After |
|---|---|---|
| App shell (every page) | 101 KB | 102 KB (framework, Inertia and Ziggy in their own cacheable files) |
| Editor | 423 KB, 37 KB CSS | 335 KB, 33 KB CSS; 22 chunks load on demand (148 KB) |
| Library | 195 KB | 195 KB |
| Player, Presenter, Audience, Shared, Print | 170 to 181 KB | 170 to 182 KB |
| Phone remote | 106 KB | 106 KB |
| Deferred loader (big decks) | n/a | 104 KB, loads the target page's code in parallel with the data |
| Offline player (one file inside the package) | 93 KB | 93 KB |

The player is a separate bundle from the editor (it shares the renderer, 62 KB, and nothing else heavy). Chart editing (`ChartPanel`) and the rich text editor (Tiptap and ProseMirror, 116 KB) were already lazy. Fifteen more panels are lazy now (animate, approval, assets, assistant, background, comments, export, history, layout, motion, notes, review, share, size, story): they load when first opened. The chart renderer, including the world map data (about 47 KB raw), is part of the shared renderer chunk because chart drawing is synchronous.

Fonts: the four interface fonts are 99 KB, two of them preloaded in the page head. The 48 slide font files (1.4 MB) are `unicode-range` subsets declared in `public/fonts/fonts.css`, so a browser fetches only the families and scripts a slide uses; the largest file is 90 KB.

## 5. What was changed

Server

- `database/migrations/2026_11_09_100000_add_press_performance_indexes.php`: 20 indexes SQLite does not make for foreign keys, among them `slides (deck_id, sort_order, id)`, `deck_changes (deck_id, id)`, `decks (project_id, archived_at)`, `assets (project_id, kind, id)`, `deck_versions (deck_id, kind, id)`, `deck_comments (slide_id)`, `deck_exports (status, expires_at)`, `deck_shares (user_id, deck_id)`, `team_user (user_id)`. Created only when missing; reversible.
- `…_add_hash_and_preview_to_decks.php`: `content_hash/hash_rev` and `preview/preview_rev` on `decks`, both caches keyed by the deck's `rev`. A slide written outside an operations batch (which does not bump `rev`) clears them (`Slide::booted`).
- `DeckDocument::chunks()` and `JsonStream`: the document is written slide by slide from the stored JSON, never decoded, gzipped on the fly. `GET /api/decks/{deck}/document`, the version snapshot, the version show answer and the shared link document use it. An empty object stays an object.
- `DeferredPage` and `Pages/Press/Deferred.vue`: a deck whose elements are over `press_perf.inline_bytes` (400 KB, about 30 dense slides) is sent as a small page that names the real page and a streaming URL; the page fetches the data while the real page's code loads. Applies to editor, player, presenter, audience, print, shared link and live audience link. Small decks are untouched.
- `DeckHash`: incremental (one slide at a time), same value byte for byte (a test keeps the old implementation as the definition), and `current()` remembers it against `rev`.
- `DeckVersion`: snapshots stored as `gz1:` plus base64 of gzip when that saves 60 percent or more; reads undo it; `press:versions-compress` backfills.
- `DeckPreview`: library cards cut once per `rev`, two queries for a page.
- `DeckBatch`: slides that only moved are written in one `UPDATE … CASE`.
- `DeckAccess`: grants remembered for the length of one HTTP request (`MemoizeDeckAccess`), cleared by any share change. Asset look-ups for shared links scan the stored JSON with a pattern instead of decoding every slide.
- `CompressResponse` (global): gzip for JSON and HTML over 4 KB, skipped when already encoded. `public/.htaccess`: year-long cache for hashed build files, a month for fonts, deflate for the files the web server serves itself.
- Exports: `PdfExporter` draws a deck in parts when its estimated memory is over the budget and `PdfMerger` joins them on disk (page resources are copied to each page, links inside a part survive, a link into another part is reported as a note); `Package` (PPTX) spills parts over 24 KB to temporary files; `ExportService::assertFits()` refuses what the memory limit cannot hold, in words, before reading the deck; the export controller counts slides instead of loading them.
- `PresentPackage::buildToFile()`: written piece by piece, downloaded from the file and deleted. The file budget follows the memory limit (one file is held three times), the total no longer does (the security branch had tied the total to one eighth of the memory limit; its test now asserts the file budget instead).
- Operations request limit follows the memory: `(limit - 48 MB) / 40`, 2 MB at 128 MB, never above 8 MB or below 512 KB. A 2 MB request peaks at 66 MB. The client sends at most 1.5 MB per batch and splits a batch the server refuses with 413.
- Pruning: exports in bulk, the change log in slices, sessions hourly from the scheduler.
- Presence and changes: the changes endpoint answers "nothing new" after one index probe; presence deletes stale rows only when it finds some and skips the slide look-up for a slide it already checked.
- Throttles that were missing: `press-library` 180 a minute (projects and decks), `press-assets` 300 (picker), `press-upload` 30 a minute and 400 an hour (a 12 MP decode is half a second of CPU), `press-export-status` 150.

Client

- `core/cadence.js` (pure, tested): presence every 3 s with company, 6 s alone, 15 s after a minute without input, 30 s after five, none when hidden. Presence answers with the log position and the tab reads the changes feed only when it is ahead; the feed has a safety poll every 15 s focused, 30 s hidden. Coming back after a pause beats at once.
- Panels loaded on first use; `LazyThumb` uses one shared observer and takes thumbnails down 20 seconds after they leave the neighbourhood; library cards use it too; `loading="lazy" decoding="async"` on the remaining thumbnails.
- `vite.config.js`: `manualChunks` for the framework, Inertia and its helpers, and the rich text editor.

## 6. Budgets

These are the numbers to hold the platform to. `tests/Feature/Press/Performance` pins the query counts and the memory behaviour; `core/__tests__/cadence.test.mjs` pins the request rates.

Requests per minute for one open tab: 24 focused with company (20 presence, 4 safety) plus one request per change by someone else; 14 focused and alone; 8 after a minute without input; 2 hidden. A tab that is itself saving adds one request per 0.6 s of continued editing at most (the editor debounces at 600 ms and sends one batch at a time).

PHP-FPM load for 20 simultaneous editors, measured with 20 virtual editors on distinct accounts for 30 seconds against `php -S` with opcache:

| Case | Requests a minute each | Requests a second, all | CPU of one core (this laptop) | p95 latency |
|---|---|---|---|---|
| Before, nobody editing | 39.8 | 13.3 | 0.22 | 28 ms |
| After, nobody editing | 19.9 | 6.6 | 0.12 | 29 ms |
| Before, one editor changing the deck every 6 s | 40.3 | 13.4 | 0.23 | 35 ms |
| After, one editor changing the deck every 6 s | 28.0 | 9.3 | 0.15 | 28 ms |
| Before, four editors changing it every 6 s | 41.8 | 13.9 | 0.26 | 34 ms |
| After, four editors changing it every 6 s | 29.5 | 9.8 | 0.16 | 28 ms |

On a shared host, multiply the CPU column by about three: 20 editors on a calm deck take roughly a third of a core, a busy one half. Each request holds a PHP worker for 8 to 14 ms on this laptop (25 to 40 ms on the host), so 20 editors need well under one worker busy on average; an account limit of 20 entry processes is not approached. Every change another person makes costs each other tab one extra request to fetch it, which is the minimum for polling. If a deck regularly has more than about 30 editors, the answer is not tuning but a longer presence interval (`ACTIVE_WITH_OTHERS_MS`) or a push channel the host does not have.

Request cost on this laptop (sequential, one process, opcache): presence 8.8 ms, changes with nothing new 8.3 ms, one operation 13.6 ms, document of 100 slides 25 ms and of 300 slides 44 ms (gzipped), editor HTML 13 ms.

Memory per request (add 37 MB for the server itself, limit 128 MB): document of any size 0.4 MB; editor and player pages 0.2 MB for big decks, 4 MB when inline; an operations request about 32 times its size (limit 2 MB at 128 MB); version snapshot 3 MB; library page 5 MB for 24 cards, 12 MB for 60; export: the document as arrays (15 times its element JSON) plus 22 MB (PDF) or 6 MB (PPTX) plus 0.04 or 0.05 MB a slide, so about 250 dense slides of PDF or 300 of PPTX at 128 MB, and everything the 600 slide limit allows at 512 MB (the export job raises its own limit to 512 MB when the host allows `ini_set`).

Limits in force: 600 slides, 400 elements a slide, 2 MB a slide, 200 ops and 1.5 MB per client batch, 40 automatic versions kept, change log kept 14 days, export files 24 hours.

## 7. Throttle review

| Limiter | Setting | Verdict |
|---|---|---|
| `press-ops` | 300 a minute per person and deck | Kept. The editor sends at most about 100 a minute; the headroom is for retries. The body is limited separately (section 5) |
| `press-changes` | 240 | Kept. A tab now makes 4 to 24 a minute; 240 allows many tabs and a long catch-up |
| `press-presence` | 150 | Kept. One tab makes at most 20 |
| `press-read` | 120 | Kept for document, versions and templates |
| `press-library`, `press-assets`, `press-upload`, `press-export-status` | new | These routes had no limit at all |
| `press-export` 10/min 60/hour, `press-import` 4/min 20/hour | | Kept: an export is seconds to minutes of CPU |
| Security branch limits (analyze 12/min, versions 10/min, share add 20/min, live follower limits) | | Left as the security branch set them |

## 8. What remains slow, and why

- **Drawing many thumbnails at once.** A dense slide's thumbnail is about 520 DOM nodes and 4 ms at 1x. Flinging through the sheets view of 300 dense slides on a four times slower processor runs at 27 frames a second. Cutting this needs a cheaper thumbnail renderer for charts and tables (a thumbnail does not need 36 data points or 35 table cells), which belongs to the renderer, not to the loading code. Unmounting far thumbnails fixed the memory, not the fling speed.
- **PDF export speed.** DomPDF draws about 12 dense slides a second at best (100 slides: 7 to 10 s, 200: 23 s). It runs on the queue, so nobody waits in a request, but a 300 slide PDF, projected from the 200 slide run, takes about 35 seconds of the cron worker. Memory is bounded; time is not.
- **The document as PHP arrays in exporters and review.** Exporters, the review and the intelligence services read the document as arrays (15 times its JSON). That caps a 128 MB host at about 250 dense slides of PDF and 300 of PPTX, and review of a deck at the 600 slide limit needs more than 128 MB (the security branch already limits it to 12 runs a minute). Feeding them one slide at a time is the next step; they were not changed here because the contract is shared with the exporter and intelligence work.
- **Large operations batches.** A 2 MB batch of 200 table inserts takes 116 ms and 66 MB; sanitising copies the payload several times. That is why the request limit follows the memory.
- **Deck content budget query.** A batch that makes a deck bigger by even one byte (a drag that turns 99 into 100.25) asks the database for the sum of `length(els)` over the deck (the security branch's budget). At 300 slides that is a few milliseconds; storing the byte count on the deck row would remove it.
- **Library card payload.** 13 KB of preview elements a card, 332 KB a page (12 KB gzipped). A card could carry fewer elements.
- **First load of a big deck still starts with two requests** (page, then document) instead of one; the gain from parallel loading is larger than the cost.
- **Asset pipeline memory on bundled GD.** A 27 megapixel image needs 233 MB resident here. The processor raises `memory_limit` when `ini_set` is allowed and otherwise refuses with the number of megapixels the server can take; that path was not exercised on a host with `ini_set` locked.
- **Chart map data (47 KB raw)** ships with the renderer for every page because chart drawing is synchronous and the chart parity tests run it in node.

## 9. Operating notes

- Run `php artisan migrate` (two new migrations) and `npm run build`. Nothing else is required; `press:versions-compress` and the session prune are scheduled.
- Set `PRESS_COMPRESS=false` if the web server compresses and you want it to be the only one; `PRESS_INLINE_DOC_BYTES` moves the point where pages switch to fetching the document; `PRESS_PDF_PART_MB` caps the memory one PDF part may be planned to use (150 by default, lowered further by the memory limit).
- The benchmark writes its throw-away database to `storage/app/bench` and never runs when `APP_ENV=production`.
- Reading made of the brief: "queries per batch" means queries per HTTP request of an operations batch; "presence under 10 concurrent clients" was run as 10 child processes sending 30 beats each as fast as they can; the PDF and PPTX "maximum deck" is the largest slide count of the generated dense deck that completes inside a 128 MB child process, and "degrade" means the up front refusal and the PDF parts.
