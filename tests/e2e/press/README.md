# Dot.Press end to end tests (acceptance checks 1 to 25, plus roles, collaboration and layout)

Real Chrome, real pointer and keyboard events. Every claim is read back from the saved document through the API, so a test only
passes when the server holds what the person did.

## Setup (once per run, from the app folder)

```
cd tests/e2e/press && npm install && cd ../../..      # playwright-core only; Chrome is taken from /Applications or CHROME_BIN
npm run build
tests/e2e/press/press-demo.sh "$PWD" 8850                       # fresh sqlite db with owner@demo.test, editor@demo.test, viewer@demo.test (password: password)
```

`press-demo.sh` (in this folder) is the helper that creates the demo database and starts `php artisan serve --port=8850`. Any equivalent works:
the tests need the app on `E2E_BASE` (default `http://127.0.0.1:8850`) seeded with `PressDemoSeeder`.

## Run

```
node tests/e2e/press/01-creation.e2e.mjs      # checks 1 to 10: blank, template, outline, generate, slides, sections
node tests/e2e/press/02-editing.e2e.mjs       # checks 11 to 18: text, typography, image upload, crop, shapes, tables, charts, chart data
node tests/e2e/press/03-arrange.e2e.mjs       # checks 19 to 24: group, layers, themes, slide size, undo and redo, shortcuts
node tests/e2e/press/04-autosave.e2e.mjs      # check 25: autosave, offline, closed tab, server killed for 20 s, 500s, refused change
node tests/e2e/press/05-collab-sync.e2e.mjs   # two browsers on one deck: order and ids stay in step
node tests/e2e/press/06-roles.e2e.mjs         # viewer (read only everywhere) and editor
node tests/e2e/press/07-responsive.e2e.mjs    # 390, 768, 1024, 1180, 1440 in light and dark; Inspector button, panel list
```

Each prints `PASS` or `FAIL` per step and exits non zero on a failure. Screenshots go to `E2E_SHOTS`
(default `$TMPDIR/press-e2e-shots`); a failing step also saves `<suite>-FAIL-<step>.png`.

Environment: `E2E_BASE`, `E2E_DB`, `E2E_APP`, `E2E_SHOTS`, `E2E_TIMEOUT` (ms per action, default 8000), `CHROME_BIN`.

Notes
- Sign-in is throttled to 5 a minute, so a session is saved per account in `E2E_SHOTS` and reused for 50 minutes.
- `04-autosave` kills and restarts the php server on port 8850 (pkill on `artisan serve --port=8850`). Run it only against a throw away server.
- The app sends a Content Security Policy without `unsafe-eval`, so the tests never use `page.waitForFunction` (`waitUntil` in `lib.mjs` polls with `evaluate`).
- The suites create their own decks and do not depend on each other. They do not clean up; the demo database is disposable.
