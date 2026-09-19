<?php

namespace App\Enums;

/**
 * The seven roles, and the capability matrix behind them.
 *
 * Transcribed verbatim from role_caps() in the legacy server/src/auth.php.
 * These grants are not a redesign — several are counter-intuitive and must stay
 * exactly as they are:
 *
 *  - hr_manager can SET a pay rate but cannot VIEW rates.
 *  - hr_manager has no screenshots access at all.
 *  - it_admin can remote-control a machine but cannot read reports.
 *  - client_viewer can see screenshots and billing, but never labor cost.
 *  - member holds nothing; its access comes from "self only" scoping.
 *
 * See docs/migration/authorization.md.
 */
enum UserRole: string
{
    case SuperAdmin   = 'super_admin';
    case ClientAdmin  = 'client_admin';
    case Manager      = 'manager';
    case HrManager    = 'hr_manager';
    case ItAdmin      = 'it_admin';
    case Member       = 'member';
    case ClientViewer = 'client_viewer';

    /** Human label, as the legacy UI renders it. */
    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin   => 'Super admin',
            self::ClientAdmin  => 'Company admin',
            self::Manager      => 'Team manager',
            self::HrManager    => 'HR admin',
            self::ItAdmin      => 'IT admin',
            self::Member       => 'Employee',
            self::ClientViewer => 'Client portal',
        };
    }

    /**
     * The platform operator holds every capability, expressed in the legacy
     * matrix as the wildcard '*'.
     */
    public function isSuperAdmin(): bool
    {
        return $this === self::SuperAdmin;
    }

    /**
     * A client portal login is a customer, not an employee. The legacy
     * require_staff() turns it away from Leave, Payslip and payslip PDFs.
     */
    public function isStaff(): bool
    {
        return $this !== self::ClientViewer;
    }

    /** @return list<Capability> */
    public function capabilities(): array
    {
        return match ($this) {
            // Wildcard in the legacy matrix — every capability.
            self::SuperAdmin => Capability::cases(),

            self::ClientAdmin => [
                Capability::ViewAll,
                Capability::Reports,
                Capability::Screenshots,
                Capability::Live,
                Capability::ApproveTime,
                Capability::ApproveOvertime,
                Capability::UsersManage,
                Capability::ProfilesManage,
                Capability::ViewRates,
                Capability::Billing,
                Capability::ClientsManage,
                Capability::ContractsManage,
                Capability::OrgSettings,
                Capability::Devices,
                Capability::Audit,
                Capability::ManageAgents,
                Capability::SetPayRate,
                Capability::Payroll,
                Capability::Remote,
                Capability::Subscription,
                Capability::DataImport,
                Capability::PayAdjustments,
                Capability::LeaveApprove,
                Capability::WiseManage,
                Capability::Messaging,
            ],

            // Sees time and money, never monitoring imagery. Can set a pay rate
            // without being able to view rates — do not "tidy" that up.
            self::HrManager => [
                Capability::ViewAll,
                Capability::Reports,
                Capability::ApproveTime,
                Capability::ApproveOvertime,
                Capability::ProfilesManage,
                Capability::SetPayRate,
                Capability::Payroll,
                Capability::ContractsManage,
                Capability::DataImport,
                Capability::PayAdjustments,
                Capability::LeaveApprove,
                Capability::WiseManage,
                Capability::Messaging,
            ],

            // Team-scoped through team_members.
            self::Manager => [
                Capability::ViewTeam,
                Capability::Reports,
                Capability::Screenshots,
                Capability::Live,
                Capability::ApproveTime,
                Capability::ManageAgents,
                Capability::ViewAgents,
                Capability::LeaveApprove,
                Capability::Messaging,
            ],

            self::ItAdmin => [
                Capability::ViewAll,
                Capability::Devices,
                Capability::Audit,
                Capability::OrgSettings,
                Capability::Remote,
            ],

            // Read-only, scoped to its own client record. Billing here means what
            // that client is charged — never internal labor cost.
            self::ClientViewer => [
                Capability::ViewTeam,
                Capability::Reports,
                Capability::Screenshots,
                Capability::Billing,
                Capability::ViewAgents,
            ],

            // Self only. All access comes from scoping, not from the matrix.
            self::Member => [],
        };
    }

    public function can(Capability $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }
}
