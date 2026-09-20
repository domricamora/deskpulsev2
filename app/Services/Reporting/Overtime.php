<?php

namespace App\Services\Reporting;

use App\Models\User;
use App\Models\WorkSession;
use App\Support\Period;
use Illuminate\Support\Collection;

/**
 * Splitting a closed session's active time into regular and overtime.
 *
 * Ports recompute_overtime(), overtime_persist() and
 * recompute_pending_overtime() from server/src/reports.php.
 *
 * This runs on the INGEST path, not at report time: the agent closing a session
 * triggers it, and the result then waits on HR (`approve_overtime`) before any
 * of it can be paid. {@see SessionStats::creditableSeconds()} is the other half
 * — it subtracts an unapproved overtime portion from anything that reaches
 * payroll.
 *
 * ## Two rules, whichever applies
 *
 * 1. Active time whose wall-clock falls outside the scheduled window, or on a
 *    day that is not a scheduled work day, is overtime.
 * 2. Active time inside the window beyond the day's scheduled LENGTH is
 *    overtime — so two sessions that each sit inside 09:00–17:00 can still
 *    produce overtime between them.
 *
 * A worker with no schedule set has no overtime at all. Nothing can be "beyond"
 * a schedule that does not exist, and treating an unset schedule as zero
 * allowance would make every tracked second overtime for everyone who has not
 * been set up yet.
 *
 * ## Which clock (decision D2, corrected)
 *
 * Everything here happens in the ORGANIZATION's `report_tz`: the day a session
 * is bucketed into, and the wall-clock window `work_start`–`work_end` is
 * measured against. Sessions are stored in UTC and are read as UTC explicitly,
 * so the result does not depend on the host's `date.timezone`.
 *
 * It used to. Both this and the legacy `recompute_overtime()` parsed the stored
 * value with a bare `strtotime()`, which reads it as server-local, and compared
 * it against a window built the same way — so a 09:00–17:00 schedule was
 * effectively 09:00–17:00 *on the server's clock*, not the organization's. For
 * any tenant outside the server's timezone that is simply the wrong window, and
 * the answer moved if the host's php.ini changed or the app was redeployed
 * somewhere else.
 *
 * `report_tz` is the clock the rest of the product already settled on: the pay
 * run cuts its periods in it, `daily_series()` buckets its bars in it, and the
 * column exists precisely because the reporting window "silently depended on
 * php.ini's date.timezone" (the legacy schema migration says so in as many
 * words). Overtime was the one calculation left behind, which mattered most
 * because overtime is what gets paid.
 *
 * Restating existing rows after this change is `php artisan overtime:recompute`.
 *
 * ## A limitation carried over deliberately
 *
 * A window that wraps midnight (22:00–06:00, a night shift) yields a negative
 * span, so the day's allowance is zero and every tracked second becomes
 * overtime. The legacy behaves identically, and
 * {@see \App\Support\Format::withinWorkSchedule()} agrees with it. Fixing it is
 * a change to what people are paid and belongs in its own approved pass.
 */
class Overtime
{
    public function __construct(private readonly Period $period) {}

    /**
     * Recompute every closed session for one user, optionally from a date.
     *
     * $since is a UTC datetime string. Callers pass a day EARLIER than the
     * session they care about, so the daily-allowance walk sees that whole
     * day's sessions rather than starting mid-day with the allowance already
     * partly spent.
     */
    public function recompute(int $userId, ?string $since = null): void
    {
        $user = User::query()
            ->whereKey($userId)
            ->first(['org_id', 'work_start', 'work_end', 'work_days']);

        if (! $user) {
            return;
        }

        $timezone = $this->period->timezone((int) $user->org_id);

        $sessions = WorkSession::query()
            ->where('user_id', $userId)
            ->whereNotNull('ended_at')
            ->when($since !== null, fn ($query) => $query->where('started_at', '>=', $since))
            ->orderBy('started_at')
            ->get();

        if ($sessions->isEmpty()) {
            return;
        }

        $start = $user->work_start;
        $end = $user->work_end;
        $days = array_values(array_filter(explode(',', (string) $user->work_days)));
        $scheduleSet = $start && $end && $days;

        // Grouped by the organization's calendar day, then each day walked in
        // order so rule 2 can track how much of the day's allowance earlier
        // sessions already consumed.
        $byDay = $sessions->groupBy(
            fn (WorkSession $session) => Period::utcToLocalDate(
                $session->getRawOriginal('started_at'),
                $timezone
            )
        );

        foreach ($byDay as $day => $rows) {
            $this->walkDay((string) $day, $rows, $scheduleSet, $days, (string) $start, (string) $end, $timezone);
        }
    }

    /**
     * @param  Collection<int, WorkSession>  $rows
     * @param  list<string>  $days
     */
    private function walkDay(string $day, Collection $rows, bool $scheduleSet, array $days, string $start, string $end, string $timezone): void
    {
        // Anchored at midday so no timezone reading of the date string can land
        // on the neighbouring day and change which weekday this is.
        $dayOfWeek = (string) ((int) date('N', strtotime($day . ' 12:00:00')));
        $scheduled = $scheduleSet && in_array($dayOfWeek, $days, true);

        // The schedule is wall-clock in the organization's timezone; sessions
        // are UTC instants. Convert the window once, then every comparison
        // below is instant-against-instant.
        $windowStart = $scheduled ? self::instant(Period::localToUtc($day . ' ' . $start, $timezone)) : 0;
        $windowEnd = $scheduled ? self::instant(Period::localToUtc($day . ' ' . $end, $timezone)) : 0;

        // The regular seconds available on this day, consumed in session order.
        $allowance = $scheduled ? max(0, $windowEnd - $windowStart) : 0;
        $regularUsed = 0;

        foreach ($rows as $session) {
            if (! $scheduleSet) {
                $this->persist($session, 0);

                continue;
            }

            $active = (int) $session->active_s;
            $startedAt = self::instant($session->getRawOriginal('started_at'));
            $endedAt = self::instant($session->getRawOriginal('ended_at'));
            $span = max(0, $endedAt - $startedAt);

            // Active seconds whose wall-clock falls inside the scheduled window.
            // Apportioned by overlap, because the agent reports a total for the
            // session rather than a per-second timeline.
            if (! $scheduled) {
                $insideActive = 0;                       // not a work day → all outside
            } elseif ($span > 0) {
                $overlap = max(0, min($endedAt, $windowEnd) - max($startedAt, $windowStart));
                $insideActive = (int) round($active * $overlap / $span);
            } else {
                $insideActive = ($startedAt >= $windowStart && $startedAt <= $windowEnd) ? $active : 0;
            }

            $insideActive = max(0, min($active, $insideActive));
            $outsideActive = $active - $insideActive;    // rule 1

            $regularPart = min($insideActive, max(0, $allowance - $regularUsed));
            $regularUsed += $regularPart;

            $this->persist($session, $outsideActive + ($insideActive - $regularPart));
        }
    }

    /**
     * A stored UTC datetime as an epoch second.
     *
     * The ` UTC` matters. Without it `strtotime()` reads the value in whatever
     * `date.timezone` the process happens to have, which is the whole bug this
     * calculation used to carry.
     */
    private static function instant(string $utcDatetime): int
    {
        return (int) strtotime($utcDatetime . ' UTC');
    }

    /**
     * Write one session's overtime, preserving a decision HR has already made.
     *
     * An approved or rejected split keeps its status and only has its AMOUNT
     * refreshed — otherwise a late-arriving offline batch would silently reopen
     * something HR had already signed off.
     */
    private function persist(WorkSession $session, int $overtime): void
    {
        $overtime = max(0, $overtime);
        $current = $session->overtime_status ?? 'none';

        $status = match (true) {
            $overtime <= 0 => 'none',
            $current === 'approved', $current === 'rejected' => $current,
            default => 'pending',
        };

        $session->forceFill([
            'overtime_s'        => $overtime,
            'overtime_status'   => $status,
            'overtime_computed' => 1,
        ])->save();
    }

    /**
     * Process closed sessions whose split has not been computed — typically
     * after a bulk stale-session close, which ends sessions without going
     * through the agent's PATCH.
     *
     * A cheap no-op when nothing is outstanding.
     */
    public function recomputePending(): void
    {
        $outstanding = WorkSession::query()
            ->selectRaw('user_id, MIN(started_at) AS since')
            ->whereNotNull('ended_at')
            ->where('overtime_computed', 0)
            ->groupBy('user_id')
            ->get();

        foreach ($outstanding as $row) {
            // A day earlier, for the allowance walk's boundary slack. One whole
            // day covers every offset, since none is further out than ±14h.
            $this->recompute((int) $row->user_id, self::dayBefore((string) $row->since));
        }
    }

    /**
     * Midnight UTC the day before a stored UTC datetime — the lower bound a
     * recompute starts from, so the allowance walk sees the session's whole
     * local day instead of joining it halfway through.
     */
    public static function dayBefore(string $utcDatetime): string
    {
        return gmdate('Y-m-d 00:00:00', self::instant($utcDatetime) - 86400);
    }
}
