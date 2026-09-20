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

> **DONE in Phase 9 (decision D4).** Screenshots are on a private disk and are
> served through `ScreenshotController::image()`. Visible behaviour is
> unchanged — images still render everywhere they used to — and only the URL
> shape changed, so existing deep links stop resolving. **Org logos stayed
> public**: they are not sensitive and are also served to the agent.

### As built

| | |
|---|---|
| Stored at | `storage/app/private/screenshots/{user_id}/{session_id}/{16 hex}.{ext}` |
| Disk | `private` in `config/filesystems.php`, `serve => false` and **no `url` key**, so `Storage::url()` fails rather than quietly returning a public path |
| Served by | `GET /app/screenshots/{id}/image` |
| Existing files | `php artisan screenshots:relocate` moves them, idempotently |

The relative path inside `screenshots.file_path` is **unchanged**. Only the root
moved, because rows already in the live database hold that path and rewriting
them at cutover would be a second migration with nothing to gain.

The two gates are deliberately different, and getting this wrong blanks a panel
or opens a hole:

- **The gallery** requires the `screenshots` capability, exactly as
  `require_cap('screenshots')` does. An HR manager is refused.
- **An image** requires only that the viewer can see the person in it
  (`Visibility::userIds()`). It must **not** require the capability, because
  `/app/overview` loads `$recentShots` with no capability check at all — so a
  member, who holds nothing, and an HR manager, who is refused the gallery, both
  see screenshots there. Requiring the capability on the image route would empty
  those panels, which is a visible change this migration is not making.

A screenshot outside the viewer's scope answers **404, not 403** — a 403 confirms
the id exists, which is the kind of leak this route was built to close.

> **Cutover note.** `screenshots:relocate` also counts image files under the
> public uploads tree that have **no** screenshot row — leftovers from deleted
> rows and re-seeds, still world-readable by URL. It reports them and deletes
> nothing, because it cannot tell a forgotten screenshot from a file somebody
> put there on purpose. Clear them by hand as part of the cutover.

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

> **DONE in Phase 9 (decision D12).** `screenshots:prune` runs nightly at 03:20.
> This is a behaviour change, stated rather than slipped in: retention becomes
> timely instead of volume-driven, so a quiet organization's images now go when
> the window says rather than whenever somebody happens to upload enough.
>
> The opportunistic 1-in-50 purge on upload is **kept as well**. It costs
> nothing, and on shared hosting — which is what production is — there is no
> guarantee anyone has wired up `schedule:run`.

## 6. Laravel destination

| Current | Laravel |
|---|---|
| `wh_screenshot()` | `ScreenshotIngest::store()` |
| `public/uploads/...` | `private` disk + `ScreenshotController::image()` (§4) |
| `dash_screenshots()` | `ScreenshotController::index()` + `cap:screenshots` |
| `purge_old_screenshots()` | `ScreenshotIngest::purge()`, on upload **and** `screenshots:prune` nightly |
| — | `screenshots:relocate`, the one-time move off the docroot |
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
