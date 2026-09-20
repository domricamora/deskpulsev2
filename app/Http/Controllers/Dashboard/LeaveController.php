<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\LeaveEntitlement;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Payroll\LeaveLedger;
use App\Services\Reporting\SessionStats;
use App\Support\Flash;
use App\Support\Visibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/app/leave` — time off.
 *
 * Staff only: a client portal login is a customer, not an employee.
 *
 * ## Requesting needs no capability
 *
 * Anyone may request leave for themselves. `leave_approve` is what lets you
 * decide somebody else's — and an approver filing directly is trusted, so
 * their own entry lands `approved` while a self-request lands `pending`. Same
 * asymmetry as the timesheet queue, for the same reason.
 *
 * ## Balances are derived
 *
 * Entitlement minus approved days used, recomputed every time. There is no
 * balance column and there must not be one — a stored balance drifts away from
 * the requests behind it and then nobody can say which is right.
 *
 * @see docs/migration/payroll.md §2
 */
class LeaveController extends Controller
{
    /** The four types every organization starts with. Ports ensure_leave_types(). */
    private const SEED_TYPES = [
        ['Vacation', 'vacation', 1, 15],
        ['Sick leave', 'sick', 1, 10],
        ['Public holiday', 'holiday', 1, 0],
        ['Unpaid leave', 'unpaid', 0, 0],
    ];

    public function __construct(
        private readonly LeaveLedger $ledger,
        private readonly SessionStats $stats,
    ) {}

    public function show(Request $request)
    {
        $user = $request->user();

        abort_if($user->role === UserRole::ClientViewer, Response::HTTP_FORBIDDEN, 'Not allowed.');

        $organizationId = (int) $user->effectiveOrgId();
        $this->seedTypes($organizationId);

        $canApprove = $user->hasCapability(Capability::LeaveApprove);

        // Without the capability you see only your own requests.
        $ids = $canApprove ? Visibility::userIds($user) : [(int) $user->id];

        $year = (int) gmdate('Y');
        $entitlements = $this->entitlementDays((int) $user->id, $organizationId, $year);
        $used = $this->ledger->usedDaysByType($user, $year);

        $balances = [];

        foreach (LeaveType::query()->where('org_id', $organizationId)->where('active', 1)->orderBy('name')->get() as $type) {
            $entitled = $entitlements[$type->id] ?? 0.0;
            $taken = $used[$type->id] ?? 0.0;

            $balances[] = ['type' => $type, 'entitled' => $entitled, 'used' => $taken, 'left' => $entitled - $taken];
        }

        return view('dashboard.leave', [
            'title'      => 'Time off',
            'active'     => 'leave',
            'types'      => LeaveType::query()->where('org_id', $organizationId)->orderBy('name')->get(),
            'requests'   => $this->requests($ids),
            'balances'   => $balances,
            'members'    => $this->staff($organizationId, $ids),
            'canApprove' => $canApprove,
            'meId'       => (int) $user->id,
            'year'       => $year,
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        abort_if($user->role === UserRole::ClientViewer, Response::HTTP_FORBIDDEN, 'Not allowed.');

        $organizationId = (int) $user->effectiveOrgId();
        $canApprove = $user->hasCapability(Capability::LeaveApprove);

        match ((string) $request->input('action', '')) {
            'request'           => $this->request($request, $organizationId, $canApprove),
            'review'            => $canApprove ? $this->review($request, $organizationId) : null,
            'cancel'            => $this->cancel($request, $organizationId, $canApprove),
            'save_type'         => $canApprove ? $this->saveType($request, $organizationId) : null,
            'save_entitlement'  => $canApprove ? $this->saveEntitlement($request, $organizationId) : null,
            default             => null,
        };

        return redirect('/app/leave');
    }

    /* ── Actions ─────────────────────────────────────────────────────────── */

    private function request(Request $request, int $organizationId, bool $canApprove): void
    {
        $user = $request->user();
        $forId = (int) $request->input('user_id', $user->id);

        // Filing for somebody else is the approver's privilege.
        abort_if($forId !== (int) $user->id && ! $canApprove, Response::HTTP_FORBIDDEN, 'Not allowed');

        $target = User::query()->whereKey($forId)->where('org_id', $organizationId)->first();
        $type = LeaveType::query()
            ->whereKey((int) $request->input('leave_type_id', 0))
            ->where('org_id', $organizationId)
            ->first();

        $from = (string) $request->input('start_date', '');
        $to = (string) $request->input('end_date', '');

        $valid = $target && $type
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to);

        if (! $valid) {
            Flash::error('Pick a leave type and a valid date range.');

            return;
        }

        if (strcmp($from, $to) > 0) {
            [$from, $to] = [$to, $from];        // tolerate a reversed range
        }

        $halfDay = $request->boolean('half_day') && $from === $to;
        $hoursPerDay = max(0.5, min(24.0, (float) $request->input('hours_per_day', 8)));
        $days = $this->ledger->workingDays($target, $from, $to, $halfDay);

        if ($days <= 0) {
            Flash::error('That range contains no scheduled working days for this member.');

            return;
        }

        LeaveRequest::create([
            'org_id'         => $organizationId,
            'user_id'        => $target->id,
            'leave_type_id'  => $type->id,
            'start_date'     => $from,
            'end_date'       => $to,
            'hours_per_day'  => $hoursPerDay,
            'total_days'     => $days,
            'total_hours'    => $days * $hoursPerDay,
            'half_day'       => $halfDay ? 1 : 0,
            'reason'         => substr((string) $request->input('reason', ''), 0, 2000),
            'status'         => $canApprove ? 'approved' : 'pending',
            'reviewed_by_id' => $canApprove ? $user->id : null,
            'reviewed_at'    => $canApprove ? gmdate('Y-m-d H:i:s') : null,
        ]);

        Flash::success($canApprove ? 'Leave recorded.' : 'Leave request submitted for approval.');
    }

    private function review(Request $request, int $organizationId): void
    {
        $leaveRequest = LeaveRequest::query()
            ->whereKey((int) $request->input('request_id', 0))
            ->where('org_id', $organizationId)
            ->first();

        if (! $leaveRequest) {
            return;
        }

        $decision = $request->input('decision') === 'approve' ? 'approved' : 'rejected';

        $leaveRequest->forceFill([
            'status'         => $decision,
            'reviewed_by_id' => $request->user()->id,
            'reviewed_at'    => gmdate('Y-m-d H:i:s'),
            'review_note'    => substr((string) $request->input('review_note', ''), 0, 2000),
        ])->save();

        Flash::success("Leave request {$decision}.");
    }

    private function cancel(Request $request, int $organizationId, bool $canApprove): void
    {
        $leaveRequest = LeaveRequest::query()
            ->whereKey((int) $request->input('request_id', 0))
            ->where('org_id', $organizationId)
            ->first();

        // A member may withdraw their own; an approver may withdraw anyone's.
        if (! $leaveRequest || ((int) $leaveRequest->user_id !== (int) $request->user()->id && ! $canApprove)) {
            return;
        }

        $leaveRequest->forceFill(['status' => 'cancelled'])->save();

        Flash::success('Leave request cancelled.');
    }

    private function saveType(Request $request, int $organizationId): void
    {
        $name = substr(trim((string) $request->input('name', '')), 0, 80);
        $code = strtolower(preg_replace('/[^a-z0-9_]+/i', '_', substr(trim((string) $request->input('code', '')), 0, 24)));

        if (! $name || ! $code) {
            return;
        }

        $id = (int) $request->input('type_id', 0);
        $existing = $id
            ? LeaveType::query()->whereKey($id)->where('org_id', $organizationId)->first()
            : null;

        if ($existing) {
            $existing->forceFill([
                'name'          => $name,
                'paid'          => $request->boolean('paid') ? 1 : 0,
                'days_per_year' => (float) $request->input('days_per_year', 0),
                'active'        => $request->boolean('active') ? 1 : 0,
            ])->save();
        } else {
            LeaveType::create([
                'org_id'        => $organizationId,
                'name'          => $name,
                'code'          => $code,
                'paid'          => $request->boolean('paid') ? 1 : 0,
                'days_per_year' => (float) $request->input('days_per_year', 0),
            ]);
        }

        Flash::success('Leave type saved.');
    }

    private function saveEntitlement(Request $request, int $organizationId): void
    {
        $target = User::query()
            ->whereKey((int) $request->input('user_id', 0))
            ->where('org_id', $organizationId)
            ->first();

        $type = LeaveType::query()
            ->whereKey((int) $request->input('leave_type_id', 0))
            ->where('org_id', $organizationId)
            ->first();

        if (! $target || ! $type) {
            return;
        }

        LeaveEntitlement::query()->updateOrCreate(
            [
                'org_id'        => $organizationId,
                'user_id'       => $target->id,
                'leave_type_id' => $type->id,
                'year'          => max(2000, min(2100, (int) $request->input('year', gmdate('Y')))),
            ],
            [
                'days'         => (float) $request->input('days', 0),
                'carried_days' => (float) $request->input('carried_days', 0),
            ]
        );

        Flash::success('Entitlement saved.');
    }

    /* ── Reads ───────────────────────────────────────────────────────────── */

    /** Pending first, then by start date — the queue order somebody works in. */
    private function requests(array $ids)
    {
        if (! $ids) {
            return collect();
        }

        return DB::table('leave_requests as lr')
            ->join('leave_types as lt', 'lt.id', '=', 'lr.leave_type_id')
            ->join('users as us', 'us.id', '=', 'lr.user_id')
            ->leftJoin('users as rv', 'rv.id', '=', 'lr.reviewed_by_id')
            ->whereIn('lr.user_id', $ids)
            ->orderByRaw("FIELD(lr.status,'pending','approved','rejected','cancelled')")
            ->orderByDesc('lr.start_date')
            ->limit(300)
            ->get(['lr.*', 'lt.name as type_name', 'lt.paid', 'us.name as user_name', 'rv.name as reviewer']);
    }

    /**
     * Entitlement plus anything carried over, per type.
     *
     * @return array<int, float>
     */
    private function entitlementDays(int $userId, int $organizationId, int $year): array
    {
        return LeaveEntitlement::query()
            ->where('org_id', $organizationId)
            ->where('user_id', $userId)
            ->where('year', $year)
            ->get()
            ->mapWithKeys(fn ($row) => [
                (int) $row->leave_type_id => (float) $row->days + (float) $row->carried_days,
            ])
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @return list<User>
     */
    private function staff(int $organizationId, array $ids): array
    {
        $members = array_values(array_filter(
            $this->stats->usersById($organizationId),
            fn (User $m) => in_array((int) $m->id, $ids, true) && $m->role !== UserRole::ClientViewer
        ));

        usort($members, fn (User $a, User $b) => strcasecmp($a->name, $b->name));

        return $members;
    }

    /** Seed the standard types the first time an organization opens the page. */
    private function seedTypes(int $organizationId): void
    {
        if (LeaveType::query()->where('org_id', $organizationId)->exists()) {
            return;
        }

        foreach (self::SEED_TYPES as [$name, $code, $paid, $daysPerYear]) {
            LeaveType::create([
                'org_id'        => $organizationId,
                'name'          => $name,
                'code'          => $code,
                'paid'          => $paid,
                'days_per_year' => $daysPerYear,
            ]);
        }
    }
}
