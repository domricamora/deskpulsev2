<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\PayAdjustment;
use App\Models\User;
use App\Services\Reporting\SessionStats;
use App\Support\Flash;
use App\Support\Period;
use App\Support\Visibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * `/app/adjustments` — one-off money on top of the pay run.
 *
 * Capability `pay_adjustments`. Every adjustment carries a SIGN taken from its
 * kind, not from the amount: the form only ever accepts a positive number, and
 * `advance` or `deduction` makes it subtract. Letting the amount itself go
 * negative would give two ways to express the same thing and one of them would
 * eventually be entered wrong.
 *
 * Adjustments created here are `approved` immediately — the person entering
 * them already holds the capability to decide pay. The status column exists so
 * an import or a future request flow can land something pending, and the pay
 * run only ever counts approved rows.
 *
 * `effective_date` decides which pay period an adjustment falls into, not the
 * date it was entered.
 *
 * @see docs/migration/payroll.md §1
 */
class AdjustmentController extends Controller
{
    /**
     * Kind ⇒ [label, sign]. Ports adjustment_kinds().
     *
     * @var array<string, array{0: string, 1: int}>
     */
    public const KINDS = [
        'bonus'         => ['Bonus', 1],
        'commission'    => ['Commission', 1],
        'reimbursement' => ['Reimbursement', 1],
        'allowance'     => ['Allowance', 1],
        'incentive'     => ['Incentive', 1],
        'other'         => ['Other earning', 1],
        'deduction'     => ['Deduction', -1],
        'advance'       => ['Advance repayment', -1],
    ];

    public function __construct(
        private readonly Period $period,
        private readonly SessionStats $stats,
    ) {}

    public function show(Request $request)
    {
        $user = $request->user();
        $organizationId = (int) $user->effectiveOrgId();

        $context = $this->period->context($request, $organizationId, 'pay', ['day', 'week', 'pay', 'month']);
        $ids = Visibility::userIds($user);

        $rows = DB::table('pay_adjustments as a')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->leftJoin('users as c', 'c.id', '=', 'a.created_by_id')
            ->whereIn('a.user_id', $ids)
            ->where('a.effective_date', '>=', $context['start_date'])
            ->where('a.effective_date', '<=', $context['end_date'])
            ->orderByDesc('a.effective_date')
            ->orderByDesc('a.id')
            ->get(['a.*', 'u.name as user_name', 'c.name as created_by']);

        return view('dashboard.adjustments', [
            'title'    => 'Pay adjustments',
            'active'   => 'adjustments',
            'period'   => $context,
            'periods'  => Period::options(['day', 'week', 'pay', 'month']),
            'rows'     => $rows,
            'members'  => $this->payableMembers($organizationId, $ids),
            'kinds'    => self::KINDS,
            'currency' => $this->period->organizationConfig($organizationId)['pay_currency'],
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $organizationId = (int) $user->effectiveOrgId();

        match ((string) $request->input('action', '')) {
            'create' => $this->create($request, $organizationId),
            'delete' => $this->delete($request, $organizationId),
            default  => null,
        };

        return redirect('/app/adjustments' . (string) $request->input('back', ''));
    }

    private function create(Request $request, int $organizationId): void
    {
        // Org-scope the target before writing — the standard tenant guard, and
        // the only thing standing between a posted user_id and another
        // company's payroll.
        $target = User::query()
            ->whereKey((int) $request->input('user_id', 0))
            ->where('org_id', $organizationId)
            ->first();

        if (! $target) {
            return;
        }

        $amount = round(abs((float) $request->input('amount', 0)), 2);

        if ($amount <= 0) {
            Flash::error('Enter an amount greater than zero.');

            return;
        }

        $kind = array_key_exists((string) $request->input('kind'), self::KINDS)
            ? (string) $request->input('kind')
            : 'bonus';

        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->input('effective_date'))
            ? (string) $request->input('effective_date')
            : gmdate('Y-m-d');

        PayAdjustment::create([
            'org_id'         => $organizationId,
            'user_id'        => $target->id,
            'kind'           => $kind,
            'label'          => substr(trim((string) $request->input('label', '')) ?: self::KINDS[$kind][0], 0, 160),
            'amount'         => $amount,
            'sign'           => self::KINDS[$kind][1],
            'currency'       => substr((string) ($request->input('currency') ?: ($target->currency ?: 'USD')), 0, 8),
            'taxable'        => $request->boolean('taxable') ? 1 : 0,
            'effective_date' => $date,
            'note'           => substr((string) $request->input('note', ''), 0, 2000),
            'status'         => 'approved',
            'created_by_id'  => $request->user()->id,
        ]);

        Flash::success('Pay adjustment added.');
    }

    private function delete(Request $request, int $organizationId): void
    {
        PayAdjustment::query()
            ->whereKey((int) $request->input('adjustment_id', 0))
            ->where('org_id', $organizationId)
            ->delete();

        Flash::success('Pay adjustment removed.');
    }

    /**
     * People who can receive an adjustment: in scope, and not a client portal.
     *
     * @param  list<int>  $ids
     * @return list<User>
     */
    private function payableMembers(int $organizationId, array $ids): array
    {
        $members = array_values(array_filter(
            $this->stats->usersById($organizationId),
            fn (User $m) => in_array((int) $m->id, $ids, true) && $m->role !== UserRole::ClientViewer
        ));

        usort($members, fn (User $a, User $b) => strcasecmp($a->name, $b->name));

        return $members;
    }
}
