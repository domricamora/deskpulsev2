# Local development

The repository holds **two applications side by side** during the migration:

| Path | What it is | Served at (WAMP) |
|---|---|---|
| `server/` | The legacy PHP application — still the behavioural reference | `http://localhost/deskpulsev2/server/public/` |
| *(repo root)* | The Laravel application being built | `http://localhost/deskpulsev2/public/` |
| `agent/` | The Python desktop agent — **unchanged by this migration** | — |

Neither the legacy server nor the agent is modified. See
[docs/migration/README.md](migration/README.md) for the audit that governs the work.

## Requirements

| Tool | Version | Notes |
|---|---|---|
| PHP | **8.3** | Not 8.4 — see below |
| Composer | 2.x | |
| MySQL / MariaDB | 8.0+ / 10.x | Dev uses MySQL 9.1 |
| Node | 20+ | 22 locally |
| npm | 10+ | |
| Python | 3.12 | Agent only |
| Apache | 2.4 | WAMP |

### PHP must be 8.3

The production host serves **PHP 8.3 only**. Laravel 13 requires `php: ^8.3`, so 8.3
satisfies it — but the local CLI must match production, or 8.4-only syntax will pass
locally and fatal in production.

`composer.json` pins the platform accordingly:

```json
"config": { "platform": { "php": "8.3.0" } }
```

That stops Composer resolving a package against a newer local PHP than production runs.
If you know the exact production patch level, pin to it.

On WAMP the CLI version is whichever `C:\wamp64\bin\php\phpX.Y.Z` sits on the machine
PATH. Changing it requires elevation, because user-level PATH entries resolve *after*
machine ones. Verify in a **new** terminal — an open shell keeps a stale snapshot:

```bash
php -v          # expect 8.3.x
composer --version
```

## Setup

```bash
composer install
npm install
npm run build

cp .env.example .env
php artisan key:generate
```

Create the databases:

```sql
CREATE DATABASE deskpulse      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE deskpulse_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

`deskpulse` is the live legacy schema and already holds the tables, so there is nothing
to migrate into it. To build the schema from scratch elsewhere:

```bash
php artisan migrate
```

For frontend work:

```bash
npm run dev     # Vite dev server with hot reload
```

## Tests

```bash
php vendor/bin/pest
```

The suite runs against **MySQL**, not sqlite. Laravel's skeleton defaults to
sqlite `:memory:`; that was changed deliberately, because Phase 3's migrations come
from a MySQL schema and sqlite cannot express most of what needs verifying — `double`
columns, `utf8mb4` collations, `ENGINE=InnoDB`. Testing on sqlite would pass migrations
that do not reproduce the real schema.

Tests use `deskpulse_test` and never touch `deskpulse`.

`RefreshDatabase` is applied **per test file**, not globally — most feature tests here
assert configuration or rendering and need no database, so migrating for them would only
slow the suite. Add `uses(RefreshDatabase::class);` at the top of any file that touches
data.

The legacy end-to-end harness must keep working unmodified — it is the Phase 7 gate:

```bash
py tools/test_webhook.py http://localhost/deskpulsev2/server/public ava@demo.test Demo12345
```

## The desktop agent

The agent is frozen — it is not being rebuilt, and nothing under `agent/` is edited
to accommodate the server. Two tools point it at a local Laravel instead of
production, because `agent/config.py` hard-locks `SERVER_URL` to
`https://deskpulse.click` and ignores any `server_url` on disk:

```bash
py tools/test_agent_compat.py http://localhost/deskpulsev2/public ava@demo.test Demo12345
py tools/run_agent_local.py   http://localhost/deskpulsev2/public --fresh
```

The first is the Phase 8 gate: it imports the agent's own `WebhookClient` and
`Tracker` and runs a real tracked session, then asserts the agent's offline queue
is empty — that queue is where the agent silently buries anything that failed. The
second launches the actual Qt application; `--fresh` keeps its config and queue in
a temp directory so a local run cannot overwrite the credentials of a real agent
installed on the same machine.

Both need the agent's Python dependencies (`pip install -r requirements.txt`), and
a compatibility run leaves a new `devices` row behind each time — registration is
non-idempotent by design.

## Uploads

**Both applications must share one uploads directory.** Laravel resolves
`public_path('uploads')`; the legacy app writes `server/public/uploads`; and the
paths already stored in `organizations.logo_path` and `screenshots.file_path` point
at whatever the legacy app wrote. With two directories, the Laravel dashboard shows
no logo and no historical screenshots, and neither app can see what the other
stored. On this machine `public/uploads` is a directory junction:

```text
mklink /J C:\wamp64\www\deskpulsev2\public\uploads C:\wamp64\www\deskpulsev2\server\public\uploads
```

Both paths are gitignored, so a fresh clone starts with neither and needs the link
(or a copy of the uploads tree) before logos resolve. At cutover the tree moves
under the Laravel `public/` for real — see `api-contract.md` §9.

**Screenshots are no longer part of that tree.** Phase 9 moved them to the
`private` disk under `storage/app/private/screenshots/`, served only through
`/app/screenshots/{id}/image` after a visibility check. Any environment carrying
data written before Phase 9 needs the files moved once:

```bash
php artisan screenshots:relocate --dry-run   # report only
php artisan screenshots:relocate
```

It is idempotent, and it also counts image files left under the public tree with
no screenshot row — leftovers from re-seeds, still readable by URL. It deletes
none of them; clear those by hand.

## Migrations

The 37 migrations in `database/migrations/` were **generated from the live database**,
not from `server/schema.sql` — that file covers only 30 of the 37 tables and is missing
the whole payments ledger, password resets, OIDC identities and the promo tables.

```bash
php tools/generate_migrations.php   # regenerate from the live schema
php tools/schema_diff.php           # prove a rebuilt schema matches live, column by column
```

`schema_diff.php` is the Phase 3 gate. It compares a migration-built database against
the live one and must report **zero differences**. It deliberately ignores
AUTO_INCREMENT counters and collation — the live dev database uses MySQL 8's
`utf8mb4_0900_ai_ci`, while the migrations use the connection default so they restore
on MariaDB and older MySQL, mirroring what the legacy SQL export already does.

To re-verify locally:

```sql
DROP DATABASE IF EXISTS deskpulse_verify;
CREATE DATABASE deskpulse_verify CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```bash
# point DB_DATABASE at deskpulse_verify, then:
php artisan migrate --force
php tools/schema_diff.php
```

### Two things that are easy to get wrong

**Primary keys are SIGNED.** Laravel's `increments()` and `bigIncrements()` produce
`int unsigned`, but every primary key in this schema is a signed `int`. An unsigned PK
makes every signed foreign key incompatible — MySQL error 3780 — so the generator emits
`integer('id', true, false)` instead.

**Laravel's skeleton migrations were removed, not merged.** They create `users`,
`sessions`, `cache`, `jobs` and `password_reset_tokens`. Two of those collide head-on:
DeskPulse already has a `users` table with a completely different shape, and its
`sessions` table holds **work sessions**, not HTTP sessions. The real schema wins.

That is also why `SESSION_DRIVER=file` and `CACHE_STORE=file`: the database drivers
would want tables that clash or do not exist. When queues are adopted, add Laravel's
`jobs`/`failed_jobs` tables as their own migration — they do not collide.

## Conventions established in Phase 2

- **Timezone is UTC** everywhere (`APP_TIMEZONE=UTC`). Reporting windows are cut in the
  organization's own `report_tz`; display happens in the viewer's browser timezone.
  Never rely on Carbon's ambient timezone — that was the original period bug.
- **The agent API prefix is `/webhooks`**, never `/api/v1`. Deployed agents on Windows,
  macOS and Linux sign the raw request body and cannot be updated in step with the
  server.
- **Design tokens live in `resources/css/app.css`** under `@theme`, reproduced exactly
  from the legacy stylesheet. The app is **dark-only** — Tailwind's defaults would
  produce a light theme.
- **Fonts are self-hosted** from `resources/fonts`. The CSP sets `font-src 'self'`, so a
  font CDN would be blocked.
- **Icons come from `resources/icons/icons.php`**, extracted verbatim from the legacy
  layout and rendered via `<x-icon name="..." />`. Swapping in an icon library would
  change every glyph.
- **`config/deskpulse.php`** holds monitoring, screenshot, agent, remote-control and
  plan defaults. Per-organization values on the `organizations` row override them, and
  a subscription plan can only ever turn capture **off**, never on.

## Deployment

A production deployment document is written in Phase 21, alongside the cutover plan.
The build steps are the standard set:

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

Do not delete the legacy application until the Laravel system has passed production
validation.
