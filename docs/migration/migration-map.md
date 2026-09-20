# Migration map, dependency graph and open decisions

The synthesis document. Read `README.md` first for the index.

## 1. Standing constraints (from the user, this session)

These override the migration plan wherever they conflict:

1. **Exact replica.** Same functions, buttons, look, flow, layout. Only the stack
   changes — Laravel + Tailwind.
2. **Nothing else changes.**
3. **The agent is frozen.** Windows, macOS and Linux agents stay as they are and keep
   pointing at the same API.

Consequences, spelled out because they invert parts of the plan:

| The plan says | Constraint says |
|---|---|
| §12/§57 move the agent to `/api/v1/*` | **No.** `/webhooks/*` is frozen |
| §38 "modernize" the UI | Reproduce it; do not redesign |
| §74 light/dark/system | App is **dark-only**; a light theme is new functionality |
| §51 rename routes | Keep existing URLs |
| §32 audit log "migrate" | Nothing is logged today — it is net-new |
| §39 charts are dependency-free | Chart.js is vendored and required |
| §82 rule 17 no float money | Existing schema already uses `double` |

## 2. Feature map

Each row: **current implementation → tables → route → authorization → agent
dependency → Laravel destination → doc**.

| Feature | Tables | Route | Auth | Agent? | Laravel | Doc |
|---|---|---|---|---|---|---|
| Marketing + CMS | — | `/`, `/blog/*`, `/compare/*`, `/use-cases/*`, `/pricing` | public | no | `MarketingController`, `ContentService` | `marketing-content.md` |
| Downloads | — | `/download`, `/download/app` | public | serves it | `DownloadController` | `marketing-content.md` |
| Password auth | `users` | `/login`, `/register`, `/forgot-password`, `/reset-password` | — | `POST /webhooks/auth` | `Auth\*Controller` | `authentication.md` |
| Password reset | `password_resets` | `/reset-password` | token | no | Laravel broker, existing table | `authentication.md` |
| OIDC + SSO | `user_identities`, org `sso_*` | `/auth/{provider}` | — | no | Socialite + generic OIDC | `authentication.md` |
| Capabilities | `users.role` | all | matrix | no | Gates + enums | `authorization.md` |
| Tenancy | all | all | org scope | yes | scopes (transitive) | `database.md` |
| Dashboard | `sessions` | `/app/overview` | login | reads | `DashboardController` | `routes.md` |
| Timesheets + manual | `sessions` | `/app/timesheets` | login | reads | `TimesheetController` | `monitoring.md` |
| Approvals | `sessions` | `/app/approvals` | `approve_time` | no | `ApprovalController` | `monitoring.md` |
| Overtime | `sessions.overtime_*` | `/app/overtime` | `approve_overtime` | computed at stop | `OvertimeService` | `monitoring.md` |
| Live view | `sessions` | `/app/live`, `/app/live/data` | `live` | heartbeat | `LiveController` | `live-monitoring.md` |
| Screenshots | `screenshots` | `/app/screenshots` | `screenshots` | uploads | `ScreenshotController` | `screenshots.md` |
| Windows/processes | `window_events`, `process_snapshots` | session detail | login | uploads | ingest services | `monitoring.md` |
| Idle | `idle_periods` | session detail | login | uploads | ingest services | `monitoring.md` |
| Tasks | `tasks` | `/app/tasks` | login | CRUD via API | `TaskController` | `api-contract.md` |
| Team/users | `users`, `teams`, `team_members` | `/app/team` | `view_all` | no | `UserController` | `routes.md` |
| Agents roster | `agent_clients` | `/app/agents` | `view_agents` | no | `AgentController` | `routes.md` |
| Clients | `clients` | `/app/clients` | `clients_manage` | `GET /webhooks/clients` | `ClientController` | `billing.md` |
| Contracts | `contracts`, `contract_members` | `/app/contracts` | `contracts_manage` | no | `ContractController` | `billing.md` |
| Client billing | `sessions`, `users.bill_*` | `/app/billing` | `billing` | no | `ClientBillingService` | `billing.md` |
| Pay run | `sessions`, `pay_adjustments`, leave | `/app/payroll` | `payroll` | no | `PayrollService` | `payroll.md` |
| Adjustments | `pay_adjustments` | `/app/adjustments` | `pay_adjustments` | no | `PayAdjustment` | `payroll.md` |
| Leave | `leave_*` | `/app/leave` | request: any; approve: `leave_approve` | no | `LeaveService` | `payroll.md` |
| Payouts | `wise_accounts` | `/app/wise`, `/app/salary-run` | `wise_manage` | no | `PayoutImportService` | `payroll.md` |
| Payslips | `payslips` | `/app/payslips`, `/app/payslip.pdf` | `payroll` / self | no | `PayslipService` | `payroll.md` |
| Payroll import | `clients`, `users`, `sessions` | `/app/import` | `data_import` | no | `PayrollImportService` | `payroll.md` |
| Reports | `sessions` etc. | `/app/reports/efficiency` | `reports` | no | `app/Services/Reports/*` | `reports.md` |
| Share links | `share_links` | `/share/{token}` | public token | no | `ShareController` | `sharing.md` |
| Devices | `devices` | `/app/devices` | `devices` | registers | `DeviceController` | `api-contract.md` |
| Audit (derived) | six sources | `/app/audit` | `audit` | no | `AuditController` | `database.md` §5 |
| Remote control | `remote_sessions`, `remote_input_events` | `/app/remote/*`, `/webhooks/remote/*` | `remote` | yes | `RemoteController` | `remote-control.md` |
| Subscriptions | `invoices`, `payments`, `payment_claims` | `/app/subscription` | `subscription` | no | `SubscriptionService` | `payments.md` |
| Wise payments | `webhook_events`, org `wise_*` | `/webhooks/wise` | RSA | no | `WiseWebhookController` | `payments.md` |
| Messaging | `email_outbox`, `notices`, `notice_reads` | `/app/messages` | `messaging` | no | Laravel Mail + services | `messaging.md` |
| Platform console | all | `/app/platform/*` | super only | no | `PlatformController` | `platform.md` |
| Onboarding | `users.welcomed_at`, `orgs.onboarded_at` | `/app/onboarding`, `/app/welcome` | login | no | `OnboardingController` | `routes.md` |
| Settings | `organizations` | `/app/settings` | login + in-page | policy via API | `SettingsController` | `monitoring.md` |

## 3. Dependency graph

```
                    ┌──────────────────────────┐
                    │ 2. Laravel bootstrap     │
                    │    Tailwind · Vite · CI  │
                    └────────────┬─────────────┘
                                 ↓
                    ┌──────────────────────────┐
                    │ 3. Database (LIVE schema)│  ← 37 tables, not schema.sql
                    └────────────┬─────────────┘
                                 ↓
                    ┌──────────────────────────┐
                    │ 4. Models + tenancy      │  ← transitive scopes
                    └────────────┬─────────────┘
                                 ↓
                    ┌──────────────────────────┐
                    │ 5. Auth · capabilities   │  ← 28 caps, 4 gates, OIDC
                    └────────┬─────────┬───────┘
                             ↓         ↓
        ┌────────────────────┘         └──────────────────┐
        ↓                                                  ↓
┌───────────────────┐                          ┌───────────────────────┐
│ 7. Agent API      │ ← the risk gate          │ 6. Core dashboard     │
│    HMAC /webhooks │                          │    team clients tasks │
└─────────┬─────────┘                          └───────────┬───────────┘
          ↓                                                 ↓
┌───────────────────┐                          ┌───────────────────────┐
│ 8. Agent compat   │ ← real agent, unmodified │ 10. Live view         │
└─────────┬─────────┘                          └───────────────────────┘
          ↓
┌───────────────────┐
│ 9. Monitoring     │ → sessions activity windows processes idle screenshots
└─────────┬─────────┘
          ↓
┌───────────────────┐     ┌──────────────┐     ┌──────────────┐
│ 11. Reports       │ ──→ │ 12. Billing  │ ──→ │ 13. Payroll  │
│     period engine │     │   + clients  │     │   + import   │
└─────────┬─────────┘     └──────┬───────┘     └──────┬───────┘
          ↓                      ↓                     ↓
   ┌─────────────┐        ┌─────────────┐      ┌──────────────┐
   │ 14. Shares  │        │ payments.md │      │ messaging    │
   └─────────────┘        └─────────────┘      └──────────────┘
          ↓
┌───────────────────┐
│ 15. Devices       │ → 16. Remote control (only after devices are stable)
└───────────────────┘
          ↓
┌───────────────────┐
│ 17. Platform      │ → 18. Tailwind UI → 19. Security → 20. Perf → 21. Cutover
└───────────────────┘
```

**Critical path:** 3 → 4 → 5 → 7 → 8. Everything else branches off it. Phase 8 is the
true risk gate: until the unmodified agent tracks a full session against Laravel, no
monitoring work downstream can be trusted.

Marketing/CMS (Phase 18's first item) is **independent** of the whole chain and can run
in parallel from day one.

## 4. The safest first implementation phase

**Phase 2 — Laravel bootstrap, plus Phase 3 prepared but not applied.**

Why this, specifically:

- It adds no behaviour, so nothing can regress. The legacy app keeps serving.
- It is the only phase with **zero** dependency on an unresolved decision in §5.
- It lets CI exist before any logic lands, so every later phase has a gate.

Concretely:

1. Scaffold Laravel 13 **alongside** the legacy app — do not move or delete
   `server/`. Point PHP CLI at 8.3/8.4 (installed at
   `C:\wamp64\bin\php\php8.3.14` and `php8.4.0`); the CLI currently defaults to 7.4,
   which cannot run Laravel 13.
2. Tailwind 4 + Vite, with the **token set from `ui-inventory.md` §2** in `@theme`
   from the start, and the two self-hosted fonts. Getting the palette right first
   avoids restyling every component later.
3. `.env` / `config/deskpulse.php` for the monitoring, agent, screenshot and payments
   config categories (the plan §66).
4. Pest + a MySQL test database; a CI job running Pest and `tools/test_webhook.py`.
5. Base layouts only — `layouts/app` (sidebar shell) and `layouts/public` — plus the
   `x-icon` component carrying the 30 inline SVGs. No business logic.
6. **Generate Phase 3 migrations from the live database** and check them in, but keep
   them unapplied until reviewed against `database.md` §1.

Commit as `chore: bootstrap Laravel DeskPulse application` (the plan §85).

## 5. Open decisions — needed before the phases named

None of these can be settled from the code; each changes output or behaviour.

| # | Decision | Needed before | Default recommendation |
|---|---|---|---|
| D1 | ~~Money: keep `double` or convert?~~ | Phase 3 | **DECIDED 2026-09-20 — keep `double`.** Migrate columns as-is so Phase 11 golden-master can prove parity; convert later as a separate approved change (`database.md` §3) |
| D2 | ~~Overtime day-bucketing: server-local or `report_tz`?~~ | Phase 9 | **DECIDED 2026-09-20 — replicate server-local.** Settled by the standing exact-replica constraint: `report_tz` is the better clock, but switching silently restates historical overtime, and overtime is what gets paid. Correct it later as its own approved change (`monitoring.md` §4) |
| D3 | **Offline replay: duplicate (current) or dedup?** | Phase 9 | Replicate duplication; dedup deviates and changes history (`agent-protocol.md` §5) |
| D4 | ~~Screenshots: public URLs or private disk?~~ | Phase 9 | **DECIDED 2026-09-20 — move to private disk + authorized route.** Images render as now; only the URL shape changes, so existing deep links stop resolving (`screenshots.md` §4) |
| D5 | ~~Remote frames: same question, worse exposure~~ | Phase 16 | **DECIDED 2026-09-20 — move to private disk + authorized route**, and delete the frame when the session ends (`remote-control.md` §5) |
| D6 | ~~Tenancy: transitive scopes or denormalize?~~ | Phase 4 | **DECIDED 2026-09-20 — transitive scopes.** Constrain via the `users` relationship, mirroring `org_user_ids()`. No schema change, no backfill. Denormalising stays a Phase 20 option (`database.md` §2) |
| D7 | **Excel readers: keep hand-rolled or PhpSpreadsheet?** | Phase 13 | Keep; row shape and loose header matching are depended on (`payroll.md` §5) |
| D8 | **`DpPdf`: port or replace?** | Phase 13 | Port, or accept a signed-off visual diff (`payroll.md` §4) |
| D9 | **Arbitrary SQL upload: keep, gate, or drop?** | Phase 17 | Drop once `artisan migrate` exists (`platform.md` §5) |
| D10 | **Reset: add transaction + confirmation phrase?** | Phase 17 | Yes — no tenant-visible change (`platform.md` §5) |
| D11 | ~~`close_stale_sessions()`: keep on the live poll or schedule it?~~ | Phase 10 | **DECIDED 2026-09-20 — kept on the render.** Runs from `NavigationComposer::maintenance()`, where `nav_context()` called it. Scheduling closes sessions earlier for any org that never opens the dashboard, changing their hours (`monitoring.md` §3) |
| D12 | ~~Screenshot retention: opportunistic or scheduled?~~ | Phase 9 | **DECIDED 2026-09-20 — both.** `screenshots:prune` nightly, and the 1-in-50 purge on upload kept, because shared hosting gives no guarantee `schedule:run` is wired up (`screenshots.md` §5) |
| D13 | **CSP: drop `unsafe-inline` after Tailwind?** | Phase 18 | Yes, as a separate verified pass (`security.md` §3.3) |
| D14 | **Audit log: build it (plan §32) or keep the derived view?** | Phase 17 | Replica ⇒ derived view. Building it is new scope (`database.md` §5) |
| D15 | ~~Legacy `v1:` ciphertext: port `dp_decrypt()` or re-enter secrets?~~ | Phase 5 | **DECIDED 2026-09-20 — ported.** `App\Support\LegacyCipher` reads and writes the same `v1:` envelope, so existing ciphertext keeps opening and a rollback to the legacy app still works. Needed earlier than Phase 21: enterprise SSO cannot resolve a client secret without it (`security.md` §4) |
| D16 | ~~Schema: normalise to Laravel conventions now or later?~~ | Phase 4 | **DECIDED 2026-09-20 — later.** Keep the production schema untouched for the whole migration; the model layer absorbs all seven breaks. Normalise, and update the code to match, as one approved pass after Phase 21 (`database.md` §7a) |

**D1, D2, D4, D5, D6, D11, D12, D15 and D16 are settled** (2026-09-20). Seven remain
open — D3, D7, D8, D9, D10, D13, D14 — and the next one needed is **D3 (offline
replay: duplicate or dedup)**, which Phase 9 did not have to answer because the
replay path is ingest, already built in Phase 7.

## 6. Highest-risk items overall

| Risk | Where |
|---|---|
| Remote frames at a sequential public URL | `remote-control.md` §5 |
| Screenshots world-readable by URL | `screenshots.md` §4 |
| Converting only `schema.sql` — loses 7 tables incl. the payments ledger | `database.md` §1 |
| Moving the agent off `/webhooks/*` | `api-contract.md` §7 |
| Verifying HMAC over re-encoded JSON | `api-contract.md` §1 |
| Returning 422 to a queued agent endpoint | `agent-protocol.md` §4 |
| Porting 16 of 28 capabilities | `authorization.md` §2 |
| Tenant isolation tested only on `org_id` tables | `database.md` §2 |
| Reset without transaction/confirmation | `platform.md` §5 |
| Arbitrary SQL execution endpoint | `platform.md` §5 |
| Omitting OIDC/SSO (absent from the plan) | `authentication.md` §6 |
| Paywall applied to `/webhooks/*` | `authentication.md` §2 |

## 7. Scope the plan omits entirely

Live, routed, and absent from the plan's document list — all audited here:

OIDC federated sign-in · enterprise SSO · subscription payments · payment claims ·
Wise payouts + SCA · promo codes · messaging, reminders and notices · email outbox and
SMTP · PDF payslips · leave management · pay adjustments · salary runs · overtime
approval · markdown CMS (blog/compare/use-cases) · cost calculator · `llms.txt` ·
multi-platform downloads · arbitrary SQL upload · 12 platform routes.

Roughly **3,900 lines of PHP** and 13 tables the plan's §2 reading list does not cover.
