<?php

namespace App\Enums;

/**
 * Every capability the application gates on.
 *
 * Transcribed from role_caps() in the legacy server/src/auth.php. There are 28 —
 * the migration plan lists only 16, and porting that shorter list would silently
 * drop all payroll, leave, messaging, import, overtime and subscription gating.
 *
 * Authorization is capability-based, never role-name-based. See
 * docs/migration/authorization.md.
 */
enum Capability: string
{
    /** See every member in the organization. */
    case ViewAll = 'view_all';

    /** See only members of teams the user belongs to. */
    case ViewTeam = 'view_team';

    /** Time / activity / productivity reports. */
    case Reports = 'reports';

    /** View screenshots. */
    case Screenshots = 'screenshots';

    /** Live team view. */
    case Live = 'live';

    /** Approve or reject manual time entries. */
    case ApproveTime = 'approve_time';

    /** Approve the overtime portion before it can be paid (HR). */
    case ApproveOvertime = 'approve_overtime';

    /** Create and edit users, assign roles and team membership. */
    case UsersManage = 'users_manage';

    /** Edit employee profiles (HR). */
    case ProfilesManage = 'profiles_manage';

    /** See and edit pay and bill rates. */
    case ViewRates = 'view_rates';

    /** Client billing. */
    case Billing = 'billing';

    /** Manage clients and contracts. */
    case ClientsManage = 'clients_manage';

    /** Assign members to a contract roster — narrower than clients_manage. */
    case ContractsManage = 'contracts_manage';

    /** Assign clients to agents — edits the roster. */
    case ManageAgents = 'manage_agents';

    /** Read-only agent roster and detail; implied by manage_agents. */
    case ViewAgents = 'view_agents';

    /** Monitoring policy and company settings. */
    case OrgSettings = 'org_settings';

    /** Manage agent installs and devices (IT). */
    case Devices = 'devices';

    /** Audit / security log (IT). */
    case Audit = 'audit';

    /** Remote desktop control; org-scoped for non-super callers. */
    case Remote = 'remote';

    /** Bulk payroll import into the uploader's own organization. */
    case DataImport = 'data_import';

    /** Bonuses, commissions, reimbursements and deductions. */
    case PayAdjustments = 'pay_adjustments';

    /** Administer leave types and entitlements, approve requests. */
    case LeaveApprove = 'leave_approve';

    /** Maintain payout details and run the salary-run export. */
    case WiseManage = 'wise_manage';

    /** Notices, custom messages and reminder mail. */
    case Messaging = 'messaging';

    /** Cross-tenant platform console. Super admin only. */
    case Platform = 'platform';

    // ── Present in the legacy matrix but absent from its docblock ──
    // Undocumented there, but enforced. Dropping these would widen access.

    /** Write a member's pay rate. Note: distinct from ViewRates. */
    case SetPayRate = 'set_pay_rate';

    /** Payslip generation, and other people's payslip PDFs. */
    case Payroll = 'payroll';

    /** The organization's own DeskPulse subscription and payment claims. */
    case Subscription = 'subscription';
}
