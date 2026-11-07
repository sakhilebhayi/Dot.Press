# R-40 Dot.Press: readiness report

Brief: `requirements/dot-press-transformation.md`. Plan: `R40_PLAN.md`. Audit: `R40_AUDIT.md`. Contract: `R40_DOCUMENT_SPEC.md`. Written 2026-11-08.

## What Dot.Press is now

A presentation platform built around one idea, that a deck is an argument. The editor's left rail, the Spine, is a line of beads: hollow until a slide carries evidence for its claim, solid once it does, amber where you are. The same line becomes the progress indicator while presenting. Under it:

| Area | What exists |
|---|---|
| Document | Slides hold their elements as JSON; every change is an operation in an append-only log; operations merge per element; idempotent retries; 14 day log, auto versions, named versions, restore with a safety copy |
| Editor | DOM canvas with smart guides, snapping, rulers, grid, margins, rotation, crop, masks, groups, layers, in place rich text (Tiptap), tables with merge, 14 chart types, icons (96), video and audio, undo and redo, keyboard map, autosave that survives a closed tab or a dead network, 22 inspector panels, touch layout |
| Structure | Sections, hidden slides, 14 layouts that never delete content, 12 themes, brand kits, 20 templates, sizes (16:9, 4:3, portrait, custom) with three resize modes |
| Presenting | Player, presenter console with notes, timer and next slide, audience window, phone remote, audience following, offline single file package, 7 transitions, 11 effects in a click sequence, morph |
| Review | Consistency inspector (11 rule families, fixes applied one at a time), story assistant with an editable argument spine, comments with mentions, review requests and approvals bound to a content hash, versions, activity |
| Sharing | Private, named people, team, unlisted link with password, expiry and optional PDF save for visitors |
| Compatibility | PPTX export and import written as raw OOXML with native charts and tables, PDF (server and print view), PNG set, offline package, a preflight that says what is native, approximated or not carried over |
| AI | Local rules by default, a model only with a key, always as proposals with diffs and a visible mode label |

## Evidence

| Check | Result |
|---|---|
| PHPUnit | 1416 tests, 109,450 assertions, 4 skipped (the old API token and registration tests) |
| JavaScript (`npm run test:press`) | 1251 tests: engine, charts, renderer, player, panels, export |
| Shared operation vectors | 80 vectors run by both the PHP server and the JS client with identical results |
| Acceptance A (checks 1 to 25) | 25 of 25 PASS in real Chrome: `R40_ACCEPTANCE_A.md`, `tests/e2e/press/` |
| Acceptance B (checks 26 to 42) | 17 of 17 PASS: `R40_ACCEPTANCE_B.md`, `tests/e2e/press-b/` |
| Security, integrity and honesty (checks 43 to 50) | 22 findings fixed with tests, route sweep across tenants and roles, enforced CSP, real parallel writer test on SQLite: `R40_SECURITY.md` |
| Performance | Benchmark command and before and after tables; large decks stream instead of exhausting memory: `R40_PERFORMANCE.md` |
| Outside design jury | 6.8 of 10 on the first pass from screenshots only; every real finding was then fixed and axe-core shows 0 serious or critical violations on all main pages at 1440 and 390, light and dark |
| Backfill | Rehearsed on a populated pre-R-40 SQLite database: legacy elements became spec elements, positions kept, rotation preserved |

Check 49 (real integrations) and 50 (no fake or placeholder features): the audit of the other platforms found no data API to use, so none is claimed; a grep of every button, link and claim against the code removed the no-op switches and dead links.

## Not verified, and why

- **PowerPoint and Keynote.** Exported files were validated against the OOXML schema, opened with python-pptx and re-imported, never opened in Microsoft PowerPoint or Keynote (none on the build machine).
- **Browsers.** Everything was driven in Chrome. Safari, Firefox and real iPhones and iPads were not tested; touch was emulated.
- **A language model.** No key exists, so model mode was tested only with faked HTTP. Local rules mode is what runs.
- **Production data.** The backfill was rehearsed on a built database, not on the live one.
- **Real world decks.** PPTX import was tested on python-pptx files, the committed fixtures and round trips, not a corpus of Keynote, Google Slides and Canva exports.

## Honest limits (by design)

Changes arrive by short polling within seconds; two people editing the very same text box means the last edit wins; there is no live co-typing. PDF from the server cannot draw gradients as vectors, shadow blur is stepped and CJK, Indic and emoji do not print; the print view has none of those limits. PPTX does not carry element animations, does not embed fonts, and approximates waterfall, funnel, KPI tiles and maps. Video and audio export as a poster. No other Dot platform has a data API, so charts import from CSV, TSV and XLSX files (a Dot.Sheets export included) rather than linking live. Rate limits count by address, so a proxy must pass the real client address.

## Owner list

1. Production `.env` keys (full list in `R40_SECURITY.md`): `APP_ENV=production`, `APP_URL=https://pres.infodot.co.za` (the deploy now fails if the site and APP_URL disagree), `SESSION_SECURE_COOKIE=true`, `ALLOW_REGISTRATION=false`, `INFODOT_SERVICE_SECRET` set. Leave `SECURITY_CSP_REPORT_ONLY` unset: the policy is enforced.
2. Decide whether to connect a model (`AI_PROVIDER=anthropic`, `ANTHROPIC_API_KEY`) and set a monthly cap. Until then the assistants say "Local rules".
3. Legal review of `resources/markdown/{terms,policy,cookies}.md` (points for the reviewer in `R40_SKIN_OWNER_LIST.md`), including the retention numbers and the account deletion behaviour.
4. Open PowerPoint or Keynote once on a real export of a real deck and tell us what looks wrong.
5. The unused legacy AI routes (`generate-slide`, `generate-deck`, `rewrite-text`) return labelled placeholder text with the mock provider; say whether to remove them.
6. `laravel/reverb` is still in `composer.json`; it is unused and can go in a later release.
7. Other platforms: Dot.Sheet, Dot.Files, Dot.Tasks, Dot.Projects, Dot.HR and Dot.Brain have no data API; Dot.Notify has no send API; Dot.docs exposes no document text; Dot.Analytics needs service authentication. Live integrations wait for those.

## Deploy notes

Two migration groups run: the document schema with its backfill, and the performance indexes. The deploy workflow mirrors fonts, images and icons into the docroot (the host's docroot is not `<app>/public`), checks that fonts, build assets and icons answer 200, and fails when `APP_URL` does not match the site. The scheduler gains `press:prune-changes`, `press:assets-gc`, `press:exports-prune` and an hourly session cleanup. New optional switches: `PRESS_COMPRESS`, `PRESS_INLINE_DOC_BYTES`, `PRESS_PDF_PART_MB`.

## Post-deploy checks

1. `/`, `/login`, `/up`, `/robots.txt`, `/sitemap.xml` answer 200; a font from `/fonts/ui/` and `/fonts/fonts.css` answer 200.
2. The response headers carry an enforced Content-Security-Policy with a nonce.
3. Sign in through InfoDot, open the library: every old deck is listed with its slides and first slide thumbnail.
4. Open one old deck: its elements are where they were (1280 by 720 decks stay that size).
5. Create a deck from a template, edit, close the tab, reopen: nothing lost. Present it. Export to PDF and PowerPoint.
6. `php artisan press:bench --only=doc` on the host is not needed; check `storage/logs` is quiet.
