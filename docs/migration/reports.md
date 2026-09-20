# Reporting — the period engine and rollups

Source of truth: `server/src/reports.php` (797 lines).

The period engine is the foundation every money and time figure sits on. It was
rewritten once already to fix timezone bugs; the fixes are documented here so they are
not reintroduced.

## 1. Three clocks — know which one applies

| Layer | Clock | Mechanism |
|---|---|---|
| **Storage** | UTC | every datetime column |
| **Period cutting** | the **org's** `report_tz` | `period_ctx()` → `local_to_utc()` |
| **Display** | the **viewer's** browser timezone | `tlocal()` → `<time data-utc>` + `dashboard.js`, viewer tz reported in the `dp_tz` cookie |

> *"Never compare a naive local date against a stored UTC datetime; use
> `local_to_utc()` / `utc_to_local_date()`."* — the production system notes

**They no longer disagree.** This page used to warn that `recompute_overtime()`
bucketed by server-local wall-clock while `compute_pay_run()` used `report_tz`, and
that the two must not be silently unified. Decision D2 unified them deliberately, with
the pay figures restated by `overtime:recompute` — see `monitoring.md` §4. Everything
that cuts a day now cuts it in the organization's `report_tz`.

`Period::viewerTimezone()` is the only place the third clock is read for anything but
display: a manual timesheet entry is typed in the filer's own wall-clock, so it is
converted from there, not from the organization's timezone and not from the server's.

## 2. `period_ctx()` — the single resolver

```php
period_ctx($default, $allowed, $orgId): array
// → label, prev, next, start_date, end_date, days
```

- Validates `?period` against the page's allowed set.
- Honours `?date=` as the **in-window anchor for every period**, day included.
- Handles `?period=range&from=&to=`.
- `period_range_from_request()` is a thin legacy wrapper that also stashes the context
  in `$GLOBALS['DP_PERIOD_CTX']` for `period_switch_html()`.

Supporting helpers: `report_org_cfg()`, `report_tz()`, `local_to_utc()`,
`utc_to_local_date()`, `date_add_days()`, `range_label()`.

## 3. Pay-cycle periods

A `pay` period driven by `organizations.pay_cycle`:

| Value | Window |
|---|---|
| `semimonthly` **(default)** | 1st–15th, 16th–end |
| `rolling15` | fixed 15-day cycles from `pay_cycle_anchor` |
| `biweekly` | 14-day cycles from the anchor |
| `weekly` | from `week_start` |
| `monthly` | calendar month |

Configured on Settings together with `week_start` and `pay_currency`.
`pay_cycle_anchor` is the origin for the fixed-length cycles.

`period_options()` centralizes the pill set — nine templates each used to hard-code
their own `$periods` literal. Keep one source.

`period_switch_html()` renders **◀ label ▶** navigation, a "Today" jump and a date
jump on every period.

## 4. Bugs already fixed — do not reintroduce

Recorded in the production log; each is a regression test:

1. `daily_series()` bucketed naive UTC strings as server-local, so chart bars could land
   on a different day than the rows above them.
2. `dash_export_csv()` used an undefined `$period` in its filename.
3. `?period=day` was accepted on pages offering only week/month, lighting no pill.
4. The Screenshots page narrowed `$ids` **before** building its member dropdown, so
   filtering to one person left only that person selectable.
5. The Screenshots page's `?date=` compared a local date against UTC timestamps.

## 5. Rollups

| Function | Produces |
|---|---|
| `summarize($sessions)` | totals — active, inactive, activity % |
| `daily_series($sessions,$start,$end,$tz)` | per-day buckets for charts, cut in `$tz` |
| `top_apps($sessionIds)` | active-window summary from `window_events` |
| `task_time_rows($ids,$start,$end)` | time per task |
| `sessions_for_users($ids,$start,$end)` | **approved sessions only** |
| `org_user_ids($orgId)` | org member ids, returns `[0]` sentinel when empty |
| `users_by_id($orgId)` | id ⇒ user map, excludes `super_admin` |

`sessions_for_users()` filtering to approved rows is why pending manual entries do not
appear in reports or billing until approved.

`org_user_ids()` returns `[0]` rather than `[]` so callers building `IN (…)` never
emit an empty `IN ()` — a super admin's platform org has no ordinary members. Any
Laravel rewrite using `whereIn` must handle the empty case explicitly.

## 6. Reports exposed today

- `/app/reports/efficiency` (+ `.csv`) — capability `reports`. **This is the only
  `/app/reports/*` route**; the migration plan §51's bare `/app/reports` does not exist.
- `/app/export.csv` — scoped session export.
- `/app/billing.csv`, `/app/salary-run.csv`, `/app/platform/subscribers.csv`,
  `/app/platform/accounting.csv`.

`money()` / `fmt_hms()` join values with a **non-breaking space** so figures never wrap
mid-number. They are **display-only** — exports format their own numbers, and `DpPdf`
maps U+00A0 back to a normal space. Never build a CSV from them.

## 7. Laravel destination

| Current | Laravel |
|---|---|
| `period_ctx()` | `PeriodResolver` value object |
| pay cycles | `PayCycle` enum + resolver |
| `summarize`/`daily_series`/`top_apps`/`task_time_rows` | `app/Services/Reports/*` |
| `sessions_for_users()` | `WorkSession::approved()->forUsers()` scope |
| CSV | `ReportExportService`, streamed (the migration plan §41) |
| `tlocal()` / `dp_tz` | Blade component + the same cookie |

Set `config('app.timezone')` to **UTC** and cut every window explicitly in
`report_tz`. Do not rely on Carbon's ambient timezone — that is exactly the bug the
period rewrite fixed.

## 8. Required tests

- Period windows cut in `report_tz`, not server or viewer time.
- `?date=` anchors every period, including `day`.
- `?period=range&from&to`; reversed dates handled.
- All five pay cycles, including semi-monthly halves summing to one month.
- `week_start` respected.
- Day-boundary: a 23:30 session lands in the right bucket under a non-UTC `report_tz`.
- Chart buckets match the table rows above them (regression 1).
- Invalid `?period` falls back without lighting a pill (regression 3).
- CSV filenames include the resolved period (regression 2).
- `sessions_for_users()` excludes pending/rejected.
- Empty scope produces no SQL error (`[0]` sentinel).
- Golden master vs. the legacy system on identical fixtures.

## 8a. Phase 11 as built

| Route | Controller | Gate |
|---|---|---|
| `/app/timesheets` (GET POST) | `TimesheetController` | login, scoped |
| `/app/export.csv` | `TimesheetController::export()` | login, scoped |
| `/app/session/{id}` | `SessionController` | login, ownership in the handler |
| `/app/approvals` (+POST) | `ApprovalController::time()` | `approve_time` |
| `/app/overtime` (+POST) | `ApprovalController::overtime()` | `approve_overtime` |
| `/app/reports/efficiency` (+`.csv`) | `EfficiencyController` | `reports`, client portal redirected |

The period engine was already built in Phase 6 (`App\Support\Period`), so this phase
is the pages on top of it.

### Decisions visible in the code

- **A member's entry lands `pending`; a manager's lands `approved`.** The person whose
  hours they are cannot wave them through — that asymmetry is the reason the approval
  queue exists, and the `user_id` a member posts is ignored outright.
- **Scope is re-checked when deciding, not just when listing.** The id arrives in a
  URL; a reviewer must not be able to sign off a row outside their team by typing one.
  Both actions 404 rather than 403, which is the same choice the screenshot route makes.
- **Timesheets is the one page that shows pending and rejected rows.** Everything else
  reads approved sessions only. It is where you go to find out what happened to an
  entry you filed, so hiding the rejected ones would be hiding the answer.
- **`null` effectiveness is not `0%`.** Somebody with neither tracked time nor tasks —
  every admin, HR and IT role — is not being measured, and the organization average
  skips them. The page says "no data" and the CSV says "n/a".
- **Exports format their own numbers.** `Format::hms()` and `money()` join with a
  non-breaking space so figures cannot wrap on screen; in a CSV that turns every number
  into text. There is a test asserting no U+00A0 reaches an export.
- **Filenames carry the resolved period** (regression 2), and the efficiency CSV and
  page resolve their window through one private method so they cannot disagree.

### Batched, not per row

`client_name()` and `task_name()` are per-call queries behind a static cache, and the
legacy's session CSV runs one client lookup per row. Timesheets, both queues and the
export each resolve their names in a single `whereIn` instead.

### One Blade note

`@json()` cannot parse an array literal containing quoted strings — `@json(['Active',
'Inactive'])` is a compile error. Such arrays are hoisted into an `@php` block first.
It cost a 500 on the efficiency page before the smoke test caught it.

## 9. Migration risks

| Risk | Severity | Note |
|---|---|---|
| Using Carbon's ambient timezone | **High** | Reintroduces the original period bug |
| Unifying the overtime and pay-run clocks silently | **High** | Changes pay |
| `whereIn` on an empty id set | Medium | Fatal SQL or, worse, an unscoped query |
| Reporting unapproved sessions | Medium | Inflates hours and invoices |
| Building CSV from `money()`/`fmt_hms()` | Medium | NBSP corrupts machine-read output |
| Re-hardcoding period pill sets per page | Low | The drift `period_options()` removed |
