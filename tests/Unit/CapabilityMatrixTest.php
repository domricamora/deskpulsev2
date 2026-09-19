<?php

/**
 * The full role × capability matrix, transcribed from the legacy role_caps().
 *
 * 7 roles × 28 capabilities. Several grants are counter-intuitive and are asserted
 * explicitly below so that "tidying them up" fails loudly rather than silently
 * widening access.
 *
 * @see docs/migration/authorization.md §3
 */

use App\Enums\Capability;
use App\Enums\UserRole;

/** Exactly the grants in the legacy matrix. */
function expectedMatrix(): array
{
    return [
        'client_admin' => [
            'view_all', 'reports', 'screenshots', 'live', 'approve_time', 'approve_overtime',
            'users_manage', 'profiles_manage', 'view_rates', 'billing', 'clients_manage',
            'contracts_manage', 'org_settings', 'devices', 'audit', 'manage_agents',
            'set_pay_rate', 'payroll', 'remote', 'subscription', 'data_import',
            'pay_adjustments', 'leave_approve', 'wise_manage', 'messaging',
        ],
        'hr_manager' => [
            'view_all', 'reports', 'approve_time', 'approve_overtime', 'profiles_manage',
            'set_pay_rate', 'payroll', 'contracts_manage', 'data_import',
            'pay_adjustments', 'leave_approve', 'wise_manage', 'messaging',
        ],
        'manager' => [
            'view_team', 'reports', 'screenshots', 'live', 'approve_time',
            'manage_agents', 'view_agents', 'leave_approve', 'messaging',
        ],
        'it_admin' => [
            'view_all', 'devices', 'audit', 'org_settings', 'remote',
        ],
        'client_viewer' => [
            'view_team', 'reports', 'screenshots', 'billing', 'view_agents',
        ],
        'member' => [],
    ];
}

test('there are 28 capabilities', function () {
    // The migration plan lists 16. Porting that shorter list would drop all
    // payroll, leave, messaging, import, overtime and subscription gating.
    expect(Capability::cases())->toHaveCount(28);
});

test('there are 7 roles', function () {
    expect(UserRole::cases())->toHaveCount(7);
});

test('every role grants exactly the legacy capability set', function () {
    foreach (expectedMatrix() as $roleValue => $expected) {
        $role = UserRole::from($roleValue);

        $actual = array_map(fn (Capability $c) => $c->value, $role->capabilities());

        sort($actual);
        sort($expected);

        expect($actual)->toBe($expected, "capability set drifted for {$roleValue}");
    }
});

test('a super admin holds every capability', function () {
    $super = UserRole::SuperAdmin;

    foreach (Capability::cases() as $capability) {
        expect($super->can($capability))->toBeTrue();
    }
});

test('the full matrix is asserted for every role and capability', function () {
    // 7 x 28 = 196 explicit checks.
    $checks = 0;

    foreach (UserRole::cases() as $role) {
        $granted = $role === UserRole::SuperAdmin
            ? array_map(fn ($c) => $c->value, Capability::cases())
            : expectedMatrix()[$role->value];

        foreach (Capability::cases() as $capability) {
            expect($role->can($capability))
                ->toBe(in_array($capability->value, $granted, true));
            $checks++;
        }
    }

    expect($checks)->toBe(196);
});

/* ── The grants that are easy to get wrong ───────────────────────────────── */

test('HR can set a pay rate but cannot view rates', function () {
    // Granting view_rates to HR would be a privilege escalation against today.
    expect(UserRole::HrManager->can(Capability::SetPayRate))->toBeTrue()
        ->and(UserRole::HrManager->can(Capability::ViewRates))->toBeFalse();
});

test('HR has no access to screenshots', function () {
    // HR sees time and money, never monitoring imagery.
    expect(UserRole::HrManager->can(Capability::Screenshots))->toBeFalse();
});

test('IT can remote-control a machine but cannot read reports', function () {
    expect(UserRole::ItAdmin->can(Capability::Remote))->toBeTrue()
        ->and(UserRole::ItAdmin->can(Capability::Reports))->toBeFalse()
        ->and(UserRole::ItAdmin->can(Capability::Screenshots))->toBeFalse()
        ->and(UserRole::ItAdmin->can(Capability::ViewRates))->toBeFalse();
});

test('a client portal login sees billing but never labor cost', function () {
    expect(UserRole::ClientViewer->can(Capability::Billing))->toBeTrue()
        ->and(UserRole::ClientViewer->can(Capability::Screenshots))->toBeTrue()
        ->and(UserRole::ClientViewer->can(Capability::ViewRates))->toBeFalse()
        ->and(UserRole::ClientViewer->can(Capability::Payroll))->toBeFalse();
});

test('a member holds no capabilities at all', function () {
    // All member access comes from scoping, not from the matrix.
    expect(UserRole::Member->capabilities())->toBe([]);

    foreach (Capability::cases() as $capability) {
        expect(UserRole::Member->can($capability))->toBeFalse();
    }
});

test('a client portal login is not staff', function () {
    // The legacy require_staff() turns it away from Leave, Payslip and payslip PDFs.
    expect(UserRole::ClientViewer->isStaff())->toBeFalse()
        ->and(UserRole::Member->isStaff())->toBeTrue()
        ->and(UserRole::HrManager->isStaff())->toBeTrue();
});
