# Platform administration — the super-admin console

Source of truth: `server/src/dashboard.php` (`dash_platform*`, `platform_reset_data`,
`seed_demo_data`, `platform_run_sql_file`, `export_sanitize_ddl`).

Every route here is `require_super()` — an exact `role === 'super_admin'` check, **not**
`can('platform')`. See `authorization.md` §5.

## 1. Routes

| Path | Purpose |
|---|---|
| `/app/platform` | console + actions (approve/reject/delete orgs, seed, reset, run SQL) |
| `/app/platform/orgs` | organization list + create-org form |
| `/app/platform/user/{id}` | account detail |
| `/app/platform/accounts` | every account across tenants |
| `/app/platform/billing` | per-employee customer billing, rates |
| `/app/platform/subscription/{id}` | one org's subscription console |
| `/app/platform/subscribers` (+ `.csv`) | who, what plan, still active? |
| `/app/platform/accounting` (+ `.csv`) | the money view (`payments.md` §7) |
| `/app/platform/sca-public-key.pem` | SCA public key |
| `/app/platform/settings` | platform settings, mail, prices, retention |
| `/app/platform/export.sql` | full database dump |
| `/app/platform/act/{id}` | **act as** an organization |
| `/app/platform/return` | stop acting |

## 2. Act-as

`/act/{id}` sets `$_SESSION['act_org']`; `current_user()` then overrides the super
admin's effective `org_id`, validating the id against the database first. `/return`
clears it. While acting, every org-scoped page works unchanged, and the live view
switches from platform mode to team mode (`live-monitoring.md` §2).

The app shell shows a persistent `.acting-banner` so the operator always knows they are
inside a tenant (`ui-inventory.md` §6). Do not lose that banner — it is the only signal
distinguishing a tenant view from the platform view.

`subscription` is in `$superHidden`: the platform org never pays itself.

## 3. Signup review

Marketing registrations create a `pending` org; the user waits at `/app/pending`. A
super admin approves, rejects or deletes on the Platform page, and approval starts a
trial (`start_trial()`). Org columns: `status`, `billing_status`, `monthly_fee`,
`billing_currency`, `billing_started_at`, `reviewed_at`.

A super admin can also create an approved org directly, with a `client_admin` login; a
blank password auto-generates a temporary one and sets `must_change_password`.

## 4. Demo data

`seed_demo_data()` builds a full demo org — admin, manager, HR, IT, members, a client
portal login, teams, clients + contracts, agent assignments, tasks and a week of
sessions — and is **idempotent**.

The migration plan §69 asks that demo credentials come from configuration rather than
hardcoded passwords. Check this during Phase 17 and move them to `.env` if they are
literals today.

## 5. Destructive operations — weaker than the plan assumes

### `platform_reset_data()`

```php
$platformOrg = (int) (db_one('SELECT org_id FROM users WHERE id = ?', [$u['id']])['org_id'] ?? $u['org_id']);
db_exec('DELETE FROM organizations WHERE id <> ?', [$platformOrg]);   // cascades all tenant data
db_exec("DELETE FROM users WHERE role <> 'super_admin'");
return [true, 'All tenant data deleted — only the super admin account remains.'];
```

Triggered by a plain `POST` with `action=reset_data`.

The migration plan §50 requires **super_admin + explicit confirmation + audit log +
transaction**, with "an unmistakable confirmation phrase". Actual state:

| §50 requirement | Present? |
|---|---|
| `super_admin` | **yes** — `require_super()` |
| Explicit confirmation | **client-side only** — no server-side phrase check |
| Audit log | **no** — no audit table exists (`database.md` §5) |
| Transaction | **no** — two bare `db_exec()` calls |

So today, a single POST from an authenticated super admin destroys every tenant, with
no server-side confirmation, no record of who did it, and no atomicity. If the second
delete fails, the first has already committed.

> **Recommendation.** This is one of the few places where implementing the plan's
> requirement is strictly better and carries **no** behavioural risk to tenants:
> wrap in a transaction, require a typed confirmation phrase server-side, and record
> the action. Worth doing in Phase 17. It needs sign-off only because it adds a step
> to an operator flow.

### `platform_run_sql_file()` — arbitrary SQL execution

Not mentioned anywhere in the migration plan. A super admin uploads a `.sql` file
(≤16 MB) which is comment-stripped, split on `;` and executed statement by statement,
reporting successes and failures. The docblock is candid: *"a simple splitter, not a
full SQL parser — it won't handle stored-procedure DELIMITER blocks."*

This is effectively **remote arbitrary SQL execution against production**, gated only
by the super-admin role. It exists for DB maintenance (migrations, schema updates, data
fixes) — a real need in a system with no migration tool.

> **Recommendation.** Laravel has `php artisan migrate`, which removes the original
> justification. Options: (a) port as-is, (b) port behind an extra confirmation +
> audit record, or (c) drop it in favour of artisan migrations. **(c) is the right
> answer once migrations exist**, but it removes an operator capability, so it needs
> the user's explicit decision. Flagged in `migration-map.md`.

## 6. Database export

`GET /app/platform/export.sql` → `dash_platform_export`.

- A **pure-PHP MySQL dump** of the whole database — no `mysqldump` dependency —
  streamed as a `.sql` download.
- `export_sanitize_ddl()` downgrades MySQL 8.0 collations
  (`utf8mb4_0900_*` → `utf8mb4_unicode_ci`, `utf8mb3` → `utf8`) so the file restores on
  **older MySQL 5.6+/MariaDB**. Keep this: it is why the dump is portable.
- Rows stream in **paginated batched INSERTs**, so memory stays flat.
- Screenshots live on disk (`uploads/`), **not** in the dump.

The migration plan §49 asks the export to "exclude secrets". It currently does **not** —
the dump includes `organizations.wise_api_token_enc` and `wise_sca_private_enc`. Those
are AES-256-GCM ciphertext, not plaintext, and are useless without `app_secret`
(`payments.md` §6) — which is exactly why they are encrypted. Worth stating explicitly
in the `DatabaseExportService` so nobody later "helpfully" decrypts them.

## 7. Laravel destination

| Current | Laravel |
|---|---|
| `dash_platform*` | `PlatformController` + `can:platform` **and** an explicit super check |
| act-as | `ResolveOrganization` middleware + `ActAsController` |
| `seed_demo_data()` | `DemoOrganizationSeeder` et al. (the migration plan §69), credentials from config |
| `platform_reset_data()` | `PlatformResetService` — transaction + confirmation phrase + record |
| `platform_run_sql_file()` | prefer `artisan migrate` — see §5 |
| `dash_platform_export` | `DatabaseExportService`, streamed (the migration plan §49) |
| `export_sanitize_ddl()` | port verbatim |

## 8. Required tests

- Every platform route refuses all six non-super roles, including one holding
  `platform` by some other means.
- Act-as: sets and clears; a forged or non-existent `act_org` is ignored; a non-super
  session cannot set it; the acting banner renders while active.
- `subscription` hidden from super-admin nav.
- Approve/reject/delete transitions an org's `status` and stamps `reviewed_at`;
  approval starts a trial.
- Create-org makes an approved org + a `client_admin` with `must_change_password` when
  the password is blank.
- `seed_demo_data()` twice ⇒ no duplicates.
- Reset removes every tenant org and non-super user, keeps the platform org; after the
  §5 change it is atomic and requires the phrase.
- Export: streams, memory stays flat on a large database, restores on MariaDB, contains
  no plaintext secrets and no screenshot binaries.
- SQL upload (if retained): rejects >16 MB, non-SQL and empty files; reports per-statement
  results.

## 9. Migration risks

| Risk | Severity | Note |
|---|---|---|
| Treating `require_super()` as `can('platform')` | **High** | A future role with `platform` would gain the whole console |
| Porting reset without transaction/confirmation | **High** | One POST destroys every tenant, unrecorded |
| Porting arbitrary SQL execution unchanged | **High** | Remote SQL execution once migrations make it unnecessary |
| Losing the acting banner | Medium | Operator cannot tell tenant view from platform view |
| Loading the export into memory | Medium | OOM on a real database |
| Dropping `export_sanitize_ddl()` | Medium | Dumps stop restoring on MariaDB/older MySQL |
| Decrypting secrets into the export | **High** | Ciphertext is the current protection |
| Hardcoded demo passwords | Medium | The migration plan §69 asks for configured credentials |
