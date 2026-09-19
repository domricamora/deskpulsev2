# Routes — complete map

Source of truth: `server/public/index.php` (the whole route table, 178 lines) and the
handler guards in `server/src/dashboard.php`.

> **Replica constraint:** URLs do not change. `claude.md` §51 proposes renames
> (`/app` → dashboard, `/app/team` → teams, `/app/reports` → reports). The real app
> already uses different paths and the user has asked for an exact replica, so the
> table below is authoritative over §51.

## 1. Routing mechanism

Front controller `server/public/index.php` + a small `Router` with `{param}`
placeholders. Base path is **auto-detected**, so the app works under any WAMP
subdirectory (`http://localhost/deskpulsev2/server/public/`) — Laravel must keep
working under a subdirectory, not assume a domain root.

Content routes bind closures because the router passes only `{params}`, so a shared
handler cannot infer its own collection.

## 2. Authorization is two-layer — middleware alone will not replicate it

Guards come in two forms, and **both** must be reproduced:

1. **Route/handler level** — `require_cap('x')`, `require_super()`, `require_staff()`.
2. **In-page branching** — the same page renders differently per capability:

```php
// dash_settings: not a capability route at all
$u = require_login();
if (…) deny_access();                 // managers have no Settings page
$canShare = can($u, 'view_all');      // share-link block hidden otherwise

// dash_subscription
$u = require_login();
require_approved_org($u);
$canManage = can($u, 'subscription'); // read-only vs manage
```

Pages like `/app/overview`, `/app/timesheets` and `/app/tasks` require **only login** —
their content is narrowed by `visible_user_ids()`, and rate columns disappear without
`view_rates`. A Laravel `can:` middleware reproduces layer 1 only; Blade must branch
on the same capabilities for layer 2 or roles will see controls they do not see today.

`require_approved_org()` is a third gate: organizations in `pending` status bounce to
`/app/pending` regardless of role.

## 3. Marketing / public

| Method | Path | Handler |
|---|---|---|
| GET | `/` | `marketing_index` |
| GET | `/pricing` | `marketing_pricing` |
| GET | `/tools/cost-calculator` | `marketing_calculator` |
| GET | `/download` | `marketing_download` |
| GET | `/download/app` | `marketing_download_app` |
| GET | `/robots.txt` | `marketing_robots` |
| GET | `/sitemap.xml` | `marketing_sitemap` |
| GET | `/llms.txt` | `marketing_llms` |
| GET | `/blog` | `marketing_blog_index` |
| GET | `/blog/{slug}` | `marketing_content('blog', …)` |
| GET | `/compare/{slug}` | `marketing_content('compare', …)` |
| GET | `/use-cases/{slug}` | `marketing_content('use-cases', …)` |
| GET | `/privacy` `/terms` `/security` `/about` `/contact` | `marketing_content('pages', …)` |
| GET | `/share/{token}` | `share_view` — public, no auth |

`/llms.txt` is an AI-crawler manifest; keep it. The five static pages are generated in
a loop over markdown in `server/content/pages/`.

## 4. Authentication

| Method | Path | Handler |
|---|---|---|
| GET POST | `/register` | `handle_register` |
| GET POST | `/login` | `handle_login` |
| GET | `/logout` | `handle_logout` |
| GET POST | `/forgot-password` | `handle_forgot_password` |
| GET POST | `/reset-password` | `handle_reset_password` |
| GET | `/auth/{provider}` | `oauth_start` |
| GET | `/auth/{provider}/callback` | `oauth_callback` |

GET and POST share one handler per route — the handler branches on method. See
`authentication.md`.

## 5. Application — `/app/*`

`/app` itself is a **role router**, not a page:

```php
super_admin without act_org → /app/platform
client_viewer               → /app/agents
everyone else               → /app/overview
```

| Path | Handler | Guard |
|---|---|---|
| `/app/pending` | `dash_pending` | login |
| `/app/welcome` | `dash_welcome` | login |
| `/app/onboarding` | `dash_onboarding` | login |
| `/app/overview` | `dash_overview` | login (scoped) |
| `/app/timesheets` | `dash_timesheets` | login (scoped) |
| `/app/tasks` | `dash_tasks` | login (scoped) |
| `/app/profile` | `dash_profile` | login |
| `/app/change-password` | `dash_change_password` | login |
| `/app/settings` | `dash_settings` | login + in-page denial for managers |
| `/app/download` | `dash_download` | login |
| `/app/export.csv` | `dash_export_csv` | login (scoped) |
| `/app/session/{id}` | `dash_session_detail` | login (ownership checked) |
| `/app/agent/{id}` | `dash_agent_info` | login |
| `/app/approvals` | `dash_approvals` | `approve_time` |
| `/app/approvals/{id}` (POST) | `dash_approval_action` | `approve_time` |
| `/app/overtime` | `dash_overtime` | `approve_overtime` |
| `/app/overtime/{id}` (POST) | `dash_overtime_action` | `approve_overtime` |
| `/app/live` | `dash_live` | `live` |
| `/app/live/data` | `dash_live_data` | `live` |
| `/app/screenshots` | `dash_screenshots` | `screenshots` |
| `/app/team` | `dash_team` | `view_all` |
| `/app/share-links` | `dash_share_links` | `view_all` |
| `/app/agents` | `dash_agents` | `view_agents` |
| `/app/agents/{id}` | `dash_agent_detail` | `view_agents` |
| `/app/clients` | `dash_clients` | `clients_manage` |
| `/app/contracts` | `dash_contracts` | `contracts_manage` |
| `/app/billing` `/app/billing.csv` | `dash_billing` | `billing` |
| `/app/reports/efficiency` (+`.csv`) | `dash_efficiency` | `reports` |
| `/app/devices` | `dash_devices` | `devices` |
| `/app/audit` | `dash_audit` | `audit` |
| `/app/import` | `dash_import` | `data_import` |
| `/app/import/template/{key}` | `dash_import_template` | login |
| `/app/subscription` | `dash_subscription` | login + approved org; `subscription` to manage |
| `/app/payment-receipt/{id}` | `dash_payment_receipt` | super, or `subscription` on that org |

### Payroll / HR

| Path | Handler | Guard |
|---|---|---|
| `/app/payroll` | `dash_payroll` | `payroll` |
| `/app/payslips` | `dash_payslips` | `payroll` |
| `/app/payslip` | `dash_payslip` | staff (`require_staff`) |
| `/app/payslip.pdf` | `dash_payslip_pdf` | self, or `payroll` for others |
| `/app/adjustments` | `dash_adjustments` | `pay_adjustments` |
| `/app/leave` | `dash_leave` | any staff; approving needs `leave_approve` |
| `/app/wise` | `dash_wise` | `wise_manage` |
| `/app/salary-run` (+`.csv`) | `dash_salary_run` | `wise_manage` |

`require_staff()` turns away `client_viewer` — a portal login is a customer, not an
employee.

### Messaging

| Path | Handler | Guard |
|---|---|---|
| `/app/messages` | `dash_messages` | `messaging` |
| `/app/notice/{id}/dismiss` (POST) | `dash_notice_dismiss` | login |

### Platform — super admin only

`/app/platform`, `/app/platform/orgs`, `/user/{id}`, `/accounts`, `/billing`,
`/subscription/{id}`, `/subscribers`(+`.csv`), `/accounting`(+`.csv`),
`/sca-public-key.pem`, `/settings`, `/export.sql`, `/act/{id}`, `/return`

All `require_super()`. `/act/{id}` sets `$_SESSION['act_org']`; `/return` clears it.
`/export.sql` streams a full database dump. See `platform.md`.

## 6. Agent + provider webhooks

Device-HMAC (see `api-contract.md`): `/webhooks/auth`, `/me`, `/clients`, `/policy`,
`/tasks` (GET POST), `/tasks/{id}` (DELETE), `/session` (POST),
`/session/{id}` (PATCH), `/session/{id}/task|activity|windows|idle|screenshot` (POST).

Remote control: `/webhooks/remote/poll` (GET), `/webhooks/remote/{id}/frame` (POST),
`/webhooks/remote/{id}/end` (POST).

Provider: `POST /webhooks/wise` — RSA-SHA256, **not** device HMAC; returns 410 while
`pay_method='bank'`.

## 7. Routes in `claude.md` §51 that do not exist

| §51 says | Reality |
|---|---|
| `/app` → dashboard | redirect only; role-dependent target |
| `/app/team` → teams | exists, but `/app/agents` is the client-portal home |
| `/app/reports` | only `/app/reports/efficiency` |
| `/app/platform/settings` | exists — plus 12 other platform routes §51 omits |
| `/api/v1/*` | **does not exist**; agent uses `/webhooks/*` — frozen |

Routes §51 omits entirely: subscription, payment-receipt, overtime, leave,
adjustments, wise, salary-run, payslips, messages, notices, import, share-links,
agents, contracts, session detail, welcome, pending, blog/compare/use-cases, llms.txt,
cost-calculator, and the platform console beyond two paths.

## 8. Laravel destination

| Current | Laravel |
|---|---|
| `public/index.php` route table | `routes/web.php` (+ `routes/agent.php` for `/webhooks`) |
| GET/POST sharing a handler | separate `Route::get` / `Route::post` to the same controller, or `match` |
| `require_cap('x')` | `->middleware('can:x')` |
| `require_super()` | `->middleware('can:platform')` + explicit role check |
| `require_approved_org()` | `EnsureOrganizationApproved` middleware |
| in-page `can()` branching | `@can` in Blade — **must be ported view by view** |
| auto-detected base path | `APP_URL` + `ASSET_URL`; verify under a subdirectory |

## 9. Required tests

- Every route above resolves with the documented guard, for all 7 roles.
- `/app` redirects per role: super→platform, client_viewer→agents, else overview.
- Pending org → `/app/pending` from any `/app/*` route.
- Denial is a redirect to `/app` with a flash, not a 403.
- In-page branching: `/app/settings` hides share links without `view_all`;
  `/app/subscription` is read-only without `subscription`.
- `/app/timesheets` shows only `visible_user_ids()` rows for each role.
- The app serves correctly from a subdirectory.

## 10. Migration risks

| Risk | Severity | Note |
|---|---|---|
| Renaming routes per §51 | **High** | Breaks bookmarks, share links and the user's replica requirement |
| Porting only route-level guards | **High** | In-page branching is where rate/billing/share controls are hidden |
| Missing the routes §51 omits | **High** | ~25 live routes would silently not exist |
| Assuming a domain root | Medium | Dev runs under `/deskpulsev2/server/public/` |
| Splitting GET/POST handlers carelessly | Medium | Shared handlers hold the validation-error re-render path |
