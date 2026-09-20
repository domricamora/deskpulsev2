<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\WorkSession;
use App\Services\Reporting\SessionStats;
use App\Support\Flash;
use App\Support\Visibility;
use Illuminate\Http\Request;

/**
 * The two review queues: `/app/approvals` and `/app/overtime`.
 *
 * They look alike and are deliberately kept apart, because they are held by
 * different people. `approve_time` decides whether a manual entry counts at
 * all; `approve_overtime` decides whether the overtime portion of a session
 * gets paid. A team manager holds the first and not the second — they can
 * accept that someone worked, without being able to authorise the premium.
 *
 * Both queues are scoped: a manager reviews their own team, never the
 * organization's whole backlog.
 *
 * ## Why unapproved overtime still shows on the timesheet
 *
 * Nothing here deletes or hides time. A rejected entry stays visible to the
 * person who filed it, with its status — the queue decides what counts, not
 * what happened. Payroll reads `creditableActiveSeconds()`, which is where an
 * unapproved overtime portion actually drops out.
 *
 * @see docs/migration/reports.md
 */
class ApprovalController extends Controller
{
    public function __construct(private readonly SessionStats $stats) {}

    /** `GET /app/approvals` — manual entries waiting on a manager. */
    public function time(Request $request)
    {
        $pending = $this->queue($request, 'approval_status');

        return view('dashboard.approvals', [
            'title'       => 'Approvals',
            'active'      => 'approvals',
            'pending'     => $pending,
            'usersById'   => $this->stats->usersById((int) $request->user()->effectiveOrgId()),
            'clientNames' => $this->clientNames($pending),
        ]);
    }

    /** `POST /app/approvals/{id}` */
    public function decideTime(Request $request, int $id)
    {
        $session = $this->ownedSession($request, $id);
        $decision = $this->decision($request);

        $session->forceFill([
            'approval_status' => $decision,
            'reviewed_by_id'  => $request->user()->id,
            'reviewed_at'     => gmdate('Y-m-d H:i:s'),
            'review_note'     => substr((string) $request->input('review_note', ''), 0, 1000),
        ])->save();

        Flash::success("Entry {$decision}.");

        return redirect('/app/approvals');
    }

    /** `GET /app/overtime` — overtime portions waiting on HR. */
    public function overtime(Request $request)
    {
        $pending = $this->queue($request, 'overtime_status');

        return view('dashboard.overtime', [
            'title'       => 'Overtime',
            'active'      => 'overtime',
            'pending'     => $pending,
            'usersById'   => $this->stats->usersById((int) $request->user()->effectiveOrgId()),
            'clientNames' => $this->clientNames($pending),
        ]);
    }

    /** `POST /app/overtime/{id}` */
    public function decideOvertime(Request $request, int $id)
    {
        $session = $this->ownedSession($request, $id);
        $decision = $this->decision($request);

        $session->forceFill([
            'overtime_status'         => $decision,
            'overtime_reviewed_by_id' => $request->user()->id,
            'overtime_reviewed_at'    => gmdate('Y-m-d H:i:s'),
            'overtime_review_note'    => substr((string) $request->input('review_note', ''), 0, 1000),
        ])->save();

        Flash::success("Overtime {$decision}.");

        return redirect('/app/overtime');
    }

    /* ── Shared ──────────────────────────────────────────────────────────── */

    /** Pending rows in this reviewer's scope, newest first. */
    private function queue(Request $request, string $column)
    {
        return WorkSession::query()
            ->whereIn('user_id', Visibility::userIds($request->user()))
            ->where($column, 'pending')
            ->orderByDesc('started_at')
            ->get();
    }

    /**
     * The session being decided on, or a 404.
     *
     * Scope is re-checked here rather than trusted from the listing: the id
     * arrives in the URL, and a reviewer must not be able to act on a row
     * outside their team by typing one.
     */
    private function ownedSession(Request $request, int $id): WorkSession
    {
        $session = WorkSession::query()->whereKey($id)->first();

        abort_if($session === null, 404, 'Entry not found');
        abort_unless(
            in_array((int) $session->user_id, Visibility::userIds($request->user()), true),
            404,
            'Entry not found'
        );

        return $session;
    }

    /** Anything that is not an explicit approval is a rejection. */
    private function decision(Request $request): string
    {
        return $request->input('decision') === 'approve' ? 'approved' : 'rejected';
    }

    /**
     * Client names for the queue, in one lookup rather than one per row —
     * the legacy's `client_name()` is a per-call query behind a static cache.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function clientNames($sessions)
    {
        return Client::query()
            ->whereIn('id', $sessions->pluck('client_id')->filter()->unique()->all())
            ->pluck('name', 'id');
    }
}
