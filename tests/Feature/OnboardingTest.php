<?php

/**
 * Phase 17 — the setup wizards and the first-run redirect.
 *
 * Two wizards live behind `/app/onboarding`, and which one renders is decided
 * by role rather than by path. The redirect that sends people there is
 * middleware rather than part of the nav composer, so the tests that matter
 * are the ones proving it stops: a rule that never stops is a lockout.
 *
 * @see docs/migration/routes.md §5
 */

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** Put somebody on a team, creating it, the way the roster wizard does. */
function teamWith(\App\Models\Organization $organization, User ...$people): Team
{
    $team = Team::create(['org_id' => $organization->id, 'name' => 'Squad']);

    foreach ($people as $person) {
        DB::table('team_members')->insert(['team_id' => $team->id, 'user_id' => $person->id]);
    }

    return $team;
}

/* ── Which wizard, and for whom ──────────────────────────────────────────── */

test('a company admin gets the five-step setup', function () {
    $this->actingAs(member(org(), UserRole::ClientAdmin))
        ->get('/app/onboarding')
        ->assertOk()
        ->assertSee('Set up DeskPulse')
        ->assertSee('Your organization')
        ->assertDontSee('Build your team');
});

test('a team manager gets the roster wizard instead', function () {
    $this->actingAs(member(org(), UserRole::Manager))
        ->get('/app/onboarding')
        ->assertOk()
        ->assertSee('Build your team')
        ->assertSee('Your roster')
        ->assertDontSee('Set up DeskPulse');
});

test('everybody else is sent to their overview', function () {
    $organization = org();

    foreach ([UserRole::Member, UserRole::HrManager, UserRole::ItAdmin, UserRole::ClientViewer] as $role) {
        $this->actingAs(member($organization, $role))
            ->get('/app/onboarding')
            ->assertRedirect('/app/overview');
    }
});

test('a role with no wizard cannot drive one by posting at it', function () {
    $organization = org();

    $this->actingAs(member($organization, UserRole::ItAdmin))
        ->post('/app/onboarding', ['action' => 'add_account', 'name' => 'X', 'email' => 'x@example.test'])
        ->assertForbidden();

    expect(User::query()->where('email', 'x@example.test')->exists())->toBeFalse();
});

/* ── The company admin's steps ───────────────────────────────────────────── */

test('saving the organization clamps the policy and advances to clients', function () {
    $organization = org(['name' => 'Before']);

    $this->actingAs(member($organization, UserRole::ClientAdmin))
        ->post('/app/onboarding', [
            'action'                  => 'save_org',
            'step'                    => 'org',
            'name'                    => 'Acme Outsourcing',
            'screenshot_interval_min' => 9999,
            'idle_threshold_min'      => 0,
            'track_screenshots'       => 'on',
        ])
        // save_org is the one action that advances on its own.
        ->assertRedirect('/app/onboarding?step=clients');

    $organization->refresh();

    expect($organization->name)->toBe('Acme Outsourcing')
        ->and($organization->screenshot_interval_min)->toBe(120)
        ->and($organization->idle_threshold_min)->toBe(1)
        ->and((int) $organization->track_windows)->toBe(0);
});

test('a blank organization name keeps the existing one', function () {
    $organization = org(['name' => 'Acme']);

    $this->actingAs(member($organization, UserRole::ClientAdmin))
        ->post('/app/onboarding', ['action' => 'save_org', 'step' => 'org', 'name' => '   ']);

    expect($organization->fresh()->name)->toBe('Acme');
});

test('a client with a contact email also gets a portal login', function () {
    $organization = org();

    $this->actingAs(member($organization, UserRole::ClientAdmin))
        ->post('/app/onboarding', [
            'action'        => 'add_client',
            'step'          => 'clients',
            'name'          => 'Globex',
            'contact_email' => 'AP@globex.test',
        ])
        ->assertRedirect('/app/onboarding?step=clients');

    $client = Client::query()->where('org_id', $organization->id)->first();

    expect($client->name)->toBe('Globex')
        ->and($client->contact_email)->toBe('ap@globex.test')
        ->and($client->user_id)->not->toBeNull();

    $portal = User::query()->whereKey($client->user_id)->first();

    expect($portal->role)->toBe(UserRole::ClientViewer)
        ->and((int) $portal->must_change_password)->toBe(1);
});

test('a client without a contact email gets no login', function () {
    $organization = org();

    $this->actingAs(member($organization, UserRole::ClientAdmin))
        ->post('/app/onboarding', ['action' => 'add_client', 'step' => 'clients', 'name' => 'Initech']);

    expect(Client::query()->where('name', 'Initech')->value('user_id'))->toBeNull();
});

test('a team member can only be assigned from the same tenant', function () {
    $ours = org(['name' => 'Acme']);
    $admin = member($ours, UserRole::ClientAdmin);
    $team = Team::create(['org_id' => $ours->id, 'name' => 'Support']);
    $outsider = member(org(['name' => 'Globex']), UserRole::Member);

    $this->actingAs($admin)->post('/app/onboarding', [
        'action' => 'add_team_member', 'step' => 'teams',
        'team_id' => $team->id, 'user_id' => $outsider->id,
    ]);

    expect(DB::table('team_members')->where('team_id', $team->id)->count())->toBe(0);

    $ourMember = member($ours, UserRole::Member);

    $this->actingAs($admin)->post('/app/onboarding', [
        'action' => 'add_team_member', 'step' => 'teams',
        'team_id' => $team->id, 'user_id' => $ourMember->id,
    ]);

    expect(DB::table('team_members')->where('team_id', $team->id)->count())->toBe(1);

    // Assigning twice is not two rows.
    $this->actingAs($admin)->post('/app/onboarding', [
        'action' => 'add_team_member', 'step' => 'teams',
        'team_id' => $team->id, 'user_id' => $ourMember->id,
    ]);

    expect(DB::table('team_members')->where('team_id', $team->id)->count())->toBe(1);
});

test('the wizard cannot mint a company admin or a portal login', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);

    foreach (['client_admin', 'super_admin', 'client_viewer'] as $attempted) {
        $email = $attempted . '@example.test';

        $this->actingAs($admin)->post('/app/onboarding', [
            'action' => 'add_account', 'step' => 'agents',
            'name' => 'Nope', 'email' => $email, 'role' => $attempted,
        ]);

        // Created, but demoted to member — the role list is a whitelist.
        expect(User::query()->where('email', $email)->value('role'))->toBe(UserRole::Member);
    }
});

test('a duplicate email creates nobody', function () {
    $organization = org();
    $existing = member($organization, UserRole::Member, ['email' => 'taken@example.test']);

    $this->actingAs(member($organization, UserRole::ClientAdmin))
        ->post('/app/onboarding', [
            'action' => 'add_account', 'step' => 'agents',
            'name' => 'Impostor', 'email' => 'taken@example.test',
        ]);

    expect(User::query()->where('email', 'taken@example.test')->count())->toBe(1)
        ->and(User::query()->where('email', 'taken@example.test')->value('name'))->toBe($existing->name);
});

test('a blank password is a random one, not an empty one', function () {
    $organization = org();

    $this->actingAs(member($organization, UserRole::ClientAdmin))
        ->post('/app/onboarding', [
            'action' => 'add_account', 'step' => 'agents',
            'name' => 'Ana', 'email' => 'ana@example.test', 'password' => '',
        ]);

    $created = User::query()->where('email', 'ana@example.test')->first();

    expect($created->password_hash)->not->toBeEmpty()
        ->and(\Illuminate\Support\Facades\Hash::check('', $created->password_hash))->toBeFalse();
});

test('only agents and team managers can own a task', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $hr = member($organization, UserRole::HrManager);
    $worker = member($organization, UserRole::Member);

    $this->actingAs($admin)->post('/app/onboarding', [
        'action' => 'add_task', 'step' => 'tasks', 'title' => 'For HR', 'user_id' => $hr->id,
    ]);

    expect(Task::query()->count())->toBe(0);

    $this->actingAs($admin)->post('/app/onboarding', [
        'action' => 'add_task', 'step' => 'tasks', 'title' => 'Real work', 'user_id' => $worker->id,
    ]);

    expect(Task::query()->count())->toBe(1)
        ->and(Task::query()->value('user_id'))->toBe($worker->id);
});

test('a task cannot be tagged to another tenant client', function () {
    $ours = org(['name' => 'Acme']);
    $worker = member($ours, UserRole::Member);
    $theirClient = Client::create(['org_id' => org(['name' => 'Globex'])->id, 'name' => 'Not ours']);

    $this->actingAs(member($ours, UserRole::ClientAdmin))->post('/app/onboarding', [
        'action' => 'add_task', 'step' => 'tasks', 'title' => 'Work',
        'user_id' => $worker->id, 'client_id' => $theirClient->id,
    ]);

    // Created, but with no client rather than a foreign one.
    expect(Task::query()->value('client_id'))->toBeNull();
});

test('finishing stamps onboarded_at and skipping does the same', function () {
    $organization = org(['onboarded_at' => null]);

    $this->actingAs(member($organization, UserRole::ClientAdmin))
        ->post('/app/onboarding', ['action' => 'finish', 'step' => 'done'])
        ->assertRedirect('/app/overview');

    expect($organization->fresh()->onboarded_at)->not->toBeNull();
});

/* ── The team manager's roster wizard ────────────────────────────────────── */

test('adding an agent creates the manager team on demand', function () {
    $organization = org();
    $manager = member($organization, UserRole::Manager);

    expect(Team::query()->count())->toBe(0);

    $this->actingAs($manager)->post('/app/onboarding', [
        'action' => 'add_agent', 'step' => 'roster',
        'name' => 'Ana Cruz', 'email' => 'ana@example.test',
    ])->assertRedirect('/app/onboarding?step=roster');

    $team = Team::query()->first();
    $agent = User::query()->where('email', 'ana@example.test')->first();

    expect($team->name)->toContain("'s Team")
        ->and($agent->role)->toBe(UserRole::Member)
        // Both the manager and the new agent are on it, or the manager sees
        // nobody.
        ->and(DB::table('team_members')->where('team_id', $team->id)->count())->toBe(2);
});

test('a manager can only ever create members', function () {
    $manager = member(org(), UserRole::Manager);

    $this->actingAs($manager)->post('/app/onboarding', [
        'action' => 'add_agent', 'step' => 'roster',
        'name' => 'Climber', 'email' => 'climber@example.test', 'role' => 'client_admin',
    ]);

    expect(User::query()->where('email', 'climber@example.test')->value('role'))
        ->toBe(UserRole::Member);
});

test('a manager cannot assign a task outside their roster', function () {
    $organization = org();
    $manager = member($organization, UserRole::Manager);
    $mine = member($organization, UserRole::Member, ['name' => 'Ana']);
    $notMine = member($organization, UserRole::Member, ['name' => 'Bo']);

    teamWith($organization, $manager, $mine);

    $this->actingAs($manager)->post('/app/onboarding', [
        'action' => 'add_task', 'step' => 'tasks', 'title' => 'Not yours', 'user_id' => $notMine->id,
    ]);

    expect(Task::query()->count())->toBe(0);

    $this->actingAs($manager)->post('/app/onboarding', [
        'action' => 'add_task', 'step' => 'tasks', 'title' => 'Ours', 'user_id' => $mine->id,
    ]);

    expect(Task::query()->count())->toBe(1);
});

test('finishing the roster wizard stamps welcomed_at', function () {
    $manager = member(org(), UserRole::Manager, ['welcomed_at' => null]);

    $this->actingAs($manager)
        ->post('/app/onboarding', ['action' => 'finish', 'step' => 'done'])
        ->assertRedirect('/app/overview');

    expect($manager->fresh()->welcomed_at)->not->toBeNull();
});

/* ── The first-run redirect, and where it stops ──────────────────────────── */

test('a brand-new company admin is pushed into setup', function () {
    $organization = org(['onboarded_at' => null]);

    $this->actingAs(member($organization, UserRole::ClientAdmin))
        ->get('/app/overview')
        ->assertRedirect('/app/onboarding');
});

test('setup stops forcing once it is finished, even with an empty roster', function () {
    $organization = org(['onboarded_at' => now()]);

    $this->actingAs(member($organization, UserRole::ClientAdmin))
        ->get('/app/overview')
        ->assertOk();
});

test('a set-up organization opening /app/onboarding bare is sent away, but ?step= reopens it', function () {
    $organization = org(['onboarded_at' => now()]);
    $admin = member($organization, UserRole::ClientAdmin);
    member($organization, UserRole::Member);     // somebody besides the admin

    $this->actingAs($admin)->get('/app/onboarding')->assertRedirect('/app/overview');
    $this->actingAs($admin)->get('/app/onboarding?step=clients')->assertOk()->assertSee('Clients &amp; companies', false);
});

test('an onboarded organization with nobody in it does not ping-pong', function () {
    // Onboarded but empty: the bounce must NOT fire, or the overview sends
    // them back here and the wizard sends them to the overview, forever.
    $organization = org(['onboarded_at' => now()]);

    $this->actingAs(member($organization, UserRole::ClientAdmin))
        ->get('/app/onboarding')
        ->assertOk();
});

test('a manager with no roster is pushed into the roster wizard, one with a roster is not', function () {
    $organization = org();
    $alone = member($organization, UserRole::Manager, ['welcomed_at' => null]);

    $this->actingAs($alone)->get('/app/overview')->assertRedirect('/app/onboarding');

    $staffed = member($organization, UserRole::Manager, ['welcomed_at' => null]);
    teamWith($organization, $staffed, member($organization, UserRole::Member));

    $this->actingAs($staffed)->get('/app/overview')->assertOk();
});

test('all four guide roles are forced to it, and nobody else is', function () {
    $organization = org();

    foreach ([UserRole::Member, UserRole::HrManager, UserRole::ItAdmin, UserRole::ClientViewer] as $role) {
        $this->actingAs(member($organization, $role, ['welcomed_at' => null]))
            ->get('/app/timesheets')
            ->assertRedirect('/app/welcome');
    }

    // A company admin and a team manager get a WIZARD, not the guide.
    $this->actingAs(member(org(['onboarded_at' => null]), UserRole::ClientAdmin))
        ->get('/app/timesheets')
        ->assertRedirect('/app/onboarding');
});

test('the role guide is forced until it is dismissed', function () {
    $organization = org();
    $user = member($organization, UserRole::Member, ['welcomed_at' => null]);

    $this->actingAs($user)->get('/app/timesheets')->assertRedirect('/app/welcome');

    $user->forceFill(['welcomed_at' => now()])->save();

    $this->actingAs($user->fresh())->get('/app/timesheets')->assertOk();
});

test('a destination never redirects to itself', function () {
    $organization = org();

    $this->actingAs(member($organization, UserRole::Member, ['welcomed_at' => null]))
        ->get('/app/welcome')
        ->assertOk();

    $this->actingAs(member(org(['onboarded_at' => null]), UserRole::ClientAdmin))
        ->get('/app/onboarding')
        ->assertOk();

    // /app/change-password is outside the gated group entirely, so a forced
    // rotation completes before any of this applies.
    // /app/subscription is exempt in the middleware for the same reason a
    // lapsed tenant is SENT there by gate 4 — that test arrives with the page,
    // in Phase 17c.
});

test('a POST is never redirected into a wizard, because that would discard it', function () {
    $organization = org();
    $user = member($organization, UserRole::Member, ['welcomed_at' => null]);

    // Whatever this page answers, it must not be the guide — the submitted
    // work would vanish.
    $response = $this->actingAs($user)->post('/app/tasks', ['action' => 'add', 'title' => 'Real work']);

    expect($response->headers->get('Location'))->not->toContain('/app/welcome');
    expect(Task::query()->where('title', 'Real work')->exists())->toBeTrue();
});

test('a platform operator is never dragged into a tenant first run', function () {
    $platform = org(['name' => 'DeskPulse platform']);

    $this->actingAs(member($platform, UserRole::SuperAdmin, ['welcomed_at' => null]))
        ->get('/app')
        ->assertRedirect('/app/platform');
});
