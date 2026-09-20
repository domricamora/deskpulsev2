# Live monitoring

Source of truth: `server/src/dashboard.php` (`dash_live`, `dash_live_data`,
`dash_live_data_platform`, `live_agent_card`, `live_team_by_user`),
`server/public/assets/js/live.js`.

## 1. It is already polling — keep it that way

```js
fetch(endpoint, { headers: { 'X-Requested-With': 'fetch' } })
setInterval(refresh, 15000);      // 15 seconds
```

Two routes:

| Route | Returns |
|---|---|
| `GET /app/live` | the page shell (`dash_live`) |
| `GET /app/live/data` | JSON payload (`dash_live_data`) |

Both require the `live` capability. The migration plan §26 says to start with polling and
only consider Reverb/WebSockets after parity — the existing system **is** the polling
implementation, so there is nothing to build up to. Do not introduce WebSockets.

Note the endpoint shape: `/app/live/data`, not `/api/v1/live`. Under the replica
constraint it keeps that path.

## 2. What a poll does

```php
function dash_live_data(): void {
    $u = require_cap('live');
    close_stale_sessions();                 // so "live now" isn't showing dead agents
    [$dayStart, $dayEnd] = period_range('day');
    $canShots = can($u, 'screenshots');

    if ($u['role'] === 'super_admin' && empty($_SESSION['act_org']))
        return dash_live_data_platform($canShots, $dayStart, $dayEnd);

    $ids = visible_user_ids($u);
    // open sessions only: WHERE s.user_id IN (…) AND s.ended_at IS NULL
    // → live_agent_card() per session, grouped client → team → members
}
```

Three behaviours worth calling out:

1. **`close_stale_sessions()` runs on every poll.** The live view is what keeps stale
   sessions from accumulating — there is no cron. See `monitoring.md` §3. A Laravel
   port that removes this call from the polling path changes when sessions close.
2. **Super admins get a different payload.** Without `act_org`, a super admin sees the
   cross-tenant platform view (`dash_live_data_platform`); while acting as an org they
   get the normal team view. Same route, two shapes — the JSON carries `mode`.
3. **Screenshots are included only with the `screenshots` capability**
   (`$canShots` is passed into each card). A user with `live` but not `screenshots`
   gets cards without imagery. Both layers must be reproduced — see
   `authorization.md` §2 on in-page branching.

## 3. Response shape

```json
{
  "now": "<ISO-8601 UTC>",
  "mode": "team",
  "total": 0,
  "groups": [
    { "client": "<name>", "count": 0,
      "teams": [ { "team": "<name|No team>", "members": [ <card>, … ] } ] }
  ]
}
```

- Grouped **client → team → member**, both levels `ksort`ed for stable ordering.
- Sessions without a team fall under the literal `"No team"`.
- Empty scope and no open sessions both return `groups: []`, `total: 0` — with `now`
  still set, so the client can render a timestamp.
- `now` is `gmdate('c')` — UTC; the browser localizes.

`live_agent_card()` builds each card from the open session joined to its user and
client, plus the day's totals (`period_range('day')`), and the latest screenshot when
permitted.

## 4. Laravel destination

| Current | Laravel |
|---|---|
| `dash_live` | `LiveController@index` |
| `dash_live_data` | `LiveController@data` — keep `/app/live/data` |
| `dash_live_data_platform` | same controller, branch on super-admin without act-as |
| `live_agent_card()` | `LiveAgentCard` resource / DTO |
| `live_team_by_user()` | eager-loaded team membership map |
| `live.js` | keep polling; Tailwind restyle only |

Performance notes for Phase 20 (not Phase 10):

- The card build is per open session — a classic N+1 if ported naively. Eager-load
  user, client, team and the latest screenshot.
- The migration plan §43 warns against caching volatile live data. With a 15s poll, any cache
  TTL above a few seconds makes the view wrong; prefer query tuning over caching.
- `close_stale_sessions()` writes on every poll, so the endpoint is not read-only —
  it must not be served from a read replica or a cached response.

## 5. Required tests

- `live` capability required; without it, redirect-to-`/app` (not 403).
- `live` **without** `screenshots` ⇒ cards contain no screenshot.
- Scope: manager sees only their team; `client_viewer` only its client's agents;
  member only themselves.
- Org A never appears in Org B's payload.
- Super admin without act-as ⇒ platform mode; with act-as ⇒ team mode for that org.
- Only sessions with `ended_at IS NULL` appear.
- A session whose heartbeat is older than 15 minutes is closed by a poll and
  disappears from the next one.
- Empty scope returns `groups: []`, `total: 0`, `now` present.
- Grouping and ordering: client then team, both sorted; unteamed under `"No team"`.
- Query count stays flat as the number of open sessions grows (no N+1).

## 6. Phase 10 as built

Two routes behind `cap:live`: `LiveController::index()` for the shell and
`LiveController::data()` for the snapshot. The data path keeps `/app/live/data`
— §51's `/api/v1/live` rename is what the replica constraint rules out, and
`live.js` targets the existing path.

`App\Services\Reporting\LiveBoard` builds the payload, in both modes, in a
**fixed number of queries**. The legacy builds each card with four per-session
queries — latest window, latest activity sample, latest screenshot, today's
totals — so ten live agents cost forty round trips every fifteen seconds from
every open dashboard. Each of those is batched here:

- the newest row per session resolves the `MAX(ts)` first and joins back to it,
  rather than dragging back every sample a day-long session has accumulated;
- today's totals are one `SessionStats::forUsers()` call for everyone on the
  board, rolled up per user with the same `summarize()` the reports use;
- team names are one join, first name per user alphabetically.

`LiveTest` asserts the count does not move between one live agent and ten. It
warms up first: the organization's period config is memoised per container, so
an un-warmed first poll costs two queries a later one does not, and the naive
version of that test fails in the *cheaper* direction.

### Reproduced deliberately

- **The endpoint writes on a read.** `closeStale()` runs before every snapshot,
  as `dash_live_data()` does. Nothing else closes a crashed agent's session on a
  schedule (decision D11), so removing it leaves the board showing people who
  went offline hours ago. It also means the route can never be cached or served
  from a replica.
- **Screenshots only with the capability.** A card carries imagery only when the
  viewer holds `screenshots`. No role currently has `live` without it, so this
  is a guard rather than a live path — but the capability split belongs to the
  product, not to this port.
- The card keeps its exact field names and the `No client / direct` and
  `No team` literals, because `live.js` reads them.

### One deviation

The activity bar's width was `style="width:N%"` built into a markup string.
Decision D13 rules that out, so the bar is created as an element and its width
set through the CSSOM — same pixels, nothing for a Content-Security-Policy to
reject. The port also builds nodes instead of concatenating HTML, which retires
the hand-rolled `esc()` the legacy needed.

Verified against real data: an agent session opened with the frozen client
appeared on the board with its latest window, 64% activity, today's totals and
its newest screenshot through `/app/screenshots/{id}/image`; a session left open
across a five-hour gap was closed by the next poll, at its last heartbeat.

## 7. Migration risks

| Risk | Severity | Note |
|---|---|---|
| Introducing WebSockets/Reverb now | Medium | §26 explicitly defers it; polling is the current behaviour |
| Dropping `close_stale_sessions()` from the poll | **High** | Stale sessions never close; live view shows dead agents; hours inflate |
| Ignoring the super-admin platform branch | Medium | Cross-tenant view silently lost |
| Including screenshots without the capability | **High** | Exposes monitoring imagery to `live`-only roles |
| Caching the payload | Medium | 15s poll makes even short TTLs visibly wrong |
| Renaming `/app/live/data` to `/api/v1/live` | Medium | Replica constraint; `live.js` targets the existing path |
| N+1 on card building | Medium | Phase 20 concern, but easy to bake in during Phase 10 |
