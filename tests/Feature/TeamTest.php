<?php

/**
 * `/app/team` — accounts, roles, schedules, employment terms and rates.
 *
 * Most of this file is about one thing: that seeing the page and changing it
 * are separate permissions, and that the three capabilities which govern
 * changes (users_manage, profiles_manage, set_pay_rate) are checked
 * independently rather than collapsed into one "can edit" flag.
 *
 * The HR admin is the case that proves it. They can SET a pay rate and cannot
 * VIEW rates; they can edit a profile and cannot create an account; and they
 * can never touch a bill rate.
 *
 * @see docs/migration/authorization.md
 */

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/* ── Reaching the page ───────────────────────────────────────────────────── */

test('the page needs view_all', function (UserRole $role, bool $allowed) {
    $tenant = org();
    $response = $this->actingAs(member($tenant, $role))->get('/app/team');

    $allowed
        ? $response->assertOk()
        : $response->assertRedirect('/app');
})->with([
    'company admin' => [UserRole::ClientAdmin, true],
    'HR admin'      => [UserRole::HrManager, true],
    'IT admin'      => [UserRole::ItAdmin, true],
    // A team manager holds view_team, not view_all.
    'team manager'  => [UserRole::Manager, false],
    'employee'      => [UserRole::Member, false],
    'client portal' => [UserRole::ClientViewer, false],
]);

test('a platform operator is sent to the console', function () {
    $platform = org(['name' => 'DeskPulse platform']);

    $this->actingAs(member($platform, UserRole::SuperAdmin))
        ->get('/app/team')
        ->assertRedirect('/app/platform');
});

/* ── What each viewer is shown ───────────────────────────────────────────── */

test('an admin sees both rate columns and HR sees only the pay column', function () {
    $tenant = org();

    $this->actingAs(member($tenant, UserRole::ClientAdmin))->get('/app/team')
        ->assertOk()
        ->assertSee('Pay (cost)')
        ->assertSee('Bill (client)')
        ->assertSee('Labor cost');

    // set_pay_rate without view_rates: the column they write is there, the
    // two they may not read are not.
    $this->actingAs(member($tenant, UserRole::HrManager))->get('/app/team')
        ->assertOk()
        ->assertSee('Pay (cost)')
        ->assertDontSee('Bill (client)')
        ->assertDontSee('Labor cost');
});

test('an IT admin sees no rates at all', function () {
    $tenant = org();

    $this->actingAs(member($tenant, UserRole::ItAdmin))->get('/app/team')
        ->assertOk()
        ->assertDontSee('Pay (cost)')
        ->assertDontSee('Bill (client)');
});

test('the create and teams panels appear only with users_manage', function () {
    $tenant = org();

    $this->actingAs(member($tenant, UserRole::ClientAdmin))->get('/app/team')
        ->assertOk()
        ->assertSee('Add a team member')
        ->assertSee('Teams &amp; membership', false);

    $this->actingAs(member($tenant, UserRole::HrManager))->get('/app/team')
        ->assertOk()
        ->assertDontSee('Add a team member');
});

test('a platform operator is never listed as one of a tenant members', function () {
    $platform = org(['name' => 'DeskPulse platform']);
    member($platform, UserRole::SuperAdmin, ['name' => 'Operator']);

    $tenant = org(['name' => 'Acme']);

    $this->actingAs(member($tenant, UserRole::ClientAdmin))->get('/app/team')
        ->assertOk()
        ->assertDontSee('Operator');
});

/* ── Creating accounts ───────────────────────────────────────────────────── */

test('an admin creates a member', function () {
    $tenant = org();

    $this->actingAs(member($tenant, UserRole::ClientAdmin))
        ->post('/app/team', [
            'action'   => 'create_user',
            'name'     => 'Dana Reyes',
            'email'    => 'Dana@Acme.Test',
            'password' => 'temporary-one',
            'role'     => 'manager',
            'pay_type' => 'monthly',
            'pay_rate' => '4200',
            'currency' => 'PHP',
        ])
        ->assertRedirect('/app/team');

    $created = User::where('email', 'dana@acme.test')->first();

    expect($created)->not->toBeNull()
        ->and($created->name)->toBe('Dana Reyes')
        ->and($created->role)->toBe(UserRole::Manager)
        ->and($created->org_id)->toBe((int) $tenant->id)
        ->and($created->pay_type)->toBe('monthly')
        ->and((float) $created->pay_rate)->toBe(4200.0)
        ->and(Hash::check('temporary-one', $created->password_hash))->toBeTrue();
});

test('a blank temporary password becomes a random one, never an empty hash', function () {
    $tenant = org();

    $this->actingAs(member($tenant, UserRole::ClientAdmin))
        ->post('/app/team', [
            'action' => 'create_user', 'name' => 'No Password', 'email' => 'np@acme.test', 'password' => '',
        ]);

    $created = User::where('email', 'np@acme.test')->firstOrFail();

    expect($created->password_hash)->not->toBeEmpty()
        ->and(Hash::check('', $created->password_hash))->toBeFalse();
});

test('a duplicate email is refused, including across organizations', function () {
    // users.email is unique globally because it is the login. Allowing a
    // second account with the same address would make sign-in ambiguous.
    $other = org(['name' => 'Other Co']);
    member($other, UserRole::Member, ['email' => 'taken@example.test']);

    $tenant = org();

    $this->actingAs(member($tenant, UserRole::ClientAdmin))
        ->post('/app/team', [
            'action' => 'create_user', 'name' => 'Clash', 'email' => 'taken@example.test',
        ]);

    expect(User::where('email', 'taken@example.test')->count())->toBe(1);
});

test('a client portal login cannot be created here', function () {
    // Portal logins are provisioned on the Clients page, attached to a client.
    // One created here would have no client and would therefore see nothing.
    $tenant = org();

    $this->actingAs(member($tenant, UserRole::ClientAdmin))
        ->post('/app/team', [
            'action' => 'create_user', 'name' => 'Stray', 'email' => 'stray@acme.test',
            'role' => 'client_viewer',
        ]);

    expect(User::where('email', 'stray@acme.test')->firstOrFail()->role)->toBe(UserRole::Member);
});

test('a tenant cannot promote anyone to platform operator', function () {
    $tenant = org();

    $this->actingAs(member($tenant, UserRole::ClientAdmin))
        ->post('/app/team', [
            'action' => 'create_user', 'name' => 'Escalation', 'email' => 'esc@acme.test',
            'role' => 'super_admin',
        ]);

    expect(User::where('email', 'esc@acme.test')->firstOrFail()->role)->toBe(UserRole::Member);
});

test('the solo plan refuses a second seat and sells an upgrade', function () {
    $tenant = org(['plan_type' => 'solo']);
    $admin = member($tenant, UserRole::ClientAdmin);

    $this->actingAs($admin)
        ->post('/app/team', [
            'action' => 'create_user', 'name' => 'Second', 'email' => 'second@acme.test',
        ])
        ->assertRedirect('/app/subscription');

    expect(User::where('email', 'second@acme.test')->exists())->toBeFalse();
});

test('a paid plan has no seat cap', function () {
    $tenant = org(['plan_type' => 'organization']);

    $this->actingAs(member($tenant, UserRole::ClientAdmin))
        ->post('/app/team', [
            'action' => 'create_user', 'name' => 'Second', 'email' => 'second@acme.test',
        ])
        ->assertRedirect('/app/team');

    expect(User::where('email', 'second@acme.test')->exists())->toBeTrue();
});

/* ── Who may change what ─────────────────────────────────────────────────── */

test('HR cannot create an account', function () {
    $tenant = org();

    $this->actingAs(member($tenant, UserRole::HrManager))
        ->post('/app/team', [
            'action' => 'create_user', 'name' => 'Nope', 'email' => 'nope@acme.test',
        ])
        ->assertForbidden();

    expect(User::where('email', 'nope@acme.test')->exists())->toBeFalse();
});

test('HR edits a profile and sets a pay rate', function () {
    $tenant = org();
    $hr = member($tenant, UserRole::HrManager);
    $worker = member($tenant, UserRole::Member, ['name' => 'Before']);

    $this->actingAs($hr)->post('/app/team', [
        'action'    => 'update_profile',
        'user_id'   => $worker->id,
        'name'      => 'After',
        'email'     => 'after@acme.test',
        'phone'     => '+63 900 000 0000',
        'job_title' => 'Analyst',
        'pay_type'  => 'hourly',
        'pay_rate'  => '12.50',
        'currency'  => 'USD',
    ])->assertRedirect('/app/team');

    $worker->refresh();

    expect($worker->name)->toBe('After')
        ->and($worker->email)->toBe('after@acme.test')
        ->and($worker->job_title)->toBe('Analyst')
        ->and((float) $worker->pay_rate)->toBe(12.5);
});

test('HR cannot set a bill rate even by posting one', function () {
    // The field is not on their form. Posting it anyway must not write it —
    // what a client is charged is not HR's to decide.
    $tenant = org();
    $worker = member($tenant, UserRole::Member, ['bill_rate' => 40.0]);

    $this->actingAs(member($tenant, UserRole::HrManager))->post('/app/team', [
        'action' => 'update_profile', 'user_id' => $worker->id,
        'name' => 'Same', 'email' => 'same@acme.test',
        'bill_rate' => '999',
    ]);

    expect((float) $worker->fresh()->bill_rate)->toBe(40.0);
});

test('HR cannot change a role even by posting one', function () {
    $tenant = org();
    $worker = member($tenant, UserRole::Member);

    $this->actingAs(member($tenant, UserRole::HrManager))->post('/app/team', [
        'action' => 'update_profile', 'user_id' => $worker->id,
        'name' => 'Same', 'email' => 'same2@acme.test',
        'role' => 'client_admin',
    ]);

    expect($worker->fresh()->role)->toBe(UserRole::Member);
});

test('an IT admin can neither manage accounts nor edit profiles', function () {
    // it_admin holds view_all — enough to open the page, nothing more.
    $tenant = org();
    $worker = member($tenant, UserRole::Member, ['name' => 'Untouched']);
    $it = member($tenant, UserRole::ItAdmin);

    $this->actingAs($it)->post('/app/team', [
        'action' => 'update_profile', 'user_id' => $worker->id,
        'name' => 'Changed', 'email' => 'changed@acme.test',
    ])->assertForbidden();

    $this->actingAs($it)->post('/app/team', [
        'action' => 'create_user', 'name' => 'New', 'email' => 'new@acme.test',
    ])->assertForbidden();

    expect($worker->fresh()->name)->toBe('Untouched');
});

test('an admin changes a role and both rate pairs', function () {
    $tenant = org();
    $worker = member($tenant, UserRole::Member);

    $this->actingAs(member($tenant, UserRole::ClientAdmin))->post('/app/team', [
        'action'    => 'update_user',
        'user_id'   => $worker->id,
        'role'      => 'hr_manager',
        'pay_type'  => 'monthly',
        'pay_rate'  => '3000',
        'bill_type' => 'monthly',
        'bill_rate' => '5000',
        'currency'  => 'USD',
    ]);

    $worker->refresh();

    expect($worker->role)->toBe(UserRole::HrManager)
        ->and((float) $worker->pay_rate)->toBe(3000.0)
        // bill_type was rendered on the legacy form but never written; a
        // monthly service charge could not actually be set from the UI.
        ->and($worker->bill_type)->toBe('monthly')
        ->and((float) $worker->bill_rate)->toBe(5000.0);
});

/* ── Tenancy ─────────────────────────────────────────────────────────────── */

test('a user_id from another organization does not resolve', function () {
    $mine = org(['name' => 'Mine']);
    $theirs = org(['name' => 'Theirs']);
    $victim = member($theirs, UserRole::Member, ['name' => 'Theirs Person']);

    $this->actingAs(member($mine, UserRole::ClientAdmin))->post('/app/team', [
        'action' => 'update_user', 'user_id' => $victim->id, 'pay_rate' => '999',
    ])->assertRedirect('/app/team');

    expect((float) $victim->fresh()->pay_rate)->toBe(0.0);
});

test('a team membership in another organization cannot be removed', function () {
    // team_members has no org_id; the join to teams is what makes it tenanted.
    $mine = org(['name' => 'Mine']);
    $theirs = org(['name' => 'Theirs']);

    $theirTeam = Team::create(['org_id' => $theirs->id, 'name' => 'Theirs']);
    $theirPerson = member($theirs, UserRole::Member);
    $membership = TeamMember::create(['team_id' => $theirTeam->id, 'user_id' => $theirPerson->id]);

    $this->actingAs(member($mine, UserRole::ClientAdmin))->post('/app/team', [
        'action' => 'remove_team_member', 'tm_id' => $membership->id,
    ]);

    expect(TeamMember::whereKey($membership->id)->exists())->toBeTrue();
});

test('a team cannot be created in another organization', function () {
    $mine = org(['name' => 'Mine']);
    $theirs = org(['name' => 'Theirs']);

    $this->actingAs(member($mine, UserRole::ClientAdmin))->post('/app/team', [
        'action' => 'create_team', 'team_name' => 'Planted', 'org_id' => $theirs->id,
    ]);

    expect(Team::where('name', 'Planted')->firstOrFail()->org_id)->toBe((int) $mine->id);
});

/* ── Teams ───────────────────────────────────────────────────────────────── */

test('teams are created, joined and left', function () {
    $tenant = org();
    $admin = member($tenant, UserRole::ClientAdmin);
    $worker = member($tenant, UserRole::Member);

    $this->actingAs($admin)->post('/app/team', ['action' => 'create_team', 'team_name' => 'Alpha']);

    $team = Team::where('name', 'Alpha')->firstOrFail();

    $this->actingAs($admin)->post('/app/team', [
        'action' => 'add_team_member', 'team_id' => $team->id, 'user_id' => $worker->id,
    ]);

    $membership = TeamMember::where('team_id', $team->id)->where('user_id', $worker->id)->firstOrFail();

    $this->actingAs($admin)->post('/app/team', ['action' => 'remove_team_member', 'tm_id' => $membership->id]);

    expect(TeamMember::whereKey($membership->id)->exists())->toBeFalse();
});

test('adding the same person to a team twice is a no-op', function () {
    $tenant = org();
    $admin = member($tenant, UserRole::ClientAdmin);
    $worker = member($tenant, UserRole::Member);
    $team = Team::create(['org_id' => $tenant->id, 'name' => 'Alpha']);

    foreach ([1, 2] as $ignored) {
        $this->actingAs($admin)->post('/app/team', [
            'action' => 'add_team_member', 'team_id' => $team->id, 'user_id' => $worker->id,
        ]);
    }

    expect(TeamMember::where('team_id', $team->id)->where('user_id', $worker->id)->count())->toBe(1);
});

/* ── Schedules and employment terms ──────────────────────────────────────── */

test('a work schedule is saved, and clearing every day falls back to the working week', function () {
    $tenant = org();
    $admin = member($tenant, UserRole::ClientAdmin);
    $worker = member($tenant, UserRole::Member);

    $this->actingAs($admin)->post('/app/team', [
        'action' => 'update_user', 'user_id' => $worker->id,
        'work_start' => '09:00', 'work_end' => '17:30', 'work_days' => ['1', '3', '5'],
    ]);

    expect($worker->fresh()->work_start)->toBe('09:00:00')
        ->and($worker->fresh()->work_end)->toBe('17:30:00')
        ->and($worker->fresh()->work_days)->toBe('1,3,5');

    $this->actingAs($admin)->post('/app/team', [
        'action' => 'update_user', 'user_id' => $worker->id,
        'work_start' => '09:00', 'work_end' => '17:30', 'work_days' => [],
    ]);

    expect($worker->fresh()->work_days)->toBe('1,2,3,4,5');
});

test('an action that posts no schedule leaves the existing one alone', function () {
    // Otherwise every rate change would silently blank somebody's schedule.
    $tenant = org();
    $worker = member($tenant, UserRole::Member, [
        'work_start' => '08:00:00', 'work_end' => '16:00:00', 'work_days' => '1,2,3',
    ]);

    $this->actingAs(member($tenant, UserRole::ClientAdmin))->post('/app/team', [
        'action' => 'update_user', 'user_id' => $worker->id, 'pay_rate' => '10',
    ]);

    expect($worker->fresh()->work_days)->toBe('1,2,3')
        ->and($worker->fresh()->work_start)->toBe('08:00:00');
});

test('a malformed work time is stored as no time rather than as garbage', function () {
    $tenant = org();
    $worker = member($tenant, UserRole::Member);

    $this->actingAs(member($tenant, UserRole::ClientAdmin))->post('/app/team', [
        'action' => 'update_user', 'user_id' => $worker->id,
        'work_start' => 'nine oclock', 'work_end' => '17:00', 'work_days' => ['1'],
    ]);

    expect($worker->fresh()->work_start)->toBeNull()
        ->and($worker->fresh()->work_end)->toBe('17:00:00');
});

test('employment terms are saved and hour caps are clamped', function () {
    $tenant = org();
    $worker = member($tenant, UserRole::Member);

    $this->actingAs(member($tenant, UserRole::ClientAdmin))->post('/app/team', [
        'action' => 'update_user', 'user_id' => $worker->id,
        'employment_type' => 'contractor', 'hired_on' => '2026-03-01',
        'daily_hours_cap' => '8', 'weekly_hours_cap' => '-5', 'period_hours_cap' => '99999',
    ]);

    $worker->refresh();

    expect($worker->employment_type)->toBe('contractor')
        ->and($worker->hired_on->format('Y-m-d'))->toBe('2026-03-01')
        ->and((float) $worker->daily_hours_cap)->toBe(8.0)
        // Negative clamps to 0 (no cap); anything over 999 clamps to 999.
        ->and((float) $worker->weekly_hours_cap)->toBe(0.0)
        ->and((float) $worker->period_hours_cap)->toBe(999.0);
});

test('an unknown employment type falls back to full time', function () {
    $tenant = org();
    $worker = member($tenant, UserRole::Member);

    $this->actingAs(member($tenant, UserRole::ClientAdmin))->post('/app/team', [
        'action' => 'update_user', 'user_id' => $worker->id, 'employment_type' => 'freelance-ish',
    ]);

    expect($worker->fresh()->employment_type)->toBe('full_time');
});

/* ── Public links ────────────────────────────────────────────────────────── */

test('opening the page mints a personal share link for anyone missing one', function () {
    $tenant = org();
    $admin = member($tenant, UserRole::ClientAdmin);
    $worker = member($tenant, UserRole::Member);

    expect(DB::table('share_links')->count())->toBe(0);

    $this->actingAs($admin)->get('/app/team')->assertOk()->assertSee('/share/');

    expect(DB::table('share_links')->where('target_id', $worker->id)->where('scope', 'user')->count())->toBe(1);
});

test('a revoked personal link is replaced rather than left broken', function () {
    $tenant = org();
    $admin = member($tenant, UserRole::ClientAdmin);

    $this->actingAs($admin)->get('/app/team')->assertOk();

    DB::table('share_links')->where('target_id', $admin->id)->update(['revoked' => 1]);

    $this->actingAs($admin)->get('/app/team')->assertOk();

    expect(DB::table('share_links')->where('target_id', $admin->id)->where('revoked', 0)->count())->toBe(1);
});
