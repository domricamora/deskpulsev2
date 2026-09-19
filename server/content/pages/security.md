---
title: Security
description: How DeskPulse protects data — transport security, tenant isolation, role-based access, screenshot storage and retention, and how to report a vulnerability.
lastmod: 2026-08-09
faq_auto: true
cta: false
---

This page describes what is actually implemented, and says plainly what is not. A vendor
that overstates its security posture is a liability to the buyer, and BPO procurement
checks.

## What we hold

Tracked time and activity, application and window titles, periodic screenshots, task and
client records, pay rates and payroll output, and account credentials. That is sensitive
data about identifiable people, and it is handled accordingly.

## Transport and headers

- **TLS everywhere**, with HTTP redirected to HTTPS and HSTS set with a one-year max-age,
  `includeSubDomains` and preload.
- A **Content-Security-Policy** restricting scripts, styles, images, fonts, connections
  and form targets to our own origin, plus the analytics providers named in the
  [privacy policy](/privacy).
- `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY` and `frame-ancestors 'none'`
  (clickjacking), and `Referrer-Policy: strict-origin-when-cross-origin`.
- A `Permissions-Policy` denying geolocation, camera and microphone outright.

## Authentication and access

- Passwords are hashed with **bcrypt**. We never store or log a plaintext password, and
  we never email one.
- **Password reset** links are single-use and expire after 60 minutes. Only a SHA-256
  hash of the token is stored, so a database dump yields no working links, and a
  successful reset invalidates every other outstanding link for that account. The flow
  never reveals whether an address is registered, and is rate limited per account and
  per IP.
- **Single sign-on via OpenID Connect** — Google Workspace, Microsoft Entra ID, Okta,
  Auth0, JumpCloud and anything else that speaks OIDC. Every flow uses PKCE (S256) and a
  session-bound `state`; ID tokens are verified against the provider's published JWKS,
  with the algorithm taken from the key rather than from the token header. Accounts are
  matched on the provider's immutable subject claim, never on an email address. An
  organization can require SSO, which blocks password sign-in for its members.
- Session identifiers are regenerated on sign-in, cookies are `HttpOnly`, `SameSite=Lax`
  and `Secure` over HTTPS, and sessions are stored in a private directory outside the web
  root rather than a shared system temp location.
- All state-changing requests carry a **CSRF token**.
- Access is **capability-based**, not role-name-based. Seven roles map to explicit
  capabilities, and every page and action is gated on the capability rather than on a
  string comparison. HR administrators cannot see screenshots. IT administrators cannot
  see pay rates. Managers are scoped to their assigned teams.
- A **client portal login** is scoped to that client's own engagement and can never see
  internal pay rates or labour cost.

## Tenant isolation

Every record is scoped to an organization, and every query is filtered by it. One
customer's workspace cannot read another's. A platform super administrator can act on
behalf of an organization for support purposes; that action is recorded.

## The desktop agent

- The agent authenticates with a **per-device secret** and signs every request with
  **HMAC-SHA256** over the raw request body. An unsigned or mis-signed request is
  rejected.
- It captures only while a session is running and displays a persistent monitoring
  indicator. It has no covert mode; concealing it is a breach of the
  [terms](/terms).
- Data queues locally when the network drops and syncs when it returns, so an outage does
  not lose a timesheet.

## Screenshots and file storage

- Screenshots are stored on disk, not in the database, and are deleted automatically on a
  retention schedule that defaults to **30 days**.
- Generated payslip PDFs and uploaded payment receipts are written **outside the document
  root** with a deny-all rule as a second layer, and are served only through an
  authenticated route that checks ownership or the payroll capability.
- **Be aware:** screenshot files under the public uploads directory are served directly by
  the web server, so anyone holding a full screenshot URL can open it. Treat those URLs as
  secret. This is a known characteristic, not a claim of protection.

## Secrets

Payment provider API tokens are **encrypted at rest with AES-256-GCM** using a key derived
from the application secret, so they are not readable in a database dump or a SQL export.

To be precise about scope: **this protects the stored payment credentials. It is not
whole-database encryption at rest, and we do not claim your monitoring data is encrypted
at rest** beyond whatever the hosting provider's disk encryption offers.

## Audit logging

Administrative actions are recorded in a per-organization audit log — who did what, when.
IT administrators and company administrators can review it.

## Vulnerability reporting

Email **security@deskpulse.click**. Please include enough detail to reproduce. We will
acknowledge within **3 business days** and keep you updated until resolution. We will not
pursue legal action against researchers who act in good faith, avoid privacy violations
and destruction of data, and give us reasonable time to fix an issue before disclosing it.

## What we do not have

Said plainly, because claiming otherwise is worse than lacking it:

- **No SOC 2 report and no ISO 27001 certification.** Neither is in place today.
- **No SAML.** Single sign-on is OpenID Connect only. Verifying a SAML assertion means
  validating an XML digital signature, and implementing that without a vetted library is
  a well-documented way to ship a signature-wrapping vulnerability — so we would rather
  not offer it than offer it badly. If SAML is a hard requirement for you, say so and we
  will tell you honestly where it sits.
- **No multi-factor authentication of our own.** If your identity provider enforces MFA,
  SSO carries it through to DeskPulse; there is no separate second factor on a
  password login yet.
- **No formal penetration-test report** from a third party.
- **No contractual uptime SLA** on the standard plans.

If you need any of these to approve a vendor, say so at [contact](/contact) and we will
tell you honestly where it sits rather than promise a date we cannot hold.

## FAQ

**Is DeskPulse SOC 2 certified?**

No. We hold no SOC 2 report and no ISO 27001 certificate today, and we would rather say so
than imply otherwise.

**Do you offer SSO?**

Yes — OpenID Connect, configured per organization in Settings. Google Workspace,
Microsoft Entra ID, Okta, Auth0 and JumpCloud all work. You can also require it, which
blocks password sign-in for your members. We do not support SAML; see above for why.

**Do you offer MFA?**

Not as a separate factor on a password login. If your identity provider enforces MFA,
signing in through SSO carries that enforcement into DeskPulse.

**How are passwords stored?**

Hashed with bcrypt. Plaintext passwords are never stored, logged or emailed.

**Is the monitoring data encrypted at rest?**

Payment provider credentials are encrypted with AES-256-GCM. Beyond that we rely on the
hosting provider's disk encryption, and we do not claim application-level encryption at
rest for monitoring data.

**How do I report a vulnerability?**

security@deskpulse.click. We acknowledge within 3 business days.
