<?php

/**
 * The authorization middleware — capability, super-admin and staff gates.
 *
 * CapabilityMatrixTest proves the role → capability matrix is the legacy one.
 * This file proves the HTTP layer actually enforces it, and enforces it the way
 * the legacy app does: a redirect to /app with a notice, never a hard 403.
 *
 * @see docs/migration/authorization.md §3, §4
 */

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use App\Support\Flash;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

function capOrg(): Organization
{
    return Organization::create(['name' => 'Acme', 'status' => 'approved']);
}

function holder(UserRole $role): User
{
    return User::create([
        'org_id'        => capOrg()->id,
        'name'          => $role->value,
        'email'         => $role->value . '-' . uniqid() . '@caps.test',
        'password_hash' => bcrypt('secret'),
        'role'          => $role,
    ]);
}

/** Routes that exist only for this file, carrying one gate each. */
beforeEach(function () {
    Route::middleware(['web', 'auth'])->group(function () {
        Route::get('/test/cap-reports', fn () => 'reports')->middleware('cap:reports');
        Route::get('/test/cap-view-rates', fn () => 'rates')->middleware('cap:view_rates');
        Route::get('/test/staff', fn () => 'staff')->middleware('staff');
        Route::get('/test/super', fn () => 'platform')->middleware('super');
    });
});

/* ── Capability ──────────────────────────────────────────────────────────── */

test('a role that holds the capability is let through', function () {
    $this->actingAs(holder(UserRole::Manager))
        ->get('/test/cap-reports')
        ->assertSuccessful()
        ->assertSee('reports');
});

test('a role that does not hold it is turned away', function () {
    // it_admin has remote and devices but no reports — one of the grants that
    // reads like an oversight and is not.
    $this->actingAs(holder(UserRole::ItAdmin))
        ->get('/test/cap-reports')
        ->assertRedirect('/app');
});

test('being turned away is a redirect with a notice, not a 403', function () {
    // This is what a stale bookmark or a back button hits after someone
    // switches accounts. A 403 there would be a visible behaviour change.
    $this->actingAs(holder(UserRole::Member))
        ->get('/test/cap-reports')
        ->assertRedirect('/app')
        ->assertSessionHas(Flash::KEY);

    expect(session(Flash::KEY)[0]['msg'])->toBe("You don't have access to that page.")
        ->and(session(Flash::KEY)[0]['type'])->toBe('error');
});

test('a super admin passes every capability gate', function () {
    $super = holder(UserRole::SuperAdmin);

    $this->actingAs($super)->get('/test/cap-reports')->assertSuccessful();
    $this->actingAs($super)->get('/test/cap-view-rates')->assertSuccessful();
});

test('HR can set a pay rate but is turned away from viewing rates', function () {
    // Asserted through HTTP as well as through the enum, because this is the
    // pair most likely to be "tidied up" into a privilege escalation.
    $this->actingAs(holder(UserRole::HrManager))
        ->get('/test/cap-view-rates')
        ->assertRedirect('/app');

    expect(UserRole::HrManager->can(Capability::SetPayRate))->toBeTrue();
});

test('an unknown capability in a route definition fails loudly', function () {
    // A typo must break in development, not quietly lock a page in production.
    Route::middleware(['web', 'auth'])
        ->get('/test/cap-typo', fn () => 'nope')
        ->middleware('cap:reprots');

    $this->withoutExceptionHandling()->actingAs(holder(UserRole::SuperAdmin));

    expect(fn () => $this->get('/test/cap-typo'))
        ->toThrow(InvalidArgumentException::class, 'Unknown capability [reprots].');
});

/* ── Staff ───────────────────────────────────────────────────────────────── */

test('a client portal login is not staff', function () {
    // It has no leave, no payslip and no pay of any kind.
    $this->actingAs(holder(UserRole::ClientViewer))
        ->get('/test/staff')
        ->assertRedirect('/app');
});

test('a plain member is staff even though it holds no capabilities', function () {
    // Which is exactly why this gate cannot be expressed as a capability.
    $this->actingAs(holder(UserRole::Member))
        ->get('/test/staff')
        ->assertSuccessful();
});

/* ── Platform ────────────────────────────────────────────────────────────── */

test('only a super admin reaches the platform console', function () {
    $this->actingAs(holder(UserRole::SuperAdmin))->get('/test/super')->assertSuccessful();

    foreach ([UserRole::ClientAdmin, UserRole::ItAdmin, UserRole::HrManager] as $role) {
        $this->actingAs(holder($role))->get('/test/super')->assertRedirect('/app');
    }
});

/* ── Signed out ──────────────────────────────────────────────────────────── */

test('a signed-out visitor hits gate 1 before any authorization gate', function () {
    // deny_access() must never strand a logged-out visitor at /app.
    $this->get('/test/cap-reports')
        ->assertRedirect('/login?next=' . rawurlencode('/test/cap-reports'));
});
