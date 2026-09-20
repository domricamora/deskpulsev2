<?php

/**
 * The client portal's landing page, the agent detail page, and the roster
 * modal endpoint.
 *
 * `/app` sends a `client_viewer` straight to `/app/agents`, so this is the
 * first thing a customer sees — and the gate on it is the one place the
 * legacy capability matrix does something surprising.
 *
 * @see docs/migration/routes.md §5
 */

use App\Enums\UserRole;
use App\Models\AgentClient;
use App\Models\Client;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\WorkSession;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function rosterSession(User $user): WorkSession
{
    return WorkSession::create([
        'user_id' => $user->id, 'started_at' => gmdate('Y-m-d') . ' 09:00:00',
        'ended_at' => gmdate('Y-m-d') . ' 17:00:00', 'active_s' => 3600,
        'inactive_s' => 0, 'source' => 'agent', 'approval_status' => 'approved',
    ]);
}

/** A manager and a worker on the same team, so the manager can see them. */
function managedPair(\App\Models\Organization $organization): array
{
    $manager = member($organization, UserRole::Manager);
    $worker = member($organization, UserRole::Member, ['name' => 'Ana Cruz']);

    $team = Team::create(['org_id' => $organization->id, 'name' => 'Support']);
    TeamMember::create(['team_id' => $team->id, 'user_id' => $manager->id]);
    TeamMember::create(['team_id' => $team->id, 'user_id' => $worker->id]);

    return [$manager, $worker];
}

/* ── The gate, including its oddity ──────────────────────────────────────── */

test('a company admin cannot open the roster, and that is the real behaviour', function () {
    // The legacy comment says view_agents is "implied by manage_agents", but
    // can() is a flat in_array with no implication, and client_admin holds
    // manage_agents WITHOUT view_agents. Reproduced rather than tidied up:
    // granting it here would be new access nobody has today.
    $this->actingAs(member(org(), UserRole::ClientAdmin))->get('/app/agents')->assertRedirect();

    // A manager holds both.
    $this->actingAs(member(org(), UserRole::Manager))->get('/app/agents')->assertOk();
});

test('a client portal lands on the roster and sees it read-only', function () {
    $organization = org();
    $portal = member($organization, UserRole::ClientViewer);
    $client = Client::create(['org_id' => $organization->id, 'name' => 'Acme', 'user_id' => $portal->id]);

    $worker = member($organization, UserRole::Member, ['name' => 'Ana Cruz']);
    AgentClient::create(['agent_id' => $worker->id, 'client_id' => $client->id]);

    $this->actingAs($portal)->get('/app/agents')
        ->assertOk()
        ->assertSee('Ana Cruz')
        // No assignment control: that needs manage_agents.
        ->assertDontSee('Save clients');
});

test('a manager sees the assignment control', function () {
    $organization = org();
    [$manager] = managedPair($organization);

    Client::create(['org_id' => $organization->id, 'name' => 'Acme']);

    $this->actingAs($manager)->get('/app/agents')->assertOk()->assertSee('Save clients');
});

test('a read-only role that posts an assignment is ignored', function () {
    $organization = org();
    $portal = member($organization, UserRole::ClientViewer);
    $client = Client::create(['org_id' => $organization->id, 'name' => 'Acme', 'user_id' => $portal->id]);
    $worker = member($organization, UserRole::Member);

    $this->actingAs($portal)->post('/app/agents', [
        'action' => 'assign_clients', 'agent_id' => $worker->id, 'client_ids' => [$client->id],
    ]);

    expect(AgentClient::query()->count())->toBe(0);
});

test('a client from another tenant cannot be assigned', function () {
    $organization = org(['name' => 'Acme']);
    [$manager, $worker] = managedPair($organization);

    $foreign = Client::create(['org_id' => org(['name' => 'Globex'])->id, 'name' => 'Theirs']);

    $this->actingAs($manager)->post('/app/agents', [
        'action' => 'assign_clients', 'agent_id' => $worker->id, 'client_ids' => [$foreign->id],
    ]);

    expect(AgentClient::query()->count())->toBe(0);
});

/* ── Detail ──────────────────────────────────────────────────────────────── */

test('an agent detail page renders for somebody in scope', function () {
    $organization = org();
    $portal = member($organization, UserRole::ClientViewer);
    $client = Client::create(['org_id' => $organization->id, 'name' => 'Acme', 'user_id' => $portal->id]);
    $worker = member($organization, UserRole::Member, ['name' => 'Ana Cruz']);
    AgentClient::create(['agent_id' => $worker->id, 'client_id' => $client->id]);

    rosterSession($worker);

    $this->actingAs($portal)->get("/app/agents/{$worker->id}")
        ->assertOk()
        ->assertSee('Ana Cruz')
        // A portal holds screenshots but not view_rates.
        ->assertSee('hidden for your role');
});

test('an out-of-scope agent sends you back rather than erroring', function () {
    $outsider = member(org(['name' => 'Globex']), UserRole::Member);

    $this->actingAs(member(org(['name' => 'Acme']), UserRole::Manager))
        ->get("/app/agents/{$outsider->id}")
        ->assertRedirect('/app/agents');
});

test('a platform operator opens any agent directly', function () {
    // Their own org is the Platform org unless acting as a tenant, and they
    // hold every capability — a support link should open, not 403.
    $super = member(org(['name' => 'Platform']), UserRole::SuperAdmin);
    $worker = member(org(['name' => 'Acme']), UserRole::Member, ['name' => 'Ana Cruz']);

    $this->actingAs($super)->get("/app/agents/{$worker->id}")->assertOk()->assertSee('Ana Cruz');
});

/* ── The modal endpoint ──────────────────────────────────────────────────── */

test('the roster modal needs a roster to click', function () {
    $organization = org();
    $worker = member($organization, UserRole::Member);

    // A member has neither view_all nor view_team.
    $this->actingAs($worker)->get("/app/agent/{$worker->id}")->assertForbidden();

    $this->actingAs(member($organization, UserRole::ClientAdmin))
        ->get("/app/agent/{$worker->id}")
        ->assertOk();
});

test('the modal refuses somebody outside the viewer scope', function () {
    $outsider = member(org(['name' => 'Globex']), UserRole::Member);

    $this->actingAs(member(org(['name' => 'Acme']), UserRole::ClientAdmin))
        ->get("/app/agent/{$outsider->id}")
        ->assertForbidden();
});

test('rates reach the modal only with view_rates', function () {
    $organization = org();
    $worker = member($organization, UserRole::Member, ['pay_rate' => 20.0, 'bill_rate' => 50.0]);

    // A company admin holds view_rates.
    $this->actingAs(member($organization, UserRole::ClientAdmin))
        ->get("/app/agent/{$worker->id}")
        ->assertOk()
        ->assertJsonStructure(['pay', 'bill']);

    // HR does not — and the fields are absent, not blanked.
    $response = $this->actingAs(member($organization, UserRole::HrManager))
        ->get("/app/agent/{$worker->id}")
        ->assertOk();

    expect($response->json())->not->toHaveKey('pay')
        ->and($response->json())->not->toHaveKey('bill');
});

/* ── Contracts ───────────────────────────────────────────────────────────── */

test('contracts need the contracts_manage capability', function () {
    // Narrower than clients_manage: HR staffs a contract without being able
    // to create clients or portal logins.
    $this->actingAs(member(org(), UserRole::HrManager))->get('/app/contracts')->assertOk();
    $this->actingAs(member(org(), UserRole::ItAdmin))->get('/app/contracts')->assertRedirect();
});

test('a contract from another tenant cannot be staffed', function () {
    $theirs = org(['name' => 'Globex']);
    $theirClient = Client::create(['org_id' => $theirs->id, 'name' => 'Theirs']);
    $theirContract = \App\Models\Contract::create([
        'org_id' => $theirs->id, 'client_id' => $theirClient->id,
        'title' => 'Their engagement', 'status' => 'active', 'currency' => 'USD',
    ]);

    $ourAdmin = member(org(['name' => 'Acme']), UserRole::ClientAdmin);

    $this->actingAs($ourAdmin)->post('/app/contracts', [
        'action' => 'assign_member', 'contract_id' => $theirContract->id, 'user_id' => $ourAdmin->id,
    ]);

    expect(\App\Models\ContractMember::query()->count())->toBe(0);
});
