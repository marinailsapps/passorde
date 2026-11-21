# Runbook

Operational tasks for Passorde. Commands assume the development stack (`docker compose exec app …` in the repository). In production, run the same commands in the install directory (`/srv/passorde` below): its `.env` sets `COMPOSE_FILE=compose.prod.yml`, so plain `docker compose` is the production stack. `<host>` is your server, `orders.example.com` your public host name.

UI labels are quoted in French, as the app shows them.

## Contents

- Accounts: [First admin](#first-admin), [Users and passwords](#users-and-passwords), [Lost or stolen phone](#lost-or-stolen-phone)
- Configuration: [Ports and environment flags](#ports-and-environment-flags), [Installation profile](#installation-profile), [EVOLIZ_ENABLED and the Evoliz settings](#evoliz_enabled-and-the-evoliz-settings)
- Standalone mode: [Import and manual data](#standalone-mode-import-and-manual-data), [Export](#standalone-mode-export)
- Orders: [Approving orders](#approving-orders), [Phones, offline mode and the outbox](#phones-offline-mode-and-the-outbox)
- Evoliz add-on: [Synchronisation](#evoliz-synchronisation), [Sync errors](#where-sync-errors-show), [Delivery mirror](#delivery-mirror-sales-history), [The push to Evoliz](#the-push-to-evoliz), [Turning writes on](#turning-evoliz-writes-on), [Rehearsing with the mock](#rehearsing-with-the-evoliz-mock-dev-only)
- Reports: [Stock by category](#reports-stock-by-category), [Broken entries and gifts](#reports-broken-entries-and-gifts), [Sales](#reports-sales)
- Operations: [Daily digest and health checks](#daily-digest-and-health-checks), [SMS to clients](#sms-to-clients)
- Production: [.env checklist](#production-env-checklist), [Production stack](#production-stack), [Server bootstrap](#server-bootstrap), [CI, images and copying the deploy files](#ci-images-and-copying-the-deploy-files), [First deploy](#first-deploy), [Deploying](#deploying), [Rollback](#rollback), [Older names](#older-names), [Backups](#backups), [Restoring a backup](#restoring-a-backup)
- Development: [Passorde Connect from a local clone](#passorde-connect-from-a-local-clone), [After deploying code](#after-deploying-code), [Production stack on a laptop](#production-stack-on-a-laptop), [Viewport sweep](#viewport-sweep-dev-only)

## First admin

The first admin comes from `.env`:

```
ADMIN_LOGIN=admin
ADMIN_EMAIL=you@example.com      # optional; needed for password reset by email
ADMIN_PASSWORD=<12+ characters>
```

Then:

```bash
docker compose exec app php artisan db:seed --force
```

The seeder creates the admin only if no admin exists, and seeds the 13 payment types (those listed in the profile's `evoliz.enabled_paytypes` start enabled; by default Virement, Carte bancaire, Chèque, Espèces and Prélèvement). Running it again is safe: it never touches existing users or the payment type choices. Remove `ADMIN_PASSWORD` from `.env` once the admin has signed in and changed it.

On a database that already has an admin (a restored backup, a copy of another installation, a scratch copy for a test), the seeder creates none and says so ("An admin already exists; skipping."); there is no command that creates a user. Sign in with an existing admin instead: list them with `docker compose exec postgres psql -U <DB_USERNAME> -d <DB_DATABASE> -c "select login, is_active from users where role = 'admin'"`, then give one a new password with `docker compose exec app php artisan user:password <login>` ("Users and passwords" below). On a copy, that changes the password in the copy only; create the other accounts from Administration → **Utilisateurs** once signed in.

In development, `docker compose exec app php artisan migrate:fresh --seed` empties the database, migrates and seeds in one go.

## Users and passwords

- Administration → **Utilisateurs** → **Ajouter**: name, login (short handle, lowercase), optional email, phone, role (*Commercial* or *Administrateur*), password (type one or press **Générer**). The password is displayed once after saving; pass it on verbally or by SMS. It is never stored in clear or emailed.
- New passwords need 12+ characters and must not appear in a known data breach (checked against Have I Been Pwned without sending the password). Existing passwords keep working.
- To set a new password: open the user → **Définir un mot de passe**. The user is signed out of every device.
- Users can change their own password in **Mon compte**: every other device is signed out, the current one stays signed in.
- Users with an email can reset their own password from **Mot de passe oublié ?** (link valid 60 minutes); the reset signs them out of every device. The page gives the same answer for unknown, email-less and deactivated accounts, so users without email must ask an admin.
- Sessions end after 30 idle days, but login always sets "remember me", so users are signed back in silently until their sessions are ended (deactivation, new password).
- Users are never deleted. Untick **Compte actif** to block a user; they are signed out on their next request.
- If the only admin is locked out, from the server (asks the new password twice, reactivates the account and signs it out everywhere; `tinker` is not installed in production):

  ```bash
  docker compose exec app php artisan user:password <login or email>
  ```

## Lost or stolen phone

1. Administration → **Utilisateurs** → open the user → untick **Compte actif** → **Enregistrer**. This deletes every session of the user and invalidates "remember me" at once. (If the person keeps working on another phone, use **Définir un mot de passe** instead: it signs out every device the same way.)
2. The next time the lost phone reaches the server (a page, a mirror refresh or the outbox), the server refuses it: pages go to the login page, API calls answer 401. The phone then cleans up by itself:
   - **Nothing waiting to be sent**: it deletes the user's local copy (client directory with phones and addresses, catalogue, the user's orders) and shows the login page.
   - **Orders still waiting** (typed offline and never sent): it keeps them and the login page shows a red **Commandes non envoyées** box with the list. They are sent as soon as that user signs in again on this phone (the box keeps retrying every 2 minutes while it is open). **Effacer les données de ce téléphone** deletes them and every local copy on the phone, after a confirmation.
   - A phone in the middle of typing an order is not interrupted: the pill shows "Session expirée" and the next screen is the login page.
3. Until the phone reaches the server again (switched off, no network), its local copy stays readable offline. The phone's lock code is the real protection: ask the user whether it had one.
4. Orders that were still waiting on a lost phone are lost unless the phone is found: ask the sales person to enter them again from another phone.
5. If the phone is found: tick **Compte actif** again and set a new password. After the user signs in, its waiting orders are sent as usual.

## Ports and environment flags

| Key | Default | Meaning |
| --- | --- | --- |
| `APP_PORT` | 8093 | Host port of the app (development); `APP_URL=http://localhost:${APP_PORT}` follows it |
| `VITE_PORT` | 5193 | Vite dev server (development only), same port inside and outside the container |
| `DB_PORT` | 54393 | Postgres port in development, inside the compose network and on the host (production: 5432, internal only) |
| `PASSORDE_DEV_IMAGE` | `passorde-php:dev` | Development: the tag of the locally built app image (`compose.yml`); another one keeps a second stack's image apart (README "Quick start") |
| `TRUSTED_PROXIES` | empty | Proxies whose `X-Forwarded-*` headers are trusted; empty means Docker's default compose address pool. In production keep the value of `.env.production.example` (the subnet of the `edge` network in `compose.prod.yml`, `EDGE_SUBNET`). Without it every phone looks like Caddy's address: one shared login limit, wrong Turnstile address, `http://` links |
| `EVOLIZ_ENABLED` | empty | The Evoliz add-on, `true` or `false`; empty means `false` (see "EVOLIZ_ENABLED and the Evoliz settings") |
| `SYNC_SCHEDULE_ENABLED` | empty | Run the scheduled Evoliz syncs (only while Evoliz is active). Empty: on with `APP_ENV=production`, off anywhere else; `true`/`false` overrides. `.env.example` says `false` |
| `PASSORDE_WRITES_ENABLED` | false | Evoliz test mode while `false`: reads stay live, every Evoliz write is refused before it is sent, approvals fail with "Envoi Evoliz désactivé (mode test)" and every page shows the amber "Mode test" bar. See "Turning Evoliz writes on" |
| `SMS_ENABLED` | false | Text the client about the order (see "SMS to clients") |
| `OPS_DIGEST_TO` | empty | Extra recipients of the daily digest, besides the active admins with an email |
| `PASSORDE_PROFILE`, `PASSORDE_PROFILE_DIR` | empty | The installation profile (below) |

After changing a port: `docker compose up -d` (recreates the affected containers).

## Installation profile

`config/passorde.php` holds the product settings of an installation, with neutral defaults: the app name, logo and colours, the phones' storage prefix, the SMS texts, the unit name, free lines, the optional client field, the product categories and variants (with their stored values), the article classifier, the "new article" button, the usual VAT rates, the Evoliz defaults and the report labels. The server settings (switches, keys, ops limits) stay in `.env` and `config/server.php`.

The installing developer sets their own values in a private **profile**: a directory outside the repository with a `profile.php` that returns only the sections it changes, with the same keys. Sections merge key by key; a list (categories, variants…) replaces the default whole. `tests/Fixtures/profiles/fictional/profile.php` is a complete, fictional example.

- **Where the app reads it**: `PASSORDE_PROFILE` (default `profile/` in the app, gitignored; `none`: no profile, the neutral defaults). Development: set `PASSORDE_PROFILE_DIR` in `.env` to the host directory and run `docker compose up -d`; compose mounts it read-only as `profile/`. macOS may hold a mount of a folder under `~/Downloads`, `~/Documents` or `~/Desktop` until Docker Desktop is allowed to read it, so keep the profile elsewhere or allow it once.
- **Check it**: `php artisan passorde:check-profile` (`--path=<dir>` for another directory, `--no-database` to skip the database). It names unknown keys, missing or invalid entries, duplicates, groups that do not exist, and every category or variant stored in the database that the profile does not list (an article would turn "unknown"); it warns when an optional field switched off still has data. Exit 1 on any error. Run it after editing a profile, and on a copy of the production database before deploying a profile change.
- A profile that does not return an array stops the app at boot, and so does a `PASSORDE_PROFILE` naming a directory that does not exist or holds no `profile.php` (only `none`, or unset/empty with no `profile/profile.php`, mean "no profile"); `passorde:check-profile --path=<dir>` fails the same way. An unknown key is only reported by the check.
- `php artisan config:cache` reads the profile when it caches: run it again after a profile change.
- **Stored values** (`catalog.categories[].value`, `catalog.variants[].value`, `catalog.category_groups[].key`) are written in the database and sent to the phones: never change one once data uses it; change the label instead.
- **Production.** The profile is a private directory on the server (e.g. `/srv/passorde/profile/profile.php`, owned by the deploy user, readable by www-data), mounted read-only on app, queue, scheduler and backup by `compose.prod.yml` (`PASSORDE_PROFILE_DIR`, default `./profile` next to the compose file); the nightly backup saves it with the dump and `.env`. `.env` must state `PASSORDE_PROFILE=/var/www/html/profile`, or `none` for the neutral defaults: with `APP_ENV=production` an empty value stops the boot ("PASSORDE_PROFILE is not set…"), and so does a directory without `profile.php`. The image never carries a profile (`.dockerignore`), but its assets are built for one ("Brand and build" below): build the image with the same profile the server mounts. `deploy.sh` refuses to deploy while `.env` does not state it, and checks the profile with the new image against the live database before switching (the old version keeps running on a failure). Afterwards a profile that no longer fits the data is reported, never fatal: the daily digest lists it under "Profil d'installation", and `php artisan ops:check profile` checks by hand. The container healthchecks do not look at it. Before deploying a profile change, run `docker compose exec app php artisan passorde:check-profile --path=<new dir>` on the server; after changing it, `docker compose up -d --force-recreate app queue scheduler` (a plain `up -d` keeps the running containers and their cached configuration; the caches are rebuilt at start). A change of name, logo, colours, storage prefix or the phones' older-name maps (`aliases.client`, `article`, `totals`, `groupings`) needs a new image built with the new profile ("Brand and build").

What each section drives:

- **Categories, groups, variants** (`catalog.categories`, `category_groups`, `variants`): the pickers, the CSV import, the stock report's tabs and the catalogue export. A variant's behaviour follows its settings: `counts` (an article of it without a category is "à vérifier", a new article and the bulk category may have it, the sales report counts only its quantities), `group` (its own stock tab, no category), neither (left out of the stock report, e.g. shipping costs); the first counting variant is the default. `name` is the variant's word in forms, `label` the short one in reports. `category_name` is what a category is called in the texts and the export header. No categories: articles have none and nothing is "à vérifier".
- **Classifier** (`catalog.classifier`): the words that guess an article's category and variant after a sync or an import; `null` guesses nothing. The matching rules (truncated words, one typo, weak aliases last) are code; the words are data.
- **New article** (`catalog.new_article`): the order form's button, its title (`label`), VAT, reference and designation templates, and `complete_pattern` (what a completed designation looks like; until then the Evoliz push asks Evoliz for it again). `null`: no button, and `PUT /api/article-requests` answers 404.
- **Texts** (`texts` and the words): `lang/fr.json` is neutral; its sentences use `:unit`, `:units`, `:free`, `:category`, `:categories` and `:item`, filled from `units`, `free_lines.label`, `catalog.category_name` and `catalog.new_article.label` (`:Units` capitalised). Where that does not give the wording wanted, `texts` replaces a whole sentence by its English key (`passorde:check-profile` warns on a key `lang/fr.json` does not have). The phones get the same, also offline.
- **Optional client field** (`client.extra_field`): its label everywhere, its CSV column before `actif` in the clients template and import, the export column, and the client search when `searchable`. `null` hides it; stored values stay.
- **Articles CSV** (`catalog.csv`, `catalog.import_example`): the names of the category and variant columns (default `categorie`, `variante`, always accepted), and the example article of the downloadable template. `catalog.usual_vat`: rates the import accepts without a warning.
- **Evoliz** (`evoliz`, `free_lines.evoliz_suffix`; add-on only): payment term, recovery indemnity mention, BL comment, payment types enabled at the first seeding, the text after a free line's designation on the BL.
- **Name** (`brand.name`, `brand.short_name`): `short_name` is the header, every page title, the home-screen label, the login logo's text, `:brand` in the SMS and the daily mail's subject; `name` is the web manifest's name, the creator of every Excel file and the daily mail's sentence. Default "Passorde" for both.
- **Logo** (`brand.logo`): the name of a PNG file in the profile directory (e.g. `logo.png`): the login page's logo and the source of every icon (`scripts/make-icons.mjs`, a wide logo centred on a white square). `null`: the neutral `resources/images/logo.png`.
- **Colours** (`brand.theme_color`, `accent`, `accent_strong`, `accent_soft`, `#rrggbb`): the browser bar and manifest, the buttons and links, the header row of the Excel files (`accent_soft`).
- **SMS** (`messages.sms`, `messages.sms_with_bl`): `:brand`, `:number`, `:quantity`, `:units` / `:unit`, `:ttc` and `:bl` (Evoliz mode) are filled in.
- **Phones' storage** (`phone.storage_prefix`, default `passorde`): the phones keep their data in the IndexedDB databases `<prefix>` and `<prefix>-user-<id>`, send the outbox under the Web Lock `<prefix>-outbox` and remember the install banner in localStorage `<prefix>.installBanner.lastShown`. **Never change it once phones use the app**: a phone would open new, empty databases and the orders it has not sent yet would stay in the old ones, out of reach.
- **Reports** (`reports`): see the reports below.

**Brand and build.** The web manifest, the icons, the colours, the login logo, the header and page titles of the Vue bundle and the storage names of the phones and the service worker are fixed when the assets are built, and the CSP (`style-src 'self'`) allows no inline style, so the build reads the profile:

1. `php scripts/profile-assets.php` writes `build-profile/` (gitignored): `brand.json` (name, colours, storage prefix) and `logo.png` when the profile names one. It finds the profile exactly as the app does (`PASSORDE_PROFILE`, from the environment or `.env`); with `APP_ENV=production` it refuses an unstated one. Development: `docker compose exec app php scripts/profile-assets.php` (the profile the app runs on), then `npm run build`, or `docker compose restart vite` for the dev server; `npm run icons` after changing the logo. On a fresh clone nothing is needed: the `vite` service and a plain build make the missing `public/icons` themselves, from the neutral logo or `build-profile/logo.png`.
2. `npm run build` makes the icons and the bundle from `build-profile/`; without that directory it builds the neutral product ("Passorde", neutral logo and colours, prefix `passorde`), and with `APP_ENV=production` it refuses to. Delete `build-profile/` (or run step 1 with `PASSORDE_PROFILE=none`) to go back to neutral. The build stamps what it used into `public/build/profile.json` (the values and the logo's SHA-256).
3. `passorde:check-profile` (so `deploy.sh` before a switch, and `ops:check profile` with the digest after it) compares that stamp with the profile: assets built for another storage prefix are an **error**, another name, colour or logo a warning.

**Building the production image** is the installing developer's job, with their profile handed to the build as a named context; the public CI never sees a profile. `PASSORDE_PROFILE` must be stated (the build stops otherwise), so an image is never neutral by accident. The build fetches Passorde Connect from its public repository at the version `composer.lock` names; it needs network access to github.com, no credential.

```bash
docker build -f docker/php/Dockerfile --target production --build-arg APP_VERSION=$(git rev-parse --short HEAD) \
  --build-context profile=/path/to/private/profile --build-arg PASSORDE_PROFILE=/profile \
  -t <registry>/<image>:<tag> .
docker build -f docker/backup/Dockerfile -t <registry>/<backup-image>:<tag> .
```

`--build-arg PASSORDE_PROFILE=none` (without the profile context) builds the neutral product. `tools/prod-local/build.sh` runs both builds (`PASSORDE_PROFILE_DIR=<dir>` or `none`; `TAG`, `IMAGE`, `BACKUP_IMAGE`). Check the result with `docker run --rm --entrypoint cat <image> public/build/profile.json`.

- **Payment term (add-on).** Evoliz requires one on every BL and its id is the account's own (`GET /payterms`, e.g. "30 jours"): set `evoliz.payterm_id` in the profile. Without it, with Evoliz on, every approved order fails before anything is sent ("Aucun délai de paiement Evoliz n'est réglé…") and `passorde:check-profile` warns; once set, "Réessayer" sends them. `EVOLIZ_PAYTERM_ID` in `.env` overrides it (the mock: 37).
- **Tests** run with `PASSORDE_PROFILE=none`, as `phpunit.xml` sets it: `tests/TestCase.php` then lays the fictional `tests/Fixtures/profiles/pinned` over the defaults, so the pinned Evoliz request bodies keep their bytes. Do not set `PASSORDE_PROFILE` for a test run: a few tests read the profile loaded at boot (the build stamp check, the older-name routes) and fail with another one. The suite does not check your profile: `php artisan passorde:check-profile` does.

## EVOLIZ_ENABLED and the Evoliz settings

Passorde Connect is compatible with Evoliz and not affiliated with Evoliz SAS / Visma (the settings page says so in French: « Compatible avec Evoliz. Non affilié à Evoliz SAS / Visma. »).

**The one switch.** Only `EVOLIZ_ENABLED=true` switches the Evoliz add-on on. Empty or unset means off, even with Evoliz keys in `.env` or saved on the settings page and a valid licence: the app then runs standalone and never contacts Evoliz. A value other than true/false, 1/0, on/off or yes/no stops the app (a typo must not change where the orders go). After changing it: `docker compose up -d` (or `deploy/deploy.sh`) and `docker compose restart queue scheduler`.

**When Evoliz runs.** Switch on, company number, public and secret key all set, licence valid or in grace. Grace: with `expires_at` = E, the last paid day, the warning starts on E + 1 at 00:00 Paris time and synchronisation stops on E + 15 at 00:00, 14 full days. A lifetime key has no end date but a signed `updates_until` ("Mises à jour jusqu'au …" on "Réglages Evoliz"): a Passorde Connect release published after it makes the key invalid (go back to an earlier release or extend the updates), and the nightly `passorde:licence refresh` extends it when the key carries a token. Otherwise the app runs on its own, as in standalone mode (exports, import, catalogue edits on); admins get a banner saying why. Check in development: `php artisan tinker --execute="dump(App\Support\EvolizState::reason());"` (`null` = runs or switched off; `credentials`; `licence`).

**Réglages Evoliz** (Administration → Réglages Evoliz, `/admin/settings`, admins, shown only with the switch on): paste the licence key (one line, `pck1.…`) and/or the Evoliz keys, "Enregistrer", then "Tester la connexion" (a queued read; the answer appears within seconds while the queue runs). A value saved there wins over `.env`; leaving a field empty keeps what is saved; "Effacer la valeur enregistrée ici" goes back to `.env`. The values are encrypted with `APP_KEY`: **rotating `APP_KEY` makes them unreadable**, enter them again afterwards. Backups carry them encrypted (in the database), so a restore needs the same `APP_KEY`. Until a key is saved on the page, the licence file (`PASSORDE_LICENCE_PATH`) is read. The licence page of the package is `/passorde/licence` (admins only, `PASSORDE_ROUTE_MIDDLEWARE=web,auth,admin`); `php artisan passorde:licence show|install|refresh` and `php artisan passorde:status` work from the shell. With the switch off, Passorde Connect registers none of its commands, its licence page or its scheduled refresh (`config/passorde-connect.php` `enabled` follows `EVOLIZ_ENABLED`): nothing mentions a licence.

**A lapse and the renewal.** While the licence is expired or invalid nothing reaches Evoliz and nothing fails: approvals settle in the app ("Validée hors Evoliz"); export them as in standalone mode and enter them in Evoliz by hand. After the renewal: an exported order is never sent (no second BL); an order approved during the lapse and not exported shows "Envoyer à Evoliz" on its page; the first syncs bring Evoliz's catalogue, stock and the hand-made BLs back. During the lapse the sales report counts the app's delivered orders (sent before the lapse, or exported), not the BLs typed in Evoliz itself; those count again after the renewal. A failed order shows "Valider hors Evoliz" to settle it for the export, unless Evoliz may already have its BL (a creation was sent without a clear answer): that one stays failed, with the reason, until Evoliz is back. An order exported even once, then unmarked, is never sent after the renewal: enter it in Evoliz by hand if its BL is not there.

**First sync after standalone use** attaches Evoliz ids to the imported articles (by reference) and clients (by code) instead of creating second rows; `grep sync.adoption storage/logs/laravel.log` shows how many were adopted and how many were ambiguous (two local rows or two Evoliz records with one key: those got a new row; merge them by hand).

## Standalone mode: import and manual data

Without Evoliz (the default; also while the licence is not usable) the catalogue and the clients come from CSV files or are typed, and stock is managed in the app. Import, catalogue edits and the full client form are admin only and refused in Evoliz mode.

**Import** (Administration → Import, `/admin/import`). Choose *Catalogue* (articles) or *Clients* and the file, then **Aperçu**: created, updated, unchanged, warnings and errors by line, with nothing changed yet (the import runs in a transaction that is rolled back). **Appliquer cet import** sends the same file again and applies it. The file stays in the browser and the answer is plain JSON, so its personal data is not stored on the server; `activity_log` gets one entry with the counts only. **Télécharger le modèle** downloads a template with one fictional row (`/admin/import/templates/articles.csv`, `/admin/import/templates/clients.csv`; `;`, UTF-8 with BOM).

File rules: 5 MB at most; separator `;` or `,` (whichever appears more in the header line, `;` on a tie); UTF-8 with or without BOM, otherwise Windows-1252; an Excel workbook or a UTF-16 file is refused ("save it as CSV"); decimal comma or point (spaces between thousands accepted); header names in any case, accents and spaces ignored ("Code postal" is `code_postal`); booleans `oui/non`, `o/n`, `1/0`, `true/false`, `x` (yes), in any case. Unknown columns are ignored and listed in one warning. Only the key column is required in the file; without it the whole file is refused.

| File | Columns | Key | Required to create a row |
| --- | --- | --- | --- |
| Articles | `reference;designation;prix_ht;tva;stock;gestion_stock;actif;fournisseur;classification;categorie;variante;seuil` (the category and variant columns as `catalog.csv` names them) | `reference` | `designation`, `prix_ht`, `tva` |
| Clients | `code;societe;contact;adresse;code_postal;ville;telephone;mobile;email;actif`, with the profile's optional client field (its `csv` name) before `actif` when it is set | `code` | `societe` |

- An empty cell never erases: clear a value in the admin form. Rows absent from the file are left alone, and importing never deactivates (`actif = non` does), so a partial file is safe.
- Keys compare trimmed and case-insensitively, only among rows with no Evoliz id. A key twice in the file: the first row wins, later ones are errors. A row with an unusable value is rejected whole and the rest continue.
- Articles: `prix_ht` 0 to 99,999; `tva` 0 to 100 (a warning when `catalog.usual_vat` lists other rates); `stock` may be negative; `seuil` a whole number (reorder threshold). A new article gets stock management only when its `stock` cell is filled or `gestion_stock` says so. The category column holds a category of the profile and the variant column a variant, by stored value in any case and accents; an unknown value warns and the classifier decides. Without them the classifier guesses. Values set by hand are kept unless the file states them.
- Clients: a postcode that is not 5 digits and a mobile that is not a French mobile number give warnings (no SMS can go to it); an invalid email is ignored with a warning; emails are stored in lower case. A client code not found, with exactly one client typed in the app with no code and the same company and postcode, takes over that client (the preview names it). Several matches: a new client, with a warning to check for duplicates. Client changes go into the client's history with the source `import`.

**Evoliz exports** (list pages, `CSV (.csv)`) import as they are, beside the templates. Headers match in any case and with or without accents; a file can mix both names.

| Import | Evoliz column | Fills |
| --- | --- | --- |
| Articles | `Référence`, `Désignation`, `TVA`, `Fournisseur` | `reference`, `designation`, `tva`, `fournisseur` |
| Articles | `PU HT`, `Qté stockée`, `Article stocké` | `prix_ht`, `stock`, `gestion_stock` |
| Articles | `Code Classification vente` (`Classification vente` when the code is empty or absent) | `classification`: the classifier reads the code, as after a sync |
| Clients | `Code`, `Société / Nom`, `Code postal`, `Ville` | `code`, `societe`, `code_postal`, `ville` |
| Clients | `Adresse` + `Complément d'adresse` | `adresse`, joined with a comma |
| Clients | `Téléphone`, `Portable` | `telephone`, `mobile` |
| Both | `Désactivé` | the opposite of `actif` (`actif` wins if a file has both) |

Everything else is ignored and named in the warning, values never read, on purpose the banking columns and `Commentaires`. The exports have no category, variant, `seuil`, `contact`, `email` or optional client field, which stay empty without an error (the classifier guesses category and variant). An article with an empty `TVA` is refused on create.

**What to tell the operator.**

- The stock column must be the current stock.
- Articles with no stock management need `gestion_stock = non`.
- **Order of work for stock:** retype the exported orders into the invoicing software first, then export from it and import. A file older than the last retyping overwrites stock with a figure that misses those orders.
- Phones refresh on the next delta (at most 15 minutes while the app is visible), so a sales person may see the old price or stock until then.

**Manual data.**

- Catalogue (`/catalog`, Modifier): designation, unit price, stock, stock management, active. Each edit that changes something goes to `activity_log` (`article_edited`). The VAT rate and the reference change only through an import or the database.
- Clients (`/admin/clients/{id}`): every field including the code, recorded in the client's history (source `admin`). New values are checked like a sales person's correction; a value left as imported is not, so a foreign postcode does not block saving something else. A code must be unique among clients with no Evoliz id.
- A new article from the order form (when the profile has `catalog.new_article`): exists at once, stock 0. Complete its designation on `/catalog` before approving; approval gives the order's lines the catalogue's designation.
- Broken entries and gifts outside orders (Administration → **Casse / Cadeaux**): lower the local stock at once; the list shows the stock before and after. Deleting within 24 hours gives the quantity back. With no stock management nothing moves.

## Standalone mode: export

Approved orders wait under **Toutes les commandes → À exporter** (the home page shows "N à exporter"), and they keep their stock reserved until they are exported. The digest lists those approved for more than 2 days (`server.ops.export_max_days`).

1. **Take the file.** On "À exporter" every listed order is ticked; untick those that must wait, and load the next page first if there are more than 25. **CSV** or **Excel** (`/admin/orders/export.csv`, `/admin/orders/export.xlsx`). A file holds at most 150 orders; download again for the rest. Taking the file changes nothing (each download is recorded in the activity log).
2. **Mark them.** After a good download the page asks "Marquer ces N commandes comme exportées ?". If the download failed, it says so and nothing is marked. If the file looks wrong, answer Annuler and download again. Only the orders that were in the file are marked: an order approved in between stays to export.
3. **Retype** in the invoicing software. "Exportées" lists what was done, and the same buttons take a file again without moving anything.

**What marking does**, per order: the stock of each article falls by the order's quantities, free lines included (nothing moves for an article with no stock management); the reservation ends; the order counts as a sale in the sales report and the client's history, dated by the export; its free lines appear in the gifts of both reports; the sales person reads "Exportée". The activity log keeps the stock before and after.

**Undo** (admin): order page → **Annuler l'export**. The order goes back to "À exporter" and the quantities go back to stock. What was already retyped elsewhere stays there. A stock imported or edited since then is not reset: the undo adds the quantities to the current figure.

**Format.** One row per order line. CSV: `;`, UTF-8 with BOM, decimal comma, dates `dd/mm/yyyy hh:mm` Europe/Paris, for French Excel. Excel: numbers as numbers, frozen header with filters. Columns: N° commande, Date commande, Date validation, Vendeur, Code client, Client à créer (`oui` when the client has no code), Société, Contact, Adresse, Code postal, Ville, Téléphone, Mobile, Email, (the optional client field, under its label, when set), Mode de paiement, Commentaire commande, Ligne, Référence, Désignation, Remarque ligne, Offerte, Quantité, PU HT, TVA %, Total ligne HT, Total commande HT, TVA, TTC. The last three repeat on each line as a check after retyping. Client columns are the current values: orders keep no copy of the client.

**Formulas.** Client details and comments must not run in Excel. In the Excel file text cells are explicit strings. In the CSV a text starting with `=`, `+`, `-` or `@` gets a leading apostrophe, which you remove if that text is retyped; a plain phone number such as `+33 6 00 00 00 00` is left as it is.

**Personal data.** The files hold client names, addresses, phones and emails. The server keeps no copy; they stay on the operator's machine, so delete them once retyped and do not forward them.

**Reports.** The sales report sums the exported orders by export date. Offline the sales page says it is not available in this mode.

## Approving orders

**Toutes les commandes** (`/admin/orders`) lists every user's orders, pending first. "Approuver" (or bulk approval of the selected pending orders) approves; "Refuser" needs a reason, which the sales person sees.

- **Standalone**: an approved order is final; it waits for the export (above). With `SMS_ENABLED=true` the client is texted at approval.
- **Evoliz**: approval creates a **registered** delivery note (BL) in Evoliz through a queued job: it cannot be undone from the app. See "The push to Evoliz".

## Phones, offline mode and the outbox

The app keeps a copy of the clients, the catalogue (with stock), the payment types and the user's own orders on the phone (IndexedDB, one database per user). "Confirmer" always saves the order on the phone first and puts it in an **outbox**; the outbox sends it as soon as the server answers. Nothing has to be done by the sales person.

### The sync pill (top bar)

| Pill | Meaning |
| --- | --- |
| **En ligne · synchronisé** (green) | Server reachable, nothing waiting. |
| **En ligne · 2 en attente** (blue) | Orders are in the outbox and about to be sent (or waiting for their next retry). |
| **Envoi…** (blue) | The outbox is sending right now. |
| **Hors ligne · 2 en attente** (grey) | No network. The orders are safe on the phone and leave on their own when the network returns. |
| **1 non envoyée(s)** (red) | The server refused an order (validation error, order no longer editable…). It is listed at the top of Mes commandes with the reason. |
| **Session expirée — reconnectez-vous** (red) | The server answered 401/419. Sending is paused, nothing is lost. After signing in again, sending resumes. |

Tapping the pill shows the age of the local data ("Données : il y a 12 min"), the counts and a **Synchroniser maintenant** button. The data refreshes when the app opens, when the network comes back and every 15 minutes while the app is visible. Stock figures shown offline are labelled with that age.

Retries: 10 s, 30 s, 2 min, then every 5 minutes while online, forever. Opening the app, coming back online or switching back to it retries at once. On Android, Background Sync also sends while the app is closed; on iOS sending happens when the app is opened.

### An order stuck "Non envoyée"

1. Ask the sales person to open **Mes commandes**: the order is at the top with a red border and the server's message (for example that an article is no longer in the catalogue and its line must be removed).
2. If the order never reached the server (no number, "Brouillon local"), **Corriger la commande** opens it in the order form; fix it and press **Confirmer** again. It goes back into the outbox.
3. If the server already has the order in another state (approved meanwhile, so the edit came too late, error 409), the server's version is shown; tap **OK** to clear the notice. A new order (Dupliquer) is the way to change it.
4. An order rejected by the office stays rejected: the author uses **Dupliquer** to enter a corrected order.

### Orders that never arrive

If the pill keeps showing "en attente" with network: open the order on the phone. The grey box shows the number of attempts and the last error. "HTTP 500" means a server problem (see `storage/logs`); a network error means the phone cannot reach the server (captive Wi-Fi, VPN). Session problems show as "Session expirée".

### Forcing a full reload of the local data

**Mon compte → Se déconnecter**, then sign in again. Logging out deletes this user's local data; the next sign-in downloads everything again (with the Evoliz add-on, then the BL history in the background). If orders are still waiting, the app warns before deleting them: send them first (get network, wait for "synchronisé"). Logging out needs the network.

On a shared phone each user has a separate local database, and signing in as someone else removes the previous user's data unless they still have orders waiting (those stay until that user signs in again).

### Installing the app

Android (Chrome): a banner "Installer l'application" appears once a day until the app is installed; **Installer** adds it to the home screen. iPhone/iPad (Safari): the banner's **Comment ?** shows Partager → Sur l'écran d'accueil. The app works offline only after one online start with the user signed in.

New versions install in the background and take effect at the next start; meanwhile a "Nouvelle version disponible — Recharger" message offers to switch at once.

## Evoliz synchronisation

The schedule below runs only while Evoliz is active (switch on, keys set, licence usable) and the schedule is on. The schedule is on when `SYNC_SCHEDULE_ENABLED=true`, off when it is `false`, and when it is unset or empty it follows `APP_ENV`: on in production, off anywhere else. So a server needs only `EVOLIZ_ENABLED=true`, and a laptop whose `.env` points at a real Evoliz account never starts syncing by itself.

| Sync | Schedule (Europe/Paris) | Command |
| --- | --- | --- |
| Catalogue + stock | every 15 min 06:00–21:00, hourly otherwise | `php artisan sync:articles` |
| Clients | daily 02:00 | `php artisan sync:clients` |
| Payment methods | daily 02:30 | `php artisan sync:paytypes` |
| Delivery notes (BL) | every 15 min 06:00–21:00 for the last 7 days; daily 02:45 for the last 60 days | `php artisan sync:deliveries` (see "Delivery mirror") |
| Licence refresh | daily 03:15, with Evoliz switched on and a refreshable key | `php artisan passorde:licence refresh` |

The `evoliz:sync-*` names are aliases of the `sync:*` commands.

- Run one now from the UI: **Synchronisation** → *Synchroniser …*, or **Clients** / **Catalogue** → *Synchroniser* (admins only; sales people see the Catalogue read-only, with its **Excel** price list). The button queues a job for the `queue` container; the page refreshes itself until the run ends.
- Run one from the shell (inline, prints the result): `docker compose exec app php artisan sync:clients`. Add `--queue` to hand it to the queue worker instead.
- Only one sync of a kind runs at a time; a second one is recorded as **Ignorée**.
- The syncs are read-only on Evoliz. Rows missing from Evoliz are kept and flagged inactive; the optional client field and the enabled payment methods are never overwritten.

## Where sync errors show

- **Synchronisation** page: last 50 runs with status, duration, rows seen/changed and the error message. **Clients** and **Catalogue** show the last failure under the sync bar.
- Database: `select * from sync_runs order by id desc limit 20;`
- Logs: `storage/logs/laravel.log` (exceptions with stack traces; rate-limit warnings), and `docker compose logs queue scheduler`.
- A failed run is not retried; the next scheduled run tries again. Typical causes: Evoliz down (HTTP 5xx), key revoked (login failed with HTTP 401), rate limit (the client pauses when few requests remain and retries a 429 once), licence not usable.

## Delivery mirror (sales history)

With the add-on the app keeps a read-only copy of the Evoliz delivery notes with their lines (`evoliz_deliveries`, `evoliz_delivery_lines`), for the client history ("Historique" in the order form), "Copier une commande précédente" and the sales report. The sync only calls `GET /deliveries?period=custom&date_min&date_max` (items come inline) and `GET /users`; nothing is written to Evoliz.

- **Window**: by BL date (`documentdate`). Every window also reaches a month ahead (BLs are sometimes dated ahead). `sync:deliveries` takes `--days=N` (default 7) or `--since=Y-m-d [--until=Y-m-d]`; a window over 100 days runs as one sync run per calendar month. The button on **Synchronisation** queues the last 60 days.
- **What a run does**: upserts each BL by its Evoliz id; rewrites a BL's lines only when they changed; links the BL to the client, to catalogue articles and to the app order it came from (`external_document_number` = order number, or the order's stored BL id); records the Evoliz user. A BL of the window that Evoliz no longer returns (deleted in Evoliz) is soft-deleted, and restored if it comes back. A run that fails changes nothing for the BLs it did not reach; the next one catches up.
- **Status**: *Facturé* when the Evoliz status is 20 `invoice` or 24 `close` (or an invoice date is set); otherwise *Bon de livraison*.

### Backfill (once per server)

To bring the older history in, after the first deploy with the add-on, on the server:

```bash
cd /srv/passorde && docker compose exec -T app php artisan sync:deliveries --since=<first date, Y-m-d>
```

It prints one line per month (`Sync run #… <from> → <to> succeeded: N seen, M changed`) and a total. It stays below the Evoliz rate limit (the client pauses whenever few requests remain). A failed month can be re-run alone with `--since` and `--until`. Re-running the whole backfill is harmless (unchanged BLs are not written). Then check **Vendeurs Evoliz** (below) and a client's "Historique".

### Sellers (Administration → Vendeurs Evoliz)

Evoliz records which Evoliz user created a BL, not which sales person sold. The seller shown in the history and used by the sales report's seller filter is therefore:

1. the author of the app order the BL came from;
2. else the app user mapped to the BL's Evoliz user on **Vendeurs Evoliz** (one line per Evoliz user seen on BLs, named from `GET /users`, with its BL count);
3. else "Evoliz".

Mapping a user takes effect at once on the server; the phones pick it up with their next refresh.

### On the phones

The phones mirror the BL history too (`GET /api/deliveries` and `/api/delivery-lines`, docs/API.md "Sales history"), after the clients, the catalogue and the orders, so the order form is usable before the history has arrived. The first load is stored page by page and resumes where it stopped if the app is closed. Later refreshes only fetch what changed.

Offline, "Historique" and "Copier une commande précédente" show the BLs and the sales person's own orders not yet in Evoliz (other users' pending orders are only on the server). The sync pill's **Synchroniser maintenant** also continues an interrupted history load.

## The push to Evoliz

With the add-on active, approval queues a job per order. In test mode (`PASSORDE_WRITES_ENABLED=false`, amber bar under the top bar) the approval dialogs say so, the job only runs its lookups and the order ends "Échec de l'envoi" with "Envoi Evoliz désactivé (mode test)". Nothing is created in Evoliz, neither client nor BL. Once writes are enabled, "Réessayer" sends such an order for real.

What the job does for each order:

1. **Client.** If the client was typed in the app, it looks the derived client code up in Evoliz (a numbered variant when the code is taken by someone else) and otherwise creates the client. It then creates a contact when there is a name or an email. If Evoliz refuses the contact (for example a malformed email), the BL is still created and the order shows an "Avertissement Evoliz" with the reason: add the contact by hand in Evoliz.
   **Client correction.** When the sales person used "Modifier les coordonnées", the order page shows "Modification de la fiche client" (old → new). Untick "Appliquer à la fiche client Evoliz lors de la validation" to send the order without it. Otherwise the job sends it before the BL (`PATCH /clients/{id}`, `PATCH /contacts-clients/{id}`), so the BL already carries the new address, and the app's client takes Evoliz's values. Evoliz may answer 500 to a client PATCH while applying it: the job reads the client back before retrying, so a 500 followed by success is normal. If Evoliz refuses the correction, the BL is still created, the order shows "Fiche client non mise à jour dans Evoliz : …", and Administration → Clients → the client → "Historique des modifications" has a "Réessayer" button (or fix it by hand in Evoliz). That history also lists what the nightly sync saw change in Evoliz.
2. **Lookup.** It searches the client's BLs for one whose external number is the order number, so a retry never creates a second BL.
3. **Create.** `POST /deliveries` with the sales price on every line, and free lines at 0 € (their designation followed by the profile's `free_lines.evoliz_suffix`).
4. **Finish.** The order becomes "Envoyée · BL-…". If Evoliz's totals differ from the app's, the order stays sent and its detail page shows "Écart de total avec Evoliz".

**Client saved without an order.** Step 1 of a new order has "Enregistrer sans commande" (online only): the client is saved and a job creates it and its contact in Evoliz straight away, with no operator check and no BL. A refusal stays on the client: Administration → Clients → the client shows it with "Envoyer à Evoliz" (the digest lists it too). Corrections after that go through "Modifier les coordonnées".

Every request and response is stored by Passorde Connect in the `passorde_requests` table, with its subject (`order`, `client`, `article_request`, `stock_adjustment`).

### Reading a failed push

- The order shows "Échec de l'envoi" (sales people still see "Validée · envoi en cours"). The detail page has the red "Échec de l'envoi vers Evoliz" block with Evoliz's message, and the History lists every attempt. The home page shows a red "n en échec" badge.
- `HTTP 400 — field : message` means Evoliz refused the data (for example an article disabled in Evoliz, or a client field). Fix the data in Evoliz or in the app, then press "Réessayer".
- "Evoliz a refusé la connexion … Vérifiez les clés API" means the API keys are wrong or were revoked.
- "Evoliz ne répond pas" means Evoliz was down or slow: the job already retried several times with increasing waits.
- "Envoi Evoliz désactivé (mode test)" means `PASSORDE_WRITES_ENABLED` is `false`: nothing was sent.
- For details:

  ```sql
  select kind, method, path, http_status, status, attempts, last_error, created_at
  from passorde_requests
  where subject_type = 'order' and subject_id = (select id from orders where number = '<order number>')
  order by id;
  ```

  `payload` and `response` hold the exact bodies.

### Retrying

Press "Réessayer" on the order (admin only). It is always safe:

- If the BL id was stored, the job only reads that BL back and marks the order sent.
- Otherwise it first looks the BL up by order number and only creates one if none exists.
- 429 (rate limit) and 5xx answers are retried automatically inside the job.

### A BL exists in Evoliz but the app shows "Échec"

This happens, for example, after a timeout once Evoliz had already created the BL. Press "Réessayer": the lookup finds the BL by its external number (the order number) and the order becomes "Envoyée" without a second BL. Do not create the BL by hand in Evoliz, and do not change its external number.

### Deleting a wrong BL

The API cannot delete a registered BL, and the app never updates, invoices or deletes one. In Evoliz: **Ventes → Bons de livraison**, open the BL, then **Supprimer** (or cancel it if Evoliz no longer allows deletion). Do not invoice it. Check the stock of its articles afterwards: deleting the BL should put the quantities back. The app order stays "Envoyée"; note it in the comment if needed.

Delete test BLs created against a live account: otherwise an app order with the same number for the same client would be matched to that BL and marked sent without a new one.

### Broken entries and gifts with Evoliz

Each entry of **Casse / Cadeaux** also adjusts the article's stock in Evoliz (a queued job): it reads `stocked_quantity`, writes it back minus the quantity (`PATCH /articles/{id}`), and reads it again. Deleting the entry (within 24 hours) puts the quantity back the same way; the deleted entry stays listed ("Supprimée : stock à remettre") until Evoliz has it back. The entry shows its Evoliz status:

- **Evoliz : stock mis à jour** with the stock before → after. If Evoliz shows another number after the update (a BL moved the stock in the same second), the entry says so and the activity log has `evoliz_stock_mismatch`: check the article's stock in Evoliz.
- **Evoliz : échec** with Evoliz's answer, and **Réessayer**. A retry after a timeout first checks whether the stock already shows the planned result, so the quantity is never applied twice. Failed entries are listed in the morning digest.
- **Evoliz : non envoyé (mode test)** while `PASSORDE_WRITES_ENABLED=false`; `php artisan evoliz:push-stock-adjustments` sends them later (below).
- **Evoliz : sans gestion de stock**: the article has no stock management in Evoliz; nothing to adjust.

## Turning Evoliz writes on

Run in test mode (`PASSORDE_WRITES_ENABLED=false`) until reads, approvals and the BL contents look right, then:

1. **Clean up the test period.** In **Toutes les commandes** → **Échec**, reject every test order (reason "Test"): once writes are enabled, "Réessayer" on such an order creates a real BL. Keep only real orders you intend to send.
2. **Flip the flag.** Set `PASSORDE_WRITES_ENABLED=true` in `.env`, then `docker compose up -d --force-recreate app queue scheduler`. The queue worker keeps the old value until it restarts.
3. **Verify.** `docker compose exec app php artisan config:show passorde-connect.writes_enabled` prints `true`, and after a page reload the amber "Mode test" bar is gone.
4. **One real order first.** Approve a single small real order and watch it become "Envoyée · BL-…" within a minute. Open the BL in Evoliz: client, lines and prices, free lines at 0 €, VAT per article, payment term and type, and the stock of its articles. If anything is wrong, set the flag back to `false`, recreate the containers, delete the BL by hand in Evoliz and investigate before approving anything else.
5. Then approve the remaining real orders, and "Réessayer" the real ones that failed in test mode, one at a time.
6. **Broken entries and gifts recorded in test mode** are not in the Evoliz stock yet. Delete the test entries first (within 24 hours of recording), then `docker compose exec app php artisan evoliz:push-stock-adjustments --dry-run` lists the real ones, and the same command without `--dry-run` sends them.

## Rehearsing with the Evoliz mock (dev only)

The Evoliz mock is Passorde Connect's own simulator (`vendor/marinailsapps/passorde-connect/connectors/evoliz/mock`, its `docs/mock.md`): a fictional company 1000 with fictional clients, articles, BLs around today, payment types 41 Virement and 42 Chèque, payment terms 31 and 37, users 91 and 92. Its data are rebuilt from the date, with no randomness, and stored HTML-encoded like Evoliz. The OVH SMS mock (`docker/ovh-mock`) prints each SMS in its log. Both run under the compose profile `mock`:

```bash
docker compose --profile mock up -d evoliz-mock ovh-mock
```

**Use a throwaway database.** The syncs flag every article and client the mock does not have as inactive, and the delivery sync soft-deletes the BLs of its window: never point the mock at a dev database you want to keep. Create one (`create database passorde_mock` in the dev Postgres), then in `.env`:

```dotenv
DB_DATABASE=passorde_mock
EVOLIZ_ENABLED=true
PASSORDE_EVOLIZ_BASE_URL=http://evoliz-mock:8080
PASSORDE_EVOLIZ_COMPANY_ID=1000
PASSORDE_EVOLIZ_PUBLIC_KEY=mock-public-key
PASSORDE_EVOLIZ_SECRET_KEY=mock-secret-value
PASSORDE_WRITES_ENABLED=true
# The mock's "30 jours".
EVOLIZ_PAYTERM_ID=37
SMS_ENABLED=true
OVH_ENDPOINT=http://ovh-mock:8080/ovh/1.0
```

The mock accepts only those keys. **A licence is required as for real Evoliz**: Passorde Connect refuses every Evoliz call, mock included, without a valid key covering `evoliz` pasted on "Réglages Evoliz"; without one the app stays standalone. There is no development signing. Then:

```bash
docker compose exec app php artisan migrate --seed
docker compose restart queue
docker compose exec app php artisan passorde:ping
docker compose exec app php artisan passorde:sync all
docker compose exec app php artisan sync:deliveries --days=200
```

`passorde:sync all` runs Passorde's readers straight into the app's tables (no `sync_runs` row); `sync:articles|clients|paytypes|deliveries` and the buttons of **Synchronisation** do the same through a `sync_runs` row, as in production. The default delivery window (7 days back) sees few of the mock's BLs, hence `--days=200`. In **Synchronisation**, tick only "Virement" #41 and "Chèque" #42 (the seeded payment types do not exist in the mock), or leave the order's payment type "Non précisé". Approvals then produce `BL-MOCK-…` numbers, `sync:deliveries` brings them into the history, and nothing reaches Evoliz. The mock's clients have no mobile: give one a `06…` number (client correction, sent to the mock too) to see an SMS in `docker compose logs ovh-mock`.

Reset and inspect the mock (its state survives a restart, not a `reset`):

```bash
docker compose exec app curl -s -X POST http://evoliz-mock:8080/__mock/reset
docker compose exec app curl -s http://evoliz-mock:8080/__mock/state
```

`state` lists the last calls and `forbidden_hits` (invoicing paths, BL updates or deletes), which must stay empty. Failures to rehearse (`docs/mock.md` of the package): `POST /__mock/switches` with `{"read_only": true}` (every write refused 403), `{"delivery_post_500": true}` (the BL is created but the answer is lost: the retry finds it, one BL), `{"client_patch_500": true}`, `{"rate_limit_remaining": 0}` (429). A wrong `PASSORDE_EVOLIZ_SECRET_KEY` rehearses a refused login. For an SMS failure, prefix `OVH_CONSUMER_KEY` with `BROKEN`.

Afterwards put `.env` back (`DB_DATABASE`, `EVOLIZ_ENABLED`, `PASSORDE_EVOLIZ_*` empty, `PASSORDE_WRITES_ENABLED=false`, no `EVOLIZ_PAYTERM_ID`, `SMS_ENABLED=false`), restart the queue, `docker compose --profile mock stop evoliz-mock ovh-mock`, and drop `passorde_mock`. Never `PASSORDE_WRITES_ENABLED=true` with a real Evoliz URL in development.

## Reports: stock by category

The stock report (`/reports/stock`, every role, online only; its title is the profile's `reports.stock_title`) lists the articles per category group, one tab per `catalog.category_groups` entry in order, then the tabs of `reports.stock_tabs` (unclassified, broken, gifts, material gifts; `all` is the single list when the profile has no categories). "Excel" downloads one sheet per tab; the sheets carry every article, the screen hides the ones without stock by default.

- **Stock** is the catalogue's (with the add-on: the last catalogue sync, whose age the page shows); **Réservé** the app orders pending, approved (not yet exported, in standalone mode) or failed; the broken and gift columns the journal entries of the chosen period (default: 1 January to today; the period changes nothing else). **À terme** = stock − reserved − broken − gifts; amber at or under the threshold, red at or under zero. **À commander** = threshold − à terme when positive.
- Gifts inside an order are the order's free lines, counted once the order is delivered (sent to Evoliz, or exported); they are listed in the gift tabs, not subtracted again.
- With the add-on, broken entries and gifts outside orders are subtracted from the Evoliz stock as soon as they are recorded, so the report's broken and gift columns show only what Evoliz does not have yet (entries recorded in test mode, pending, or failed). Normally both columns are empty.

### Category and variant of each article (Administration → Attributs articles)

The app guesses each article's category and variant from its reference and designation with the profile's classifier (automatic), takes them from an import (from the file), or keeps what an admin set (set by hand, never overwritten). New articles are classified automatically; an article whose designation changes is classified again unless it was set by hand or from the file.

**From a workbook, once**: `php artisan reports:seed-attributes --file=<workbook.xlsx>` classifies every active article, then takes category and variant from every sheet whose header row has the columns `Référence` and `Catégorie` (and optionally `Variante`; other names with `--reference-column`, `--category-column`, `--variant-column`), matching references case- and space-insensitively. Values set by hand are never touched; running it again gives the same result.

**The "à vérifier" list**: Attributs articles → "À vérifier seulement" (the home badge counts them): articles of a counting variant with no category or two possible ones. They sit in the report's unclassified tab until an admin picks the category (one by one, or tick several and apply one category to all).

**Thresholds**: Administration → **Seuils par catégorie** (the word follows `catalog.category_name`) sets the quantity to keep per article for a whole category; a threshold typed on one article (Attributs articles, last field) wins over its category's; empty means none. The category threshold applies to **active** articles only, so old articles nobody buys any more never show up in "À commander"; to stop recommending an active article, give it a threshold of 0.

An article is **active** when at least one of these holds: it has stock; it was ordered in the app (order not rejected) or delivered on a BL in the last 12 months; a broken entry or a gift outside an order was recorded for it in the last 12 months; a threshold was typed on the article itself. In the report, "Masquer les articles inactifs" (ticked at first) hides the others; the unclassified tab and the Excel file always show every article (column "Actif").

## Reports: broken entries and gifts

The write-offs report (`/reports/write-offs`, every role, online only; title `reports.write_offs_title`, labels `reports.write_off_labels`) lists, 20 per page and newest first, every broken entry and gift of a period: the journal's entries (by their date; deleted entries left out) and the free lines of delivered orders (by delivery date, Paris time). Period: Cette année / L'année dernière / Ce trimestre / Trimestre précédent, or any dates (default: 1 January to today); type: the three ticked by default, at least one stays ticked. Each row: date, type, article, quantity, client and order number, note, who recorded it and when, and with the add-on the Evoliz stock status. "Excel" downloads the same period, types and rows on one sheet (real dates and numbers, totals row, frozen header, filters).

The journal itself is Administration → **Casse / Cadeaux**: article, quantity, date, client for a gift, note. The author or an admin can delete an entry for 24 hours; after that it stays (activity log `stock_adjustment_added` / `_deleted`).

## Reports: sales

The sales report ("Ventes", `/reports/sales`, every role) sums what was delivered over a period: with the add-on, the Evoliz delivery notes of the mirror (**it never counts app orders that are not in Evoliz yet**: a sale counts once its BL exists); standalone, the exported orders by export date.

- **Filters**: Semaine en cours, Semaine dernière, Mois en cours (default), Trimestre en cours, Année en cours, or any dates; with the add-on *Bons de livraison* (not invoiced) and *Facturés*, both ticked by default; *Vendeur*.
- **Cards**: number of orders (BLs); quantities of lines whose article is of a counting variant (free-form lines without an article are left out), of which free (at 0 €); HT, TVA, TTC (with the add-on, the BL totals as Evoliz computed them).
- **Groupings** (one sortable table, tap a row for its orders): per category, per article, per client, per month, per département (first two digits of the client's postcode; "Inconnu" without one) and per variant. Client, month and département rows are whole orders (their HT/TTC add up to the cards). Category, article and variant split an order by line, so an order with two categories counts in both rows. Lines of articles without a category are in the unclassified row, other articles in their own rows, free-form lines in "Lignes libres".
- **Excel** (online only): a summary sheet (filters, totals as formulas over the detail), one sheet per grouping with a TOTAL row of formulas, and a detail sheet with every line.
- **Offline** (add-on): the page is computed on the phone from its copy of the BLs, with the same rules (`resources/js/lib/salesReport.ts`, pinned against the server by `tests/Fixtures/sales-report-cases.json`); a note says so, and that figures may be incomplete while the first history load is still running. The Excel button then says a connection is needed.

## Daily digest and health checks

- `ops:daily-digest` runs at 07:30 Europe/Paris and emails the active admins with an email (plus `OPS_DIGEST_TO`) **only when something is wrong**: orders whose push failed, Evoliz syncs failed in the last 24 hours, failed queue jobs (`failed_jobs`), orders pending for more than 2 days, approved orders not exported for more than 2 days (standalone), a failed or missing backup (more than 26 hours; always in production, elsewhere only where `BACKUP_S3_BUCKET` is set), a queue or scheduler heartbeat older than 10 minutes, client SMS that failed in the last 24 hours, an installation profile that no longer fits the data, and with the add-on the licence state, missing or unreadable Evoliz keys, failed stock adjustments and clients Evoliz refused (also: Evoliz switched off while the database holds a catalogue synced from Evoliz). `--print` shows the report without emailing.
- `ops:heartbeat` runs every minute: it records the scheduler's heartbeat and queues a job that records the queue worker's. `ops:check [queue|scheduler|profile]` exits non-zero when a heartbeat is older than 10 minutes (or the profile has an error); `compose.prod.yml` uses it as the healthcheck of the queue and scheduler containers (`docker compose ps` shows "unhealthy"). If both stay unhealthy and `docker compose exec scheduler php artisan schedule:list` shows "Has Mutex" on `ops:heartbeat`, a killed run left its lock: `docker compose exec app php artisan schedule:clear-cache` (locks also expire by themselves within 5 to 120 minutes).
- Failed queue jobs: `docker compose exec app php artisan queue:failed`, then `queue:retry <id>` or `queue:forget <id>` (pushes and SMS have their own retry buttons; prefer those).
- Uptime: point an external monitor at `https://orders.example.com/up`.

## SMS to clients

With `SMS_ENABLED=true` the client gets an SMS from the OVH SMS service `OVH_SMS_SERVICE`: in standalone mode when the order is approved (`messages.sms`), with the add-on when the order becomes "Envoyée", the BL existing (`messages.sms_with_bl`). The texts come from the profile.

- Only to a French mobile (06/07, also written +33 6…, 0033 6…): the client's mobile field first, then the phone. Anything else (landline, foreign number, none) is not texted; the order shows "Pas de SMS : le client n'avait pas de numéro de mobile."
- Sender: `OVH_SMS_SENDER` when set (only once OVH validated the name), otherwise OVH's short number (clients can reply). No STOP mention (transactional message).
- "n°" and "€" make it a Unicode SMS: about 120 characters is 2 SMS credits per order. Credits: OVH manager → Télécom → SMS.
- One SMS per order, sent once (`SendOrderSmsJob`, unique per order). Every attempt is in `sms_log` (phone, text, OVH job id, status, error). A failure never changes the order. Admins see the status on the order page ("SMS au client") with **Renvoyer**; the approval dialogs say whether an SMS will go out.
- Failures show in the daily digest. Typical errors: `HTTP 403 — Invalid signature` or `This credential does not exist` (keys wrong or revoked: create new ones and update `OVH_*`), `OVH a refusé le numéro …`, no credits left.
- Test the keys without the app: `tools/ovh-sms-test.sh +336XXXXXXXX "Test"` (from a machine with the `OVH_*` values in `.env`); never from a test suite.
- Development and rehearsals: `OVH_ENDPOINT=http://ovh-mock:8080/ovh/1.0` makes the OVH mock (`docker/ovh-mock`, compose profile `mock`) print each SMS in `docker compose logs ovh-mock` instead of sending it.

## Production .env checklist

`/srv/passorde/.env` starts as a copy of `.env.production.example` (`bootstrap.sh` does it), which lists the keys with a comment per group. Docker Compose reads it too (it sets `COMPOSE_FILE=compose.prod.yml`), so secrets must not contain `$`: generate them with `openssl rand -hex 32`. Check every line before the first deploy and after editing it, then `docker compose up -d --force-recreate app queue scheduler backup` so the containers read it.

| Key | Production value | Why |
| --- | --- | --- |
| `COMPOSE_FILE`, `COMPOSE_PROJECT_NAME` | `compose.prod.yml`, `passorde` | Plain `docker compose …` in `/srv/passorde` uses the production stack |
| `PASSORDE_TAG` | managed by `deploy/deploy.sh` and `rollback.sh` | The image tag running now; never edit by hand |
| `PASSORDE_IMAGE`, `PASSORDE_BACKUP_IMAGE` | your app and backup image names, without tag | Not in `.env.production.example`; the compose defaults name images that are not published |
| `APP_DOMAIN` | `orders.example.com` | The host name Caddy serves and gets a Let's Encrypt certificate for; its DNS record points at the server |
| `ACME_EMAIL` | an address that is read | Let's Encrypt warns there if renewals fail |
| `PASSORDE_PROFILE` | `/var/www/html/profile` (or `none`) | Required: production refuses to boot, and `deploy.sh` refuses to deploy, while it is empty |
| `PASSORDE_PROFILE_DIR` | empty (`./profile`, i.e. `/srv/passorde/profile`) or another host directory | Holds the private `profile.php`, mounted read-only on app, queue, scheduler and backup |
| `APP_ENV` | `production` | https URLs, secure cookies, production error pages |
| `APP_DEBUG` | `false` | `true` shows stack traces, queries and environment values to anyone |
| `APP_URL` | `https://orders.example.com` | Links in reset emails and the digest; `deploy.sh` checks `APP_URL/up` |
| `APP_KEY` | generated once (`echo "base64:$(openssl rand -base64 32)"`), never changed | Encrypts sessions, cookies and the values saved on "Réglages Evoliz"; changing it signs everyone out. Keep a copy outside the server |
| `LOG_STACK`, `LOG_LEVEL` | `daily,stderr`, `warning` | One file per day in the storage volume (14 days) plus the container log |
| `TURNSTILE_ENABLED` | `true`, with `TURNSTILE_SITE_KEY` and `TURNSTILE_SECRET_KEY` for your host name | Bot protection on login and reset. It fails closed: if Cloudflare is unreachable nobody can log in; set `false` temporarily during an outage |
| `SESSION_DRIVER` | `database` | Sessions must be deletable (deactivation, new password) |
| `SESSION_LIFETIME` | `43200` | 30 idle days; remember-me signs users back in |
| `SESSION_ENCRYPT` | `true` | Session payloads encrypted at rest |
| `SESSION_SECURE_COOKIE` | unset (follows `APP_ENV=production`) or `true` | Cookies only over https |
| `SESSION_DOMAIN` | `null` | Cookies for the app host only |
| `EDGE_SUBNET`, `TRUSTED_PROXIES` | the values of `.env.production.example` (`172.30.93.0/24`) | Caddy's network in `compose.prod.yml` and the proxies trusted on it: real client addresses for the login limit and Turnstile, https detection. Change them only for a second installation on the host ("A second installation on the same host"); a changed `EDGE_SUBNET` takes effect after `docker compose down` (volumes kept) and a deploy |
| `DB_HOST`, `DB_PORT` | `postgres`, `5432` | The postgres container on the internal network (no published port) |
| `DB_PASSWORD` | long random value (required) | `compose.prod.yml` refuses to start without it |
| `MAIL_*` | your SMTP account | Password reset emails and the daily digest |
| `ADMIN_*` | the first admin; remove `ADMIN_PASSWORD` once changed | The seeder only needs it once |
| `EVOLIZ_ENABLED` | `false` (standalone) or `true` (add-on) | See "EVOLIZ_ENABLED and the Evoliz settings" |
| `SYNC_SCHEDULE_ENABLED` | empty (on in production) or `true` | Scheduled syncs while Evoliz is active; `false` stops them |
| `PASSORDE_WRITES_ENABLED` | `false` until you turn writes on, then `true` | Test mode refuses every Evoliz write |
| `PASSORDE_EVOLIZ_*` | the app's own Evoliz API key | Give the app a dedicated key |
| `SMS_ENABLED`, `OVH_*` | `false` until tested; the OVH keys and `OVH_SMS_SERVICE`; `OVH_SMS_SENDER` once OVH validated it | See "SMS to clients" |
| `OPS_DIGEST_TO` | optional | Extra digest recipients |
| `BACKUP_S3_*`, `BACKUP_NAME`, `RESTIC_PASSWORD` | your S3 bucket and credentials; a long random restic password | Nightly backups. Without `RESTIC_PASSWORD` the backups cannot be read: keep it outside the server too |

### Editing .env on the server

1. Edit `/srv/passorde/.env` in place. Keep one line per key: the last duplicate wins. No quotes, no trailing spaces. A feature with a flag needs the flag too: pasting the Turnstile keys does nothing while `TURNSTILE_ENABLED=false`.
2. Owner and mode must stay `deploy:www-data 640` (the app runs as www-data and reads the file through a read-only mount): `stat -c '%U:%G %a' .env`; if an editor replaced the file, `chown deploy:www-data .env && chmod 640 .env`.
3. Apply it: `docker compose exec app php artisan optimize && docker compose restart app queue scheduler`. Compose mounts the file, so a restart is enough for Laravel's settings; keys Compose itself interpolates (image names, `APP_DOMAIN`, `DB_PASSWORD`, `BACKUP_*`) need `docker compose up -d --force-recreate …`.
4. Verify what the app uses, masking secrets before sharing the output, for example: `docker compose exec app php artisan config:show services.turnstile | sed -E 's/(secret_key[ .]*).*/\1<masked>/'`.

## Production stack

`compose.prod.yml`, on the server in `/srv/passorde`:

| Service | What | Notes |
| --- | --- | --- |
| `caddy` | caddy:2, ports 80 and 443 (tcp + udp) | Let's Encrypt for `APP_DOMAIN`, security headers and CSP (`docker/caddy/security.caddy`), `/sw.js` and the manifest never cached |
| `app` | `${PASSORDE_IMAGE}:${PASSORDE_TAG}` (nginx + php-fpm) | Runs the migrations and caches config/routes/views on start; healthy when `/up` answers |
| `queue` | same image, `queue:work --tries=1 --max-time=3600` | Healthy while `ops:check queue` passes (heartbeat job run within 10 minutes) |
| `scheduler` | same image, `schedule:work` | Syncs (while Evoliz is active), heartbeat every minute, digest at 07:30; healthy while `ops:check scheduler` passes |
| `backup` | `${PASSORDE_BACKUP_IMAGE}:${PASSORDE_TAG}` | pg_dump + restic at 02:30 Europe/Paris |
| `postgres` | postgres:16 | Internal network only, no published port; data in the `postgres-data` volume |

Volumes: `postgres-data`, `storage` (Laravel's storage: logs, file cache, locks, the licence file; shared by app, queue and scheduler), `caddy-data` (certificates), `caddy-config`, `restic-cache`. Logs of every container: `docker compose logs -f <service>` (json-file, 5 × 10 MB per container); Laravel's own log: `docker compose exec app ls storage/logs`. Only Caddy publishes ports: Docker's port rules bypass ufw.

The `Caddyfile` serves one host name. For another layout (a `www.` redirect, more names) use your own file named by `CADDYFILE` in `.env`, or extra sites in `docker/caddy/sites/*.caddy` (imported by the Caddyfile, never touched by deploys: `docker/caddy/sites/README.md`).

Layout of `/srv/passorde`: `.env` (owner deploy, group www-data, mode 640: the app containers run as www-data, uid 33; written once, edited by hand), `profile/profile.php`, `compose.prod.yml`, `docker/caddy/{Caddyfile,security.caddy}`, `deploy/*.sh` (copied before every deploy), `docker/caddy/sites/`, `releases.log` (one line per deploy), `restore/` (restored `.env` files and profiles).

Everyday commands:

```bash
docker compose ps                                   # health of every service
docker compose logs --since 1h app queue scheduler
docker compose exec app php artisan ops:check       # queue + scheduler heartbeats
docker compose exec app php artisan ops:daily-digest --print
docker compose exec app php artisan user:password <login>
```

### A second installation on the same host

A second stack on the same server (a test copy beside production, say) gets its own install directory (e.g. `/srv/passorde-test`, with its own `.env`, profile and `deploy/`), and in that `.env`:

- `COMPOSE_PROJECT_NAME=passorde-test`: containers, networks and volumes are named after it, so the two never share a database volume;
- `EDGE_SUBNET` another free /24 (e.g. `172.30.94.0/24`): two stacks cannot use the same subnet (Docker refuses to create the second network). `TRUSTED_PROXIES` names both subnets (`172.30.93.0/24,172.30.94.0/24`), because requests reach this app through both Caddys;
- `BACKUP_NAME` of its own (e.g. `passorde-test`), or another bucket, so its snapshots never mix with production's; its own `DB_PASSWORD`, `APP_KEY` and `RESTIC_PASSWORD`;
- `COMPOSE_FILE=compose.prod.yml:compose.behind.yml` and `CADDYFILE=./docker/caddy/Caddyfile.behind`, the two files below.

Only one Caddy can publish ports 80 and 443. The first installation's Caddy keeps them, gets the certificate for the second host name and hands its requests to the second installation's Caddy, which publishes nothing and joins the first one's `edge` network under an alias. `compose.behind.yml`, in the second install directory (`passorde_edge` is `<first COMPOSE_PROJECT_NAME>_edge`):

```yaml
services:
  caddy:
    ports: !reset []
    networks:
      edge: {}
      front:
        aliases: [passorde-test]
networks:
  front:
    name: passorde_edge
    external: true
```

`docker/caddy/Caddyfile.behind`, in the second install directory: plain HTTP on that network only, the first Caddy trusted for the client address and the scheme, and the upstream named by container (`<COMPOSE_PROJECT_NAME>-app-1`), because the name `app` would also find the first installation's app on the shared network:

```
{
	auto_https off
	servers {
		trusted_proxies static 172.30.93.0/24
	}
}

http://{$APP_DOMAIN} {
	import /etc/caddy/security.caddy
	reverse_proxy passorde-test-app-1:8080
}

import /etc/caddy/sites/*.caddy
```

`docker/caddy/sites/test.caddy`, in the **first** install directory (then `caddy reload`, `docker/caddy/sites/README.md`):

```
test.example.com {
	import /etc/caddy/security.caddy
	header {
		defer
		Strict-Transport-Security "max-age=31536000"
	}
	reverse_proxy passorde-test:80
}
```

The second installation's `APP_DOMAIN` is `test.example.com` and `APP_URL=https://test.example.com`; point its DNS record at the server. The first stack must be running when the second one starts (its `edge` network is external to the second). Copy `compose.behind.yml` and `Caddyfile.behind` with the other deploy files; `deploy.sh` and `rollback.sh` work in each directory as usual. The second Caddy can reach the first installation's app on the shared network: keep its Caddyfile to the lines above.

## Server bootstrap

Once, as root, on a Debian or Ubuntu server. `deploy/bootstrap.sh` keeps every file that already exists and only adds what is missing; running it again is safe. It reads the port sshd really listens on for ufw and fail2ban. **It does not harden sshd** (key-only login, root login, port): do that yourself first, keeping a session open until a new login works.

1. On your machine, create a deploy key pair (no passphrase, for whatever runs your deploys) and a read-only token for the server to pull your images from your registry:

   ```bash
   ssh-keygen -t ed25519 -N '' -C passorde-deploy -f ~/.ssh/passorde_deploy
   ```

2. Copy the script and the example `.env` to the server (same directory: the script copies `.env.production.example` from its own directory), then run it:

   ```bash
   scp deploy/bootstrap.sh .env.production.example root@<host>:/root/
   ssh root@<host> 'DEPLOY_PUBKEY="$(cat)" REGISTRY=<registry host> REGISTRY_USER=<user> REGISTRY_TOKEN=<read-only token> sh /root/bootstrap.sh' < ~/.ssh/passorde_deploy.pub
   ```

   `DRY_RUN=1` in front prints what it would do without changing anything. Other variables: `DEPLOY_USER` (default `deploy`), `APP_DIR` (default `/srv/passorde`), `REGISTRY` (default `ghcr.io`). The token may end up in your shell history: give it pull rights only.
3. What it does: installs Docker CE and the compose plugin (Docker's apt repository) if missing; sets ufw to deny incoming except the SSH port, 80/tcp, 443/tcp and 443/udp; adds a fail2ban sshd jail (`/etc/fail2ban/jail.d/passorde.local`: 3 failures in 10 minutes, banned 1 hour) and unattended security upgrades without reboot, if their files are missing; creates the `deploy` user (docker group, your public key) and, when a file in `/etc/ssh/sshd_config.d/` has an `AllowUsers` line, adds the user to it (with `sshd -t` first; keep your session open until `ssh -i ~/.ssh/passorde_deploy deploy@<host>` works); creates `/srv/passorde` with `.env` from the example (deploy:www-data, mode 640), `deploy/`, `docker/caddy/`, `docker/caddy/sites/`, `restore/`; and logs the deploy user in to your registry.
4. Fill in `/srv/passorde/.env` ("Production .env checklist"), including `APP_DOMAIN`, `APP_KEY` and `DB_PASSWORD`. Put the installation profile in `/srv/passorde/profile/profile.php` (owner deploy, group www-data, mode 640; from your private profile, never from the repository) and keep `PASSORDE_PROFILE=/var/www/html/profile`, or set `none` for the neutral defaults.
5. Create an S3-compatible bucket and a key with read/write on it; put the endpoint, bucket, region, access key and secret in `BACKUP_S3_*`, and a new `RESTIC_PASSWORD`.
6. Point the DNS record of `APP_DOMAIN` at the server; ports 80 and 443 must be reachable for Let's Encrypt.

Afterwards: when `/var/run/reboot-required` exists (kernel, libc), reboot in a quiet moment. Docker comes from Docker's apt repository, so it is upgraded with `apt full-upgrade`, not by unattended-upgrades. `fail2ban-client status sshd` lists bans; `fail2ban-client set sshd unbanip <address>` lifts one.

## CI, images and copying the deploy files

- `.github/workflows/ci.yml` runs on every push and pull request: Pest against a Postgres 16 service, Pint, Larastan, Vitest, ESLint and the build (Composer fetches Passorde Connect from its public repository), and it builds the **neutral** production images (`PASSORDE_PROFILE=none`). It pushes nothing and deploys nothing.
- Your installation builds its own images, with its own profile, in a pipeline of your choice (a private repository of yours that holds the profile, or a build machine): see "Installation profile" for the build commands (`tools/prod-local/build.sh` runs exactly those). Tag them (short git SHA), push them to your registry and set `PASSORDE_IMAGE` / `PASSORDE_BACKUP_IMAGE` in the server `.env`. The tag is free text (letters, digits, `.`, `_`, `-`): when the profile lives in a repository of its own, name both commits in it (for example `<app sha>-<profile sha>`), so that two builds of the same app commit with different profiles never share a tag. `APP_VERSION` (the build argument, printed by `deploy.sh` at the end) stays the app commit.
- **Without a registry** (one server that builds its own images): clone the repository on the server, put the profile beside it and run the two builds there, with an image name whose registry part answers nothing, for example `PASSORDE_IMAGE=localhost:1/passorde` and `PASSORDE_BACKUP_IMAGE=localhost:1/passorde-backup` (port 1 refuses the connection at once: no image is ever fetched from elsewhere under that name). Skip the registry login of the bootstrap (leave `REGISTRY_USER` / `REGISTRY_TOKEN` unset). `deploy.sh` always pulls first and stops when the pull fails; `DEPLOY_PULL_OPTIONAL=1` in front of it (and of `rollback.sh`, which passes it on) lets it go on with the images of that tag already on the server, and an image of that tag that is not on the server (the app's or the backup's) still stops the deploy before the tag is written and before anything is stopped. Keep the images of the tags you may roll back to (never `docker image prune -a`).
- Before each deploy, copy the deploy files from the same commit as the image into the install directory (`deploy` is the deploy user):

  ```bash
  ssh deploy@<host> "install -d /srv/passorde/deploy /srv/passorde/docker/caddy/sites"
  scp compose.prod.yml deploy@<host>:/srv/passorde/
  scp docker/caddy/Caddyfile docker/caddy/security.caddy deploy@<host>:/srv/passorde/docker/caddy/
  scp deploy/deploy.sh deploy/rollback.sh deploy/backup.sh deploy/restore.sh deploy/bootstrap.sh deploy@<host>:/srv/passorde/deploy/
  ssh deploy@<host> "sh /srv/passorde/deploy/deploy.sh <tag>"
  ```

- The PHP base image is pinned in `docker/php/Dockerfile` (tag and digest). Upgrade it deliberately: new tag and digest from Docker Hub, `docker compose build app`, all checks.
- Validate the workflows after editing them: `docker run --rm -v "$PWD:/repo" -w /repo rhysd/actionlint:1.7.7`. Check the shell scripts with `docker run --rm -v "$PWD:/mnt" -w /mnt koalaman/shellcheck-alpine:v0.11.0 shellcheck -s sh deploy/*.sh`.

## First deploy

1. The bootstrap is done, `.env` is complete (standalone: `EVOLIZ_ENABLED=false`; `SMS_ENABLED=false` until tested), the profile is in place, and both images are in your registry (or built on the server, "Without a registry"). Bringing the database of another installation: load it now, before `deploy.sh` ("Restoring a backup", "A database from another installation").
2. Copy the deploy files and run `deploy.sh <tag>` (`DEPLOY_PULL_OPTIONAL=1 deploy.sh <tag>` without a registry; "CI, images and copying the deploy files"). It starts Postgres, which creates the empty database on its first start. Caddy gets the certificate on the first request.
3. On the server:

   ```bash
   cd /srv/passorde
   docker compose ps                                   # all healthy (queue and scheduler within ~2 minutes)
   docker compose exec app php artisan db:seed --force # first admin from ADMIN_* (none if the database has one: "First admin") + payment types
   docker compose exec app php artisan passorde:check-profile
   docker compose exec backup backup.sh                # first backup now, creates the restic repository
   curl -sI https://orders.example.com/login | grep -i -E 'strict|content-security'
   ```

4. Sign in as the admin, change the password, remove `ADMIN_PASSWORD` from `.env`. Create the sales people (Utilisateurs → Ajouter), import the catalogue and the clients (Administration → Import), check the payment methods.

With the add-on, afterwards: set `EVOLIZ_ENABLED=true`, deploy again (or `docker compose up -d --force-recreate app queue scheduler`), paste the licence and the Evoliz keys on "Réglages Evoliz", "Tester la connexion", set `evoliz.payterm_id` in the profile, then run the first syncs one at a time (`sync:paytypes`, `sync:clients`, `sync:articles`, `sync:deliveries`, and the backfill), each printing "N seen, M changed". Stay in test mode until "Turning Evoliz writes on".

## Deploying

What `deploy/deploy.sh <tag>` does on the server (idempotent, POSIX sh):

0. refuses at once, changing nothing, when `.env` does not state `PASSORDE_PROFILE` (it says what to add);
1. pulls the app and backup images for the tag; if that fails, nothing changed (`DEPLOY_PULL_OPTIONAL=1`: it goes on with the images already on the server, "CI, images and copying the deploy files"). It starts Postgres if it is not running (the first deploy of a new installation; a running one is left as it is). Then, still changing nothing, it stops on any of these (the running version keeps running):
   - an image of the tag (app, backup, Postgres) is not on the server (`docker image inspect`);
   - the image does not start (`php artisan --version`);
   - its `passorde:check-profile` fails against the live database, not migrated yet (a database without any table, before the first migration, has nothing to compare: the check warns "The database is empty (no table yet): stored values not compared." and checks the profile itself; a database with any table is compared).

   Each refusal ends with `deploy: FAILED (exit 1) while deploying <tag>.` and `deploy: nothing was changed; <previous tag> is still running.`;
2. writes `PASSORDE_TAG=<tag>` in `.env`;
3. stops the scheduler and clears its overlap locks (`schedule:clear-cache`), then stops the queue worker (its current job finishes, up to 60 s): no old job code runs while the new app migrates; queued jobs wait in `jobs` for the new worker;
4. `docker compose up -d --remove-orphans --wait --force-recreate app queue scheduler backup`: every app container starts fresh (also on a redeploy of the same tag after editing `.env`), the app migrates and each container runs `optimize` on start, queue and scheduler wait for the app; Postgres and Caddy are not recreated;
5. reloads Caddy (the Caddyfile may have changed);
6. checks `/up` inside the app container and at `APP_URL` (`DEPLOY_CURL_OPTS=-k` for a self-signed certificate), appends the deploy to `releases.log` and prints the running services and tag.

On failure it says whether anything changed and the rollback command. To deploy by hand: `ssh deploy@<host> /srv/passorde/deploy/deploy.sh <tag>`. Phones pick up the new version at their next start ("Nouvelle version disponible").

Migrations only add (columns, tables), so the previous image keeps working on the migrated database. Renaming something the code reads is never a migration: see "Older names".

## Rollback

```bash
/srv/passorde/deploy/rollback.sh             # the tag deployed before the current one (releases.log)
/srv/passorde/deploy/rollback.sh 1a2b3c4     # a given tag
```

It runs `deploy.sh <tag>`: the older app finds no migration to run and ignores the tables and columns it does not know.

Tags are short commit SHAs (or app and profile commits, above); keep the ones you may roll back to in your registry, or on the server without one (`DEPLOY_PULL_OPTIONAL=1 /srv/passorde/deploy/rollback.sh`). A rollback does not stop a later deploy of the bad tag: fix or revert the commit first.

An installation that renamed its database with `passorde:rename-legacy` and goes back to an image from before that rename undoes the rename first, with the image running now and the application stopped ("Older names", "Going back past the rename"); only then `rollback.sh <older tag>`.

## Older names

An installation whose fields, tables or texts keys had other names before (its phones were installed under them) states those older names in its profile, section `aliases` (config/passorde.php describes every map). A new installation leaves every map empty and never needs this section.

- **Phones and API**: `client`, `article`, `totals`, `summary`, `stock_tabs`, `routes`, `groupings` keep older phones and cached pages working: the server accepts and answers the old names beside the current ones (docs/API.md "Old names"), and the phones read the rows they stored under the old names under the current ones. The phone maps are fixed in the assets at build time: build the image with the profile, as always (`passorde:check-profile` reports assets built with other older names as an error).
- **Profile texts**: `texts` (old key => current key) reads a profile's `texts` entry written under an older key; the check asks to rename it.
- **Database**: `schema` names the tables and columns as they were (current => old). Only `passorde:check-profile` and `passorde:rename-legacy` read this map; the app itself reads the current names only, so an image that uses the current names never runs on a database that still has the older ones (its queries on those tables and columns would fail), and an image from before the rename never runs on a renamed one. `passorde:check-profile` reads the stored values under both names, so `deploy.sh` can check the profile with the new image either side of the rename.

  **Upgrading to the first image with the current names**, once, in this order: the application stopped (nothing writes while the names change), a backup kept, the rename run with the **new** image (the one you are about to deploy: the older image may not have the command, or not this version of it), then `deploy.sh` with that same tag, which starts everything again, Caddy included. `PASSORDE_TAG=<new tag>` on the command line makes Compose use the new image for these one-off containers while `.env` still names the running one:

  ```bash
  cd /srv/passorde
  docker compose stop caddy app queue scheduler
  docker compose exec backup backup.sh --keep before-rename
  PASSORDE_TAG=<new tag> docker compose pull app
  PASSORDE_TAG=<new tag> docker compose run --rm --no-deps -e AUTORUN_ENABLED=false app php artisan passorde:rename-legacy --dry-run
  PASSORDE_TAG=<new tag> docker compose run --rm --no-deps -e AUTORUN_ENABLED=false app php artisan passorde:rename-legacy
  /srv/passorde/deploy/deploy.sh <new tag>
  ```

  The dry run lists every rename and changes nothing; read it before the real run. If `deploy.sh` then refuses (a profile error, an image that does not start), the older version is **not** running any more: fix the cause and run `deploy.sh <new tag>` again, or go back with the undo below and start the older tag with `docker compose up -d`. `deploy.sh` writes the new tag in `.env` only after its checks (it refuses a pull, an image that does not start or a profile error before that), so after such a refusal `.env` still names the older image, which may not have the command: put `PASSORDE_TAG=<new tag>` in front of both `docker compose run` lines of the undo.

  **Going back past the rename** (before `rollback.sh` to an image from before it), with the image running now, because only it knows the current names, and with the application stopped: the undo takes each table's exclusive lock and rewrites stored rows in one transaction, so a running request waits on it and then fails on the names that have gone, a queue worker fails its jobs the same way, and a row written in between may keep the current field names:

  ```bash
  cd /srv/passorde
  docker compose stop caddy app queue scheduler
  docker compose exec backup backup.sh --keep before-undo
  docker compose run --rm --no-deps -e AUTORUN_ENABLED=false app php artisan passorde:rename-legacy --undo --dry-run
  docker compose run --rm --no-deps -e AUTORUN_ENABLED=false app php artisan passorde:rename-legacy --undo
  /srv/passorde/deploy/rollback.sh <older tag>
  ```

  It renames the tables and columns with their sequences, constraints and indexes, and the same field names inside the stored client corrections and order snapshots, in one transaction; a second run changes nothing. `--undo` goes back to the older names (above). The fictional `tests/Fixtures/profiles/renamed` shows every map; `tests/Feature/Migrations/RenameLegacyTest.php` pins the command.

## Backups

- Every night at 02:30 Europe/Paris the `backup` container runs `deploy/backup.sh` (its crond, `TZ=Europe/Paris`, crontab from `BACKUP_SCHEDULE`, default `30 2 * * *`): `pg_dump -Fc` of the database plus the server `.env` and the installation profile, into a restic repository at `s3:<BACKUP_S3_ENDPOINT>/<BACKUP_S3_BUCKET>/<BACKUP_NAME>` (restic host `BACKUP_NAME`, default `passorde`; encrypted with `RESTIC_PASSWORD`). Every night `restic forget --keep-daily 30 --keep-monthly 12 --keep-tag keep` marks old snapshots; on Sundays it also prunes (frees the space) and runs `restic check`. Each step has a deadline (an unreachable bucket fails after 10 minutes instead of retrying for hours).
- Any S3-compatible storage works (the laptop rehearsal uses MinIO). The container has `RESTIC_REPOSITORY` and the S3 keys in its environment (`compose.prod.yml`), so plain `restic` commands work in it.
- The result goes to `ops_heartbeats` ("backup"): the daily digest reports a failed run or no successful backup for 26 hours.
- Retention: the last snapshot of each of the last 30 days and of 12 months, so a second backup the same day makes the first one forgettable; a snapshot tagged `keep` (`backup.sh --keep <name>`, lower-case letters, digits and `-`) is never forgotten until the tag is removed. Any snapshot can be tagged afterwards, nightly ones included and those of a backup image whose `backup.sh` has no `--keep` yet: `restic tag --add keep --add <name> <snapshot id>` (the id from `restic snapshots`), before the next nightly `forget` would drop it.
- The licence file of the add-on (on the `storage` volume, used only until a key is saved on "Réglages Evoliz") is not in the backup; keep your licence key elsewhere.
- By hand:

  ```bash
  docker compose exec backup backup.sh                    # one backup now
  docker compose exec -e BACKUP_PRUNE=1 backup backup.sh  # with prune and check
  docker compose exec backup restic snapshots             # list (also: restic stats, restic check)
  docker compose exec backup backup.sh --keep <name>      # a backup the retention never forgets (tags keep, <name>)
  docker compose exec backup restic tag --add keep --add <name> <snapshot id>   # keep a snapshot taken without --keep
  docker compose exec backup restic tag --tag <name> --remove keep   # forgettable again
  docker compose logs backup                              # last runs
  ```

## Restoring a backup

```bash
docker compose exec backup restic snapshots
docker compose exec backup restore.sh latest              # or a snapshot id; optional 2nd argument: database name
```

`restore.sh` restores the snapshot into a **new** database (`<DB_DATABASE>_restore_<date>_<time>` unless named; `a-z`, `0-9` and `_` only; never the live name), copies that night's `.env` to `/srv/passorde/restore/<snapshot>/.env` and its installation profile to `/srv/passorde/restore/<snapshot>/profile/`, and prints the switch-over steps without touching the live database: stop caddy, app, queue and scheduler; rename the live database aside and the restored one to the live name; only if the server `.env` was lost, compare it with the restored one (`APP_KEY` must be the backup's); `docker compose up -d`; `docker compose exec app php artisan migrate --force`; check `/up`; drop the old database once satisfied. Orders created after the backup are gone from the server; orders still waiting on phones are sent as usual. A snapshot taken before `passorde:rename-legacy` holds the older names: after the switch-over rename and before `docker compose up -d`, run `passorde:rename-legacy --dry-run` and then `passorde:rename-legacy` as in "Older names" (with `docker compose run --rm --no-deps -e AUTORUN_ENABLED=false app …`), so the running image finds the current names.

**A database from another installation** (a `pg_dump -Fc` file, not a restic snapshot), brought into a new install directory: bootstrap and fill in `.env` as for a first deploy, with that installation's `APP_KEY` (encrypted values in the database, such as the Evoliz keys and the licence key, need it), put the profile in place, and load the dump into the empty database **before the first `deploy.sh`** (the app's first start would create the tables, and the restore would then fail on them). Postgres 16 restores dumps of 16 and older.

```bash
cd /srv/passorde
docker compose up -d --wait postgres                 # creates the empty DB_DATABASE on its first start
docker compose exec -T postgres pg_restore --no-owner --role=<DB_USERNAME> --exit-on-error -U <DB_USERNAME> -d <DB_DATABASE> < old.dump
/srv/passorde/deploy/deploy.sh <tag>                 # checks the profile against it, then migrates what is missing
```

A plain SQL dump goes through `psql` instead (`docker compose exec -T postgres psql -U <DB_USERNAME> -d <DB_DATABASE> -v ON_ERROR_STOP=1 < old.sql`). If that installation's database still has older names (its profile has a `schema` map), run the rename of "Older names" from the `pull` line on, before `deploy.sh`. The users come with the database: sign in with one of its admins ("First admin" says how to give one a new password); `ADMIN_*` creates nobody. Take a first backup once it runs (`docker compose exec backup backup.sh`).

If the whole server is lost: bootstrap a new one, put back `.env` from your own copy (or from a backup: `restic restore` from any machine with the same `BACKUP_S3_*`, `BACKUP_NAME` and `RESTIC_PASSWORD`), put back the profile, deploy, then restore the latest snapshot as above.

Drill (before going live and every few months), into a scratch database only (`passorde` is the default `DB_DATABASE` / `DB_USERNAME`):

```bash
docker compose exec backup restore.sh latest passorde_restore_test
docker compose exec postgres psql -U passorde -d passorde_restore_test -c 'select count(*) from users' -c 'select count(*) from clients' -c 'select count(*) from articles' -c 'select count(*) from orders'
docker compose exec postgres psql -U passorde -d passorde -c 'select count(*) from users' -c 'select count(*) from clients' -c 'select count(*) from articles' -c 'select count(*) from orders'
docker compose exec postgres dropdb -U passorde passorde_restore_test
rm -rf /srv/passorde/restore/<snapshot>      # the copy of that night's .env: it holds every secret
```

## Passorde Connect from a local clone

`composer.json` takes Passorde Connect from its public repository at an exact version. To work on both at once, point Composer at a local clone with changes you never commit: a `compose.override.yml` mounting the clone into the `app` container (for example at `/passorde-connect`), and in `composer.json` a `path` repository with that URL placed before the `vcs` one, plus a requirement the clone satisfies (its branch, e.g. `dev-main`). Then `docker compose up -d` and `docker compose exec app composer update marinailsapps/passorde-connect`. Composer copies the clone into `vendor/` and keeps that copy while the clone's `composer.json` does not change: `rm -rf vendor/marinailsapps/passorde-connect && composer install` takes the clone's code again (Passorde Connect's `docs/install.md` §1). Before committing, put `composer.json`, `composer.lock` and `vendor/` back to the published version (`git checkout composer.json composer.lock`, then `composer install`).

## After deploying code

In production `deploy/deploy.sh` does all of this. In development, the queue worker and scheduler keep the old code in memory: `docker compose restart queue scheduler`. The `vite` container installs npm packages only when it starts: `docker compose restart vite` after changing `package.json`.

The service worker (`public/sw.js`) and the web manifest exist only after `npm run build`; the dev server (`public/hot`) does not register a service worker. To try offline mode in development, stop the vite container (`docker compose stop vite`; delete `public/hot` if it is still there), run `npm run build`, and load the app on http://localhost:8093. Restart vite afterwards. Phones pick up a new build at their next start.

After changing `docker/php/` (Dockerfile or the nginx rules in `docker/php/nginx/`, such as `/sw.js` being served with `Cache-Control: no-cache`): `docker compose build app && docker compose up -d app queue scheduler`.

The PWA icons in `public/icons` are generated from the profile's logo, else `resources/images/logo.png`: `npm run icons` after changing it.

## Production stack on a laptop

Rehearses the production image, compose file, Caddy (with Caddy's own local CA), backups (MinIO standing in for the S3 bucket), the Evoliz mock and the OVH mock, without touching the dev stack (separate project `passorde-prod`, ports 8088/8443). `tools/prod-local/compose.yml` adds the two mocks (`evoliz-mock` from `vendor/marinailsapps/passorde-connect/connectors/evoliz/mock`, so run `composer install` in development first; `ovh-mock` from `docker/ovh-mock`) and MinIO; it is never used on a server.

1. A local env file (gitignored): copy `.env.production.example` to `.env.prod.local` and set `PASSORDE_TAG=prod-local`, `COMPOSE_PROJECT_NAME=passorde-prod`, `APP_URL=https://localhost:8443`, `APP_DOMAIN=localhost`, `APP_KEY`, `DB_PASSWORD`, `MAIL_MAILER=log`, `ADMIN_LOGIN`/`ADMIN_PASSWORD` (a throwaway password), Cloudflare's published test keys for Turnstile (`TURNSTILE_SITE_KEY=1x00000000000000000000AA`, `TURNSTILE_SECRET_KEY=1x0000000000000000000000000000000AA`), `SYNC_SCHEDULE_ENABLED=false`, `PASSORDE_PROFILE=none` (or a fictional profile directory in `PASSORDE_PROFILE_DIR` with `PASSORDE_PROFILE=/var/www/html/profile`), `BACKUP_S3_ENDPOINT=http://minio:9000`, `BACKUP_S3_BUCKET=passorde-backups`, `BACKUP_S3_REGION=us-east-1`, `BACKUP_S3_KEY`/`BACKUP_S3_SECRET` (any; MinIO uses them as its root user), `RESTIC_PASSWORD`. To rehearse the add-on too: `EVOLIZ_ENABLED=true`, the mock's `PASSORDE_EVOLIZ_*` values and `EVOLIZ_PAYTERM_ID=37` ("Rehearsing with the Evoliz mock"), `PASSORDE_WRITES_ENABLED=true`, `SMS_ENABLED=true`, `OVH_ENDPOINT=http://ovh-mock:8080/ovh/1.0` with any `OVH_*` values, and a real licence key pasted on "Réglages Evoliz" (there is no development signing). At the end:

   ```
   PASSORDE_IMAGE=passorde
   PASSORDE_BACKUP_IMAGE=passorde-backup
   APP_ENV_FILE=./.env.prod.local
   CADDYFILE=./docker/caddy/Caddyfile.local
   HTTP_PORT=8088
   HTTPS_PORT=8443
   ```

2. Build the images and start:

   ```bash
   PASSORDE_PROFILE_DIR=none sh tools/prod-local/build.sh     # or the directory of a fictional profile
   docker compose -p passorde-prod -f compose.prod.yml -f tools/prod-local/compose.yml --env-file .env.prod.local up -d
   docker compose -p passorde-prod -f compose.prod.yml -f tools/prod-local/compose.yml --env-file .env.prod.local exec app php artisan db:seed --force
   ```

   `build.sh` builds `passorde:prod-local` and `passorde-backup:prod-local` with the command of "Installation profile", and prints the build's stamp.

   **Data**: import a CSV in standalone mode, or with the add-on run `exec app php artisan sync:paytypes`, `sync:clients`, `sync:articles`, `sync:deliveries --days=200` against the mock; then keep only the mock's payment types selectable (41 and 42) and give one client a mobile number so the SMS goes out.

3. Smoke test over HTTPS (Playwright, with Caddy's root CA trusted inside the container): sign-in with Turnstile, service worker registered and controlling the page, one order through the wizard, approval, every screen opened at 390 px and the order page at 1280 px, and **any CSP violation fails the run**. `SMOKE_MODE=standalone` (the default) expects the order approved locally and in the CSV export, and the add-on's pages (`/passorde/licence`, `/admin/sync`) to answer 404; `SMOKE_MODE=evoliz` expects the Evoliz mock's BL and SMS and a valid licence on the licence page. In standalone mode, give it a client and an article first (CSV import) and name them with `SMOKE_CLIENT` / `SMOKE_ARTICLE`. Leave a minute between runs: the API allows 120 requests a minute per user, and two runs in a row as the same admin hit it (HTTP 429, the order then waits in the outbox):

   ```bash
   SMOKE_LOGIN=admin SMOKE_PASSWORD=<ADMIN_PASSWORD> sh tools/prod-local/smoke.sh
   ```

   `SMOKE_CLIENT` and `SMOKE_ARTICLE` pick the client and the article by a search term, `SMOKE_OUT=<dir>` keeps screenshots of failed checks, `PROJECT` names the stack. Playwright comes from the `passorde-playwright-node-modules` volume (`NODE_MODULES_VOLUME`); when it is empty ("Cannot find package 'playwright'"), fill it: `docker run --rm -e PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 -v "$PWD:/app" -v passorde-playwright-node-modules:/app/node_modules -w /app mcr.microsoft.com/playwright:v1.63.0-noble npm ci`. A browser on the host shows the same stack at https://localhost:8443 after accepting Caddy's certificate (no service worker then). The smoke test needs a way out to the internet while it runs: `smoke.sh` installs `libnss3-tools` in the Playwright container with apt (for `certutil`), the login page loads Cloudflare's Turnstile widget, and the app checks its token with Cloudflare (filling the Playwright volume needs npm's registry too). It cannot run on a machine without one, and not with Turnstile switched off either: the login check waits for the widget's token and fails after 30 seconds.

4. Backup cycle against MinIO (the alias saves typing):

   ```bash
   alias pc='docker compose -p passorde-prod -f compose.prod.yml -f tools/prod-local/compose.yml --env-file .env.prod.local'
   pc exec backup backup.sh                                   # creates the repository, snapshot saved
   pc exec -e BACKUP_PRUNE=1 backup backup.sh                 # prune + check
   pc exec -e BACKUP_S3_SECRET=wrong -e BACKUP_PROBE_TIMEOUT=15 backup backup.sh   # fails, recorded for the digest
   pc exec app php artisan ops:daily-digest --print           # shows the failed backup
   pc stop caddy app queue scheduler
   pc exec postgres psql -U passorde -d postgres -c 'DROP DATABASE passorde WITH (FORCE)'
   pc exec backup restore.sh latest passorde_restore
   pc exec postgres psql -U passorde -d postgres -c 'ALTER DATABASE "passorde_restore" RENAME TO "passorde"'
   pc up -d && SMOKE_LOGIN=admin SMOKE_PASSWORD=<ADMIN_PASSWORD> sh tools/prod-local/smoke.sh
   ```

5. Deploy and rollback rehearsal: run a local registry (`docker run -d -p 5050:5000 --name passorde-registry registry:2`), push two tags of both images to `localhost:5050/…`, make a directory that looks like `/srv/passorde` (the compose files, `docker/caddy/`, `deploy/`, `tools/prod-local/compose.yml`, `docker/ovh-mock/`, `vendor/marinailsapps/passorde-connect/connectors/evoliz/mock/` and a `.env` from `.env.prod.local` with `PASSORDE_IMAGE=localhost:5050/passorde`, `PASSORDE_BACKUP_IMAGE=localhost:5050/passorde-backup`, `COMPOSE_FILE=compose.prod.yml:tools/prod-local/compose.yml` and no `APP_ENV_FILE`), then from it: `DEPLOY_CURL_OPTS=-k ./deploy/deploy.sh <tag1>`, `… deploy.sh <tag2>`, `./deploy/rollback.sh`, and `./deploy/deploy.sh <missing tag>` (fails with "nothing was changed").

6. Stop and remove: `pc down -v` (drops the rehearsal volumes only).

## Viewport sweep (dev only)

`tools/viewport-sweep` photographs every page for a guest, a sales user and an admin at 360, 390, 430, 590, 768 and 1024 px in headless Chromium (Playwright's own, or an installed Google Chrome), and lists what does not fit: sideways scrolling, elements past the right edge, cut text, tap targets under 40 px. Not part of CI; run it after layout changes:

```bash
docker compose exec app php artisan sweep:seed
node tools/viewport-sweep/sweep.mjs
docker compose exec app php artisan sweep:seed --clean
```

`sweep:seed` (`APP_ENV=local` only) creates `sweep-admin` and `sweep-vendeur` (passwords generated into `storage/app/sweep/credentials.json`), a fictitious client with a long name, one order per status and two journal entries; no Evoliz call. Screenshots and `report.md` land in `storage/viewport-sweep/` (not committed). `--only orders` limits the run to pages whose name contains the word; `--widths 360,1024` and `--base` change the defaults. A full run takes about 15 minutes. The app allows 5 logins a minute: wait a minute between quick reruns.
