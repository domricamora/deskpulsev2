# DeskPulse Laravel + Tailwind CSS Migration Plan

## PROJECT

Migrate:

https://github.com/domricamora/deskpulse

from its current custom PHP/MySQL server into a production-grade Laravel application using:

- Laravel 13
- PHP 8.3+
- MySQL/MariaDB
- Blade
- Tailwind CSS 4
- Vite
- Laravel Queues
- Laravel Scheduler
- Laravel Notifications
- Laravel Mail
- Laravel HTTP Client
- Laravel Cache
- Laravel Filesystem
- Laravel Policies/Gates
- PHPUnit/Pest
- Laravel Sanctum where appropriate for authenticated API access

The existing Python/PySide6 desktop agent remains Python.

The objective is NOT to rewrite DeskPulse from scratch conceptually.

The objective is:

**Preserve all existing DeskPulse functionality while replacing the PHP server architecture with Laravel and modernizing the UI with Tailwind CSS.**

---

# 1. IMPORTANT ARCHITECTURE DECISION

DeskPulse has two major components:

```text
DESKPULSE
│
├── Laravel Web/API Server
│
└── Python Desktop Agent
```

Do NOT convert the Python desktop agent to PHP/Laravel.

The agent remains:

```text
Python
PySide6
Windows desktop application
```

Laravel becomes responsible for:

```text
authentication
organizations
users
teams
clients
contracts
tasks
sessions
time tracking
activity
idle detection data
window data
process data
screenshots
devices
live monitoring
reports
billing
salary/labor cost
payroll imports
public share links
notifications
audit logs
remote-control authorization/API
marketing site
platform administration
```

The Python agent communicates with Laravel through a documented API/webhook contract.

---

# 2. EXISTING SYSTEM MUST BE THE BEHAVIORAL REFERENCE

Before modifying functionality, The implementer must audit the existing application.

Read:

```text
the project notes
README.md

server/schema.sql

server/src/bootstrap.php
server/src/db.php
server/src/helpers.php
server/src/auth.php
server/src/webhooks.php
server/src/dashboard.php
server/src/reports.php
server/src/share.php
server/src/marketing.php

server/templates/**

agent/**

tools/test_webhook.py
```

Create:

```text
docs/migration/
```

with:

```text
architecture.md
database.md
api-contract.md
authentication.md
authorization.md
payroll.md
billing.md
monitoring.md
screenshots.md
live-monitoring.md
agent-protocol.md
remote-control.md
routes.md
migration-map.md
testing.md
security.md
```

Do not start implementation until the existing behavior has been documented.

---

# 3. EXISTING DESKPULSE CAPABILITIES TO PRESERVE

The migration must preserve:

## Multi-tenancy

Organizations are the primary tenant boundary.

Every tenant-owned record must be scoped to:

```text
organization_id
```

No organization must ever be able to access another organization's data.

Super administrators are the only cross-tenant users.

---

# 4. ROLES

Preserve the existing roles:

```text
super_admin
client_admin
manager
hr_manager
it_admin
member
client_viewer
```

Do NOT simplify these into only:

```text
admin
manager
employee
```

The existing capability-based authorization model must become Laravel policies/gates.

Capabilities include:

```text
view_all
view_team
reports
screenshots
live
approve_time
users_manage
profiles_manage
view_rates
billing
clients_manage
org_settings
devices
audit
platform
remote
```

Authorization must be capability-based rather than checking role names throughout controllers.

Create:

```text
app/Enums/UserRole.php
app/Enums/Capability.php
app/Policies/
app/Services/Authorization/
```

---

# 5. SUPER ADMIN

Super admin is a platform-level account.

It can:

```text
create organizations
approve organizations
reject organizations
delete organizations
pause organizations
manage billing
set monthly fees
manage users
act as an organization
seed demo data
reset tenant data
export database
```

Super admin must NOT automatically expose sensitive employee salary/rate information unless the existing product explicitly permits it.

Preserve the existing distinction between:

```text
platform administration
tenant administration
employee data
```

---

# 6. ORGANIZATION MODEL

Create:

```text
Organization
```

with fields based on the existing schema.

Important properties include:

```text
name
status
billing_status
monthly_fee
billing_currency
billing_started_at
logo_path
screenshot_interval
idle_threshold
blur_screenshots
capture_screenshots
```

Use an organization context middleware:

```text
ResolveOrganization
```

Every authenticated request must establish the effective organization.

Super admins may explicitly act as another organization.

Never trust an organization ID submitted by the browser.

---

# 7. DATABASE MIGRATION

Convert:

```text
server/schema.sql
```

into Laravel migrations.

Do NOT simply import schema.sql into Laravel.

Create proper migrations under:

```text
database/migrations/
```

Likely tables include:

```text
organizations
users
teams
team_members
clients
contracts
tasks
devices
sessions
activity_samples
window_events
process_snapshots
idle_periods
screenshots
share_links
payroll/import records
audit logs
```

Preserve all existing columns and relationships.

Do not remove deprecated fields until migration compatibility has been confirmed.

---

# 8. DATABASE TENANCY

Every tenant-owned model must use organization scoping.

Implement:

```text
OrganizationScope
```

or an equivalent repository/service strategy.

For example:

```php
TimeEntry::query()
    ->where('organization_id', $organization->id);
```

Prefer centralized tenant scoping rather than manually remembering it in every controller.

Security test:

```text
Organization A cannot retrieve Organization B records.
```

This test is mandatory.

---

# 9. ELOQUENT MODELS

Create models for the complete domain.

At minimum:

```text
Organization
User
Team
TeamMember
Client
Contract
Task
Device
WorkSession
ActivitySample
WindowEvent
ProcessSnapshot
IdlePeriod
Screenshot
ShareLink
AuditLog
PayrollImport
PayrollImportRow
```

Use meaningful relationships.

Example:

```text
Organization
 ├── users
 ├── teams
 ├── clients
 ├── contracts
 ├── tasks
 ├── devices
 ├── sessions
 ├── screenshots
 └── share links
```

---

# 10. AUTHENTICATION

Replace:

```text
server/src/auth.php
```

with Laravel authentication.

Implement:

```text
login
logout
registration
password reset
password change
session management
remember me if currently supported
```

Registration lifecycle:

```text
Marketing signup
       ↓
Pending Organization
       ↓
Super Admin Review
       ↓
Approved
       ↓
Company Admin Setup
```

Preserve this lifecycle.

---

# 11. API AUTHENTICATION

The desktop agent needs a stable authentication mechanism.

Use Laravel Sanctum or a dedicated device-token mechanism.

However:

**Do not break the existing HMAC security model.**

The current agent uses:

```text
X-DeskPulse-Device
X-DeskPulse-Signature
```

with:

```text
HMAC-SHA256
```

against the device secret.

Laravel must preserve this protocol during migration.

Create:

```text
app/Http/Middleware/VerifyDeskPulseSignature.php
```

Algorithm:

```text
raw request body
       ↓
HMAC SHA-256
       ↓
device secret
       ↓
constant-time comparison
       ↓
accept/reject
```

Use:

```php
hash_equals()
```

for comparison.

---

# 12. API VERSIONING

Create a versioned API:

```text
/api/v1/
```

Examples:

```text
/api/v1/auth/login
/api/v1/devices/register
/api/v1/webhooks/session
/api/v1/webhooks/activity
/api/v1/webhooks/windows
/api/v1/webhooks/processes
/api/v1/webhooks/idle
/api/v1/webhooks/screenshots
/api/v1/tasks
/api/v1/me
```

Do not hard-code API URLs throughout the Python agent.

Create an agent API client/configuration layer.

---

# 13. WEBHOOK COMPATIBILITY

Preserve existing webhook payload formats first.

Migration strategy:

```text
Python agent
      ↓
existing payload format
      ↓
Laravel API adapter
      ↓
new Laravel domain services
      ↓
database
```

Only change the agent protocol after Laravel has achieved parity.

This dramatically reduces migration risk.

---

# 14. WEBHOOK INGESTION

Create:

```text
app/Services/Agent/
```

with:

```text
AgentAuthenticationService
SessionIngestService
ActivityIngestService
WindowEventIngestService
ProcessSnapshotService
IdlePeriodService
ScreenshotIngestService
```

Webhook controllers should remain thin.

Example:

```text
WebhookController
       ↓
AgentIngestService
       ↓
Domain Service
       ↓
Model
```

Do not put database logic directly inside webhook controllers.

---

# 15. IDEMPOTENCY

Agent data may be retransmitted.

Implement idempotency where the existing protocol allows it.

The server must safely handle:

```text
network retry
offline queue replay
duplicate webhook
duplicate screenshot
duplicate session event
```

Do not create duplicate records when the same event is intentionally replayed.

---

# 16. OFFLINE AGENT SUPPORT

The existing Python agent has a disk-backed offline queue.

Do not remove it.

Laravel must tolerate batches arriving after connectivity resumes.

Test:

```text
Agent offline
↓
events queued locally
↓
network returns
↓
events uploaded
↓
server accepts them
↓
reports become accurate
```

---

# 17. TIME TRACKING

Preserve automatic tracking.

A session contains concepts such as:

```text
user
client
task
start
end
active seconds
inactive seconds
source
approval status
reviewer
```

Automatic agent sessions should remain:

```text
approved
```

Manual entries should remain:

```text
pending
```

until approved/rejected by an authorized manager.

---

# 18. IDLE DETECTION

The existing default idle threshold is:

```text
15 minutes
```

Do not hard-code this into multiple places.

Create an organization monitoring policy:

```text
idle_threshold
```

Use the configured value.

Preserve:

```text
active time
inactive time
idle periods
```

Do not overwrite raw agent data.

---

# 19. TASKS

Workers can:

```text
add task
remove task
select task
set current task
```

Tasks can optionally belong to:

```text
client
```

Preserve task reporting.

Reports must support:

```text
time per task
time per employee
time per client
```

---

# 20. CLIENTS AND CONTRACTS

Preserve:

```text
clients
contracts
agent/client relationships
```

Contract information includes billing configuration.

Support:

```text
hourly
flat monthly service charge
```

Do not merge client and organization concepts.

---

# 21. BILLING

Preserve the distinction between:

```text
internal labor cost
```

and:

```text
client billing
```

User fields conceptually include:

```text
pay_type
pay_rate

bill_type
bill_rate
```

Create services:

```text
BillingCalculator
LaborCostCalculator
ClientBillingService
```

Monthly billing must support:

```text
flat service charge
```

and existing prorating/splitting behavior.

Never expose labor cost to client viewers.

---

# 22. PAYROLL EXCEL IMPORT

Preserve:

```text
Company Admin
HR Admin
```

ability to upload payroll Excel files.

Pipeline:

```text
Upload XLSX
 ↓
Validate
 ↓
Store original
 ↓
Parse
 ↓
Map employee external_ref
 ↓
Map client
 ↓
Import daily time
 ↓
Validate
 ↓
Report unmatched records
```

Use Laravel storage.

Never expose raw payroll files publicly.

Prefer PhpSpreadsheet if compatible with the existing formats.

---

# 23. SALARY AND RATE SECURITY

Rate information is sensitive.

Use authorization:

```text
view_rates
```

Never simply hide rate fields in Blade.

The server must prevent unauthorized API/database access.

Tests must prove:

```text
member cannot see other employee pay
manager cannot access unauthorized rates
HR cannot see screenshots if current capability rules prohibit it
client_viewer cannot see internal labor cost
```

---

# 24. SCREENSHOTS

Screenshots are sensitive monitoring data.

Store them using:

```text
Storage
```

with a private disk.

Do not store screenshot binaries directly in MySQL unless the existing architecture requires it.

Example:

```text
storage/app/private/screenshots/{organization}/{user}/{date}/
```

Access screenshots through authorized controller endpoints.

Never expose raw filesystem URLs.

---

# 25. SCREENSHOT PROCESSING

Preserve:

```text
capture interval
blur option
recording indicator
capture enabled/disabled
```

If blur is performed by the agent, preserve current behavior.

If server processing exists, move it into a service/job.

Potential service:

```text
ScreenshotService
```

---

# 26. LIVE MONITORING

The existing product has a manager live team view.

Implement this using:

```text
Laravel
+
polling initially
```

Then optionally introduce:

```text
Laravel Reverb/WebSockets
```

after functional parity.

Do NOT introduce WebSockets during the first migration unless necessary.

First make:

```text
GET /app/live
GET /api/v1/live
```

work correctly.

Then optimize.

---

# 27. ACTIVE WINDOW DATA

Preserve:

```text
active window summary
window timeline
```

Create:

```text
WindowEvent
```

and reporting services.

Do not lose:

```text
application/window title
timestamps
duration
user
session
```

where these exist in the current schema.

---

# 28. RUNNING PROCESSES

Preserve process snapshots.

Use:

```text
ProcessSnapshot
```

and report only what the existing product currently exposes.

---

# 29. PUBLIC SHARE LINKS

Preserve:

```text
/share/{token}
```

functionality.

Public reports support:

```text
day
week
month
custom date range
```

Public reports must never expose:

```text
salary
internal labor cost
private user information
```

Use hashed/tokenized share identifiers where appropriate.

Implement expiration/revocation.

---

# 30. CLIENT PORTAL

Preserve client viewer accounts.

Client viewers must only see:

```text
their client
assigned workers
approved work/time
client billing
allowed screenshots
```

They must NOT see:

```text
internal employee pay
labor cost
other clients
other organizations
admin settings
```

---

# 31. DEVICES

Create:

```text
DeviceController
DeviceService
```

Support:

```text
list devices
device status
last seen
device assignment
device authentication
device management
```

IT/admin authorization must be preserved.

---

# 32. AUDIT LOG

Every sensitive administrative action must be logged.

Create:

```text
AuditLog
```

Capture:

```text
organization
user
action
entity
entity_id
IP
user agent
metadata
timestamp
```

Log:

```text
login
role change
user creation
user deletion
rate change
billing change
screenshot access where appropriate
payroll import
data deletion
device registration
remote control
organization changes
```

---

# 33. REMOTE CONTROL

The existing product includes remote desktop control.

Treat this as a high-risk subsystem.

Do not casually rewrite it.

First document:

```text
server/src/remote.php
agent/monitor/remote.py
```

and the exact protocol.

Then implement a Laravel authorization layer:

```text
RemoteControlPolicy
RemoteSessionService
RemoteCommandService
```

Authorization:

```text
remote capability
+
organization scope
+
device ownership
+
explicit user action
```

Remote-control activity must be audited.

Do not allow arbitrary remote control through an unauthenticated API endpoint.

---

# 34. ORGANIZATION BRANDING

Preserve company logo functionality.

Upload:

```text
logo
```

then convert/resize according to current behavior.

Recommended storage:

```text
storage/app/public/organizations/logos
```

Use Laravel filesystem.

Validate:

```text
mime
size
dimensions
```

Generate optimized WebP where appropriate.

---

# 35. ONBOARDING

Preserve:

```text
company admin setup wizard
manager roster wizard
role-specific Getting Started
```

Create:

```text
OnboardingController
OnboardingService
```

Use reusable Blade components.

---

# 36. MARKETING WEBSITE

Move:

```text
server/src/marketing.php
server/templates/marketing/*
```

into Laravel Blade.

Create:

```text
resources/views/marketing/
```

Pages:

```text
home
features
pricing
security
download
signup
login
```

Use Tailwind CSS.

Marketing pages must remain SEO-friendly.

Implement:

```text
semantic HTML
meta title
meta description
Open Graph
Twitter cards
canonical URL
structured data
robots
sitemap
```

---

# 37. TAILWIND DESIGN SYSTEM

Replace:

```text
deskpulse.css
```

incrementally.

Use:

```text
resources/css/app.css
resources/js/app.js
```

and Tailwind 4.

Create reusable components:

```text
button
card
modal
table
badge
alert
input
select
textarea
dropdown
sidebar
navbar
stat-card
chart-card
empty-state
pagination
date-picker
period-selector
```

Do not generate enormous Blade files.

---

# 38. DESKPULSE UI

Preserve the existing blue DeskPulse identity while modernizing it.

Main application layout:

```text
sidebar
top navigation
organization context
notifications
profile
main content
```

Responsive behavior:

```text
desktop
tablet
mobile
```

The live-monitoring dashboard should be optimized for desktop/tablet.

---

# 39. CHARTS

The existing application uses dependency-free canvas charts.

Do not introduce a huge charting dependency unnecessarily.

Possible approach:

```text
Blade
+
small JavaScript chart module
```

or introduce a lightweight library only if it materially improves maintainability.

Preserve:

```text
daily hours
weekly hours
monthly hours
activity
task time
billing
labor cost
```

visualizations.

---

# 40. REPORTING SERVICE

Create:

```text
app/Services/Reports/
```

with:

```text
TimeReportService
ActivityReportService
TaskReportService
BillingReportService
LaborCostReportService
PayrollReportService
```

Do not put complex SQL/report calculations directly in Blade controllers.

---

# 41. CSV EXPORT

Preserve CSV exports.

Create:

```text
ReportExportService
```

Use streamed responses for large reports.

Never load millions of rows into memory.

---

# 42. TIMEZONE

The existing application stores datetimes in UTC.

Preserve this.

Database:

```text
UTC
```

Display:

```text
viewer local timezone
```

The Laravel application should use:

```text
UTC
```

internally.

Convert at presentation boundaries.

---

# 43. CACHING

Use Laravel Cache for:

```text
dashboard aggregates
organization settings
report data where safe
```

Do not cache highly volatile live-monitoring data for long periods.

Invalidate caches after:

```text
time entry
manual adjustment
billing change
contract change
```

where appropriate.

---

# 44. QUEUES

Move expensive operations into jobs:

```text
PayrollImportJob
ReportExportJob
ScreenshotProcessingJob
NotificationJob
BulkSyncJob
```

Do not queue the basic webhook ingestion path if doing so would create unacceptable tracking latency.

The agent should receive a fast acknowledgement.

---

# 45. QUEUE SAFETY

Webhook processing must be resilient.

The agent expects an HTTP response.

Use:

```text
200/201
```

for successful ingestion.

Use appropriate:

```text
400
401
403
409
422
429
500
```

responses.

The Python agent must be able to distinguish:

```text
retryable
non-retryable
authentication failure
validation failure
```

---

# 46. RATE LIMITING

Apply Laravel rate limits to:

```text
login
registration
password reset
public share links
API
device registration
```

Do not apply overly aggressive limits to legitimate agent heartbeat/webhook traffic.

Create separate limits for:

```text
agent ingestion
browser API
authentication
public pages
```

---

# 47. SECURITY

The migration must improve security without breaking functionality.

Implement:

```text
CSRF
secure sessions
password hashing
authorization policies
rate limiting
input validation
mass assignment protection
secure file storage
security headers
HMAC verification
constant-time signature comparison
tenant isolation
audit logging
```

Never commit:

```text
API keys
device secrets
database passwords
.env
production credentials
```

---

# 48. PASSWORDS

Replace custom password handling with Laravel's secure hashing.

Force password change where the existing application requires:

```text
must_change_password
```

Preserve client-viewer temporary password behavior.

---

# 49. DATA EXPORT

Preserve platform database export functionality.

However, implement it through a dedicated service:

```text
DatabaseExportService
```

It must:

```text
stream output
avoid huge memory usage
sanitize incompatible MySQL/MariaDB syntax
exclude secrets
```

Do not expose database exports to ordinary organization admins unless explicitly authorized.

---

# 50. RESET / DELETE OPERATIONS

Existing platform reset functionality is destructive.

Protect it with:

```text
super_admin
+
explicit confirmation
+
audit log
+
transaction where possible
```

Require an unmistakable confirmation phrase for destructive operations.

---

# 51. ROUTE MIGRATION

Map existing pages to Laravel routes.

Examples:

```text
/                         → marketing.home

/login                    → auth.login
/register                 → auth.register

/app                      → dashboard
/app/team                 → teams
/app/clients              → clients
/app/timesheets           → timesheets
/app/tasks                → tasks
/app/live                 → live
/app/screenshots          → screenshots
/app/billing              → billing
/app/reports              → reports
/app/devices              → devices
/app/audit                → audit
/app/profile              → profile
/app/settings             → settings
/app/onboarding           → onboarding
/app/welcome              → welcome

/app/platform             → platform
/app/platform/settings    → platform.settings

/share/{token}            → public.share
```

Preserve existing URLs where practical.

If URLs change, implement redirects.

---

# 52. CONTROLLER ORGANIZATION

Controllers:

```text
DashboardController
OrganizationController
UserController
TeamController
ClientController
ContractController
TaskController
TimesheetController
ApprovalController
ScreenshotController
LiveController
DeviceController
AuditController
BillingController
ReportController
PayrollImportController
ShareController
PlatformController
ProfileController
OnboardingController
```

API:

```text
Api/V1/AuthController
Api/V1/DeviceController
Api/V1/WebhookController
Api/V1/TaskController
Api/V1/AgentController
```

---

# 53. FORM REQUESTS

Create Form Requests for:

```text
Registration
Organization
User
Team
Client
Contract
Task
ManualTime
PayrollImport
Billing
Device
Settings
```

Never trust raw request input.

---

# 54. SERVICE LAYER

Business logic belongs in services.

Examples:

```text
OrganizationService
UserManagementService
TeamService
TimeTrackingService
ApprovalService
ScreenshotService
TaskService
BillingService
ReportService
PayrollImportService
ShareLinkService
DeviceService
RemoteControlService
```

Controllers should coordinate, not calculate.

---

# 55. EVENTS

Consider Laravel events for:

```text
SessionRecorded
ManualTimeSubmitted
ManualTimeApproved
ManualTimeRejected
ScreenshotReceived
PayrollImported
OrganizationApproved
UserCreated
Payout/BillingChanged
```

Listeners can handle:

```text
notifications
audit logging
cache invalidation
analytics
```

---

# 56. PYTHON AGENT COMPATIBILITY

Do not rewrite:

```text
agent/config.py
agent/webhook_client.py
agent/main.py
agent/monitor/*
agent/ui/*
```

until the Laravel API is working.

Create an API compatibility test suite.

Test:

```text
login
device registration
me
task retrieval
session start
session stop
activity upload
window upload
process upload
idle upload
screenshot upload
offline queue replay
```

---

# 57. AGENT API CLIENT

Eventually update:

```text
agent/webhook_client.py
```

to use:

```text
https://domain.com/api/v1/
```

instead of the legacy PHP routes.

Keep configuration server URL-based:

```text
DESKPULSE_SERVER_URL
```

or the existing equivalent.

Never hard-code production URLs.

---

# 58. DESKTOP AGENT INSTALLER

Do not break:

```text
PyInstaller
Inno Setup
Windows installer
```

After the Laravel migration, test:

```text
fresh install
login
device registration
tracking
offline queue
screenshot
upgrade
uninstall
```

The Laravel migration must not require the Python installer to change unless API URLs/protocols require it.

---

# 59. TESTING STRATEGY

The existing test:

```text
tools/test_webhook.py
```

must continue to work.

Create Laravel equivalents.

Test categories:

```text
Unit
Feature
Integration
Security
API
Browser
```

---

# 60. CRITICAL TESTS

### Tenant isolation

```text
Org A cannot access Org B.
```

### Role isolation

```text
member
manager
HR
IT
client viewer
client admin
super admin
```

must each have correct capabilities.

### HMAC

Test:

```text
valid signature
invalid signature
wrong device
tampered body
missing headers
replayed request where applicable
```

### Time

Test:

```text
active
inactive
idle threshold
manual
approval
rejection
task allocation
client allocation
```

### Billing

Test:

```text
hourly
monthly
proration
per-user
per-client
```

### Screenshots

Test:

```text
authorized
unauthorized
wrong tenant
wrong user
```

### Public shares

Test:

```text
valid
expired
revoked
wrong token
salary hidden
```

---

# 61. GOLDEN-MASTER TESTING

For important reports, run the old and new systems against identical data.

Compare:

```text
employee hours
active seconds
inactive seconds
task hours
client hours
billing
labor cost
payroll import results
```

Create fixtures from the existing demo database.

The Laravel version must match the old version before the old calculation is retired.

---

# 62. PERFORMANCE TESTING

Test with:

```text
10 users
100 users
1,000 users
10,000 users
```

where practical.

Measure:

```text
webhook response time
dashboard load
live dashboard
report generation
screenshot loading
database query count
memory usage
queue throughput
```

Avoid N+1 queries.

Use:

```text
with()
select()
chunkById()
cursor()
```

where appropriate.

---

# 63. INDEXES

Review the existing schema and add indexes for:

```text
organization_id
user_id
team_id
client_id
task_id
device_id
started_at
ended_at
created_at
approval_status
external_ref
```

Composite indexes should be based on actual query patterns.

---

# 64. OBSERVABILITY

Implement:

```text
Laravel logging
failed jobs
audit logs
health endpoint
database health
queue health
```

Optional:

```text
Laravel Telescope
```

for local/staging environments.

---

# 65. HEALTH CHECK

Create:

```text
/health
```

Return:

```json
{
  "status": "ok"
}
```

and optionally check:

```text
database
cache
queue
storage
```

Do not expose sensitive diagnostics publicly.

---

# 66. ENVIRONMENT CONFIGURATION

Use:

```text
.env
.env.example
```

Configuration categories:

```text
APP
DATABASE
CACHE
QUEUE
MAIL
FILESYSTEM
DESKPULSE
AGENT
SCREENSHOTS
HUB/SPARE INTEGRATIONS
```

Never put secrets in committed files.

---

# 67. LOCAL DEVELOPMENT

Support:

```text
Windows/WAMP
```

because the current project is developed on Windows.

Document:

```text
PHP
Composer
Node
NPM
MySQL
Apache
Python
PySide6
```

requirements.

---

# 68. INSTALLER

Replace the current PHP installation workflow with Laravel deployment commands.

Development:

```bash
composer install
npm install
npm run build
php artisan migrate
php artisan db:seed
php artisan storage:link
```

Create a production deployment document.

---

# 69. DEMO DATA

Preserve the existing demo environment.

Create Laravel seeders:

```text
DatabaseSeeder
DemoOrganizationSeeder
DemoUserSeeder
DemoClientSeeder
DemoTeamSeeder
DemoTaskSeeder
DemoTimeSeeder
DemoScreenshotSeeder
```

Demo accounts should use environment/configured credentials rather than hard-coded production passwords.

---

# 70. PLATFORM ADMIN

Create a dedicated platform dashboard.

Features:

```text
organizations
pending approvals
active organizations
billing status
monthly fees
user counts
usage
system health
audit
database export
demo data
reset
act-as organization
```

Use platform-specific authorization.

---

# 71. SETTINGS

Organization settings:

```text
company name
logo
timezone
idle threshold
screenshot interval
screenshot enabled
blur enabled
monitoring settings
billing
```

Separate:

```text
organization settings
user profile settings
platform settings
```

---

# 72. TAILWIND PAGE MIGRATION ORDER

Do not convert everything simultaneously.

Order:

```text
1. Marketing
2. Authentication
3. Main application layout
4. Dashboard
5. Employees/team
6. Clients/contracts
7. Timesheets
8. Tasks
9. Live monitoring
10. Screenshots
11. Reports
12. Billing
13. Payroll
14. Devices
15. Audit
16. Platform
17. Public shares
18. Settings
```

---

# 73. UI COMPONENT SYSTEM

Create reusable components.

Example:

```text
<x-sidebar />
<x-topbar />
<x-card />
<x-stat />
<x-table />
<x-badge />
<x-button />
<x-modal />
<x-alert />
<x-confirm-dialog />
<x-form.input />
<x-form.select />
<x-form.date />
<x-empty-state />
<x-loading />
```

Do not duplicate HTML unnecessarily.

---

# 74. DARK MODE

Support:

```text
light
dark
system
```

if consistent with the product design.

Use Tailwind's dark-mode strategy.

Charts must remain readable in both themes.

---

# 75. MOBILE

Mobile priorities:

```text
dashboard
timesheets
tasks
approvals
reports
profile
```

The live-monitoring interface can remain desktop-first but must remain usable on tablets.

---

# 76. ACCESSIBILITY

Implement:

```text
keyboard navigation
focus states
ARIA labels
semantic headings
accessible forms
accessible modals
screen-reader labels
sufficient contrast
```

Do not use color as the only indicator of status.

---

# 77. SEO

Marketing pages:

```text
unique title
meta description
canonical
OpenGraph
schema.org
robots.txt
sitemap.xml
```

Do not index:

```text
/app/*
/api/*
/share/private/*
```

unless explicitly intended.

---

# 78. ANALYTICS

If analytics are introduced, keep them optional and configurable.

Do not collect unnecessary employee monitoring data outside the core product.

---

# 79. PRIVACY

DeskPulse is workforce monitoring software.

Preserve the existing consent-first behavior:

```text
tracking only while session is on
visible monitoring indicator
configurable screenshots
optional screenshot blur
organization-controlled policies
```

Do not add hidden monitoring mechanisms.

Do not add stealth screenshot capture.

Do not remove the visible monitoring indicator.

---

# 80. MIGRATION PHASES

## Phase 1 — Audit

No application changes.

Generate:

```text
docs/migration/*
```

---

## Phase 2 — Laravel Bootstrap

Create:

```text
Laravel 13
PHP 8.3+
Tailwind 4
Vite
MySQL
```

---

## Phase 3 — Database

Convert:

```text
schema.sql
```

to migrations.

---

## Phase 4 — Models

Create all Eloquent models and relationships.

---

## Phase 5 — Authentication

Implement:

```text
login
registration
password reset
roles
capabilities
tenant context
```

---

## Phase 6 — Core Dashboard

Migrate:

```text
dashboard
team
users
clients
tasks
```

---

## Phase 7 — Agent API

Implement:

```text
/api/v1
```

and HMAC authentication.

---

## Phase 8 — Agent Compatibility

Run the existing Python agent against Laravel.

Do not modify the agent until the compatibility layer works.

---

## Phase 9 — Monitoring

Migrate:

```text
sessions
activity
windows
processes
idle
screenshots
```

---

## Phase 10 — Live Monitoring

Implement live dashboard.

Start with polling.

Optimize later.

---

## Phase 11 — Reports

Migrate:

```text
time
activity
tasks
salary
billing
CSV
```

---

## Phase 12 — Clients/Billing

Migrate:

```text
clients
contracts
billing
client portal
```

---

## Phase 13 — Payroll

Migrate:

```text
XLSX import
employee mapping
daily time
pay rates
```

---

## Phase 14 — Public Sharing

Migrate:

```text
share links
day/week/month
graphs
```

---

## Phase 15 — Devices

Migrate:

```text
devices
device management
agent status
```

---

## Phase 16 — Remote Control

Only after device/API migration is stable.

---

## Phase 17 — Platform Administration

Migrate:

```text
organizations
approval
billing
act-as
seed demo
reset
database export
```

---

## Phase 18 — Tailwind UI

Complete the visual migration.

---

## Phase 19 — Security Audit

Perform:

```text
tenant isolation
RBAC
API
HMAC
file storage
CSRF
XSS
SQL injection
authorization
rate limiting
```

---

## Phase 20 — Performance

Optimize:

```text
database
queries
reports
live dashboard
webhooks
screenshots
queues
```

---

## Phase 21 — Production Cutover

Use:

```text
staging
database copy
migration verification
agent compatibility
production deployment
```

Do not delete the legacy application until the new Laravel system has passed production validation.

---

# 81. GIT STRATEGY

Create:

```text
laravel-migration
```

branch.

Commit by feature.

Examples:

```text
chore: bootstrap Laravel application
feat: add organization migrations
feat: implement tenant context
feat: migrate authentication
feat: migrate capability authorization
feat: add agent HMAC middleware
feat: implement webhook ingestion
feat: migrate time tracking
feat: migrate screenshots
feat: migrate billing
feat: migrate payroll imports
feat: migrate public shares
style: migrate dashboard to Tailwind
test: add tenant isolation coverage
test: add agent compatibility tests
```

Never create one giant migration commit.

---

# 82. IMPLEMENTATION RULES

The implementer must:

1. Read the existing implementation before changing it.
2. Never guess business rules.
3. Never remove functionality without explicit approval.
4. Preserve existing API payloads during the compatibility stage.
5. Preserve tenant isolation.
6. Preserve HMAC authentication.
7. Preserve visible monitoring behavior.
8. Preserve screenshot privacy controls.
9. Preserve role/capability behavior.
10. Test every migrated subsystem.
11. Run the existing webhook test.
12. Compare old and new report output.
13. Never expose secrets.
14. Never put business logic into Blade templates.
15. Never put large business logic blocks into controllers.
16. Never bypass Laravel authorization.
17. Never use floating-point values for monetary calculations.
18. Never silently discard existing records.
19. Never modify the Python agent unnecessarily.
20. Commit after each completed phase.

---

# 83. DEFINITION OF DONE

The migration is complete only when:

### Web

```text
Laravel application works
Tailwind UI works
authentication works
RBAC works
multi-tenancy works
dashboard works
reports work
billing works
payroll import works
client portal works
public shares work
devices work
audit works
platform administration works
```

### Agent

```text
Python agent connects
device registration works
session tracking works
activity works
idle works
windows work
processes work
screenshots work
tasks work
offline queue works
HMAC works
```

### Security

```text
tenant isolation tested
role permissions tested
HMAC tested
screenshot access tested
file access tested
CSRF tested
rate limits tested
audit logging tested
```

### Performance

```text
no major N+1 queries
webhook latency acceptable
reports scalable
screenshots efficient
live dashboard responsive
```

### Migration

```text
existing data migrated
existing users preserved
existing clients preserved
existing sessions preserved
existing screenshots preserved
existing billing data preserved
existing payroll data preserved
```

---

# 84. FIRST IMPLEMENTATION TASK

Start with:

```text
Do NOT modify the application yet.

Perform a complete architectural audit of this repository.

Read the project notes, README.md, server/schema.sql, every PHP source file under server/src, every relevant template, the Python agent implementation, and tools/test_webhook.py.

Create docs/migration/ containing:

architecture.md
database.md
api-contract.md
authentication.md
authorization.md
monitoring.md
billing.md
payroll.md
screenshots.md
live-monitoring.md
agent-protocol.md
remote-control.md
routes.md
migration-map.md
testing.md
security.md

For every existing feature identify:

1. Current implementation
2. Database tables
3. Current route
4. Current authorization
5. Agent dependency
6. Laravel destination
7. Required tests
8. Migration risks

Do not make assumptions.

Do not delete or rewrite existing code.

At the end provide a migration dependency graph and identify the safest first implementation phase.

Wait for review before implementing Phase 2.
```

---

# 85. SECOND IMPLEMENTATION TASK

After the audit has been reviewed:

```text
Proceed with Phase 2.

Create the Laravel 13 application foundation while preserving the existing DeskPulse application.

Do not delete the existing PHP server or Python agent.

Create the Laravel structure, Composer dependencies, Tailwind CSS 4/Vite configuration, environment configuration, testing infrastructure, base Blade layouts, and initial application configuration.

Do not migrate business logic yet.

Run all available tests.

Commit the result:

chore: bootstrap Laravel DeskPulse application
```

---

# 86. IMPORTANT FINAL ARCHITECTURE

The desired end state is:

```text
                         DESKPULSE
                             │
              ┌──────────────┴──────────────┐
              │                             │
       Laravel SaaS Server             Python Agent
              │                             │
      ┌───────┼────────┐              PySide6 UI
      │       │        │                    │
     Web     API    Workers            Monitoring
      │       │        │                    │
   Blade   Sanctum  Queues             HMAC API
   Tailwind       Scheduler                 │
      │              │                      │
      └──────────────┼──────────────────────┘
                     │
                  MySQL
                     │
             Organization/Tenant
                 Isolation
```

Laravel owns the business platform.

Python owns desktop monitoring.

The API is the contract between them.

This separation should be maintained throughout the migration.
