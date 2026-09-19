# Security — current posture

Source of truth: `server/src/bootstrap.php`, `server/src/auth.php`,
`server/src/helpers.php`, `server/public/.htaccess`.

The existing app is **deliberately hardened in most places**. The migration must not
regress what is already there, and should treat the known gaps in §3 as explicit,
approved decisions rather than silent changes — the user's constraint is that nothing
changes except the stack.

## 1. Controls that already exist — preserve all of them

### Transport

- Forced HTTPS 301 on live hosts; **localhost / 127.0.0.1 / ::1 exempt** so WAMP dev
  keeps working on plain http.
- `request_is_https()` detects direct TLS, `X-Forwarded-Proto`, and port 443 — one
  shared helper so `public_base_url()` cannot disagree with the redirect (they used to).
- HSTS `max-age=31536000; includeSubDomains; preload`, **only** over real HTTPS.

### Response headers

Emitted by `send_security_headers()` **and** duplicated in `public/.htaccess` for
static files Apache serves without invoking PHP. Both carry a "keep in sync" comment.

```
Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none';
  frame-ancestors 'none'; img-src 'self' data: <ga/clarity>;
  style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline' <gtm/clarity>;
  font-src 'self'; connect-src 'self' <ga/clarity/bing>; form-action 'self'
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: geolocation=(), camera=(), microphone=(), interest-cohort=()
```

### Sessions

- Saved to `server/sessions/` — **above the docroot**, plus a deny-all `.htaccess`.
  The shared-hosting default save_path was dropping sessions and breaking CSRF.
- `session_name('deskpulse')`, `gc_maxlifetime` 86400.
- Cookie: `HttpOnly`, `SameSite=Lax`, `Secure` only over HTTPS, `path` = base path.

### CSRF

```php
csrf_token()  // $_SESSION['csrf'] = random_token(24)
csrf_field()  // <input type="hidden" name="_csrf" …>
check_csrf()  // hash_equals($_SESSION['csrf'], $_POST['_csrf']) else abort(400)
```

Constant-time compare; 400 on mismatch. `/webhooks/*` is HMAC-authenticated and
correctly outside CSRF.

### Agent authentication

HMAC-SHA256 over the raw body, `hash_equals()` comparison, per-device secret from
`random_bytes(32)`. See `api-contract.md` §1.

### Secrets at rest

`dp_encrypt()` / `dp_decrypt()` — **AES-256-GCM**, key derived from `app_secret`,
stored as `v1:<base64 iv|tag|ciphertext>`. Applied to the Wise API token and the SCA
private key, explicitly so they do not sit in plaintext columns that every DB backup
and the platform SQL export would carry. Rotating `app_secret` invalidates them by
design. Masked in the UI; a blank submit keeps the stored value rather than wiping it.

### Private file storage

`server/storage/` sits above the docroot and holds payslip PDFs and payment receipts.
`private_path()` also writes a deny-all `.htaccess` as a second layer. Receipt MIME is
taken from `finfo`, not the filename. Verified behaviour: direct filesystem URL 403,
authorized route 200, logged-out 302.

### Other

- `Options -Indexes`; dotfiles denied via `<FilesMatch "^\.">`.
- Passwords via `password_verify()` / PHP's hasher.
- `must_change_password` forces a reset on first login, enforced in `require_login()`.
- Tenant isolation on ingest is fail-quiet: a foreign `client_id`/`task_id` stores
  `NULL` rather than erroring.
- Payment claims: `confirm_payment_claim()` refuses a non-`pending` claim, so a
  double-click cannot bank the same money twice.
- Wise: RSA-SHA256 delivery verification, `X-Delivery-Id` dedup via `webhook_events`,
  and a 410 kill-switch while on bank transfer.

## 2. Verification already performed by the existing team

Recorded in the production system notes session logs — useful as a regression baseline:

- Encryption round-trips; fails under a rotated `app_secret`; ciphertext never contains
  the plaintext; two encryptions differ.
- Wise webhook: valid signature 200, replayed delivery id deduped, **tampered body 401**,
  missing signature 401 when a key is set.
- Payslip PDFs return **403** at their direct URL.
- Every route smoke-tested as each of the 7 roles.

## 3. Known gaps — carry them forward as decisions, not accidents

### 3.1 Screenshots are world-readable — **highest-severity finding**

`public/.htaccess` serves any real file it finds:

```apache
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]
```

Screenshots are written to `public/uploads/{user_id}/{session_id}/{16 hex}.{ext}`, so
**anyone with the URL can fetch any screenshot, unauthenticated, cross-tenant.** The
codebase knows: the payslip comment says files go to private storage *"because
`public/.htaccess` serves any real file it finds, so anything under `public/uploads/`
is world-readable to anyone with the URL (this is already true of screenshots)"*.

Mitigation today is only the 16-hex-byte random filename — unguessable, but permanent,
shareable, and exposed by any referrer leak, proxy log or browser history sync.

The migration plan §24 already requires fixing this (private disk + authorized controller).
It is the one place where "change nothing" and "do not regress security" conflict.

> **Recommendation:** fix it during Phase 9. Screenshots move to a private disk served
> through an authorized controller. This changes no *visible* behaviour — images still
> render on `/app/screenshots` — only the URL shape. Needs explicit approval because
> existing deep links would stop resolving. Org logos can stay public.

### 3.2 No replay protection on agent webhooks

No nonce, timestamp or window. A captured signed request replays indefinitely, and
the offline queue replays legitimately (see `agent-protocol.md` §5). Adding rejection
would break the queue; adding a timestamp window requires an agent change, which is
out of scope.

### 3.3 CSP keeps `unsafe-inline` for script and style

Deliberate — the app uses inline `<style>`, `<script>` and inline event handlers
heavily. Noted in the source as a pragmatic choice pending nonces.

> Migrating to Tailwind + Vite compiles styles and scripts into same-origin bundles,
> which makes dropping `unsafe-inline` achievable *if* inline handlers are ported to
> listeners. Worth taking, but it is a real behaviour change and must be verified
> page by page — a missed inline handler silently stops working under a strict CSP.

### 3.4 No audit trail

`/app/audit` is derived at read time from six existing timestamp sources; nothing is
written. Logins, role changes, rate changes, billing changes, screenshot access,
payroll imports and deletions are **not recorded**. See `database.md` §5.
The migration plan §32 is net-new work, not a migration.

### 3.5 Money in `double`

`users.pay_rate`, `users.bill_rate` and most org pricing are `double` — contradicting
The migration plan §82 rule 17. See `database.md` §3.

### 3.6 Unverified Wise deliveries are accepted when no key is configured

A deliberate tradeoff so a real payment is never dropped mid-setup; surfaced as an
alert, and an unreconciled credit only ever creates an *unmatched* row. Until the key
is set, the endpoint is an unauthenticated write. Currently moot — 410 while on bank
transfer.

### 3.7 Device registration is unthrottled and unbounded

`POST /webhooks/auth` inserts a new `devices` row per call with no dedup and no rate
limit. Valid credentials can mint unlimited devices. The migration plan §46 asks for a
registration limit; adding one is a behaviour change (the agent does not expect 429).

## 4. Laravel destination

| Current | Laravel |
|---|---|
| `send_security_headers()` + `.htaccess` | middleware, **plus** static-file parity |
| `check_csrf()` | `VerifyCsrfToken`, `/webhooks/*` excluded |
| `dp_encrypt()` AES-256-GCM | `Crypt` — must decrypt existing `v1:` values during cutover, or re-enter |
| session hardening | `config/session.php` |
| `private_path()` | `Storage::disk('private')` |
| `public/uploads/` screenshots | private disk + authorized controller (§3.1) |
| HMAC verify | `VerifyDeskPulseSignature` |
| `must_change_password` | middleware |

> **Cutover note on encrypted values.** Existing `v1:` ciphertexts were produced with a
> key derived from `app_secret`, not Laravel's `APP_KEY`. Either port `dp_decrypt()` to
> read legacy values, or plan to re-enter the Wise token and SCA key after cutover.
> Laravel's `Crypt` will not read them as-is.

## 5. Required tests

- **Tenant isolation** across transitive tables — Org A cannot read Org B's sessions,
  screenshots, activity, windows, processes, idle periods or devices.
- **Role isolation** — the full 7 × 28 matrix in `authorization.md`.
- **HMAC** — valid, invalid, wrong device, tampered body, missing headers, empty-body
  signature; replay asserted to current behaviour.
- **Screenshots** — authorized, unauthorized, wrong tenant, wrong user; and, if §3.1 is
  taken, that the direct storage URL 403s.
- **Public shares** — valid, expired, revoked, wrong token, salary hidden.
- **CSRF** — missing/invalid `_csrf` → 400; `/webhooks/*` exempt.
- **Headers** — present on both HTML responses and static assets.
- **Secrets** — no plaintext Wise token in the SQL export; `.env`, `config.php`,
  device secrets and payroll sheets never committed.
- **Rate limits** — auth/registration/share limited; **agent ingest not** (a 429 is
  indistinguishable from failure and triggers retry).

## 6. Migration risks

| Risk | Severity | Note |
|---|---|---|
| Carrying screenshots forward into a public disk | **Critical** | Reproduces a cross-tenant unauthenticated read |
| CSRF middleware applied to `/webhooks/*` | **Critical** | Breaks every agent call |
| Laravel `Crypt` cannot read `v1:` ciphertext | **High** | Wise token and SCA key unreadable after cutover |
| Global rate limiter covering agent ingest | **High** | 429 → queue-and-retry storm after any outage |
| Dropping `unsafe-inline` without porting inline handlers | Medium | Silent JS breakage |
| Forgetting `.htaccess`/static-file header parity | Medium | Assets served under a weaker policy |
| Treating `/app/audit` as an existing audit trail | Medium | No history exists to migrate |
| Adding replay/nonce protection | Medium | Breaks the legitimate offline queue |
