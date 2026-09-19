# Subscriptions and payments — what orgs pay DeskPulse

> Not in the migration plan's document list. Added during the audit because it is a
> live, routed subsystem: `server/src/payments.php` (379 lines) and
> `server/src/wise.php` (629 lines), five platform routes and six tables.

Distinct from `billing.md`, which is what an org charges **its own clients**.

## 1. Current method: direct bank transfer, manually reconciled

All gateway automation is archived. **Customers pay by bank transfer and a super admin
records the payment.**

- **Stripe** was mid-build and now sits at `server/src/archive/stripe.php` — not
  `require`d, no route, migration block removed. Recoverable, but nothing loads it.
  **Do not port it.** `/webhooks/stripe` returns 404.
- **Wise API** is kept but gated on `organizations.pay_method`. While that is `bank`
  (the default), `POST /webhooks/wise` returns **410 Gone** rather than quietly
  accepting, so a stale Wise subscription cannot file phantom credits into the ledger.

Bank details live on the platform org row: `wise_usd_holder`, `wise_usd_type`,
`wise_usd_account`, `wise_usd_routing`, `wise_usd_swift`, `wise_usd_bank`,
`wise_usd_address`. The customer Subscription page splits them into "sending from a US
bank" (routing number) and "sending from outside the US" (SWIFT/BIC), and repeats that
org's unique **`DP-<id>-XXXX`** reference — the thing that makes a transfer matchable.

> **The account details are data, never committed.** After a deploy they are entered
> once on the live Accounting page. The repo carries no bank details, and the Laravel
> port must not introduce any.

## 2. Tables

| Table | Meaning |
|---|---|
| `invoices` | issued subscription invoices — `amount_cents`, `period_start/end`, `status`, `reference` |
| `payments` | **the money ledger** — settled payments only |
| `payment_claims` | an **unverified customer assertion** that money was sent |
| `promo_codes` / `promo_redemptions` | discounts |
| `webhook_events` | inbound delivery dedup by `X-Delivery-Id` |

`invoices`, `payments`, `webhook_events`, `promo_codes` and `promo_redemptions` exist
**only in `db.php` migrations**, not in `schema.sql` (`database.md` §1).

### `payment_claims` is deliberately not `payments`

A claim is what a customer *says* they sent. *"Letting it near the money ledger would
corrupt revenue reporting."* Confirming a claim is what creates the `payments` row, and
the claim links back via `payment_id`.

`confirm_payment_claim()` refuses a claim that is not `pending`, so a double-click or a
back-button resubmit cannot bank the same money twice — verified behaviour. Preserve
that guard.

Money is stored as **`int` cents** here, unlike the `double` rates elsewhere
(`database.md` §3).

## 3. Customer side — `/app/subscription`

`require_login()` + `require_approved_org()`; capability `subscription` to manage
(held by `client_admin`). A customer submits: amount, date sent, method (transfer /
SWIFT wire / ACH / other), sending account name and bank, the reference actually used,
a note, and an optional receipt. They also see their own submission history with status
and the reviewer's note, so "pending" is visible rather than silent.

`notify_sales()` fires on submission so it is not missed.

### Receipts are private

Stored at `server/storage/receipts/…` — **outside the docroot** — because they carry
bank details. The type is taken from `finfo`, not the filename. Served only through
`/app/payment-receipt/{id}`, which allows a super admin **or** someone with
`subscription` on **that** org. Verified: direct filesystem URL 403, authorized route
200, logged-out 302.

## 4. The paywall

`require_approved_org()` holds an org at the subscription page once the trial ends with
no active subscription and no super-admin comp (`payments_enabled() && !sub_is_current($org)`).

Exempt: the subscription/account pages, `/logout`, and **the webhook/API endpoints** —
so paying, leaving, and **the agent** all keep working. See `authentication.md` §2.
Putting `/webhooks/*` behind the paywall would silently stop tracking for lapsed orgs.

## 5. Plans and pricing

`organizations.plan_type` ∈ `solo | individual | per_seat | organization | enterprise`,
with `discount_pct` off the plan's base price; the effective fee is stored in
`monthly_fee`. Base prices live on the **platform org row**: `price_solo`,
`price_individual`, `price_organization`, `price_per_seat`, `price_seat_cap`.

Seat controls: `seats_min`, `seats_max`, `seats_bill_min`, `seats_cap_covers`.
Trial: `trial_days`, `trial_ends_at`. Period: `billing_period_unit`,
`billing_period_count`, `current_period_end`, `subscription_status`.

**Solo is a real free tier**, enforced server-side — 7-day history, 1 seat, no
screenshots (`monitoring.md` §1). Three pre-existing billing bugs were fixed during the
repricing work; the fixes live in `dashboard.php`'s price ladder.

## 6. Wise integration — archived but intact

Kept behind `pay_method`, and worth documenting because re-enabling it is a supported
path (Accounting → Archived payment integrations).

### Credentials

Wise settings live on the platform org row and are edited from the Accounting page:
environment, API token, profile id, USD balance id, webhook public key, deposit
instructions, and a master on/off switch. `config('payments.wise.*')` remains the
fallback so an existing deployment behaves as its config file says until a super admin
overrides it.

**The API token is encrypted at rest** — `dp_encrypt()`/`dp_decrypt()`, AES-256-GCM,
key derived from `app_secret`, stored `v1:<base64 iv|tag|ciphertext>`. Rotating
`app_secret` invalidates stored secrets; they must be re-entered. The field renders
masked and a blank submit keeps the existing token rather than wiping it.

> Gotcha already found: `config.example.php` shipped `profile_id`/`balance_id` as
> integer `0`. A plain string cast produced `"0"`, which reads as "configured".
> `wise_settings()` now treats `null`/`''`/`0`/`'0'` uniformly as not-set. Reproduce
> that normalization.

### Two different keys — do not conflate them

1. **Webhook key** — *Wise* owns the private half and publishes the public half; we
   store it to verify inbound deliveries. There is nothing for an operator to
   generate, and a self-made key would reject every genuine delivery.
   `wise_parse_public_key()` validates a paste on save (rejects API tokens, private
   keys, truncated PEMs, non-RSA and <2048-bit).
2. **SCA keypair** — *we* own the private half and upload the public half to Wise.
   Wise guards sensitive endpoints — **balance statements included** — by answering
   `403` with a one-time `x-2fa-approval` token; `wise_api()` signs it (RSA-SHA256,
   base64) and replays the identical request once with `x-2fa-approval` +
   `X-Signature`. Without this the statement sync cannot work.
   `wise_openssl_binary()` locates an openssl binary because PHP's `openssl_pkey_new()`
   fails without an `openssl.cnf`, and `exec()` does not inherit an interactive shell's
   PATH. A child process writes the key, so `clearstatcache()` is required before
   reading it back.

Served at `/app/platform/sca-public-key.pem`.

### `POST /webhooks/wise`

RSA-SHA256 over the raw body (`X-Signature-SHA256`, falling back to `X-Signature`),
verified against the stored public key. Deduped on `X-Delivery-Id` via
`webhook_events`, so a Wise retry cannot extend a subscription twice. Wise's
subscription test event is acknowledged without a signature.

**Design note — the credit event does not carry the payer's reference.** It has amount
and time only. So the webhook records the credit and then triggers a **statement
sync**, which is the only place `paymentReference` appears; that is what reconciles the
money to an org by its `DP-<id>-XXXX` code. The sync is also runnable on demand from
Accounting, so a missed webhook is recoverable and the system never depends on the
webhook alone.

**Deliberate tradeoff:** when no public key is configured the endpoint *accepts* the
delivery and marks it unverified rather than rejecting, so a real payment is never
dropped while an admin is still setting up. Unverified is surfaced as an alert. A
credit that cannot be reconciled only ever creates an **unmatched** payment row — it
never extends a subscription without a human.

`wise_api()` uses cURL with a bearer token and **never throws** — a payment provider
being down must not break a page. Every call records success/failure in
`organizations.wise_last_sync_at` / `wise_last_error`.

`server/tools/wise_test_webhook.php` mints a throwaway keypair and fires a correctly
signed delivery so the endpoint can be proven before Wise is connected.

## 7. Platform consoles

**`/app/platform/accounting`** (+ `.csv`) — collected this period and all-time, MRR,
outstanding invoices, unassigned deposits, a 12-month revenue chart, receivables,
lapsed subscriptions, the period's payments and invoices, subscription mix, recent Wise
deliveries. Deposits that arrived without a usable reference can be assigned to an org
(settling its invoice and extending the period) or discarded. Actions: save settings,
save bank details, test connection, list balances, sync statement, register the
webhook, clear the stored token.

> **Two save actions, deliberately.** `save_bank` (live details) and `save_wise` (API
> credentials) write **disjoint** column sets. One combined form would null out
> whatever it omitted once the API fields moved into the collapsed archive block.

**`/app/platform/subscribers`** (+ `.csv`) — plan, status, seats, people, agents
installed, sessions in 30 days, last activity, signup date, paid-until, lifetime paid,
admin contact. **Dormant** (no tracked time in 30 days) is the churn signal. This
replaced the tenant paywall page, which used to leak into the platform operator's nav —
the platform org never pays itself, so `subscription` is now in `$superHidden`.

## 8. Laravel destination

| Current | Laravel |
|---|---|
| `payments.php` | `app/Services/Billing/SubscriptionService`, `InvoiceService` |
| `record_manual_payment()` / `assign_payment()` | `PaymentService` |
| `confirm_payment_claim()` | `PaymentClaimService` — keep the non-`pending` guard |
| `wise.php` | `app/Services/Payments/Wise/*` |
| `POST /webhooks/wise` | `Api/WiseWebhookController` + RSA verification middleware |
| `dp_encrypt()` | `Crypt` — **but see `security.md` §4 on reading legacy `v1:` values** |
| receipts | `Storage::disk('private')` + `/app/payment-receipt/{id}` |
| accounting / subscribers | `PlatformController` + `can:platform` |
| `archive/stripe.php` | do not port |

## 9. Required tests

- Claim → confirm creates exactly one `payments` row and links `payment_id`.
- Confirming a non-`pending` claim is refused (double-click, back-button).
- A super admin can adjust the amount actually received before confirming.
- Receipt: direct filesystem URL 403; owner with `subscription` 200; other org 403;
  logged-out 302; type from `finfo`, not the filename.
- Paywall exempts subscription pages, logout and `/webhooks/*`.
- Solo limits enforced server-side.
- `/webhooks/wise` returns **410** while `pay_method='bank'`; `/webhooks/stripe` 404.
- With Wise enabled: valid signature 200; **tampered body 401**; missing signature 401
  when a key is set; replayed `X-Delivery-Id` deduped; test event acknowledged.
- No public key configured ⇒ delivery accepted, marked unverified, alert raised, and
  **no subscription extended**.
- `wise_settings()` treats `0` / `'0'` / `''` / `null` as not-set.
- Token encrypted at rest; SQL export contains no plaintext token; blank submit keeps
  the existing value.
- `save_bank` and `save_wise` never null each other's columns.
- `DP-<id>-XXXX` reference reconciles a statement line to the right org.

## 10. Migration risks

| Risk | Severity | Note |
|---|---|---|
| Porting `archive/stripe.php` | Medium | Intentionally dead; would reintroduce a half-built gateway |
| Writing claims straight into `payments` | **High** | Corrupts revenue reporting |
| Losing the non-`pending` confirm guard | **High** | Same money banked twice |
| Receipts on a public disk | **High** | Bank details exposed |
| Combining the two save actions | Medium | Nulls the omitted column set |
| Plaintext Wise token | **High** | Leaks through every backup and the SQL export |
| Laravel `Crypt` unable to read `v1:` ciphertext | **High** | Token and SCA key unreadable after cutover |
| Conflating the webhook key with the SCA keypair | **High** | Statement sync silently fails; reconciliation stops |
| Rejecting unverified deliveries "to be safe" | Medium | Drops real payments mid-setup, reversing a deliberate tradeoff |
| Removing the 410 kill-switch | Medium | Stale subscriptions file phantom credits |
| Committing bank details | **High** | They are data, never repo content |
