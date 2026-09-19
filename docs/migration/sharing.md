# Public share links

Source of truth: `server/src/share.php` (137 lines),
`server/templates/share/summary.php`.

## 1. Route

```
GET /share/{token}      → share_view()     (public, no auth, layout_public)
```

Lookup by `share_links.token`. If the link is missing or inactive:

```
404 — "This share link is invalid or has expired."
```

Revoked and expired produce the **same** message, so a token cannot be probed for its
state. Preserve that.

```php
function share_link_active(array $link): bool {
    if ((int) $link['revoked'] === 1) return false;
    if (!empty($link['expires_at']) && strtotime($link['expires_at']) < time()) return false;
    return true;
}
```

The migration plan §29 asks for expiration and revocation — both already exist.

## 2. Scope

```php
share_target_user_ids($link):
  scope 'org'  → org_user_ids($link['org_id'])
  scope 'team' → team_members WHERE team_id = target_id
  else         → [ target_id ]           // scope 'user'
```

### Every user gets a personal link automatically

```php
ensure_personal_links(int $orgId)
// for each org user with no active scope='user' link:
//   INSERT share_links (org_id, scope='user', target_id, token=random_token(18),
//                       label="<name> — personal", period_default='day')
```

So a share link exists for every member whether or not anyone asked. Managers see the
links for people under them on the Team page (`personal_links_map()`).

Token is `random_token(18)` — URL-safe base64, not hex. Any sanitizer must use the
real charset: a previous bug sanitized an import token with `[^a-f0-9]` and broke it.

## 3. Date windows

`share_resolve_range()` accepts, in order:

| Input | Behaviour |
|---|---|
| `?from=YYYY-MM-DD&to=YYYY-MM-DD` | explicit range; **swapped if reversed** |
| `?week=YYYY-Www` | ISO week via `setISODate()` |
| `?period=day\|week\|month` | anything else falls back to `week` |
| `?date=YYYY-MM-DD` | in-window anchor, evaluated at **12:00:00** to dodge DST |

Default period comes from `share_links.period_default`.

**The window is cut in the owning org's `report_tz`**, not the server's and not the
viewer's — there is no signed-in user, and the page must match what that org sees on
its own dashboard. This is the six date modes the migration plan §29 refers to.

## 4. What a share page shows — and what it must never show

Shown: `summary` (totals), `daily_series` (chart), `top_apps`, `task_times`, org name,
period controls.

**Never shown:**

- **Screenshots** — *"Public pages never expose screenshots (privacy) — stats &
  graphs only."*
- Salary, pay rates, labor cost — no pay data is passed to the view at all.
- Private user detail beyond the display name.

Sessions come through `sessions_for_users()`, so **approved only**.

## 5. Indexing is blocked by header, deliberately

```php
header('X-Robots-Tag: noindex, nofollow, noarchive');
```

The reasoning in the source is worth keeping: a share URL is a **capability token** —
anyone holding it can read the summary. `robots.txt` `Disallow` is not enough on its
own, because *a crawler told not to fetch the page never reads a `noindex` meta tag*,
so anything already indexed would stay indexed. **A response header is always
honoured.**

Use the header in Laravel too. A `<meta name="robots">` tag is not equivalent.

## 6. Laravel destination

| Current | Laravel |
|---|---|
| `share_view()` | `ShareController@show` — public route, no auth |
| `share_link_active()` | `ShareLink::scopeActive()` |
| `share_target_user_ids()` | `ShareLink::targetUserIds()` |
| `share_resolve_range()` | `PeriodResolver` in a public context, org tz |
| `ensure_personal_links()` | `ShareLinkService::ensurePersonal()` |
| `X-Robots-Tag` | middleware on the share route |
| `layout_public` | `layouts.public` Blade |

Rate-limit `/share/{token}` (the migration plan §46) — it is unauthenticated and enumerable
— but keep the limit generous enough for a client refreshing a dashboard.

## 7. Required tests

Per the migration plan §60 — valid, expired, revoked, wrong token, salary hidden:

- Valid token renders; revoked → 404; expired → 404; unknown → 404; **identical message**.
- Scope: `user` shows one person; `team` shows the team; `org` shows the org.
- No screenshot appears on any share page, for any scope.
- No pay rate, labor cost or salary figure in the HTML or any embedded JSON.
- Pending/rejected sessions excluded.
- Window cut in the **owning org's** timezone — assert with a non-UTC org while the
  test runner is on a different timezone.
- `?from`/`?to` reversed are swapped; `?week=` resolves the ISO week; `?date=`
  anchors; an invalid `?period` falls back to `week`.
- `X-Robots-Tag: noindex, nofollow, noarchive` present on the response.
- `ensure_personal_links()` is idempotent and creates nothing for users who already
  have an active personal link.
- A token from Org A cannot resolve Org B data.

## 8. Migration risks

| Risk | Severity | Note |
|---|---|---|
| Leaking salary or labor cost onto a public page | **Critical** | §29 forbids it; no pay data reaches the view today |
| Exposing screenshots on a share page | **Critical** | Explicit privacy boundary |
| Distinguishing "revoked" from "expired" in the response | Medium | Lets a holder probe token state |
| Using a `<meta>` robots tag instead of the header | Medium | Already-indexed pages stay indexed |
| Cutting the window in server or viewer time | Medium | Public figures disagree with the org's own dashboard |
| Sanitizing the token with a hex charset | Medium | `random_token()` is URL-safe base64 — a known past bug |
| Dropping auto-created personal links | Low | Team page link map silently empties |
| Omitting rate limiting | Medium | Unauthenticated and enumerable |
