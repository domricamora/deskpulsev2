<?php

/**
 * Phase 13 — payslip documents.
 *
 * A payslip names somebody's salary, so the two things worth asserting are
 * that it says the right numbers and that only the right people can open it.
 *
 * @see docs/migration/payroll.md §4
 */

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\PayAdjustment;
use App\Models\Payslip;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\Payroll\PayRun;
use App\Services\Payroll\Payslips;
use App\Support\Pdf;
use App\Support\Period;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('private');
});

function paid(User $user, string $day, int $activeSeconds, array $attributes = []): WorkSession
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

/** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
function payRunFor(User $viewer, Organization $organization, string $from, string $toExclusive): array
{
    $tz = app(Period::class)->timezone($organization->id);

    $context = [
        'period' => 'pay', 'tz' => $tz, 'cycle' => 'semimonthly',
        'start' => Period::localToUtc($from . ' 00:00:00', $tz),
        'end'   => Period::localToUtc($toExclusive . ' 00:00:00', $tz),
        'start_date' => $from, 'end_date' => Period::addDays($toExclusive, -1),
        'days' => (int) round((strtotime($toExclusive . ' 12:00:00') - strtotime($from . ' 12:00:00')) / 86400),
        'label' => 'test period', 'anchor' => $from,
    ];

    return [$context, app(PayRun::class)->compute($viewer, $context)];
}

/* ── The document ────────────────────────────────────────────────────────── */

test('a rendered payslip is a structurally valid PDF', function () {
    $organization = org(['name' => 'Acme Outsourcing']);
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member, [
        'name' => 'Ana Cruz', 'pay_type' => 'hourly', 'pay_rate' => 10.0,
    ]);

    paid($worker, '2026-09-03', 28800);

    [$context, $run] = payRunFor($admin, $organization, '2026-09-01', '2026-10-01');
    $row = collect($run['rows'])->firstWhere('user_id', $worker->id);

    $bytes = app(Payslips::class)->render($row, $context, $organization);

    expect($bytes)->toStartWith('%PDF-1.4')
        ->and($bytes)->toContain('%%EOF')
        ->and($bytes)->toContain("\nxref\n")
        // The base-14 fonts, so nothing has to be embedded.
        ->and($bytes)->toContain('/BaseFont /Helvetica')
        ->and($bytes)->toContain('PAYSLIP')
        ->and($bytes)->toContain('NET PAY')
        ->and($bytes)->toContain('Ana Cruz')
        ->and($bytes)->toContain('Acme Outsourcing');
});

test('the lines name what is being paid, in print order', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member, ['pay_type' => 'hourly', 'pay_rate' => 10.0]);

    paid($worker, '2026-09-03', 36000, [
        'overtime_s' => 3600, 'overtime_status' => 'approved', 'overtime_computed' => 1,
    ]);

    PayAdjustment::create([
        'org_id' => $organization->id, 'user_id' => $worker->id, 'kind' => 'bonus',
        'label' => 'Q3 bonus', 'amount' => 250.0, 'sign' => 1, 'currency' => 'USD',
        'effective_date' => '2026-09-05', 'status' => 'approved',
    ]);

    [, $run] = payRunFor($admin, $organization, '2026-09-01', '2026-10-01');
    $row = collect($run['rows'])->firstWhere('user_id', $worker->id);

    $lines = app(Payslips::class)->lines($row);

    expect($lines[0][0])->toContain('Worked hours')
        // An annotation, not a second payment — the amount is null.
        ->and($lines[1][0])->toContain('approved overtime')
        ->and($lines[1][2])->toBeNull()
        ->and($lines[2][0])->toBe('Bonus — Q3 bonus')
        ->and($lines[2][2])->toBe(250.0);
});

test('salaried leave is recorded without being paid twice', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member, [
        'pay_type' => 'monthly', 'pay_rate' => 3000.0, 'work_days' => '1,2,3,4,5',
    ]);

    $type = \App\Models\LeaveType::create([
        'org_id' => $organization->id, 'name' => 'Vacation', 'code' => 'vac', 'paid' => 1,
    ]);

    \App\Models\LeaveRequest::create([
        'org_id' => $organization->id, 'user_id' => $worker->id, 'leave_type_id' => $type->id,
        'start_date' => '2026-09-07', 'end_date' => '2026-09-08', 'hours_per_day' => 8,
        'total_days' => 2, 'total_hours' => 16, 'half_day' => 0, 'status' => 'approved',
    ]);

    [, $run] = payRunFor($admin, $organization, '2026-09-01', '2026-10-01');
    $row = collect($run['rows'])->firstWhere('user_id', $worker->id);

    $leaveLine = collect(app(Payslips::class)->lines($row))
        ->first(fn ($line) => str_contains($line[0], 'Time off'));

    expect($leaveLine[0])->toContain('covered by salary')
        ->and($leaveLine[2])->toBeNull();
});

test('the writer measures real character widths', function () {
    // This is what makes right alignment and wrapping land correctly. A
    // fixed-width guess would drift further the longer the string.
    $pdf = new Pdf();
    $pdf->setFont(false, 10);

    expect($pdf->textWidth('lll'))->toBeLessThan($pdf->textWidth('WWW'))
        ->and($pdf->textWidth(''))->toBe(0.0);
});

test('a non-WinAnsi character is transliterated rather than corrupting the file', function () {
    // Payslips carry names and currency symbols from everywhere.
    expect(Pdf::toWinAnsi('caf—é · ₱500'))->not->toContain('₱')
        ->and(Pdf::toWinAnsi('₱500'))->toContain('PHP');
});

/* ── Caching ─────────────────────────────────────────────────────────────── */

test('a payslip is generated once and reused', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member, ['pay_type' => 'hourly', 'pay_rate' => 10.0]);

    paid($worker, '2026-09-03', 3600);

    [$context, $run] = payRunFor($admin, $organization, '2026-09-01', '2026-10-01');
    $row = collect($run['rows'])->firstWhere('user_id', $worker->id);

    [$first] = app(Payslips::class)->generate($row, $context, $organization);
    [$second] = app(Payslips::class)->generate($row, $context, $organization);

    expect($second)->toBe($first)
        ->and(Payslip::query()->count())->toBe(1);
});

test('regenerating replaces the old file rather than leaving it behind', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member, ['pay_type' => 'hourly', 'pay_rate' => 10.0]);

    paid($worker, '2026-09-03', 3600);

    [$context, $run] = payRunFor($admin, $organization, '2026-09-01', '2026-10-01');
    $row = collect($run['rows'])->firstWhere('user_id', $worker->id);

    [$first] = app(Payslips::class)->generate($row, $context, $organization);
    [$second] = app(Payslips::class)->generate($row, $context, $organization, force: true);

    expect($second)->not->toBe($first)
        ->and(Storage::disk('private')->exists($first))->toBeFalse()
        ->and(Storage::disk('private')->exists($second))->toBeTrue()
        ->and(Payslip::query()->count())->toBe(1);
});

test('payslips are stored on the private disk, never under the document root', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member, ['pay_type' => 'hourly', 'pay_rate' => 10.0]);

    paid($worker, '2026-09-03', 3600);

    [$context, $run] = payRunFor($admin, $organization, '2026-09-01', '2026-10-01');
    [$path] = app(Payslips::class)->generate(
        collect($run['rows'])->firstWhere('user_id', $worker->id),
        $context,
        $organization
    );

    expect($path)->toStartWith('payslips/')
        ->and(is_file(public_path($path)))->toBeFalse();
});

/* ── Who may open one ────────────────────────────────────────────────────── */

test('a member downloads their own payslip', function () {
    $organization = org();
    $worker = member($organization, UserRole::Member, ['pay_type' => 'hourly', 'pay_rate' => 10.0]);

    paid($worker, gmdate('Y-m-d'), 3600);

    $this->actingAs($worker)->get('/app/payslip')->assertOk();

    $response = $this->actingAs($worker)->get('/app/payslip.pdf')->assertOk();

    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

test('a member cannot download somebody else payslip', function () {
    $organization = org();
    $worker = member($organization, UserRole::Member, ['pay_type' => 'hourly', 'pay_rate' => 10.0]);
    $colleague = member($organization, UserRole::Member, ['pay_type' => 'hourly', 'pay_rate' => 99.0]);

    paid($colleague, gmdate('Y-m-d'), 3600);

    $this->actingAs($worker)
        ->get('/app/payslip.pdf?user_id=' . $colleague->id)
        ->assertForbidden();
});

test('payroll can download anybody payslip', function () {
    $organization = org();
    $hr = member($organization, UserRole::HrManager);
    $worker = member($organization, UserRole::Member, ['pay_type' => 'hourly', 'pay_rate' => 10.0]);

    paid($worker, gmdate('Y-m-d'), 3600);

    $this->actingAs($hr)->get('/app/payslip.pdf?user_id=' . $worker->id)->assertOk();
});

test('a client portal has no payslip at all', function () {
    $portal = member(org(), UserRole::ClientViewer);

    $this->actingAs($portal)->get('/app/payslip')->assertForbidden();
    $this->actingAs($portal)->get('/app/payslip.pdf')->assertForbidden();
});

test('the bulk page needs the payroll capability', function () {
    $this->actingAs(member(org(), UserRole::HrManager))->get('/app/payslips')->assertOk();
    $this->actingAs(member(org(), UserRole::Member))->get('/app/payslips')->assertRedirect();
});

/* ── Emailing ────────────────────────────────────────────────────────────── */

test('generating queues an email once and does not re-send', function () {
    $organization = org();
    $hr = member($organization, UserRole::HrManager);
    $worker = member($organization, UserRole::Member, [
        'name' => 'Ana Cruz', 'email' => 'ana@example.test', 'pay_type' => 'hourly', 'pay_rate' => 10.0,
    ]);

    paid($worker, gmdate('Y-m-d'), 3600);

    $send = fn (array $extra = []) => $this->actingAs($hr)->post('/app/payslips', array_merge([
        'action' => 'generate_email', 'period' => 'month', 'date' => gmdate('Y-m-d'),
    ], $extra));

    $send();
    expect(\App\Models\EmailOutbox::query()->where('kind', 'payslip')->count())->toBe(1);

    // Again, without asking for a re-send.
    $send();
    expect(\App\Models\EmailOutbox::query()->where('kind', 'payslip')->count())->toBe(1);

    // And again, explicitly.
    $send(['resend' => '1']);
    expect(\App\Models\EmailOutbox::query()->where('kind', 'payslip')->count())->toBe(2);
});

test('a synthetic import address is never emailed', function () {
    $organization = org();
    $hr = member($organization, UserRole::HrManager);
    $worker = member($organization, UserRole::Member, [
        'name' => 'Imported Person', 'email' => 'vt1.org9@import.deskpulse.local',
        'pay_type' => 'hourly', 'pay_rate' => 10.0,
    ]);

    paid($worker, gmdate('Y-m-d'), 3600);

    $this->actingAs($hr)->post('/app/payslips', [
        'action' => 'generate_email', 'period' => 'month', 'date' => gmdate('Y-m-d'),
    ]);

    // The PDF is still made; only the send is skipped.
    expect(Payslip::query()->count())->toBe(1)
        ->and(\App\Models\EmailOutbox::query()->where('kind', 'payslip')->count())->toBe(0);
});

test('somebody with nothing to pay gets no payslip', function () {
    $organization = org();
    $hr = member($organization, UserRole::HrManager);
    member($organization, UserRole::Member, ['pay_type' => 'hourly', 'pay_rate' => 10.0]);

    $this->actingAs($hr)->post('/app/payslips', [
        'action' => 'generate', 'period' => 'month', 'date' => gmdate('Y-m-d'),
    ]);

    expect(Payslip::query()->count())->toBe(0);
});
