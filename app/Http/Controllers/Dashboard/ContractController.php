<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractMember;
use App\Models\User;
use App\Services\Reporting\SessionStats;
use App\Support\Flash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * `/app/contracts` — who is staffed on which engagement.
 *
 * Capability `contracts_manage`, which is deliberately NARROWER than
 * `clients_manage`: HR holds it and can staff a contract without being able to
 * create clients or provision portal logins. The contracts themselves are
 * created and edited on the Clients page, nested under the client they belong
 * to; this page only assigns people to them.
 *
 * Ended contracts sort last rather than disappearing — a finished engagement
 * is still the answer to "who worked on that".
 *
 * @see docs/migration/routes.md §5
 */
class ContractController extends Controller
{
    public function __construct(private readonly SessionStats $stats) {}

    public function show(Request $request)
    {
        $organizationId = (int) $request->user()->effectiveOrgId();

        $membersByContract = [];

        foreach (DB::table('contract_members as cm')
            ->join('contracts as c', 'c.id', '=', 'cm.contract_id')
            ->join('users as us', 'us.id', '=', 'cm.user_id')
            ->where('c.org_id', $organizationId)
            ->orderBy('us.name')
            ->get(['cm.contract_id', 'cm.user_id', 'us.name', 'us.email']) as $row) {
            $membersByContract[(int) $row->contract_id][] = $row;
        }

        return view('dashboard.contracts', [
            'title'             => 'Contracts',
            'active'            => 'contracts',
            'contracts'         => Contract::query()
                ->join('clients', 'clients.id', '=', 'contracts.client_id')
                ->where('contracts.org_id', $organizationId)
                // Ended last, then by client, then by title.
                ->orderByRaw('(contracts.status = "ended")')
                ->orderBy('clients.name')
                ->orderBy('contracts.title')
                ->get(['contracts.*', 'clients.name as client_name']),
            'membersByContract' => $membersByContract,
            'people'            => $this->stats->usersById($organizationId),
        ]);
    }

    public function update(Request $request)
    {
        $organizationId = (int) $request->user()->effectiveOrgId();

        $contractId = (int) $request->input('contract_id', 0);
        $userId = (int) $request->input('user_id', 0);

        // Both sides org-scoped before anything is written: a posted id is not
        // evidence that either belongs to this tenant.
        $contract = Contract::query()
            ->whereKey($contractId)
            ->where('org_id', $organizationId)
            ->first();

        if (! $contract) {
            return redirect('/app/contracts');
        }

        match ((string) $request->input('action', '')) {
            'assign_member' => $this->assign($contract->id, $userId, $organizationId),
            'unassign_member' => $this->unassign($contract->id, $userId),
            default => null,
        };

        return redirect('/app/contracts');
    }

    private function assign(int $contractId, int $userId, int $organizationId): void
    {
        $exists = User::query()
            ->whereKey($userId)
            ->where('org_id', $organizationId)
            ->exists();

        if (! $exists) {
            return;
        }

        ContractMember::query()->firstOrCreate(['contract_id' => $contractId, 'user_id' => $userId]);

        Flash::success('Member assigned to the contract.');
    }

    private function unassign(int $contractId, int $userId): void
    {
        ContractMember::query()
            ->where('contract_id', $contractId)
            ->where('user_id', $userId)
            ->delete();

        Flash::success('Member removed from the contract.');
    }
}
