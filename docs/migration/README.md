# Phase 1 — architectural audit

Documents the existing DeskPulse application as the behavioural reference for the
Laravel + Tailwind migration. **No application code was changed to produce this.**

The baseline commit is a byte-identical snapshot of the production tree
(`vt-deskpulse` @ `e7a1ad8`) for `server/src`, `server/schema.sql`,
`server/templates` and `agent/`.

## Read in this order

| # | Document | What it settles |
|---|---|---|
| 1 | [migration-map.md](migration-map.md) | **Start here.** Feature map, dependency graph, the safest first phase, and 15 open decisions |
| 2 | [architecture.md](architecture.md) | Request lifecycle, module layout, the "no dependency" choices |
| 3 | [database.md](database.md) | The real schema — 37 tables, not the 30 in `schema.sql` |
| 4 | [authorization.md](authorization.md) | 28 capabilities × 7 roles, and how denial actually behaves |
| 5 | [authentication.md](authentication.md) | Four gates, signup lifecycle, OIDC and enterprise SSO |
| 6 | [api-contract.md](api-contract.md) | **Frozen.** The agent HTTP surface, byte for byte |
| 7 | [agent-protocol.md](agent-protocol.md) | Agent behaviour the server must accommodate |
| 8 | [monitoring.md](monitoring.md) | Sessions, activity, idle, overtime, monitoring policy |
| 9 | [screenshots.md](screenshots.md) | Capture, storage, retention — and the access-control gap |
| 10 | [live-monitoring.md](live-monitoring.md) | The 15-second poll and what it does |
| 11 | [reports.md](reports.md) | Period engine, pay cycles, three clocks |
| 12 | [billing.md](billing.md) | Client charges vs internal labor cost |
| 13 | [payroll.md](payroll.md) | Pay run, leave, adjustments, payouts, payslips, import |
| 14 | [payments.md](payments.md) | What orgs pay DeskPulse — subscriptions, claims, Wise |
| 15 | [sharing.md](sharing.md) | Public `/share/{token}` pages |
| 16 | [remote-control.md](remote-control.md) | High-risk subsystem — protocol and containment |
| 17 | [messaging.md](messaging.md) | Outbox, SMTP, reminders, notices |
| 18 | [marketing-content.md](marketing-content.md) | Marketing site, markdown CMS, SEO, downloads |
| 19 | [platform.md](platform.md) | Super-admin console, reset, export |
| 20 | [routes.md](routes.md) | Every route and its guard |
| 21 | [ui-inventory.md](ui-inventory.md) | The design system Tailwind must reproduce |
| 22 | [security.md](security.md) | Current posture, and the known gaps |
| 23 | [testing.md](testing.md) | What exists, and the test plan per phase |

Each document follows the same structure: current implementation, tables, route,
authorization, agent dependency, Laravel destination, required tests, migration risks.

## Constraints this audit was written under

1. **Exact replica** — same functions, buttons, look, flow, layout. Only the stack
   changes: Laravel + Tailwind.
2. **Nothing else changes.**
3. **The agent is frozen** — Windows, macOS and Linux clients stay as they are and keep
   pointing at the same API.

Where these conflict with the migration plan, the constraints win and the document
says so. The main inversions are listed in [migration-map.md](migration-map.md) §1.

## The seven findings that change the plan

1. **`schema.sql` is not the schema.** It covers 30 of 37 live tables. Seven — including
   the entire payments ledger (`payments`, `invoices`), `password_resets`,
   `user_identities`, `webhook_events` and the promo tables — plus 10 columns on
   `organizations`, exist only in `db.php`'s runtime migrations. Generating migrations
   from `schema.sql` silently drops them.

2. **Tenancy is transitive.** 17 tables have no `org_id`, including `sessions`,
   `screenshots` and every monitoring table. A flat `OrganizationScope` on
   `organization_id` cannot work; isolation runs through `users.org_id`.

3. **There are 28 capabilities, not 16.** Porting only the documented list drops all
   payroll, leave, messaging, import, overtime and subscription gating. `hr_manager`
   can set pay but cannot view rates — an easy privilege escalation to introduce.

4. **Nothing is audited.** `/app/audit` is synthesised at read time from six existing
   timestamp sources. No audit trail is written, so the plan's §32 is net-new work.

5. **Screenshots and remote-control frames are world-readable.** Anything under
   `public/uploads/` is served directly by Apache. Screenshots use a random filename;
   remote frames use the **sequential session id**. Both are unauthenticated,
   cross-tenant reads of the most sensitive data in the product.

6. **The agent API is `/webhooks/*` and cannot move.** There is no `/api/v1`. The agent
   signs the raw body, cannot distinguish error classes, and retries any non-2xx on a
   queued endpoint forever.

7. **The app is dark-themed, and about a third of the CSS is marketing.** Tailwind's
   defaults would produce a light theme; the palette must be rebuilt as the only theme.

## Scope the plan does not mention

OIDC sign-in · enterprise SSO · subscription payments · payment claims · Wise payouts
and SCA · promo codes · messaging and notices · email outbox and hand-rolled SMTP ·
PDF payslips · leave · pay adjustments · salary runs · overtime approval · a markdown
CMS · cost calculator · `llms.txt` · multi-platform downloads · an arbitrary-SQL upload
endpoint · 12 platform routes.

Roughly 3,900 lines of PHP and 13 tables beyond the plan's reading list.

## Next step

Phase 2 — Laravel bootstrap, per [migration-map.md](migration-map.md) §4. It adds no
behaviour and depends on none of the open decisions.

Decisions **D1 (money), D2 (overtime clock), D4 (screenshot storage)** should be
settled before Phase 3 and Phase 9 respectively.
