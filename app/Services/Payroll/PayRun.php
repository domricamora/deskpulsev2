<?php

namespace App\Services\Payroll;

use App\Enums\UserRole;
use App\Models\PayAdjustment;
use App\Models\User;
use App\Models\WiseAccount;
use App\Models\WorkSession;
use App\Services\Reporting\SessionStats;
use App\Support\Period;
use App\Support\Visibility;

/**
 * The single source of truth for money.
 *
 * Ports compute_pay_run(). The Payroll page, the payslip PDFs and the salary
 * run all read from here, so they cannot disagree with each other — that
 * single-entry-point property is the point of the class, and any second place
 * that computes pay is a bug waiting to be reconciled by hand.
 *
 * ## What is paid
 *
 * `creditableActiveSeconds()` — so overtime HR has not approved is NOT paid,
 * while billing charges it anyway (`billing.md` §3). Approved overtime is
 * tracked separately for reporting, not added again; it is already inside the
 * creditable seconds.
 *
 * ## Proration
 *
 * A monthly salary is divided by the calendar days of the month the period
 * STARTS in, so a semi-monthly month's two halves sum to exactly one salary
 * (15/31 + 16/31 = 1). The noon anchor on `date('t')` keeps a DST transition
 * from changing the month length.
 *
 * ## Paid leave is for hourly staff only
 *
 * A monthly salary already covers the day off. Adding leave pay on top would
 * pay a salaried person twice for the same absence.
 *
 * ## Caps are advisory
 *
 * Over-cap hours still track and still pay; the member is flagged. Turning a
 * cap into a hard limit would mean refusing to pay somebody for time they
 * actually worked.
 *
 * All arithmetic is PHP float over `double` columns, per decision D1.
 *
 * @see docs/migration/payroll.md §1
 */
class PayRun
{
    public function __construct(
        private readonly SessionStats $stats,
        private readonly LeaveLedger $leave,
        private readonly Period $period,
    ) {}

    /**
     * @param  array<string, mixed>  $context  a resolved Period::context()
     * @return array{rows: list<array<string, mixed>>, totals: array<string, float|int>, currency: string}
     */
    public function compute(User $viewer, array $context, ?int $onlyUserId = null): array
    {
        $organizationId = (int) $viewer->effectiveOrgId();
        $config = $this->period->organizationConfig($organizationId);
        $usersById = $this->stats->usersById($organizationId);

        $ids = Visibility::userIds($viewer);

        if ($onlyUserId !== null) {
            $ids = in_array($onlyUserId, $ids, true) ? [$onlyUserId] : [];
        }

        // A client portal login is a customer, not staff. They are never paid.
        $ids = array_values(array_filter(
            $ids,
            fn (int $id) => ($usersById[$id]->role ?? null) !== UserRole::ClientViewer
        ));

        if (! $ids) {
            return ['rows' => [], 'totals' => [], 'currency' => $config['pay_currency']];
        }

        $sessions = $this->stats->forUsers($ids, $context['start'], $context['end']);
        $adjustments = $this->adjustmentsInPeriod($ids, $context['start_date'], $context['end_date']);
        $leave = $this->leave->inPeriod($ids, $context['start_date'], $context['end_date'], $usersById);
        $wise = WiseAccount::query()->where('org_id', $organizationId)->get()->keyBy('user_id');

        [$seconds, $overtimeSeconds, $daily] = $this->worked($sessions, $config['tz']);

        $daysInMonth = (int) date('t', strtotime($context['start_date'] . ' 12:00:00'));
        $monthFactor = min(1.0, ((int) $context['days']) / max(1, $daysInMonth));

        $rows = [];

        foreach ($ids as $id) {
            $member = $usersById[$id] ?? null;

            if (! $member) {
                continue;
            }

            $hours = ($seconds[$id] ?? 0) / 3600.0;
            $rate = $this->stats->hourlyRate($member);
            $monthly = ($member->pay_type ?? 'hourly') === 'monthly';

            $base = $monthly ? (float) $member->pay_rate * $monthFactor : $hours * $rate;

            $memberLeave = $leave[$id]
                ?? ['paid_hours' => 0.0, 'unpaid_hours' => 0.0, 'days' => 0.0, 'by_type' => []];

            $leavePay = $monthly ? 0.0 : $memberLeave['paid_hours'] * $rate;

            $earnings = 0.0;
            $deductions = 0.0;

            foreach ($adjustments[$id] ?? [] as $adjustment) {
                if ((int) $adjustment->sign < 0) {
                    $deductions += (float) $adjustment->amount;
                } else {
                    $earnings += (float) $adjustment->amount;
                }
            }

            $gross = $base + $leavePay + $earnings;
            $account = $wise->get($id);

            $rows[] = [
                'user'               => $member,
                'user_id'            => $id,
                'name'               => $member->name,
                'role'               => $member->role,
                'employment_type'    => $member->employment_type ?? 'full_time',
                'pay_type'           => $member->pay_type ?? 'hourly',
                'pay_rate'           => (float) $member->pay_rate,
                'hourly'             => $rate,
                'currency'           => $member->currency ?: $config['pay_currency'],
                'hours'              => $hours,
                'overtime_hours'     => ($overtimeSeconds[$id] ?? 0) / 3600.0,
                'leave_paid_hours'   => (float) $memberLeave['paid_hours'],
                'leave_unpaid_hours' => (float) $memberLeave['unpaid_hours'],
                'leave_days'         => (float) $memberLeave['days'],
                'leave_by_type'      => $memberLeave['by_type'],
                'base'               => $base,
                'leave_pay'          => $leavePay,
                'earnings'           => $earnings,
                'deductions'         => $deductions,
                'gross'              => $gross,
                'net'                => $gross - $deductions,
                'adjustments'        => $adjustments[$id] ?? [],
                'flags'              => $this->capFlags($member, $hours, $daily[$id] ?? [], (int) $context['days']),
                'wise'               => $account,
                'payable'            => (bool) ($account?->active && ($account->recipient_id || $account->email)),
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['net'] <=> $a['net']);

        return ['rows' => $rows, 'totals' => $this->totals($rows), 'currency' => $config['pay_currency']];
    }

    /**
     * Creditable seconds per user, approved overtime per user, and seconds per
     * user per LOCAL day for the daily cap check.
     *
     * @return array{0: array<int, int>, 1: array<int, int>, 2: array<int, array<string, int>>}
     */
    private function worked($sessions, string $timezone): array
    {
        $seconds = [];
        $overtime = [];
        $daily = [];

        foreach ($sessions as $session) {
            $id = (int) $session->user_id;
            $creditable = $session->creditableActiveSeconds();

            $seconds[$id] = ($seconds[$id] ?? 0) + $creditable;

            if (($session->overtime_status ?? 'none') === 'approved') {
                $overtime[$id] = ($overtime[$id] ?? 0) + (int) ($session->overtime_s ?? 0);
            }

            // The organization's day, the same clock the overtime split uses.
            $day = Period::utcToLocalDate($session->getRawOriginal('started_at'), $timezone);
            $daily[$id][$day] = ($daily[$id][$day] ?? 0) + $creditable;
        }

        return [$seconds, $overtime, $daily];
    }

    /**
     * Approved adjustments in the window, per user.
     *
     * @param  list<int>  $userIds
     * @return array<int, \Illuminate\Support\Collection>
     */
    private function adjustmentsInPeriod(array $userIds, string $fromDate, string $toDate): array
    {
        return PayAdjustment::query()
            ->whereIn('user_id', $userIds)
            ->where('effective_date', '>=', $fromDate)
            ->where('effective_date', '<=', $toDate)
            ->where('status', 'approved')
            ->orderBy('effective_date')
            ->orderBy('id')
            ->get()
            ->groupBy('user_id')
            ->all();
    }

    /**
     * Advisory cap warnings. Never a block — the hours are paid either way.
     *
     * @param  array<string, int>  $dailySeconds
     * @return list<string>
     */
    private function capFlags(User $member, float $hours, array $dailySeconds, int $days): array
    {
        $flags = [];

        // The 0.01 slack keeps float noise from flagging somebody who worked
        // exactly their cap.
        $periodCap = (float) ($member->period_hours_cap ?? 0);

        if ($periodCap > 0 && $hours > $periodCap + 0.01) {
            $flags[] = sprintf('Over pay-period cap by %.2f h', $hours - $periodCap);
        }

        $dailyCap = (float) ($member->daily_hours_cap ?? 0);

        if ($dailyCap > 0) {
            $busiest = $dailySeconds ? max($dailySeconds) / 3600.0 : 0.0;

            if ($busiest > $dailyCap + 0.01) {
                $flags[] = sprintf('Busiest day %.2f h exceeds the %.2f h daily cap', $busiest, $dailyCap);
            }
        }

        $weeklyCap = (float) ($member->weekly_hours_cap ?? 0);

        if ($weeklyCap > 0 && $days >= 7) {
            $weeks = max(1.0, $days / 7.0);

            if ($hours / $weeks > $weeklyCap + 0.01) {
                $flags[] = sprintf('Averaging %.2f h/week against a %.2f h cap', $hours / $weeks, $weeklyCap);
            }
        }

        return $flags;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    private function totals(array $rows): array
    {
        return [
            'hours'      => array_sum(array_column($rows, 'hours')),
            'gross'      => array_sum(array_column($rows, 'gross')),
            'net'        => array_sum(array_column($rows, 'net')),
            'earnings'   => array_sum(array_column($rows, 'earnings')),
            'deductions' => array_sum(array_column($rows, 'deductions')),
            'leave_days' => array_sum(array_column($rows, 'leave_days')),
            'headcount'  => count($rows),
        ];
    }
}
