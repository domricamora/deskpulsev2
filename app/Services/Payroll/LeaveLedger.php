<?php

namespace App\Services\Payroll;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Period;
use Illuminate\Support\Facades\DB;

/**
 * Approved leave, turned into the hours and days a pay run needs.
 *
 * Ports leave_in_period(), leave_working_days() and member_work_days().
 *
 * ## Balances are derived, never stored
 *
 * There is no balance column anywhere and there must not be one. Every figure
 * is recomputed from approved requests, so a balance cannot drift away from
 * the requests that produced it.
 *
 * ## Requests are clipped to the window
 *
 * A leave spanning two pay periods is split between them rather than being
 * paid in full by both — the clip is what makes a semi-monthly run add up.
 *
 * ## Days count only against the member's own schedule
 *
 * A week off is five days for somebody on Monday–Friday and two for somebody
 * who works weekends. A member with no `work_days` set falls back to the
 * working week rather than counting zero days of leave.
 *
 * @see docs/migration/payroll.md §2
 */
class LeaveLedger
{
    /** @var list<string> */
    private const DEFAULT_WORK_DAYS = ['1', '2', '3', '4', '5'];

    /**
     * Approved leave overlapping a local date window, per user.
     *
     * @param  list<int>  $userIds
     * @param  array<int, User>  $usersById
     * @return array<int, array{paid_hours: float, unpaid_hours: float, days: float, by_type: array<string, float>}>
     */
    public function inPeriod(array $userIds, string $fromDate, string $toDate, array $usersById): array
    {
        if (! $userIds) {
            return [];
        }

        $rows = DB::table('leave_requests as lr')
            ->join('leave_types as lt', 'lt.id', '=', 'lr.leave_type_id')
            ->whereIn('lr.user_id', $userIds)
            ->where('lr.status', 'approved')
            // Overlap, not containment: a request that starts before the window
            // and ends inside it is partly this period's.
            ->where('lr.start_date', '<=', $toDate)
            ->where('lr.end_date', '>=', $fromDate)
            ->get(['lr.user_id', 'lr.start_date', 'lr.end_date', 'lr.half_day',
                'lr.hours_per_day', 'lt.name as type_name', 'lt.paid']);

        $ledger = [];

        foreach ($rows as $row) {
            $userId = (int) $row->user_id;

            $from = strcmp((string) $row->start_date, $fromDate) < 0 ? $fromDate : (string) $row->start_date;
            $to = strcmp((string) $row->end_date, $toDate) > 0 ? $toDate : (string) $row->end_date;

            $days = $this->workingDays($usersById[$userId] ?? null, $from, $to, (bool) $row->half_day);

            if ($days <= 0) {
                continue;
            }

            $hours = $days * (float) $row->hours_per_day;

            $ledger[$userId] ??= ['paid_hours' => 0.0, 'unpaid_hours' => 0.0, 'days' => 0.0, 'by_type' => []];
            $ledger[$userId][$row->paid ? 'paid_hours' : 'unpaid_hours'] += $hours;
            $ledger[$userId]['days'] += $days;
            $ledger[$userId]['by_type'][$row->type_name] =
                ($ledger[$userId]['by_type'][$row->type_name] ?? 0) + $days;
        }

        return $ledger;
    }

    /**
     * Days in an inclusive local range that fall on this member's work days.
     *
     * A half-day request counts 0.5 and can only ever span one day.
     */
    public function workingDays(?User $member, string $from, string $to, bool $halfDay = false): float
    {
        $workDays = $this->workDays($member);

        $days = 0.0;
        $day = $from;

        // The 400-iteration bound is the legacy's, and it is a guard rather
        // than a limit: no pay period is longer than a year.
        for ($i = 0; $i < 400 && strcmp($day, $to) <= 0; $i++) {
            // Noon anchor: a DST transition must not move a date's weekday.
            if (in_array((string) ((int) date('N', strtotime($day . ' 12:00:00'))), $workDays, true)) {
                $days += 1.0;
            }

            $day = Period::addDays($day, 1);
        }

        return $halfDay ? min($days, 0.5) : $days;
    }

    /**
     * Leave taken per type in a calendar year, for the entitlement balances.
     *
     * @return array<int, float>
     */
    public function usedDaysByType(User $member, int $year): array
    {
        $used = [];

        foreach (LeaveRequest::query()
            ->where('user_id', $member->id)
            ->where('status', 'approved')
            ->where('start_date', '<=', "{$year}-12-31")
            ->where('end_date', '>=', "{$year}-01-01")
            ->get(['leave_type_id', 'start_date', 'end_date', 'half_day']) as $request) {
            $from = max((string) $request->getRawOriginal('start_date'), "{$year}-01-01");
            $to = min((string) $request->getRawOriginal('end_date'), "{$year}-12-31");

            $typeId = (int) $request->leave_type_id;
            $used[$typeId] = ($used[$typeId] ?? 0)
                + $this->workingDays($member, $from, $to, (bool) $request->half_day);
        }

        return $used;
    }

    /** @return list<string> */
    private function workDays(?User $member): array
    {
        $days = array_values(array_filter(explode(',', (string) ($member->work_days ?? ''))));

        return $days ?: self::DEFAULT_WORK_DAYS;
    }
}
