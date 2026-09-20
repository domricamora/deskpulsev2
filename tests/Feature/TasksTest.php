<?php

/**
 * `/app/tasks` — what people are working on, and how long it took.
 *
 * The page is login-only and the split is by ROLE: a member owns their task
 * list, everyone else reads it. That is the one place in the app where a role
 * name is the right test, because no capability grants editing someone else's
 * tasks — not even a company admin's.
 */

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Task;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\WorkSession;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function task(int $organizationId, int $userId, array $attributes = []): Task
{
    return Task::create(array_merge([
        'org_id'  => $organizationId,
        'user_id' => $userId,
        'title'   => 'Do the thing',
        'status'  => 'open',
    ], $attributes));
}

/* ── Who may edit ────────────────────────────────────────────────────────── */

test('only a member gets the editable view', function (UserRole $role, bool $editable) {
    $tenant = org();
    $response = $this->actingAs(member($tenant, $role))->get('/app/tasks')->assertOk();

    $editable
        ? $response->assertSee('Add a task')
        : $response->assertSee('read-only view');
})->with([
    'employee'      => [UserRole::Member, true],
    'company admin' => [UserRole::ClientAdmin, false],
    'team manager'  => [UserRole::Manager, false],
    'HR admin'      => [UserRole::HrManager, false],
    'IT admin'      => [UserRole::ItAdmin, false],
    'client portal' => [UserRole::ClientViewer, false],
]);

test('a company admin cannot post a task change', function () {
    // There is no capability for editing someone else's task list, so the
    // most privileged tenant role is refused along with everyone else.
    $tenant = org();
    $worker = member($tenant, UserRole::Member);
    $existing = task($tenant->id, $worker->id, ['title' => 'Untouched']);

    $this->actingAs(member($tenant, UserRole::ClientAdmin))->post('/app/tasks', [
        'action' => 'edit', 'task_id' => $existing->id, 'title' => 'Rewritten',
    ])->assertForbidden();

    expect($existing->fresh()->title)->toBe('Untouched');
});

/* ── Owning your own list ────────────────────────────────────────────────── */

test('a member adds, edits and removes their own tasks', function () {
    $tenant = org();
    $me = member($tenant, UserRole::Member);
    $customer = Client::create(['org_id' => $tenant->id, 'name' => 'Northwind']);

    $this->actingAs($me)->post('/app/tasks', [
        'action' => 'add', 'title' => 'Build login page', 'client_id' => $customer->id,
    ])->assertRedirect('/app/tasks');

    $created = Task::where('title', 'Build login page')->firstOrFail();

    expect($created->user_id)->toBe((int) $me->id)
        ->and($created->client_id)->toBe((int) $customer->id)
        ->and($created->status)->toBe('open');

    $this->actingAs($me)->post('/app/tasks', [
        'action' => 'edit', 'task_id' => $created->id, 'title' => 'Build login page v2',
        'status' => 'done', 'client_id' => '',
    ]);

    expect($created->fresh()->title)->toBe('Build login page v2')
        ->and($created->fresh()->status)->toBe('done')
        ->and($created->fresh()->client_id)->toBeNull();

    $this->actingAs($me)->post('/app/tasks', ['action' => 'delete', 'task_id' => $created->id]);

    expect(Task::whereKey($created->id)->exists())->toBeFalse();
});

test('a blank title is ignored on add and keeps the old one on edit', function () {
    $tenant = org();
    $me = member($tenant, UserRole::Member);

    $this->actingAs($me)->post('/app/tasks', ['action' => 'add', 'title' => '   ']);
    expect(Task::count())->toBe(0);

    $existing = task($tenant->id, $me->id, ['title' => 'Original']);

    $this->actingAs($me)->post('/app/tasks', [
        'action' => 'edit', 'task_id' => $existing->id, 'title' => '',
    ]);

    expect($existing->fresh()->title)->toBe('Original');
});

test('an unknown status becomes open', function () {
    $tenant = org();
    $me = member($tenant, UserRole::Member);
    $existing = task($tenant->id, $me->id, ['status' => 'done']);

    $this->actingAs($me)->post('/app/tasks', [
        'action' => 'edit', 'task_id' => $existing->id, 'title' => 'Same', 'status' => 'blocked',
    ]);

    expect($existing->fresh()->status)->toBe('open');
});

test('the done shortcut closes a task', function () {
    $tenant = org();
    $me = member($tenant, UserRole::Member);
    $existing = task($tenant->id, $me->id);

    $this->actingAs($me)->post('/app/tasks', ['action' => 'done', 'task_id' => $existing->id]);

    expect($existing->fresh()->status)->toBe('done');
});

/* ── Ownership ───────────────────────────────────────────────────────────── */

test('a member cannot touch a colleague task', function () {
    $tenant = org();
    $me = member($tenant, UserRole::Member);
    $colleague = member($tenant, UserRole::Member);
    $theirs = task($tenant->id, $colleague->id, ['title' => 'Theirs']);

    $this->actingAs($me)->post('/app/tasks', [
        'action' => 'edit', 'task_id' => $theirs->id, 'title' => 'Mine now',
    ]);

    $this->actingAs($me)->post('/app/tasks', ['action' => 'delete', 'task_id' => $theirs->id]);

    expect($theirs->fresh()->title)->toBe('Theirs');
});

test('a client from another organization cannot be attached to a task', function () {
    $mine = org(['name' => 'Mine']);
    $theirs = org(['name' => 'Theirs']);
    $theirClient = Client::create(['org_id' => $theirs->id, 'name' => 'Theirs Customer']);

    $this->actingAs(member($mine, UserRole::Member))->post('/app/tasks', [
        'action' => 'add', 'title' => 'Cross tenant', 'client_id' => $theirClient->id,
    ]);

    expect(Task::where('title', 'Cross tenant')->firstOrFail()->client_id)->toBeNull();
});

/* ── Who sees whose tasks ────────────────────────────────────────────────── */

test('a member sees only their own tasks', function () {
    $tenant = org();
    $me = member($tenant, UserRole::Member);
    $colleague = member($tenant, UserRole::Member);

    task($tenant->id, $me->id, ['title' => 'Mine']);
    task($tenant->id, $colleague->id, ['title' => 'Theirs']);

    $this->actingAs($me)->get('/app/tasks')
        ->assertOk()
        ->assertSee('Mine')
        ->assertDontSee('Theirs');
});

test('an admin sees every task in the organization', function () {
    $tenant = org();
    $worker = member($tenant, UserRole::Member, ['name' => 'Dana']);
    task($tenant->id, $worker->id, ['title' => 'Their work']);

    $this->actingAs(member($tenant, UserRole::ClientAdmin))->get('/app/tasks')
        ->assertOk()
        ->assertSee('Their work')
        ->assertSee('Dana');
});

test('a team manager sees their team tasks and not the rest', function () {
    $tenant = org();
    $manager = member($tenant, UserRole::Manager);
    $mine = member($tenant, UserRole::Member);
    $theirs = member($tenant, UserRole::Member);

    $team = Team::create(['org_id' => $tenant->id, 'name' => 'Alpha']);
    TeamMember::create(['team_id' => $team->id, 'user_id' => $manager->id]);
    TeamMember::create(['team_id' => $team->id, 'user_id' => $mine->id]);

    task($tenant->id, $mine->id, ['title' => 'In my team']);
    task($tenant->id, $theirs->id, ['title' => 'Someone else']);

    $this->actingAs($manager)->get('/app/tasks')
        ->assertOk()
        ->assertSee('In my team')
        ->assertDontSee('Someone else');
});

test('a client portal sees only tasks tagged to its own engagement', function () {
    // An untagged task is not "related to this client", so it is excluded
    // rather than shown — the portal is the customer's view of their own work.
    $tenant = org();
    $worker = member($tenant, UserRole::Member);
    $portalUser = member($tenant, UserRole::ClientViewer);

    $theirClient = Client::create([
        'org_id' => $tenant->id, 'name' => 'Northwind', 'user_id' => $portalUser->id,
    ]);
    $otherClient = Client::create(['org_id' => $tenant->id, 'name' => 'Contoso']);

    DB::table('agent_clients')->insert(['client_id' => $theirClient->id, 'agent_id' => $worker->id]);

    task($tenant->id, $worker->id, ['title' => 'For Northwind', 'client_id' => $theirClient->id]);
    task($tenant->id, $worker->id, ['title' => 'For Contoso', 'client_id' => $otherClient->id]);
    task($tenant->id, $worker->id, ['title' => 'Untagged']);

    $this->actingAs($portalUser)->get('/app/tasks')
        ->assertOk()
        ->assertSee('For Northwind')
        ->assertDontSee('For Contoso')
        ->assertDontSee('Untagged');
});

test('a portal login with no client attached sees nothing', function () {
    // clientFilter() returns -1 rather than null: "filter to nothing", not
    // "do not filter". Collapsing the two would show the whole organization.
    $tenant = org();
    $worker = member($tenant, UserRole::Member);
    task($tenant->id, $worker->id, ['title' => 'Should be invisible']);

    $this->actingAs(member($tenant, UserRole::ClientViewer))->get('/app/tasks')
        ->assertOk()
        ->assertDontSee('Should be invisible');
});

/* ── Time per task ───────────────────────────────────────────────────────── */

test('time per task counts approved sessions only, and is scoped to the viewer', function () {
    $tenant = org();
    $me = member($tenant, UserRole::Member);
    $mine = task($tenant->id, $me->id, ['title' => 'Mine']);

    WorkSession::create([
        'user_id' => $me->id, 'task_id' => $mine->id,
        'started_at' => '2026-09-01 09:00:00', 'ended_at' => '2026-09-01 11:00:00',
        'active_s' => 7200, 'inactive_s' => 0, 'source' => 'agent', 'approval_status' => 'approved',
    ]);

    WorkSession::create([
        'user_id' => $me->id, 'task_id' => $mine->id,
        'started_at' => '2026-09-02 09:00:00', 'ended_at' => '2026-09-02 11:00:00',
        'active_s' => 7200, 'inactive_s' => 0, 'source' => 'manual', 'approval_status' => 'pending',
    ]);

    // 2h and one session, not 4h and two.
    $this->actingAs($me)->get('/app/tasks')
        ->assertOk()
        ->assertSee("2h\u{00A0}00m · 1 sess", false);
});
