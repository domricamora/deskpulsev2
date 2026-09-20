<?php

namespace App\Services\Reporting;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\WorkSession;
use App\Support\Period;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rolling work sessions up into the numbers the dashboard renders.
 *
 * Ports sessions_for_users(), summarize(), labor_cost(), daily_series(),
 * top_apps(), activity_timeline() and users_by_id() from
 * server/src/reports.php. The dashboard and the public share view both read
 * these, so a change here is visible in two places.
 *
 * ## Approved-only, by default
 *
 * Every rollup counts approved sessions only. A manual entry sitting at
 * `pending` must not appear in anyone's hours, cost or charts until a manager
 * acts on it — otherwise approving would be cosmetic. The one caller that
 * passes false is the approvals queue itself, which exists to show the
 * unapproved rows.
 */
class SessionStats
{
    /**
     * Sessions for a set of users starting inside [start, end).
     *
     * The window is on `started_at`, not on overlap: a session that began
     * before the window and ran into it belongs to the day it started, which is
     * how the timesheet, the pay run and the charts all group it.
     *
     * @param  list<int>  $userIds
     * @return Collection<int, WorkSession>
     */
    public function forUsers(array $userIds, string $start, string $end, bool $approvedOnly = true): Collection
    {
        if (! $userIds) {
            return collect();
        }

        return WorkSession::query()
            ->whereIn('user_id', $userIds)
            ->where('started_at', '>=', $start)
            ->where('started_at', '<', $end)
            ->when($approvedOnly, fn ($query) => $query->where('approval_status', 'approved'))
            ->orderBy('started_at')
            ->get();
    }

    /**
     * Roll a list of sessions into totals.
     *
     * @param  Collection<int, WorkSession>  $sessions
     * @return array{active_s: int, inactive_s: int, total_s: int, activity_pct: int, count: int}
     */
    public function summarize(Collection $sessions): array
    {
        $active = (int) $sessions->sum('active_s');
        $inactive = (int) $sessions->sum('inactive_s');
        $total = $active + $inactive;

        return [
            'active_s'     => $active,
            'inactive_s'   => $inactive,
            'total_s'      => $total,
            'activity_pct' => $total ? (int) round(100 * $active / $total) : 0,
            'count'        => $sessions->count(),
        ];
    }

    /**
     * Creditable active seconds for pay: the overtime portion is excluded
     * unless it has been approved.
     *
     * So 'pending' and 'rejected' overtime never reaches labor cost or payroll.
     * Ports creditable_active_s().
     */
    public function creditableSeconds(WorkSession $session): int
    {
        if (($session->overtime_status ?? 'none') === 'approved') {
            return (int) $session->active_s;
        }

        return max(0, (int) $session->active_s - (int) ($session->overtime_s ?? 0));
    }

    /** Normalize a user's pay to an hourly figure. Ports user_hourly_rate(). */
    public function hourlyRate(User $user): float
    {
        $rate = (float) ($user->pay_rate ?? 0);

        if ($rate <= 0) {
            return 0.0;
        }

        // 173.33 = 52 weeks x 40 hours / 12 months. The legacy constant.
        return $user->pay_type === 'monthly' ? $rate / 173.33 : $rate;
    }

    /**
     * Internal labor cost across a set of sessions.
     *
     * @param  Collection<int, WorkSession>  $sessions
     * @param  array<int, User>  $usersById
     * @return array{amount: float, currency: string}
     */
    public function laborCost(Collection $sessions, array $usersById): array
    {
        $total = 0.0;
        $currency = 'USD';

        foreach ($sessions as $session) {
            $user = $usersById[(int) $session->user_id] ?? null;

            if (! $user) {
                continue;
            }

            $currency = $user->currency ?: $currency;
            $total += $this->creditableSeconds($session) / 3600.0 * $this->hourlyRate($user);
        }

        return ['amount' => round($total, 2), 'currency' => $currency];
    }

    /**
     * Per-day active vs inactive hours for the bar chart.
     *
     * Bucketed by the ORGANIZATION's reporting day, not the server's. $start
     * and $end are UTC, and bucketing on the naive string put bars in a
     * different day than the table rows directly beneath them.
     *
     * @param  Collection<int, WorkSession>  $sessions
     * @return array{labels: list<string>, active: list<float>, inactive: list<float>}
     */
    public function dailySeries(Collection $sessions, string $start, string $end, string $tz): array
    {
        $firstDay = Period::utcToLocalDate($start, $tz);
        $lastDay = Period::utcToLocalDate(date('Y-m-d H:i:s', strtotime($end) - 1), $tz);

        $labels = [];
        $buckets = [];
        $day = $firstDay;

        // The 400 cap is the legacy guard against a malformed range spinning here.
        for ($i = 0; $i < 400 && strcmp($day, $lastDay) <= 0; $i++) {
            $labels[] = $day;
            $buckets[$day] = ['active' => 0, 'inactive' => 0];
            $day = Period::addDays($day, 1);
        }

        foreach ($sessions as $session) {
            $key = Period::utcToLocalDate($session->getRawOriginal('started_at'), $tz);

            if (isset($buckets[$key])) {
                $buckets[$key]['active'] += (int) $session->active_s;
                $buckets[$key]['inactive'] += (int) $session->inactive_s;
            }
        }

        return [
            'labels'   => $labels,
            'active'   => array_map(fn ($k) => round($buckets[$k]['active'] / 3600, 2), $labels),
            'inactive' => array_map(fn ($k) => round($buckets[$k]['inactive'] / 3600, 2), $labels),
        ];
    }

    /**
     * Focused-window seconds by application across a set of sessions.
     *
     * @param  list<int>  $sessionIds
     * @return array{labels: list<string>, seconds: list<int>}
     */
    public function topApps(array $sessionIds, int $limit = 8): array
    {
        if (! $sessionIds) {
            return ['labels' => [], 'seconds' => []];
        }

        $rows = DB::table('window_events')
            ->selectRaw('app_name, SUM(focus_seconds) AS secs')
            ->whereIn('session_id', $sessionIds)
            ->groupBy('app_name')
            ->orderByDesc('secs')
            ->limit(max(1, $limit))
            ->get();

        return [
            'labels'  => $rows->map(fn ($r) => $r->app_name ?: 'Unknown')->all(),
            'seconds' => $rows->map(fn ($r) => (int) $r->secs)->all(),
        ];
    }

    /**
     * Activity timeline for the interactive chart: activity-% points over time
     * plus screenshot markers, each annotated with the app and window that were
     * focused and with who was working, so the UI can show a hover tooltip and
     * a screenshot preview.
     *
     * @param  list<int>  $userIds
     * @return array{points: list<array{x: int, pct: int}>, markers: list<array<string, mixed>>, start: int, end: int}
     */
    public function activityTimeline(array $userIds, string $start, string $end): array
    {
        $startEpoch = strtotime($start . ' UTC');
        $endEpoch = strtotime($end . ' UTC');
        $empty = ['points' => [], 'markers' => [], 'start' => $startEpoch, 'end' => $endEpoch];

        if (! $userIds) {
            return $empty;
        }

        $sessions = DB::table('sessions')
            ->select('id', 'user_id')
            ->whereIn('user_id', $userIds)
            ->where('started_at', '>=', $start)
            ->where('started_at', '<', $end)
            ->where('approval_status', 'approved')
            ->get();

        if ($sessions->isEmpty()) {
            return $empty;
        }

        $sessionIds = $sessions->pluck('id')->map(fn ($id) => (int) $id)->all();
        $userBySession = $sessions->pluck('user_id', 'id')->all();

        $names = User::query()
            ->whereIn('id', $userIds)
            ->pluck('name', 'id')
            ->all();

        // The 800 cap keeps a long window from returning a point per second.
        $points = DB::table('activity_samples')
            ->select('ts', 'activity_pct')
            ->whereIn('session_id', $sessionIds)
            ->orderBy('ts')
            ->limit(800)
            ->get()
            ->map(fn ($a) => ['x' => strtotime($a->ts . ' UTC'), 'pct' => (int) $a->activity_pct])
            ->all();

        // Focused-window observations per session, in order, to label each shot.
        $windowsBySession = [];

        foreach (DB::table('window_events')
            ->select('session_id', 'ts', 'app_name', 'window_title')
            ->whereIn('session_id', $sessionIds)
            ->orderBy('ts')
            ->get() as $window) {
            $windowsBySession[$window->session_id][] = [
                't'     => strtotime($window->ts . ' UTC'),
                'app'   => $window->app_name,
                'title' => $window->window_title,
            ];
        }

        $markers = [];

        foreach (DB::table('screenshots')
            ->select('id', 'session_id', 'ts', 'file_path')
            ->whereIn('session_id', $sessionIds)
            ->orderBy('ts')
            ->get() as $shot) {
            $at = strtotime($shot->ts . ' UTC');
            $app = '';
            $title = '';

            // The last window seen at or just after the shot (60s of slack, since
            // the capture and the window poll are on different timers).
            foreach ($windowsBySession[$shot->session_id] ?? [] as $window) {
                if ($window['t'] <= $at + 60) {
                    $app = $window['app'];
                    $title = $window['title'];
                } else {
                    break;
                }
            }

            $markers[] = [
                'x'     => $at,
                // Served through the authorizing route, not as a public file
                // (decision D4, taken in Phase 9).
                'url'   => route('screenshots.image', $shot->id),
                'app'   => $app,
                'title' => $title,
                'who'   => $names[$userBySession[$shot->session_id] ?? null] ?? '',
            ];
        }

        return ['points' => $points, 'markers' => $markers, 'start' => $startEpoch, 'end' => $endEpoch];
    }

    /**
     * Every member of an organization, keyed by id. Platform operators are
     * excluded — see {@see \App\Support\Visibility::organizationUserIds()}.
     *
     * @return array<int, User>
     */
    public function usersById(int $organizationId): array
    {
        return User::query()
            ->where('org_id', $organizationId)
            ->where('role', '<>', UserRole::SuperAdmin->value)
            ->get()
            ->keyBy(fn (User $user) => (int) $user->id)
            ->all();
    }
}
