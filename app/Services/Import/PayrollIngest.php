<?php

namespace App\Services\Import;

use App\Models\Client;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\Reporting\Overtime;
use App\Support\Token;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * Upserting parsed payroll rows into the uploader's own organization.
 *
 * Ports import_ingest(). Preview and commit are the SAME code path with
 * `$commit` false or true, so what the preview promises is what the commit
 * does — a separate dry-run implementation is a second thing to keep in step.
 *
 * ## Idempotency — the one place this system has it
 *
 * Two keys, and both must stay exactly as they are:
 *
 * - **employees** match on `users.external_ref` (the VT ID)
 * - **sessions** match on `user + DATE(started_at) + client + source='import'`
 *
 * Re-importing the same period updates the same rows instead of doubling
 * everybody's month. Widening or narrowing either key silently duplicates
 * payroll, which is the worst failure this subsystem has.
 *
 * ## Synthetic addresses
 *
 * An employee who exists only in a spreadsheet still needs a `users` row, and
 * every user needs an email. They get `…@import.deskpulse.local`, which is
 * never a real mailbox and is excluded from sending.
 *
 * @see docs/migration/payroll.md §5
 */
class PayrollIngest
{
    public function __construct(private readonly Overtime $overtime) {}

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function ingest(int $organizationId, array $rows, bool $commit): array
    {
        $summary = [
            'clients_new' => 0, 'clients_existing' => 0,
            'emps_new' => 0, 'emps_updated' => 0, 'rate_changes' => [],
            'sessions_new' => 0, 'sessions_updated' => 0,
            'errors' => [],
        ];

        // First occurrence of each distinct client and employee wins.
        $clients = [];
        $employees = [];

        foreach ($rows as $row) {
            if ($row['client_name'] !== '') {
                $clients[mb_strtolower($row['client_name'])] ??= $row;
            }

            $employees[strtolower($row['vt_id'])] ??= $row;
        }

        // Preloaded so this is a handful of queries rather than thousands.
        $clientIds = Client::query()
            ->where('org_id', $organizationId)
            ->get(['id', 'name'])
            ->mapWithKeys(fn ($c) => [mb_strtolower(trim($c->name)) => (int) $c->id])
            ->all();

        $byReference = [];
        $byEmail = [];

        foreach (User::query()->where('org_id', $organizationId)->get(['id', 'external_ref', 'email', 'pay_rate']) as $user) {
            if (($user->external_ref ?? '') !== '') {
                $byReference[strtolower($user->external_ref)] = $user;
            }

            $byEmail[strtolower($user->email)] = $user;
        }

        // Negative ids stand in for rows a dry run would have created.
        $placeholder = -1;

        if ($commit) {
            DB::beginTransaction();
        }

        try {
            $clientIds = $this->upsertClients($clients, $clientIds, $organizationId, $commit, $summary, $placeholder);
            $userIds = $this->upsertEmployees(
                $employees, $byReference, $byEmail, $organizationId, $commit, $summary, $placeholder
            );

            $affected = $this->upsertSessions($rows, $userIds, $clientIds, $commit, $summary, $placeholder);

            if ($commit) {
                DB::commit();

                // Outside the transaction: the split is a read-then-write over
                // everything the employee has, and holding the import's lock
                // through it would block the agent API for the duration.
                foreach ($affected as $userId => $earliest) {
                    $this->overtime->recompute((int) $userId, $earliest . ' 00:00:00');
                }
            }
        } catch (Throwable $exception) {
            if ($commit && DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            $summary['errors'][] = 'Import aborted: ' . $exception->getMessage();
            $summary['fatal'] = true;
        }

        return $summary;
    }

    /**
     * @param  array<string, array<string, mixed>>  $clients
     * @param  array<string, int>  $clientIds
     * @return array<string, int>
     */
    private function upsertClients(array $clients, array $clientIds, int $organizationId, bool $commit, array &$summary, int &$placeholder): array
    {
        foreach ($clients as $key => $row) {
            if (isset($clientIds[$key])) {
                $summary['clients_existing']++;

                continue;
            }

            $summary['clients_new']++;

            if (! $commit) {
                $clientIds[$key] = $placeholder--;

                continue;
            }

            $notes = 'Imported.'
                . ($row['client_code'] !== '' ? ' Client ID: ' . $row['client_code'] : '')
                . ($row['industry'] !== '' ? ' | Industry: ' . $row['industry'] : '');

            $clientIds[$key] = (int) Client::create([
                'org_id'        => $organizationId,
                'name'          => $row['client_name'],
                'contact_email' => '',
                'notes'         => substr($notes, 0, 65535),
                'currency'      => 'USD',
            ])->id;
        }

        return $clientIds;
    }

    /**
     * @param  array<string, array<string, mixed>>  $employees
     * @return array<string, int>
     */
    private function upsertEmployees(array $employees, array $byReference, array $byEmail, int $organizationId, bool $commit, array &$summary, int &$placeholder): array
    {
        $userIds = [];

        foreach ($employees as $key => $row) {
            $email = $this->syntheticEmail($row['vt_id'], $organizationId);
            $existing = $byReference[$key] ?? ($byEmail[strtolower($email)] ?? null);

            if ($existing) {
                $userIds[$key] = (int) $existing->id;
                $summary['emps_updated']++;

                // Surfaced in the preview: a rate change is the one edit an
                // import makes that somebody should see before committing.
                if ((float) $existing->pay_rate !== (float) $row['pay_rate']) {
                    $summary['rate_changes'][] = [
                        'name' => $row['name'],
                        'from' => (float) $existing->pay_rate,
                        'to'   => (float) $row['pay_rate'],
                    ];
                }

                if ($commit) {
                    User::query()->whereKey($existing->id)->update([
                        'name'         => $row['name'],
                        'job_title'    => $row['job_title'],
                        'pay_type'     => 'hourly',
                        'pay_rate'     => $row['pay_rate'],
                        'wise_id'      => $row['wise_id'] ?: null,
                        'wise_name'    => $row['wise_name'] ?: null,
                        // Never overwrite a reference that is already set.
                        'external_ref' => DB::raw('COALESCE(external_ref, ' . DB::getPdo()->quote($row['vt_id']) . ')'),
                    ]);
                }

                continue;
            }

            $summary['emps_new']++;

            if (! $commit) {
                $userIds[$key] = $placeholder--;

                continue;
            }

            try {
                $userIds[$key] = (int) User::create([
                    'org_id'               => $organizationId,
                    'name'                 => $row['name'],
                    'email'                => $email,
                    'password_hash'        => Hash::make(Token::hex(12)),
                    'role'                 => 'member',
                    'pay_type'             => 'hourly',
                    'pay_rate'             => $row['pay_rate'],
                    'currency'             => 'USD',
                    'external_ref'         => $row['vt_id'],
                    'wise_id'              => $row['wise_id'] ?: null,
                    'wise_name'            => $row['wise_name'] ?: null,
                    'job_title'            => $row['job_title'],
                    'must_change_password' => 1,
                ])->id;
            } catch (Throwable $exception) {
                // One employee failing must not abort a month's payroll.
                $summary['emps_new']--;
                $summary['errors'][] = 'Employee ' . $row['name'] . ' (' . $row['vt_id'] . '): ' . $exception->getMessage();
                $userIds[$key] = 0;
            }
        }

        return $userIds;
    }

    /**
     * One session per employee, day and client.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, int>  $userIds
     * @param  array<string, int>  $clientIds
     * @return array<int, string>  userId => earliest touched date
     */
    private function upsertSessions(array $rows, array $userIds, array $clientIds, bool $commit, array &$summary, int &$placeholder): array
    {
        $realIds = array_values(array_filter($userIds, fn (int $id) => $id > 0));
        $existing = [];

        if ($realIds) {
            foreach (DB::table('sessions')
                ->where('source', 'import')
                ->whereIn('user_id', $realIds)
                ->get(['id', 'user_id', 'client_id', DB::raw('DATE(started_at) as d')]) as $session) {
                $existing[$session->user_id . '|' . $session->d . '|' . ((int) ($session->client_id ?? 0))] = (int) $session->id;
            }
        }

        $affected = [];

        foreach ($rows as $row) {
            $userId = $userIds[strtolower($row['vt_id'])] ?? 0;

            if ($userId === 0) {
                continue;                       // the employee insert failed
            }

            $clientId = $row['client_name'] !== ''
                ? ($clientIds[mb_strtolower($row['client_name'])] ?? null)
                : null;

            $key = $userId . '|' . $row['date'] . '|' . ((int) ($clientId ?? 0));

            // An imported day is a nominal 09:00 start; the sheet carries a
            // total, not a timeline.
            $startedAt = $row['date'] . ' 09:00:00';
            $endedAt = gmdate('Y-m-d H:i:s', strtotime($startedAt . ' UTC') + $row['active_s']);

            if (isset($existing[$key])) {
                $summary['sessions_updated']++;

                if ($commit) {
                    // overtime_computed = 0 so the split is redone for the new
                    // hours rather than keeping the previous import's answer.
                    WorkSession::query()->whereKey($existing[$key])->update([
                        'active_s'          => $row['active_s'],
                        'ended_at'          => $endedAt,
                        'note'              => $row['note'],
                        'overtime_computed' => 0,
                    ]);
                }
            } else {
                $summary['sessions_new']++;

                if ($commit) {
                    $existing[$key] = (int) WorkSession::create([
                        'user_id'         => $userId,
                        'client_id'       => ($clientId && $clientId > 0) ? $clientId : null,
                        'started_at'      => $startedAt,
                        'ended_at'        => $endedAt,
                        'active_s'        => $row['active_s'],
                        'inactive_s'      => 0,
                        'source'          => 'import',
                        'note'            => $row['note'],
                        // Payroll import writes settled history.
                        'approval_status' => 'approved',
                    ])->id;
                } else {
                    $existing[$key] = $placeholder--;
                }
            }

            if (! isset($affected[$userId]) || $row['date'] < $affected[$userId]) {
                $affected[$userId] = $row['date'];
            }
        }

        return $affected;
    }

    /**
     * A never-deliverable address for somebody who exists only in a sheet.
     *
     * Scoped by organization so the same VT ID in two tenants does not collide
     * on the unique email index.
     */
    private function syntheticEmail(string $reference, int $organizationId): string
    {
        return 'vt' . preg_replace('/[^a-z0-9]/i', '', strtolower($reference))
            . '.org' . $organizationId . '@import.deskpulse.local';
    }
}
