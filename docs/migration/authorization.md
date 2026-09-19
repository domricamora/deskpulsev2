# Authorization — current behaviour

Source of truth: `server/src/auth.php` (`role_caps()`, `can()`, `require_cap()`).
Authorization is **already capability-based**. Roles are never checked by name in
feature code; `require_cap('x')` is. The migration must preserve this shape.

## 1. Current implementation

```php
role_caps(string $role): array   // static matrix, role => [capabilities]
can(array $u, string $cap): bool // in_array('*') || in_array($cap)
require_cap(string $cap): array  // require_login() then can(), else deny_access()
require_super(): array           // role === 'super_admin' exactly
is_admin(array $u): bool         // alias for can($u,'users_manage')
```

`super_admin` holds the wildcard `*`, so it satisfies every `can()` check.

### deny_access() is a redirect, not a 403

```php
flash("You don't have access to that page.", 'error');
redirect('/app');
```

An authenticated user hitting a page above their role is **bounced to `/app` with a
flash notice** — not given a 403. `/app` then re-routes per role. Unauthenticated
users are redirected to `/login` earlier, by `require_login()`.

> Replica requirement: Laravel's default is a 403 `AuthorizationException`. To match
> current behaviour the denial must render as a redirect to `/app` with a flash.
> Do not ship Laravel's default 403 page for these routes.

## 2. The capability set — 28 capabilities

`claude.md` §4 lists **16**. The application defines **28**. Migrating only the
listed 16 would silently drop 12 authorization controls.

Documented in the `role_caps()` docblock (25):

| Capability | Meaning |
|---|---|
| `view_all` | see every member in the org |
| `view_team` | see only members of teams the user belongs to |
| `reports` | time/activity/productivity reports |
| `screenshots` | view screenshots |
| `live` | live team view |
| `approve_time` | approve/reject manual time entries |
| `approve_overtime` | approve/reject the overtime portion before it is paid (HR) |
| `users_manage` | create/edit users, assign roles, team membership |
| `profiles_manage` | edit employee profiles (HR) |
| `view_rates` | see/edit pay & bill rates |
| `billing` | client billing |
| `clients_manage` | manage clients & contracts |
| `contracts_manage` | assign members to a contract roster (no client CRUD / portal logins) |
| `manage_agents` | assign clients to agents — edits the roster |
| `view_agents` | read-only agent roster + detail (implied by `manage_agents`) |
| `org_settings` | monitoring policy / company settings |
| `devices` | manage agent installs / devices (IT) |
| `audit` | audit / security log (IT) |
| `remote` | remote desktop control, org-scoped for non-super callers |
| `data_import` | bulk payroll Excel import into the uploader's own org |
| `pay_adjustments` | bonuses, commissions, reimbursements, deductions |
| `leave_approve` | administer leave types/entitlements, approve requests |
| `wise_manage` | maintain payout details, run the salary-run export |
| `messaging` | notices, custom messages, reminder mail |
| `platform` | cross-tenant platform console (super only) |

Present in the matrix but **absent from the docblock** — undocumented, still enforced (3):

| Capability | Granted to | Gates |
|---|---|---|
| `set_pay_rate` | `client_admin`, `hr_manager` | writing a member's pay rate |
| `payroll` | `client_admin`, `hr_manager` | `/app/payslips`, other people's payslip PDFs |
| `subscription` | `client_admin` | `/app/subscription`, payment claims |

## 3. Role → capability matrix (verbatim from `role_caps()`)

| Role | Capabilities | Count |
|---|---|---|
| `super_admin` | `*` — all | all |
| `client_admin` | view_all, reports, screenshots, live, approve_time, approve_overtime, users_manage, profiles_manage, view_rates, billing, clients_manage, contracts_manage, org_settings, devices, audit, manage_agents, set_pay_rate, payroll, remote, subscription, data_import, pay_adjustments, leave_approve, wise_manage, messaging | 25 |
| `hr_manager` | view_all, reports, approve_time, approve_overtime, profiles_manage, set_pay_rate, payroll, contracts_manage, data_import, pay_adjustments, leave_approve, wise_manage, messaging | 13 |
| `manager` | view_team, reports, screenshots, live, approve_time, manage_agents, view_agents, leave_approve, messaging | 9 |
| `it_admin` | view_all, devices, audit, org_settings, remote | 5 |
| `client_viewer` | view_team, reports, screenshots, billing, view_agents | 5 |
| `member` | *(none — self only)* | 0 |

### Non-obvious rules that MUST survive the migration

1. **`hr_manager` can set pay but cannot view rates.** It holds `set_pay_rate` and
   `payroll` but **not** `view_rates`. Granting HR `view_rates` would be a privilege
   escalation against current behaviour.
2. **`hr_manager` has no `screenshots`.** HR sees time and money, never monitoring
   imagery. (`claude.md` §23 asks for a test proving this — it holds today.)
3. **`it_admin` has `remote`** — IT can remote-control machines — but has no
   `reports`, `screenshots`, `view_rates` or `approve_time`.
4. **`client_viewer` has `screenshots` and `billing`**, but not `view_rates`. Its
   billing view is filtered to its own client — what that client is *charged*, never
   internal labor cost.
5. **`member` has zero capabilities.** All member access is "self only", enforced by
   scoping helpers rather than by the matrix.
6. **`manager` is team-scoped** via `view_team`, resolved through `team_members`.
7. `super_admin` is excluded from `subscription` in the nav via a `$superHidden` list
   — the platform org never pays itself — even though `*` would grant it.

## 4. Scoping — capability is only half of it

A capability says *what*; scoping says *whose rows*. Two helpers do the second half:

- `visible_user_ids()` — the users the caller may see. `view_all` → the whole org;
  `view_team` → team membership; `member` → self.
- `client_viewer_user_ids()` — for a portal login, the agents assigned to or logging
  time for **its own client** (`clients.user_id` links the login to the client).

Both must become query scopes in Laravel. A capability check alone does not isolate
tenants or teams.

## 5. Laravel destination

| Current | Laravel |
|---|---|
| `role_caps()` matrix | `app/Enums/Capability.php` + `app/Enums/UserRole.php` (`UserRole::capabilities()`) |
| `can($u,$cap)` | `Gate::define()` per capability, plus `Gate::before` for the `super_admin` wildcard |
| `require_cap($cap)` | route middleware `can:<capability>` |
| `deny_access()` | exception handler mapping `AuthorizationException` → redirect `/app` + flash |
| `require_super()` | `can:platform` **plus** an explicit role check — `*` and `platform` are not identical |
| `visible_user_ids()` | Eloquent scope `scopeVisibleTo(User $u)` |
| `client_viewer_user_ids()` | Eloquent scope on the portal login's client |

## 6. Required tests

- Every (role × capability) pair — 7 × 28 = 196 assertions against the table above.
- `hr_manager` cannot load any screenshot route.
- `hr_manager` can set a pay rate, but rate columns/JSON are **absent** without `view_rates`.
- `client_viewer` sees only its own client's agents, and no labor-cost figure anywhere.
- `member` cannot read another member's session, screenshot, payslip or rate.
- `it_admin` can open remote control; cannot open reports.
- Denial renders as redirect-to-`/app`-with-flash, not 403.
- `super_admin` passes every gate except the nav-hidden `subscription`.

## 7. Migration risks

| Risk | Severity | Note |
|---|---|---|
| Porting only the 16 capabilities named in `claude.md` §4 | **High** | Drops 12 real controls, including all payroll / leave / messaging / import gating |
| Granting HR `view_rates` "for consistency" | **High** | Privilege escalation vs. today |
| Laravel's default 403 replacing the redirect | Medium | Visible flow change; an exact replica is required |
| Treating `require_super()` as `can('platform')` | Medium | Any future role holding `platform` would gain super-admin routes |
| Implementing capabilities without the scoping helpers | **High** | Capability alone leaks other teams' and tenants' rows |
