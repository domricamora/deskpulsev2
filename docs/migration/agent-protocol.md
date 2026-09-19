# Desktop agent — protocol and behaviour

> **Standing constraint (user, this session):** the agent for Windows, macOS and Linux
> **does not change**. No file under `agent/` is edited by this migration. Laravel
> adapts to the agent, never the reverse. See `api-contract.md` for the frozen HTTP
> surface; this page covers agent-side behaviour the server must accommodate.

Source of truth: `agent/webhook_client.py`, `agent/config.py`, `agent/main.py`,
`agent/monitor/*`, `requirements.txt`, `agent/packaging/*`.

## 1. Platform support — what actually exists today

| Platform | Runs from source | Packaged installer |
|---|---|---|
| Windows | yes | **yes** — PyInstaller (`deskpulse.spec`) + Inno Setup (`installer.iss`), `build.ps1` |
| macOS | yes | **yes** — `deskpulse_mac.spec` + `build_mac.sh` |
| Linux | yes (deps are cross-platform) | **no packaging script in repo** |

The dependency set is cross-platform by design:

```
PySide6, requests, pynput, mss (cross-platform capture),
Pillow, psutil, pygetwindow (cross-platform active window)
pywin32==308 ; sys_platform == "win32"      # Windows only, conditional
```

Windows-specific code is guarded, not assumed:

- `main.py:218` — `if sys.platform == "win32"` around `SetCurrentProcessExplicitAppUserModelID`
- `monitor/window.py` — "prefers pywin32 on Windows", falls back to `pygetwindow`
- `monitor/winject.py:32` — returns early unless `win32`
- `monitor/inject.py:46` — `ctypes.windll.user32` (remote-control input injection)

**Remote-control input injection is Windows-only.** Screen capture is cross-platform
(`mss`); synthesising keyboard/mouse input is not. A macOS or Linux agent can be
viewed but not driven. This is existing behaviour, unchanged by the migration.

None of this is affected by replacing the PHP server — but the audit records it so
"the agent works on Linux, Windows and Mac" is understood precisely: **the code runs
on all three; shipped installers exist for two.**

## 2. Client shape

`WebhookClient(server_url, device_id, secret)`; `server_url` is `rstrip("/")`-ed and
already includes any base path. Timeout **15s** on every call.

```python
headers = {
    "X-DeskPulse-Device":    str(self.device_id),
    "X-DeskPulse-Signature": hmac.new(secret.encode(), body, sha256).hexdigest(),
}
if raw is None:
    headers["Content-Type"] = "application/json"
```

- Body is `json.dumps(json_body).encode()` for JSON, the bytes themselves for raw,
  `b""` otherwise. **The signature covers exactly those bytes.**
- `resp.raise_for_status()` on every call — any 4xx/5xx becomes an exception.

## 3. Three delivery modes — the server must respect the difference

| Mode | Methods | On failure |
|---|---|---|
| **Direct** (`_send`) | register, me, clients, policy, tasks CRUD, session start, set task | exception propagates to the UI |
| **Queued** (`_enqueue_or_send`) | session stop, activity, windows, idle | appended to the disk queue, retried later |
| **Best effort** | screenshots, remote frames | exception swallowed / never queued |

```python
def post_screenshot(...):
    try:    self._send("POST", path, raw=image_bytes)
    except Exception: pass        # never blocks the tracker
```

Remote-control frames deliberately bypass the queue — a stale frame must be dropped,
never replayed.

## 4. The offline queue — and why status codes matter

```python
def _enqueue_or_send(self, method, path, json_body):
    try:    self._send(method, path, json_body)
    except Exception:
        self._queue_append({"method":…, "path":…, "body":…, "ts": time.time()})

def flush_queue(self):
    for item in self._queue_load():
        try:    self._send(item["method"], item["path"], item["body"])
        except Exception: remaining.append(item)     # keep for next time
```

- Stored as JSON at `QUEUE_PATH` (under `%APPDATA%/DeskPulse`), capped at the **last
  500 items** (`items[-500:]`) — overflow silently drops the **oldest**.
- Retry is **unbounded in time** and has **no backoff**: an item is retried on every
  flush until it succeeds.

> **Critical server consequence.** The agent cannot distinguish "retryable" from
> "permanently invalid" — `raise_for_status()` makes every non-2xx identical. A
> Laravel `FormRequest` returning **422** for a malformed queued batch would pin that
> item in the queue forever, retried on every flush, eventually evicting good data
> past the 500 cap.
>
> The migration plan §45 assumes the agent distinguishes these classes. **It does not.**
> Therefore: for queued endpoints, Laravel must return **2xx for anything it intends
> to discard**, and reserve non-2xx for genuinely retryable conditions. This is why
> the current handlers validate permissively and store `NULL` rather than rejecting.

## 5. Replay is real, and today it duplicates

When connectivity returns, `flush_queue()` resends stored bodies verbatim. The server
has **no idempotency key** — no request id, no dedup column on
`activity_samples`, `window_events`, `process_snapshots` or `idle_periods`. Each
handler is an unconditional `INSERT` per element.

**So a replayed batch duplicates rows today.** The only guard is on live totals:

```sql
UPDATE sessions SET active_s=?, inactive_s=? WHERE id=? AND ended_at IS NULL
```

which stops a stale batch clobbering finalized totals.

This contradicts the migration plan §15 ("do not create duplicate records when the same event
is replayed"). The protocol carries nothing to deduplicate on, so honouring §15 would
require either a **protocol change** (excluded — the agent is frozen) or **server-side
heuristic dedup** on `(session_id, ts, …)`, which changes recorded data.

> **Decision required before Phase 9.** Options:
> (a) replicate exactly — duplicates on replay, matching today;
> (b) add heuristic dedup on natural keys — deviates from "exact replica", changes
>     report figures for any org that has been offline.
> Defaulting to (a) preserves parity and golden-master comparability. Flagged in
> `migration-map.md` as an open decision.

## 6. Session lifecycle as the agent drives it

```
register  →  POST /webhooks/auth              (once; stores device_id+secret)
startup   →  GET  /webhooks/me                (401 ⇒ account revoked ⇒ sign out)
             GET  /webhooks/policy            (capture interval, blur, toggles)
             GET  /webhooks/clients, /tasks
start     →  POST /webhooks/session           → session_id
during    →  POST …/activity   (queued, carries running active_s/inactive_s)
             POST …/windows    (queued, windows + processes together)
             POST …/idle       (queued)
             POST …/screenshot (best effort, raw bytes)
             POST …/task       (direct, when the worker switches task)
stop      →  PATCH /webhooks/session/{id}     (queued)
```

- Tracking runs **only while the session timer is on**, with a visible "● Monitoring"
  indicator. This is a privacy guarantee (the migration plan §79) — do not add server
  behaviour that implies capture outside a session.
- `/webhooks/me` returning 401 is the revocation channel.
- `schedule` from `/webhooks/me` drives auto start/stop within working hours.

## 7. Configuration

`agent/config.py` holds `server_url` plus the device token/secret under
`%APPDATA%/DeskPulse`. The URL is **user-supplied at sign-in** — no production URL is
compiled in, so pointing an existing install at a Laravel host is a settings change,
which is exactly how cutover will be tested.

## 8. Laravel destination

No agent changes. Server-side only:

| Concern | Laravel |
|---|---|
| Signature check | `VerifyDeskPulseSignature` over `$request->getContent()` |
| Route group | `/webhooks/*`, CSRF-exempt, no `TrimStrings`/`ConvertEmptyStringsToNull` |
| Handlers | thin controller → `app/Services/Agent/*IngestService` |
| Rate limiting | agent ingest must have its **own** limiter; a 429 is indistinguishable from failure and will queue-and-retry (the migration plan §46) |

## 9. Required tests

Run `tools/test_webhook.py` unmodified against Laravel — it exercises register →
session → activity → windows → idle → screenshot and is the existing harness
(the migration plan §59 requires it keep working).

Beyond that:

- Queue replay: take a session offline, generate batches, restore connectivity, flush
  — assert the server accepts every item with 2xx.
- Assert the agreed duplicate-vs-dedup behaviour from §5 explicitly, either way.
- Queue overflow: >500 items evicts oldest, newest still deliver.
- A 422 from any queued endpoint is treated as a **regression** — add a test asserting
  queued endpoints never return 422.
- `/webhooks/me` → 401 after the user row is deleted.
- Screenshot 402 on a screenshot-less plan does not interrupt tracking.

## 10. Migration risks

| Risk | Severity | Note |
|---|---|---|
| Any edit to `agent/` | **Critical** | Explicitly out of scope this migration |
| Changing agent-facing routes | **Critical** | Deployed installs break; no auto-update path assumed |
| Returning 422/429 on queued endpoints | **High** | Poison items retried forever, evict good data |
| Re-encoding the body before HMAC | **Critical** | Universal signature failure |
| Assuming the agent classifies errors | **High** | the migration plan §45 is incorrect about this client |
| Silently "fixing" replay duplication | Medium | Changes historical report figures; must be a decision |
| Aggressive global rate limiting | Medium | Heartbeat/ingest traffic is legitimate and bursty after downtime |
