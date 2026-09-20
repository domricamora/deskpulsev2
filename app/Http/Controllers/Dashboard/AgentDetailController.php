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
use App\Support\Flash;
use App\Support\Period;
use App\Support\Visibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * `/app/agents/{id}` — one agent, in full.
 *
 * Capability `view_agents`, plus a scope check. Two details carried over from
 * the legacy because both are deliberate:
 *
 * - **A platform operator is scoped to the agent's own organization** rather
 *   than refused. Their own `org_id` is the Platform org unless they have
 *   clicked Act as, and they hold every capability, so a support link straight
 *   to an agent should open rather than 403.
 * - **Out of scope redirects home with a flash**, not a 403 page. The usual
 *   way to land here wrongly is a stale link after switching accounts.
 *
 * Screenshots and rates are each gated separately inside the page: a role can
 * hold `view_agents` without holding either.
 *
 * @see docs/migration/routes.md §5
 */
class AgentDetailController extends Controller
{
    public function __construct(
        private readonly Period $period,
        private readonly SessionStats $stats,
    ) {}

    public function show(Request $request, int $id)
    {
        $viewer = $request->user();
        $agent = User::query()->whereKey($id)->first();

        abort_if($agent === null || $agent->role === UserRole::SuperAdmin, 404, 'Agent not found.');

        $organizationId = (int) $viewer->effectiveOrgId();

        if ($viewer->isSuperAdmin()) {
            // Scope the request to the agent's org rather than the operator's.
            $organizationId = (int) $agent->org_id;
        } elseif (! in_array($id, Visibility::userIds($viewer), true)
            || (int) $agent->org_id !== $organizationId) {
            Flash::error('That agent is not available.');

            return redirect('/app/agents');
        }

        $context = $this->period->context($request, $organizationId, 'week', ['day', 'week', 'pay', 'month']);

        $sessions = $this->stats->forUsers([$id], $context['start'], $context['end']);
        $sessionIds = $sessions->pluck('id')->all();

        $canSeeScreenshots = $viewer->hasCapability(Capability::Screenshots);

        $idle = $sessionIds
            ? DB::table('idle_periods')
                ->selectRaw('COUNT(*) AS c, COALESCE(SUM(duration_s), 0) AS s')
                ->whereIn('session_id', $sessionIds)
                ->first()
            : null;

        $screenshots = ($sessionIds && $canSeeScreenshots)
            ? DB::table('screenshots')
                ->whereIn('session_id', $sessionIds)
                ->orderByDesc('ts')
                ->limit(24)
                ->get()
            : collect();

        $activeSeconds = $sessions->pluck('active_s')->map(fn ($s) => (int) $s)->all();

        return view('dashboard.agent_detail', [
            'title'           => $agent->name,
            'active'          => 'agents',
            'period'          => $context,
            'periods'         => Period::options(['day', 'week', 'pay', 'month']),
            'agent'           => $agent,
            'summary'         => $this->stats->summarize($sessions),
            'timeline'        => $this->stats->activityTimeline([$id], $context['start'], $context['end']),
            'daily'           => $this->stats->dailySeries($sessions, $context['start'], $context['end'], $context['tz']),
            'apps'            => $this->stats->topApps($sessionIds),
            'screenshots'     => $screenshots,
            'recentSessions'  => WorkSession::query()
                ->leftJoin('clients', 'clients.id', '=', 'sessions.client_id')
                ->where('sessions.user_id', $id)
                ->where('sessions.started_at', '>=', $context['start'])
                ->where('sessions.started_at', '<', $context['end'])
                ->orderByDesc('sessions.started_at')
                ->limit(25)
                ->get(['sessions.*', 'clients.name as client_name']),
            'tasks'           => Task::query()
                ->where('user_id', $id)
                ->orderBy('status')
                ->orderByDesc('created_at')
                ->get(),
            'taskTime'        => $this->timePerTask($id),
            'teams'           => DB::table('team_members as tm')
                ->join('teams as t', 't.id', '=', 'tm.team_id')
                ->where('tm.user_id', $id)
                ->orderBy('t.name')
                ->pluck('t.name'),
            'assignedClients' => DB::table('agent_clients as ac')
                ->join('clients as c', 'c.id', '=', 'ac.client_id')
                ->where('ac.agent_id', $id)
                ->orderBy('c.name')
                ->pluck('c.name'),
            'devices'         => Device::query()
                ->where('user_id', $id)
                ->orderByRaw('(last_seen IS NULL), last_seen DESC')
                ->get(['name', 'last_seen', 'created_at']),
            'stats'           => [
                'idle_count'        => (int) ($idle->c ?? 0),
                'idle_total_s'      => (int) ($idle->s ?? 0),
                'avg_session_s'     => $activeSeconds ? (int) round(array_sum($activeSeconds) / count($activeSeconds)) : 0,
                'longest_session_s' => $activeSeconds ? max($activeSeconds) : 0,
                'screenshots'       => $sessionIds
                    ? DB::table('screenshots')->whereIn('session_id', $sessionIds)->count()
                    : 0,
                'live'              => WorkSession::query()->where('user_id', $id)->whereNull('ended_at')->exists(),
                'total_active_s'    => (int) WorkSession::query()->where('user_id', $id)->sum('active_s'),
            ],
            'canSeeRates'       => $viewer->hasCapability(Capability::ViewRates),
            'canSeeScreenshots' => $canSeeScreenshots,
        ]);
    }

    /** @return array<int, array{secs: int, cnt: int}> */
    private function timePerTask(int $userId): array
    {
        $totals = [];

        foreach (DB::table('sessions')
            ->selectRaw('task_id, SUM(active_s) AS secs, COUNT(*) AS cnt')
            ->where('user_id', $userId)
            ->whereNotNull('task_id')
            ->where('approval_status', 'approved')
            ->groupBy('task_id')
            ->get() as $row) {
            $totals[(int) $row->task_id] = ['secs' => (int) $row->secs, 'cnt' => (int) $row->cnt];
        }

        return $totals;
    }
}
