# R-40 Dot.Press: product and technical audit

Brief: `requirements/dot-press-transformation.md`. Audited 2026-11-07 on `ca60019` (main). The code was read, the app was run locally and the baseline suite was run: 217 tests, all passing, 4 skipped.

## What exists

Laravel 13, Inertia, Vue 3, Tailwind 4, Konva (canvas), Tiptap (rich text), DomPDF and PhpPresentation (export), SQLite in production, Jetstream teams, InfoDot SSO, Sanctum. About 14,000 lines of first-party code. The editor is one 2,462-line Vue page, the player is a 402-line page. The data model is Project, Deck, Slide, Element (a table that the canvas save rewrites row by row), Asset, AiUsageLog.

## Classification

| # | Feature | Class | Evidence |
|---|---|---|---|
| 1 | Sign-in, teams, roles, InfoDot SSO | 1 fully functional | Jetstream roles, viewer write protection and SSO covered by tests |
| 2 | Tenant isolation on the presentation domain | 2 | Global scopes by team plus policies. Good. No test that a second team cannot read another team's deck through every route |
| 3 | Project and deck CRUD | 2 | API only, no real library. The dashboard lists projects and decks with rename. No favourites, recents, search, folders, duplicate, delete confirmation |
| 4 | Editor | 2 and 3 | One slide per page: changing slide reloads the page, so there is no deck-wide state and no slide sorter. A single "Save Slide" button. Canvas only 1280x720 hard-coded |
| 5 | Canvas manipulation | 2 | Drag, resize, rotate, marquee, snap guides exist. No rulers, no zoom, no grid, no distribute, no group semantics beyond a "Group Box" rectangle, no copy/paste, no multi-slide operations |
| 6 | Rich text | 2 | Tiptap overlay while editing, flattened text on the canvas. Limited fonts, no letter spacing, no text fitting |
| 7 | Images and media | 3 | Upload works, crop is fit modes only, no masks, no adjustments, no thumbnails, no optimisation, video is a URL or file with no poster handling, audio uploads accepted but cannot be placed |
| 8 | Shapes and icons | 2 | Rect, circle, polygon, line, arrow, 8 text glyphs as icons |
| 9 | Charts and tables | 6 | Missing |
| 10 | Themes and brand kits | 4 | Deck has a `theme` JSON holding only a background colour. Four "style presets" for shapes. Nothing applies a theme |
| 11 | Layouts and templates | 6 | `layout` column holds the string "blank". `is_template` exists and is unused |
| 12 | Slide sizes | 5 | Hard-coded 1280x720 in the editor, the player and the AI generator. PDF assumes 1200x675 on A4 |
| 13 | Slide transitions and element animation | 6 | None |
| 14 | Presenting | 3 | Fullscreen and keyboard navigation. Navigation reloads a page per slide. No presenter view, no notes shown, no timer, no next-slide preview, no audience view, no media playback controls. Notes are stored and never shown |
| 15 | Autosave and recovery | 5 | Manual save only. Nothing is saved on a closed tab. Revision check is skipped when the client omits `expected_revision`. No local recovery. Save rewrites every element row with one query each |
| 16 | Undo and redo | 2 | Per-slide, in memory, lost on navigation |
| 17 | PDF export | 5 | Text and plain boxes only. Images, video, icons, rotation and fonts are ignored. A 1200px canvas on A4 landscape overflows. Synchronous in the request |
| 18 | PPTX export | 4 | Writes the slide title and all text joined in one box. No positions, shapes, images or styling. It reads like a real export and is not |
| 19 | PPTX import | 6 | Missing |
| 20 | Image export, share links | 6 | Missing |
| 21 | AI generation | 4 and 2 | Provider defaults to `mock`: the mock copies the prompt into a title slide and numbered "Section N" slides. The Anthropic path exists, is rate limited, quota-checked, safety-checked and logged. No key is configured in production |
| 22 | AI whole-deck intelligence, storytelling | 6 | Missing |
| 23 | Collaboration | 3 | "Presence" is a cache heartbeat that can lose users (read-modify-write of an index list). Broadcast of slide updates exists but broadcasting is off by default and the host has no daemon, so nothing is live. The README is honest that it is not websocket collaboration |
| 24 | Comments, mentions, approvals, version history, activity | 6 | Missing |
| 25 | Sharing links and public decks | 6 | Missing |
| 26 | Asset library | 3 | Per project, paginated list, picker for images and videos |
| 27 | Ecosystem integrations | 6 | Only InfoDot sign-in. Nothing else is connected |
| 28 | Responsive and touch | 3 | The editor stacks its panels on a phone. No touch gestures on the canvas |
| 29 | Accessibility | 3 | Canvas content is invisible to assistive technology. No keyboard path to move elements |
| 30 | Security headers, CSP | 2 | Baseline headers enforced, CSP report-only |
| 31 | Backups, health, guardian | 1 | Backup command, health probes, scheduler probe |
| 32 | Tests | 2 | 217 tests, good on auth and policies, thin on the editor, export and AI |

## Technical weaknesses

1. The canvas save is a lossy full rewrite per slide. Two people editing different elements of the same slide get a 409 conflict, and the cure is "reload latest".
2. `Element` rows exist only to be rebuilt from a flat array on every save. They add 1 query per element per save and carry no value of their own (no history, no querying).
3. There is no deck-level state: no sections, hidden slides, slide size, theme, transitions, sections or notes view.
4. Exports are not faithful, and the PPTX export is cosmetic. This is the biggest honesty problem.
5. Nothing is stored when the browser closes unexpectedly.
6. Presence is racy, and anything that needs "live" depends on a websocket host the platform does not have.
7. The editor's look is a dark glossy dashboard with button grids, the pattern the brief rejects.

## What is good and must be kept

Team scoping and policies, the viewer role, signed asset delivery, the AI quota, safety guard and usage log, the InfoDot sign-in, backups, health and guardian probes, the SQLite production settings, security headers.
