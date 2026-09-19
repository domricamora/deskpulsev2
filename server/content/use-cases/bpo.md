---
title: Time tracking and monitoring for BPOs and outsourcing teams
nav_title: For BPOs
description: What a BPO actually needs from monitoring software — proof of work for client disputes, per-client billing, payroll from tracked time, and a bill that stops growing when you hire.
og_image: /assets/img/og-image.png
schema_type: Article
published: 2026-08-09
lastmod: 2026-08-09
---

**Short answer:** a BPO needs three things from monitoring software that a generic time
tracker does not provide — defensible proof of work when a client disputes an invoice,
per-client billing that is separate from what you pay the agent, and payroll that comes
out of the same tracked time rather than a parallel spreadsheet. DeskPulse does all three
at {{price.per_seat}} a seat, and the bill stops at {{price.cap}} however many agents you
add.

## The three problems that are specific to outsourcing

Most monitoring tools are built for an in-house team with one employer, one budget and no
external party checking the hours. An outsourcing operation has a client on the other side
of every timesheet, and that changes what the software has to do.

### 1. A client disputes 40 hours on an invoice

In-house, this conversation does not happen. In outsourcing it happens monthly, and
"trust us" is not an answer that keeps the contract. You need per-agent, per-day,
per-task records with activity levels and screenshot evidence, exportable and shareable
with the client without exposing anything you don't intend to.

DeskPulse gives every client a read-only portal login scoped to **their own engagement**
— the agents assigned to them, the time logged against them, and what they're being
charged. It never shows what you pay the agent, and it never shows another client's data.
Public share links carry the summary and the graphs but never the screenshots.

### 2. Your cost and your price are two different numbers

Every agent has a pay rate, and every client has a bill rate, and they are not the same
number — that difference is your margin. Most tools model one rate.

DeskPulse holds both, separately: `pay_rate` for what the agent costs you (hourly or a
monthly salary), and `bill_rate` for what the client is charged (hourly, or a flat monthly
service charge prorated across the period). Billing reports come out both per agent and
per customer, so "what did this client cost us and what did we invoice them" is one screen
rather than a reconciliation.

### 3. Payroll is a separate spreadsheet

Tracked hours land in one system, payroll in another, and someone re-keys the numbers
every fortnight. That is where errors and disputes come from.

DeskPulse runs the pay period out of the same tracked time: overtime that needs HR
approval before it pays, manual adjustments for bonuses, commissions and reimbursements,
paid leave, semi-monthly and 15-day pay cycles, PDF payslips emailed to staff, and a Wise
payout CSV for the actual transfer.

## Why per-seat pricing is a BPO problem specifically

A BPO's headcount is its product. Growing from 20 seats to 60 is the plan, not an
accident — and under per-seat pricing that triples a fixed cost in the same year your
margins are under the most pressure.

{{table.matrix}}

DeskPulse is {{price.per_seat}} a seat until the total reaches {{price.cap}}, which
happens at {{seats.cap_engages}} seats. After that, hiring is free as far as monitoring is
concerned, up to {{seats.cap_covers}} seats.

## Rolling it out without a mutiny

The tool matters less than the introduction. What consistently works:

**Tell them before you install it, in writing.** Not after. The single biggest predictor
of a bad rollout is people discovering monitoring by finding the agent running.

**Explain what it does not capture.** No keystroke content, no clipboard, no browsing feed,
nothing at all while the session is stopped. Most fear is about things the software does
not do.

**Point at the client, not at them.** "This is how we prove your hours when a client
queries an invoice" lands very differently from "this is so we can check on you." In
outsourcing it also happens to be true.

**Give them the same data you get.** Every DeskPulse employee sees their own dashboard,
their own timesheet and their own payslip, computed from the same records their manager
reads. Monitoring that is mutual is far easier to defend than monitoring that is one-way.

**Blur the screenshots if the work involves personal data.** It is one setting, and it
removes an entire category of objection.

## What DeskPulse does not do

Said plainly, so you don't discover it in week three:

- **No GPS or mobile tracking.** It is built for desk-based work. Field teams need
  something else.
- **No contractor payment rails.** It computes payroll and exports a Wise-ready CSV; it
  does not move the money.
- **A thin integration catalogue.** If your project-management tool must sync
  automatically, check before committing.
- **No SOC 2 or ISO 27001** today. See [security](/security) for what is and is not in
  place.

## FAQ

**Can my client see the screenshots?**

Only if you give them a portal login and intend them to. Client portal users are scoped to
their own engagement, and public share links never include screenshots at all.

**Can I charge different clients different rates?**

Yes. Each client or contract carries its own rate, and each employee separately carries
the bill rate they are charged out at. Both are used, and neither is visible to the agent.

**Does it handle a semi-monthly or 15-day pay cycle?**

Yes — weekly, biweekly, semi-monthly (1st–15th, 16th–end), rolling 15-day and monthly, in
your organisation's own reporting timezone rather than the server's.

**What happens when I go over {{seats.cap_covers}} seats?**

That is an Enterprise conversation — [get in touch](/contact). Below that, the cap holds
at {{price.cap}}.

**Is monitoring legal where my agents are?**

That depends on the jurisdiction, and it is your responsibility as the employer. Most
regimes require that workers are informed in advance; some require consent or a
works-council consultation. DeskPulse is built consent-first — the worker starts the
session and sees an indicator throughout — but the legal assessment is yours, and covert
deployment is a breach of our [terms](/terms).
