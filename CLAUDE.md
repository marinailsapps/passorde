# Passorde

Mobile-first orders app: sales people take orders on their phones (an installable PWA that works offline), an operator approves them; stock, clients, reports and Excel exports. Standalone by default (catalogue and clients from CSV, approved orders exported); the optional Passorde Connect add-on links it to Evoliz. This file is the contributor guide. Installation: [README.md](README.md); operations: [docs/RUNBOOK.md](docs/RUNBOOK.md); the JSON API: [docs/API.md](docs/API.md).

## Stack

PHP 8.3, Laravel 12, Inertia 2, Vue 3 + TypeScript, Tailwind 4, PostgreSQL 16. Queue driver `database`, cache `file`. Pest, Pint, Larastan (level 6), ESLint, Vitest. No Redis, Horizon, Sail, Breeze or Jetstream.

## Running

```bash
docker compose up -d                 # app http://localhost:8093, vite :5193, postgres :54393
docker compose exec app php artisan <command>
docker compose exec app composer <command>
docker compose logs -f app queue scheduler
docker compose down                  # add -v to drop the database volume
```

- Services: `app` (serversideup/php 8.3 fpm-nginx, runs the migrations on start), `queue`, `scheduler`, `vite` (dev server with HMR), `postgres`. A fresh clone follows the README's quick start (the PHP dependencies and the key come before the first `up`).
- Ports come from `.env`: `APP_PORT`, `VITE_PORT`, `DB_PORT` (the same value inside the compose network and on the host).
- Passorde Connect comes from its public repository (`composer.json` `vcs`, https) at the exact version `composer.lock` names; a new version is `composer update marinailsapps/passorde-connect` after changing the requirement.
- After changing PHP code used by jobs or scheduled commands: `docker compose restart queue scheduler`. After changing `package.json`: `docker compose restart vite`.
- The service worker is built by `npm run build` only (not by the dev server).
- `compose.prod.yml` is the production stack; rehearse it with `tools/prod-local` (RUNBOOK "Production stack on a laptop"). Layout check: `php artisan sweep:seed`, then `node tools/viewport-sweep/sweep.mjs`.

## Checks (all must pass before every commit)

```bash
docker compose exec app php artisan test       # also --parallel
docker compose exec app vendor/bin/pint --test
docker compose exec app vendor/bin/phpstan analyse
npm test                                       # Vitest (resources/js/**/*.test.ts)
npm run lint
npm run build
```

Tests run against the `passorde_test` database (created by `docker/postgres/init-test-db.sql`); `tests/TestCase.php` refuses any other database (or, under `--parallel`, the process's own copy `passorde_test_test_<n>`) before `RefreshDatabase` empties it. Shell scripts: `docker run --rm -v "$PWD:/mnt" -w /mnt koalaman/shellcheck-alpine:v0.11.0 shellcheck -s sh deploy/*.sh`; workflows: `docker run --rm -v "$PWD:/repo" -w /repo rhysd/actionlint:1.7.7`. `.github/workflows/ci.yml` runs the same checks and builds the neutral image; it pushes and deploys nothing.

## Conventions

- English everywhere in code, comments, docs and commit messages. UI strings are French: English keys (`t('Save')` in Vue, `__('Save')` in PHP), French text in `lang/fr.json`, validation messages in `lang/fr/*.php`.
- Product settings live in `config/passorde.php` with neutral defaults, merged with the installation's private profile (`PASSORDE_PROFILE`, a directory outside the repository; `App\Support\Profile\Profile`), checked by `php artisan passorde:check-profile`. Server settings live in `.env` and `config/server.php`. Never put an installation's profile, names or data in the repository: test profiles in `tests/Fixtures/profiles` are fictional, and every test value is fictional. Tests run with `PASSORDE_PROFILE=none`, on the fictional `tests/Fixtures/profiles/pinned`.
- Brand, icons, colours, the phones' storage names and the phones' older field names are fixed when the assets are built (`scripts/profile-assets.php`, `scripts/brand.ts`); a production image is built for one profile.
- Modes: `App\Support\SyncMode` follows `App\Support\EvolizState`. Evoliz runs only when `EVOLIZ_ENABLED=true`, the three Evoliz keys are set and the licence is usable; every other state is standalone (file mode), licence lapses included. Branch on `SyncMode::isFile()` on the server (routes: the `sync.evoliz` middleware, 404) and `isFileMode()` (`resources/js/lib/syncMode.ts`) in Vue. In file mode `EvolizApi` and `SyncProvider` throw `SyncFileModeException` on any call: never catch it to carry on. Switched off, nothing mentions licences.
- Approved orders in file mode: `approved` is terminal, `orders.exported_at` beside it. Delivered means `Order::DELIVERED_SQL` (`status = 'sent' OR exported_at IS NOT NULL`). An order with `exported_at` is never pushed to Evoliz; one approved while Evoliz did not run (`standalone_at`) only through "Envoyer à Evoliz".
- Never call Evoliz from a web request: only through Passorde Connect (`marinailsapps/passorde-connect`, transport `Passorde\Api\Contracts\EvolizApi`) behind `App\Integrations\Sync\SyncProvider`, from queued jobs or console commands; buttons create a `sync_runs` row and queue a `SyncJob`, pages poll. Reads go to the app's own sinks (`App\Integrations\Sync\Sinks`); the article and client sinks adopt an unlinked row with the same reference or code (`Sinks\Adoption`).
- Writes to Evoliz go through `PassordeProvider` and `App\Integrations\Sync\Push\*` from queued jobs, every request logged in `passorde_requests` with its subject. The request-body assertions of `OrderPushTest`, `ClientChangeTest`, `ClientWithoutOrderTest`, `NewArticleTest` and `EvolizStockAdjustmentTest` are the contract with Evoliz: keep their structure. The app never invoices, updates or deletes an Evoliz delivery after creating it; look one up by `external_document_number` before any create. `PASSORDE_WRITES_ENABLED=false` (the default) refuses every write before it is sent.
- Every Evoliz job gets Pest tests with `Http::fake` fixtures (`tests/Fixtures/evoliz`, helpers `fakeEvoliz()` / `scriptEvoliz()` in `tests/Pest.php`, on `https://evoliz.test`). Tests refuse stray HTTP calls. Tests install a licence with `Passorde\Testing\TestLicence`; never put a real licence or key in a test or in the repository.
- Offline mode lives in `resources/js/offline` (docs/API.md "Offline mirror"). Order changes from the UI go through `confirmOrder` / `deleteOrder` (local copy + outbox), never straight to the API. `outbox.ts`, `mirror.ts`, `search.ts`, `orders.ts`, `db.ts` and `legacy.ts` have no browser globals. Authenticated pages and API answers are never cached by the service worker. Pages that work offline get their props from `offline/pages.ts`: keep the two in step.
- The JSON API under `/api` is a contract for the phones' outbox: document every change in docs/API.md, only add fields, never rename or remove them. An installation that renamed fields states the older names in its profile (`aliases`; `App\Http\Api\ApiAliases`, `resources/js/offline/legacy.ts`, `php artisan passorde:rename-legacy`; RUNBOOK "Older names"); the maps are empty by default. Migrations only add; `PUT /api/orders/{uuid}` stays idempotent.
- Twins that must change together: money in integer cents (`app/Domain/Orders/OrderTotals.php`, `resources/js/lib/orderTotals.ts`); search ranking (`app/Domain/Search/FuzzyMatch.php`, `resources/js/lib/fuzzyMatch.ts`, `tests/Fixtures/search-cases.json`); sales report (`App\Domain\Reports\SalesReport::aggregate()`, `resources/js/lib/salesReport.ts`, `tests/Fixtures/sales-report-cases.json`); client history (`App\Domain\Sales\SalesHistory`, `resources/js/lib/salesHistory.ts`).
- The Evoliz delivery mirror is read-only (`GET /deliveries?period=custom`, `GET /users`); a BL's lines are rewritten together with one `updated_at`.
- Client SMS go through `SendOrderSmsJob` → `OvhSmsClient` only, behind `SMS_ENABLED` (default `false`); tests fake OVH, walkthroughs use `docker/ovh-mock`.
- Operations: `ops:heartbeat` (every minute), `ops:check` (healthchecks), `ops:daily-digest` (07:30, mails only when something is wrong). `deploy/backup.sh` writes `ops_heartbeats` with psql: change both together. `deploy/*.sh` and `docker/backup/entrypoint.sh` are POSIX sh, `set -eu`, idempotent.
- No inline scripts or styles: production serves `style-src 'self'` and `script-src 'self'` (+ Cloudflare Turnstile) from `docker/caddy/security.caddy`; the prod-local smoke test fails on any CSP violation.
- Secrets only in `.env` (never committed, logged or documented); `.env.example` lists every key with empty values.

## Layout

```
app/Models, app/Enums                 Eloquent models, backed enums
app/Domain/{Orders,Clients,Catalog}   domain code (OrderService, OrderTotals, ArticleClassifier, imports, …)
app/Domain/{Sales,Reports,Sms,Ops}    sales history, reports and their Excel exports, SMS text, digest and heartbeats
app/Integrations/Sync                 SyncProvider (PassordeProvider, FileSyncProvider), Sinks/, Jobs/, Push/, SyncLicence
app/Integrations/Ovh                  OvhSmsClient, SendOrderSmsJob
app/Http/Api                          ApiAliases (an installation's older API names)
app/Support                           SyncMode, EvolizState, Profile/ (profile loader, check, brand, wording)
config/passorde.php, config/server.php  product settings (profile) and server settings
resources/js/{Pages,layouts,components,lib,types,offline}   the Vue app; offline/: Dexie mirror, outbox; sw.ts: service worker
lang/fr.json, lang/fr/                French UI and validation strings
scripts/                              profile-assets.php, brand.ts, make-icons.mjs (build-time brand and icons); third-party.php (THIRD-PARTY.md)
docker/, compose.yml, compose.prod.yml  images and stacks; docker/caddy, docker/backup, docker/ovh-mock
deploy/                               bootstrap.sh, deploy.sh, rollback.sh, backup.sh, restore.sh (run by the installer)
tools/prod-local, tools/viewport-sweep  production stack on a laptop with smoke test; layout sweep
docs/{RUNBOOK.md,API.md}, README.md, LICENSE
```
