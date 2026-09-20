<?php

/**
 * `/app/overview` — the dashboard.
 *
 * The property under test throughout is that ONE route renders three different
 * pages, and that which one you get comes from scoping and capabilities rather
 * than from anything in the request.
 *
 * @see docs/migration/routes.md §5
 */

use App\Enums\UserRole;
use App\Models\Task;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\WorkSession;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** A closed, approved session — the only kind that reaches a rollup. */
function tracked(int $userId, string $startedAt, int $active = 3600, int $inactive = 600): WorkSession
{
    return WorkSession::create([
        'user_id'          => $userId,
        'started_at'       => $startedAt,
        'ended_at'         => date('Y-m-d H:i:s', strtotime($startedAt) + $active + $inactive),
        'active_s'         => $active,
        'inactive_s'       => $inactive,
        'source'           => 'agent',
        'approval_status'  => 'approved',
    ]);
}

/** Today in the organization's reporting timezone, which the page opens on. */
function todayAt(string $time = '09:00:00'): string
{
    return date('Y-m-d') . ' ' . $time;
}

/* ── Who lands where ─────────────────────────────────────────────────────── */

test('a platform operator is sent to the console, not a dashboard', function () {
    // The platform organization has no tracked time of its own, so an overview
    // for it would render a page of zeroes.
    $platform = org(['name' => 'DeskPulse platform']);

    $this->actingAs(member($platform, UserRole::SuperAdmin))
        ->get('/app/overview')
        ->assertRedirect('/app/platform');
});

test('a platform operator acting as a tenant gets that tenant dashboard', function () {
    $platform = org(['name' => 'DeskPulse platform']);
    $tenant = org(['name' => 'Acme']);
    $super = member($platform, UserRole::SuperAdmin);

    $this->actingAs($super)
        ->withSession([App\Http\Middleware\ResolveOrganization::SESSION_KEY => $tenant->id])
        ->get('/app/overview')
        ->assertOk()
        ->assertSee('Overview');
});

test('a client portal has no overview and is sent to its agent roster', function () {
    $tenant = org();

    $this->actingAs(member($tenant, UserRole::ClientViewer))
        ->get('/app/overview')
        ->assertRedirect('/app/agents');
});

/* ── Scoping: whose numbers appear ───────────────────────────────────────── */

test('a member sees only their own tracked time', function () {
    $tenant = org();
    $me = member($tenant, UserRole::Member);
    $someoneElse = member($tenant, UserRole::Member);

    tracked($me->id, todayAt(), active: 3600);
    tracked($someoneElse->id, todayAt(), active: 7200);

    // 1h, not 3h: the colleague's session is invisible.
    // Asserted against the Active time stat rather than the whole page, and
    // Format::hms() separates with U+00A0 — neither the legacy e() nor Blade
    // turns that into &nbsp;, so the raw character is what reaches the markup.
    $html = $this->actingAs($me)->get('/app/overview')->assertOk()->getContent();

    expect($html)->toContain("<span class=\"lbl\">Active time</span><b>1h\u{00A0}00m</b>");
});

test('an admin sees the whole organization', function () {
    $tenant = org();
    $admin = member($tenant, UserRole::ClientAdmin);
    $worker = member($tenant, UserRole::Member);

    tracked($admin->id, todayAt(), active: 3600);
    tracked($worker->id, todayAt(), active: 7200);

    $html = $this->actingAs($admin)->get('/app/overview')->assertOk()->getContent();

    expect($html)->toContain("<span class=\"lbl\">Active time</span><b>3h\u{00A0}00m</b>");
});

test('a team manager sees their team and not the rest of the organization', function () {
    $tenant = org();
    $manager = member($tenant, UserRole::Manager);
    $mine = member($tenant, UserRole::Member);
    $theirs = member($tenant, UserRole::Member);

    $team = Team::create(['org_id' => $tenant->id, 'name' => 'Alpha']);
    TeamMember::create(['team_id' => $team->id, 'user_id' => $manager->id]);
    TeamMember::create(['team_id' => $team->id, 'user_id' => $mine->id]);

    tracked($mine->id, todayAt(), active: 3600);
    tracked($theirs->id, todayAt(), active: 7200);

    // The Active time stat specifically, not just the string anywhere on the
    // page: the roster panel prints each person's own hours, so a loose
    // assertion passes even when the scope has widened to the organization.
    $html = $this->actingAs($manager)->get('/app/overview')->assertOk()->getContent();

    expect($html)->toContain("<span class=\"lbl\">Active time</span><b>1h\u{00A0}00m</b>")
        ->and($html)->not->toContain("<span class=\"lbl\">Active time</span><b>3h\u{00A0}00m</b>");
});

test('a manager with no team assignment sees nobody', function () {
    // team_member_ids() returns the [0] sentinel, which matches no row. The
    // alternative reading — "no team means no restriction" — would show an
    // unassigned manager the entire organization.
    $tenant = org();
    $manager = member($tenant, UserRole::Manager);
    $worker = member($tenant, UserRole::Member);

    tracked($worker->id, todayAt(), active: 7200);

    $this->actingAs($manager)->get('/app/overview')
        ->assertOk()
        ->assertSee('No tracked time in this period yet.');
});

test('an unapproved session is excluded from the rollup', function () {
    $tenant = org();
    $me = member($tenant, UserRole::Member);

    $session = tracked($me->id, todayAt(), active: 3600);
    $session->forceFill(['approval_status' => 'pending'])->save();

    $this->actingAs($me)->get('/app/overview')
        ->assertOk()
        ->assertSee('No tracked time in this period yet.');
});

/* ── What each role is shown ─────────────────────────────────────────────── */

test('labor cost appears only for a viewer who can see rates', function () {
    $tenant = org();
    $admin = member($tenant, UserRole::ClientAdmin);
    $hr = member($tenant, UserRole::HrManager);

    $this->actingAs($admin)->get('/app/overview')->assertOk()->assertSee('Labor cost');

    // HR can SET a pay rate but cannot VIEW rates — so no cost figure.
    $this->actingAs($hr)->get('/app/overview')->assertOk()->assertDontSee('Labor cost');
});

test('a member gets the personal workspace and no roster', function () {
    $tenant = org();
    $me = member($tenant, UserRole::Member, ['job_title' => 'Support engineer']);
    member($tenant, UserRole::Member);

    $this->actingAs($me)->get('/app/overview')
        ->assertOk()
        ->assertSee('Your information')
        ->assertSee('Your workspace')
        ->assertSee('Support engineer')
        ->assertDontSee('Agents under you');
});

test('an admin gets the roster and no personal workspace', function () {
    $tenant = org();
    $admin = member($tenant, UserRole::ClientAdmin);
    $worker = member($tenant, UserRole::Member, ['name' => 'Dana Reyes']);

    tracked($worker->id, todayAt());

    $this->actingAs($admin)->get('/app/overview')
        ->assertOk()
        ->assertSee('Agents under you')
        ->assertSee('Dana Reyes')
        ->assertDontSee('Your workspace');
});

test('the roster never lists the viewer under themselves', function () {
    $tenant = org();
    $admin = member($tenant, UserRole::ClientAdmin, ['name' => 'Sole Admin']);

    // The only visible person is the viewer, so there is no roster at all.
    $this->actingAs($admin)->get('/app/overview')
        ->assertOk()
        ->assertDontSee('Agents under you');
});

test('open task and headcount figures are scoped to the viewer', function () {
    $tenant = org();
    $me = member($tenant, UserRole::Member);
    $other = member($tenant, UserRole::Member);

    Task::create(['org_id' => $tenant->id, 'user_id' => $me->id, 'title' => 'Mine', 'status' => 'open']);
    Task::create(['org_id' => $tenant->id, 'user_id' => $other->id, 'title' => 'Theirs', 'status' => 'open']);
    Task::create(['org_id' => $tenant->id, 'user_id' => $other->id, 'title' => 'Done', 'status' => 'done']);

    $member = $this->actingAs($me)->get('/app/overview')->assertOk();
    $admin = $this->actingAs(member($tenant, UserRole::ClientAdmin))->get('/app/overview')->assertOk();

    // The member counts one open task; the admin counts two.
    expect($member->getContent())->toContain('<span class="lbl">Open tasks</span><b>1</b>')
        ->and($admin->getContent())->toContain('<span class="lbl">Open tasks</span><b>2</b>');
});

/* ── The period widget ───────────────────────────────────────────────────── */

test('the overview opens on today', function () {
    $tenant = org();

    $this->actingAs(member($tenant, UserRole::Member))
        ->get('/app/overview')
        ->assertOk()
        ->assertSee(date('D j M Y'));
});

test('a period is honoured and an unknown one falls back', function () {
    $tenant = org();
    $me = member($tenant, UserRole::Member);

    $this->actingAs($me)->get('/app/overview?period=month')
        ->assertOk()
        ->assertSee(date('F Y'));

    // ?period=decade is not one of this page's pills.
    $this->actingAs($me)->get('/app/overview?period=decade')
        ->assertOk()
        ->assertSee(date('D j M Y'));
});

test('a signed-out visitor is sent to login with the overview as the destination', function () {
    $this->get('/app/overview')
        ->assertRedirect('/login?next=' . rawurlencode('/app/overview'));
});
