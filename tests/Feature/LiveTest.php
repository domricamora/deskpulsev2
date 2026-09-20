<?php

/**
 * `/app/live` and the snapshot it polls.
 *
 * Two properties under test. First, the endpoint is the one thing in the app
 * that WRITES on a read: it closes stale sessions, and nothing else does on a
 * schedule, so a crashed agent stays live forever if that call goes missing.
 * Second, one route serves two different trees — a platform operator sees every
 * tenant, everybody else sees their own scope — and the payload says which.
 *
 * @see docs/migration/live-monitoring.md
 */

use App\Enums\UserRole;
use App\Http\Middleware\ResolveOrganization;
use App\Models\Client;
use App\Models\Screenshot;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\WorkSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** An open session — the only kind the live board shows. */
function live(User $user, ?Client $client = null, ?string $heartbeat = null): WorkSession
{
    return WorkSession::create([
        'user_id'         => $user->id,
        'client_id'       => $client?->id,
        'started_at'      => gmdate('Y-m-d H:i:s', time() - 3600),
        'last_seen_at'    => $heartbeat ?? gmdate('Y-m-d H:i:s'),
        'source'          => 'agent',
        'approval_status' => 'approved',
    ]);
}

function inTeam(User $user, string $name): void
{
    $team = Team::create(['org_id' => $user->org_id, 'name' => $name]);

    TeamMember::create(['team_id' => $team->id, 'user_id' => $user->id]);
}

/* ── The gate ────────────────────────────────────────────────────────────── */

test('a role holding live opens the board', function (UserRole $role) {
    $this->actingAs(member(org(), $role))
        ->get('/app/live')
        ->assertOk()
        ->assertSee('Live team activity');
})->with([UserRole::ClientAdmin, UserRole::Manager]);

test('a role without live is turned away from both routes', function (UserRole $role) {
    $viewer = member(org(), $role);

    $this->actingAs($viewer)->get('/app/live')->assertRedirect();
    $this->actingAs($viewer)->get('/app/live/data')->assertRedirect();
})->with([UserRole::HrManager, UserRole::ItAdmin, UserRole::Member, UserRole::ClientViewer]);

/* ── The snapshot ────────────────────────────────────────────────────────── */

test('an empty board still carries a timestamp', function () {
    // The client renders "updated …" from `now` whether or not anyone is on.
    $this->actingAs(member(org(), UserRole::ClientAdmin))
        ->get('/app/live/data')
        ->assertOk()
        ->assertJsonPath('mode', 'team')
        ->assertJsonPath('total', 0)
        ->assertJsonPath('groups', [])
        ->assertJsonStructure(['now']);
});

test('only open sessions appear', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $working = member($organization, UserRole::Member, ['name' => 'Still Here']);
    $finished = member($organization, UserRole::Member, ['name' => 'Gone Home']);

    live($working);

    WorkSession::create([
        'user_id'         => $finished->id,
        'started_at'      => gmdate('Y-m-d H:i:s', time() - 7200),
        'ended_at'        => gmdate('Y-m-d H:i:s', time() - 3600),
        'source'          => 'agent',
        'approval_status' => 'approved',
    ]);

    $response = $this->actingAs($admin)->get('/app/live/data')->assertOk();

    expect($response->json('total'))->toBe(1);
    $response->assertSee('Still Here')->assertDontSee('Gone Home');
});

test('a card carries the latest window, activity and day so far', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member, ['name' => 'Ava']);
    $session = live($worker);

    // Two of each: the newest must win, not the first or the last inserted.
    foreach ([['09:00:00', 'Old.exe', 5], ['09:30:00', 'Code.exe', 73]] as [$time, $app, $pct]) {
        DB::table('window_events')->insert([
            'session_id' => $session->id, 'ts' => date('Y-m-d ') . $time,
            'app_name' => $app, 'window_title' => $app . ' — window', 'focus_seconds' => 60,
        ]);
        DB::table('activity_samples')->insert([
            'session_id' => $session->id, 'ts' => date('Y-m-d ') . $time,
            'keyboard_count' => 1, 'mouse_count' => 1, 'activity_pct' => $pct,
        ]);
    }

    $card = $this->actingAs($admin)->get('/app/live/data')
        ->assertOk()
        ->json('groups.0.teams.0.members.0');

    expect($card['name'])->toBe('Ava')
        ->and($card['app'])->toBe('Code.exe')
        ->and($card['activity'])->toBe(73)
        ->and($card)->toHaveKeys(['since', 'client', 'today_active', 'today_pct', 'screenshot']);
});

test('a session with no client is grouped under the direct label', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);

    live(member($organization, UserRole::Member));

    $this->actingAs($admin)->get('/app/live/data')
        ->assertOk()
        ->assertJsonPath('groups.0.client', 'No client / direct')
        ->assertJsonPath('groups.0.teams.0.team', 'No team');
});

test('groups are sorted by client then team', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);

    $zebra = Client::create(['org_id' => $organization->id, 'name' => 'Zebra Ltd']);
    $acme = Client::create(['org_id' => $organization->id, 'name' => 'Acme Ltd']);

    $one = member($organization, UserRole::Member, ['name' => 'One']);
    $two = member($organization, UserRole::Member, ['name' => 'Two']);

    inTeam($one, 'Support');
    inTeam($two, 'Delivery');

    live($one, $zebra);
    live($two, $acme);

    $response = $this->actingAs($admin)->get('/app/live/data')->assertOk();

    expect($response->json('groups.0.client'))->toBe('Acme Ltd')
        ->and($response->json('groups.1.client'))->toBe('Zebra Ltd')
        ->and($response->json('groups.0.teams.0.team'))->toBe('Delivery');
});

/* ── Scope ───────────────────────────────────────────────────────────────── */

test('one tenant never appears in another tenant board', function () {
    $ours = org(['name' => 'Acme']);
    $theirs = org(['name' => 'Globex']);

    live(member($ours, UserRole::Member, ['name' => 'Our Worker']));
    live(member($theirs, UserRole::Member, ['name' => 'Their Worker']));

    $this->actingAs(member($ours, UserRole::ClientAdmin))
        ->get('/app/live/data')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertSee('Our Worker')
        ->assertDontSee('Their Worker');
});

test('a team manager sees their own team and no one else', function () {
    $organization = org();
    $manager = member($organization, UserRole::Manager);
    $mine = member($organization, UserRole::Member, ['name' => 'On My Team']);
    $theirs = member($organization, UserRole::Member, ['name' => 'Another Team']);

    $team = Team::create(['org_id' => $organization->id, 'name' => 'Support']);
    TeamMember::create(['team_id' => $team->id, 'user_id' => $manager->id]);
    TeamMember::create(['team_id' => $team->id, 'user_id' => $mine->id]);
    inTeam($theirs, 'Delivery');

    live($mine);
    live($theirs);

    $this->actingAs($manager)->get('/app/live/data')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertSee('On My Team')
        ->assertDontSee('Another Team');
});

/* ── The platform branch ─────────────────────────────────────────────────── */

test('a platform operator sees every tenant, grouped by organization', function () {
    $platform = org(['name' => 'DeskPulse platform']);
    $super = member($platform, UserRole::SuperAdmin);

    $acme = org(['name' => 'Acme']);
    $globex = org(['name' => 'Globex']);

    live(member($acme, UserRole::Member, ['name' => 'Acme Worker']));
    live(member($globex, UserRole::Member, ['name' => 'Globex Worker']));

    $response = $this->actingAs($super)->get('/app/live/data')->assertOk();

    expect($response->json('mode'))->toBe('platform')
        ->and($response->json('total'))->toBe(2)
        ->and($response->json('orgs.0.org'))->toBe('Acme')
        ->and($response->json('orgs.1.org'))->toBe('Globex');
});

test('a platform operator acting as a tenant gets that tenant team view', function () {
    $platform = org(['name' => 'DeskPulse platform']);
    $super = member($platform, UserRole::SuperAdmin);
    $tenant = org(['name' => 'Acme']);

    live(member($tenant, UserRole::Member, ['name' => 'Acme Worker']));
    live(member(org(['name' => 'Globex']), UserRole::Member, ['name' => 'Globex Worker']));

    $this->actingAs($super)
        ->withSession([ResolveOrganization::SESSION_KEY => $tenant->id])
        ->get('/app/live/data')
        ->assertOk()
        ->assertJsonPath('mode', 'team')
        ->assertJsonPath('total', 1)
        ->assertSee('Acme Worker')
        ->assertDontSee('Globex Worker');
});

test('a platform operator is not shown as an agent of anyone', function () {
    // They have no tracked time of their own and must not appear inside a
    // customer's monitoring data.
    $platform = org(['name' => 'DeskPulse platform']);
    $super = member($platform, UserRole::SuperAdmin, ['name' => 'Operator']);

    live($super);
    live(member(org(['name' => 'Acme']), UserRole::Member, ['name' => 'Acme Worker']));

    $this->actingAs($super)->get('/app/live/data')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertDontSee('Operator');
});

/* ── Screenshots on cards ────────────────────────────────────────────────── */

test('a card carries the latest screenshot when the viewer may see imagery', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member);
    $session = live($worker);

    Screenshot::create([
        'session_id' => $session->id, 'ts' => gmdate('Y-m-d H:i:s', time() - 600),
        'file_path' => $worker->id . '/' . $session->id . '/old.png', 'blurred' => 0,
    ]);
    $newest = Screenshot::create([
        'session_id' => $session->id, 'ts' => gmdate('Y-m-d H:i:s'),
        'file_path' => $worker->id . '/' . $session->id . '/new.png', 'blurred' => 0,
    ]);

    $this->actingAs($admin)->get('/app/live/data')
        ->assertOk()
        ->assertJsonPath('groups.0.teams.0.members.0.screenshot', url("/app/screenshots/{$newest->id}/image"));
});

/* ── The write on the read ───────────────────────────────────────────────── */

test('a poll closes a stale session, and the next poll no longer shows it', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $crashed = member($organization, UserRole::Member, ['name' => 'Crashed Agent']);

    $heartbeat = gmdate('Y-m-d H:i:s', time() - 3600);
    $session = live($crashed, null, $heartbeat);

    // The first poll still shows them — it closes the session before reading,
    // so they are gone from this very response.
    $this->actingAs($admin)->get('/app/live/data')
        ->assertOk()
        ->assertJsonPath('total', 0);

    // Closed at the last heartbeat, not at now: a crash must not bank hours.
    expect(DB::table('sessions')->where('id', $session->id)->value('ended_at'))->toBe($heartbeat);
});

/* ── Cost ────────────────────────────────────────────────────────────────── */

test('the query count does not grow with the number of live agents', function () {
    // The legacy builds every card with four per-session queries. At fifteen
    // second intervals from every open dashboard, that is the difference
    // between a board and an outage.
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);

    $count = function () use ($admin) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get('/app/live/data')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    live(member($organization, UserRole::Member));

    // Discarded: the first poll of a process also resolves the organization's
    // period config, which is memoised for the life of the request container
    // and would otherwise show up as a saving rather than a cost.
    $count();

    $withOne = $count();

    for ($i = 0; $i < 9; $i++) {
        live(member($organization, UserRole::Member));
    }
    $withTen = $count();

    expect($withTen)->toBe($withOne, "1 agent: {$withOne} queries, 10 agents: {$withTen}");
});
