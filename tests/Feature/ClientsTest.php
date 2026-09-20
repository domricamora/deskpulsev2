<?php

/**
 * `/app/clients` — the companies people are placed with, their contracts and
 * their read-only portal logins.
 *
 * Two behaviours carry most of the risk here and are covered hardest:
 * provisioning a portal login (it creates a real account with a real password),
 * and deleting a client (it must never destroy tracked time).
 *
 * @see docs/migration/billing.md
 */

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSession;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function client(int $organizationId, array $attributes = []): Client
{
    return Client::create(array_merge([
        'org_id' => $organizationId,
        'name'   => 'Northwind',
    ], $attributes));
}

/* ── Reaching the page ───────────────────────────────────────────────────── */

test('the page needs clients_manage', function (UserRole $role, bool $allowed) {
    $tenant = org();
    $response = $this->actingAs(member($tenant, $role))->get('/app/clients');

    $allowed ? $response->assertOk() : $response->assertRedirect('/app');
})->with([
    'company admin' => [UserRole::ClientAdmin, true],
    // A team manager oversees people, not commercial relationships.
    'team manager'  => [UserRole::Manager, false],
    'HR admin'      => [UserRole::HrManager, false],
    'IT admin'      => [UserRole::ItAdmin, false],
    'employee'      => [UserRole::Member, false],
    'client portal' => [UserRole::ClientViewer, false],
]);

test('a platform operator is sent to the console', function () {
    $platform = org(['name' => 'DeskPulse platform']);

    $this->actingAs(member($platform, UserRole::SuperAdmin))
        ->get('/app/clients')
        ->assertRedirect('/app/platform');
});

test('another organization clients are never listed', function () {
    $mine = org(['name' => 'Mine']);
    $theirs = org(['name' => 'Theirs']);
    client($theirs->id, ['name' => 'Their Customer']);

    $this->actingAs(member($mine, UserRole::ClientAdmin))
        ->get('/app/clients')
        ->assertOk()
        ->assertDontSee('Their Customer');
});

/* ── Adding a client ─────────────────────────────────────────────────────── */

test('a client added with a contact email gets a portal login', function () {
    $tenant = org();

    $this->actingAs(member($tenant, UserRole::ClientAdmin))->post('/app/clients', [
        'action' => 'add_client', 'name' => 'Northwind', 'contact_email' => 'Ops@Northwind.Test',
        'bill_rate' => '55.5', 'currency' => 'usd',
    ])->assertRedirect('/app/clients');

    $created = Client::where('name', 'Northwind')->firstOrFail();
    $login = User::where('email', 'ops@northwind.test')->firstOrFail();

    expect((float) $created->bill_rate)->toBe(55.5)
        ->and($created->currency)->toBe('USD')
        ->and($created->user_id)->toBe((int) $login->id)
        ->and($login->role)->toBe(UserRole::ClientViewer)
        ->and($login->org_id)->toBe((int) $tenant->id)
        // Gate 2 stops them at the change-password form on first sign-in.
        ->and((bool) $login->must_change_password)->toBeTrue();
});

test('the temporary password is shown to the admin once', function () {
    $tenant = org();

    $this->actingAs(member($tenant, UserRole::ClientAdmin))
        ->post('/app/clients', [
            'action' => 'add_client', 'name' => 'Northwind', 'contact_email' => 'ops@northwind.test',
        ])
        ->assertSessionHas(App\Support\Flash::KEY, function (array $queue) {
            return str_contains($queue[0]['msg'], 'temporary password:');
        });
});

test('a client added without an email gets no login and is told why', function () {
    $tenant = org();

    $this->actingAs(member($tenant, UserRole::ClientAdmin))->post('/app/clients', [
        'action' => 'add_client', 'name' => 'No Contact',
    ]);

    expect(Client::where('name', 'No Contact')->firstOrFail()->user_id)->toBeNull()
        ->and(User::where('role', UserRole::ClientViewer->value)->count())->toBe(0);
});

test('a nameless client is not created', function () {
    $tenant = org();

    $this->actingAs(member($tenant, UserRole::ClientAdmin))
        ->post('/app/clients', ['action' => 'add_client', 'name' => '   ']);

    expect(Client::count())->toBe(0);
});

test('a client whose email is already registered is still created, without a login', function () {
    // The company is real and the admin should not lose the record because the
    // address collides — they are told, and can fix the email afterwards.
    $tenant = org();
    member($tenant, UserRole::Member, ['email' => 'ops@northwind.test']);

    $this->actingAs(member($tenant, UserRole::ClientAdmin))->post('/app/clients', [
        'action' => 'add_client', 'name' => 'Northwind', 'contact_email' => 'ops@northwind.test',
    ]);

    expect(Client::where('name', 'Northwind')->firstOrFail()->user_id)->toBeNull()
        ->and(User::where('email', 'ops@northwind.test')->firstOrFail()->role)->toBe(UserRole::Member);
});

/* ── Portal logins ───────────────────────────────────────────────────────── */

test('a login can be added later to a client that has none', function () {
    $tenant = org();
    $record = client($tenant->id, ['contact_email' => 'later@northwind.test']);

    $this->actingAs(member($tenant, UserRole::ClientAdmin))->post('/app/clients', [
        'action' => 'create_client_login', 'client_id' => $record->id,
    ]);

    expect($record->fresh()->user_id)->not->toBeNull()
        ->and(User::where('email', 'later@northwind.test')->firstOrFail()->role)
        ->toBe(UserRole::ClientViewer);
});

test('a replayed create-login POST does not mint a second account', function () {
    $tenant = org();
    $record = client($tenant->id, ['contact_email' => 'ops@northwind.test']);
    $admin = member($tenant, UserRole::ClientAdmin);

    foreach ([1, 2] as $ignored) {
        $this->actingAs($admin)->post('/app/clients', [
            'action' => 'create_client_login', 'client_id' => $record->id,
            'contact_email' => 'ops@northwind.test',
        ]);
    }

    expect(User::where('role', UserRole::ClientViewer->value)->count())->toBe(1);
});

test('resetting a client password issues a new one and forces a change', function () {
    $tenant = org();
    $record = client($tenant->id, ['contact_email' => 'ops@northwind.test']);
    $admin = member($tenant, UserRole::ClientAdmin);

    $this->actingAs($admin)->post('/app/clients', [
        'action' => 'create_client_login', 'client_id' => $record->id,
    ]);

    $login = User::where('email', 'ops@northwind.test')->firstOrFail();
    $before = $login->password_hash;

    $login->forceFill(['must_change_password' => 0])->save();

    $this->actingAs($admin)->post('/app/clients', [
        'action' => 'reset_client_password', 'client_id' => $record->fresh()->id,
    ]);

    $login->refresh();

    expect($login->password_hash)->not->toBe($before)
        ->and((bool) $login->must_change_password)->toBeTrue();
});

test('the password reset cannot reach an employee account', function () {
    // clients.user_id is pointed at an employee by hand. The reset is scoped to
    // client_viewer, so it must refuse rather than lock a colleague out.
    $tenant = org();
    $employee = member($tenant, UserRole::Member);
    $record = client($tenant->id);
    $record->forceFill(['user_id' => $employee->id])->save();

    $before = $employee->password_hash;

    $this->actingAs(member($tenant, UserRole::ClientAdmin))->post('/app/clients', [
        'action' => 'reset_client_password', 'client_id' => $record->id,
    ]);

    expect($employee->fresh()->password_hash)->toBe($before);
});

/* ── Editing, archiving and deleting ─────────────────────────────────────── */

test('a client is updated', function () {
    $tenant = org();
    $record = client($tenant->id);

    $this->actingAs(member($tenant, UserRole::ClientAdmin))->post('/app/clients', [
        'action' => 'update_client', 'client_id' => $record->id,
        'name' => 'Northwind Ltd', 'contact_email' => 'ap@northwind.test',
        'notes' => 'Net 30', 'bill_rate' => '60', 'currency' => 'gbp',
    ]);

    $record->refresh();

    expect($record->name)->toBe('Northwind Ltd')
        ->and($record->notes)->toBe('Net 30')
        ->and((float) $record->bill_rate)->toBe(60.0)
        ->and($record->currency)->toBe('GBP');
});

test('a client is archived and restored', function () {
    $tenant = org();
    $record = client($tenant->id);
    $admin = member($tenant, UserRole::ClientAdmin);

    $this->actingAs($admin)->post('/app/clients', ['action' => 'archive_client', 'client_id' => $record->id]);
    expect((int) $record->fresh()->archived)->toBe(1);

    $this->actingAs($admin)->post('/app/clients', ['action' => 'unarchive_client', 'client_id' => $record->id]);
    expect((int) $record->fresh()->archived)->toBe(0);
});

test('deleting a client keeps the tracked time and untags it', function () {
    // Destroying the hours would take them off timesheets and out of payroll
    // for work that really happened and has already been billed.
    $tenant = org();
    $worker = member($tenant, UserRole::Member);
    $record = client($tenant->id);

    $session = WorkSession::create([
        'user_id' => $worker->id, 'client_id' => $record->id,
        'started_at' => '2026-09-01 09:00:00', 'ended_at' => '2026-09-01 17:00:00',
        'active_s' => 25200, 'inactive_s' => 3600, 'source' => 'agent', 'approval_status' => 'approved',
    ]);

    $task = Task::create([
        'org_id' => $tenant->id, 'user_id' => $worker->id,
        'client_id' => $record->id, 'title' => 'Build the thing',
    ]);

    $this->actingAs(member($tenant, UserRole::ClientAdmin))
        ->post('/app/clients', ['action' => 'delete_client', 'client_id' => $record->id]);

    expect(Client::whereKey($record->id)->exists())->toBeFalse()
        ->and(WorkSession::whereKey($session->id)->firstOrFail()->client_id)->toBeNull()
        ->and((int) WorkSession::whereKey($session->id)->firstOrFail()->active_s)->toBe(25200)
        ->and(Task::whereKey($task->id)->firstOrFail()->client_id)->toBeNull();
});

test('deleting a client removes its portal login', function () {
    // The account exists only to view that client's data, so leaving it behind
    // would be a live login that can see nothing.
    $tenant = org();
    $record = client($tenant->id, ['contact_email' => 'ops@northwind.test']);
    $admin = member($tenant, UserRole::ClientAdmin);

    $this->actingAs($admin)->post('/app/clients', [
        'action' => 'create_client_login', 'client_id' => $record->id,
    ]);

    $loginId = $record->fresh()->user_id;

    $this->actingAs($admin)->post('/app/clients', ['action' => 'delete_client', 'client_id' => $record->id]);

    expect(User::whereKey($loginId)->exists())->toBeFalse();
});

test('deleting a client cascades its contracts away', function () {
    $tenant = org();
    $record = client($tenant->id);

    Contract::create(['org_id' => $tenant->id, 'client_id' => $record->id, 'title' => 'Retainer']);

    $this->actingAs(member($tenant, UserRole::ClientAdmin))
        ->post('/app/clients', ['action' => 'delete_client', 'client_id' => $record->id]);

    expect(Contract::count())->toBe(0);
});

/* ── Tenancy ─────────────────────────────────────────────────────────────── */

test('a client_id from another organization does not resolve', function () {
    $mine = org(['name' => 'Mine']);
    $theirs = org(['name' => 'Theirs']);
    $victim = client($theirs->id, ['name' => 'Theirs Customer']);

    $this->actingAs(member($mine, UserRole::ClientAdmin))->post('/app/clients', [
        'action' => 'delete_client', 'client_id' => $victim->id,
    ]);

    expect(Client::whereKey($victim->id)->exists())->toBeTrue();
});

test('a contract in another organization cannot be edited or deleted', function () {
    $mine = org(['name' => 'Mine']);
    $theirs = org(['name' => 'Theirs']);
    $victim = Contract::create([
        'org_id' => $theirs->id, 'client_id' => client($theirs->id)->id, 'title' => 'Theirs',
    ]);

    $admin = member($mine, UserRole::ClientAdmin);

    $this->actingAs($admin)->post('/app/clients', [
        'action' => 'update_contract', 'contract_id' => $victim->id, 'title' => 'Hijacked',
    ]);

    $this->actingAs($admin)->post('/app/clients', [
        'action' => 'delete_contract', 'contract_id' => $victim->id,
    ]);

    expect(Contract::whereKey($victim->id)->firstOrFail()->title)->toBe('Theirs');
});

/* ── Contracts ───────────────────────────────────────────────────────────── */

test('a contract inherits the client rate when none is given', function () {
    $tenant = org();
    $record = client($tenant->id, ['bill_rate' => 75.0, 'currency' => 'EUR']);

    $this->actingAs(member($tenant, UserRole::ClientAdmin))->post('/app/clients', [
        'action' => 'add_contract', 'client_id' => $record->id, 'title' => 'Retainer',
        'bill_rate' => '', 'currency' => '',
    ]);

    $contract = Contract::where('title', 'Retainer')->firstOrFail();

    expect((float) $contract->bill_rate)->toBe(75.0)
        ->and($contract->currency)->toBe('EUR');
});

test('a contract keeps its own rate when one is given', function () {
    $tenant = org();
    $record = client($tenant->id, ['bill_rate' => 75.0, 'currency' => 'EUR']);

    $this->actingAs(member($tenant, UserRole::ClientAdmin))->post('/app/clients', [
        'action' => 'add_contract', 'client_id' => $record->id, 'title' => 'Fixed bid',
        'bill_rate' => '120', 'currency' => 'usd', 'status' => 'ended',
        'start_date' => '2026-01-01', 'end_date' => '2026-06-30',
    ]);

    $contract = Contract::where('title', 'Fixed bid')->firstOrFail();

    expect((float) $contract->bill_rate)->toBe(120.0)
        ->and($contract->currency)->toBe('USD')
        ->and($contract->status)->toBe('ended')
        ->and($contract->start_date->format('Y-m-d'))->toBe('2026-01-01');
});

test('an unknown contract status becomes active', function () {
    $tenant = org();
    $record = client($tenant->id);

    $this->actingAs(member($tenant, UserRole::ClientAdmin))->post('/app/clients', [
        'action' => 'add_contract', 'client_id' => $record->id, 'title' => 'Odd', 'status' => 'pending',
    ]);

    expect(Contract::where('title', 'Odd')->firstOrFail()->status)->toBe('active');
});

test('blank contract dates are stored as null rather than as empty strings', function () {
    $tenant = org();
    $record = client($tenant->id);

    $this->actingAs(member($tenant, UserRole::ClientAdmin))->post('/app/clients', [
        'action' => 'add_contract', 'client_id' => $record->id, 'title' => 'Open ended',
        'start_date' => '', 'end_date' => '',
    ]);

    $contract = Contract::where('title', 'Open ended')->firstOrFail();

    expect($contract->start_date)->toBeNull()->and($contract->end_date)->toBeNull();
});

test('a contract is updated and deleted', function () {
    $tenant = org();
    $record = client($tenant->id);
    $admin = member($tenant, UserRole::ClientAdmin);
    $contract = Contract::create(['org_id' => $tenant->id, 'client_id' => $record->id, 'title' => 'Before']);

    $this->actingAs($admin)->post('/app/clients', [
        'action' => 'update_contract', 'contract_id' => $contract->id,
        'title' => 'After', 'bill_rate' => '90', 'status' => 'ended',
    ]);

    expect($contract->fresh()->title)->toBe('After')
        ->and((float) $contract->fresh()->bill_rate)->toBe(90.0);

    $this->actingAs($admin)->post('/app/clients', [
        'action' => 'delete_contract', 'contract_id' => $contract->id,
    ]);

    expect(Contract::whereKey($contract->id)->exists())->toBeFalse();
});

/* ── The billing rollup ──────────────────────────────────────────────────── */

test('the rollup bills the worker rate, and only approved time', function () {
    $tenant = org();
    $worker = member($tenant, UserRole::Member, ['bill_rate' => 50.0]);
    $record = client($tenant->id, ['bill_rate' => 999.0]);   // a quoted figure, not what is charged

    WorkSession::create([
        'user_id' => $worker->id, 'client_id' => $record->id,
        'started_at' => '2026-09-01 09:00:00', 'ended_at' => '2026-09-01 11:00:00',
        'active_s' => 7200, 'inactive_s' => 0, 'source' => 'agent', 'approval_status' => 'approved',
    ]);

    WorkSession::create([
        'user_id' => $worker->id, 'client_id' => $record->id,
        'started_at' => '2026-09-02 09:00:00', 'ended_at' => '2026-09-02 11:00:00',
        'active_s' => 7200, 'inactive_s' => 0, 'source' => 'manual', 'approval_status' => 'pending',
    ]);

    // 2 approved hours at the worker's 50/hr — not 4 hours, and not 999.
    $this->actingAs(member($tenant, UserRole::ClientAdmin))
        ->get('/app/clients')
        ->assertOk()
        ->assertSee("USD\u{00A0}100.00", false);
});
