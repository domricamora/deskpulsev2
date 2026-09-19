# Database — current schema

Ground truth for this page is the **live `deskpulse` database** introspected via
`information_schema`, cross-checked against `server/schema.sql` and
`server/src/db.php`. Where the two disagree, the live database wins — it is what the
application actually runs against.

## 1. The headline finding: `schema.sql` is not the schema

`claude.md` §7 says "Convert `server/schema.sql` into Laravel migrations." **Doing only
that loses 7 tables and 10 columns.**

| Source | Tables | Columns |
|---|---|---|
| `server/schema.sql` | 30 | 371 |
| Live database | **37** | **439** |

Every one of the 371 columns in `schema.sql` exists live (nothing is stale or
renamed) — it is accurate as far as it goes. It simply stops short. The remainder is
created at runtime by `ensure_migrations()` in `server/src/db.php` (~700 lines of
`add_columns()` and `CREATE TABLE`), which runs on first request.

### Tables that exist ONLY in `db.php`

| Table | Carries |
|---|---|
| `payments` | the money ledger — settled subscription payments |
| `invoices` | issued subscription invoices |
| `payment_claims` *(in schema.sql)* | — listed for contrast: customer-asserted payments |
| `webhook_events` | inbound webhook dedup (`X-Delivery-Id`) |
| `password_resets` | reset tokens (`token_hash`, `expires_at`, `used_at`, `requested_ip`) |
| `user_identities` | OIDC/SSO identity links (`provider`, `subject`) |
| `promo_codes` | discount codes |
| `promo_redemptions` | code redemptions per org |

Losing these would delete the entire payments ledger, password reset, federated
sign-in and promo machinery.

### Columns on `organizations` only in `db.php` (10)

`billing_period_count`, `billing_period_unit`, `current_period_end`,
`ga4_measurement_id`, `pay_reference`, `price_per_seat`, `promo_code`,
`subscription_status`, `trial_days`, `trial_ends_at`

> **Phase 3 rule:** generate migrations from the **live database**, then verify by
> rebuilding into a throwaway schema and diffing against `information_schema`. Do not
> transcribe `schema.sql`.

## 2. Tenancy is NOT a column on most tables

`claude.md` §8 proposes a global `OrganizationScope` filtering `organization_id`.
**17 of 37 tables have no `org_id` at all**, including the busiest ones:

```
activity_samples  agent_clients   contract_members  devices
idle_periods      notice_reads    password_resets   process_snapshots
promo_codes       remote_input_events  screenshots  sessions
team_members      user_identities  webhook_events   window_events
organizations
```

`sessions` columns — note the absence:

```
id user_id project_id task_id device_id started_at ended_at active_s inactive_s
source note approval_status reviewed_by_id reviewed_at review_note client_id
last_seen_at overtime_s overtime_status overtime_reviewed_by_id
overtime_reviewed_at overtime_review_note overtime_computed
```

Tenancy is reached **transitively**:

```
sessions            → user_id     → users.org_id
screenshots         → session_id  → sessions.user_id → users.org_id
activity_samples    → session_id  → …
window_events       → session_id  → …
process_snapshots   → session_id  → …
idle_periods        → session_id  → …
devices             → user_id     → users.org_id
team_members        → team_id     → teams.org_id
```

The application enforces this through `org_user_ids($orgId)` and `visible_user_ids()`,
which resolve a user-id set and then filter `WHERE user_id IN (…)`.

**Implication for Phase 4/8.** A single global `organization_id` scope cannot be
applied. Options:

- (a) **Replicate**: model scopes that constrain via the `users` relationship —
  `whereHas('user', fn($q) => $q->where('org_id', $org->id))` or a join on a
  cached id set, mirroring `org_user_ids()`. No schema change, exact parity.
- (b) **Denormalize**: add `org_id` to the child tables and backfill. Faster queries
  and a uniform scope, but it is a schema change, needs backfill + triggers to stay
  correct, and diverges from "nothing else changes".

Default to (a) for parity; (b) is a Phase 20 performance option, not a Phase 3 one.

> Whichever is chosen, the mandatory test from `claude.md` §8 — *Org A cannot retrieve
> Org B records* — must cover the **transitive** tables, not just those with `org_id`.

## 3. Money is stored three different ways

| Convention | Columns |
|---|---|
| `double` | `users.pay_rate`, `users.bill_rate`, `clients.bill_rate`, `contracts.bill_rate`, `projects.hourly_rate`, `organizations.monthly_fee`, `custom_fee`, `seat_rate`, `price_per_seat`, `price_seat_cap`, `price_solo` |
| `decimal(10,2)` / `decimal(12,2)` | `organizations.price_individual`, `price_organization`, `pay_adjustments.amount`, `payslips.gross`, `net`, `deductions` |
| `int` (cents) | `invoices.amount_cents`, `payments.amount_cents`, `payment_claims.amount_cents` |

`claude.md` §82 rule 17 says *"never use floating-point values for monetary
calculations."* **The existing schema already violates this** — the two rates that
drive nearly all money (`pay_rate`, `bill_rate`) are `double`.

This is a direct conflict with "exact replica":

- Keeping `double` preserves every computed figure bit-for-bit and keeps golden-master
  comparison meaningful, but carries the float imprecision forward.
- Converting to `decimal` is the correct engineering choice but **will change
  outputs** at the cent level for some orgs, breaking golden-master equality and any
  historical reconciliation.

> **Decision required before Phase 3.** Recommendation: migrate the columns as-is
> (`double` → `double`) so Phase 11 golden-master tests can prove parity, and schedule
> a separate, tested, explicitly-approved conversion afterwards. Tracked in
> `migration-map.md`.

## 4. Table inventory (37)

**Tenancy & identity** — `organizations`, `users`, `teams`, `team_members`,
`user_identities`, `password_resets`

**Work & clients** — `clients`, `contracts`, `contract_members`, `agent_clients`,
`tasks`, `projects` *(deprecated)*

**Monitoring** — `sessions`, `activity_samples`, `window_events`,
`process_snapshots`, `idle_periods`, `screenshots`, `devices`

**Remote control** — `remote_sessions`, `remote_input_events`

**Payroll & HR** — `pay_adjustments`, `leave_types`, `leave_requests`,
`leave_entitlements`, `wise_accounts`, `payslips`

**Billing & platform** — `invoices`, `payments`, `payment_claims`, `promo_codes`,
`promo_redemptions`, `webhook_events`

**Messaging** — `email_outbox`, `notices`, `notice_reads`

**Sharing** — `share_links`

### `projects` is deprecated

Retired in favour of tracking work directly against **clients**; kept only for
migration backfill of `client_id`. `sessions.project_id` and `tasks.project_id`
persist. Live row count: **0**. Port the table and columns (rule 18: never silently
discard records) but build nothing on it.

## 5. There is no audit table

`/app/audit` exists and is gated by the `audit` capability, but **no audit log is
written anywhere**. `dash_audit()` synthesises a feed at read time by UNION-ing
timestamps already present in other tables:

```
sessions.started_at        → "Session started" / "Manual entry created"
sessions.reviewed_at       → "Time entry approved/rejected"
devices.created_at         → "Device registered"
share_links.created_at     → "Share link created"
remote_sessions.started_at → "Remote session started"
remote_sessions.ended_at   → "Remote session ended"
```

…sorted desc, capped at 120 rows.

Consequences:

- Nothing in `claude.md` §32's list — login, role change, user creation/deletion, rate
  change, billing change, screenshot access, payroll import, data deletion — is
  recorded today. Those events are **unauditable retrospectively**.
- §32 is therefore **net-new functionality, not a migration**. Under "nothing else
  changes" it is out of scope for parity; building it is a deliberate addition.
- For an exact replica, `/app/audit` must reproduce the derived feed, same six
  sources, same ordering, same 120-row cap.

## 6. Indexes

Every `org_id` column is indexed. Composite index review against real query shapes
belongs in Phase 20; the Phase 3 job is to reproduce existing indexes exactly, since
report SQL was written against them.

## 7. Laravel destination

| Current | Laravel |
|---|---|
| `schema.sql` + `ensure_migrations()` | `database/migrations/` generated from the **live** schema |
| `ensure_schema()` auto-create on request | `php artisan migrate` — no runtime DDL |
| `org_user_ids()` / `visible_user_ids()` | Eloquent scopes (see `authorization.md`) |
| `db_one/db_all/db_exec` | Eloquent + query builder |

**Drop the auto-migration pattern.** Creating schema during a web request is a
production hazard and has no Laravel equivalent. Migrations become explicit and
versioned — one of the few places the migration should *not* replicate behaviour.

## 8. Required tests

- Rebuild migrations into an empty database; diff `information_schema` against the
  live schema — **zero** differences in tables, columns and types.
- Tenant isolation across the **transitive** tables (`sessions`, `screenshots`,
  `activity_samples`, `window_events`, `process_snapshots`, `idle_periods`,
  `devices`) — Org A must not read Org B.
- `projects` migrates with its columns intact and zero rows lost.
- Money columns keep their existing SQL types (per the §3 decision).
- `/app/audit` renders the same six derived event kinds, same cap.

## 9. Migration risks

| Risk | Severity | Note |
|---|---|---|
| Converting only `schema.sql` | **Critical** | Loses 7 tables incl. the payments ledger, and 10 columns |
| Applying a flat `organization_id` scope | **High** | 17 tables lack the column; scope silently matches nothing or errors |
| Testing isolation only on `org_id` tables | **High** | Leaves the monitoring tables — the sensitive ones — unproven |
| Converting `double` money to `decimal` mid-migration | **High** | Changes figures; destroys golden-master comparability |
| Treating `/app/audit` as an existing audit trail | Medium | It is derived; no historical record exists to migrate |
| Dropping `projects` as dead | Medium | Violates rule 18; `client_id` backfill provenance is lost |
| Porting runtime auto-migration into Laravel | Medium | DDL on a web request |
