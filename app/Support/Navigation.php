<?php

namespace App\Support;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\User;

/**
 * The sidebar.
 *
 * Transcribed from the `$groups` table in server/templates/layout.php, item for
 * item and tooltip for tooltip. It is data rather than markup because three
 * separate rules decide whether a link appears, and expressing them as
 * conditionals inside a Blade loop is how one of them quietly stops applying:
 *
 *   1. the capability the item declares, if any;
 *   2. {@see self::SUPER_HIDDEN} — the platform operator's console is signup and
 *      oversight only, so the member-level pages are dropped. While acting as an
 *      organization they get the full nav back, because navigating that tenant
 *      is the entire point of act-as;
 *   3. {@see self::ROLE_HIDDEN} — per-role removals beyond capability gating.
 *
 * Rule 3 is not redundant with rule 1. A client portal login holds `reports`
 * for data-scoping reasons, so the Efficiency report would otherwise appear in
 * a customer's sidebar; it is removed by name.
 *
 * Links here are not access control. Every destination enforces its own guard —
 * hiding a link the user could still reach by typing the URL would be theatre.
 *
 * @see docs/migration/authorization.md §2
 */
class Navigation
{
    /**
     * Groups of [key, label, href, capability, icon, tooltip].
     *
     * @return array<string, list<array{0: string, 1: string, 2: string, 3: ?Capability, 4: string, 5: string}>>
     */
    public static function groups(): array
    {
        return [
            'Work' => [
                ['overview', 'Overview', '/app/overview', null, 'overview', 'Your dashboard: active vs inactive time, top apps, screenshots and an activity timeline.'],
                ['timesheets', 'Timesheets', '/app/timesheets', null, 'clock', 'Tracked sessions by day. Add manual time entries (sent for approval).'],
                ['tasks', 'Tasks', '/app/tasks', null, 'tasks', "Add tasks, pick what you're working on, and see time spent per task."],
                ['leave', 'Time off', '/app/leave', null, 'leave', 'Request paid time off and track your remaining leave balance.'],
            ],
            'Insights' => [
                ['approvals', 'Approvals', '/app/approvals', Capability::ApproveTime, 'approvals', 'Review, approve or reject manual time entries your team submits.'],
                ['overtime', 'Overtime', '/app/overtime', Capability::ApproveOvertime, 'overtime', 'Approve hours worked beyond a schedule before they count toward payroll.'],
                ['live', 'Live', '/app/live', Capability::Live, 'live', "See who is tracking right now and what they're working on, in real time."],
                ['screenshots', 'Screenshots', '/app/screenshots', Capability::Screenshots, 'image', 'Browse periodic screenshots captured during tracked work.'],
                ['efficiency', 'Efficiency report', '/app/reports/efficiency', Capability::Reports, 'efficiency', 'Per-person activity, task completion and an effectiveness score — for performance evaluation.'],
            ],
            'Manage' => [
                ['agents', 'Agents', '/app/agents', Capability::ViewAgents, 'agents', "Manage the agents you oversee and the clients they're assigned to."],
                ['team', 'Team', '/app/team', Capability::ViewAll, 'users', 'Manage accounts, roles, work schedules and pay/bill rates.'],
                ['clients', 'Clients', '/app/clients', Capability::ClientsManage, 'clients', 'Add the companies you work for and their read-only portal logins.'],
                ['contracts', 'Contracts', '/app/contracts', Capability::ContractsManage, 'contracts', 'See every contract at a glance and assign the team members working under each.'],
                ['billing', 'Billing', '/app/billing', Capability::Billing, 'billing', 'Bill customers per agent or as a flat monthly service charge.'],
                ['payroll', 'Pay breakdown', '/app/payroll', Capability::Payroll, 'payroll', 'Internal labor cost: hours and pay per person for any period.'],
                ['adjustments', 'Pay adjustments', '/app/adjustments', Capability::PayAdjustments, 'adjust', 'Bonuses, commissions, reimbursements and deductions for a pay period.'],
                ['payslips', 'Payslips', '/app/payslips', Capability::Payroll, 'payslip', 'Generate PDF payslips for a pay period and email them to employees.'],
                ['wise', 'Wise accounts', '/app/wise', Capability::WiseManage, 'wise', 'Employee payout details - import from Excel/CSV or enter them by hand.'],
                ['salary_run', 'Salary run', '/app/salary-run', Capability::WiseManage, 'salary', 'Net pay per employee for a period, exported as a Wise batch payment file.'],
                ['messages', 'Messages', '/app/messages', Capability::Messaging, 'message', 'Send notices, custom messages and reminders to your team.'],
                ['import', 'Import data', '/app/import', Capability::DataImport, 'upload', 'Upload a payroll Excel file to create clients, employees and time entries.'],
                ['devices', 'Devices', '/app/devices', Capability::Devices, 'devices', 'Every installed agent with last-seen time; rename or remove a device.'],
                ['audit', 'Audit log', '/app/audit', Capability::Audit, 'audit', 'A trail of key account and security actions across the organization.'],
            ],
            'Account' => [
                ['payslip', 'Payslip', '/app/payslip', null, 'payslip', 'Your paid-hours breakdown for a period, including approved overtime.'],
                ['share_links', 'Share links', '/app/share-links', Capability::ViewAll, 'share', 'Public read-only summary links (day/week/month) you can share.'],
                ['download', 'Download app', '/app/download', null, 'download', 'Get the desktop agent that tracks time and activity.'],
                ['settings', 'Settings', '/app/settings', null, 'settings', 'Monitoring policy, devices, share links and company branding.'],
                ['subscription', 'Subscription', '/app/subscription', Capability::Subscription, 'billing', 'Your DeskPulse plan, free-trial status and monthly payment.'],
                ['profile', 'My profile', '/app/profile', null, 'profile', 'Update your name, contact details and password.'],
                ['welcome', 'Getting started', '/app/welcome', null, 'help', 'Reopen the quick role guide for what you can do in DeskPulse.'],
            ],
        ];
    }

    /**
     * The platform console's own links, shown to a super admin who is not
     * currently acting as an organization.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function platformGroup(): array
    {
        return [
            ['platform', 'Platform', '/app/platform', 'platform'],
            ['platform_orgs', 'Organizations', '/app/platform/orgs', 'orgs'],
            ['platform_subscribers', 'Subscribers', '/app/platform/subscribers', 'users'],
            ['platform_accounting', 'Accounting', '/app/platform/accounting', 'salary'],
        ];
    }

    /**
     * The Organizations link stays lit across the whole organization cluster,
     * because those four pages are tabs of one screen.
     *
     * @var list<string>
     */
    public const ORG_CLUSTER = [
        'platform_orgs', 'platform_accounts', 'platform_billing', 'platform_settings',
    ];

    /**
     * Hidden from a super admin who is NOT acting as an organization.
     *
     * The platform organization has no members, no rates and no tracked time of
     * its own, so these pages would all render empty. `subscription` is in the
     * list because it is the tenant paywall — the platform never pays itself;
     * Subscribers is the operator's equivalent.
     *
     * @var list<string>
     */
    public const SUPER_HIDDEN = [
        'overview', 'timesheets', 'approvals', 'overtime', 'screenshots', 'devices',
        'tasks', 'download', 'billing', 'payroll', 'import', 'settings', 'team', 'clients',
        'contracts', 'live', 'agents', 'share_links', 'payslip', 'welcome', 'efficiency',
        'adjustments', 'payslips', 'wise', 'salary_run', 'messages', 'leave',
        'subscription',
    ];

    /**
     * Per-role removals beyond capability gating.
     *
     * A client portal gets exactly Agents, Timesheets, Tasks, Screenshots,
     * Billing and My profile.
     *
     * @var array<string, list<string>>
     */
    public const ROLE_HIDDEN = [
        'manager' => ['settings'],
        'client_viewer' => [
            'overview', 'welcome', 'payslip', 'download', 'settings', 'efficiency',
            'leave', 'adjustments', 'payslips', 'wise', 'salary_run', 'messages',
        ],
    ];

    /**
     * Resolve the groups this user actually sees, dropping any that end up
     * empty.
     *
     * @return array<string, list<array{key: string, label: string, href: string, icon: string, tooltip: string}>>
     */
    public static function forUser(User $user): array
    {
        $hideSuperLinks = $user->isSuperAdmin() && ! $user->isActingAsOrganization();
        $hideForRole = self::ROLE_HIDDEN[$user->role instanceof UserRole ? $user->role->value : ''] ?? [];

        $resolved = [];

        foreach (self::groups() as $groupLabel => $items) {
            $visible = [];

            foreach ($items as [$key, $label, $href, $capability, $icon, $tooltip]) {
                if ($capability !== null && ! $user->hasCapability($capability)) {
                    continue;
                }

                if ($hideSuperLinks && in_array($key, self::SUPER_HIDDEN, true)) {
                    continue;
                }

                if (in_array($key, $hideForRole, true)) {
                    continue;
                }

                $visible[] = compact('key', 'label', 'href', 'icon', 'tooltip');
            }

            if ($visible) {
                $resolved[$groupLabel] = $visible;
            }
        }

        return $resolved;
    }
}
