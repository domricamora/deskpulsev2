# DeskPulse

**Remote-work monitoring & time tracking for teams and individuals.** A standalone,
multi-tenant Laravel application — an open, self-hostable alternative to Hubstaff,
Time Doctor, and RescueTime.

- **Backend** — [Laravel](https://laravel.com) 13 on PHP 8.3, MySQL, Composer
- **Frontend** — Laravel Blade templates styled with [Tailwind CSS](https://tailwindcss.com) v4, built with [Vite](https://vitejs.dev)
- **Desktop agent** — Python tray app that tracks time, activity/idle, active windows,
  running tasks, screenshots, and syncs to the server over **HMAC-signed webhooks**

## Tech stack

| Layer | Technology |
|-------|-----------|
| Backend | Laravel 13 (PHP 8.3) |
| Build tool | Vite + Laravel Vite Plugin |
| CSS | Tailwind CSS v4 (`@tailwindcss/vite`) |
| Database | MySQL |
| Frontend | Blade templates, vanilla JS (modular IIFEs) |
| Desktop agent | Python + PySide6 |
| Testing | PHPUnit / Pest |
| Package manager | Composer (PHP) + npm (JS) |

## Features

- **Time tracking** — automatic (per project & task) + manual entries with manager
  approval/rejection
- **Activity & idle detection** — 15-min threshold → active vs inactive
- **Window/app insights** — active window summary + timeline, running tasks
- **Screenshots** — configurable interval, optional blur, visible recording state
- **Live team view** — see who's working, right now
- **Clients & contracts** — per-agent and per-customer billing (hourly or flat-rate),
  salary/labor-cost reports, CSV export
- **Leave management** — entitlement tracking, requests, manager approval
- **Public share links** — read-only day/week/month summaries with graphs
- **Role-based dashboards** — member / manager / admin / staff / super-admin
- **Multi-tenant** — complete data isolation per organization
- **Dark-first design** — custom Tailwind theme with self-hosted fonts

## Requirements

- PHP 8.3+ with Composer
- MySQL 8.0+
- Node.js 20+ with npm
- Python 3.12+ (desktop agent only)

## Installation

### 1. Clone the repository

```bash
git clone https://github.com/domricamora/deskpulsev2.git
cd deskpulsev2
```

### 2. Install PHP dependencies

```bash
composer install
```

### 3. Configure the application

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` and set your database credentials, app URL, and mail settings.

### 4. Run migrations and seed demo data

```bash
php artisan migrate --force
php artisan db:seed --class=DemoSeeder
```

### 5. Build frontend assets

```bash
npm install
npm run build
```

### 6. Start the server

```bash
php artisan serve
```

Open `http://localhost:8000` in your browser.

### Demo logins (after seeding)

| Role | Email | Password |
|------|-------|----------|
| Admin | `admin@demo.test` | `ChangeMe!123` |
| Manager | `manager@demo.test` | `Demo12345` |
| Members | `ava@demo.test`, `ben@demo.test`, `carla@demo.test` | `Demo12345` |

## Desktop agent

```bash
python -m venv .venv
.venv\Scripts\python -m pip install -r requirements.txt
.venv\Scripts\python -m agent.main
```

Sign in with your DeskPulse account and server URL, pick a project/task, and click
**Start**. A "● Monitoring" badge indicates tracking is active.

### Build a Windows installer

```powershell
powershell -ExecutionPolicy Bypass -File agent\packaging\build.ps1
```

Produces `dist\DeskPulse\` and (with Inno Setup) `dist\installer\DeskPulse-Setup-*.exe`.

## Testing

```bash
php artisan test
```

Tests use the `deskpulse_test` MySQL database (configure in `phpunit.xml`).

## Project structure

```
app/          Application code (models, controllers, middleware, providers)
resources/    Blade views, CSS (Tailwind), JS modules
routes/       Web, console, and agent API routes
config/       Application configuration
database/     Migrations and seeders
agent/        Python desktop agent
tests/        PHPUnit / Pest test suites
tools/        CLI utilities
```

## Contributing

Open a pull request! Please run the test suite and linters before submitting.

## Security & privacy

Monitoring is consent-first: it runs only while tracking is on, the agent shows a
visible recording indicator, screenshots can be blurred, and all data lives in your
own workspace. Use it for legitimate, disclosed workforce management.

## License

MIT
