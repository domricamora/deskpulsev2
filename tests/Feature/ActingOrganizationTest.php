<?php

/**
 * Super-admin "act as" — viewing the product as one tenant.
 *
 * Two properties carry the whole security of this feature:
 *   • the organization id is never read from the request, and
 *   • the session value is validated against the database before use.
 *
 * @see docs/migration/authentication.md §1
 */

use App\Enums\UserRole;
use App\Http\Middleware\ResolveOrganization;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a super admin lands on the platform console when not acting as anyone', function () {
    $platform = org(['name' => 'DeskPulse platform']);

    $this->actingAs(member($platform, UserRole::SuperAdmin))
        ->get('/app')
        ->assertRedirect('/app/platform');
});

test('acting as a tenant sends the super admin to that tenant dashboard', function () {
    $platform = org(['name' => 'DeskPulse platform']);
    $tenant = org(['name' => 'Acme']);
    $super = member($platform, UserRole::SuperAdmin);

    $this->actingAs($super)
        ->withSession([ResolveOrganization::SESSION_KEY => $tenant->id])
        ->get('/app')
        ->assertRedirect('/app/overview');
});

test('the acting organization becomes the effective one for scoping', function () {
    $platform = org(['name' => 'DeskPulse platform']);
    $tenant = org(['name' => 'Acme']);
    $super = member($platform, UserRole::SuperAdmin);

    $super->actAs($tenant->id);

    expect($super->effectiveOrgId())->toBe((int) $tenant->id)
        ->and($super->org_id)->toBe((int) $platform->id)
        ->and($super->isActingAsOrganization())->toBeTrue()
        ->and($super->effectiveOrganization()->name)->toBe('Acme');
});

test('act-as is never written to the database', function () {
    // The legacy code overwrites $user['org_id'] in an array. The equivalent
    // here would be an attribute a later save() could persist, which would move
    // the platform operator into the tenant permanently.
    $platform = org(['name' => 'DeskPulse platform']);
    $tenant = org(['name' => 'Acme']);
    $super = member($platform, UserRole::SuperAdmin);

    $super->actAs($tenant->id);
    $super->forceFill(['name' => 'Renamed while acting'])->save();

    expect(DB::table('users')->where('id', $super->id)->value('org_id'))
        ->toBe((int) $platform->id);
});

test('a forged act_org for an organization that does not exist is ignored', function () {
    $platform = org(['name' => 'DeskPulse platform']);
    $super = member($platform, UserRole::SuperAdmin);

    $this->actingAs($super)
        ->withSession([ResolveOrganization::SESSION_KEY => 999999])
        ->get('/app')
        ->assertRedirect('/app/platform');
});

test('a non-super session cannot act as an organization', function () {
    $tenantA = org(['name' => 'Alpha']);
    $tenantB = org(['name' => 'Beta']);
    $admin = member($tenantA, UserRole::ClientAdmin);

    $this->actingAs($admin)
        ->withSession([ResolveOrganization::SESSION_KEY => $tenantB->id])
        ->get('/app')
        ->assertRedirect('/app/overview');

    expect($admin->effectiveOrgId())->toBe((int) $tenantA->id);
});

/* ── Starting and stopping ───────────────────────────────────────────────── */

test('a super admin starts acting as a tenant from the platform console link', function () {
    $platform = org(['name' => 'DeskPulse platform']);
    $tenant = org(['name' => 'Acme']);

    $this->actingAs(member($platform, UserRole::SuperAdmin))
        ->get('/app/platform/act/' . $tenant->id)
        ->assertRedirect('/app/overview')
        ->assertSessionHas(ResolveOrganization::SESSION_KEY, (int) $tenant->id);
});

test('returning clears the acting organization', function () {
    $platform = org(['name' => 'DeskPulse platform']);
    $tenant = org(['name' => 'Acme']);

    $this->actingAs(member($platform, UserRole::SuperAdmin))
        ->withSession([ResolveOrganization::SESSION_KEY => $tenant->id])
        ->get('/app/platform/return')
        ->assertRedirect('/app/platform')
        ->assertSessionMissing(ResolveOrganization::SESSION_KEY);
});

test('acting as an organization that does not exist writes nothing', function () {
    // A stale link from a deleted tenant lands somewhere useful rather than
    // putting an unresolvable id in the session.
    $platform = org(['name' => 'DeskPulse platform']);

    $this->actingAs(member($platform, UserRole::SuperAdmin))
        ->get('/app/platform/act/999999')
        ->assertRedirect('/app/overview')
        ->assertSessionMissing(ResolveOrganization::SESSION_KEY);
});

test('a non-super admin cannot start acting as anyone', function () {
    $tenantA = org(['name' => 'Alpha']);
    $tenantB = org(['name' => 'Beta']);

    $this->actingAs(member($tenantA, UserRole::ClientAdmin))
        ->get('/app/platform/act/' . $tenantB->id)
        ->assertRedirect('/app')
        ->assertSessionMissing(ResolveOrganization::SESSION_KEY);
});

test('a signed-out visitor cannot start acting as anyone', function () {
    $tenant = org(['name' => 'Acme']);

    $this->get('/app/platform/act/' . $tenant->id)
        ->assertRedirect('/login?next=' . rawurlencode('/app/platform/act/' . $tenant->id))
        ->assertSessionMissing(ResolveOrganization::SESSION_KEY);
});

test('a super admin acting as a pending tenant is still exempt from the gate', function () {
    // Gate 3 exempts super_admin by ROLE, not by "is not acting". A platform
    // operator has to be able to open a tenant that is waiting for their own
    // approval — that is the page they approve it from.
    $platform = org(['name' => 'DeskPulse platform']);
    $pending = org(['name' => 'Not yet approved', 'status' => 'pending']);
    $super = member($platform, UserRole::SuperAdmin);

    $this->actingAs($super)
        ->withSession([ResolveOrganization::SESSION_KEY => $pending->id])
        ->get('/app')
        ->assertRedirect('/app/overview');
});
