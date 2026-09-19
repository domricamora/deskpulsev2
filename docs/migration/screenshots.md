# Screenshots

Source of truth: `server/src/webhooks.php` (`wh_screenshot`), `server/src/dashboard.php`
(`dash_screenshots`), `server/templates/dashboard/screenshots.php`,
`purge_old_screenshots()`, `agent/monitor/screenshot.py`.

## 1. Capture — the agent decides, the server enforces

The agent reads `GET /webhooks/policy` and honours `screenshot_interval_min`,
`screenshot_blur` and `track_screenshots`. **Blur is applied on the agent**, before
upload — the server stores whatever it receives and records the flag. There is no
server-side blur, so nothing moves into a job (the migration plan §25).

Policy is a *request*, not a control. A stale or modified agent can post anyway, so
ingest re-checks the plan and answers **402** when screenshots are not included. That
refusal is the actual enforcement.

## 2. Upload

```
POST /webhooks/session/{id}/screenshot?ext=png&ts=<iso>&blurred=0[&monitor=N]
body = raw image bytes
```

Raw bytes, metadata in the query string — so the HMAC covers the same raw body as
every other call. Multipart would empty `php://input` and break signing.

| Check | Failure |
|---|---|
| Plan includes screenshots | 402 `screenshots are not included on this plan` |
| Non-empty body | 400 `missing image` |
| `ext` ∈ png/jpg/jpeg/webp | 400 `unsupported image type` |
| Session owned by the device's user | 404 |

Stored at:

```
server/public/uploads/{user_id}/{session_id}/{16 random hex}.{ext}
```

Row: `screenshots(session_id, ts, file_path, blurred, monitor)`. `blurred` is truthy
only for `'1' | 'true' | 'True'`. `monitor` is an optional index for multi-monitor
setups, `null` for single-screen.

The agent swallows all screenshot errors (`except Exception: pass`) — a 402 or a
network failure never interrupts tracking, and screenshots are **never queued**.

## 3. Viewing

`/app/screenshots` — capability `screenshots`, scoped by `visible_user_ids()`.

- Filters: `?user_id=N` and `?date=YYYY-MM-DD`.
- The date filter is cut in the **org's reporting timezone** and converted to UTC
  instants (`local_to_utc()`), because `screenshots.ts` is UTC.
- `LIMIT 200`, newest first.
- The member dropdown is built from the **full** visible scope, not the filtered set —
  a fixed bug: narrowing first left the filtered person as the only selectable option.
- The dropdown renders only for `is_manager()`; note `client_viewer` holds `view_team`
  and therefore counts as a manager here, scoped to its own client's agents.

## 4. The access-control gap — highest-severity finding

The page is authorized. **The images are not.**

```php
<a href="<?= e(url('/uploads/' . $sc['file_path'])) ?>" target="_blank">
  <img src="<?= e(url('/uploads/' . $sc['file_path'])) ?>" loading="lazy" alt="screenshot">
```

`public/.htaccess` serves any real file directly:

```apache
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]
```

So **every screenshot is fetchable by anyone with the URL** — unauthenticated, and
across tenants. No session, no capability, no org check. The only protection is a
16-byte random filename.

The codebase is aware: payslips and payment receipts were deliberately routed to
`server/storage/` (above the docroot) with the comment *"because `public/.htaccess`
serves any real file it finds, so anything under `public/uploads/` is world-readable
to anyone with the URL (**this is already true of screenshots**)."*

Why it matters more than an unguessable URL suggests: the URL is permanent and
reusable, and leaks through referrer headers, proxy and CDN logs, browser history
sync, and anyone a link is forwarded to. It is a capability URL for the most sensitive
data the product holds.

The migration plan §24 already mandates the fix: private disk + authorized controller.

> **Recommendation — take this in Phase 9.** Screenshots move to
> `storage/app/private/screenshots/{org}/{user}/{date}/` and are served through an
> authorized controller. Visible behaviour is unchanged (images still render on the
> page); only the URL shape changes, so existing deep links stop resolving.
>
> This is the one place where "nothing else changes" and "do not regress security"
> genuinely conflict, so it needs the user's explicit call. **Org logos can stay
> public** — they are not sensitive and are also served to the agent.

## 5. Retention

```php
purge_old_screenshots(int $limit = 500)
  $days = screenshot_retention_days();      // platform-wide, on the platform org row
  if ($days <= 0) return 0;                 // 0 = keep forever
  // delete file from disk, then the row, oldest first, capped at $limit
```

- Default **30 days** (`organizations.screenshot_retention_days`), set on the
  super-admin's platform org and governing **every** org.
- Triggered opportunistically: **1 upload in 50** runs `purge_old_screenshots(100)`.
  There is no cron. Retention therefore depends on upload volume — a quiet org's
  screenshots persist past the window until enough uploads occur.

> Laravel should move this to the scheduler. That is a behaviour change (retention
> becomes timely rather than volume-driven) but strictly in the privacy-positive
> direction and consistent with the stated policy. Flag it; do not do it silently.

## 6. Laravel destination

| Current | Laravel |
|---|---|
| `wh_screenshot()` | `ScreenshotIngestService` |
| `public/uploads/...` | `Storage::disk('private')` + `ScreenshotController@show` (§4) |
| `dash_screenshots()` | `ScreenshotController@index` + policy |
| `purge_old_screenshots()` | `PruneScreenshots` scheduled command |
| agent-side blur | unchanged — no server processing |

Keep the raw-body upload. Do not introduce multipart, resizing, re-encoding or
thumbnailing: the agent controls format, and any re-encode invalidates the stored
bytes the HMAC was computed over.

## 7. Required tests

Per the migration plan §60 — authorized, unauthorized, wrong tenant, wrong user:

- `screenshots` capability required for `/app/screenshots`; `hr_manager` refused.
- A manager sees only their team's shots; `client_viewer` only its client's agents.
- Org A cannot read Org B's screenshot, **including by direct file URL** (this is the
  test that fails today and must pass after §4).
- Date filter bucketed in `report_tz`, not UTC or server-local.
- Member dropdown lists the full visible scope even when filtered.
- Upload: 402 on solo, 400 on empty body, 400 on bad `ext`, 404 on a foreign session.
- `blurred` truthiness matches `'1'|'true'|'True'` exactly.
- Retention deletes both the row and the file; `days = 0` keeps forever.
- The agent ignores a 402 and keeps tracking.

## 8. Migration risks

| Risk | Severity | Note |
|---|---|---|
| Carrying screenshots into a public disk | **Critical** | Reproduces unauthenticated cross-tenant read |
| Serving via `Storage::url()` on a public disk | **Critical** | Same defect, new syntax |
| Converting the upload to multipart | **High** | Empties the raw body, breaks HMAC |
| Re-encoding or thumbnailing on ingest | Medium | Invalidates bytes; agent owns format and blur |
| Moving retention to cron silently | Medium | Behaviour change — privacy-positive but must be stated |
| Applying server-side blur | Medium | Blur is an agent responsibility today |
| Dropping the `monitor` column | Low | Multi-monitor captures would collapse |
