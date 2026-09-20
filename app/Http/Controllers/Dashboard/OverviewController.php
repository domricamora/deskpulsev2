<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\Reporting\SessionStats;
use App\Support\Format;
use App\Support\Period;
use App\Support\Visibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * `/app/overview` — the dashboard.
 *
 * One route, three pages. A member gets a personal workspace; a manager or
 * admin gets the same charts widened to their roster plus the roster itself; a
 * platform operator gets neither, because the platform organization has no
 * tracked time of its own.
 *
 * Nothing here is capability-gated at the route. Everything that separates the
 * three is {@see Visibility::userIds()} and two `can` checks in the view — the
 * two-layer model in docs/migration/authorization.md §2.
 */
class OverviewController extends Controller
{
    public function __construct(
        private readonly SessionStats $stats,
        private readonly Period $period,
    ) {}

    public function show(Request $request)
    {
        $user = $request->user();

        // A platform operator's home is the console, not a dashboard.
        if ($user->isSuperAdmin() && ! $user->isActingAsOrganization()) {
            return redirect('/app/platform');
        }

        // A client portal has no Overview — its home is the agent roster.
        if ($user->role === UserRole::ClientViewer) {
            return redirect('/app/agents');
        }

        $organizationId = $user->effectiveOrgId();

        // The overview opens on Today, unlike every other dated page.
        $ctx = $this->period->context($request, $organizationId, 'day', ['day', 'week', 'pay', 'month']);

        $ids = Visibility::userIds($user);
        $sessions = $this->stats->forUsers($ids, $ctx['start'], $ctx['end']);
        $usersById = $this->stats->usersById($organizationId);
        $sessionIds = $sessions->pluck('id')->map(fn ($id) => (int) $id)->all();

        $summary = $this->stats->summarize($sessions);
        $canViewRates = $user->hasCapability(Capability::ViewRates);
        $teamView = $user->hasCapability(Capability::ViewAll) || $user->hasCapability(Capability::ViewTeam);

        $daysTracked = $sessions
            ->map(fn ($s) => substr($s->getRawOriginal('started_at'), 0, 10))
            ->unique()
            ->count();

        $stats = [
            'days_tracked'  => $daysTracked,
            'avg_daily_s'   => $daysTracked ? (int) round($summary['active_s'] / $daysTracked) : 0,
            'top_app'       => null,
            'screenshots'   => DB::table('screenshots')
                ->whereIn('session_id', fn ($q) => $q->select('id')->from('sessions')->whereIn('user_id', $ids))
                ->where('ts', '>=', $ctx['start'])
                ->where('ts', '<', $ctx['end'])
                ->count(),
            'open_tasks'    => Task::query()->whereIn('user_id', $ids)->where('status', 'open')->count(),
            'team_view'     => $teamView,
            'headcount'     => count($usersById),
            'people_active' => $sessions->pluck('user_id')->unique()->count(),
            'tracking_now'  => WorkSession::query()->whereIn('user_id', $ids)->whereNull('ended_at')->count(),
        ];

        $apps = $this->stats->topApps($sessionIds);
        $stats['top_app'] = $apps['labels'][0] ?? null;

        // Idle breaks across the period's sessions.
        $idle = $sessionIds
            ? DB::table('idle_periods')
                ->selectRaw('COUNT(*) AS c, COALESCE(SUM(duration_s), 0) AS s')
                ->whereIn('session_id', $sessionIds)
                ->first()
            : null;

        $stats['idle_count'] = (int) ($idle->c ?? 0);
        $stats['idle_total_s'] = (int) ($idle->s ?? 0);

        $activeSeconds = $sessions->map(fn ($s) => (int) $s->active_s);
        $stats['avg_session_s'] = $sessions->isNotEmpty()
            ? (int) round($activeSeconds->sum() / $sessions->count())
            : 0;
        $stats['longest_session_s'] = $activeSeconds->max() ?? 0;

        return view('dashboard.overview', [
            'title'        => $user->role === UserRole::Member ? 'Agent dashboard' : 'Overview',
            'active'       => 'overview',
            'ctx'          => $ctx,
            'summary'      => $summary,
            'cost'         => $this->stats->laborCost($sessions, $usersById),
            'timeline'     => $this->stats->activityTimeline($ids, $ctx['start'], $ctx['end']),
            'apps'         => $apps,
            'recentShots'  => $this->recentScreenshots($ids),
            'stats'        => $stats,
            'canViewRates' => $canViewRates,
            'roster'       => $this->roster($user, $ids, $sessions, $usersById, $teamView),
            'daily'        => $this->stats->dailySeries($sessions, $ctx['start'], $ctx['end'], $ctx['tz']),
            'agent'        => $this->agentCard($user),
            'periods'      => Period::options(['day', 'week', 'pay', 'month']),
        ]);
    }

    /**
     * The twelve most recent screenshots across everyone this viewer can see.
     *
     * Deliberately NOT limited to the period: the panel is "recent", and on a
     * quiet day the period query returns nothing while the agent has been
     * capturing all week.
     *
     * @param  list<int>  $ids
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function recentScreenshots(array $ids)
    {
        return DB::table('screenshots as sc')
            ->join('sessions as s', 's.id', '=', 'sc.session_id')
            ->whereIn('s.user_id', $ids)
            ->orderByDesc('sc.ts')
            ->limit(12)
            ->get(['sc.*']);
    }

    /**
     * The member self-dashboard: this person's own details and tracking setup.
     *
     * Deliberately personal only — no team, company or client data appears
     * here, because the role that sees it holds no capability to see any.
     *
     * @return array<string, mixed>|null
     */
    private function agentCard(User $user): ?array
    {
        if ($user->role !== UserRole::Member) {
            return null;
        }

        $device = Device::query()
            ->where('user_id', $user->id)
            // A device that has never checked in sorts last, not first.
            ->orderByRaw('(last_seen IS NULL), last_seen DESC')
            ->first(['name', 'last_seen']);

        $currentTask = WorkSession::query()
            ->join('tasks', 'tasks.id', '=', 'sessions.task_id')
            ->where('sessions.user_id', $user->id)
            ->whereNotNull('sessions.task_id')
            ->orderByDesc('sessions.started_at')
            ->value('tasks.title');

        return [
            'name'         => $user->name,
            'role'         => $user->role,
            'email'        => $user->email,
            'phone'        => $user->phone ?? '',
            'job_title'    => $user->job_title ?? '',
            'pay'          => Format::money($user->pay_rate, $user->currency)
                . ' / ' . ($user->pay_type ?: 'hourly'),
            'schedule'     => Format::workSchedule($user),
            // In the organization's reporting timezone, the clock the overtime
            // split uses. The badge and the money have to agree.
            'in_schedule'  => Format::withinWorkSchedule(
                $user,
                $this->period->timezone((int) $user->effectiveOrgId())
            ),
            'device'       => $device,
            'current_task' => $currentTask,
            'live'         => WorkSession::query()
                ->where('user_id', $user->id)
                ->whereNull('ended_at')
                ->exists(),
        ];
    }

    /**
     * The people under this viewer, with their tracked time and live status for
     * the period. Each row opens the agent modal.
     *
     * @param  list<int>  $ids
     * @param  array<int, User>  $usersById
     * @return list<array<string, mixed>>
     */
    private function roster(User $user, array $ids, $sessions, array $usersById, bool $teamView): array
    {
        // count($ids) > 1 rather than $teamView alone: a manager whose team is
        // just themselves has no roster to show.
        if (! $teamView || count($ids) <= 1) {
            return [];
        }

        $live = WorkSession::query()
            ->whereIn('user_id', $ids)
            ->whereNull('ended_at')
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->flip();

        $byUser = $sessions->groupBy(fn ($s) => (int) $s->user_id);

        $roster = [];

        foreach ($ids as $id) {
            if ($id === (int) $user->id) {
                continue;   // the viewer is not listed under themselves
            }

            $member = $usersById[$id] ?? null;

            if (! $member) {
                continue;
            }

            $summary = $this->stats->summarize($byUser->get($id, collect()));

            $roster[] = [
                'id'           => $id,
                'name'         => $member->name,
                'role'         => $member->role,
                'active_s'     => $summary['active_s'],
                'activity_pct' => $summary['activity_pct'],
                'sessions'     => $summary['count'],
                'live'         => $live->has($id),
            ];
        }

        usort($roster, fn ($a, $b) => $b['active_s'] <=> $a['active_s']);

        return $roster;
    }
}
