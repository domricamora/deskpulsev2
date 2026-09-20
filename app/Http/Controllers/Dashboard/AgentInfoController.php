<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\Capability;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\Reporting\SessionStats;
use App\Support\Format;
use App\Support\Period;
use App\Support\Visibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * `/app/agent/{id}` — the JSON behind the roster modal.
 *
 * Note the singular: this is NOT `/app/agents/{id}`, which is a full page.
 * `resources/js/agent-modal.js` fetches this when somebody clicks a row on the
 * overview or the team page.
 *
 * ## Two gates, both needed
 *
 * The caller must hold `view_all` or `view_team` — a member has neither and
 * has no roster to click — AND the person asked about must be inside their
 * visible scope. Capability alone would let a team manager read anybody in the
 * organization by changing the id in the URL.
 *
 * Rates are added only for `view_rates`, server-side. A viewer without it does
 * not receive the fields at all, rather than receiving them and having the
 * script decline to draw them.
 *
 * @see docs/migration/routes.md §5
 */
class AgentInfoController extends Controller
{
    public function __construct(
        private readonly Period $period,
        private readonly SessionStats $stats,
    ) {}

    public function show(Request $request, int $id): JsonResponse
    {
        $viewer = $request->user();

        abort_unless(
            $viewer->hasCapability(Capability::ViewAll) || $viewer->hasCapability(Capability::ViewTeam),
            403,
            'Not allowed.'
        );

        abort_unless(in_array($id, Visibility::userIds($viewer), true), 403, 'Out of scope.');

        $person = User::query()->whereKey($id)->first();

        abort_if($person === null, 404, 'user not found');

        $organizationId = (int) $viewer->effectiveOrgId();
        $config = $this->period->organizationConfig($organizationId);
        $today = (new \DateTimeImmutable('now', new \DateTimeZone($config['tz'])))->format('Y-m-d');

        [$dayStart, $dayEnd] = $this->period->bounds('day', $today, $config);
        [$weekStart, $weekEnd] = $this->period->bounds('week', $today, $config);

        // approvedOnly false: the modal answers "what has this person been
        // doing", which includes time still waiting on a manager.
        $day = $this->stats->summarize($this->stats->forUsers([$id], $dayStart, $dayEnd, false));
        $week = $this->stats->summarize($this->stats->forUsers([$id], $weekStart, $weekEnd, false));

        $payload = [
            'id'        => $id,
            'name'      => $person->name,
            'email'     => $person->email,
            'phone'     => $person->phone ?: '—',
            'job_title' => $person->job_title ?: '—',
            'role'      => $person->role?->label(),
            'org'       => $person->organization?->name,
            'teams'     => DB::table('team_members as tm')
                ->join('teams as t', 't.id', '=', 'tm.team_id')
                ->where('tm.user_id', $id)
                ->orderBy('t.name')
                ->pluck('t.name'),
            'clients'   => DB::table('sessions as s')
                ->join('clients as c', 'c.id', '=', 's.client_id')
                ->where('s.user_id', $id)
                ->distinct()
                ->orderBy('c.name')
                ->pluck('c.name'),
            'devices'   => Device::query()
                ->where('user_id', $id)
                ->orderByDesc('created_at')
                ->get(['id', 'name', 'created_at']),
            'today'     => ['active' => Format::hms($day['active_s']), 'pct' => $day['activity_pct']],
            'week'      => [
                'active'   => Format::hms($week['active_s']),
                'pct'      => $week['activity_pct'],
                'sessions' => $week['count'],
            ],
            'total_active' => Format::hms(
                (int) WorkSession::query()->where('user_id', $id)->sum('active_s')
            ),
            'open_tasks'   => Task::query()->where('user_id', $id)->where('status', 'open')->count(),
            'live'         => WorkSession::query()->where('user_id', $id)->whereNull('ended_at')->exists(),
            'last_seen'    => WorkSession::query()
                ->where('user_id', $id)
                ->orderByDesc('started_at')
                ->value('started_at'),
        ];

        // Money is added here or not at all — never sent and hidden.
        if ($viewer->hasCapability(Capability::ViewRates)) {
            $payload['pay'] = Format::money($person->pay_rate, $person->currency)
                . ' / ' . ($person->pay_type ?: 'hourly');
            $payload['bill'] = Format::money($person->bill_rate, $person->currency)
                . ' / ' . ($person->bill_type ?: 'hourly');
        }

        return response()->json($payload);
    }
}
