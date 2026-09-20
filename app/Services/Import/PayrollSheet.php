<?php

namespace App\Services\Import;

use App\Support\Sheet;

/**
 * Parsing a payroll timesheet workbook into validated rows.
 *
 * Ports import_locate(), import_norm_header() and import_parse_file().
 *
 * ## The data sheet is found, not named
 *
 * A workbook carries a pivot tab, a notes tab and the actual data in whatever
 * order that month's export produced. The data sheet is the one whose header
 * row holds both "Contract Name" and "NET PAYROLL", and the header row is
 * found by scanning the first 60 rows — real exports have title banners,
 * blank rows and merged cells above the table.
 *
 * ## Bad rows are skipped, never fatal
 *
 * A payroll file has padding rows, `#N/A` from a broken lookup and totals
 * rows. Every one of them is counted with a reason and the rest of the file
 * still imports; throwing on the first would mean one bad cell blocks a
 * month's payroll.
 *
 * @see docs/migration/payroll.md §5
 */
class PayrollSheet
{
    /** The two labels that identify the data sheet. */
    private const REQUIRED = ['Contract Name', 'NET PAYROLL'];

    /**
     * @return array{rows: list<array<string, mixed>>, sheet: ?string, skipped: array{count: int, reasons: array<string, int>}, error: ?string}
     */
    public function parse(string $path): array
    {
        $located = $this->locate($path);

        if ($located === null) {
            return [
                'rows'    => [],
                'sheet'   => null,
                'skipped' => ['count' => 0, 'reasons' => []],
                'error'   => 'Could not find a payroll data sheet (no header row with "Contract Name" and "NET PAYROLL").',
            ];
        }

        [$sheet, $rows, $headerRow, $labels] = $located;

        $cell = fn (array $row, string $label): string => isset($labels[Sheet::normalizeLabel($label)])
            ? trim((string) ($row[$labels[Sheet::normalizeLabel($label)]] ?? ''))
            : '';

        $parsed = [];
        $skipped = 0;
        $reasons = [];

        $skip = function (string $reason) use (&$skipped, &$reasons): void {
            $skipped++;
            $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
        };

        foreach ($rows as $number => $row) {
            if ($number <= $headerRow) {
                continue;
            }

            $reference = $cell($row, 'VT ID');
            $date = Sheet::serialToDate($cell($row, 'Date'));
            $name = $cell($row, 'Wise Name') ?: $cell($row, 'Contract Name');

            // A completely blank row is padding, not an error.
            if ($reference === '' && $name === '' && $date === null) {
                continue;
            }

            if ($reference === '' || strtoupper($reference) === '#N/A') {
                $skip('missing or invalid VT ID');
                continue;
            }

            if ($date === null) {
                $skip('missing or invalid date');
                continue;
            }

            if ($name === '') {
                $skip('missing employee name');
                continue;
            }

            // Two spellings for the same figure, depending on the export.
            $adjusted = $cell($row, 'Adj Credited Hrs');
            $credited = $cell($row, 'Credited Time');
            $hours = is_numeric($adjusted) ? (float) $adjusted : (is_numeric($credited) ? (float) $credited : null);

            if ($hours === null) {
                $skip('no credited hours');
                continue;
            }

            $activeSeconds = (int) round(max(0.0, $hours) * 3600);

            if ($activeSeconds <= 0) {
                $skip('zero credited hours');
                continue;
            }

            $rate = $cell($row, 'Payroll Rate');
            $dispute = $cell($row, 'Dispute / Adjustments');

            $parsed[] = [
                'vt_id'       => substr($reference, 0, 40),
                'name'        => substr($name, 0, 120),
                'job_title'   => substr($cell($row, 'Role Name'), 0, 120),
                'pay_rate'    => is_numeric($rate) ? (float) $rate : 0.0,
                'wise_id'     => substr($cell($row, 'Wise ID'), 0, 64),
                'wise_name'   => substr($cell($row, 'Wise Name'), 0, 160),
                'client_name' => substr($cell($row, 'Client'), 0, 200),
                'client_code' => $cell($row, 'Client ID'),
                'industry'    => $cell($row, 'Industry'),
                'date'        => $date,
                'active_s'    => $activeSeconds,
                'note'        => substr(
                    'Imported payroll (' . $reference . ')'
                    . (is_numeric($dispute) && (float) $dispute != 0.0 ? '; dispute/adj ' . $dispute . 'h' : ''),
                    0,
                    1000
                ),
            ];
        }

        return [
            'rows'    => $parsed,
            'sheet'   => $sheet,
            'skipped' => ['count' => $skipped, 'reasons' => $reasons],
            'error'   => null,
        ];
    }

    /**
     * The data sheet, its rows, its header row number and its label map.
     *
     * @return array{0: string, 1: array<int, array<string, string>>, 2: int, 3: array<string, string>}|null
     */
    private function locate(string $path): ?array
    {
        foreach (Sheet::sheetNames($path) ?: [null] as $sheet) {
            $rows = Sheet::rows($path, $sheet);

            if (! $rows) {
                continue;
            }

            [$headerRow, $labels] = Sheet::findHeader($rows, self::REQUIRED);

            if ($headerRow > 0) {
                return [(string) $sheet, $rows, $headerRow, $labels];
            }
        }

        return null;
    }
}
