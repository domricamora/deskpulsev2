# DeskPulse

**Remote-work monitoring & time tracking for teams and individuals.** A standalone,
multi-tenant product — an open, self-hostable alternative to Hubstaff / Time Doctor /
RescueTime.

- **Server** — PHP 8.2 + MySQL (runs on WAMP/Apache; no Composer, no build step).
- **Desktop agent** — Python + PySide6 tray app that tracks time, activity/idle,
  active windows, running tasks, the current task, and screenshots, syncing to the
  server over **HMAC-signed webhooks**.

> Architecture, the full requirements log, and data model are documented in
> [CLAUDE.md](CLAUDE.md).

## Features

- Automatic time tracking (per project & per task) + manual entries with
  **manager approval/rejection**
- Activity & **idle detection** (15-min threshold → active vs inactive)
- Active **window/app** insights (summary + timeline) and **running tasks**
- **Screenshots** (configurable interval, optional blur, visible recording state)
- Worker-managed **tasks** (add/remove/select) and **time-per-task** reporting
- **Live** team view for managers (who's working now)
- **Clients & contracts**, **per-agent and per-customer billing** (hourly or flat
  monthly service charge), **salary/labor-cost** reports, CSV export
- **Public share links** (read-only day/week/month summaries with graphs)
- SEO-optimized, blue-themed marketing site + **download** page + user logins that
  lead into role-based dashboards (member / manager / admin)

## Quick start — server (WAMP)

1. **Configure**: copy `server/config.example.php` → `server/config.php` and set your
   MySQL credentials (WAMP default: user `root`, empty password).
2. **Install the database** (creates the DB + tables, optionally demo data):
   ```powershell
   powershell -ExecutionPolicy Bypass -File install.ps1 -Seed
   ```
   or directly:
   ```
   "c:/wamp64/bin/php/php8.2.26/php.exe" server/install.php --seed
   ```
   (Tables also auto-create on first request; `--seed` adds demo users/data.)
3. **Open the app** (Apache serves the repo under the webroot):
   ```
   http://localhost/vtnew/deskpulse/server/public/
   ```
   For a clean URL, point a vhost document root at `server/public/`.

### Demo logins (after `--seed`)

| Role | Email | Password |
|------|-------|----------|
| Admin | `admin@demo.test` (or `DESKPULSE_ADMIN_EMAIL`) | `ChangeMe!123` (or `DESKPULSE_ADMIN_PASS`) |
| Manager | `manager@demo.test` | `Demo12345` |
| Members | `ava@demo.test`, `ben@demo.test`, `carla@demo.test` | `Demo12345` |

## Quick start — desktop agent

```powershell
py -m venv .venv
.venv\Scripts\python -m pip install -r requirements.txt
.venv\Scripts\python -m agent.main      # run from the repo root
```

Sign in with a DeskPulse account and your server URL; pick a project/task and press
**Start**. Tracking runs only while the session is on (a "● Monitoring" badge shows).

### Build an installable Windows app

```powershell
powershell -ExecutionPolicy Bypass -File agent\packaging\build.ps1
```

Produces `dist\DeskPulse\` and (with Inno Setup) `dist\installer\DeskPulse-Setup-*.exe`.
See [agent/packaging/README.md](agent/packaging/README.md) for the Windows trust /
SmartScreen / code-signing details.

## Testing

- Lint PHP: `"c:/wamp64/bin/php/php8.2.26/php.exe" -l server/src/dashboard.php`
- Webhook end-to-end (no GUI):
  ```
  py tools/test_webhook.py http://localhost/vtnew/deskpulse/server/public ava@demo.test Demo12345
  ```

## Layout

```
server/        PHP web app (config, schema.sql, install.php, seed.php, public/, src/, templates/)
agent/         Python desktop agent (config, webhook_client, monitor/, ui/, packaging/)
tools/         test_webhook.py
install.ps1    one-step DB installer (finds WAMP PHP)
requirements.txt   agent Python deps
```

## Security & privacy

Monitoring is consent-first: it runs only while tracking is on, the agent shows a
visible recording indicator, screenshots can be blurred, and all data lives in your
own workspace. Use it for legitimate, disclosed workforce management.
