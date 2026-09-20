# Monitoring — sessions, activity, windows, processes, idle, overtime

Source of truth: `server/src/webhooks.php` (ingest), `server/src/reports.php`
(`org_policy`, `close_stale_sessions`, `recompute_overtime`, `creditable_active_s`),
`agent/monitor/*`.

Ingest mechanics are in `api-contract.md`. This page covers what the data *means* and
how it is derived.

## 1. The monitoring policy

`org_policy(orgId)` is the single policy object, served to the agent at
`GET /webhooks/policy`:

| Key | Default | Source |
|---|---|---|
| `screenshot_interval_min` | 10 | `organizations.screenshot_interval_min` |
| `screenshot_blur` | false | `organizations.screenshot_blur` |
| `idle_threshold_min` | **15** | `organizations.idle_threshold_min` |
| `sync_interval_s` | 60 | `organizations.sync_interval_s` |
| `track_screenshots` | true | org toggle **AND** `plan_limits()['screenshots']` |
| `track_windows` | true | `organizations.track_windows` |
| `track_processes` | true | `organizations.track_processes` |

```php
// Plan limit overrides the admin toggle — it can only ever turn capture OFF.
'track_screenshots' => (bool)($o['track_screenshots'] ?? 1) && $limits['screenshots'],
```

The plan can only *remove* capability, never grant it. Preserve that asymmetry.

The 15-minute idle default satisfies the migration plan §18, and it is already in one place —
this function. Read it from here; do not re-hardcode it.

### Plan limits

```php
plan_limits($orgId) = [
    'solo'         => plan_type === 'solo',
    'history_days' => solo ? 7 : 0,    // 0 = unlimited
    'screenshots'  => !solo,
    'max_seats'    => solo ? 1 : 0,    // 0 = unlimited
];
```

The free **Solo** tier has a 7-day history window, no screenshots and one seat. These
limits are real and enforced server-side, not just in the UI — see `api-contract.md`
§2 (402 on screenshot upload).

## 2. Session model

`sessions` is the time entry. Columns that carry meaning:

| Column | Meaning |
|---|---|
| `user_id`, `device_id`, `client_id`, `task_id` | who / where / for whom / on what |
| `started_at`, `ended_at` | UTC; `ended_at IS NULL` ⇒ live |
| `active_s`, `inactive_s` | totals maintained live, finalized at stop |
| `last_seen_at` | heartbeat, stamped by any session-scoped webhook |
| `source` | `agent` \| `manual` \| `import` |
| `approval_status` | `approved` \| `pending` \| `rejected` |
| `reviewed_by_id`, `reviewed_at`, `review_note` | approval trail |
| `overtime_s`, `overtime_status`, `overtime_reviewed_*`, `overtime_computed` | overtime split |
| `project_id` | deprecated |

Approval defaults by source (the migration plan §17 — already true):

- `agent` → `approved`
- `manual` → `pending` until a manager with `approve_time` acts
- `import` → `approved` (payroll import writes settled history)

## 3. Stale sessions

```php
close_stale_sessions(int $minutes = 15)
  UPDATE sessions SET ended_at = COALESCE(last_seen_at, started_at)
  WHERE ended_at IS NULL
    AND COALESCE(last_seen_at, started_at) < UTC_TIMESTAMP() - INTERVAL 15 MINUTE
```

A crashed or disconnected agent leaves an open session forever, so:

- It is closed at **its last heartbeat**, not at "now" — a crash does not silently
  bank hours.
- Called **on every live-view poll** (`dash_live_data`), i.e. roughly every 15s while
  anyone watches. There is **no cron for this** — if nobody opens the live view,
  stale sessions stay open until someone does.
- A reconnecting agent also closes its own stale sessions in `POST /webhooks/session`.

> **Settled in Phase 9 (decision D11): kept on the render, not scheduled.**
> `SessionIngest::closeStale()` runs from `NavigationComposer::maintenance()`,
> which is where `nav_context()` called it, so `ended_at` still lands when
> somebody looks. Scheduling it would close sessions earlier for any
> organization that never opens the dashboard — a change to their recorded
> hours wearing a cleanup's clothes.
>
> `recompute_pending_overtime()` runs in the same pass, immediately after.
> Order matters: closing a crashed agent's session is what makes it eligible
> for an overtime split, so recomputing first would leave that session's
> overtime uncounted until the next page view — and the sidebar badge counts it.

## 4. Overtime — computed at ingest, gated by HR

`recompute_overtime($userId, $sinceUtc)` runs on **session stop**
(`PATCH /webhooks/session/{id}`), re-walking that user's closed sessions from one day
before the session start.

```
work_start / work_end / work_days on users
   ↓ no schedule set → nothing is overtime
   ↓ group closed sessions by day, walk in order
   ↓ rule 1: active time outside the scheduled window/days
   ↓ rule 2: active time beyond the scheduled daily length
   ↓ overtime_s persisted, overtime_status='pending', overtime_computed=1
   ↓ an existing approved/rejected decision is preserved — only the amount refreshes
```

Payment gate:

```php
creditable_active_s($s) =
    overtime_status === 'approved' ? active_s : max(0, active_s - overtime_s);
```

**Unapproved overtime never pays.** `compute_pay_run()` uses this exclusively.

### The timezone — corrected (decision D2)

**Overtime is bucketed and measured in the organization's `report_tz`.** The day a
session belongs to, and the wall-clock window `work_start`–`work_end` it is weighed
against, both come from the tenant's clock. Session timestamps are read as UTC
explicitly, so the result does not depend on the host's `date.timezone`.

It used to. `recompute_overtime()` grouped by `date('Y-m-d', strtotime($s['started_at']))`
— the **naive stored UTC value read as server-local wall-clock** — and built the
window the same way. For a tenant outside the server's timezone that is not a
rounding error, it is the wrong window: a Manila team on 09:00–17:00 was measured
against 09:00–17:00 *on the server's clock*, which is the middle of their night.

Three details make this worse than it first reads:

- **`report_tz` exists because of exactly this bug, one layer up.** The schema
  migration that added it says the period window "silently depended on php.ini's
  `date.timezone`" and names the three-clock problem outright. Overtime was the
  calculation left behind.
- **The legacy source comment is stale.** It claims the bucketing matches
  `daily_series()`, but `daily_series()` was corrected to `report_tz` at some point
  — its own comment explains that bucketing on the naive string "put bars in a
  different day than the table rows". So the dashboard chart and the overtime split
  have disagreed for a while.
- **"Replicate exactly" was never actually available.** The legacy app inherits the
  host's `date.timezone`; Laravel pins `config('app.timezone')` to UTC. Reproducing
  the old behaviour would have meant reproducing the production box's php.ini, and
  z.com is a Japanese host. A faithful port of an accident is still an accident, and
  it is one that moves if the host is ever reconfigured.

`Format::withinWorkSchedule()` moved with it, as it had to: it drives the overview's
"in schedule" badge from the same rule, and a badge that says someone is inside their
hours while the calculation bills the time as overtime is worse than either answer
alone.

**Restating existing rows is `php artisan overtime:recompute`.** It reports before it
writes, `--dry-run` shows the impact without touching anything, and it counts
separately the sessions HR has already approved whose amount moves — those are the
ones that change what someone is paid. Run the dry run first.

> **Impact.** For any organization already on `report_tz = UTC` with the host on UTC,
> the arithmetic is unchanged — verified row by row on the development database, zero
> differences. Everything else moves, and near midnight it moves sessions between
> days. Golden-master work in Phase 11 pins the corrected behaviour, not the old one.

> **Carried over deliberately:** a window that wraps midnight (22:00–06:00, a night
> shift) still yields a zero allowance, so every tracked second becomes overtime. The
> legacy does the same and the badge agrees with it. Fixing that changes what people
> are paid and belongs in its own approved pass.

## 5. Activity, windows, processes, idle

| Data | Table | Written by | Notes |
|---|---|---|---|
| Activity samples | `activity_samples` | `POST …/activity` | `keyboard_count`, `mouse_count`, `activity_pct` |
| Window events | `window_events` | `POST …/windows` | `app_name`(160), `window_title`(400), `focus_seconds` |
| Process snapshots | `process_snapshots` | same call | `app_name`(200), `pid` |
| Idle periods | `idle_periods` | `POST …/idle` | `duration_s` computed **server-side** as `end − start`, floored at 0 |

Windows and processes arrive in **one request** (`{windows:[…], processes:[…]}`) —
they are not separate endpoints, contrary to the migration plan §12 which lists a
`/webhooks/processes`. No such route exists.

Live totals refresh from the activity call, guarded:

```sql
UPDATE sessions SET active_s=?, inactive_s=? WHERE id=? AND ended_at IS NULL
```

The migration plan §18 says "do not overwrite raw agent data" — already honoured; samples,
window events and idle periods are append-only. Only the session *totals* are updated,
and only while open.

## 6. Privacy behaviour — do not weaken

The migration plan §79 requires consent-first monitoring. Current behaviour:

- Tracking runs **only while the session timer is on**, with a visible "● Monitoring"
  indicator in the tray app.
- Screenshot capture is org-configurable and can be disabled; blur is optional.
- The policy endpoint is the only capture instruction, and the plan can only reduce it.

No server change may imply capture outside a session, and the indicator must not be
removed.

## 7. Laravel destination

| Current | Laravel |
|---|---|
| `org_policy()` | `MonitoringPolicy` value object / `OrganizationPolicyService` |
| `plan_limits()` | `PlanLimits` value object |
| `wh_activity/windows/idle` | `app/Services/Agent/*IngestService` |
| `close_stale_sessions()` | service called from the live endpoint — see §3 before moving to the scheduler |
| `recompute_overtime()` | `Reporting\Overtime`, invoked on session close; restated by `overtime:recompute` |
| `creditable_active_s()` | `WorkSession::creditableActiveSeconds()` |

Ingest must stay synchronous. The migration plan §44 already says not to queue the basic
webhook path — the agent needs a fast acknowledgement, and `recompute_overtime()` runs
inside the stop request today.

## 8. Required tests

- Policy: defaults (10/false/15/60/true/true/true); plan turns screenshots off and
  **cannot** turn them on.
- Solo: 7-day history, 1 seat, screenshot upload 402.
- Session source → approval: agent `approved`, manual `pending`, import `approved`.
- Stale close sets `ended_at` to the **last heartbeat**, not now.
- Reconnect closes prior open sessions.
- Overtime: no schedule ⇒ zero; outside-window ⇒ overtime; beyond-daily-length ⇒
  overtime; an approved/rejected decision survives recomputation; amount refreshes.
- `creditable_active_s()` excludes unapproved overtime; pay run matches.
- **Day-boundary test**: a session starting 23:30 and one at 00:30 bucket exactly as
  they do today under the chosen §4 decision.
- Idle `duration_s` computed server-side; negative ranges floor to 0.
- Append-only: replaying a batch never mutates existing samples.

## 9. Migration risks

| Risk | Severity | Note |
|---|---|---|
| Changing overtime day-bucketing timezone | **High** | Silently moves sessions between days → changes pay |
| Moving `close_stale_sessions()` to the scheduler | Medium | Changes `ended_at` for orgs that never open the live view |
| Queueing ingest | **High** | Breaks the fast-ack contract; stop-time overtime recompute would lag |
| Splitting windows/processes into two endpoints | **High** | Agent posts them together; a second route would never be called |
| Re-hardcoding the 15-minute idle threshold | Medium | §18 explicitly forbids it |
| Letting the plan *enable* capture | Medium | Inverts a deliberate one-way limit |
| Treating totals as append-only, or samples as mutable | Medium | Inverts the current model |
