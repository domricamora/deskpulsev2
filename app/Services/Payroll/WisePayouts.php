<?php

namespace App\Services\Payroll;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\WiseAccount;
use App\Support\Period;
use App\Support\Sheet;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Wise payout details — parsing an export, and matching it to real people.
 *
 * Ports wise_parse_file(), wise_ingest(), wise_is_junk() and
 * is_synthetic_email().
 *
 * ## It never creates people
 *
 * A payout sheet says where to send money; it is not a roster. An unmatched
 * row is REPORTED, not turned into an employee — inventing a person from a
 * payments file is how you end up paying somebody who does not work here.
 *
 * ## Matching, in order, first hit wins
 *
 * Wise Recipient ID → VT ID (`users.external_ref`) → email → exact name.
 * The list is ordered by how much you can trust each key: a recipient id is
 * unique to a payout account, while a name match is a last resort that two
 * people can share.
 *
 * ## What these files actually look like
 *
 * `#REF!` and `#N/A` are cells where a lookup broke, not data. Header blocks
 * repeat partway down when somebody pasted two exports together. Spacer rows
 * are everywhere. All three are handled, because the alternative is telling
 * somebody their real payroll file is invalid.
 *
 * @see docs/migration/payroll.md §3
 */
class WisePayouts
{
    /** The export's column order, including the deliberately blank ninth. */
    public const EXPORT_HEADERS = [
        'Wise Recipient ID', 'Wise Name', 'EMAIL', 'Wise account',
        'from Currency', 'to Currency', 'Source', 'Amount', '', 'Type',
    ];

    /** Values that mean "this cell is broken", not a value. */
    private const JUNK = ['', '#REF!', '#N/A', '#VALUE!', '#NAME?'];

    public function __construct(private readonly Period $period) {}

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
                'error'   => 'Could not find a header row. The sheet needs at least a "Wise Name" column — '
                           . 'see the column guide below for the accepted names.',
            ];
        }

        [$sheet, $rows, $headerRow, $map] = $located;

        $cell = fn (array $row, string $label): string => $this->cell($row, $map, $label);

        $parsed = [];
        $skipped = 0;
        $reasons = [];

        foreach ($rows as $number => $row) {
            if ($number <= $headerRow) {
                continue;
            }

            $name = $cell($row, 'Wise Name');
            $recipientId = $cell($row, 'Wise Recipient ID');
            $email = $cell($row, 'EMAIL');

            // A wholly empty spacer row is normal in these exports.
            if ($this->isJunk($name) && $this->isJunk($recipientId) && $this->isJunk($email)
                && trim(implode('', $row)) === '') {
                continue;
            }

            // A header block repeated inside the data — two exports pasted
            // together. Not an error, just not a row.
            if (Sheet::normalizeLabel($name) === 'wise name'
                || Sheet::normalizeLabel($recipientId) === 'wise recipient id') {
                continue;
            }

            if ($this->isJunk($name)) {
                $skipped++;
                $reasons['Broken or empty "Wise Name"'] = ($reasons['Broken or empty "Wise Name"'] ?? 0) + 1;

                continue;
            }

            $type = strtoupper($cell($row, 'Type'));

            $parsed[] = [
                'row'             => $number,
                'recipient_id'    => $this->isJunk($recipientId) ? '' : $recipientId,
                'account_holder'  => $name,
                'email'           => (! $this->isJunk($email) && filter_var($email, FILTER_VALIDATE_EMAIL))
                    ? strtolower($email)
                    : '',
                'account_summary' => $this->isJunk($cell($row, 'Wise account')) ? '' : $cell($row, 'Wise account'),
                'source_currency' => strtoupper($cell($row, 'from Currency')),
                'target_currency' => strtoupper($cell($row, 'to Currency')),
                'source_label'    => $this->isJunk($cell($row, 'Source')) ? '' : $cell($row, 'Source'),
                'recipient_type'  => in_array($type, ['PERSON', 'BUSINESS'], true) ? $type : 'PERSON',
                'external_ref'    => $this->isJunk($cell($row, 'VT ID')) ? '' : $cell($row, 'VT ID'),
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
     * Match rows to people and save their payout details.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function ingest(int $organizationId, array $rows, bool $commit): array
    {
        $summary = [
            'matched' => 0, 'created' => 0, 'updated' => 0, 'unmatched' => 0,
            'unmatched_names' => [], 'matches' => [], 'fatal' => null,
        ];

        [$byRecipient, $byReference, $byEmail, $byName] = $this->indexes($organizationId);

        $existing = WiseAccount::query()
            ->where('org_id', $organizationId)
            ->pluck('user_id')
            ->flip();

        $payCurrency = $this->period->organizationConfig($organizationId)['pay_currency'];

        if ($commit) {
            DB::beginTransaction();
        }

        try {
            foreach ($rows as $row) {
                [$person, $how] = $this->match($row, $byRecipient, $byReference, $byEmail, $byName);

                if (! $person) {
                    $summary['unmatched']++;

                    if (count($summary['unmatched_names']) < 25) {
                        $summary['unmatched_names'][] = $row['account_holder'];
                    }

                    continue;
                }

                $summary['matched']++;
                $isNew = ! $existing->has($person->id);
                $summary[$isNew ? 'created' : 'updated']++;

                if (count($summary['matches']) < 25) {
                    $summary['matches'][] = [
                        'name'   => $person->name,
                        'holder' => $row['account_holder'],
                        'how'    => $how,
                        'new'    => $isNew,
                    ];
                }

                if ($commit) {
                    $this->save($organizationId, $person, $row, $payCurrency);
                }
            }

            if ($commit) {
                DB::commit();
            }
        } catch (Throwable $exception) {
            if ($commit && DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            $summary['fatal'] = $exception->getMessage();
        }

        return $summary;
    }

    /** A synthetic import address is never a real mailbox or a payout target. */
    public static function isSyntheticEmail(?string $email): bool
    {
        return $email !== null && str_ends_with(strtolower($email), '@import.deskpulse.local');
    }

    /* ── Internals ───────────────────────────────────────────────────────── */

    /**
     * @return array{0: array<string, User>, 1: array<string, User>, 2: array<string, User>, 3: array<string, User>}
     */
    private function indexes(int $organizationId): array
    {
        $byRecipient = [];
        $byReference = [];
        $byEmail = [];
        $byName = [];

        // A client portal login is a customer; they are never paid.
        foreach (User::query()
            ->where('org_id', $organizationId)
            ->where('role', '<>', UserRole::ClientViewer->value)
            ->get(['id', 'name', 'email', 'external_ref', 'wise_id', 'wise_name']) as $person) {
            if ($person->wise_id) {
                $byRecipient[strtolower($person->wise_id)] = $person;
            }

            if ($person->external_ref) {
                $byReference[strtolower($person->external_ref)] = $person;
            }

            if ($person->email) {
                $byEmail[strtolower($person->email)] = $person;
            }

            $byName[strtolower(trim($person->name))] = $person;

            // Their Wise name is often not their DeskPulse name.
            if ($person->wise_name) {
                $byName[strtolower(trim($person->wise_name))] = $person;
            }
        }

        return [$byRecipient, $byReference, $byEmail, $byName];
    }

    /**
     * @return array{0: ?User, 1: string}
     */
    private function match(array $row, array $byRecipient, array $byReference, array $byEmail, array $byName): array
    {
        if ($row['recipient_id'] !== '' && isset($byRecipient[strtolower($row['recipient_id'])])) {
            return [$byRecipient[strtolower($row['recipient_id'])], 'Wise Recipient ID'];
        }

        if ($row['external_ref'] !== '' && isset($byReference[strtolower($row['external_ref'])])) {
            return [$byReference[strtolower($row['external_ref'])], 'VT ID'];
        }

        if ($row['email'] !== '' && isset($byEmail[$row['email']])) {
            return [$byEmail[$row['email']], 'email'];
        }

        $name = strtolower(trim($row['account_holder']));

        if (isset($byName[$name])) {
            return [$byName[$name], 'name'];
        }

        return [null, ''];
    }

    private function save(int $organizationId, User $person, array $row, string $payCurrency): void
    {
        $holder = $row['account_holder'];
        $source = $row['source_currency'] ?: $payCurrency;

        WiseAccount::query()->updateOrCreate(
            ['org_id' => $organizationId, 'user_id' => $person->id],
            [
                'recipient_id'    => $row['recipient_id'] ?: null,
                'account_holder'  => $holder,
                'email'           => $row['email'] ?: null,
                'account_summary' => $row['account_summary'] ?: null,
                'source_currency' => $source,
                'target_currency' => $row['target_currency'] ?: $source,
                'recipient_type'  => $row['recipient_type'],
                'source_label'    => $row['source_label'] ?: 'source',
                'active'          => 1,
                'updated_at'      => gmdate('Y-m-d H:i:s'),
            ]
        );

        // Keep the person's own record in step, without clearing a recipient
        // id that is already there.
        $person->forceFill(array_filter([
            'wise_id'   => $row['recipient_id'] ?: null,
            'wise_name' => $holder,
        ]))->save();
    }

    /**
     * The first worksheet carrying a "Wise Name" column.
     *
     * A payout tab is usually the second sheet in the workbook, so every one
     * is tried rather than assuming the first.
     *
     * @return array{0: string, 1: array<int, array<string, string>>, 2: int, 3: array<string, string>}|null
     */
    private function locate(string $path): ?array
    {
        $sheets = preg_match('/\.(csv|tsv|txt)$/i', $path) ? [null] : (Sheet::sheetNames($path) ?: [null]);

        foreach ($sheets as $sheet) {
            $rows = Sheet::rows($path, $sheet);

            if (! $rows) {
                continue;
            }

            [$headerRow, $map] = Sheet::findHeader($rows, ['Wise Name']);

            if ($headerRow > 0) {
                return [(string) $sheet, $rows, $headerRow, $map];
            }
        }

        return null;
    }

    /**
     * A cell by its canonical label, trying the spec's aliases too.
     *
     * @param  array<string, string>  $map
     */
    private function cell(array $row, array $map, string $label): string
    {
        foreach ($this->spellings($label) as $spelling) {
            $normalized = Sheet::normalizeLabel($spelling);

            if (isset($map[$normalized])) {
                return trim((string) ($row[$map[$normalized]] ?? ''));
            }
        }

        return '';
    }

    /**
     * The canonical label plus every alias the registry accepts for it.
     *
     * @return list<string>
     */
    private function spellings(string $label): array
    {
        foreach (config('imports.wise.columns', []) as $column) {
            if ($column['name'] === $label) {
                return [$label, ...$column['aliases']];
            }
        }

        return [$label];
    }

    private function isJunk(string $value): bool
    {
        return in_array(strtoupper(trim($value)), self::JUNK, true);
    }
}
