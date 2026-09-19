# Payroll — pay run, adjustments, leave, payouts, payslips, import

Source of truth: `server/src/payroll.php` (1,315 lines), `server/src/helpers.php`
(`xlsx_rows`, `csv_rows`, `import_specs`), `server/src/pdf.php`,
`server/src/dashboard.php` (`dash_import`).

The migration plan §22 describes only the Excel import. The live subsystem is much larger:
pay runs, adjustments, paid time off, Wise payout details, salary-run export and PDF
payslips.

## 1. `compute_pay_run()` is the only money calculation

> *"`compute_pay_run()` is the single source of truth for money. Don't recompute pay
> anywhere else."* — the production system notes

The Payroll page, payslip PDFs and the salary run all read from it, so they cannot
disagree. Preserve that single-entry-point property.

```php
compute_pay_run(array $u, array $ctx, ?int $onlyUserId = null): array
```

```
visible_user_ids()                       → scope
strip client_viewer                      → portal logins are customers, never paid
sessions_for_users($ids, start, end)     → approved only
adjustments_in_period()
leave_in_period()
wise_accounts_for()
   ↓
per user: hours, overtime hours, base, leave pay, adjustments, deductions, caps, net
```

### Worked seconds

```php
$c = creditable_active_s($s);            // unapproved overtime excluded
$secs[$uid]  += $c;
$daily[$uid][ utc_to_local_date($s['started_at'], $cfg['tz']) ] += $c;
```

Approved overtime is additionally tracked for reporting.

> **Two different clocks.** The pay run buckets days with
> `utc_to_local_date(…, $cfg['tz'])` — the **org's reporting timezone**. But
> `recompute_overtime()` buckets with server-local wall-clock (`monitoring.md` §4).
> The same session can therefore land on different days in the two calculations. This
> is existing behaviour; reproduce it knowingly or fix both together, never one.

### Base pay

```php
$daysInMonth = (int) date('t', strtotime($ctx['start_date'] . ' 12:00:00'));
$monthFactor = min(1.0, $ctx['days'] / max(1, $daysInMonth));

$base = $monthly ? (float) $m['pay_rate'] * $monthFactor
                 : $hours * $rate;
```

- Monthly salary is **prorated by calendar days** so a semi-monthly month's halves sum
  to exactly one salary (15/31 + 16/31 = 1).
- The `' 12:00:00'` noon anchor avoids DST edge cases in `date('t')`. Keep it.

### Paid leave — hourly staff only

```php
$leavePay = $monthly ? 0.0 : $lv['paid_hours'] * $rate;
```

A monthly salary already covers the day off. Adding leave pay for salaried staff would
double-pay them.

### Adjustments

`sign = -1` → deductions; otherwise earnings. Kinds: bonus, commission,
reimbursement, allowance, incentive, deduction, advance. `effective_date` decides the
pay period. Capability `pay_adjustments`.

### Currency and caps

Per-user `currency`, falling back to `organizations.pay_currency`. Hour caps
(`daily_hours_cap`, `weekly_hours_cap`, `period_hours_cap`, `0` = none) are
**advisory** — hours over a cap still track and still pay; the member is *flagged* on
Payroll and the salary run. Do not turn a cap into a hard limit.

All arithmetic is PHP float over `decimal`/`double` columns — see `database.md` §3.

## 2. Paid time off

`/app/leave`. Tables `leave_types`, `leave_requests`, `leave_entitlements`.

- Org-defined types, seeded with Vacation / Sick / Public holiday / Unpaid.
- Any user may **request** for themselves — no capability needed. Approving needs
  `leave_approve`.
- **Balances are derived** from approved requests, never stored, so they cannot drift.
  Do not add a materialized balance column.
- Days count only against the member's own `work_days`.
- `require_staff()` turns away `client_viewer`.

## 3. Wise payout details and the salary run

`wise_accounts` — one row per employee: recipient id, account holder, email, account
description, source/target currency, `PERSON|BUSINESS`, source label.

`/app/wise` (cap `wise_manage`) — manual entry plus **.xlsx/.csv import**,
preview → confirm. Matching order:

```
Wise Recipient ID → VT ID (users.external_ref) → email → exact name
```

It **never creates people**; unmatched rows are reported. `#REF!` / `#N/A` rows and
repeated header blocks inside the data are skipped.

`/app/salary-run` + `.csv` — net pay per employee for the period, exported in the
source payout sheet's exact column order:

```
Wise Recipient ID, Wise Name, EMAIL, Wise account, from Currency,
to Currency, Source, Amount, <blank>, Type
```

…with **Amount** filled in. The blank ninth column is part of the format — preserve it.
People without usable payout details are listed and excluded.

## 4. PDF payslips

`server/src/pdf.php` — `DpPdf`, a hand-rolled PDF 1.4 writer: base-14
Helvetica/Helvetica-Bold with **real advance widths** (so right-alignment and wrapping
are correct), text, lines, rectangles, JPEG XObjects, page breaks, xref table.
Top-left coordinates via `y()`.

- `pdf_logo_jpeg()` converts the org's stored **WebP** logo to JPEG via GD, because
  PDF cannot embed WebP.
- `payslip_generate()` caches one PDF per (user, period) in `payslips`.
- Files are written to **`server/storage/`, outside the docroot**, precisely because
  `public/.htaccess` serves any real file it finds. `private_path()` also drops a
  deny-all `.htaccess`. Verified: direct URL **403**.
- Served via `/app/payslip.pdf`, which checks ownership **or** the `payroll` cap.
- `/app/payslips` (cap `payroll`) generates and emails a whole period, idempotently
  via `payslips.emailed_at`, with an explicit re-send checkbox.
- `money()` / `fmt_hms()` join with a non-breaking space; `DpPdf` maps U+00A0 back to a
  normal space. These helpers are **display-only** — never build CSV or machine-read
  output from them.

Replacing `DpPdf` with dompdf/tcpdf changes layout. If the payslip must look identical,
either port `DpPdf` or accept a visual diff and get it signed off.

## 5. Excel import

`/app/import` (cap `data_import` → `client_admin`, `hr_manager`, and `super_admin` via
`*`). Preview-then-confirm. Ingests into the **uploader's own org**:

| Source column | Becomes |
|---|---|
| *Client* (+ Client ID / Industry → notes) | a `clients` row |
| *VT ID* | `users.external_ref` (the match key) |
| *Payroll Rate* | `users.pay_rate` (hourly) |
| *Wise/Contract Name* | `users.name` |
| *Role Name* | `users.job_title` |
| *Adj Credited Hrs* | one approved `source='import'` session per employee/day/client, into `active_s` |

**Idempotency** — the one place the system has it:

- employees matched on `users.external_ref`
- sessions matched on `user + DATE(started_at) + client + source`

Re-importing the same file does not duplicate. Preserve both keys exactly.

### The readers are load-bearing

- `xlsx_rows()` — pure PHP, ZipArchive + DOM, no dependency. Rows keyed by **1-based
  row number**, cells by **column letter**.
- `csv_rows()` returns the **same shape**, so every importer accepts either format
  through one code path; `sheet_rows()` dispatches on extension. Delimiter sniffed,
  BOM stripped.
- `sheet_find_header()` scans the **first 60 rows** for the header, so title banners
  and blank rows above the table are fine.
- `sheet_norm_label()` matches headers loosely — case, spaces, `_`, `-` and
  punctuation all ignored; order irrelevant; extra columns ignored.
- The pivot sheet "Sheet2" is ignored; the data sheet is auto-detected by column labels.
- `import_specs()` is one registry driving **three** things: header matching, the
  on-screen naming guide (`templates/dashboard/_import_guide.php`), and the
  downloadable blank template (`/app/import/template/{key}`). Adding a column there
  makes it appear everywhere — the guide can never drift from the parser.

> The migration plan §22 suggests PhpSpreadsheet. It would change the row/cell shape every
> importer depends on, and must reproduce the 60-row header scan and loose matching or
> real payroll files stop importing. Recommendation: **keep the existing readers** for
> Phase 13 and treat PhpSpreadsheet as optional later work behind the same interface.

### Data handling

Source spreadsheets live in `sheets/` and are **gitignored — payroll PII, never
commit**. Synthetic import addresses (`…@import.deskpulse.local`) are never mailed
(`mail_address_ok()`).

## 6. Laravel destination

| Current | Laravel |
|---|---|
| `compute_pay_run()` | `PayrollService::computeRun()` — single entry point |
| adjustments | `PayAdjustment` + `pay_adjustments` gate |
| leave | `LeaveService`, derived balances |
| `wise_accounts` | `WiseAccount` + `PayoutImportService` |
| salary run CSV | `ReportExportService`, streamed, exact column order |
| `DpPdf` | port, or replace with sign-off on visual diff |
| `payslip_generate()` | `PayslipService` + `payslips` cache, `Storage::disk('private')` |
| `/app/payslips` mailing | queued job, idempotent on `emailed_at` |
| `xlsx_rows`/`csv_rows`/`import_specs` | keep; wrap behind `SheetReader` |
| `dash_import()` | `PayrollImportController` + `PayrollImportService` (queued, §44) |

The migration plan §9 lists `PayrollImport` / `PayrollImportRow` models. **No such tables
exist** — import writes straight into `clients`, `users` and `sessions`. Adding staging
tables is new functionality; the idempotency keys in §5 are what make re-import safe
today.

## 7. Required tests

- Hourly: `creditable hours × rate`; unapproved overtime excluded; approved included.
- Monthly: prorated by calendar days; semi-monthly halves sum to one salary.
- Paid leave added for hourly only; never for monthly.
- Adjustments by sign; `effective_date` selects the period.
- Caps flag but never truncate.
- `client_viewer` never appears in a pay run; `require_staff()` blocks Leave/Payslip.
- Payslip PDF: direct storage URL **403**; owner 200; `payroll` cap 200 for others;
  logged-out 302.
- Payslip emailing idempotent on `emailed_at`; re-send checkbox forces a resend.
- Import: re-running the same file creates no duplicate users or sessions.
- Import matches employees on `external_ref` and sessions on
  `user+date+client+source`.
- Header detection tolerates banner rows, reordered columns, extra columns and
  case/punctuation differences.
- `.xlsx` and `.csv` produce identical results.
- Wise import matches in the documented order and **creates nobody**.
- Salary-run CSV column order is byte-identical, including the blank 9th column.
- Golden master: pay figures match the legacy system on the demo fixture.

## 8. Migration risks

| Risk | Severity | Note |
|---|---|---|
| Recomputing pay outside `compute_pay_run()` | **High** | Payroll, payslips and salary run would diverge |
| Swapping in PhpSpreadsheet unverified | **High** | Row shape + loose header matching are depended on |
| Losing import idempotency keys | **High** | Duplicate employees and double-counted hours |
| Mixing the two day-bucketing clocks | **High** | Moves sessions between days → changes pay |
| Adding leave pay for monthly staff | **High** | Double-pays salaried employees |
| Turning advisory caps into hard limits | Medium | Silently underpays overtime |
| Payslips on a public disk | **High** | Salary data world-readable |
| Storing derived leave balances | Medium | They drift; current design avoids it |
| Replacing `DpPdf` without sign-off | Medium | Payslip layout changes |
| Committing `sheets/` | **High** | Payroll PII in git history |
| Building `PayrollImport*` tables as if they exist | Low | They do not; §9 of the plan is aspirational |
