<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Payroll\PayRun;
use App\Services\Payroll\WisePayouts;
use App\Services\Reporting\CsvExport;
use App\Support\Period;
use Illuminate\Http\Request;

/**
 * `/app/salary-run` and its CSV — net pay, in Wise's own upload format.
 *
 * Capability `wise_manage`.
 *
 * The CSV is not a report; it is the file that gets uploaded to Wise to
 * actually move money. Its column order is Wise's, **including the blank ninth
 * column**, which is part of the format and not an oversight. `Amount` is the
 * one field DeskPulse fills in.
 *
 * Two people are left out of the batch, and both are listed on the page so
 * nobody wonders where they went:
 *
 * - anyone without usable payout details (`payable` is false)
 * - anyone whose net pay is zero or less
 *
 * A synthetic `@import.deskpulse.local` address is blanked rather than
 * exported — it is not a real mailbox, and Wise would bounce on it.
 *
 * @see docs/migration/payroll.md §3
 */
class SalaryRunController extends Controller
{
    public function __construct(
        private readonly Period $period,
        private readonly PayRun $payRun,
        private readonly CsvExport $csv,
    ) {}

    public function show(Request $request)
    {
        [$context, $run] = $this->run($request);

        return view('dashboard.salary_run', [
            'title'   => 'Salary run',
            'active'  => 'salary_run',
            'period'  => $context,
            'periods' => Period::options(['day', 'week', 'pay', 'month']),
            'run'     => $run,
        ]);
    }

    public function export(Request $request)
    {
        [$context, $run] = $this->run($request);

        $rows = [WisePayouts::EXPORT_HEADERS];

        foreach ($run['rows'] as $row) {
            if (! $row['payable'] || $row['net'] <= 0) {
                continue;
            }

            $account = $row['wise'];
            $source = $account->source_currency ?: $run['currency'];

            $rows[] = [
                $account->recipient_id ?? '',
                $account->account_holder ?: $row['name'],
                WisePayouts::isSyntheticEmail($account->email) ? '' : ($account->email ?? ''),
                $account->account_summary ?: 'Wise account',
                $source,
                $account->target_currency ?: $source,
                $account->source_label ?: 'source',
                // Plain, not Format::money() — this file is machine-read.
                number_format($row['net'], 2, '.', ''),
                '',
                $account->recipient_type ?: 'PERSON',
            ];
        }

        return $this->csv->rows(
            "wise-salary-run-{$context['start_date']}_to_{$context['end_date']}.csv",
            $rows
        );
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function run(Request $request): array
    {
        $context = $this->period->context(
            $request,
            (int) $request->user()->effectiveOrgId(),
            'pay',
            ['day', 'week', 'pay', 'month']
        );

        return [$context, $this->payRun->compute($request->user(), $context)];
    }
}
