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

Two places deviate and must be treated deliberately:

- `recompute_overtime()` buckets days by **server-local wall-clock** (`monitoring.md` §4).
- `compute_pay_run()` buckets days by **`report_tz`** (`payroll.md` §1).

They can disagree for sessions near midnight. Do not silently unify them.

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

## 9. Migration risks

| Risk | Severity | Note |
|---|---|---|
| Using Carbon's ambient timezone | **High** | Reintroduces the original period bug |
| Unifying the overtime and pay-run clocks silently | **High** | Changes pay |
| `whereIn` on an empty id set | Medium | Fatal SQL or, worse, an unscoped query |
| Reporting unapproved sessions | Medium | Inflates hours and invoices |
| Building CSV from `money()`/`fmt_hms()` | Medium | NBSP corrupts machine-read output |
| Re-hardcoding period pill sets per page | Low | The drift `period_options()` removed |
