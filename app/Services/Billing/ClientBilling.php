<?php

namespace App\Services\Billing;

use App\Models\Client;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\Reporting\SessionStats;
use App\Support\Visibility;

/**
 * What clients are charged. Ports compute_billing().
 *
 * This is NOT what organizations pay DeskPulse — that is `payments.md`.
 *
 * ## Two rates that are never interchangeable
 *
 * `users.bill_rate` is what the client is charged. `users.pay_rate` is what the
 * organization pays the worker. A `client_viewer` holds `billing` and not
 * `view_rates`, so it sees the first and must never receive the second — that
 * is margin, and it belongs to the agency.
 *
 * ## Billing and payroll disagree on purpose
 *
 * Billing charges **all** `active_s`, including overtime HR has not approved.
 * Payroll pays `creditableActiveSeconds()`, which withholds exactly that.
 * Making the two agree would change invoices, so the asymmetry stays.
 *
 * ## Three subtleties worth stating
 *
 * 1. Proration anchors on the **start** month — `date('t', start)` — so a
 *    period crossing a month boundary is divided by the first month's length.
 * 2. `monthFactor` is capped at 1.0, so a long period never charges more than
 *    one flat fee.
 * 3. For a single-client view, a monthly agent's flat fee is split by that
 *    client's share of the agent's **total** time, not of the filtered time.
 *    That is why total seconds are tracked even for sessions the filter drops.
 *
 * The `$days` divisor is a plain 86400, so a DST transition inside the period
 * shifts the fraction by an hour's worth. Reproduced, not corrected.
 *
 * @see docs/migration/billing.md
 */
class ClientBilling
{
    public function __construct(private readonly SessionStats $stats) {}

    /**
     * @return array{by_agent: array<int, array<string, mixed>>, by_client: array<int, array<string, mixed>>}
     */
    public function compute(User $viewer, string $startUtc, string $endUtc, ?int $clientFilter = null): array
    {
        $ids = Visibility::userIds($viewer);
        $organizationId = (int) $viewer->effectiveOrgId();

        $people = array_intersect_key($this->stats->usersById($organizationId), array_flip($ids));

        // Approved only: a manual entry nobody has signed off is not billable.
        $sessions = $this->stats->forUsers($ids, $startUtc, $endUtc);

        $clientNames = Client::query()
            ->where('org_id', $organizationId)
            ->pluck('name', 'id');

        $monthFactor = $this->monthFactor($startUtc, $endUtc);

        [$agentTotalSeconds, $agentSeconds, $agentClientSeconds] =
            $this->aggregate($sessions, $clientFilter);

        $byAgent = [];
        $byClient = [];

        foreach ($people as $id => $person) {
            $seconds = $agentSeconds[$id] ?? 0;
            $monthly = ($person->bill_type ?? 'hourly') === 'monthly';
            $rate = (float) ($person->bill_rate ?? 0);

            if ($monthly) {
                $amount = $rate * $monthFactor;

                if ($clientFilter !== null) {
                    $total = $agentTotalSeconds[$id] ?? 0;
                    $amount = $total > 0 ? $amount * ($seconds / $total) : 0.0;
                }
            } else {
                $amount = $seconds / 3600.0 * $rate;
            }

            // An agent who produced no charge and no time is not a line item.
            if ($amount <= 0 && $seconds <= 0) {
                continue;
            }

            $byAgent[$id] = [
                'name'      => $person->name,
                'secs'      => $seconds,
                'rate'      => $rate,
                'bill_type' => $monthly ? 'monthly' : 'hourly',
                'currency'  => $person->currency,
                'amount'    => $amount,
            ];

            $perClient = $agentClientSeconds[$id] ?? [];

            if (! $monthly) {
                foreach ($perClient as $clientId => $clientSeconds) {
                    $this->addToClient($byClient, $clientNames, $clientId, $clientSeconds,
                        $clientSeconds / 3600.0 * $rate);
                }

                continue;
            }

            // A flat charge follows the time it was spent on; with no time at
            // all it lands in Unassigned rather than vanishing.
            if ($seconds > 0) {
                foreach ($perClient as $clientId => $clientSeconds) {
                    $this->addToClient($byClient, $clientNames, $clientId, $clientSeconds,
                        $amount * ($clientSeconds / $seconds));
                }
            } else {
                $this->addToClient($byClient, $clientNames, 0, 0, $amount);
            }
        }

        return ['by_agent' => $byAgent, 'by_client' => $byClient];
    }

    /**
     * Seconds per agent, per agent-inside-the-filter, and per agent-and-client.
     *
     * @return array{0: array<int, int>, 1: array<int, int>, 2: array<int, array<int, int>>}
     */
    private function aggregate($sessions, ?int $clientFilter): array
    {
        $total = [];
        $inFilter = [];
        $perClient = [];

        foreach ($sessions as $session) {
            $userId = (int) $session->user_id;
            $clientId = (int) ($session->client_id ?: 0);
            $active = (int) $session->active_s;

            // Tracked even when the filter drops the row: a monthly agent's
            // flat fee is divided by their TOTAL time, not the filtered slice.
            $total[$userId] = ($total[$userId] ?? 0) + $active;

            if ($clientFilter !== null && $clientId !== $clientFilter) {
                continue;
            }

            $inFilter[$userId] = ($inFilter[$userId] ?? 0) + $active;
            $perClient[$userId][$clientId] = ($perClient[$userId][$clientId] ?? 0) + $active;
        }

        return [$total, $inFilter, $perClient];
    }

    /**
     * Proration for flat monthly charges.
     *
     * Anchored on the start month's length, and capped so a period longer than
     * a month still charges exactly one fee.
     */
    private function monthFactor(string $startUtc, string $endUtc): float
    {
        $days = max(1, (strtotime($endUtc . ' UTC') - strtotime($startUtc . ' UTC')) / 86400);
        $daysInMonth = (int) date('t', strtotime($startUtc . ' UTC'));

        return min(1.0, $days / $daysInMonth);
    }

    /**
     * @param  array<int, array<string, mixed>>  $byClient
     */
    private function addToClient(array &$byClient, $clientNames, int $clientId, int $seconds, float $amount): void
    {
        $byClient[$clientId] ??= [
            'name'   => $clientId ? ($clientNames[$clientId] ?? 'Client') : 'Unassigned',
            'secs'   => 0,
            'amount' => 0.0,
        ];

        $byClient[$clientId]['secs'] += $seconds;
        $byClient[$clientId]['amount'] += $amount;
    }
}
