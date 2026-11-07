# R-40 Dot.Press: security, integrity and honesty pass

Branch `feat/r40-sec` (the merged trunk plus this pass). Written 2026-11-08 for acceptance checks 43 to 50 of `requirements/dot-press-transformation.md` section 22: tenant isolation, unauthorised access, invalid uploads, interrupted autosave, export failure recovery, no silent data loss, real integrations, no fake or placeholder features.

## How the pass was done

Every route in `php artisan route:list` was read controller by controller and attacked twice: by reading the code and by calling it as a hostile person (another team, a guest, a team viewer, a person who only holds a share). The running app (port 8852, seeded demo plus a second team) was driven in the browser pane for the checks only a browser shows. For each finding a failing test was written first, then the fix. Suites at the end: PHPUnit 1359 tests, all green (1303 at the start); `node --test` 1225 tests, all green (1208 at the start); `composer audit` and `npm audit` report nothing; Pint passes.

## Findings

Severity: High = data loss, corruption or a broken security feature in normal use; Medium = a real attack or a silent failure that needs an unusual step; Low = hardening or an honesty fix.

| Id | Sev | Area | What was wrong | Fix | Test |
|---|---|---|---|---|---|
| S-01 | High | Document integrity | Laravel's `TrimStrings` middleware trimmed every string in an ops request, so a rich text run `"This is "` followed by a bold word was stored as `"This is"` and the words ran together (also table cells and speaker notes). Silent corruption on every formatted edit, and other editors received the corrupted text. | `trimStrings(except: ops and layouts)` in `bootstrap/app.php`. The sanitiser trims the fields it means to trim. | `Press/Security/OpsTextIntegrityTest` |
| S-02 | High | Public share link | `Press/Shared.vue` read props the controller never sent (`deck`, `slides`), so every public link, protected or not, showed a password form that could not succeed (and posted to a route that does not exist). The feature was dead in the browser while its API tests were green. | The page now reads `doc`, `needsPassword`, `error`, `token` and posts to `/s/{token}/unlock`. Verified in the browser: open link, wrong password, right password. | `Press/Security/PageContractTest` (server props against the props every page declares, for all eight pages) |
| S-03 | High | Data loss | The team workspace project is created, on paper, by whoever makes the first deck, and a project row is deleted with its creator. An editor deleting their own account deleted every deck and picture of the team. | `DeleteUser` hands projects of teams the person does not own to the team owner first. The account deletion page says what really happens. | `Press/Security/AccountDeletionKeepsTeamWorkTest` |
| S-04 | High | Data loss | `DELETE /api/projects/{id}` was allowed to editors and erased the team's whole library in one request, with no confirmation. | Only a team administrator may delete a project, and only an empty one (409 otherwise). | same file; `ViewerWriteProtectionTest` updated |
| S-05 | High | Public share link | The public page data and the document route sent hidden slides in full (title, background, every element) to guests, who could read what the owner had hidden. | A hidden slide is sent as an empty place holder that keeps its position. | `PageContractTest::test_a_hidden_slide_is_not_readable_in_a_guests_page_data` |
| S-06 | Medium | Tenant privacy | `POST /api/decks/{deck}/sharing/people` with `user_id` accepted any user id of the platform and answered with that person's name and e-mail address: a directory of every account, across teams. | A user id is resolved only for people in a team of the actor; any other id answers exactly like a missing one. Lookup by address keeps working (that is the feature) with a limit of 20 a minute per person. | `Press/Security/SharingEnumerationTest` (three existing tests moved to e-mail for outsiders) |
| S-07 | Medium | Availability and storage | One 8 KB ops request with 200 `slide.dup` of a 1.8 MB slide wrote 360 MB of rows and used 738 MB of memory. | A request may add 8 MB of slide content, a deck may hold 32 MB; the ops that are not paid for are skipped and reported like any refused op. Removing and shrinking is never charged. Normal editing is untouched (150 element batch test). | `Press/Security/OpsAmplificationTest` |
| S-08 | Medium | Live links | `/live/{token}` and `/remote/{token}` were cached, indexable and sent in Referer; a link kept working for 12 hours after its starter left the team, lost access or the deck was archived or made private; and the rate limits (180 polls a minute per link and address) locked a room of more than three people behind one network address out of a talk. | The pages are private responses (no-store, noindex, no-referrer). A session ends for good when its starter loses the right to present the deck (remembered 15 s). Limits are sized for rooms: 1200 a minute per address and link, 6000 per link. | `Press/Security/LiveSessionExposureTest` |
| S-09 | Medium | Browser hardening | The Content-Security-Policy was report only. | Enforced, with a per-request nonce for scripts (no `unsafe-inline`, no `unsafe-eval` for scripts), `connect-src 'self'`, `img-src` may also use https (authors place pictures by address), kill switch `SECURITY_CSP_REPORT_ONLY`. No violation on the library, the editor with panels open, player, presenter, audience, print view, shared page, remote, landing, sign in, profile, team and error pages (see "Browser checks"). | `SecurityHeadersTest` |
| S-10 | Medium | Mail | With no trusted host, a request carrying a Host header of the attacker's choosing put that host into the password reset mail. | In production every URL is built from `APP_URL` (`SECURITY_FORCE_ROOT_URL`, default on when `APP_ENV=production`, https only upgraded). | `Press/Security/HostHeaderTest` (fails with the setting off, passes on) |
| S-11 | Medium | Mail | Mention and review mails put names, deck titles, comments and notes into Markdown lines, so `[text](https://...)` became a link and `![](https://...)` a picture inside a mail from the platform; a line break in a name reached the subject. | `MailText::plain()` and `subject()`; the three notifications use them. | `Press/Security/MailInjectionTest` |
| S-12 | Medium | Silent data loss | The editor never listened to `skipped`. An operation the server refused was removed from the view and the change quietly undid itself. | `skippedNotice` (pure, tested) and a toast and live region announcement in `useEditor`. Verified in the browser by deleting an element from a second session and editing it in the first: "3 changes not saved: deleted by someone else." | `editor/ui/__tests__/skippedNotice.test.mjs` |
| S-13 | Medium | Silent data loss | A tab that went offline offered, 16 seconds later, to "restore unsent changes from an earlier session" that were its own live queue. Choosing Restore after the retry succeeded could have re-sent `slide.add` and duplicated slides. | A tab never recovers its own record. | `editor/core/__tests__/sync.test.mjs` (fails without the fix) |
| S-14 | Medium | Silent data loss | The automatic history is written after a batch, so a batch that removed 50 slides left no copy of them unless an older snapshot was close. | A batch that removes slides or resizes the deck is preceded by a snapshot of the deck as it was (at most one per two minutes). | `Press/Security/SafetyVersionTest` |
| S-15 | Low | Tenant isolation | A theme background could point at a picture of another team's library (stored, never usable, but accepted). | Validated against the team's own library, like brand kit logos. | `Press/Security/LibraryIsolationTest` |
| S-16 | Low | Dependencies | `composer audit`: five symfony advisories (http-foundation, http-kernel, routing, yaml). `npm audit`: follow-redirects (high). | `composer update symfony/*` (8.1.9 to 8.1.11 family) and `npm audit fix`; both audits are clean and the suites are green. | audits |
| S-17 | Low | CORS | `/api/*` answered `Access-Control-Allow-Origin: *` (framework default). | `config/cors.php`: no origin allowed (`CORS_ALLOWED_ORIGINS`), no credentials. | `SecurityHeadersTest` |
| S-18 | Low | Surface | The framework's `GET/PUT /storage/{path}` file route was on. | `serve => false` on the local disk. Files are only served through the signed asset routes. | `SecurityHeadersTest` |
| S-19 | Low | Input | Id patterns ended in `$`, which PHP lets match before a final newline (`"abc\n"` passed). The HTTP layer trims, imports and internal callers do not. | `/D` on every id, token, colour, layout, theme id and URL pattern. | `OpsIsolationTest::test_the_sanitiser_refuses_ids_with_a_trailing_newline` |
| S-20 | Low | Availability | Saved named versions had no rate or number limit (each is a full copy); the whole-deck review takes 10 s and 480 MB on a 300 slide deck and had a 120 a minute limit. | 10 saves a minute and 200 named versions per deck; review limited to 12 a minute per person and deck. | `OpsAmplificationTest` |
| S-21 | Low | Availability | The offline package loaded a file into memory before checking whether it fits, then held it three or four times (base64, JSON, page): a large deck ended in a fatal error with no message. PPTX import held up to 150 MB of pictures under a 128 MB default limit. | The budget follows the memory limit and the size is read from storage first; oversized files are left out and named in the warnings. Import raises the memory limit the way exports already did. | `HostileContentOutputsTest` |
| S-22 | Low | Honesty | See "Honesty audit" below. | | |

## Honesty audit

Checked: every button and link in every Vue page has a handler or target (parsed, not grepped); every claim on the landing page, the sign in page, the policies and the panels against the code; every integration claim.

- Ecosystem: only InfoDot sign in is real, and the pages say so. No page names Dot.Sheets, Analytics or any other platform as a connection. Chart data imports from CSV, TSV and XLSX files (stated).
- The share panel had a "Let visitors download" switch whose own hint said the page had no download button yet. Removed from the panel and from the page props. The `allow_download` column and API field remain (stored, unused) and are listed below.
- `editor.exportLinks` pointed at routes that do not exist (`/export/decks/{id}/pdf`). It was unused; removed.
- `resources/views/exports/deck-pdf.blade.php` (old PDF, not referenced by anything): removed.
- Three error texts said a feature "is not available in this build yet" (PowerPoint import, the outline, the layout list) when the feature exists and a request had failed. Reworded to say what happened and what to do.
- Privacy policy: said viewers can be allowed to download (not built), said nothing about presenting links, and described account deletion wrongly after S-03. Corrected, dated 2026-11-08.
- AI: the review, outline and suggestions say "local rules" or "model" on every answer; model mode needs `AI_PROVIDER=anthropic` and a key. The old `generate-slide`, `generate-deck` and `rewrite-text` API routes are not used by any page; with the default `mock` provider they return placeholder text labelled as such in the slide notes. Left in place (see open items).

## Verified clean (with how)

- **Tenant isolation.** `RouteSweepTest` calls every route that names something (deck, slide, asset, comment, review, version, export, theme, brand kit, layout, project, user, session token), generated from the router so a new route is swept too, as a person from another team and as a guest: only 401, 403 or 404, nothing changed, nothing leaked. A team admin is refused on every deck route for a private deck. The list routes (`decks` with every filter, `projects`, `assets`, `themes`, `brand-kits`, `layouts`, `user`, `ai/usage`, changes, dashboard) never contain the other team's titles, names or files. Ids in operations (a slide of a deck you cannot open, an asset of another team in `src`, `poster`, `bg`, theme background, `slide.add` content) are skipped, not applied (`OpsIsolationTest`). Comment, review, version and export ids of another deck are 404.
- **Authorisation of present sessions, shares, exports, print view, asset file and poster, import, data parse, intel, AI routes, presence**: read controller by controller; the role matrix tests in `Access*Test` cover them and the sweep re-proves them.
- **Stored XSS.** The document renderer (`docToHtml`: escaped text, fixed tags, validated href and style values), the chart engine (new test: hostile strings in every chart type, attribute names parsed, not grepped), the PPTX writer (every XML part parsed after a hostile deck: well formed, no script, no entity), the PDF export (remote and PHP off, chroot to fonts and temp), the offline package (JSON with `JSON_HEX_*`, `</script` and `<!--` neutralised in the bundle, escaped title, meta CSP with `default-src 'none'`), notification mails, Inertia props (Vue text interpolation; the only `v-html` uses are the document renderer, generated chart SVG and the Markdown legal pages). `HostileContentOutputsTest` feeds seven hostile strings into every text field of a deck and checks all of these outputs.
- **Prototype pollution.** `__proto__`, `constructor` keys at every depth of `el.add`, `el.set`, charts and `adj` never reach the stored document or the change log (test).
- **Uploads.** Already covered by 120 tests and re-read: content sniffing ignores name and client type, SVG is rebuilt from an allow list (scripts, foreignObject, external references, entities, CSS at-rules and url() other than fragments removed), served sandboxed with `default-src 'none'`, images re-encoded (EXIF and appended payloads dropped), pixel budget checked from the header before decoding, file names cleaned of paths and control characters, `Content-Disposition` built from an ASCII slug plus `filename*`, `nosniff` on every file response. PPTX and XLSX: path traversal, zip bombs by size and ratio, lying declared sizes (reads are capped on bytes produced), DOCTYPE and ENTITY refused, entry and slide counts capped.
- **SSRF.** The server fetches no user supplied URL. The only outbound calls go to `api.anthropic.com` (hard coded host) and the configured InfoDot redeem URL. Image and video URLs are fetched by the viewer's browser only.
- **Share and live tokens.** 40 characters from the framework's random generator; lookups answer a plain 404 for wrong, off, expired, rotated or archived (indistinguishable); password unlock is limited per visitor (5 per 15 minutes) and per link (60 per hour), the unlock marker dies with a rotated token or a changed password; only assets a slide of the deck uses are served; pages are `no-store`, `noindex`, `no-referrer`; the remote key is compared with `hash_equals`; commands and notes need the key. Tokens are not compared in constant time (the database equality lookup); at 238 bits that is not exploitable.
- **CSRF and sessions.** Checked live with curl: a cross-site POST that carries the session cookie answers 401 (not stateful, no token), the same with no Origin answers 401, same origin without the XSRF header answers 419. Login regenerates the session. Cookie is `HttpOnly`, `SameSite=Lax`. Jetstream API tokens are switched off, so no bearer token can be minted.
- **InfoDot sign in.** POST only, Origin must be InfoDot's host, the token is never read from a URL, the redirect target is an in-app path with no `//`, `\`, `..` or control characters.
- **Mass assignment.** No controller passes `request->all()`, `validated()` or an unfiltered array to `create`, `update` or `fill`; share secrets and the approval stamp are only set by their services.
- **Secrets.** Nothing in the history, the tracked files or the config: searched for API key shapes, private keys, `APP_KEY`, committed `.env`, sqlite and key files. `.env.example` carries blanks.
- **Concurrency and interruption.** `ConcurrentOpsTest` runs real PHP processes against one SQLite file: 6 writers by 8 batches lose nothing and corrupt nothing (gapless log, one revision per batch, every element once, the shared element whole, order column a permutation), a key sent twice at once is applied once, and four writers killed with SIGKILL in the middle of fat batches leave `integrity_check = ok` and a revision, log and slide count that agree. Run with `DB_TRANSACTION_MODE=DEFERRED` it fails with "database is locked", so the test does bite. In the browser: server stopped, edit made, the save bead said "Offline: changes kept on this device", server restarted, the batch arrived by itself (log entry 2 with the four operations).
- **Export failure and restore.** `ExportApiTest` (existing) covers a failed export saying why, leaving no file, not touching the deck, and a stuck job being failed by the hourly prune. Restore takes a safety version first; S-14 adds one before destructive batches.
- **Large paste and upload failures.** A batch over 8 MB is refused with 413 and the sync engine halves it to find the bad operation; a single refused operation stops saving with a visible error and an explicit discard (existing engine tests). Upload errors come back as 422 with the reason.

## Browser checks (built app, local server, enforced CSP)

Library; editor with Share, Review, Export, Approval, More, every insert menu, shortcuts, claims and sheets views; player (advanced with the keyboard); presenter console; audience window; print view with notes; shared page open and password protected (wrong then right password); live audience page and phone remote; sign in; profile; team settings; cookie policy; 404 page. The server log of `/csp-report` stayed empty; a control (an injected inline script and an `http://` image) was blocked by the browser and reported, so the policy really is enforced and reporting works. Playwright is not installed in this repository and no dependency was added, so these were done in the browser pane, not as a committed Playwright script.

## What remains open and why

1. **Legacy AI routes** (`generate-slide`, `generate-deck`, `rewrite-text`): unused by every page; the mock provider answers with labelled placeholder text. Removing them needs the owner's word because tests and the usage log are built around them.
2. **Visitor download on public links** is not built (S-02 removed the switch). `allow_download` and `share_allow_download` still exist in the API and database, unused. Building it means a public route that builds the offline package for guests; with its memory cost that should be a separate, rate limited decision.
3. **`laravel/reverb`** is still in `composer.json` `require` though broadcasting is off (`BROADCAST_CONNECTION=null`). Harmless, but one more package to patch; removing it touches the lock file.
4. **Operator dependency patch job** runs a fixed command line (`composer update --with-dependencies`, `npm audit fix`) after an operator approves; there is no allow list inside the job, because its own tests run arbitrary commands. A database writer could already do worse. Fleet code, not Press specific.
5. **Tokens in web server access logs.** A share or live link is part of the request path, so the host's access log holds it. Restrict who can read those logs; rotate a link that may have leaked.
6. **Client IP.** Rate limits and the unlock lock use `$request->ip()`. Behind a proxy that is the proxy's address unless the host forwards the real one and `TrustProxies` is set; check on the host (the app trusts no proxy today).
7. **Account lookup by e-mail** in the share panel confirms that an account exists (it is the feature). It is limited to 20 a minute per person.
8. **MySQL.** Concurrency was proved on SQLite (production). The `lockForUpdate` path for other databases is unchanged and untested here.
9. **PHPStan** reports about 300 lines (mostly Eloquent typing); CI counts only its errors. Not addressed.
10. **AI prompts** are stored (up to 5000 characters) in the usage log for the quota and audit; the policy lists them. A retention period is the owner's decision.

## Production settings the owner must have (`.env` of pres.infodot.co.za)

Required:

```
APP_ENV=production
APP_DEBUG=false                 # the deploy forces this
APP_URL=https://pres.infodot.co.za
SESSION_SECURE_COOKIE=true      # deploy.yml now warns when it is not true
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
BROADCAST_CONNECTION=null
DB_CONNECTION=sqlite            # DB_DATABASE absolute, outside the docroot
ALLOW_REGISTRATION=false
INFODOT_PLATFORM_KEY=press
INFODOT_SERVICE_SECRET=<the value InfoDot holds as ECOSYSTEM_SECRET_PRESS>
INFODOT_REDEEM_URL=https://infodot.co.za/api/ecosystem/redeem
```

Leave unset (the defaults are the secure values), or set only to roll back:

```
SECURITY_CSP_REPORT_ONLY=       # unset or false: the policy is enforced. true is the kill switch
SECURITY_FORCE_ROOT_URL=        # unset: on in production. false only if the site must answer on several host names
SECURITY_HSTS_SECONDS=15552000
SECURITY_LIVE_ACCESS_SECONDS=15
PRESS_BATCH_GROWTH_BYTES=8388608
PRESS_DECK_BYTES=33554432
CORS_ALLOWED_ORIGINS=           # empty: no other origin may call /api
```

Only if a language model is wanted: `AI_PROVIDER=anthropic` and `ANTHROPIC_API_KEY=...` (deck text then goes to that provider; the policy says so).

On the host:

- The web root must be `public/`; `.env`, `database/`, `storage/` and `vendor/` must not be reachable by URL.
- PHP: `memory_limit` at least 256M (exports and import raise it to 512M themselves where the host allows), `post_max_size` at least 8M for operations, `upload_max_filesize` and `post_max_size` at least 200M if video uploads are wanted.
- Cron: `schedule:run` every minute and the queue worker, as deployed.
- Secrets: nothing of Dot.Press was found in any git history, so none needs rotating because of this pass.

## Reading of the spec

Section 8 says the server applies operations "in order, inside one transaction". I read the 8 MB and 200 operation limits of section 10 as request limits, and added the content budgets of S-07 on top of them rather than changing them. Section 10 says strings are sanitised: I read "trimmed" as only the fields the sanitiser names (titles, section titles), never the text of a rich text run (S-01).

## Shared files touched

`bootstrap/app.php`, `routes/api.php`, `routes/web.php`, `config/security.php`, `config/cors.php` (new), `config/filesystems.php`, `composer.lock`, `package-lock.json`, `.env.example`, `.github/workflows/deploy.yml` (two warnings), `resources/views/app.blade.php` and the error layout (nonce), `resources/markdown/policy.md`, `resources/js/Pages/Press/Shared.vue`, `Audience.vue`, `Profile/Partials/DeleteUserForm.vue`, `resources/js/press/editor/ui/useEditor.js`, `SharePanel.vue`, `NewDeckSheet.vue`, `LayoutChooser.vue`, `core/sync-storage.js`, and the app files named in the table (`DeckBatch`, `OpApplier`, `VersionService`, `Sharing`, `PresentSessionController`, `SharedDeckController`, `ThemeController`, `ProjectController`, `ProjectPolicy`, `DeleteUser`, `PresentPackage`, `DeckImportController`, `AppServiceProvider`, `SecurityHeaders`, the three notifications). Existing tests changed because behaviour changed on purpose: `AccessSharingApiTest`, `AccessActivityTest` (outsiders are added by e-mail), `AccessPublicLinkTest` (download flag removed), `PresentSessionTest` (room sized limit), `ThemesApiTest` (a real asset), `ViewerWriteProtectionTest` (editors no longer delete projects), `PublicPagesTest` (policy date), `SecurityHeadersTest` (enforced policy).
