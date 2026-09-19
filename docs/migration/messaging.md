# Mail, messaging and notices

> Not in the migration plan's document list. Added during the audit:
> `server/src/mailer.php` (542 lines) and `server/src/messaging.php` (468 lines),
> three tables, plus a CLI worker.

## 1. Nothing sends synchronously — except one thing

> *"Never send inline from a handler — `mail_queue()` and let the worker drain it."*

Everything is queued in **`email_outbox`** and drained by:

1. `php server/tools/mail_worker.php` from cron (**preferred**),
2. the "Send queued now" button on Platform settings,
3. opportunistically, 2-per-request, when `mail_autoflush` is on.

**The exception:** the password reset email sends **immediately** via PHP `mail()`, not
via the cron drain — a deliberate later change, because a reset that waits for cron is
useless. Laravel must keep this one synchronous while everything else queues
(`authentication.md` §5).

`email_outbox` columns: `to_email`, `to_name`, `subject`, `body_html`, `body_text`,
`kind`, `status`, `attempts`, `last_error`, `scheduled_at`, `sent_at`, `attachments`,
`org_id`, `user_id`, `created_by_id`.

## 2. Transport is switchable

`mail_transport` = `mail` (PHP's built-in `mail()`, the default) or `smtp` (a
hand-rolled client). `mail_build_parts()` is shared by both.

The SMTP client is written from scratch — `stream_socket_client`, STARTTLS and implicit
TLS, AUTH LOGIN, multi-line reply handling, dot-stuffing, MIME multipart/alternative
and multipart/mixed for attachments, RFC 2047 header encoding, CR/LF injection
stripping. No Composer, no PHPMailer — the same convention as `xlsx_rows()`.

Laravel Mail replaces all of it. Keep the CR/LF injection stripping and RFC 2047
encoding semantics in mind when diffing output: header encoding differences are the
most likely source of "the email looks different" after migration.

Platform mail settings live on the platform org row: `mail_enabled`, `mail_from`,
`mail_from_name`, `mail_reply_to`, `mail_notify`, `mail_transport`.

## 3. Send safety — three guards worth preserving

1. **Synthetic payroll-import addresses** (`…@import.deskpulse.local`) are **never
   mailed** — `mail_address_ok()`. Payroll import invents addresses for employees it
   creates; mailing them would bounce en masse and damage sending reputation.
2. **`users.email_opt_out`** mutes non-transactional mail.
3. A **"send test to myself"** button exists for verifying live delivery.

## 4. `/app/messages` — capability `messaging`

Held by `client_admin`, `hr_manager` and `manager`. Four features on one page:

### Custom messages
To everyone / a role / a team / selected people, with `{{name}}`-style merge tokens and
a preview.

### Reminders
A **registry** whose entries resolve their own recipients by query:

- no time logged today
- below expected hours
- missing Wise payout details
- no device installed
- leave awaiting review

Each reminder is a query + a template. Porting them as a registry keeps "who gets this"
next to "what it says" — do not scatter them into separate jobs.

### Email log
With retry. Reads `email_outbox`.

### Notices
`notices` + `notice_reads`. Rendered as a **dismissible banner in the layout**, and can
optionally be emailed too.

- `notices`: `audience`, `audience_ref`, `title`, `body`, `level`, `starts_at`,
  `ends_at`, `emailed`, `created_by_id`, `org_id`.
- `notice_reads`: `notice_id`, `user_id`, `dismissed_at`.
- Dismissed via `POST /app/notice/{id}/dismiss` — available to **any logged-in user**,
  not gated by `messaging`.

A notice is active between `starts_at` and `ends_at` and hidden once the viewer has a
`notice_reads` row. The banner is part of the app shell (`ui-inventory.md` §6).

## 5. Internal alerts

Signup and subscription events notify `mail_notify` (sales@). `notify_sales()` fires on
payment-claim submission (`payments.md` §3).

## 6. Laravel destination

| Current | Laravel |
|---|---|
| `email_outbox` + `mail_worker.php` | Laravel Mail + queue; **keep the table** if in-flight rows matter at cutover |
| hand-rolled SMTP | Laravel's SMTP transport |
| `mail()` transport | `MAIL_MAILER=sendmail`/`smtp` per `mail_transport` |
| `mail_queue()` | `Mail::queue()` |
| reset mail | `Mail::send()` — **synchronous** |
| `mail_address_ok()` | a global "should send" gate, not per-call checks |
| reminders registry | `app/Services/Messaging/Reminders/*` + scheduler |
| notices | `Notice` / `NoticeRead` + a Blade banner component |
| platform mail settings | `config/mail.php` overridden from the platform org row |

Cutover note: unsent `email_outbox` rows at switchover either need draining on the
legacy system first, or a one-off importer. Decide before Phase 21.

## 7. Required tests

- Everything queues **except** password reset, which sends synchronously.
- `…@import.deskpulse.local` is never sent, on any path.
- `email_opt_out` mutes non-transactional mail but not transactional mail.
- Both transports produce equivalent MIME for text, HTML and attachments.
- Header injection: CR/LF in a subject or name is stripped.
- Merge tokens resolve; an unknown token does not leak raw `{{…}}` to a recipient.
- Each reminder resolves the documented recipient set and nobody else.
- Notices respect `starts_at`/`ends_at` and disappear per-user on dismiss.
- Dismiss works for any logged-in user without `messaging`.
- Audience targeting (everyone / role / team / selected) is org-scoped.
- Failed sends increment `attempts` and record `last_error`; retry works.

## 8. Migration risks

| Risk | Severity | Note |
|---|---|---|
| Queueing the password reset | **High** | Resets stop arriving without a worker |
| Mailing synthetic import addresses | **High** | Mass bounces, sender reputation damage |
| Losing `email_opt_out` | Medium | Sends to people who opted out |
| Dropping CR/LF stripping | Medium | Header injection |
| Scattering the reminder registry | Medium | Recipient queries drift from their templates |
| Gating notice dismissal behind `messaging` | Medium | Users cannot dismiss their own banners |
| Abandoning unsent `email_outbox` rows at cutover | Medium | Silently lost mail |
| Notices not org-scoped | **High** | Cross-tenant broadcast |
