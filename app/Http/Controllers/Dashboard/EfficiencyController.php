<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Services\Reporting\CsvExport;
use App\Services\Reporting\Efficiency;
use App\Support\Period;
use Illuminate\Http\Request;

/**
 * `/app/reports/efficiency` and its CSV.
 *
 * This is the only `/app/reports/*` route that exists. The migration plan §51
 * describes a bare `/app/reports`; there has never been one.
 *
 * A client portal is turned away even though `client_viewer` holds `reports`.
 * The capability buys them their own billing and time figures, not an internal
 * performance ranking of the people working their account — so the redirect is
 * the second layer of a two-layer guard, not a redundancy.
 *
 * @see docs/migration/reports.md §6
 */
class EfficiencyController extends Controller
{
    public function __construct(
        private readonly Period $period,
        private readonly Efficiency $efficiency,
        private readonly CsvExport $csv,
    ) {}

    public function show(Request $request)
    {
        if ($request->user()->role === UserRole::ClientViewer) {
            return redirect('/app/overview');
        }

        [$context, $rows] = $this->report($request);

        return view('dashboard.efficiency', [
            'title'       => 'Efficiency report',
            'active'      => 'efficiency',
            'period'      => $context,
            'periods'     => Period::options(['week', 'pay', 'month']),
            'rows'        => $rows,
            'totals'      => $this->efficiency->totals($rows),
            'canSeeRates' => $request->user()->hasCapability(Capability::ViewRates),
        ]);
    }

    public function export(Request $request)
    {
        if ($request->user()->role === UserRole::ClientViewer) {
            return redirect('/app/overview');
        }

        [$context, $rows] = $this->report($request);

        return $this->csv->efficiency(
            $rows,
            $context['period'],
            "deskpulse-efficiency-{$context['period']}.csv"
        );
    }

    /**
     * The window and its rows — resolved identically for the page and the file,
     * so a CSV can never disagree with the table it was downloaded from.
     *
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    private function report(Request $request): array
    {
        $context = $this->period->context(
            $request,
            (int) $request->user()->effectiveOrgId(),
            'month',
            ['week', 'pay', 'month']
        );

        return [$context, $this->efficiency->rows($request->user(), $context['start'], $context['end'])];
    }
}
