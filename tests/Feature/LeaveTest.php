<?php

/**
 * Phase 13 — time off and pay adjustments.
 *
 * Two pages with the same shape as the timesheet queue: what you may file for
 * yourself, what you may decide for somebody else, and the fact that neither
 * is allowed to leak across a tenant boundary.
 *
 * @see docs/migration/payroll.md §2
 */

use App\Enums\UserRole;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayAdjustment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/* ── Gates ───────────────────────────────────────────────────────────────── */

test('anyone on staff may open time off, and a client portal may not', function () {
    $organization = org();

    $this->actingAs(member($organization, UserRole::Member))->get('/app/leave')->assertOk();
    $this->actingAs(member($organization, UserRole::HrManager))->get('/app/leave')->assertOk();
    $this->actingAs(member($organization, UserRole::ClientViewer))->get('/app/leave')->assertForbidden();
});

test('opening time off seeds the four standard types, once', function () {
    $organization = org();

    $this->actingAs(member($organization, UserRole::Member))->get('/app/leave')->assertOk();
    $this->actingAs(member($organization, UserRole::Member))->get('/app/leave')->assertOk();

    expect(LeaveType::query()->where('org_id', $organization->id)->count())->toBe(4);
});

test('adjustments need the capability', function () {
    $this->actingAs(member(org(), UserRole::HrManager))->get('/app/adjustments')->assertOk();
    $this->actingAs(member(org(), UserRole::Member))->get('/app/adjustments')->assertRedirect();
});

/* ── Requesting ──────────────────────────────────────────────────────────── */

test('a member request lands pending and an approver entry lands approved', function () {
    $organization = org();
    $worker = member($organization, UserRole::Member, ['work_days' => '1,2,3,4,5']);
    $hr = member($organization, UserRole::HrManager, ['work_days' => '1,2,3,4,5']);

    $type = LeaveType::create(['org_id' => $organization->id, 'name' => 'Vacation', 'code' => 'vac', 'paid' => 1]);

    $payload = fn () => [
        'action' => 'request', 'leave_type_id' => $type->id,
        'start_date' => '2026-09-07', 'end_date' => '2026-09-08', 'hours_per_day' => 8,
    ];

    $this->actingAs($worker)->post('/app/leave', $payload());
    $this->actingAs($hr)->post('/app/leave', $payload());

    expect(LeaveRequest::query()->where('user_id', $worker->id)->value('status'))->toBe('pending')
        ->and(LeaveRequest::query()->where('user_id', $hr->id)->value('status'))->toBe('approved');
});

test('a member cannot file leave for somebody else', function () {
    $organization = org();
    $worker = member($organization, UserRole::Member);
    $victim = member($organization, UserRole::Member);
    $type = LeaveType::create(['org_id' => $organization->id, 'name' => 'Vacation', 'code' => 'vac', 'paid' => 1]);

    $this->actingAs($worker)->post('/app/leave', [
        'action' => 'request', 'user_id' => $victim->id, 'leave_type_id' => $type->id,
        'start_date' => '2026-09-07', 'end_date' => '2026-09-08',
    ])->assertForbidden();

    expect(LeaveRequest::query()->where('user_id', $victim->id)->exists())->toBeFalse();
});

test('a range with no working days in it is refused', function () {
    $organization = org();
    // Monday to Friday; the request is a Saturday and Sunday.
    $worker = member($organization, UserRole::Member, ['work_days' => '1,2,3,4,5']);
    $type = LeaveType::create(['org_id' => $organization->id, 'name' => 'Vacation', 'code' => 'vac', 'paid' => 1]);

    $this->actingAs($worker)->post('/app/leave', [
        'action' => 'request', 'leave_type_id' => $type->id,
        'start_date' => '2026-09-12', 'end_date' => '2026-09-13',
    ]);

    expect(LeaveRequest::query()->count())->toBe(0);
});

test('a reversed date range is tolerated rather than rejected', function () {
    $organization = org();
    $worker = member($organization, UserRole::Member, ['work_days' => '1,2,3,4,5']);
    $type = LeaveType::create(['org_id' => $organization->id, 'name' => 'Vacation', 'code' => 'vac', 'paid' => 1]);

    $this->actingAs($worker)->post('/app/leave', [
        'action' => 'request', 'leave_type_id' => $type->id,
        'start_date' => '2026-09-11', 'end_date' => '2026-09-07',
    ]);

    $request = LeaveRequest::query()->first();

    expect($request->getRawOriginal('start_date'))->toBe('2026-09-07')
        ->and($request->getRawOriginal('end_date'))->toBe('2026-09-11');
});

/* ── Deciding ────────────────────────────────────────────────────────────── */

test('an approver decides a request and the trail is recorded', function () {
    $organization = org();
    $hr = member($organization, UserRole::HrManager);
    $worker = member($organization, UserRole::Member, ['work_days' => '1,2,3,4,5']);
    $type = LeaveType::create(['org_id' => $organization->id, 'name' => 'Vacation', 'code' => 'vac', 'paid' => 1]);

    $request = LeaveRequest::create([
        'org_id' => $organization->id, 'user_id' => $worker->id, 'leave_type_id' => $type->id,
        'start_date' => '2026-09-07', 'end_date' => '2026-09-08', 'hours_per_day' => 8,
        'total_days' => 2, 'total_hours' => 16, 'half_day' => 0, 'status' => 'pending',
    ]);

    $this->actingAs($hr)->post('/app/leave', [
        'action' => 'review', 'request_id' => $request->id,
        'decision' => 'approve', 'review_note' => 'enjoy',
    ]);

    $decided = $request->fresh();

    expect($decided->status)->toBe('approved')
        ->and((int) $decided->reviewed_by_id)->toBe((int) $hr->id)
        ->and($decided->review_note)->toBe('enjoy');
});

test('a member without the capability cannot decide anything', function () {
    $organization = org();
    $worker = member($organization, UserRole::Member);
    $type = LeaveType::create(['org_id' => $organization->id, 'name' => 'Vacation', 'code' => 'vac', 'paid' => 1]);

    $request = LeaveRequest::create([
        'org_id' => $organization->id, 'user_id' => $worker->id, 'leave_type_id' => $type->id,
        'start_date' => '2026-09-07', 'end_date' => '2026-09-08', 'hours_per_day' => 8,
        'total_days' => 2, 'total_hours' => 16, 'half_day' => 0, 'status' => 'pending',
    ]);

    $this->actingAs($worker)->post('/app/leave', [
        'action' => 'review', 'request_id' => $request->id, 'decision' => 'approve',
    ]);

    expect($request->fresh()->status)->toBe('pending');
});

test('a member may withdraw their own pending request', function () {
    $organization = org();
    $worker = member($organization, UserRole::Member);
    $other = member($organization, UserRole::Member);
    $type = LeaveType::create(['org_id' => $organization->id, 'name' => 'Vacation', 'code' => 'vac', 'paid' => 1]);

    $mine = LeaveRequest::create([
        'org_id' => $organization->id, 'user_id' => $worker->id, 'leave_type_id' => $type->id,
        'start_date' => '2026-09-07', 'end_date' => '2026-09-08', 'hours_per_day' => 8,
        'total_days' => 2, 'total_hours' => 16, 'half_day' => 0, 'status' => 'pending',
    ]);
    $theirs = LeaveRequest::create([
        'org_id' => $organization->id, 'user_id' => $other->id, 'leave_type_id' => $type->id,
        'start_date' => '2026-09-07', 'end_date' => '2026-09-08', 'hours_per_day' => 8,
        'total_days' => 2, 'total_hours' => 16, 'half_day' => 0, 'status' => 'pending',
    ]);

    $this->actingAs($worker)->post('/app/leave', ['action' => 'cancel', 'request_id' => $mine->id]);
    $this->actingAs($worker)->post('/app/leave', ['action' => 'cancel', 'request_id' => $theirs->id]);

    expect($mine->fresh()->status)->toBe('cancelled')
        ->and($theirs->fresh()->status)->toBe('pending');
});

test('leave from another tenant is untouchable', function () {
    $theirWorker = member(org(['name' => 'Globex']), UserRole::Member);
    $theirType = LeaveType::create(['org_id' => $theirWorker->org_id, 'name' => 'Vacation', 'code' => 'vac', 'paid' => 1]);

    $theirs = LeaveRequest::create([
        'org_id' => $theirWorker->org_id, 'user_id' => $theirWorker->id, 'leave_type_id' => $theirType->id,
        'start_date' => '2026-09-07', 'end_date' => '2026-09-08', 'hours_per_day' => 8,
        'total_days' => 2, 'total_hours' => 16, 'half_day' => 0, 'status' => 'pending',
    ]);

    $this->actingAs(member(org(['name' => 'Acme']), UserRole::HrManager))
        ->post('/app/leave', ['action' => 'review', 'request_id' => $theirs->id, 'decision' => 'approve']);

    expect($theirs->fresh()->status)->toBe('pending');
});

/* ── Adjustments ─────────────────────────────────────────────────────────── */

test('the kind decides the sign, never the amount', function () {
    $organization = org();
    $hr = member($organization, UserRole::HrManager);
    $worker = member($organization, UserRole::Member);

    foreach (['bonus' => 1, 'advance' => -1, 'deduction' => -1, 'commission' => 1] as $kind => $sign) {
        $this->actingAs($hr)->post('/app/adjustments', [
            'action' => 'create', 'user_id' => $worker->id, 'kind' => $kind,
            // Always positive on the way in.
            'amount' => 100, 'effective_date' => '2026-09-10',
        ]);

        expect(PayAdjustment::query()->where('kind', $kind)->value('sign'))->toBe($sign);
    }
});

test('a negative amount is stored as its absolute value', function () {
    $organization = org();
    $hr = member($organization, UserRole::HrManager);
    $worker = member($organization, UserRole::Member);

    $this->actingAs($hr)->post('/app/adjustments', [
        'action' => 'create', 'user_id' => $worker->id, 'kind' => 'deduction',
        'amount' => -250, 'effective_date' => '2026-09-10',
    ]);

    $adjustment = PayAdjustment::query()->first();

    expect((float) $adjustment->amount)->toBe(250.0)
        ->and((int) $adjustment->sign)->toBe(-1);
});

test('a zero amount is refused', function () {
    $organization = org();
    $hr = member($organization, UserRole::HrManager);

    $this->actingAs($hr)->post('/app/adjustments', [
        'action' => 'create', 'user_id' => member($organization, UserRole::Member)->id,
        'kind' => 'bonus', 'amount' => 0, 'effective_date' => '2026-09-10',
    ]);

    expect(PayAdjustment::query()->count())->toBe(0);
});

test('an adjustment cannot be filed against another tenant', function () {
    $outsider = member(org(['name' => 'Globex']), UserRole::Member);

    $this->actingAs(member(org(['name' => 'Acme']), UserRole::HrManager))
        ->post('/app/adjustments', [
            'action' => 'create', 'user_id' => $outsider->id,
            'kind' => 'bonus', 'amount' => 5000, 'effective_date' => '2026-09-10',
        ]);

    expect(PayAdjustment::query()->count())->toBe(0);
});

test('deleting is scoped to the tenant too', function () {
    $outsider = member(org(['name' => 'Globex']), UserRole::Member);

    $theirs = PayAdjustment::create([
        'org_id' => $outsider->org_id, 'user_id' => $outsider->id, 'kind' => 'bonus',
        'label' => 'Bonus', 'amount' => 100, 'sign' => 1, 'currency' => 'USD',
        'effective_date' => '2026-09-10', 'status' => 'approved',
    ]);

    $this->actingAs(member(org(['name' => 'Acme']), UserRole::HrManager))
        ->post('/app/adjustments', ['action' => 'delete', 'adjustment_id' => $theirs->id]);

    expect(PayAdjustment::query()->whereKey($theirs->id)->exists())->toBeTrue();
});
