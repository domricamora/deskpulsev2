---
title: Privacy policy
description: How DeskPulse handles personal data — what the desktop agent captures, what it never captures, who controls it, how long it is kept and how to exercise your rights.
lastmod: 2026-08-09
faq_auto: true
cta: false
---

DeskPulse is workplace monitoring software. That places a higher obligation on us than on
most SaaS products, and this page is written to be read by the person being monitored as
well as by the company that bought it.

<!-- OPERATOR: the bracketed fields below — legal entity, registered address,
     jurisdiction, hosting and email providers — must be completed, and the whole
     document reviewed by a qualified adviser. Everything else describes what the
     software actually does today and was written against the code, not aspirationally.
     This comment is stripped at render time and never appears on the page. -->

**Effective date:** [DATE] · **Entity:** [LEGAL ENTITY NAME] · **Registered address:**
[REGISTERED ADDRESS] · **Contact:** privacy@deskpulse.click

## Who controls your data

There are two distinct relationships, and conflating them is the most common error in
this category:

- **For monitoring data about a worker** — tracked time, activity, screenshots, app and
  window titles — **the employer is the data controller and DeskPulse is the processor.**
  Your employer decides that monitoring happens, configures what is captured, and is
  responsible for telling you and, where the law requires it, obtaining your consent. We
  process that data on their documented instructions.
- **For account, billing and support data** — the name and email of whoever signs up,
  subscription records, payment references, support correspondence — **DeskPulse is the
  controller.**

If you are an employee and you want your monitoring data corrected or deleted, start with
your employer. If they do not respond, write to privacy@deskpulse.click and we will
identify the controller and route your request.

## What the desktop agent captures

Only while a worker has started a session. The agent shows a persistent "● Monitoring"
indicator whenever it is recording, and captures nothing when a session is stopped.

| Category | What is recorded | Purpose |
|---|---|---|
| Time | Session start and stop, active seconds, idle seconds | Timesheets, payroll, client billing |
| Activity | Whether genuine mouse/keyboard input occurred in each interval | Distinguishing worked time from idle time |
| Applications | Name of the foreground application and its window title | Time-per-application reporting |
| Tasks | The task the worker selected | Time-per-task reporting |
| Screenshots | Periodic desktop images at an interval the employer sets, optionally blurred | Proof of work, dispute resolution |
| Device | Machine name, operating system, agent version, a device identifier | Pairing the agent to an account, support |

## What is never captured

State plainly, because it is the thing people most fear and most often assume:

- **No keystroke content.** The agent counts that input events occurred; it does not
  record which keys were pressed. There is no keylogger.
- **No clipboard contents, no file contents, no browsing history as a feed**, and no
  microphone or camera access of any kind.
- **No location or GPS data.**
- **Nothing at all while a session is stopped**, including outside working hours.
- **No screenshots on public share links.** Share pages deliberately exclude them.

## Retention

Screenshots are deleted automatically on a platform-wide retention schedule, which
defaults to **30 days**. Deletion removes both the image file and its database record.
Timesheet, payroll and billing records are kept for as long as the organization's account
is open, and afterwards only as long as tax and employment law require.

## Sub-processors

| Provider | Purpose | Location |
|---|---|---|
| [HOSTING PROVIDER] | Application hosting and database | [REGION] |
| Wise Payments Ltd | Processing subscription payments and salary payouts | United Kingdom / EEA |
| [EMAIL PROVIDER] | Transactional and notification email | [REGION] |

We will update this list before adding a sub-processor that handles personal data.

## Legal basis and international transfers

Where the GDPR or a comparable regime applies, an employer's lawful basis for monitoring
is usually legitimate interests, balanced against the worker's rights, and it is the
employer's responsibility to complete and document that assessment. Some jurisdictions
require prior consent or works-council consultation; DeskPulse does not and cannot make
that determination for you. Where data moves outside its region of origin we rely on
Standard Contractual Clauses.

## Your rights

Subject to local law you may request access, correction, deletion, restriction, portability
or object to processing. Employees should approach their employer first, as controller. We
respond to controller-routed requests within 30 days.

## Cookies

DeskPulse sets only what it needs to function, plus analytics if the operator has enabled
it.

| Cookie | Purpose | Type |
|---|---|---|
| `deskpulse` | Sign-in session | Essential |
| `dp_tz` | Your browser's timezone, so times render in your local clock | Essential |
| `_ga`, `_ga_*` | Google Analytics, if enabled by the operator | Analytics |
| `_clck`, `_clsk` | Microsoft Clarity, if enabled by the operator | Analytics |

We do not run advertising cookies and we do not sell personal data.

## Security

See the [security page](/security) for how data is protected in transit and at rest, and
for how to report a vulnerability.

## Data processing agreement

A DPA is available on request for any customer that needs one. Write to
privacy@deskpulse.click.

## Breach notification

If a breach affecting personal data occurs, we will notify affected controllers without
undue delay and, where required, within 72 hours of becoming aware of it.

## FAQ

**Does DeskPulse record what I type?**

No. The agent counts that input happened in an interval so it can tell active time from
idle time. Key content is never captured or transmitted.

**Can my employer see my screen right now?**

They can see screenshots taken at the interval they configured, and live session status.
There is no continuous live screen feed in the standard product. A separate remote-control
feature exists for IT administrators and is capability-gated and audit-logged.

**Does it track me outside working hours?**

Only if you start a session. The agent captures nothing when the session is stopped, and
shows an indicator whenever it is running.

**How long are screenshots kept?**

30 days by default, after which the file and its record are deleted automatically. Your
employer's operator may configure a different period.

**Who do I contact about my data?**

Your employer, as the data controller. If that fails, privacy@deskpulse.click.
