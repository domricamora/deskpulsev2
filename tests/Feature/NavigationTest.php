<?php

/**
 * The app shell: which links each role sees, and the two badges.
 *
 * Three independent rules decide whether a link appears — its capability, the
 * super-admin hide list, and the per-role hide list — and they are not
 * interchangeable. The client portal is the case that proves the third is
 * needed: it HOLDS `reports`, so without an explicit removal the Efficiency
 * report would appear in a customer's sidebar.
 *
 * Links are not access control; every destination enforces its own guard. What
 * is tested here is that the sidebar tells the truth about what a role can do.
 *
 * @see docs/migration/authorization.md §2
 */

use App\Enums\UserRole;
use App\Http\Middleware\ResolveOrganization;
use App\Models\WorkSession;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Every link key a role resolves to, flattened out of its groups. */
function navKeys(UserRole $role, bool $acting = false): array
{
    $organization = org();
    $user = member($organization, $role);

    if ($acting) {
        $user->actAs($organization->id);
    }

    return collect(Navigation::forUser($user))
        ->flatten(1)
        ->pluck('key')
        ->all();
}

/* ── Capability gating ───────────────────────────────────────────────────── */

test('a link with a capability appears only for roles that hold it', function () {
    $admin = navKeys(UserRole::ClientAdmin);
    $employee = navKeys(UserRole::Member);

    expect($admin)->toContain('team')          // view_all
        ->and($admin)->toContain('clients')    // clients_manage
        ->and($employee)->not->toContain('team')
        ->and($employee)->not->toContain('clients');
});

test('an employee still gets every uncapability-gated link', function () {
    // member holds nothing at all; its access comes from "self only" scoping,
    // so the Work group has to survive an empty capability set.
    expect(navKeys(UserRole::Member))
        ->toContain('overview')
        ->toContain('timesheets')
        ->toContain('tasks')
        ->toContain('profile');
});

test('HR sees payroll but never screenshots or billing', function () {
    $hr = navKeys(UserRole::HrManager);

    expect($hr)->toContain('payroll')
        ->and($hr)->toContain('overtime')
        ->and($hr)->not->toContain('screenshots')
        ->and($hr)->not->toContain('billing');
});

test('IT sees devices and audit but no reports', function () {
    $it = navKeys(UserRole::ItAdmin);

    expect($it)->toContain('devices')
        ->and($it)->toContain('audit')
        ->and($it)->not->toContain('efficiency')
        ->and($it)->not->toContain('payroll');
});

/* ── The two hide lists ──────────────────────────────────────────────────── */

test('a platform operator sees the console and none of the member pages', function () {
    $super = navKeys(UserRole::SuperAdmin);

    // The wildcard grants every capability, so without SUPER_HIDDEN the
    // operator would see the entire product against an empty organization.
    expect($super)->not->toContain('overview')
        ->and($super)->not->toContain('team')
        ->and($super)->not->toContain('subscription')   // the tenant paywall
        ->and($super)->toContain('profile');            // not in the hide list
});

test('a platform operator acting as a tenant gets the full navigation back', function () {
    // Navigating that tenant is the entire point of act-as.
    $acting = navKeys(UserRole::SuperAdmin, acting: true);

    expect($acting)->toContain('overview')
        ->toContain('team')
        ->toContain('clients')
        ->toContain('subscription');
});

test('a client portal gets its six pages and nothing else', function () {
    $portal = navKeys(UserRole::ClientViewer);

    expect($portal)->toContain('agents')
        ->toContain('timesheets')
        ->toContain('tasks')
        ->toContain('screenshots')
        ->toContain('billing')
        ->toContain('profile');
});

test('the efficiency report is hidden from a client portal by name', function () {
    // client_viewer HOLDS `reports` — it needs the capability for data scoping.
    // Only ROLE_HIDDEN keeps an internal performance report out of a
    // customer's sidebar, which is why that list is not redundant.
    $portal = member(org(), UserRole::ClientViewer);

    expect($portal->hasCapability(App\Enums\Capability::Reports))->toBeTrue()
        ->and(navKeys(UserRole::ClientViewer))->not->toContain('efficiency');
});

test('a team manager has no settings link', function () {
    expect(navKeys(UserRole::Manager))->not->toContain('settings')
        ->and(navKeys(UserRole::ClientAdmin))->toContain('settings');
});

test('an empty group is dropped rather than rendered as a heading with nothing under it', function () {
    // A client portal has no Manage group beyond Agents, and no Insights
    // beyond Screenshots — the groups that empty out must disappear.
    $groups = Navigation::forUser(member(org(), UserRole::ClientViewer));

    foreach ($groups as $label => $items) {
        expect($items)->not->toBeEmpty("group {$label} rendered empty");
    }
});

/* ── The rendered shell ──────────────────────────────────────────────────── */

test('the sidebar renders the viewer name and role', function () {
    $tenant = org();

    $this->actingAs(member($tenant, UserRole::HrManager, ['name' => 'Sam Okafor']))
        ->get('/app/overview')
        ->assertOk()
        ->assertSee('Sam Okafor')
        // role_label() calls hr_manager "HR manager", not "HR admin".
        ->assertSee('HR manager');
});

test('the acting banner appears only while acting as a tenant', function () {
    $platform = org(['name' => 'DeskPulse platform']);
    $tenant = org(['name' => 'Acme Corporation']);
    $super = member($platform, UserRole::SuperAdmin);

    $this->actingAs($super)
        ->withSession([ResolveOrganization::SESSION_KEY => $tenant->id])
        ->get('/app/overview')
        ->assertOk()
        ->assertSee('Acme Corporation')
        ->assertSee('Return to platform');

    // An ordinary admin is never told they are acting as anyone.
    $this->actingAs(member($tenant, UserRole::ClientAdmin))
        ->get('/app/overview')
        ->assertOk()
        ->assertDontSee('Return to platform');
});

test('the platform group is rendered only for an operator who is not acting', function () {
    $platform = org(['name' => 'DeskPulse platform']);
    $tenant = org(['name' => 'Acme']);
    $super = member($platform, UserRole::SuperAdmin);

    $this->actingAs($super)
        ->withSession([ResolveOrganization::SESSION_KEY => $tenant->id])
        ->get('/app/overview')
        ->assertOk()
        ->assertDontSee('/app/platform/subscribers');

    $this->actingAs(member($tenant, UserRole::ClientAdmin))
        ->get('/app/overview')
        ->assertOk()
        ->assertDontSee('/app/platform/subscribers');
});

/* ── Badges ──────────────────────────────────────────────────────────────── */

test('the approvals badge counts only what this viewer may act on', function () {
    $tenant = org();
    $admin = member($tenant, UserRole::ClientAdmin);
    $worker = member($tenant, UserRole::Member);

    foreach ([1, 2, 3] as $ignored) {
        WorkSession::create([
            'user_id' => $worker->id, 'started_at' => '2026-09-01 09:00:00',
            'ended_at' => '2026-09-01 10:00:00', 'active_s' => 3600, 'inactive_s' => 0,
            'source' => 'manual', 'approval_status' => 'pending',
        ]);
    }

    $this->actingAs($admin)->get('/app/overview')
        ->assertOk()
        ->assertSee('<span class="badge">3</span>', false);

    // The employee whose entries they are holds no approve_time, so no badge.
    $this->actingAs($worker)->get('/app/overview')
        ->assertOk()
        ->assertDontSee('<span class="badge">', false);
});

test('a badge is absent rather than showing a zero', function () {
    $tenant = org();

    $this->actingAs(member($tenant, UserRole::ClientAdmin))
        ->get('/app/overview')
        ->assertOk()
        ->assertDontSee('<span class="badge">0</span>', false);
});

test('the overtime badge is separate from the approvals badge', function () {
    $tenant = org();
    $worker = member($tenant, UserRole::Member);

    // overtime_computed = 1 because that is what a real row carries: the split
    // is written by recompute_overtime(), which marks the row done. Without it
    // the Phase 9 maintenance pass on every dashboard render picks the session
    // up as uncomputed, finds this worker has no schedule, and correctly
    // resets the overtime to zero — taking the badge with it.
    WorkSession::create([
        'user_id' => $worker->id, 'started_at' => '2026-09-01 09:00:00',
        'ended_at' => '2026-09-01 19:00:00', 'active_s' => 36000, 'inactive_s' => 0,
        'source' => 'agent', 'approval_status' => 'approved',
        'overtime_s' => 7200, 'overtime_status' => 'pending', 'overtime_computed' => 1,
    ]);

    // A team manager holds approve_time but NOT approve_overtime.
    $manager = member($tenant, UserRole::Manager);

    $this->actingAs(member($tenant, UserRole::HrManager))->get('/app/overview')
        ->assertOk()
        ->assertSee('<span class="badge">1</span>', false);

    $this->actingAs($manager)->get('/app/overview')
        ->assertOk()
        ->assertDontSee('<span class="badge">1</span>', false);
});
