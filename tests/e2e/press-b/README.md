# Dot.Press acceptance scripts B (checks 26 to 42)

Real end to end scripts. They drive the real UI in Chrome with `playwright-core`, make real files with ffmpeg and
python-pptx, and assert on the DOM, the network, the database and the files that come out (python-pptx, pypdf, PyMuPDF).
Screenshots go to `$E2E_OUT` (default `/tmp/press-b-out`) and are meant to be looked at.

## Needs

- A served app with demo data, a database queue worker, and several PHP workers (two browsers poll at once):

      php artisan migrate:fresh --seed --seeder=PressDemoSeeder --force
      npm run build
      PHP_CLI_SERVER_WORKERS=6 php artisan serve --port=8851 &
      php artisan queue:work --sleep=1 --tries=1 &       # restart it after any PHP change

- `E2E_BASE` (default `http://127.0.0.1:8851`), `E2E_DB` (the sqlite file the app uses, default `/tmp/press-demo-pw-e2eb.sqlite`), `E2E_APP` (repo root, default found from the script path).
- Google Chrome (`channel: chrome`) or `CHROME_PATH`.
- `/tmp/press-venv/bin/python` with `python-pptx pillow pypdf pymupdf` (`PRESS_PY` overrides), `ffmpeg` (`FFMPEG` overrides).
- `npm install` in this folder (only `playwright-core`; `node_modules` is ignored by git).

## Scripts

| Script | Checks |
|---|---|
| `present.mjs` | 26 preview, 27 full screen, 28 navigation, 29 transitions (every type, forward, back, jump, reduced motion), 30 element animations (every step compared with the pure playback model), 32 notes overlay |
| `presenter.mjs` | 31 presenter view, timer, next slide, audience window over BroadcastChannel, phone remote and audience following (separate contexts), 32 notes never reach an audience surface |
| `media.mjs` | 33 video and audio playback, autoplay and start offset, reduced motion, phone |
| `share.mjs` | 34 every visibility level, link password, expiry, rotate, guest viewer, allow download (print view) |
| `collab.mjs` | 34 and 35 people, roles, outsiders, read only editor |
| `comments.mjs` | 36 comments, mentions, replies, resolve, pins, notifications |
| `history.mjs` | 37 named versions, compare, restore, undo, another editor follows, approvals, restoring Approved |
| `import.mjs` | 38 PPTX import (python-pptx decks, fixtures, bad files) and the round trip |
| `export.mjs` | 39 PPTX, 40 PDF (server), print view PDF, PNG images, 41 preflight accuracy and formatting |
| `package.mjs` | 41 offline present package opened with the network blocked |
| `recovery.mjs` | export failure and recovery (makes the export storage unwritable for a while; restores it on exit) |
| `responsive.mjs` | 42 phone (390x844) and tablet (768x1024) with touch |

Run one with `node present.mjs`; it exits non-zero when an expectation fails. `run-all.sh` runs them all.
`lib.mjs` has the helpers, `fixture.mjs` builds the acceptance deck and a rich deck through the real API.
