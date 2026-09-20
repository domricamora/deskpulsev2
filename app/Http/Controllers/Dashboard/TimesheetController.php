<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Task;
use App\Models\WorkSession;
use App\Services\Reporting\CsvExport;
use App\Services\Reporting\SessionStats;
use App\Services\Reporting\Overtime;
use App\Support\Flash;
use App\Support\Period;
use App\Support\Visibility;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/app/timesheets` — every tracked and hand-entered slice of time.
 *
 * Login only; what you see is narrowed by `Visibility::userIds()`. A client
 * portal additionally sees only time logged against its own engagement, not the
 * agent's work for other customers.
 *
 * ## Manual entries and who they belong to
 *
 * A manager files an entry for anyone in their scope and it is `approved` on
 * the spot. A member files only for themselves and it lands `pending`, waiting
 * on `/app/approvals`. That asymmetry is the whole point of the approval queue:
 * the person whose hours they are cannot wave them through.
 *
 * `sessions_for_users($ids, …, false)` — all statuses — is deliberate here.
 * Timesheets is the one page that shows pending and rejected rows, because it
 * is where you go to see what happened to an entry you filed.
 *
 * @see docs/migration/reports.md
 */
class TimesheetController extends Controller
{
    public function __construct(
        private readonly Period $period,
        private readonly SessionStats $stats,
        private readonly Overtime $overtime,
        private readonly CsvExport $csv,
    ) {}

    public function show(Request $request)
    {
        $user = $request->user();
        $organizationId = (int) $user->effectiveOrgId();

        $context = $this->period->context($request, $organizationId, 'week', ['day', 'week', 'pay', 'month']);
        $sessions = $this->sessions($request, $context);

        return view('dashboard.timesheets', [
            'title'     => 'Timesheets',
            'active'    => 'timesheets',
            'period'    => $context,
            'periods'   => Period::options(['day', 'week', 'pay', 'month']),
            'sessions'  => $sessions,
            'usersById' => $this->stats->usersById($organizationId),
            // One lookup each rather than the legacy's per-row client_name()
            // and task_name(), which are per-call queries behind a static.
            'clientNames' => Client::query()
                ->whereIn('id', $sessions->pluck('client_id')->filter()->unique()->all())
                ->pluck('name', 'id'),
            'taskNames' => Task::query()
                ->whereIn('id', $sessions->pluck('task_id')->filter()->unique()->all())
                ->pluck('title', 'id'),
            'clients'   => Client::query()
                ->where('org_id', $organizationId)
                ->where('archived', 0)
                ->orderBy('name')
                ->get(['id', 'name']),
            // A client portal login is a customer looking at what they are
            // billed for. They do not file time.
            'canLog'    => $user->role !== UserRole::ClientViewer,
            'canSeeRates' => $user->hasCapability(Capability::ViewRates),
        ]);
    }

    /** `GET /app/export.csv` — the same scope and window, as a file. */
    public function export(Request $request)
    {
        $user = $request->user();
        $context = $this->period->context(
            $request,
            (int) $user->effectiveOrgId(),
            'week',
            ['day', 'week', 'pay', 'month']
        );

        return $this->csv->sessions(
            $this->sessions($request, $context),
            $this->stats->usersById((int) $user->effectiveOrgId()),
            // The period and its anchor, so this morning's third export does
            // not overwrite the first two.
            "deskpulse-{$context['period']}-{$context['anchor']}.csv"
        );
    }

    /**
     * File one or more manual entries.
     *
     * The form posts parallel arrays, one set per "Add more" row. Each row
     * carries both a UTC value computed by the browser and the raw local
     * strings; the UTC one wins, and the local one is the fallback for a
     * browser where the script did not run.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        abort_if($user->role === UserRole::ClientViewer, Response::HTTP_FORBIDDEN, 'Read-only access.');

        $isManager = $user->hasCapability(Capability::ViewAll)
            || $user->hasCapability(Capability::ViewTeam);

        // A member can only ever file for themselves, whatever the form says.
        $targetId = $isManager ? (int) $request->input('user_id', $user->id) : (int) $user->id;

        if ($isManager && ! in_array($targetId, Visibility::userIds($user), true)) {
            $targetId = (int) $user->id;
        }

        $startsUtc = (array) $request->input('started_at_utc', []);
        $endsUtc = (array) $request->input('ended_at_utc', []);
        $startsLocal = (array) $request->input('started_at', []);
        $endsLocal = (array) $request->input('ended_at', []);
        $clientIds = (array) $request->input('client_id', []);
        $notes = (array) $request->input('note', []);

        $viewerTimezone = Period::viewerTimezone($request);
        $created = 0;
        $skipped = 0;

        for ($i = 0, $rows = max(count($startsUtc), count($startsLocal)); $i < $rows; $i++) {
            if (empty($startsLocal[$i]) && empty($startsUtc[$i])) {
                continue;                       // a blank "Add more" row
            }

            $startUtc = Period::submittedToUtc($startsUtc[$i] ?? $startsLocal[$i] ?? '', $viewerTimezone);
            $endUtc = Period::submittedToUtc($endsUtc[$i] ?? $endsLocal[$i] ?? '', $viewerTimezone);

            $activeSeconds = max(0, strtotime($endUtc . ' UTC') - strtotime($startUtc . ' UTC'));

            if ($activeSeconds <= 0) {
                $skipped++;                     // end not after start
                continue;
            }

            WorkSession::create([
                'user_id'         => $targetId,
                'client_id'       => ($clientIds[$i] ?? null) ?: null,
                'started_at'      => $startUtc,
                'ended_at'        => $endUtc,
                'active_s'        => $activeSeconds,
                'inactive_s'      => 0,
                'source'          => 'manual',
                'note'            => substr((string) ($notes[$i] ?? ''), 0, 1000),
                'approval_status' => $isManager ? 'approved' : 'pending',
            ]);

            $created++;
        }

        if ($created > 0) {
            // Split the new entries into regular and overtime straight away —
            // the sidebar badge counts the result.
            $this->overtime->recompute($targetId);

            Flash::success(
                $created . ($created === 1 ? ' entry' : ' entries')
                . ($isManager ? ' added.' : ' submitted for approval.')
                . ($skipped ? " ({$skipped} skipped — end not after start.)" : '')
            );
        } else {
            Flash::error('No entries added — check that each end time is after its start.');
        }

        return redirect('/app/timesheets');
    }

    /**
     * The window's sessions for this viewer, in every approval state.
     *
     * @param  array<string, mixed>  $context
     */
    private function sessions(Request $request, array $context)
    {
        $user = $request->user();

        $sessions = $this->stats->forUsers(
            Visibility::userIds($user),
            $context['start'],
            $context['end'],
            approvedOnly: false
        );

        $clientFilter = Visibility::clientFilter($user);

        if ($clientFilter !== null) {
            $sessions = $sessions
                ->filter(fn (WorkSession $session) => (int) $session->client_id === $clientFilter)
                ->values();
        }

        return $sessions;
    }
}
