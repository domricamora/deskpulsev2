# API contract — FROZEN

> **Standing constraint (user, this session):** the desktop agent for Windows, macOS
> and Linux **stays exactly as it is** and must keep pointing at the same API.
> Therefore every route, header, payload shape, status code and signature rule on
> this page is **frozen**. Laravel must serve these byte-for-byte.
>
> This **overrides the migration plan §12 and §57**, which propose moving the agent to
> `/api/v1/...`. There is no `/api/v1` today and there must not be one for the agent.
> A versioned namespace may be added later *in addition*, never as a replacement.

Source of truth: `server/public/index.php` (routing), `server/src/webhooks.php`
(handlers), `server/src/auth.php` (`verify_webhook()`), `agent/webhook_client.py`
(the only client).

## 1. Authentication

### Registration — the one unsigned call

```
POST /webhooks/auth
Content-Type: application/json
{"email": "...", "password": "...", "device_name": "..."}
```

| Outcome | Status | Body |
|---|---|---|
| OK | 200 | `{"device_id": <int>, "secret": "<64 hex>", "user": {"id","name","email"}}` |
| Bad credentials | 401 | `{"error":"invalid credentials"}` |
| `super_admin` | 403 | `{"error":"platform operators cannot run the tracker"}` |

- `secret` = `bin2hex(random_bytes(32))` → 64 lowercase hex chars.
- **Not idempotent.** Every call `INSERT`s a **new** `devices` row. Registering twice
  yields two devices. Preserve this — the agent stores whichever it was last given.
- Platform operators are refused outright.

### Every other call — HMAC-SHA256

```
X-DeskPulse-Device:    <devices.id>
X-DeskPulse-Signature: hash_hmac('sha256', <raw request body>, devices.secret)
```

Server (`verify_webhook()`):

```php
$expected = hash_hmac('sha256', raw_body(), $device['secret']);  // lowercase hex
if (!hash_equals($expected, $signature)) abort(401, 'bad signature');
db_exec('UPDATE devices SET last_seen = NOW() WHERE id = ?', [$device['id']]);
```

Rules that must not drift:

1. **Hex digest, lowercase.** Not base64. Agent uses `.hexdigest()`.
2. **Signed over the raw body**, read once via `file_get_contents('php://input')`.
   Laravel must sign/verify `$request->getContent()` — never a re-encoded array.
   Re-serializing JSON changes bytes and breaks every signature.
3. **The empty body is signed too.** `GET`/`DELETE` sign `b""`; the agent always sends
   the header. `hash_hmac` of `''` is a valid, required signature.
4. `hash_equals()` — constant time. Keep it.
5. Every verified call stamps `devices.last_seen`.

| Failure | Status | Body |
|---|---|---|
| No `X-DeskPulse-Device` | 401 | `{"error":"missing device header"}` |
| Device id not found | 401 | `{"error":"unknown device"}` |
| Digest mismatch | 401 | `{"error":"bad signature"}` |

> **No replay protection exists.** There is no nonce, timestamp or window; a captured
> request replays forever. The migration plan §60 asks for a replay test — today replay is
> *accepted*. Adding rejection is a behaviour change; see `security.md`.

> **`last_seen` uses `NOW()`, not `UTC_TIMESTAMP()`** — the only place in the ingest
> path on server-local time while everything else is UTC. Replicating the bug keeps
> parity; fixing it shifts displayed "last seen". Decide deliberately.

## 2. Endpoints

All paths are relative to the agent's configured `server_url`, which already includes
the app base path. Response is always JSON; `abort()` emits JSON whenever the URI
contains `/webhooks/`.

### Bootstrap

| Method | Path | Body | Response |
|---|---|---|---|
| `GET` | `/webhooks/me` | — | `{user:{id,name,email,role,org_id}, device_id, org:{name,logo_url}, schedule:{work_start,work_end,work_days[]}}` |
| `GET` | `/webhooks/clients` | — | `[{id,name}]` — org's non-archived clients, by name |
| `GET` | `/webhooks/policy` | — | `org_policy(org_id)` — monitoring policy |

- `/webhooks/me` returns **401 `account no longer exists`** if the user row is gone —
  this is how the agent detects a revoked account.
- `org.logo_url` is `/uploads/<logo_path>` or `null`; the agent prepends `server_url`.
- `schedule.work_days` is an int array of ISO weekdays (1=Mon … 7=Sun), used by the
  agent to auto-start/stop tracking.

### Tasks

| Method | Path | Body | Response |
|---|---|---|---|
| `GET` | `/webhooks/tasks` | — | `[{id,title,client_id,status}]` — **open only**, newest first |
| `POST` | `/webhooks/tasks` | `{title, client_id?}` | `{task_id, title}` |
| `DELETE` | `/webhooks/tasks/{id}` | — | `{"ok":true}` |

- Empty `title` → **400 `title required`**. Title truncated to 240 chars.
- `DELETE` is scoped `WHERE id=? AND user_id=?` and returns `{"ok":true}`
  **even when nothing was deleted**. Do not "improve" this into a 404.

### Session lifecycle

| Method | Path | Body | Response |
|---|---|---|---|
| `POST` | `/webhooks/session` | `{client_id, task_id, started_at}` | `{session_id}` |
| `PATCH` | `/webhooks/session/{id}` | `{ended_at, active_s, inactive_s}` | `{"ok":true}` |
| `POST` | `/webhooks/session/{id}/task` | `{task_id}` | `{"ok":true, task_id}` |

`POST /webhooks/session` behaviour, in order:

1. **Closes every stale open session** for that user:
   `UPDATE sessions SET ended_at = COALESCE(last_seen_at, started_at) WHERE user_id=? AND ended_at IS NULL`
2. Validates `task_id` / `client_id` — see "silent null" below.
3. Inserts with `source='agent'`, `approval_status='approved'`, `last_seen_at=UTC_TIMESTAMP()`.

`PATCH` closes the session with final totals, then calls
`recompute_overtime(user_id, started_at - 1 day)` — splitting activity into regular vs
overtime, which then waits on HR (`approve_overtime`). **The overtime recompute is part
of the ingest path**, not a report-time calculation.

### Monitoring data

| Method | Path | Body |
|---|---|---|
| `POST` | `/webhooks/session/{id}/activity` | `{samples:[{ts,keyboard,mouse,pct}], active_s?, inactive_s?}` |
| `POST` | `/webhooks/session/{id}/windows` | `{windows:[{ts,app,title,focus_seconds}], processes:[{ts,app,pid}]}` |
| `POST` | `/webhooks/session/{id}/idle` | `{periods:[{start,end}]}` |

All respond `{"ok":true}`. Field truncation: `app` 160, `title` 400, process `app` 200.
`idle.duration_s` is **computed server-side** as `end - start`, floored at 0 — the agent
does not send it.

`activity` also refreshes live totals, but only while open:

```sql
UPDATE sessions SET active_s=?, inactive_s=? WHERE id=? AND ended_at IS NULL
```

The `ended_at IS NULL` guard exists so a replayed offline batch cannot clobber the
finalized totals written at stop. **Keep this guard** — it is the one piece of replay
safety in the system.

### Screenshots

```
POST /webhooks/session/{id}/screenshot?ext=png&ts=<iso>&blurred=0[&monitor=N]
<raw image bytes as the body>
```

Metadata travels in the **query string** and the image is the **raw body** —
deliberately, so the HMAC covers the same raw bytes as every other call. Multipart
would empty `php://input` and break signing. **Do not convert this to multipart.**

| Outcome | Status |
|---|---|
| OK | 200 `{"screenshot_id":<int>}` |
| Plan excludes screenshots | **402** `screenshots are not included on this plan` |
| Empty body | 400 `missing image` |
| `ext` not png/jpg/jpeg/webp | 400 `unsupported image type` |

- 402 is deliberate: authenticated and well-formed, but not covered by the plan.
  `org_policy()` asks the agent not to capture; **ingest refusal is the real
  enforcement** against a stale or modified agent.
- Stored at `uploads/{user_id}/{session_id}/{16 hex}.{ext}`.
- `blurred` is truthy for `'1'|'true'|'True'` only.
- 1-in-50 uploads opportunistically run `purge_old_screenshots(100)` for retention.

### Session ownership + heartbeat

Every `/webhooks/session/{id}/*` call runs `wh_owned_session()`:

- `404 session not found` if the session is missing **or owned by another user**.
- If still open, stamps `last_seen_at = UTC_TIMESTAMP()`. This heartbeat is what keeps
  a session from being auto-closed as stale, and drives the live view.

### Remote control

`GET /webhooks/remote/poll`, `POST /webhooks/remote/{id}/frame`,
`POST /webhooks/remote/{id}/end` — same HMAC scheme, never queued. See
`remote-control.md`.

### Wise (not the agent)

`POST /webhooks/wise` shares the `/webhooks/` prefix but is a **payment provider**
callback using RSA-SHA256, not device HMAC. Currently returns **410 Gone** while
`pay_method='bank'`. See `billing.md`.

## 3. Validation is permissive — preserve it

`wh_validate_task()` / `wh_validate_client()` return **`null`** when the id does not
belong to the caller's user/org. They do **not** error.

Consequence: posting another org's `client_id` silently stores `NULL` rather than
returning 403. This is the tenant-isolation boundary for ingest, and it is
fail-quiet by design. A Laravel `exists:` validation rule returning 422 here would
**break the agent**, whose queued calls retry on any non-2xx.

## 4. Status codes actually in use

| Code | Meaning here |
|---|---|
| 200 | every success (**no 201 anywhere** — do not "correct" this) |
| 400 | missing/invalid payload (`title required`, `missing image`, bad `ext`) |
| 401 | auth failure — bad credentials, missing header, unknown device, bad signature, account gone |
| 402 | plan does not include screenshots |
| 403 | `super_admin` may not register a device |
| 404 | session not found or not owned |
| 410 | `/webhooks/wise` while on bank transfer |

**422 and 429 are never returned to the agent.** Introducing them changes retry
behaviour — see `agent-protocol.md` §4.

## 5. Laravel destination

| Current | Laravel |
|---|---|
| `routes` in `public/index.php` | `routes/agent.php`, prefix `/webhooks`, **no `/api/v1`** |
| `verify_webhook()` | `app/Http/Middleware/VerifyDeskPulseSignature.php` |
| `wh_*()` handlers | `Api/WebhookController` (thin) → `app/Services/Agent/*` |
| `json_out()` / `abort()` | response macro preserving the exact bodies above |
| `wh_owned_session()` | route-model binding scoped to the device's user + heartbeat |

Laravel specifics that will otherwise break the contract:

- Disable `ConvertEmptyStringsToNull` / `TrimStrings` on these routes — they mutate
  input the current handlers accept verbatim.
- Exclude `/webhooks/*` from CSRF.
- `VerifyCsrfToken`, `ValidateSignature` and the default exception renderer must not
  turn a failure into HTML — the agent calls `.json()`.
- Do not let `FormRequest` validation return 422; validate manually and mirror
  400/401/402/404.

## 6. Required tests

- Valid signature accepted; tampered body 401; wrong device 401; missing headers 401.
- Signature over an **empty** body accepted for `GET`/`DELETE`.
- Signature computed over raw bytes, verified against a body Laravel has not re-encoded.
- Replay of an identical signed request — assert current behaviour (accepted).
- Another user's `session_id` → 404.
- Another org's `client_id` → stored `NULL`, response still 200.
- Screenshot on a screenshot-less plan → 402, and the agent swallows it.
- `POST /webhooks/session` closes prior open sessions for that user.
- Agent session lands `source='agent'`, `approval_status='approved'`.
- `tools/test_webhook.py` passes unmodified against Laravel.

## 8. Phase 7 as built

Served from `routes/agent.php`, prefixed `/webhooks`, registered through
`withRouting(then: ...)` so it carries **no middleware group at all** — not
`api`, whose throttling would return 429, and not `web`, which would add
sessions and CSRF.

| Legacy | Laravel |
|---|---|
| `verify_webhook()` | `App\Http\Middleware\VerifyAgentSignature` |
| `wh_*()` handlers | `Agent\WebhookController` -> `App\Services\Agent\*` |
| `json_out()` / `abort()` | `App\Support\AgentResponse` |
| `wh_owned_session()` | `WebhookController::ownedSession()` — 404 plus heartbeat |
| `recompute_overtime()` | `App\Services\Reporting\Overtime` |
| `org_policy()` | `App\Services\Agent\MonitoringPolicy` |
| `upload_path()` | `App\Support\Uploads` |

Three framework behaviours are switched off for this prefix in
`bootstrap/app.php`, each of which would otherwise break the contract silently:
`TrimStrings`, `ConvertEmptyStringsToNull` and CSRF. Exception rendering is
forced to JSON for `webhooks/*`, so a 404 or a method mismatch cannot reach the
agent as an HTML page.

### The gate

`tools/test_webhook.py` passes **unmodified** against this implementation —
registration, session open, activity, windows, idle, a raw-body screenshot and
session close. The CI job that runs it (`legacy-webhook-contract`) was
`if: false` and is now enabled; it seeds `ava@demo.test / Demo12345` via
`DemoSeeder`, which is the account the frozen harness is invoked with.

### One deviation

**The screenshot plan check now works.** The legacy handler reads
`$device['org_id']`, and `devices` has **no `org_id` column** — it reaches its
tenant through `user_id`. So the expression is `null`, `plan_limits(0)` finds no
organization, `solo` is false, and the 402 never fires: Solo organizations can
upload screenshots today despite the plan excluding them.

The documented intent is unambiguous — §2 above calls ingest refusal "the real
enforcement" against a stale or modified agent, and `plan_limits()` names this
as one of exactly three places Solo is enforced. The port resolves the
organization through `Device::user()` so the refusal happens.

No organization is currently on the `solo` plan, so nothing changes for anyone
today. If Solo customers exist at cutover their agents begin receiving 402 on
upload, which the agent swallows without re-queueing. Reverting is a one-line
change in `WebhookController::screenshot()`.

### Reproduced deliberately, not fixed

- `devices.last_seen` still uses server-local `NOW()` while the rest of the
  ingest path is UTC. Verified: a harness run stamped `15:17:33` against a
  session closing at `07:17:33` UTC. Fixing it shifts every displayed last-seen.
- Replay is still accepted. There is no nonce, timestamp or window.
- Registration is still non-idempotent — every call creates a device row.
- `DELETE /webhooks/tasks/{id}` still answers `{"ok":true}` for a row that is
  not there.

### Not yet built on this prefix

`/webhooks/remote/*` (Phase 16) and `/webhooks/wise` (Phase 12, a payment
provider callback on RSA-SHA256 rather than device HMAC, currently 410 Gone).

## 7. Migration risks

| Risk | Severity | Note |
|---|---|---|
| Moving the agent to `/api/v1/*` | **Critical** | Every deployed agent breaks; explicitly excluded by the user |
| Verifying HMAC over re-encoded JSON | **Critical** | Every request fails signature |
| Laravel middleware trimming/nulling input before hashing | **Critical** | Silent signature failures |
| Returning 201 / 422 / 429 | **High** | Agent treats non-2xx as failure → infinite queue retry |
| Converting screenshot upload to multipart | **High** | Empties the raw body, breaks HMAC |
| Enforcing `exists:` on `client_id` / `task_id` | **High** | Turns a silent null into a 422 retry loop |
| Dropping the `ended_at IS NULL` guard on activity | Medium | Replayed batches overwrite final totals |
| "Fixing" `NOW()` → `UTC_TIMESTAMP()` on `last_seen` | Low | Shifts displayed last-seen; do it knowingly |
