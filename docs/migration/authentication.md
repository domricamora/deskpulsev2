# Authentication — current behaviour

Source of truth: `server/src/auth.php`, `server/src/oauth.php`.

Covers password auth, the signup lifecycle, password reset, **OIDC federated sign-in
and enterprise SSO** (which `claude.md` never mentions), and the four gates that stand
between a valid session and a usable page.

## 1. Session identity

```php
function current_user(): ?array {
    static $loaded = false, $user = null;      // one query per request
    if (!empty($_SESSION['user_id'])) {
        $user = db_one('SELECT * FROM users WHERE id = ?', [$_SESSION['user_id']]);
        if ($user['role'] === 'super_admin' && !empty($_SESSION['act_org'])) {
            if (db_one('SELECT id FROM organizations WHERE id = ?', [$_SESSION['act_org']]))
                $user['org_id'] = (int) $_SESSION['act_org'];   // act-as
        }
    }
    return $user;
}
```

- Session holds **only `user_id`**; the row is re-read each request, so a role or org
  change takes effect immediately.
- **Act-as**: a super admin's *effective* `org_id` is overridden by
  `$_SESSION['act_org']`, and the override is validated against the database before
  use. Every org-scoped page then works unchanged.
- The org id is never read from the request — it comes from the session or the user
  row (`claude.md` §6: "never trust an organization ID submitted by the browser" —
  already true).

## 2. Four gates, in order

A valid session is not enough. `require_login()` → `require_approved_org()`:

| # | Gate | Condition | Effect |
|---|---|---|---|
| 1 | Not signed in | no `user_id` | `redirect('/login?next=' . urlencode(path))` |
| 2 | Temporary password | `users.must_change_password` | hold at `/app/change-password` — only that path and `/logout` are exempt |
| 3 | Org not approved | `organizations.status !== 'approved'` | hold at `/app/pending` (super admin exempt) |
| 4 | **Paywall** | `payments_enabled() && !sub_is_current($org)` | hold at the subscription page once the trial ends with no active subscription and no super-admin comp |

Gate 4 exempts the subscription/account pages, `/logout`, and the webhook/API
endpoints — so paying, and leaving, and **the agent**, all keep working. Preserve that
exemption list exactly: putting `/webhooks/*` behind the paywall would silently stop
tracking for any lapsed org.

`?next=` round-trips the intended destination through login. Laravel's
`intended()` is the equivalent, but the query-parameter name is user-visible in URLs.

## 3. Password sign-in

`handle_login()` — one handler for GET and POST, branching on method. Credentials via
`password_verify()`. On success the session is populated and the user is routed by
role (see `routes.md` §5).

`handle_logout()` clears the session.

**Remember-me does not exist.** Cookie `lifetime => 0` — sessions are browser-session
scoped with a 1-day server GC. `claude.md` §10 says "remember me if currently
supported"; it is not. Do not add it.

## 4. Signup lifecycle

```
marketing /register
   ↓ handle_register()   → organizations.status = 'pending'
   ↓ user held at /app/pending
   ↓ super admin reviews on /app/platform  (approve / reject / delete)
   ↓ approved  → company-admin setup wizard (/app/onboarding)
```

Matches `claude.md` §10. Additional real behaviour:

- Registration captures a **plan choice** (`plan_signup_price()`), and first-touch UTM
  attribution recorded earlier in the request by `attribution_capture()`.
- Approval starts a trial (`start_trial()`), which feeds gate 4.
- A super admin can also create an org directly from the Platform page, with a
  generated temporary password → `must_change_password`.

## 5. Password reset

`/forgot-password` → `/reset-password`. Implemented with hashed tokens:

| Function | Role |
|---|---|
| `reset_token_hash($token)` | stores a **hash**, never the token |
| `send_password_reset($user, $ip)` | records `requested_ip`, queues the mail |
| `reset_token_lookup($token)` | validates hash, `expires_at`, `used_at` |
| `client_ip()` | proxy-aware source address |

`password_resets` columns: `user_id`, `token_hash`, `expires_at`, `used_at`,
`created_at`, `requested_ip`. Single-use and expiring.

> Reset mail **sends immediately** via PHP `mail()`, not through the cron outbox drain
> — a deliberate change recorded in the production log ("Send the password reset email
> immediately via PHP `mail()`, not via the cron drain"), because a queued reset that
> waits for cron is useless. Laravel must send this one synchronously even though
> everything else queues. See `messaging.md`.

## 6. Federated sign-in — OIDC (absent from `claude.md`)

`server/src/oauth.php`, 462 lines. Routes `/auth/{provider}` and
`/auth/{provider}/callback`.

### Built-in providers

| Key | Issuer | Scope |
|---|---|---|
| `google` | `https://accounts.google.com` | `openid email profile` |
| `microsoft` | `https://login.microsoftonline.com/common/v2.0` | `openid email profile` |

`common` accepts both Entra work/school and personal accounts, so the issuer is
tenant-specific at runtime and validated against a pattern rather than by equality.

A provider is **only offered when both `client_id` and `client_secret` are configured**
(`oauth_providers()` filters on it; `oauth_enabled()` drives the UI). With nothing
configured the buttons do not render — so a fresh Laravel install must behave the same
rather than showing dead buttons.

### Enterprise SSO — per organization

Beyond the two built-ins, an **organization** can configure its own OIDC issuer:
`organizations.sso_issuer`, `sso_client_id`, `sso_client_secret`, `sso_domains`,
`sso_enabled`, `sso_enforce`.

`sso_org_for_email($email)` maps an email **domain** to the org that owns it, so a user
typing a work address is routed to their employer's IdP. `sso_enforce` makes SSO the
only permitted path for that org — password sign-in is refused.

> SAML is deliberately not supported, and that is stated publicly. Do not add it.

### Token verification is hand-rolled — and must not be weakened

```
oidc_discover($issuer)                     → .well-known/openid-configuration
jwk_to_pem($jwk)                           → JWKS → PEM
oidc_verify_id_token($jwt, $disco, $clientId, $nonce)
```

`oidc_verify_id_token()` is the security boundary: signature against the provider's
JWKS, issuer, audience (`client_id`), expiry, and a **nonce** carried through the
session to bind the callback to the request it started.

Laravel destination: Socialite covers Google/Microsoft but **not** per-org enterprise
issuers or `sso_enforce`. Either keep a generic OIDC driver for the enterprise path or
port this module. Whatever is chosen, nonce and audience validation must survive —
dropping the nonce reintroduces callback replay.

### Identity links

`user_identities` — `user_id`, `provider`, `subject`, `email`, `last_login_at`,
`created_at`. `subject` is the provider's stable id; matching on email alone would let
an email change hijack an account.

`oauth_sign_in()` links or creates, then establishes the same session as password
login — so all four gates in §2 apply equally to SSO users.

## 7. Agent authentication is separate

`POST /webhooks/auth` takes email+password and returns a device id + secret. It does
**not** create a web session, and `super_admin` is refused. See `api-contract.md`.

## 8. Laravel destination

| Current | Laravel |
|---|---|
| `$_SESSION['user_id']` + re-read | standard session guard |
| `$_SESSION['act_org']` | `ResolveOrganization` middleware + an explicit act-as action |
| `require_login()` | `auth` middleware + `?next=` → `intended()` |
| `must_change_password` | `EnsurePasswordChanged` middleware |
| `require_approved_org()` gates 3+4 | `EnsureOrganizationApproved` + `EnsureSubscriptionCurrent`, with the same exemption list |
| `handle_login/register/...` | `Auth\*Controller` |
| `password_resets` | Laravel's broker, but **keep the existing table and hash format** |
| `oauth.php` | Socialite for Google/Microsoft + a generic OIDC driver for per-org SSO |
| `user_identities` | `UserIdentity` model |

## 9. Required tests

- All four gates, in order, for every role; each redirects to the documented page.
- Paywall exempts subscription pages, logout **and `/webhooks/*`** — a lapsed org's
  agent keeps ingesting.
- `?next=` round-trips and cannot be used as an open redirect.
- Act-as: super admin sets/clears `act_org`; a forged `act_org` for a non-existent org
  is ignored; a non-super session cannot set it.
- Reset token: single-use, expiring, stored hashed, `requested_ip` recorded; reset mail
  sends synchronously.
- `must_change_password` blocks every route except change-password and logout.
- OIDC: bad signature, wrong audience, expired token and **missing/mismatched nonce**
  are each rejected; provider buttons hidden when unconfigured.
- Enterprise SSO: email domain routes to the right org; `sso_enforce` refuses password login.
- `user_identities` matches on `subject`, not email.
- `super_admin` cannot register a device.

## 10. Migration risks

| Risk | Severity | Note |
|---|---|---|
| Omitting OIDC/SSO — absent from `claude.md` | **High** | Live sign-in path; users would be locked out |
| Putting `/webhooks/*` behind the paywall | **High** | Silently stops tracking for lapsed orgs |
| Dropping nonce/audience checks by using a generic library | **High** | Callback replay / token substitution |
| Matching identities on email instead of `subject` | **High** | Account takeover via email change |
| Replacing `password_resets` with Laravel's default table | Medium | In-flight reset links break at cutover |
| Queueing the reset email | Medium | Resets stop arriving without cron |
| Adding remember-me | Low | Not current behaviour |
| Losing act-as validation | Medium | Forged `act_org` would cross tenants |
