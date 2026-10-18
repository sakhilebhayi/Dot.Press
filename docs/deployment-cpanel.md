# Deploying Dot.Press

Production is `https://pres.infodot.co.za`, on the shared cPanel host that also runs the other Dot apps. Everything goes through GitHub Actions; nothing is uploaded by hand any more.

| Workflow | Runs | What it does |
|---|---|---|
| `ci.yml` | every PR and push to `main`; called by `deploy.yml` | Pint, the PHP test suite, the frontend build and a check that the Vite manifest contains every entry the views use. One required check: **Quality gate**. The Composer and npm dependency audits are in the gate too (both clean); PHPStan reports as warnings only. |
| `drift.yml` | manual, **read-only** | Compares every tracked file of `main` with the host's copy (sha256) and prints counts and paths. Run it before the takeover. |
| `deploy.yml` | manual, `confirm=adopt` once, then `confirm=deploy` | See below. |
| `server-logs.yml` | manual, read-only | Tails the newest Laravel log. |

## Why there may be a takeover step

Nothing in the repository says how the app folder on the host was put there, or whether it is a git checkout of `main`. Dot.Press may have been uploaded by hand (as Dot.Projects was, which also left it without its built `public/build` and made its login pages answer 500). **Run the drift report first**: it says whether the host is a checkout and how many files differ from `main`. The host has no Node, so the frontend is built on the runner and synced over either way.

## First time (once)

1. Set the repository secrets: `DEPLOY_HOST` (`pres.infodot.co.za`), `DEPLOY_USER` (`infodotc`), `DEPLOY_SSH_KEY`, `DEPLOY_KNOWN_HOSTS`. `DEPLOY_PATH` is optional: left unset, the app folder is the one `artisan` found under the docroot.
2. Run **Drift Report**. It says how many files differ and lists them. Read it.
3. Run **Deploy to Production** with `confirm=adopt` once. It works on an uploaded copy (no `.git`) and on a checkout of the host's own history that was never pushed; for the latter it keeps that history on a local `host-before-adopt-*` branch. If the folder is already a checkout of this repo's `main`, use `confirm=deploy` instead. It makes a rollback copy, turns the folder into a checkout of `main` without deleting anything untracked (`.env`, `storage/`, `database/`), installs dependencies, ships the build, forces `APP_DEBUG=false`, lists pending migrations (it does **not** run them unless you also pass `migrate=migrate`), rebuilds the caches and checks the site.
4. Check the pending-migrations list, then run `confirm=deploy` to apply them.

## Every release after that

Run **Deploy to Production** with `confirm=deploy` (`gh workflow run deploy.yml -f confirm=deploy`). It runs the CI gate first.

## Database

SQLite, one file (`database/database.sqlite`), on the host as locally; there is no PostgreSQL or MySQL. Queue (`jobs`), cache (`cache`) and sessions (`sessions`) are tables in it. Web requests, cron's `queue:work` and `schedule:run` write to it at the same time, so `config/database.php` sets a 5 s `busy_timeout`, WAL journaling, `synchronous=NORMAL` and `IMMEDIATE` transactions. WAL keeps `database.sqlite-wal` and `-shm` beside the file; they belong to it, so restore or copy all three together (or use `press:backup-database`, which is consistent on its own).

## Safety nets

- A rollback copy of the app folder (without `vendor/`, `node_modules/`, logs and the build) is written to `~/dot-press-<mode>-<time>.tgz`; the newest three are kept. To restore: stop writes, `tar xzf` it over the app folder, `php artisan up`.
- A failure after maintenance mode starts **leaves the site down** and says so, with the copy's name. A failure before it says the site is untouched.
- `APP_DEBUG` is forced to `false` on every run, because with it on any error prints a ~840 KB debug page to every visitor. `APP_ENV=local`, an `http://` `APP_URL` and settings this host cannot run (a `DB_CONNECTION` other than `sqlite`, `BROADCAST_CONNECTION` of `reverb`/`pusher`/`ably`, `QUEUE_CONNECTION` of `sync`/`redis`, `SESSION_DRIVER` or `CACHE_STORE` of `redis`) are reported as warnings, never changed. A stray `public/hot` (the Vite dev-server marker) is removed after the build swap.
- Cron: one weekly scheduled command (the dependency-advisory scan) and one queued class. The deploy installs 2 lines through the same locked, verified crontab block as the other Dot apps, so the account's crontab grows by 2 the first time. Every deploy log prints `== crontab: 2 line(s) for # dot-press installed, M other line(s) preserved`; M must not shrink.
- The smoke check requests `/`, `/login`, `/up`, the built CSS and a harmless `POST /up`, and fails the run if any is wrong, if a debug page is served, or if `/` or `/login` reference a Vite dev server (`:5173`, `@vite/client`).

## Known limits

- The JS build imports from `vendor/` (`resources/js/app.js` pulls in `tightenco/ziggy`), so CI and the deploy run `composer install --no-dev --no-scripts` on the runner before `npm run build`. Without it the build fails with `UNRESOLVED_IMPORT`.

## Not covered yet

PHPStan runs in CI as an informational job (it is not in the gate while it still reports findings). Enrolling the platform in Dot.Brain (see Monitoring below) is a separate change in that repository.

## Monitoring (Dot.Brain guardian)

| What | Where | Auth |
|---|---|---|
| Scheduler liveness | `GET /up/scheduler`: 200 while the minute heartbeat is fresh, 503 when stale (and while the queue worker's five-minute heartbeat is fresh) | none |
| Guardian report | `GET /guardian/health` (`dot-guardian/v1`, platform `dot-press`) | `Authorization: Bearer <GUARDIAN_TOKEN>`; 503 when no token is configured, 401 when wrong |
| Alarm | `scheduler-watchdog.yml` asks `/up/scheduler` every 15 minutes (three tries) and fails the run, which GitHub emails | none |

One-time provisioning, after the release that ships this is deployed:

1. Generate a long random token and store it as the repository secret `GUARDIAN_TOKEN`.
2. Run **Provision Guardian Token** (`confirm=provision`). It writes the token to the production `.env` and refreshes the config; the value is never printed. It then calls `/guardian/health` and expects 200.
3. Enrolling the platform in Dot.Brain (registry entry and `DOT_PRESS_GUARDIAN_TOKEN`) is a separate change in that repository.

Checks in the report: database, cache, scheduler, error rate, queue backlog and worker. A missing heartbeat is `unknown`, never a claim of health.

### Nightly database backup

`php artisan press:backup-database` copies the SQLite file to `storage/backups/press-YYYY-MM-DD.sqlite` using SQLite's online backup API (consistent even while the app writes) and keeps the newest 14 copies (`--keep=N`). It is scheduled daily at 02:55 server time through the same `schedule:run` cron line; the other Dot apps use 02:15 to 02:55, one minute-slot each. It does nothing on a non-SQLite database. The copy sits beside the live file on the same host, so it protects against a bad migration or deletion, not a lost host; copying it off the host is a separate step. To restore: stop writes, copy the chosen file over `database/database.sqlite`, start again. The release's rollback tarball also contains the SQLite file as of that deploy.

