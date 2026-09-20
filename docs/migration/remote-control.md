# Remote desktop control — high-risk subsystem

Source of truth: `server/src/remote.php` (278 lines), `agent/monitor/remote.py`,
`agent/monitor/inject.py`, `agent/monitor/winject.py`,
`server/public/assets/js/remote.js`.

The migration plan §33 says document the exact protocol before touching it. This is that
document. **Do not rewrite this module during the migration** — port it behaviour-for-
behaviour and audit it separately.

## 1. Model

An admin opens a control session against a worker's **device**. The agent polls, then
streams JPEG frames of the primary screen and applies queued input commands.

```
admin  POST /app/remote/{device}/start   → remote_sessions row, status='pending'
agent  GET  /webhooks/remote/poll        → activates it, gets stream params
agent  POST /webhooks/remote/{id}/frame  → JPEG body; returns queued commands
admin  GET  /app/remote/{id}/frame       → latest frame
admin  POST /app/remote/{id}/input       → queues a command
admin  POST /app/remote/{id}/stop        → ends
agent  POST /webhooks/remote/{id}/end    → agent-side stop
```

Tables: `remote_sessions` (status, token, `frame_seq`, `screen_w/h`, `activated_at`,
`last_frame_at`, `last_input_at`, `end_reason`, `admin_user_id`, `user_id`,
`device_id`, `org_id`), `remote_input_events` (`session_id`, `payload`).

## 2. Server-enforced expiry — the safety property

```php
const REMOTE_AGENT_TIMEOUT_S   = 15;   // active, no frame     → 'agent_gone'
const REMOTE_IDLE_MAX_S        = 600;  // active, no admin input → 'expired'
const REMOTE_PENDING_TIMEOUT_S = 30;   // pending, never picked up → 'expired'
```

`remote_gc()` runs **at the top of every endpoint**, admin and agent alike. The module
header states the intent plainly: *"All auto-expiry is enforced server-side; the server
never trusts the agent to end a session."*

So a control session cannot outlive: 15s without frames, 10 minutes without admin
input, or 30s unacknowledged. **These three constants are the containment mechanism.**
Preserve the values, and keep the GC on every endpoint — moving it to a scheduler
introduces a window where an abandoned session stays live between ticks.

## 3. Authorization

```php
function remote_scope_or_deny(array $u, ?int $targetOrgId): void {
    if (!is_super($u) && (int) $targetOrgId !== (int) $u['org_id'])
        abort(404, 'device not found');
}
```

- Every admin endpoint is `require_cap('remote')` — held by `super_admin`,
  `client_admin` and `it_admin`.
- Super admins reach **any** device, cross-tenant. Everyone else is confined to their
  own org.
- A cross-org attempt returns **404, not 403** — deliberately, so foreign devices are
  not even revealed. Keep the 404; a 403 leaks existence.

> The route comment in `public/index.php` says "super-admin only, unpublished". That is
> **stale** — the capability grants it to company and IT admins too. Trust
> `role_caps()`, not the comment.

## 4. Agent endpoints (device HMAC)

### `GET /webhooks/remote/poll`

Returns the newest `pending|active` session for that device, activating a pending one:

```json
{"session": {"id": 1, "token": "…", "fps": 3, "max_width": 1280, "quality": 55}}
```

or `{"session": null}`. Stream parameters are **server-dictated** — 3 fps, 1280px wide,
JPEG quality 55. Keep them server-side; they bound bandwidth and are not negotiable by
the agent.

### `POST /webhooks/remote/{id}/frame?w=&h=`

Raw JPEG bytes as the body (same rationale as screenshots — the HMAC covers the raw
body).

1. Rejects unless the session exists, belongs to this device, and is `active` —
   otherwise `{"status":"ended"}`, which is the agent's stop signal.
2. Writes the frame to `uploads/remote/{id}.jpg`.
3. On the **first** frame only, records `screen_w`/`screen_h` from the query — used to
   map the admin's normalized input coordinates back to real pixels.
4. Increments `frame_seq`, stamps `last_frame_at`.
5. **Drains** `remote_input_events` for the session: decode → return → `DELETE`.

```json
{"status": "active", "commands": [ … ]}
```

Input delivery is therefore **at-most-once**: commands are deleted as they are handed
over. A frame response lost in transit loses those commands. That is the existing
design — do not "improve" it into at-least-once, which would risk replaying clicks and
keystrokes onto a worker's machine.

### `POST /webhooks/remote/{id}/end`

Marks `ended` / `agent_gone`, scoped to the calling device.

## 5. Security finding — frames are world-readable at a guessable URL

```php
$dest = upload_path('remote/' . $id . '.jpg');     // server/public/uploads/remote/1.jpg
```

`public/.htaccess` serves any real file directly, so the **live screen of a worker's
machine** is fetchable at `/uploads/remote/{session_id}.jpg` with **no authentication**.

This is more severe than the screenshot exposure (`screenshots.md` §4): screenshots at
least use 16 random bytes in the path, whereas remote frames use the **sequential
`remote_sessions.id`**. The whole space is trivially enumerable, the file is
overwritten in place at 3 fps, and it persists after the session ends.

The admin-side route `/app/remote/{id}/frame` *is* properly gated by `require_cap('remote')`
— but it is not the only way to reach the bytes.

> **DONE in Phase 16 (decision D5).** Frames are on the `private` disk, served
> only through `/app/remote/{id}/frame` behind `cap:remote` and the org scope
> check, and **deleted when the session ends** — by the admin, by the agent, or
> by the garbage collector. Visible behaviour is unchanged: the console still
> shows the same picture. There is a test asserting nothing lands under
> `public/uploads/remote/`.

## 6. Platform coverage

Frame capture uses `mss` and is cross-platform. **Input injection is Windows-only** —
`inject.py` uses `ctypes.windll.user32`, and `winject.py` returns early unless
`sys.platform == 'win32'`. A macOS or Linux agent can be *viewed* but not *driven*.
Existing behaviour; the agent is unchanged (`agent-protocol.md` §1).

## 7. Audit

`remote_sessions` start/end rows are two of the six sources behind the derived
`/app/audit` feed (`database.md` §5) — the only part of the migration plan §32's audit
requirement that exists today. `admin_user_id` records who initiated. Input events are
**deleted as they are drained**, so there is no record of what was actually typed or
clicked.

If audit logging is built later, remote control is the strongest candidate for real
logging — but note that recording keystrokes would itself be a significant privacy
change, and §79 constrains monitoring additions.

## 8. Laravel destination

| Current | Laravel |
|---|---|
| `remote_gc()` | `RemoteSessionService::collectGarbage()`, called per endpoint |
| `remote_scope_or_deny()` | `RemoteControlPolicy` — 404 on cross-org, not 403 |
| `wh_remote_poll/frame/end` | `Api/RemoteController` + HMAC middleware |
| `dash_remote_*` | `RemoteController` + `can:remote` |
| `remote_input_events` drain | `RemoteCommandService`, at-most-once |
| `uploads/remote/{id}.jpg` | private disk + authorized route (§5) |
| the three constants | `config/deskpulse.php`, same values |

## 9. Required tests

- Cross-org device → **404** (not 403), for `client_admin` and `it_admin`.
- Super admin reaches another org's device.
- Without `remote`, every admin route redirects to `/app`.
- Expiry: no frame for 15s → `agent_gone`; no input for 600s → `expired`; pending
  unacknowledged for 30s → `expired`.
- GC runs on every endpoint, not only on a schedule.
- `poll` activates exactly one pending session and returns the fixed stream params.
- `frame` on a non-active or foreign session → `{"status":"ended"}`.
- `screen_w/h` recorded on the first frame only.
- Input commands are returned **once** and deleted.
- A frame is not fetchable without authorization, and not at a guessable path (after §5).
- Remote start/end appear in the `/app/audit` feed.
- Sessions cannot be started against a device in another org even with a forged id.

## 10. Migration risks

| Risk | Severity | Note |
|---|---|---|
| Frames on a public/guessable path | **Critical** | Live screen of a worker's machine, unauthenticated, enumerable |
| Rewriting rather than porting | **High** | §33 warns explicitly; containment is subtle |
| Moving `remote_gc()` to the scheduler only | **High** | Abandoned sessions stay live between ticks |
| Changing 404 → 403 on cross-org | Medium | Leaks device existence across tenants |
| Making input delivery at-least-once | **High** | Replayed clicks/keystrokes on a real machine |
| Letting the agent set fps/size/quality | Medium | Server currently dictates; removes a bandwidth bound |
| Trusting the agent to end sessions | **High** | Inverts the stated security model |
| Assuming super-admin-only from the stale route comment | Medium | Company/IT admins hold `remote` too |
