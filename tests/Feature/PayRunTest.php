<?php

/**
 * Phase 13 — the pay run.
 *
 * `PayRun` is the single source of truth for money: the payroll page, the
 * payslip PDF and the salary run all read it. Everything asserted here is a
 * figure somebody is actually paid.
 *
 * @see docs/migration/payroll.md §1
 */

use App\Enums\UserRole;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\PayAdjustment;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\Payroll\PayRun;
use App\Support\Period;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function worked(User $user, string $day, int $activeSeconds, array $attributes = []): WorkSession
{
    return WorkSession::create(array_merge([
        'user_id'         => $user->id,
        'started_at'      => $day . ' 09:00:00',
        'ended_at'        => $day . ' 17:00:00',
        'active_s'        => $activeSeconds,
        'inactive_s'      => 0,
        'source'          => 'agent',
        'approval_status' => 'approved',
    ], $attributes));
}

/**
 * A resolved period context, without going through a request.
 *
 * @return array<string, mixed>
 */
function window(Organization $organization, string $startDate, string $endDateExclusive): array
{
    $tz = app(Period::class)->timezone($organization->id);

    return [
        'period'      => 'pay',
        'tz'          => $tz,
        'start'       => Period::localToUtc($startDate . ' 00:00:00', $tz),
        'end'         => Period::localToUtc($endDateExclusive . ' 00:00:00', $tz),
        'start_date'  => $startDate,
        'end_date'    => Period::addDays($endDateExclusive, -1),
        'days'        => (int) round((strtotime($endDateExclusive . ' 12:00:00') - strtotime($startDate . ' 12:00:00')) / 86400),
        'label'       => 'test window',
        'anchor'      => $startDate,
    ];
}

function runFor(User $viewer, Organization $organization, string $from, string $toExclusive): array
{
    return app(PayRun::class)->compute($viewer, window($organization, $from, $toExclusive));
}

function rowFor(array $run, User $member): ?array
{
    return collect($run['rows'])->firstWhere('user_id', $member->id);
}

/* ── Base pay ────────────────────────────────────────────────────────────── */

test('an hourly member is paid credited hours times their rate', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member, ['pay_type' => 'hourly', 'pay_rate' => 20.0]);

    worked($worker, '2026-09-03', 7200);

    expect(rowFor(runFor($admin, $organization, '2026-09-01', '2026-10-01'), $worker)['base'])->toBe(40.0);
});

test('the two halves of a semi-monthly month sum to exactly one salary', function () {
    // The reason proration divides by the START month's day count. September
    // has 30 days: 15/30 + 15/30 = 1.
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member, ['pay_type' => 'monthly', 'pay_rate' => 3000.0]);

    worked($worker, '2026-09-03', 7200);

    $first = rowFor(runFor($admin, $organization, '2026-09-01', '2026-09-16'), $worker)['base'];
    $second = rowFor(runFor($admin, $organization, '2026-09-16', '2026-10-01'), $worker)['base'];

    expect(round($first + $second, 2))->toBe(3000.0);
});

test('a monthly salary is not multiplied by a longer period', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member, ['pay_type' => 'monthly', 'pay_rate' => 3000.0]);

    worked($worker, '2026-09-03', 7200);

    expect(rowFor(runFor($admin, $organization, '2026-09-01', '2026-12-01'), $worker)['base'])->toBe(3000.0);
});

/* ── What is payable ─────────────────────────────────────────────────────── */

test('overtime HR has not approved is not paid', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member, ['pay_type' => 'hourly', 'pay_rate' => 10.0]);

    worked($worker, '2026-09-03', 36000, [
        'overtime_s' => 7200, 'overtime_status' => 'pending', 'overtime_computed' => 1,
    ]);

    // Ten hours worked, two of them unapproved overtime: eight are paid.
    expect(rowFor(runFor($admin, $organization, '2026-09-01', '2026-10-01'), $worker)['base'])->toBe(80.0);
});

test('approving the overtime pays it', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member, ['pay_type' => 'hourly', 'pay_rate' => 10.0]);

    $session = worked($worker, '2026-09-03', 36000, [
        'overtime_s' => 7200, 'overtime_status' => 'approved', 'overtime_computed' => 1,
    ]);

    $row = rowFor(runFor($admin, $organization, '2026-09-01', '2026-10-01'), $worker);

    expect($row['base'])->toBe(100.0)
        // Reported separately, not added a second time.
        ->and($row['overtime_hours'])->toBe(2.0);
});

test('a pending timesheet entry is not paid', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member, ['pay_type' => 'hourly', 'pay_rate' => 10.0]);

    worked($worker, '2026-09-03', 3600, ['approval_status' => 'pending', 'source' => 'manual']);

    expect(rowFor(runFor($admin, $organization, '2026-09-01', '2026-10-01'), $worker)['base'])->toBe(0.0);
});

test('a client portal login is never paid', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $portal = member($organization, UserRole::ClientViewer, ['pay_type' => 'monthly', 'pay_rate' => 5000.0]);

    expect(rowFor(runFor($admin, $organization, '2026-09-01', '2026-10-01'), $portal))->toBeNull();
});

/* ── Leave ───────────────────────────────────────────────────────────────── */

test('paid leave is added for hourly staff and not for salaried', function () {
    // A salary already covers the day off; adding leave pay would pay twice.
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);

    $hourly = member($organization, UserRole::Member, [
        'pay_type' => 'hourly', 'pay_rate' => 10.0, 'work_days' => '1,2,3,4,5',
    ]);
    $salaried = member($organization, UserRole::Member, [
        'pay_type' => 'monthly', 'pay_rate' => 3000.0, 'work_days' => '1,2,3,4,5',
    ]);

    $vacation = LeaveType::create(['org_id' => $organization->id, 'name' => 'Vacation', 'code' => 'vac', 'paid' => 1]);

    foreach ([$hourly, $salaried] as $person) {
        LeaveRequest::create([
            'org_id' => $organization->id, 'user_id' => $person->id,
            'leave_type_id' => $vacation->id,
            'start_date' => '2026-09-07', 'end_date' => '2026-09-08',   // Mon + Tue
            'hours_per_day' => 8, 'half_day' => 0, 'status' => 'approved',
        ]);
    }

    $run = runFor($admin, $organization, '2026-09-01', '2026-10-01');

    expect(rowFor($run, $hourly)['leave_pay'])->toBe(160.0)     // 2 days x 8h x 10
        ->and(rowFor($run, $salaried)['leave_pay'])->toBe(0.0)
        ->and(rowFor($run, $salaried)['leave_days'])->toBe(2.0);
});

test('leave days count only against the member own work days', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    // Monday to Friday. The request spans a full week including the weekend.
    $worker = member($organization, UserRole::Member, [
        'pay_type' => 'hourly', 'pay_rate' => 10.0, 'work_days' => '1,2,3,4,5',
    ]);

    $type = LeaveType::create(['org_id' => $organization->id, 'name' => 'Vacation', 'code' => 'vac', 'paid' => 1]);

    LeaveRequest::create([
        'org_id' => $organization->id, 'user_id' => $worker->id, 'leave_type_id' => $type->id,
        'start_date' => '2026-09-07', 'end_date' => '2026-09-13',        // Mon–Sun
        'hours_per_day' => 8, 'half_day' => 0, 'status' => 'approved',
    ]);

    expect(rowFor(runFor($admin, $organization, '2026-09-01', '2026-10-01'), $worker)['leave_days'])->toBe(5.0);
});

test('leave spanning two periods is split, not paid twice', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member, [
        'pay_type' => 'hourly', 'pay_rate' => 10.0, 'work_days' => '1,2,3,4,5',
    ]);

    $type = LeaveType::create(['org_id' => $organization->id, 'name' => 'Vacation', 'code' => 'vac', 'paid' => 1]);

    // Mon 14th to Fri 18th, straddling a 16th boundary.
    LeaveRequest::create([
        'org_id' => $organization->id, 'user_id' => $worker->id, 'leave_type_id' => $type->id,
        'start_date' => '2026-09-14', 'end_date' => '2026-09-18',
        'hours_per_day' => 8, 'half_day' => 0, 'status' => 'approved',
    ]);

    $first = rowFor(runFor($admin, $organization, '2026-09-01', '2026-09-16'), $worker)['leave_days'];
    $second = rowFor(runFor($admin, $organization, '2026-09-16', '2026-10-01'), $worker)['leave_days'];

    expect($first)->toBe(2.0)           // 14th, 15th
        ->and($second)->toBe(3.0)       // 16th, 17th, 18th
        ->and($first + $second)->toBe(5.0);
});

/* ── Adjustments ─────────────────────────────────────────────────────────── */

test('earnings add to gross and deductions come off net', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member, ['pay_type' => 'hourly', 'pay_rate' => 10.0]);

    worked($worker, '2026-09-03', 3600);

    PayAdjustment::create([
        'org_id' => $organization->id, 'user_id' => $worker->id, 'kind' => 'bonus',
        'label' => 'Bonus', 'amount' => 100.0, 'sign' => 1, 'currency' => 'USD',
        'effective_date' => '2026-09-05', 'status' => 'approved',
    ]);
    PayAdjustment::create([
        'org_id' => $organization->id, 'user_id' => $worker->id, 'kind' => 'advance',
        'label' => 'Advance', 'amount' => 30.0, 'sign' => -1, 'currency' => 'USD',
        'effective_date' => '2026-09-06', 'status' => 'approved',
    ]);

    $row = rowFor(runFor($admin, $organization, '2026-09-01', '2026-10-01'), $worker);

    expect($row['earnings'])->toBe(100.0)
        ->and($row['deductions'])->toBe(30.0)
        ->and($row['gross'])->toBe(110.0)
        ->and($row['net'])->toBe(80.0);
});

test('an adjustment outside the period is not counted', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member, ['pay_type' => 'hourly', 'pay_rate' => 10.0]);

    PayAdjustment::create([
        'org_id' => $organization->id, 'user_id' => $worker->id, 'kind' => 'bonus',
        'label' => 'Last month', 'amount' => 500.0, 'sign' => 1, 'currency' => 'USD',
        'effective_date' => '2026-08-20', 'status' => 'approved',
    ]);

    // Everybody in scope gets a row whether or not they have anything in the
    // period — a payroll that silently omits people is worse than one of zeroes.
    expect(rowFor(runFor($admin, $organization, '2026-09-01', '2026-10-01'), $worker)['earnings'])->toBe(0.0);
});

test('an unapproved adjustment is not counted', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member, ['pay_type' => 'hourly', 'pay_rate' => 10.0]);

    worked($worker, '2026-09-03', 3600);

    PayAdjustment::create([
        'org_id' => $organization->id, 'user_id' => $worker->id, 'kind' => 'bonus',
        'label' => 'Proposed', 'amount' => 500.0, 'sign' => 1, 'currency' => 'USD',
        'effective_date' => '2026-09-05', 'status' => 'pending',
    ]);

    expect(rowFor(runFor($admin, $organization, '2026-09-01', '2026-10-01'), $worker)['earnings'])->toBe(0.0);
});

/* ── Caps are advisory ───────────────────────────────────────────────────── */

test('hours over a cap are flagged and still paid in full', function () {
    // A cap is a warning to a manager, not a refusal to pay somebody for time
    // they actually worked.
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member, [
        'pay_type' => 'hourly', 'pay_rate' => 10.0, 'period_hours_cap' => 5.0,
    ]);

    worked($worker, '2026-09-03', 36000);       // ten hours against a five-hour cap

    $row = rowFor(runFor($admin, $organization, '2026-09-01', '2026-10-01'), $worker);

    expect($row['base'])->toBe(100.0)
        ->and($row['flags'])->toHaveCount(1)
        ->and($row['flags'][0])->toContain('Over pay-period cap');
});

test('working exactly to the cap is not flagged', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member, [
        'pay_type' => 'hourly', 'pay_rate' => 10.0, 'daily_hours_cap' => 8.0,
    ]);

    worked($worker, '2026-09-03', 28800);       // exactly eight hours

    expect(rowFor(runFor($admin, $organization, '2026-09-01', '2026-10-01'), $worker)['flags'])->toBe([]);
});

/* ── Scope ───────────────────────────────────────────────────────────────── */

test('a pay run never reaches another tenant', function () {
    $ours = org(['name' => 'Acme']);
    $admin = member($ours, UserRole::ClientAdmin);

    worked(member($ours, UserRole::Member, ['pay_type' => 'hourly', 'pay_rate' => 10.0]), '2026-09-03', 3600);
    worked(member(org(['name' => 'Globex']), UserRole::Member, ['pay_type' => 'hourly', 'pay_rate' => 99.0]), '2026-09-03', 3600);

    $run = runFor($admin, $ours, '2026-09-01', '2026-10-01');

    // The admin is on their own payroll too, at zero — but the other tenant's
    // 99/hour worker is nowhere in it.
    expect($run['rows'])->toHaveCount(2)
        ->and($run['totals']['net'])->toBe(10.0);
});

test('the payroll page needs its capability', function () {
    $this->actingAs(member(org(), UserRole::HrManager))->get('/app/payroll')->assertOk();
    $this->actingAs(member(org(), UserRole::ClientAdmin))->get('/app/payroll')->assertOk();
    $this->actingAs(member(org(), UserRole::Manager))->get('/app/payroll')->assertRedirect();
    $this->actingAs(member(org(), UserRole::Member))->get('/app/payroll')->assertRedirect();
});
