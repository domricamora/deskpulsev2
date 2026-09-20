# Testing strategy

Consolidates the per-subsystem test requirements into one plan, and records what
testing exists today.

## 1. What exists today

**One automated test**, and it is an end-to-end harness, not a unit test:

```
py tools/test_webhook.py http://localhost/deskpulsev2/server/public ava@demo.test Demo12345
```

It registers a device and exercises the full ingest path — session → activity samples →
window events → idle → screenshot. The migration plan §59 requires it to keep working
**unmodified** against Laravel. That makes it the single best early signal that the
agent contract survived, and it should be the first green light in Phase 7.

Its blind spot is that it re-implements the contract: it proves the server accepts *a*
correct client, not *the* client that is installed on people's machines. Phase 8 added
`tools/test_agent_compat.py` for that, which imports the agent's own modules and lets
them drive — `WebhookClient` signs, `Tracker` builds the payloads, and the agent's
offline queue reports what silently failed (`api-contract.md` §9):

```
py tools/test_agent_compat.py http://localhost/deskpulsev2/public ava@demo.test Demo12345
py tools/run_agent_local.py   http://localhost/deskpulsev2/public --fresh   # the real GUI
```

Both run in CI as `legacy-webhook-contract` and `agent-compatibility`. The second needs
`xvfb-run` — the tracker's input listeners and screen capture want a display, and without
one it skips the tracked-session half without failing.

There is no PHPUnit, no Pest, no CI test job. Everything else has been manual:

- Route smoke tests as each of the seven roles (HTTP status + PHP-diagnostic scan).
- Period variants: day/week/pay/month/range, `?date=` anchors, invalid `?period=`, and
  all six public-share date modes.
- Functional POSTs: period settings, three adjustment kinds, a leave request, manual
  Wise entry, a real payroll `.xlsx` through preview→commit, salary-run CSV, payslip
  PDF, notice publication, reminder queue.
- `schema.sql` rebuilt into a throwaway database and counted.
- Payslip PDFs confirmed **403** at their direct URL.
- Playwright: table filter/sort/search/clear/dropdown/persistence and the ◀ ▶ navigator,
  with no console or page errors.
- A **132 page-width responsive pass** (132/132 clean).
- Wise webhook: valid 200, replay deduped, tampered body 401, missing signature 401.
- Encryption: round-trips, fails under a rotated `app_secret`, ciphertext never contains
  the plaintext, two encryptions differ.

**Treat that manual list as the regression baseline.** Each item becomes an automated
test in the Laravel suite; together they are most of Phase 19.

## 2. Test pyramid for the migration

| Layer | Tool | Covers |
|---|---|---|
| Unit | Pest/PHPUnit | calculators — pay run, billing, overtime, period resolution, plan limits |
| Feature | Laravel HTTP tests | every route × every role, redirects, gates |
| Integration | HTTP + DB | webhook ingest, imports, payroll pipeline, share pages |
| Security | Feature tests | tenant isolation, capability matrix, HMAC, file access, CSRF |
| API compatibility | `tools/test_webhook.py` + Pest | the frozen agent contract |
| Golden master | fixture comparison | old vs new report output |
| Browser | Playwright (already in use) | tables.js, charts, live poll, responsive |

## 3. The mandatory tests

### 3.1 Tenant isolation — the migration plan §8 calls this mandatory

Org A must not reach Org B. Critically, this must cover the **transitive** tables that
have no `org_id` (`database.md` §2):

```
sessions  screenshots  activity_samples  window_events
process_snapshots  idle_periods  devices  team_members
```

A test that only checks `org_id` tables proves nothing about the monitoring data —
which is the sensitive half.

Also: a foreign `client_id`/`task_id` posted to the agent API stores `NULL` and still
returns 200 (`api-contract.md` §3).

### 3.2 Role and capability matrix

7 roles × 28 capabilities = **196 assertions** against the table in
`authorization.md` §3. Plus the non-obvious rules:

- `hr_manager` can set pay but has **no** `view_rates`, and **no** `screenshots`.
- `it_admin` has `remote` but no `reports`.
- `client_viewer` has `screenshots` and `billing` but never labor cost.
- `member` has zero capabilities.
- Denial is a **redirect to `/app` with a flash**, not a 403.
- In-page branching: `/app/settings` hides share links without `view_all`;
  `/app/subscription` is read-only without `subscription`.

### 3.3 HMAC — the migration plan §60

Valid, invalid, wrong device, tampered body, missing headers, **empty-body signature**,
and replay. Replay must be asserted to *current* behaviour (accepted) until a decision
says otherwise.

Plus the Laravel-specific traps: verify the signature against the **raw** body, with
`TrimStrings`/`ConvertEmptyStringsToNull` disabled and CSRF excluded.

### 3.4 Time and money

- Active, inactive, idle threshold, manual entry, approval, rejection, task and client
  allocation.
- Overtime: no schedule ⇒ none; outside window; beyond daily length; an approved or
  rejected decision survives recomputation.
- `creditable_active_s()` excludes unapproved overtime; **billing includes it**
  (`billing.md` §3.2).
- Billing: hourly, monthly, proration, `monthFactor` capped at 1.0, per-user,
  per-client, client-filtered split by the agent's **total** time.
- Pay: semi-monthly halves sum to one salary; paid leave for hourly only; caps flag
  but never truncate.
- **Day-boundary tests** under a non-UTC `report_tz`, with the test runner in a third
  timezone — this is where the two clocks in `monitoring.md` §4 and `payroll.md` §1
  will bite.

### 3.5 Screenshots and files

Authorized, unauthorized, wrong tenant, wrong user. And the one that fails today:
**a direct file URL must not serve another org's screenshot** (`screenshots.md` §4).
Same for remote frames (`remote-control.md` §5), payslips and payment receipts — the
latter two already pass.

### 3.6 Public shares

Valid, expired, revoked, wrong token — all 404 with an **identical** message. No
salary, no labor cost, no screenshots on any share page. Window cut in the owning
org's timezone. `X-Robots-Tag` present.

### 3.7 Agent compatibility

The migration plan §56's list: login, device registration, me, task retrieval, session
start/stop, activity, window, process, idle and screenshot upload, offline queue replay.

Add: queued endpoints **never return 422** (`agent-protocol.md` §4), and the
duplicate-vs-dedup decision from §5 is asserted explicitly either way.

## 4. Golden-master testing — the migration plan §61

For every report that drives money or hours, run old and new against identical data and
compare:

```
employee hours · active seconds · inactive seconds · task hours
client hours · billing · labor cost · payroll import results
```

Practical approach:

1. Seed the legacy system with `seed_demo_data()` — it is idempotent and covers all
   roles, teams, clients, contracts, tasks and a week of sessions.
2. Export that database (`/app/platform/export.sql`) as the fixture.
3. Load it into the Laravel test database.
4. Run both systems over the same periods and diff the numbers.

**Golden master is only meaningful if the float-vs-decimal and timezone decisions are
"replicate exactly" first** (`database.md` §3, `monitoring.md` §4). If those are changed
in the same pass, every diff becomes ambiguous — you cannot tell a port bug from an
intended change. Port faithfully, prove parity, *then* change under test.

## 5. Performance — the migration plan §62

Measure webhook response time, dashboard load, live dashboard, report generation,
screenshot loading, query count, memory and queue throughput at 10 / 100 / 1,000 /
10,000 users.

Known N+1 candidates from the audit:

- `live_agent_card()` per open session (`live-monitoring.md` §4).
- `compute_billing()` / `compute_pay_run()` iterating users and sessions.
- Any transitive tenancy scope implemented as `whereHas` on a hot path
  (`database.md` §2).

Assert **query counts**, not just wall time — a count assertion catches an N+1 that a
fast local database hides.

## 6. CI

There is no CI test job today (`.github/workflows/build-agent.yml` builds the agent).
Phase 2 should add one running Pest on MySQL, plus a job running
`tools/test_webhook.py` against the booted app.

## 7. Per-phase gates

| Phase | Must pass before moving on |
|---|---|
| 3 Database | migrations rebuild to a schema identical to live; 37 tables, 439 columns |
| 4 Models | tenant isolation across transitive tables |
| 5 Auth | four gates, capability matrix, OIDC nonce/audience |
| 7 Agent API | `tools/test_webhook.py` **unmodified**, green |
| 8 Compatibility | the real agent tracks a full session against Laravel — `tools/test_agent_compat.py`, green, empty offline queue |
| 9 Monitoring | overtime day-boundary tests; replay behaviour asserted |
| 11 Reports | golden master matches the legacy system |
| 12 Billing | golden master; no labor cost reaches a `client_viewer` |
| 13 Payroll | import idempotency; payslip 403 at direct URL |
| 14 Shares | expired/revoked/wrong-token; nothing sensitive leaks |
| 16 Remote | expiry constants; cross-org 404; frames not publicly fetchable |
| 17 Platform | reset is atomic and confirmed; export streams and restores |
| 18 UI | 132-width responsive pass; tables.js behaviour intact |
| 19 Security | the full §3 set |
| 21 Cutover | agent compatibility on production data |

## 8. Risks

| Risk | Severity | Note |
|---|---|---|
| Testing isolation only on `org_id` tables | **High** | Leaves monitoring data unproven |
| Running golden master after changing money/timezone handling | **High** | Cannot distinguish a bug from an intended change |
| Modifying `tools/test_webhook.py` to make it pass | **High** | Destroys the one unmodified-contract signal |
| No query-count assertions | Medium | N+1s ship and surface only at scale |
| Treating the manual list as already covered | Medium | It is the regression baseline, not prior coverage |
| Skipping browser tests for `tables.js` | Medium | Cross-cutting behaviour on every table |
