<?php

/**
 * The four gates, in order.
 *
 * A valid session is not enough to see a page. This file covers every gate and,
 * more importantly, every EXEMPTION — an exemption that quietly disappears is
 * how a paywall stops a lapsed organization's agents from tracking, or how a
 * forced password change deadlocks against the paywall.
 *
 * @see docs/migration/authentication.md §2
 */

use App\Enums\UserRole;
use App\Http\Middleware\EnsureSubscriptionCurrent;
use App\Models\Organization;
use App\Models\User;
use App\Support\Platform;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * An ESTABLISHED tenant and an established account. `onboarded_at` and
 * `welcomed_at` are stamped so RedirectFirstRun stays out of the way: this file
 * is about gates 1–4, and a first-run redirect would answer every assertion
 * with the wizard rather than the gate under test.
 */
function makeOrg(array $attributes = []): Organization
{
    return Organization::create(array_merge([
        'name'         => 'Acme',
        'status'       => 'approved',
        'onboarded_at' => '2024-01-01 00:00:00',
    ], $attributes));
}

function makeUser(Organization $org, UserRole $role = UserRole::Member, array $attributes = []): User
{
    return User::create(array_merge([
        'org_id'        => $org->id,
        'name'          => $role->value,
        'email'         => $role->value . '-' . uniqid() . '@example.test',
        'password_hash' => bcrypt('correct-horse'),
        'role'          => $role,
        'welcomed_at'   => '2024-01-01 00:00:00',
    ], $attributes));
}

/** Switch payments on for the platform, the way a super admin does. */
function enablePayments(): Organization
{
    $platform = makeOrg(['name' => 'DeskPulse platform', 'pay_enabled' => 1]);
    makeUser($platform, UserRole::SuperAdmin);

    app(Platform::class)->flush();

    return $platform;
}

/* ── Gate 1: not signed in ───────────────────────────────────────────────── */

test('a signed-out visitor is sent to login with the destination attached', function () {
    $this->get('/app/pending')
        ->assertRedirect('/login?next=' . rawurlencode('/app/pending'));
});

test('signing in returns to the page that was asked for', function () {
    $user = makeUser(makeOrg());

    $this->post('/login?next=' . rawurlencode('/app/reports'), [
        'email'    => $user->email,
        'password' => 'correct-horse',
    ])->assertRedirect('/app/reports');
});

test('next cannot be turned into an open redirect', function () {
    // `//evil.example` starts with a slash, which is all the legacy check tests
    // for — and a browser reads it as scheme-relative. See Access::internalPath.
    $user = makeUser(makeOrg());

    foreach (['//evil.example/phish', '/\\evil.example', 'https://evil.example', 'evil'] as $hostile) {
        $this->post('/login?next=' . rawurlencode($hostile), [
            'email'    => $user->email,
            'password' => 'correct-horse',
        ])->assertRedirect('/app');

        auth()->logout();
    }
});

/* ── Gate 2: temporary password ──────────────────────────────────────────── */

test('a temporary password holds the account at the change-password page', function () {
    $user = makeUser(makeOrg(), UserRole::Member, ['must_change_password' => 1]);

    $this->actingAs($user)->get('/app')->assertRedirect('/app/change-password');
    $this->actingAs($user)->get('/app/pending')->assertRedirect('/app/change-password');
});

test('only change-password and logout are exempt from the forced change', function () {
    $user = makeUser(makeOrg(), UserRole::Member, ['must_change_password' => 1]);

    $this->actingAs($user)->get('/app/change-password')->assertSuccessful();
    $this->actingAs($user)->get('/logout')->assertRedirect('/');
});

test('setting a password clears the hold and signs the user into the app', function () {
    $user = makeUser(makeOrg(), UserRole::Member, ['must_change_password' => 1]);

    $this->actingAs($user)
        ->post('/app/change-password', [
            'password'         => 'a-better-password',
            'password_confirm' => 'a-better-password',
        ])
        ->assertRedirect('/app');

    expect($user->fresh()->must_change_password)->toBeFalsy();
});

/* ── Gate 3: organization not approved ───────────────────────────────────── */

test('a pending organization holds its members at the pending page', function () {
    $user = makeUser(makeOrg(['status' => 'pending']));

    $this->actingAs($user)->get('/app')->assertRedirect('/app/pending');
});

test('the pending page itself is reachable, or the gate would loop', function () {
    $user = makeUser(makeOrg(['status' => 'pending']));

    $this->actingAs($user)->get('/app/pending')->assertSuccessful();
});

test('an approved organization lets its members through', function () {
    $user = makeUser(makeOrg(['status' => 'approved']));

    $this->actingAs($user)->get('/app')->assertRedirect('/app/overview');
});

test('an organization created without a status is approved', function () {
    // The column is NOT NULL DEFAULT 'approved', so an organization inserted
    // without one is live immediately. The `?? 'approved'` in the middleware
    // covers the other case below, not this one.
    $org = Organization::create(['name' => 'No status given']);

    expect($org->fresh()->status)->toBe('approved');

    $this->actingAs(makeUser($org))->get('/app')->assertRedirect('/app/overview');
});

test('a user can never point at an organization that does not exist', function () {
    // users.org_id is a foreign key, so the middleware's `?? 'approved'`
    // fallback is unreachable in practice. Asserted rather than assumed,
    // because the legacy require_approved_org() would pass that null row
    // straight into sub_is_current(array $org) and fatal.
    $user = makeUser(makeOrg());

    expect(fn () => $user->forceFill(['org_id' => 999999])->save())
        ->toThrow(Illuminate\Database\QueryException::class);
});

test('a super admin is never held at the organization gate', function () {
    $platform = makeOrg(['name' => 'DeskPulse platform', 'status' => 'pending']);

    $this->actingAs(makeUser($platform, UserRole::SuperAdmin))
        ->get('/app')
        ->assertRedirect('/app/platform');
});

test('the pending page lets an approved tenant straight through', function () {
    $user = makeUser(makeOrg(['status' => 'approved']));

    $this->actingAs($user)->get('/app/pending')->assertRedirect('/app/overview');
});

/* ── Gate 4: the paywall ─────────────────────────────────────────────────── */

test('the paywall is inert while payments are switched off', function () {
    $user = makeUser(makeOrg(['subscription_status' => 'canceled']));

    $this->actingAs($user)->get('/app')->assertRedirect('/app/overview');
});

test('a lapsed organization is held at the subscription page', function () {
    enablePayments();
    $user = makeUser(makeOrg(['subscription_status' => 'canceled']));

    $this->actingAs($user)->get('/app')->assertRedirect('/app/subscription');
});

test('an expired trial is lapsed and a live one is not', function () {
    enablePayments();

    $expired = makeUser(makeOrg([
        'subscription_status' => 'trialing',
        'trial_ends_at'       => now()->subDay(),
    ]));

    $live = makeUser(makeOrg([
        'subscription_status' => 'trialing',
        'trial_ends_at'       => now()->addDay(),
    ]));

    $this->actingAs($expired)->get('/app')->assertRedirect('/app/subscription');
    $this->actingAs($live)->get('/app')->assertRedirect('/app/overview');
});

test('a super-admin comp beats every other subscription state', function () {
    enablePayments();

    $user = makeUser(makeOrg([
        'billing_status'      => 'active',
        'subscription_status' => 'canceled',
        'trial_ends_at'       => now()->subYear(),
    ]));

    $this->actingAs($user)->get('/app')->assertRedirect('/app/overview');
});

test('paying and leaving stay possible for a lapsed organization', function () {
    enablePayments();
    $user = makeUser(makeOrg(['subscription_status' => 'past_due']));

    // change-password is exempt, or gate 2 and gate 4 would deadlock.
    $this->actingAs($user)->get('/app/change-password')->assertSuccessful();
    $this->actingAs($user)->get('/logout')->assertRedirect('/');
});

test('the agent API is exempt from the paywall', function () {
    // THE important one. Putting /webhooks/* behind the paywall silently stops
    // tracking for a lapsed organization: the agents keep running, keep
    // retrying, and every hour of work is thrown away.
    expect(EnsureSubscriptionCurrent::isExempt('/webhooks/heartbeat'))->toBeTrue()
        ->and(EnsureSubscriptionCurrent::isExempt('/webhooks/auth'))->toBeTrue()
        ->and(EnsureSubscriptionCurrent::isExempt('/webhooks'))->toBeTrue();
});

test('the exemption list is exactly the documented one', function () {
    $exempt = ['/app/subscription', '/app/change-password', '/app/profile', '/logout', '/webhooks'];

    foreach ($exempt as $path) {
        expect(EnsureSubscriptionCurrent::isExempt($path))->toBeTrue("{$path} should be exempt");
    }

    foreach (['/app', '/app/overview', '/app/reports', '/app/pending', '/'] as $gated) {
        expect(EnsureSubscriptionCurrent::isExempt($gated))->toBeFalse("{$gated} must not be exempt");
    }
});

/* ── Order ───────────────────────────────────────────────────────────────── */

test('the forced password change is applied before the organization gate', function () {
    // Both apply. The legacy order is require_login() first, so someone in a
    // pending organization with a temporary password sets the password first.
    enablePayments();

    $user = makeUser(
        makeOrg(['status' => 'pending', 'subscription_status' => 'canceled']),
        UserRole::Member,
        ['must_change_password' => 1]
    );

    $this->actingAs($user)->get('/app')->assertRedirect('/app/change-password');
});
