# Architecture — current system

Source of truth: `server/src/bootstrap.php`, `server/public/index.php`, the module
set under `server/src/`, and `agent/`.

## 1. Shape

```
Browser ─┐
         ├─→ Apache → server/public/index.php ─→ bootstrap.php ─→ Router ─→ handler ─→ templates/
Agent   ─┘                                            │
(Windows/macOS/Linux, Python+PySide6)                 └─→ db.php (PDO) → MySQL
         └─ HMAC → /webhooks/*
```

Two halves, one contract:

1. **Server** — PHP 8.2 + MySQL on WAMP/Apache. **No Composer, no build step, no
   autoloader.** Every module is `require`d on every request.
2. **Desktop agent** — Python + PySide6 tray app. Unchanged by this migration.

There is no framework. `bootstrap.php` is the kernel and a ~50-line `Router` class
is the routing layer.

## 2. Request lifecycle

```
error_reporting(E_ALL & ~E_DEPRECATED)
  ↓ require helpers.php
  ↓ $CONFIG = config.php  (falls back to config.example.php in dev)
  ↓ $BASE_PATH from SCRIPT_NAME        — works under any subdirectory
  ↓ request_is_https()                 — direct, X-Forwarded-Proto, or :443
  ↓ 301 → https   (live only; localhost/127.0.0.1/::1 exempt)
  ↓ send_security_headers()            — CSP, XCTO, XFO, Referrer, Permissions, HSTS
  ↓ session config + session_start()
  ↓ require ×16 modules
  ↓ attribution_capture()              — first-touch UTM, before any handler
  ↓ Router::dispatch(request_method(), current_route_path())
```

Ordering is load-bearing: headers must precede `session_start()` and any output.

### Router

- `{param}` placeholders compiled to `(?P<name>[^/]+)`; first match wins.
- **`HEAD` dispatches to the `GET` handler** (RFC 9110). Routing HEAD separately used
  to 404 for crawlers, link checkers, uptime monitors and social unfurlers.
- `request_method()` honours a `_method` POST field for `PATCH`/`DELETE` from forms.
- No match → `abort(404)`.

### Base path

`$BASE_PATH = dirname($_SERVER['SCRIPT_NAME'])`. Dev runs at
`http://localhost/deskpulsev2/server/public/`; production docroot is `public_html/`
with `src/` and `templates/` **above** it. Laravel must keep working from a
subdirectory — do not hardcode a domain root.

## 3. Modules (`server/src/`) — 14,559 lines

| Module | LOC | Responsibility |
|---|---|---|
| `dashboard.php` | 4,986 | every `/app/*` page |
| `payroll.php` | 1,315 | pay run, adjustments, PTO, payouts, payslips |
| `helpers.php` | 991 | escaping, urls, csrf, formatting, xlsx/csv readers, crypto |
| `marketing.php` | 875 | landing pages, SEO, attribution |
| `db.php` | 847 | PDO, auto-schema, ~700 lines of runtime migrations |
| `reports.php` | 797 | period engine (tz + pay cycles), rollups, cost, CSV |
| `wise.php` | 629 | payout API client, SCA, webhook verification |
| `auth.php` | 584 | register/login/reset, guards, capability matrix, webhook HMAC |
| `mailer.php` | 542 | outbox, hand-rolled SMTP, PHP `mail()` |
| `messaging.php` | 468 | messages, reminders, notices, email log |
| `oauth.php` | 462 | OIDC federated sign-in, enterprise SSO |
| `content.php` | 425 | markdown CMS (`server/content/`) |
| `payments.php` | 379 | subscriptions, invoices, reconciliation |
| `webhooks.php` | 328 | agent ingest |
| `pdf.php` | 308 | hand-rolled PDF 1.4 writer |
| `remote.php` | 278 | remote desktop control |
| `share.php` | 137 | public read-only share pages |

`server/src/archive/stripe.php` is never `require`d — Stripe was replaced by direct
bank transfer. Do not port it.

> `dashboard.php` at 4,986 lines is one file holding ~56 page handlers. It is the
> single biggest decomposition job in the migration and maps to roughly 20 Laravel
> controllers (the migration plan §52).

## 4. Deliberate "no dependency" choices

The codebase repeatedly hand-rolls what a package would provide. Each is a migration
decision, because Laravel *does* have Composer:

| Hand-rolled | Where | Laravel option |
|---|---|---|
| xlsx reader (ZipArchive + DOM) | `helpers.php` `xlsx_rows()` | PhpSpreadsheet (the migration plan §22) |
| CSV reader matching the xlsx row shape | `helpers.php` `csv_rows()` | League\Csv |
| PDF 1.4 writer with base-14 metrics | `pdf.php` `DpPdf` | dompdf / tcpdf |
| SMTP client (STARTTLS, AUTH LOGIN, MIME) | `mailer.php` | Laravel Mail |
| AES-256-GCM encrypt/decrypt | `helpers.php` `dp_encrypt()` | Laravel `Crypt` |
| MySQL dump | `dash_platform_export` | `DatabaseExportService` |
| Router | `bootstrap.php` | Laravel routing |

**Caution on the readers.** `xlsx_rows()`/`csv_rows()` return rows keyed by 1-based row
number and cells by **column letter**, and headers are matched loosely via
`sheet_norm_label()` scanning the first 60 rows. Swapping in PhpSpreadsheet changes
the row shape every importer depends on, and must reproduce the loose header matching
or real payroll files stop importing. See `payroll.md`.

Chart.js **is** used — vendored at `public/assets/js/vendor/chart.umd.js`, loaded
same-origin because the CSP blocks external scripts. The migration plan §39 calls the charts
"dependency-free"; that claim is outdated and the file itself says so.

## 5. Frontend

No build step today. `deskpulse.css` (1,182 lines) plus 8 JS modules (2,599 lines
total incl. the vendored chart bundle):

| File | LOC | Role |
|---|---|---|
| `tables.js` | 397 | auto-enhances every `table.data` — filters, sort, counter, CSV, responsive wrapper |
| `charts.js` | 383 | `<canvas class="dp-chart">` → Chart.js |
| `remote.js` | 162 | remote-control viewer |
| `hero-globe.js` | 163 | marketing hero |
| `dashboard.js` | 125 | UTC→local time rendering, `dp_tz` cookie |
| `live.js` | 85 | live view polling |
| `platform.js` | 60 | platform console |
| `reveal.js` | 28 | scroll reveal |

`tables.js` and `charts.js` are cross-cutting behaviour, not page decoration —
see `ui-inventory.md`.

## 6. Storage

| Path | Web-reachable | Contents |
|---|---|---|
| `server/public/uploads/` | **yes** | screenshots, org logos, import temp |
| `server/storage/` | no (above docroot) | payslip PDFs, payment receipts |
| `server/sessions/` | no (+ deny `.htaccess`) | PHP session files |
| `server/content/` | no | markdown for the CMS |

**Screenshots are world-readable to anyone holding the URL.** `public/.htaccess`
serves any real file it finds. The code comments acknowledge this and route payslips
and receipts to private storage *because* of it. See `screenshots.md` and
`security.md`.

## 7. Laravel destination

| Current | Laravel |
|---|---|
| `bootstrap.php` | framework kernel + middleware stack |
| `Router` | `routes/web.php`, `routes/agent.php` |
| 16 `require`s | PSR-4 autoloading |
| `dashboard.php` | ~20 controllers + services (the migration plan §52, §54) |
| `templates/*.php` | `resources/views/**.blade.php` |
| `deskpulse.css` + inline styles | Tailwind 4 via Vite |
| `config.php` | `.env` + `config/deskpulse.php` |
| `send_security_headers()` | middleware — **and** `.htaccess` parity for static files |
| `attribution_capture()` | middleware |
| PDO helpers | Eloquent |

### Framework behaviour the legacy code does not have

Laravel runs middleware on every request that PHP's `$_POST` never did. These
are not bugs to fix; they are differences to port against, and each one has
already changed a result during the migration.

| Middleware | What changes | Where it bit |
|---|---|---|
| `ConvertEmptyStringsToNull` | A blank form field arrives as `null`, not `''` | A legacy `($_POST['x'] ?? '') !== ''` test becomes true for every blank field. On the Clients page that wrote `0.00` into a contract instead of inheriting the client's rate. Cast to string before comparing: `(string) $request->input('x', '') !== ''` |
| `TrimStrings` | Leading and trailing whitespace is gone before the handler sees it | A `trim()` in ported code is now redundant, never wrong. Keep it — it documents the intent and survives the middleware being skipped for a route |

Eloquent adds a third, of the same shape: an attribute **cast** hands back an
object where PDO handed back a string. `organizations.pay_cycle_anchor` is cast
to a `Carbon`, and concatenating it with `' 12:00:00'` the way the legacy code
concatenates the raw column produced `"2026-01-05 00:00:00 12:00:00"` — which
`strtotime()` parses into a different date and silently shifts every pay period
by days. Port date arithmetic against `getRawOriginal()`, or cast explicitly.

## 8. Required tests

- App serves correctly from a subdirectory (`/deskpulsev2/...`), not just a root domain.
- `HEAD` on every public route returns the same status as `GET`.
- Security headers present on HTML responses and on static assets.
- HTTPS redirect fires on a proxied request (`X-Forwarded-Proto: https`) and never on localhost.
- Session cookie: name `deskpulse`, `HttpOnly`, `SameSite=Lax`, `Secure` only over HTTPS.
- `_method` override still drives PATCH/DELETE from forms.

## 9. Migration risks

| Risk | Severity | Note |
|---|---|---|
| Replacing `xlsx_rows()` with PhpSpreadsheet unverified | **High** | Row/cell shape and loose header matching are depended on by every importer |
| Assuming a domain root | Medium | Breaks WAMP dev and the production `public_html` layout |
| Losing `HEAD`→`GET` | Medium | 404s for crawlers, uptime monitors, unfurlers |
| Porting `archive/stripe.php` | Low | Intentionally dead |
| Keeping runtime auto-migration | Medium | DDL during a web request |
| Treating charts as dependency-free | Low | Chart.js is vendored and required |
| Splitting `dashboard.php` without a route inventory | **High** | 56 handlers; easy to drop pages silently — see `routes.md` |
