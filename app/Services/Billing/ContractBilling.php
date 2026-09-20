<?php

namespace App\Services\Billing;

use App\Models\Contract;
use App\Models\User;

/**
 * A client portal's own billing, split across its engagements.
 *
 * Ports compute_contract_billing(). This is **not** a second calculation: it
 * takes the figures {@see ClientBilling} already produced for this client and
 * divides them between the client's contracts, so the rows always sum back to
 * the total shown above them.
 *
 * ## Why it is a proration and not a lookup
 *
 * Sessions are tagged with a CLIENT, not a contract — there is no session ⇄
 * contract link in the schema. So a client's hours for the period are split in
 * proportion to how many days each contract overlaps that period. A contract
 * with no dates set is treated as in effect for the whole window.
 *
 * When nothing overlaps at all — every contract's dates fall outside the period
 * — it falls back to an even split rather than dropping the billing silently.
 *
 * Only ever shown to a client portal looking at its own engagement, never to an
 * admin viewing the whole organization's billing: for them the per-contract
 * split would be a made-up breakdown of somebody else's invoice.
 *
 * @see docs/migration/billing.md §2
 */
class ContractBilling
{
    public function __construct(private readonly ClientBilling $billing) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function forClient(User $viewer, string $startUtc, string $endUtc, int $clientId): array
    {
        if ($clientId <= 0) {
            return [];
        }

        $contracts = Contract::query()
            ->where('org_id', (int) $viewer->effectiveOrgId())
            ->where('client_id', $clientId)
            ->orderBy('start_date')
            ->orderBy('created_at')
            ->get();

        if ($contracts->isEmpty()) {
            return [];
        }

        $totals = $this->billing->compute($viewer, $startUtc, $endUtc, $clientId)['by_client'];

        $totalSeconds = (int) array_sum(array_column($totals, 'secs'));
        $totalAmount = (float) array_sum(array_column($totals, 'amount'));

        $weights = $this->overlapWeights($contracts, $startUtc, $endUtc);
        $weightSum = array_sum($weights);

        if ($weightSum <= 0) {
            $weights = array_fill_keys(array_keys($weights), 1);
            $weightSum = count($weights);
        }

        return $contracts->map(function (Contract $contract) use ($weights, $weightSum, $totalSeconds, $totalAmount) {
            $share = $weights[$contract->id] / $weightSum;

            return [
                'id'         => $contract->id,
                'title'      => $contract->title,
                'status'     => $contract->status,
                'start_date' => $contract->start_date,
                'end_date'   => $contract->end_date,
                'currency'   => $contract->currency,
                'secs'       => (int) round($totalSeconds * $share),
                'amount'     => $totalAmount * $share,
            ];
        })->all();
    }

    /**
     * Days of overlap between each contract and the period.
     *
     * @return array<int, float>
     */
    private function overlapWeights($contracts, string $startUtc, string $endUtc): array
    {
        $periodStart = strtotime($startUtc . ' UTC');
        $periodEnd = strtotime($endUtc . ' UTC');

        $weights = [];

        foreach ($contracts as $contract) {
            // No dates set means "in effect for this whole period".
            $from = $contract->start_date
                ? strtotime($contract->getRawOriginal('start_date') . ' 00:00:00 UTC')
                : $periodStart;
            $to = $contract->end_date
                ? strtotime($contract->getRawOriginal('end_date') . ' 23:59:59 UTC')
                : $periodEnd;

            $overlapStart = max($from, $periodStart);
            $overlapEnd = min($to, $periodEnd);

            $weights[$contract->id] = $overlapEnd > $overlapStart
                ? ($overlapEnd - $overlapStart) / 86400
                : 0;
        }

        return $weights;
    }
}
