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

`deskpulse` is the live legacy schema. **No Laravel migrations exist yet** — they are
generated from the live database in Phase 3, so nothing here alters it.

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

> `RefreshDatabase` is currently commented out in `tests/Pest.php`, which is correct
> while no migrations exist. **Enable it in Phase 3**, or feature tests will run
> against leftover state.

The legacy end-to-end harness must keep working unmodified — it is the Phase 7 gate:

```bash
py tools/test_webhook.py http://localhost/deskpulsev2/server/public ava@demo.test Demo12345
```

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
