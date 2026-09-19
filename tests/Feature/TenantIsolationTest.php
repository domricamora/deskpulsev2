<?php

/**
 * MANDATORY — Organization A must not be able to retrieve Organization B's records.
 *
 * The migration plan §8 requires this test. It deliberately covers all three
 * tenancy paths, because 17 of the 37 tables have no `org_id` and a test that only
 * checked the direct ones would prove nothing about the monitoring data — which is
 * the sensitive half.
 *
 *   direct      org_id            clients, tasks, teams, ...
 *   via user    user_id           sessions, devices, ...
 *   via session session_id        screenshots, activity samples, window events, ...
 *
 * @see docs/migration/database.md §2
 */

use App\Enums\UserRole;
use App\Models\ActivitySample;
use App\Models\Client;
use App\Models\Device;
use App\Models\IdlePeriod;
use App\Models\Organization;
use App\Models\ProcessSnapshot;
use App\Models\Screenshot;
use App\Models\Task;
use App\Models\User;
use App\Models\WindowEvent;
use App\Models\WorkSession;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Build a complete, self-contained tenant: org, member, client, task, session, monitoring rows. */
function makeTenant(string $name): array
{
    $org = Organization::create(['name' => $name]);

    $user = User::create([
        'org_id'        => $org->id,
        'name'          => "$name member",
        'email'         => strtolower($name) . '@example.test',
        'password_hash' => bcrypt('secret'),
        'role'          => UserRole::Member,
    ]);

    $client = Client::create(['org_id' => $org->id, 'name' => "$name client"]);

    $task = Task::create([
        'org_id'  => $org->id,
        'user_id' => $user->id,
        'title'   => "$name task",
    ]);

    $device = Device::create([
        'user_id' => $user->id,
        'name'    => "$name device",
        'secret'  => bin2hex(random_bytes(32)),
    ]);

    $session = WorkSession::create([
        'user_id'         => $user->id,
        'device_id'       => $device->id,
        'client_id'       => $client->id,
        'task_id'         => $task->id,
        'started_at'      => now(),
        'source'          => 'agent',
        'approval_status' => 'approved',
    ]);

    $screenshot = Screenshot::create([
        'session_id' => $session->id,
        'ts'         => now(),
        'file_path'  => "$name/1/abc.png",
    ]);

    $sample = ActivitySample::create([
        'session_id'     => $session->id,
        'ts'             => now(),
        'keyboard_count' => 1,
        'mouse_count'    => 1,
        'activity_pct'   => 50,
    ]);

    $window = WindowEvent::create([
        'session_id'    => $session->id,
        'ts'            => now(),
        'app_name'      => "$name app",
        'window_title'  => "$name window",
        'focus_seconds' => 10,
    ]);

    $process = ProcessSnapshot::create([
        'session_id' => $session->id,
        'ts'         => now(),
        'app_name'   => "$name process",
        'pid'        => 1234,
    ]);

    $idle = IdlePeriod::create([
        'session_id' => $session->id,
        'start_ts'   => now(),
        'end_ts'     => now()->addMinutes(20),
        'duration_s' => 1200,
    ]);

    return compact('org', 'user', 'client', 'task', 'device', 'session',
        'screenshot', 'sample', 'window', 'process', 'idle');
}

beforeEach(function () {
    $this->a = makeTenant('Alpha');
    $this->b = makeTenant('Beta');
});

test('directly scoped tables do not leak across tenants', function () {
    foreach ([Client::class, Task::class] as $model) {
        $ids = $model::forOrganization($this->a['org'])->pluck('id')->all();

        expect($ids)->toContain($this->a[strtolower(class_basename($model))]->id)
            ->and($ids)->not->toContain($this->b[strtolower(class_basename($model))]->id);
    }
});

test('sessions do not leak across tenants', function () {
    // sessions has no org_id — this is the transitive path through user_id.
    $ids = WorkSession::forOrganization($this->a['org'])->pluck('id')->all();

    expect($ids)->toBe([$this->a['session']->id]);
});

test('devices do not leak across tenants', function () {
    $ids = Device::forOrganization($this->a['org'])->pluck('id')->all();

    expect($ids)->toBe([$this->a['device']->id]);
});

test('monitoring data does not leak across tenants', function () {
    // Two hops from a tenant, and the most sensitive data in the product.
    $cases = [
        [Screenshot::class, 'screenshot'],
        [ActivitySample::class, 'sample'],
        [WindowEvent::class, 'window'],
        [ProcessSnapshot::class, 'process'],
        [IdlePeriod::class, 'idle'],
    ];

    foreach ($cases as [$model, $key]) {
        $ids = $model::forOrganization($this->a['org'])->pluck('id')->all();

        expect($ids)->toBe(
            [$this->a[$key]->id],
            class_basename($model) . ' leaked across tenants'
        );
    }
});

test('an organization with no members retrieves nothing', function () {
    // The legacy org_user_ids() returns a [0] sentinel so an empty IN () is never
    // generated. Laravel produces a false predicate instead; the observable result
    // must be the same — no rows, no error.
    $empty = Organization::create(['name' => 'Empty']);

    expect(WorkSession::forOrganization($empty)->count())->toBe(0)
        ->and(Screenshot::forOrganization($empty)->count())->toBe(0)
        ->and(Client::forOrganization($empty)->count())->toBe(0);
});

test('a platform operator is not counted as a member of a tenant', function () {
    // Mirrors org_user_ids(), which filters role <> 'super_admin'. A super admin
    // lives in the platform organization and must not appear in a tenant's scope.
    $super = User::create([
        'org_id'        => $this->a['org']->id,
        'name'          => 'Platform operator',
        'email'         => 'ops@example.test',
        'password_hash' => bcrypt('secret'),
        'role'          => UserRole::SuperAdmin,
    ]);

    $session = WorkSession::create([
        'user_id'         => $super->id,
        'started_at'      => now(),
        'source'          => 'agent',
        'approval_status' => 'approved',
    ]);

    $ids = WorkSession::forOrganization($this->a['org'])->pluck('id')->all();

    expect($ids)->not->toContain($session->id)
        ->and($ids)->toBe([$this->a['session']->id]);
});

test('scoping stays a single query as data grows', function () {
    // The transitive scopes use sub-selects rather than fetching an id array, so
    // they must not degrade into N+1.
    DB::enableQueryLog();

    Screenshot::forOrganization($this->a['org'])->get();

    expect(DB::getQueryLog())->toHaveCount(1);

    DB::disableQueryLog();
});
