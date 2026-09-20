<?php

namespace App\Services\Reporting;

use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV exports. Streamed, never buffered into a string first.
 *
 * ## Never format numbers with the display helpers
 *
 * `Format::money()` and `Format::hms()` join their parts with a NON-BREAKING
 * SPACE so a figure cannot wrap mid-number on screen. Put one of those through
 * a CSV and every spreadsheet reads the cell as text. Exports format their own
 * numbers, plainly, and that is the whole reason this class does its own
 * rounding instead of reusing the helpers a Blade would.
 *
 * ## Filenames carry the period
 *
 * A fixed filename means the third export of the morning silently overwrites
 * the first two in the downloads folder. The legacy had a bug here — the
 * session export interpolated an undefined `$period` — which is recorded as
 * regression 2 in `reports.md` §4 and asserted in the tests.
 *
 * @see docs/migration/reports.md §6
 */
class CsvExport
{
    public function __construct(private readonly SessionStats $stats) {}

    /**
     * The scoped session export behind `/app/export.csv`.
     *
     * @param  Collection<int, \App\Models\WorkSession>  $sessions
     * @param  array<int, User>  $usersById
     */
    public function sessions(Collection $sessions, array $usersById, string $filename): StreamedResponse
    {
        // One lookup for every client on the export. The legacy queries the
        // clients table once per session, which on a month of data for a team
        // is thousands of round trips to print a handful of distinct names.
        $clientNames = Client::query()
            ->whereIn('id', $sessions->pluck('client_id')->filter()->unique()->all())
            ->pluck('name', 'id');

        $rows = $sessions->map(function ($session) use ($usersById, $clientNames) {
            $user = $usersById[(int) $session->user_id] ?? null;
            $cost = (int) $session->active_s / 3600.0
                * ($user ? $this->stats->hourlyRate($user) : 0.0);

            return [
                gmdate('Y-m-d H:i', strtotime($session->getRawOriginal('started_at') . ' UTC')),
                $user->name ?? $session->user_id,
                $clientNames[$session->client_id] ?? '',
                $session->source,
                $session->approval_status,
                round((int) $session->active_s / 3600, 2),
                round((int) $session->inactive_s / 3600, 2),
                $session->activityPercent(),
                round($cost, 2),
                str_replace("\n", ' ', (string) $session->note),
            ];
        })->all();

        return $this->stream($filename, [
            ['Date (UTC)', 'User', 'Client', 'Source', 'Status',
                'Active (h)', 'Inactive (h)', 'Activity %', 'Cost', 'Note'],
        ], $rows);
    }

    /**
     * The efficiency report behind `/app/reports/efficiency.csv`.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function efficiency(array $rows, string $period, string $filename): StreamedResponse
    {
        $body = array_map(fn (array $row) => [
            $row['name'],
            $row['role']?->label() ?? '',
            round($row['active_s'] / 3600, 2),
            round($row['inactive_s'] / 3600, 2),
            $row['activity_pct'],
            $row['sessions'],
            $row['tasks_done'],
            $row['tasks_total'],
            $row['task_completion_pct'],
            // Unscored people are "n/a", not 0 — see Efficiency.
            $row['effectiveness_pct'] ?? 'n/a',
        ], $rows);

        return $this->stream($filename, [
            ['Employee efficiency & effectiveness — period: ' . $period],
            ['Name', 'Role', 'Active (h)', 'Inactive (h)', 'Activity %', 'Sessions',
                'Tasks done', 'Tasks total', 'Task completion %', 'Effectiveness %'],
        ], $body);
    }

    /**
     * Any pre-built grid of rows, streamed as a download.
     *
     * The escape hatch for reports whose shape is their own — billing stacks
     * two tables with a blank line between them, and payroll's salary run is
     * shaped by the pay cycle rather than by a fixed column set.
     *
     * @param  list<list<mixed>>  $rows
     */
    public function rows(string $filename, array $rows): StreamedResponse
    {
        return $this->stream($filename, [], $rows);
    }

    /**
     * @param  list<list<mixed>>  $headerRows
     * @param  list<list<mixed>>  $rows
     */
    private function stream(string $filename, array $headerRows, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headerRows, $rows) {
            $handle = fopen('php://output', 'w');

            foreach ([...$headerRows, ...$rows] as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
