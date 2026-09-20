<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Services\Billing\ClientBilling;
use App\Services\Billing\ContractBilling;
use App\Services\Reporting\CsvExport;
use App\Support\Period;
use App\Support\Visibility;
use Illuminate\Http\Request;

/**
 * `/app/billing` and `/app/billing.csv` — what clients are charged.
 *
 * Capability `billing`. A client portal holds it and is locked to its own
 * client record by `Visibility::clientFilter()`, so the same route serves an
 * agency admin the whole book and a customer exactly their own invoice.
 *
 * **Labor cost never appears here.** `client_viewer` holds `billing` but not
 * `view_rates`; this page deals only in `bill_rate`, so there is no cost field
 * to leak in the HTML or the CSV. That separation is the product's margin.
 *
 * @see docs/migration/billing.md
 */
class BillingController extends Controller
{
    public function __construct(
        private readonly Period $period,
        private readonly ClientBilling $billing,
        private readonly ContractBilling $contracts,
        private readonly CsvExport $csv,
    ) {}

    public function show(Request $request)
    {
        $user = $request->user();
        [$context, $billing, $clientFilter] = $this->compute($request);

        // The per-contract split is only meaningful for a portal login looking
        // at its own engagement — for an admin it would be an invented
        // breakdown of somebody else's invoice.
        $contractRows = ($user->role === UserRole::ClientViewer && $clientFilter > 0)
            ? $this->contracts->forClient($user, $context['start'], $context['end'], $clientFilter)
            : [];

        return view('dashboard.billing', [
            'title'            => 'Billing',
            'active'           => 'billing',
            'period'           => $context,
            'periods'          => Period::options(['day', 'week', 'pay', 'month']),
            'byAgent'          => $billing['by_agent'],
            'byClient'         => $billing['by_client'],
            'contractBilling'  => $contractRows,
            'total'            => array_sum(array_column($billing['by_agent'], 'amount')),
            // The composer binds nav variables to the LAYOUT, not to this
            // view's section body, so what the body branches on is passed here.
            'isSuper'          => $user->isSuperAdmin(),
        ]);
    }

    public function export(Request $request)
    {
        [$context, $billing] = $this->compute($request);

        $rows = [['Billing by agent — period: ' . $context['period']],
            ['Agent', 'Active hours', 'Bill rate/hr', 'Amount', 'Currency']];

        foreach ($billing['by_agent'] as $agent) {
            $rows[] = [$agent['name'], round($agent['secs'] / 3600, 2), $agent['rate'],
                round($agent['amount'], 2), $agent['currency']];
        }

        $rows[] = [];
        $rows[] = ['Billing by customer'];
        $rows[] = ['Customer', 'Active hours', 'Amount'];

        foreach ($billing['by_client'] as $client) {
            $rows[] = [$client['name'], round($client['secs'] / 3600, 2), round($client['amount'], 2)];
        }

        return $this->csv->rows("deskpulse-billing-{$context['period']}.csv", $rows);
    }

    /**
     * Resolved window, figures and filter — shared so the page and the file can
     * never disagree about what they are showing.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: int}
     */
    private function compute(Request $request): array
    {
        $user = $request->user();

        $context = $this->period->context(
            $request,
            (int) $user->effectiveOrgId(),
            'month',
            ['day', 'week', 'pay', 'month']
        );

        $clientFilter = Visibility::clientFilter($user);

        return [
            $context,
            $this->billing->compute($user, $context['start'], $context['end'], $clientFilter),
            (int) $clientFilter,
        ];
    }
}
