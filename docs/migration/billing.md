# Billing — client charges vs internal labor cost

Source of truth: `server/src/dashboard.php` (`compute_billing`), `server/src/auth.php`
(`user_hourly_rate`, `user_bill_rate`).

Platform subscription billing (what orgs pay **DeskPulse**) is a different subsystem —
see `payments.md`.

## 1. The two-rate model

| Concept | Field pair | Meaning | Who may see it |
|---|---|---|---|
| **Internal labor cost** | `users.pay_type`, `users.pay_rate` | what the org pays the worker | `view_rates` |
| **Client billing** | `users.bill_type`, `users.bill_rate` | what the client is charged | `billing` |

`user_hourly_rate()` = cost. `user_bill_rate()` = charge. They are never
interchangeable, and **labor cost is never exposed to a `client_viewer`** — that role
holds `billing` but not `view_rates`, so it sees only what its own client is charged.

`bill_type` / `pay_type` are each `hourly` or `monthly`.

Client rates also exist on `clients.bill_rate` and `contracts.bill_rate`; the per-user
`bill_rate` is what `compute_billing()` uses.

## 2. `compute_billing($u, $start, $end, $clientFilter = null)`

```php
$ids       = visible_user_ids($u);                    // viewer scope
$sessions  = sessions_for_users($ids, $start, $end);  // APPROVED ONLY
$days        = max(1, (strtotime($end) - strtotime($start)) / 86400);
$daysInMonth = (int) date('t', strtotime($start));
$monthFactor = min(1.0, $days / $daysInMonth);        // proration for flat charges
```

Then per session, accumulating **`active_s`**:

- `agentTotalSecs[uid]` — all of the agent's time, regardless of client filter
- `agentSecs[uid]` — time inside the filter
- `agentClientSecs[uid][cid]` — time per client (`cid = 0` ⇒ "Unassigned")

Per agent:

```php
$monthly = ($usr['bill_type'] ?? 'hourly') === 'monthly';
$rate    = user_bill_rate($usr);
$amount  = $monthly ? $rate * $monthFactor      // flat service charge, prorated
                    : $hours * $rate;           // hourly
```

For a **single-client view**, a monthly agent's flat charge is further split by that
client's share of the agent's total time — which is why `agentTotalSecs` is tracked
even when filtered out. This is the "prorating/splitting behaviour" of `claude.md` §21,
and it is subtle: the divisor is the agent's *total* time, not the filtered time.

Output is two rollups: `byAgent` and `byClient`, plus `Unassigned` for sessions with no
client.

## 3. Behaviours that are easy to get wrong

1. **Approved sessions only.** `sessions_for_users()` filters on approval, so pending
   manual entries are not billed until a manager approves.
2. **Billing uses `active_s`; pay uses `creditable_active_s()`.** Billing charges all
   active time *including unapproved overtime*; payroll withholds it. The two figures
   legitimately disagree, and that asymmetry must be preserved — "fixing" it changes
   invoices.
3. **Proration anchors on the period start month.** `date('t', start)` — a period
   spanning a month boundary uses the *start* month's length.
4. **`monthFactor` is capped at 1.0** — a period longer than a month never charges more
   than one flat fee.
5. **Inactive time is never billed.** Only `active_s` enters any total.
6. `$days` uses a plain 86400-second divisor, so a DST transition inside the period
   shifts it by an hour's worth of fraction. Faithful replication means reproducing
   this, not "correcting" it.

## 4. Money is computed in PHP floats

`$rate * $monthFactor`, `$hours * $rate` — all float arithmetic over `double` columns
(`users.bill_rate` is `double`; see `database.md` §3).

`claude.md` §82 rule 17 forbids floats for money, but the current system uses them
end to end. Converting to integer cents or `decimal` **changes output** at the cent
level and breaks golden-master comparison.

> **Decision required before Phase 12.** Recommendation: port the arithmetic as-is so
> Phase 11 can prove parity, then convert under test with explicit approval. If
> converting, `BillingCalculator` should take and return minor units and round once, at
> the end — not per line.

## 5. Laravel destination

| Current | Laravel |
|---|---|
| `compute_billing()` | `app/Services/Billing/ClientBillingService` |
| hourly/monthly branch | `BillingCalculator` |
| `user_hourly_rate()` | `LaborCostCalculator` |
| `user_bill_rate()` | `BillingCalculator::rateFor()` |
| `/app/billing`, `/app/billing.csv` | `BillingController@index`, `@export` |
| CSV | `ReportExportService`, streamed (`claude.md` §41) |

Rate exposure must be enforced **server-side**: a viewer without `view_rates` must not
receive cost fields in the response or the CSV at all — not merely have them hidden in
Blade (`claude.md` §23).

## 6. Required tests

Per `claude.md` §60 — hourly, monthly, proration, per-user, per-client:

- Hourly agent: `hours × bill_rate`.
- Monthly agent: `bill_rate × monthFactor`; a full month ⇒ exactly one fee.
- Semi-monthly halves sum to one monthly fee (1st–15th + 16th–end).
- Period longer than a month ⇒ `monthFactor` capped at 1.0.
- Client-filtered monthly agent ⇒ flat fee split by that client's share of the agent's
  **total** time.
- Sessions with no client roll into `Unassigned`.
- Pending manual entries excluded; approving one changes the total.
- Unapproved overtime **is** billed but **is not** paid.
- `client_viewer` sees only its own client and **no labor cost field** in HTML, JSON
  or CSV.
- A viewer without `view_rates` receives no cost data server-side.
- Golden master: totals match the legacy system on identical fixture data.

## 7. Migration risks

| Risk | Severity | Note |
|---|---|---|
| Exposing labor cost to `client_viewer` | **Critical** | Reveals margin to the customer |
| Hiding rates in Blade only | **High** | §23 requires server-side enforcement |
| Unifying billing and pay on one seconds figure | **High** | They deliberately differ over unapproved overtime |
| Converting float money mid-migration | **High** | Changes invoices; breaks golden master |
| Prorating on the period-end month | Medium | Off-by-a-day/fee at month boundaries |
| Splitting a monthly fee by filtered time | Medium | Divisor is the agent's total time |
| Billing unapproved sessions | Medium | Over-invoices clients |
| Including `inactive_s` | Medium | Inflates every invoice |
