<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\Capability;
use App\Http\Controllers\Controller;
use App\Services\Payroll\PayRun;
use App\Services\Reporting\SessionStats;
use App\Support\Period;
use Illuminate\Http\Request;

/**
 * `/app/payroll` — the pay breakdown.
 *
 * Capability `payroll`, held by company admins and HR.
 *
 * Every money figure comes from {@see PayRun}, which is also what the payslip
 * PDF and the salary run read. The page adds only two things the run does not
 * carry, both of them about what is being HELD BACK rather than paid:
 *
 * - credited seconds per member, which is `creditableActiveSeconds()` and so
 *   already excludes unapproved overtime;
 * - the pending overtime total, so an admin can see how many hours are sitting
 *   on the Overtime page waiting for HR rather than wondering why the payroll
 *   looks light.
 *
 * Members with no pay-run row still appear, costed at base — somebody who has
 * not been set up yet should show as a zero, not vanish from the payroll.
 *
 * @see docs/migration/payroll.md §1
 */
class PayrollController extends Controller
{
    public function __construct(
        private readonly Period $period,
        private readonly PayRun $payRun,
        private readonly SessionStats $stats,
    ) {}

    public function show(Request $request)
    {
        $user = $request->user();
        $organizationId = (int) $user->effectiveOrgId();

        $context = $this->period->context($request, $organizationId, 'pay', ['day', 'week', 'pay', 'month']);

        $usersById = $this->stats->usersById($organizationId);
        $ids = array_keys($usersById);

        [$credited, $overtimePending] = $this->creditedAndHeld($ids, $context);

        $run = $this->payRun->compute($user, $context);
        $byUser = collect($run['rows'])->keyBy('user_id');

        $rows = [];
        $currency = $run['currency'];

        foreach ($usersById as $id => $member) {
            $payRow = $byUser->get($id);
            $creditedSeconds = $credited[$id] ?? 0;
            $hourly = $this->stats->hourlyRate($member);

            // No pay-run row means the member is out of the viewer's scope or
            // is a portal login; fall back to bare labor cost rather than
            // dropping them off the payroll entirely.
            $cost = $payRow ? $payRow['base'] : $creditedSeconds / 3600.0 * $hourly;
            $extras = $payRow ? $payRow['earnings'] + $payRow['leave_pay'] : 0.0;
            $deductions = $payRow ? $payRow['deductions'] : 0.0;

            $currency = $member->currency ?: $currency;

            $rows[] = [
                'user_id'            => (int) $id,
                'name'               => $member->name,
                'role'               => $member->role,
                'employment_type'    => $member->employment_type ?? 'full_time',
                'pay_type'           => $member->pay_type ?: 'hourly',
                'pay_rate'           => (float) $member->pay_rate,
                'currency'           => $member->currency ?: 'USD',
                'active_s'           => $creditedSeconds,
                'overtime_pending_s' => $overtimePending[$id] ?? 0,
                'hourly'             => $hourly,
                'cost'               => $cost,
                'leave_days'         => $payRow['leave_days'] ?? 0.0,
                'extras'             => $extras,
                'deductions'         => $deductions,
                'net'                => $payRow ? $payRow['net'] : $cost,
                'flags'              => $payRow['flags'] ?? [],
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['net'] <=> $a['net']);

        return view('dashboard.payroll', [
            'title'                 => 'Pay breakdown',
            'active'                => 'payroll',
            'period'                => $context,
            'periods'               => Period::options(['day', 'week', 'pay', 'month']),
            'rows'                  => $rows,
            'currency'              => $currency,
            'totalCost'             => array_sum(array_column($rows, 'cost')),
            'totalActiveSeconds'    => array_sum(array_column($rows, 'active_s')),
            'totalOvertimePending'  => array_sum(array_column($rows, 'overtime_pending_s')),
            'totalExtras'           => array_sum(array_column($rows, 'extras')),
            'totalDeductions'       => array_sum(array_column($rows, 'deductions')),
            'totalNet'              => array_sum(array_column($rows, 'net')),
            'canAdjust'             => $user->hasCapability(Capability::PayAdjustments),
        ]);
    }

    /**
     * Credited seconds and pending-overtime seconds per member.
     *
     * @param  list<int>  $ids
     * @param  array<string, mixed>  $context
     * @return array{0: array<int, int>, 1: array<int, int>}
     */
    private function creditedAndHeld(array $ids, array $context): array
    {
        $credited = array_fill_keys($ids, 0);
        $pending = array_fill_keys($ids, 0);

        foreach ($this->stats->forUsers($ids, $context['start'], $context['end']) as $session) {
            $id = (int) $session->user_id;

            $credited[$id] = ($credited[$id] ?? 0) + $session->creditableActiveSeconds();

            if (($session->overtime_status ?? 'none') === 'pending') {
                $pending[$id] = ($pending[$id] ?? 0) + (int) $session->overtime_s;
            }
        }

        return [$credited, $pending];
    }
}
