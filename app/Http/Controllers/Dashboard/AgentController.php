<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\Capability;
use App\Http\Controllers\Controller;
use App\Models\AgentClient;
use App\Models\Client;
use App\Models\Task;
use App\Models\WorkSession;
use App\Services\Reporting\SessionStats;
use App\Support\Flash;
use App\Support\Period;
use App\Support\Visibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * `/app/agents` — the roster of people doing the work.
 *
 * Capability `view_agents`. This is also where a **client portal login lands**
 * — `/app` sends `client_viewer` straight here — so it is the first page a
 * customer sees, showing the agents on their own engagement and nobody else's.
 *
 * Two capabilities, two different pages from one route:
 *
 * - `view_agents` opens it, read-only.
 * - `manage_agents` adds the client-assignment control.
 *
 * A client portal holds the first and not the second, so it gets the roster
 * without being able to re-assign anybody.
 *
 * ## Tasks are shown, never edited
 *
 * A task belongs to the person doing the work and is edited only by them, on
 * their own Tasks page. Here they are read-only with time against each, which
 * is what a manager or a customer actually wants: what is being worked on, and
 * how long it took.
 *
 * @see docs/migration/routes.md §5
 */
class AgentController extends Controller
{
    public function __construct(
        private readonly Period $period,
        private readonly SessionStats $stats,
    ) {}

    public function show(Request $request)
    {
        $user = $request->user();
        $organizationId = (int) $user->effectiveOrgId();
        $ids = Visibility::userIds($user);

        $context = $this->period->context($request, $organizationId, 'week', ['week']);

        $weekActive = array_fill_keys($ids, 0);

        foreach ($this->stats->forUsers($ids, $context['start'], $context['end']) as $session) {
            $weekActive[(int) $session->user_id] =
                ($weekActive[(int) $session->user_id] ?? 0) + (int) $session->active_s;
        }

        $live = WorkSession::query()
            ->whereIn('user_id', $ids)
            ->whereNull('ended_at')
            ->distinct()
            ->pluck('user_id')
            ->flip();

        $assignments = [];

        foreach (AgentClient::query()->whereIn('agent_id', $ids)->get() as $assignment) {
            $assignments[(int) $assignment->agent_id][] = (int) $assignment->client_id;
        }

        $tasks = Task::query()
            ->whereIn('user_id', $ids)
            ->orderBy('status')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('user_id');

        $usersById = $this->stats->usersById($organizationId);
        $agents = [];

        foreach ($ids as $id) {
            // A manager is not an agent underneath themselves.
            if ((int) $id === (int) $user->id) {
                continue;
            }

            $person = $usersById[$id] ?? null;

            if (! $person) {
                continue;
            }

            $agents[] = [
                'id'            => (int) $id,
                'name'          => $person->name,
                'role'          => $person->role,
                'email'         => $person->email,
                'job_title'     => $person->job_title ?? '',
                'week_active_s' => $weekActive[$id] ?? 0,
                'live'          => $live->has($id),
                'client_ids'    => $assignments[$id] ?? [],
                'tasks'         => $tasks->get($id) ?? collect(),
            ];
        }

        usort($agents, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return view('dashboard.agents', [
            'title'      => 'Agents',
            'active'     => 'agents',
            'agents'     => $agents,
            'clients'    => Client::query()
                ->where('org_id', $organizationId)
                ->where('archived', 0)
                ->orderBy('name')
                ->get(['id', 'name']),
            'timeByTask' => $this->timePerTask($ids),
            'canManage'  => $user->hasCapability(Capability::ManageAgents),
        ]);
    }

    /** Re-assign which clients an agent works for. */
    public function update(Request $request)
    {
        $user = $request->user();

        // A read-only role that reached the POST anyway gets no further.
        if (! $user->hasCapability(Capability::ManageAgents)) {
            return redirect('/app/agents');
        }

        $agentId = (int) $request->input('agent_id', 0);

        if ($request->input('action') === 'assign_clients'
            && in_array($agentId, Visibility::userIds($user), true)) {
            // Only clients this organization actually owns — a posted id from
            // somewhere else is dropped rather than trusted.
            $valid = Client::query()
                ->where('org_id', (int) $user->effectiveOrgId())
                ->whereIn('id', array_map('intval', (array) $request->input('client_ids', [])))
                ->pluck('id');

            AgentClient::query()->where('agent_id', $agentId)->delete();

            foreach ($valid as $clientId) {
                AgentClient::query()->firstOrCreate(['agent_id' => $agentId, 'client_id' => $clientId]);
            }

            Flash::success('Client assignment updated.');
        }

        return redirect('/app/agents');
    }

    /**
     * Approved time per task — what each piece of work has actually cost.
     *
     * @param  list<int>  $ids
     * @return array<int, array{secs: int, cnt: int}>
     */
    private function timePerTask(array $ids): array
    {
        $totals = [];

        foreach (DB::table('sessions')
            ->selectRaw('task_id, SUM(active_s) AS secs, COUNT(*) AS cnt')
            ->whereNotNull('task_id')
            ->where('approval_status', 'approved')
            ->whereIn('user_id', $ids)
            ->groupBy('task_id')
            ->get() as $row) {
            $totals[(int) $row->task_id] = ['secs' => (int) $row->secs, 'cnt' => (int) $row->cnt];
        }

        return $totals;
    }
}
