<?php

/**
 * Phase 12 — what clients are charged.
 *
 * The property under test is that billing and payroll are allowed to disagree,
 * and that a customer never sees what the people on their account cost.
 *
 * @see docs/migration/billing.md §6
 */

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\Billing\ClientBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** An approved session — the only kind that is ever billed. */
function billable(User $user, ?Client $client, int $activeSeconds, string $day = '2026-09-10'): WorkSession
{
    return WorkSession::create([
        'user_id'         => $user->id,
        'client_id'       => $client?->id,
        'started_at'      => $day . ' 09:00:00',
        'ended_at'        => $day . ' 17:00:00',
        'active_s'        => $activeSeconds,
        'inactive_s'      => 0,
        'source'          => 'agent',
        'approval_status' => 'approved',
    ]);
}

/** September 2026, whole month, in UTC. */
function september(): array
{
    return ['2026-09-01 00:00:00', '2026-10-01 00:00:00'];
}

function bill(User $viewer, ?int $clientFilter = null, ?array $window = null): array
{
    [$start, $end] = $window ?? september();

    return app(ClientBilling::class)->compute($viewer, $start, $end, $clientFilter);
}

/* ── Hourly and monthly ──────────────────────────────────────────────────── */

test('an hourly agent is charged for active hours at their bill rate', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member, [
        'name' => 'Ava', 'bill_type' => 'hourly', 'bill_rate' => 25.0, 'currency' => 'USD',
    ]);

    billable($worker, null, 7200);          // two hours

    expect(bill($admin)['by_agent'][$worker->id]['amount'])->toBe(50.0);
});

test('a monthly agent is charged one flat fee for a whole month', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member, [
        'bill_type' => 'monthly', 'bill_rate' => 3000.0,
    ]);

    billable($worker, null, 7200);

    expect(bill($admin)['by_agent'][$worker->id]['amount'])->toBe(3000.0);
});

test('a half month charges half the flat fee', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member, [
        'bill_type' => 'monthly', 'bill_rate' => 3000.0,
    ]);

    billable($worker, null, 7200, '2026-09-10');

    // 1st–16th of a 30-day month: 15 days.
    $half = bill($admin, null, ['2026-09-01 00:00:00', '2026-09-16 00:00:00']);

    expect($half['by_agent'][$worker->id]['amount'])->toBe(1500.0);
});

test('a period longer than a month never charges more than one fee', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member, [
        'bill_type' => 'monthly', 'bill_rate' => 3000.0,
    ]);

    billable($worker, null, 7200);

    $quarter = bill($admin, null, ['2026-09-01 00:00:00', '2026-12-01 00:00:00']);

    expect($quarter['by_agent'][$worker->id]['amount'])->toBe(3000.0);
});

/* ── Client splitting ────────────────────────────────────────────────────── */

test('an hourly agent splits across clients by the hours on each', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member, ['bill_type' => 'hourly', 'bill_rate' => 10.0]);

    $acme = Client::create(['org_id' => $organization->id, 'name' => 'Acme']);
    $globex = Client::create(['org_id' => $organization->id, 'name' => 'Globex']);

    billable($worker, $acme, 3600);
    billable($worker, $globex, 7200);

    $byClient = bill($admin)['by_client'];

    expect($byClient[$acme->id]['amount'])->toBe(10.0)
        ->and($byClient[$globex->id]['amount'])->toBe(20.0);
});

test('a session with no client rolls into Unassigned', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member, ['bill_type' => 'hourly', 'bill_rate' => 10.0]);

    billable($worker, null, 3600);

    expect(bill($admin)['by_client'][0]['name'])->toBe('Unassigned')
        ->and(bill($admin)['by_client'][0]['amount'])->toBe(10.0);
});

test('a client-filtered monthly fee is split by the share of the agent TOTAL time', function () {
    // The subtle one. The divisor is everything the agent worked, not just the
    // filtered slice — otherwise every client would be charged the whole fee.
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member, ['bill_type' => 'monthly', 'bill_rate' => 3000.0]);

    $acme = Client::create(['org_id' => $organization->id, 'name' => 'Acme']);
    $globex = Client::create(['org_id' => $organization->id, 'name' => 'Globex']);

    billable($worker, $acme, 3600);         // a quarter of the agent's time
    billable($worker, $globex, 10800);

    $filtered = bill($admin, $acme->id);

    expect($filtered['by_agent'][$worker->id]['amount'])->toBe(750.0);
});

/* ── What is and is not billable ─────────────────────────────────────────── */

test('a pending entry is not billed until it is approved', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member, ['bill_type' => 'hourly', 'bill_rate' => 10.0]);

    $entry = billable($worker, null, 3600);
    $entry->forceFill(['approval_status' => 'pending', 'source' => 'manual'])->save();

    expect(bill($admin)['by_agent'])->toBe([]);

    $entry->forceFill(['approval_status' => 'approved'])->save();

    expect(bill($admin)['by_agent'][$worker->id]['amount'])->toBe(10.0);
});

test('inactive time is never billed', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member, ['bill_type' => 'hourly', 'bill_rate' => 10.0]);

    $session = billable($worker, null, 3600);
    $session->forceFill(['inactive_s' => 36000])->save();

    expect(bill($admin)['by_agent'][$worker->id]['amount'])->toBe(10.0);
});

test('unapproved overtime IS billed even though it is not paid', function () {
    // Billing charges all active time; payroll withholds the overtime portion.
    // The two disagreeing is correct — making them agree changes invoices.
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member, ['bill_type' => 'hourly', 'bill_rate' => 10.0]);

    $session = billable($worker, null, 7200);
    $session->forceFill(['overtime_s' => 3600, 'overtime_status' => 'pending', 'overtime_computed' => 1])->save();

    expect(bill($admin)['by_agent'][$worker->id]['amount'])->toBe(20.0)
        // …while payroll would credit only the hour that is not overtime.
        ->and($session->fresh()->creditableActiveSeconds())->toBe(3600);
});

test('an agent with no time and no charge is not a line item', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    member($organization, UserRole::Member, ['bill_type' => 'hourly', 'bill_rate' => 10.0]);

    expect(bill($admin)['by_agent'])->toBe([]);
});

/* ── Scope and exposure ──────────────────────────────────────────────────── */

test('a client portal sees only its own client and no labor cost anywhere', function () {
    $organization = org();
    $worker = member($organization, UserRole::Member, [
        'name' => 'Ava', 'bill_type' => 'hourly', 'bill_rate' => 50.0,
        'pay_type' => 'hourly', 'pay_rate' => 20.0,
    ]);

    // A portal login is provisioned AGAINST a client record — clients.user_id
    // is the link, and without it the login sees nothing at all.
    $portal = member($organization, UserRole::ClientViewer);
    $acme = Client::create(['org_id' => $organization->id, 'name' => 'Acme', 'user_id' => $portal->id]);

    billable($worker, $acme, 3600);

    $result = bill($portal, $acme->id);
    $agent = $result['by_agent'][$worker->id];

    // The charge, never the cost — the difference is the agency's margin.
    expect($agent['rate'])->toBe(50.0)
        ->and($agent)->not->toHaveKey('pay_rate')
        ->and($agent)->not->toHaveKey('cost');
});

test('one tenant never appears in another billing run', function () {
    $ours = org(['name' => 'Acme']);
    $admin = member($ours, UserRole::ClientAdmin);

    billable(member($ours, UserRole::Member, ['bill_type' => 'hourly', 'bill_rate' => 10.0]), null, 3600);
    billable(member(org(['name' => 'Globex']), UserRole::Member, ['bill_type' => 'hourly', 'bill_rate' => 99.0]), null, 3600);

    $result = bill($admin);

    expect($result['by_agent'])->toHaveCount(1)
        ->and(array_sum(array_column($result['by_agent'], 'amount')))->toBe(10.0);
});

/* ── The page ────────────────────────────────────────────────────────────── */

test('billing needs the capability', function () {
    $this->actingAs(member(org(), UserRole::ClientAdmin))->get('/app/billing')->assertOk();
    $this->actingAs(member(org(), UserRole::ClientViewer))->get('/app/billing')->assertOk();
    $this->actingAs(member(org(), UserRole::HrManager))->get('/app/billing')->assertRedirect();
    $this->actingAs(member(org(), UserRole::Member))->get('/app/billing')->assertRedirect();
});

test('the export names its period and carries both tables', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member, ['name' => 'Ava', 'bill_type' => 'hourly', 'bill_rate' => 10.0]);
    billable($worker, null, 3600, gmdate('Y-m-d'));

    $response = $this->actingAs($admin)->get('/app/billing.csv?period=month')->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('deskpulse-billing-month.csv');

    $csv = $response->streamedContent();

    expect($csv)->toContain('Billing by agent')
        ->and($csv)->toContain('Billing by customer')
        ->and($csv)->toContain('Ava')
        ->and($csv)->not->toContain("\u{00A0}");
});
