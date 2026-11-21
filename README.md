# Passorde

Passorde is a mobile-first orders app. Sales people take orders on their phones (an installable PWA that keeps working offline and sends its orders when the network comes back); an operator approves them in the office. Around that: the catalogue with its stock, the client directory, stock and sales reports, and Excel exports.

It runs **standalone** by default: the catalogue and the clients are imported from CSV files (or typed), and approved orders are exported to CSV or Excel for your invoicing software. An optional, separately licensed add-on links it to Evoliz instead (compatible with Evoliz, not affiliated with Evoliz SAS / Visma; see "Optional Evoliz add-on").

The interface is in French. Code, comments and documentation are in English.

## Who this is for

Passorde is published for a developer who knows PHP/Laravel and Docker and is ready to install, configure, run and maintain it themselves. **Nobody offers installation, hosting, customisation or support for it, paid or free.** Issues and pull requests may be read, but no answer, fix or release is promised.

## Requirements

- Docker Engine with the Compose plugin (development and production both run in containers).
- Git, and Node.js 22 with npm on the host if you run the front-end checks (`npm test`, `npm run lint`, `npm run build`) outside the containers.
- Network access to https://github.com during `composer install` and the image build: Composer fetches the Passorde Connect package (`marinailsapps/passorde-connect`, a public repository, no account needed) at the exact version `composer.lock` names, even when the Evoliz add-on stays off.
- For production: a Linux server (the bootstrap script supports Debian and Ubuntu) with a public DNS name, an SMTP account (password reset mails, daily digest), an S3-compatible bucket for backups, and somewhere to build and store your images (a registry).

Stack: PHP 8.3, Laravel 12, Inertia 2, Vue 3 + TypeScript, Tailwind 4, PostgreSQL 16. Queue driver `database`, cache `file`.

## Quick start (development)

```bash
git clone https://github.com/marinailsapps/passorde.git passorde
cd passorde
cp .env.example .env
```

Edit `.env`:

- `ADMIN_LOGIN`, `ADMIN_PASSWORD` (12 characters or more) and optionally `ADMIN_EMAIL`: the first admin.
- Ports, if the defaults collide with something: `APP_PORT` (8093), `VITE_PORT` (5193), `DB_PORT` (54393).
- On a Linux host whose user or group id is not 1000 (`id -u`, `id -g`): `USER_ID` and `GROUP_ID`, before the first `docker compose build app`, so that the files the container writes (`vendor/`, `storage/`, `.env`) belong to you. They are build arguments of the dev image (`compose.yml`); after changing them, build it again. macOS and Windows (Docker Desktop) need nothing.

To run beside another Docker stack (another checkout of this app, or another project), give this one its own Compose project name, ports and image tag in `.env`, so that containers, volumes, ports and the built image never meet:

- `COMPOSE_PROJECT_NAME=passorde-second` (Compose otherwise names the project after the directory; the database volume carries this name);
- `APP_PORT`, `VITE_PORT`, `DB_PORT`: free ports on the host (`DB_PORT` is also the port Postgres listens on inside the network, so the app follows it);
- `PASSORDE_DEV_IMAGE=passorde-php:second`: the tag `docker compose build app` gives the dev image (default `passorde-php:dev`).

Then, in this order (the app container migrates the database each time it starts, so the PHP dependencies and the key come first, with that start-up step switched off):

```bash
docker compose build app
docker compose run --rm --no-deps -e AUTORUN_ENABLED=false app composer install
docker compose run --rm --no-deps -e AUTORUN_ENABLED=false app php artisan key:generate
docker compose up -d
docker compose exec app php artisan db:seed
```

- `docker compose up -d` starts `app` (nginx + php-fpm, http://localhost:8093), `queue`, `scheduler`, `vite` (dev server with hot reload; it runs `npm ci` itself, in its own volume) and `postgres`. The app container runs the migrations each time it starts. The first start of `vite` takes a few minutes (its `npm ci`); pages answer 500 until it has written `public/hot`.
- `db:seed` creates the first admin from `ADMIN_*` (only if no admin exists) and the payment types; `php artisan migrate:fresh --seed` starts the dev database again from scratch. Remove `ADMIN_PASSWORD` from `.env` once you have signed in and changed the password.
- After changing PHP code used by jobs or scheduled commands: `docker compose restart queue scheduler`. After changing `package.json`: `docker compose restart vite`.
- The service worker (offline mode) exists only after `npm run build`; see docs/RUNBOOK.md "After deploying code" to try it locally.

Sign in at http://localhost:8093 with the admin login.

## The installation profile

`config/passorde.php` holds the product settings of an installation, with neutral defaults. You set your own in a **profile**: one PHP file, `profile.php`, in a private directory **outside the repository**, that returns only the sections it changes, with the same keys. Sections merge key by key; a list (categories, variants...) replaces the default list whole. Server settings (switches, keys, limits) stay in `.env`.

| Section | What it sets |
| --- | --- |
| `brand` | `name`, `short_name` (header, page titles, home-screen label, SMS, mail subject), `logo` (a PNG file in the profile directory: login logo and every icon), `theme_color`, `accent`, `accent_strong`, `accent_soft` |
| `phone` | `storage_prefix`: the names of the phones' local databases. **Never change it once phones use the app**: their unsent orders would stay in the old databases |
| `messages` | `sms`, `sms_with_bl`: the SMS text sent to a client (`:brand`, `:number`, `:quantity`, `:units`, `:ttc`, `:bl`) |
| `units` | what one quantity is called (`singular`, `plural`; default "unité" / "unités") |
| `free_lines` | free (gifted) order lines: `enabled`, `label`, `evoliz_suffix` |
| `client` | `extra_field`: one optional client field (`label`, `csv` column name, `searchable`), or `null` |
| `catalog` | `categories`, `category_name`, `category_groups` (stock report tabs), `variants` (`counts`, `group`), `csv` (names of the category and variant columns of the articles CSV), `import_example`, `classifier` (words that guess an article's category and variant), `new_article` (the "new article" button of the order form), `usual_vat` |
| `evoliz` | add-on only: `payterm_id`, `recovery_indemnity`, `delivery_comment`, `enabled_paytypes` (also used once by the seeder to pick the payment types enabled at first) |
| `reports` | report titles, stock report tab names, write-off labels |
| `texts` | whole French sentences replaced by their English key in `lang/fr.json` |

Stored values (`catalog.categories[].value`, `catalog.variants[].value`, `catalog.category_groups[].key`) are written in the database and sent to the phones: never change one once data uses it; change its label instead.

Where the app reads it:

- `PASSORDE_PROFILE`: the directory the app reads. Empty: the app's `profile/` directory (gitignored) if it holds a `profile.php`; `none`: no profile, the neutral defaults. In production it must be stated (`/var/www/html/profile` or `none`): an empty value stops the app at boot.
- `PASSORDE_PROFILE_DIR`: the host directory that compose mounts read-only as `/var/www/html/profile` (default `./profile`). After changing it: `docker compose up -d`.

Check a profile after every edit (exit code 1 on any error; `--path=<dir>` checks another directory, `--no-database` skips the comparison with the stored data):

```bash
docker compose exec app php artisan passorde:check-profile
```

A short example (a fictional profile; `tests/Fixtures/profiles/fictional/profile.php` is a complete one):

```php
<?php

return [
    'brand' => ['name' => 'Pépinière Exemple', 'short_name' => 'Exemple', 'logo' => 'logo.png',
        'theme_color' => '#2f5d3a', 'accent' => '#2f5d3a', 'accent_strong' => '#1f3f27', 'accent_soft' => '#e6f0e8'],
    'phone' => ['storage_prefix' => 'exemple'],
    'units' => ['singular' => 'plant', 'plural' => 'plants'],
    'catalog' => [
        'categories' => [
            ['value' => 'POMMIER', 'label' => 'Pommier', 'group' => 'fruitiers'],
            ['value' => 'ERABLE', 'label' => 'Érable', 'group' => 'ornement'],
        ],
        'category_groups' => [
            ['key' => 'fruitiers', 'label' => 'FRUITIERS'],
            ['key' => 'ornement', 'label' => 'ORNEMENT'],
        ],
        'variants' => [
            ['value' => 'racine', 'label' => 'Racines nues', 'counts' => true, 'group' => null],
            ['value' => 'godet', 'label' => 'Godet', 'counts' => true, 'group' => null],
        ],
        'usual_vat' => [10, 20],
    ],
];
```

The brand, the icons, the colours and the phones' storage names are fixed when the front-end assets are built, so a production image is built for one profile (see "Production"). In development, `docker compose exec app php scripts/profile-assets.php` then `docker compose restart vite` shows a profile's look. Details: docs/RUNBOOK.md "Installation profile".

## Importing the catalogue and the clients (CSV)

Administration → **Import** (`/admin/import`, admins only, standalone mode only). Choose *Catalogue* (articles) or *Clients* and the file, press **Aperçu** (a dry run: the import runs in a transaction that is rolled back, and the page lists created, updated, unchanged, warnings and errors per line), then **Appliquer cet import** sends the same file again and applies it. **Télécharger le modèle** downloads a template with one fictional row (`/admin/import/templates/articles.csv` or `/admin/import/templates/clients.csv`: `;`-separated, UTF-8 with BOM).

File rules (`app/Domain/Import/CsvReader.php`):

- 5 MB at most; separator `;` or `,` (whichever appears more in the header line, `;` on a tie); UTF-8 with or without BOM, otherwise read as Windows-1252. An Excel workbook (`.xlsx`) is refused: save it as CSV.
- Header names in any case, accents and spaces ignored ("Code postal" is `code_postal`). Unknown columns are ignored and listed in one warning.
- Numbers with a decimal comma or point; yes/no columns accept `oui`/`non`, `o`/`n`, `1`/`0`, `true`/`false`, `x` (yes).
- Only the key column must be in the file; without it the whole file is refused. A key is compared trimmed and case-insensitively; a key twice in the file: the first row wins, later ones are errors. A row with an unusable value is rejected alone.
- An empty cell never erases a value; rows absent from the file are left alone; importing never deactivates (`actif` = `non` does).

| File | Columns, in template order | Key | Required to create a row |
| --- | --- | --- | --- |
| Articles (`app/Domain/Catalog/ArticleImport.php`) | `reference`, `designation`, `prix_ht`, `tva`, `stock`, `gestion_stock`, `actif`, `fournisseur`, `classification`, `categorie`, `variante`, `seuil` | `reference` | `designation`, `prix_ht`, `tva` |
| Clients (`app/Domain/Clients/ClientImport.php`) | `code`, `societe`, `contact`, `adresse`, `code_postal`, `ville`, `telephone`, `mobile`, `email`, (the profile's optional client field, when set), `actif` | `code` | `societe` |

- Articles: `categorie` and `variante` take the names set in `catalog.csv` (the neutral names are always accepted too) and hold a stored value of the profile; an unknown value only warns, and without them the classifier guesses. `seuil` is a whole number (reorder threshold). A new article gets stock management only when its `stock` cell is filled (or `gestion_stock` says so). With `catalog.usual_vat` set, another VAT rate gives a warning.
- Clients: a postcode that is not 5 digits and a mobile that is not a French mobile number give warnings; an invalid email is ignored with a warning. A new code with exactly one client typed in the app (no code, same company and postcode) takes over that client instead of creating a second one.
- The CSV exports of Evoliz's article and client lists are accepted as they are (their French headers are aliases); see docs/RUNBOOK.md.

## Your first order (standalone)

1. **A sales person.** Administration → **Utilisateurs** → **Ajouter**: name, login, optional email and phone, role *Commercial*, a password (or **Générer**). The password is shown once.
2. **Data.** Import the articles and the clients (above). Check the payment methods on Administration → **Modes de paiement**.
3. **An order.** Sign in as the sales person (on a phone, or a second browser) and open **Nouvelle commande** (`/orders/new`): pick the client, add lines, **Confirmer**. The order is saved on the device first and sent by its outbox; the top bar pill reads "En ligne · synchronisé" once it reached the server.
4. **Approval.** As the admin, Administration → **Toutes les commandes** (`/admin/orders`): open the pending order and **Approuver** (or tick several and approve them together); **Refuser** asks for a reason the sales person sees. In standalone mode an approved order is final and keeps its stock reserved until it is exported. With `SMS_ENABLED=true` the client is texted at approval.
5. **Export.** In **Toutes les commandes**, the filter **À exporter** lists the approved orders, all ticked. Download **CSV** or **Excel** (`/admin/orders/export.csv`, `/admin/orders/export.xlsx`; at most 150 orders per file). One row per order line; the CSV is `;`, UTF-8 with BOM, decimal comma, dates `dd/mm/yyyy hh:mm` Europe/Paris.
6. **Mark exported.** After a good download the page asks "Marquer ces N commandes comme exportées ?". Marking takes the quantities out of the stock and counts the orders as sales (reports, client history). Only the orders that were in the file are marked. An admin can undo it on the order page (**Annuler l'export**).
7. Retype the orders in your invoicing software, then delete the file: it holds client personal data.

## Production

The production stack is `compose.prod.yml`: `caddy` (TLS from Let's Encrypt for `APP_DOMAIN`, security headers and CSP), `app`, `queue`, `scheduler`, `backup` (nightly pg_dump + restic) and `postgres` (internal network only). The public CI builds neutral images and pushes nothing: **you build and host your own images**, with your profile.

1. **Build the images** with your profile, from the repository root (the build fetches Passorde Connect from its public repository, at the version of `composer.lock`):

   ```bash
   docker build -f docker/php/Dockerfile --target production --build-arg APP_VERSION=$(git rev-parse --short HEAD) \
     --build-context profile=/path/to/private/profile --build-arg PASSORDE_PROFILE=/profile \
     -t <registry>/<image>:<tag> .
   docker build -f docker/backup/Dockerfile -t <registry>/<backup-image>:<tag> .
   ```

   `--build-arg PASSORDE_PROFILE=none` (without the profile context) builds the neutral product; the build stops when `PASSORDE_PROFILE` is not stated. `tools/prod-local/build.sh` runs the same two builds (`PASSORDE_PROFILE_DIR=<dir>` or `none`; `TAG`, `IMAGE`, `BACKUP_IMAGE` name the result). Tag with the short git SHA (with your profile in a repository of its own, add its commit: `<app sha>-<profile sha>`) and push both images to your registry. No registry: build on the server itself and deploy with `DEPLOY_PULL_OPTIONAL=1` (docs/RUNBOOK.md "CI, images and copying the deploy files").
2. **Bootstrap the server** once, as root (Debian or Ubuntu): `deploy/bootstrap.sh` installs Docker and the compose plugin, sets ufw (SSH, 80, 443), a fail2ban sshd jail and unattended security upgrades, creates the `deploy` user with your deploy key, creates `/srv/passorde` with `.env` copied from `.env.production.example`, and logs the deploy user in to your registry (`DEPLOY_PUBKEY`, `REGISTRY`, `REGISTRY_USER`, `REGISTRY_TOKEN`; `DRY_RUN=1` only prints). It does not harden sshd itself.
3. **Fill in `/srv/passorde/.env`** (every key of `.env.production.example` has a comment): `APP_DOMAIN` (the host name Caddy serves and certifies; its DNS record must point at the server), `APP_URL=https://<APP_DOMAIN>`, `ACME_EMAIL`, `APP_KEY`, `DB_PASSWORD`, `MAIL_*`, `ADMIN_*`, Turnstile keys, `BACKUP_*` and `RESTIC_PASSWORD`, and `PASSORDE_IMAGE` / `PASSORDE_BACKUP_IMAGE` (your image names, without the tag). Put your profile in `/srv/passorde/profile/profile.php` and keep `PASSORDE_PROFILE=/var/www/html/profile`. Secrets must not contain `$` (Compose reads the file too): `openssl rand -hex 32`.
4. **Copy the deploy files** from the same commit as the image and deploy:

   ```bash
   ssh deploy@<host> "install -d /srv/passorde/deploy /srv/passorde/docker/caddy/sites"
   scp compose.prod.yml deploy@<host>:/srv/passorde/
   scp docker/caddy/Caddyfile docker/caddy/security.caddy deploy@<host>:/srv/passorde/docker/caddy/
   scp deploy/deploy.sh deploy/rollback.sh deploy/backup.sh deploy/restore.sh deploy/bootstrap.sh deploy@<host>:/srv/passorde/deploy/
   ssh deploy@<host> "sh /srv/passorde/deploy/deploy.sh <tag>"
   ```

   `deploy.sh <tag>` refuses while `.env` does not state `PASSORDE_PROFILE`, pulls the images, checks the profile with the new image against the live database, then recreates the app containers (the app migrates on start), reloads Caddy, checks `/up` and appends to `releases.log`. When a check fails, nothing changes and the running version keeps running. `deploy/rollback.sh [<tag>]` goes back to the previous (or a given) tag.
5. **First start**: `docker compose exec app php artisan db:seed --force` (first admin and payment types), `docker compose exec backup backup.sh` (first backup), sign in, change the password, remove `ADMIN_PASSWORD` from `.env`.

Everything in more detail, with the `.env` checklist: docs/RUNBOOK.md "Production .env checklist", "Production stack", "Server bootstrap", "First deploy", "Deploying", "Rollback". Rehearse the whole stack on a laptop first: "Production stack on a laptop".

## Backups and restoring

- Every night at 02:30 Europe/Paris (`BACKUP_SCHEDULE`) the `backup` container runs `deploy/backup.sh`: a `pg_dump -Fc` of the database, plus the server `.env` and the installation profile, into a restic repository `s3:<BACKUP_S3_ENDPOINT>/<BACKUP_S3_BUCKET>/<BACKUP_NAME>`, encrypted with `RESTIC_PASSWORD`. It keeps 30 daily and 12 monthly snapshots (and every snapshot tagged `keep`), prunes and checks on Sundays, and records the result for the daily digest.
- Settings: `BACKUP_S3_ENDPOINT`, `BACKUP_S3_BUCKET`, `BACKUP_S3_REGION`, `BACKUP_S3_KEY`, `BACKUP_S3_SECRET`, `BACKUP_NAME` (default `passorde`: the restic host and the repository's folder in the bucket), `RESTIC_PASSWORD`. **Without `RESTIC_PASSWORD` the backups cannot be read: keep a copy away from the server.** Keep `APP_KEY` too: encrypted values in the database need it.
- By hand: `docker compose exec backup backup.sh`; list: `docker compose exec backup restic snapshots`.
- Restore: `docker compose exec backup restore.sh latest` (or a snapshot id, optionally followed by a new database name) restores into a **new** database, copies that night's `.env` and profile to `/srv/passorde/restore/<snapshot>/`, and prints the switch-over steps. It never touches the live database.

Details and a restore drill: docs/RUNBOOK.md "Backups" and "Restoring a backup".

## Optional Evoliz add-on

Passorde Connect (https://github.com/marinailsapps/passorde-connect) links Passorde to Evoliz: catalogue, stock, clients and payment methods synchronised from Evoliz, and each approved order sent as an Evoliz delivery note. It is compatible with Evoliz, not affiliated with Evoliz SAS / Visma.
It does nothing unless `EVOLIZ_ENABLED=true` **and** a licence key for the add-on is pasted with the Evoliz API keys on Administration → **Réglages Evoliz**. Without a valid licence the app simply runs standalone. A licence is obtained from the Passorde page of the site, https://apps.marinails.site/passorde (sales are not open yet).
`PASSORDE_WRITES_ENABLED=false` (the default) is a test mode: reads run, every write to Evoliz is refused before it is sent.
See docs/RUNBOOK.md "EVOLIZ_ENABLED and the Evoliz settings" and the add-on's own documentation.

## Checks

All must pass before every commit:

```bash
docker compose exec app php artisan test
docker compose exec app vendor/bin/pint --test
docker compose exec app vendor/bin/phpstan analyse
npm test                             # Vitest (resources/js/**/*.test.ts)
npm run lint
npm run build
```

On the host, run `npm ci` once before the npm commands (the `vite` container keeps its own `node_modules`). Tests run against the `passorde_test` database (created by `docker/postgres/init-test-db.sql` when the Postgres volume is first created), never the dev database.

## Licence

MIT. Copyright (c) 2026 les auteurs de Passorde — publié par Maryna SYOMKO, entrepreneur individuel. See [LICENSE](LICENSE).

The third-party components and their licences are listed in [THIRD-PARTY.md](THIRD-PARTY.md) (`php scripts/third-party.php` writes it from the lock files); Passorde Connect, which Composer installs with every installation, is under its own proprietary licence (`vendor/marinailsapps/passorde-connect/LICENSE.md` once installed, and `LICENSE.md` in https://github.com/marinailsapps/passorde-connect) and does nothing without a licence key.
