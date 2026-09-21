<?php

/**
 * Password sign-in, sign-out and self-serve signup.
 *
 * @see docs/migration/authentication.md §3, §4
 */

use App\Enums\UserRole;
use App\Models\EmailOutbox;
use App\Models\Organization;
use App\Models\ShareLink;
use App\Models\User;
use App\Services\Oidc\ProviderRegistry;
use App\Support\Platform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/*
 * `onboarded_at` and `welcomed_at` are stamped for the same reason `status` is
 * approved: these helpers stand for an ESTABLISHED tenant. Without them
 * RedirectFirstRun sends the account to its setup wizard, and a test of gate 3
 * or gate 4 would be asserting against the wizard instead of the gate.
 */
function tenant(array $attributes = []): Organization
{
    return Organization::create(array_merge([
        'name'         => 'Acme',
        'status'       => 'approved',
        'onboarded_at' => '2024-01-01 00:00:00',
    ], $attributes));
}

function account(Organization $o, array $attributes = []): User
{
    return User::create(array_merge([
        'org_id'        => $o->id,
        'name'          => 'Owner',
        'email'         => 'owner@acme.test',
        'password_hash' => bcrypt('correct-horse'),
        'role'          => UserRole::ClientAdmin,
        'welcomed_at'   => '2024-01-01 00:00:00',
    ], $attributes));
}

/* ── Sign in ─────────────────────────────────────────────────────────────── */

test('the sign-in form renders for a visitor', function () {
    $this->get('/login')
        ->assertSuccessful()
        ->assertSee('Sign in')
        ->assertSee('Forgot your password?');
});

test('an already signed-in user opening login goes to the dashboard', function () {
    // A stale tab after switching accounts, or /login typed directly.
    $this->actingAs(account(tenant()))->get('/login')->assertRedirect('/app');
});

test('correct credentials sign the user in', function () {
    $user = account(tenant());

    $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse'])
        ->assertRedirect('/app');

    expect(auth()->id())->toBe($user->id);
});

test('the email is matched case-insensitively', function () {
    $user = account(tenant());

    $this->post('/login', ['email' => 'OWNER@ACME.TEST', 'password' => 'correct-horse'])
        ->assertRedirect('/app');

    expect(auth()->id())->toBe($user->id);
});

test('a wrong password is refused with one generic message', function () {
    // The same message for a wrong password and an unknown address, so the form
    // cannot be used to enumerate accounts.
    account(tenant());

    $wrongPassword = $this->post('/login', [
        'email' => 'owner@acme.test', 'password' => 'not-the-password',
    ]);

    $unknownAddress = $this->post('/login', [
        'email' => 'nobody@acme.test', 'password' => 'not-the-password',
    ]);

    $wrongPassword->assertSee('Invalid email or password.', false);
    $unknownAddress->assertSee('Invalid email or password.', false);

    expect(auth()->check())->toBeFalse();
});

test('signing in rotates the session id', function () {
    $user = account(tenant());

    $this->get('/login');
    $before = session()->getId();

    $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse']);

    expect(session()->getId())->not->toBe($before);
});

test('signing out clears the session and returns to the marketing site', function () {
    $this->actingAs(account(tenant()))->get('/logout')->assertRedirect('/');

    expect(auth()->check())->toBeFalse();
});

test('remember-me is switched off at the model, not merely unused', function () {
    // This schema has no remember_token column. Laravel would UPDATE it on a
    // remembered login and every sign-in would fail on an unknown column.
    expect(account(tenant())->getRememberTokenName())->toBeNull()
        ->and(Schema::hasColumn('users', 'remember_token'))->toBeFalse();
});

/* ── Enterprise SSO on the sign-in form ──────────────────────────────────── */

test('an organization that enforces SSO refuses the password path', function () {
    // Even with the correct password — otherwise "enforce SSO" is decorative.
    $org = tenant(['sso_enabled' => 1, 'sso_enforce' => 1, 'sso_issuer' => 'https://idp.acme.test']);
    $user = account($org);

    $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse'])
        ->assertSee('requires single sign-on', false);

    expect(auth()->check())->toBeFalse();
});

test('SSO configured but not enforced still allows a password', function () {
    $org = tenant(['sso_enabled' => 1, 'sso_enforce' => 0]);
    $user = account($org);

    $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse'])
        ->assertRedirect('/app');
});

test('a claimed email domain offers SSO after a failed password', function () {
    // Usually the reason the password was wrong.
    tenant([
        'name'        => 'Acme Corp',
        'sso_enabled' => 1,
        'sso_domains' => 'acme.test, acme.example',
    ]);

    $this->post('/login', ['email' => 'someone@acme.example', 'password' => 'wrong'])
        ->assertSee('Acme Corp', false)
        ->assertSee('single sign-on', false);
});

test('no provider buttons render when nothing is configured', function () {
    // A fresh install must not show a dead "Continue with Google".
    config(['services.google.client_id' => null, 'services.google.client_secret' => null]);

    $this->get('/login')->assertDontSee('Continue with Google');
});

test('a provider is offered only when both id and secret are present', function () {
    // Half-configured is the dangerous state: the button renders, the flow
    // starts, and the exchange fails at the provider with an error the person
    // cannot act on.
    config(['services.google.client_id' => 'id-only', 'services.google.client_secret' => null]);
    app()->forgetInstance(ProviderRegistry::class);

    expect(app(ProviderRegistry::class)->providers())->toBe([])
        ->and(app(ProviderRegistry::class)->enabled())->toBeFalse();

    config(['services.google.client_secret' => 'and-a-secret']);
    app()->forgetInstance(ProviderRegistry::class);

    expect(array_keys(app(ProviderRegistry::class)->providers()))->toBe(['google'])
        ->and(app(ProviderRegistry::class)->enabled())->toBeTrue();
});

test('a configured provider renders its button on the sign-in form', function () {
    config(['services.google.client_id' => 'gid', 'services.google.client_secret' => 'gsecret']);
    app()->forgetInstance(ProviderRegistry::class);

    $this->get('/login')
        ->assertSee('Continue with Google', false)
        ->assertSee('/auth/google', false)
        // The brand mark is the provider's own asset, colours and all.
        ->assertSee('#4285F4', false);
});

/* ── Signup ──────────────────────────────────────────────────────────────── */

test('the signup form defaults to the Team plan and shows its price', function () {
    $this->get('/register')
        ->assertSuccessful()
        ->assertSee('Team')
        ->assertSee('/seat/month', false);
});

test('a plan from a pricing CTA survives the round trip', function () {
    // It rides as a hidden field: the form POSTs to a bare /register, so a
    // query-string-only plan would silently become the default.
    $this->get('/register?plan=solo')
        ->assertSee('Solo')
        ->assertSee('Free forever')
        ->assertSee('value="solo"', false);
});

test('a successful signup creates the organization, the admin and the plan', function () {
    $this->post('/register', [
        'plan'     => 'organization',
        'company'  => 'New Co',
        'name'     => 'Dana',
        'email'    => 'dana@newco.test',
        'password' => 'a-good-password',
    ])->assertRedirect('/app');

    $org = Organization::where('name', 'New Co')->firstOrFail();
    $user = User::where('email', 'dana@newco.test')->firstOrFail();

    expect($user->role)->toBe(UserRole::ClientAdmin)
        ->and($user->org_id)->toBe((int) $org->id)
        ->and(Hash::check('a-good-password', $user->password_hash))->toBeTrue()
        ->and($org->plan_type)->toBe('organization')
        ->and((float) $org->monthly_fee)->toBe(49.0)
        ->and($org->pay_reference)->toStartWith('DP-')
        ->and(auth()->id())->toBe($user->id);
});

test('signup falls back to pending approval while payments are off', function () {
    $this->post('/register', [
        'plan' => 'per_seat', 'company' => 'Pending Co', 'name' => 'Sam',
        'email' => 'sam@pending.test', 'password' => 'a-good-password',
    ]);

    expect(Organization::where('name', 'Pending Co')->value('status'))->toBe('pending');
});

test('signup starts a trial and grants immediate access once payments are on', function () {
    $platform = tenant(['name' => 'DeskPulse platform', 'pay_enabled' => 1]);
    account($platform, ['email' => 'ops@deskpulse.test', 'role' => UserRole::SuperAdmin]);
    app(Platform::class)->flush();

    $this->post('/register', [
        'plan' => 'per_seat', 'company' => 'Trial Co', 'name' => 'Kai',
        'email' => 'kai@trial.test', 'password' => 'a-good-password',
    ]);

    $org = Organization::where('name', 'Trial Co')->firstOrFail();

    expect($org->status)->toBe('approved')
        ->and($org->subscription_status)->toBe('trialing')
        ->and($org->trial_ends_at->isFuture())->toBeTrue();
});

test('a single-user plan derives the workspace name from the person', function () {
    $this->post('/register', [
        'plan' => 'solo', 'company' => '', 'name' => 'Robin Lee',
        'email' => 'robin@solo.test', 'password' => 'a-good-password',
    ])->assertRedirect('/app');

    expect(Organization::where('name', 'Robin Lee')->exists())->toBeTrue();
});

test('a company name is still required on a multi-user plan', function () {
    $this->post('/register', [
        'plan' => 'per_seat', 'company' => '', 'name' => 'Robin',
        'email' => 'robin@team.test', 'password' => 'a-good-password',
    ])->assertSee('All fields are required.', false);

    expect(User::where('email', 'robin@team.test')->exists())->toBeFalse();
});

test('every field repopulates when the form is rejected', function () {
    // Losing four fields to one typo is the biggest avoidable drop-off there is.
    $this->post('/register', [
        'plan' => 'per_seat', 'company' => 'Typo Co', 'name' => 'Alex',
        'email' => 'not-an-email', 'password' => 'a-good-password',
    ])
        ->assertSee('Enter a valid email address.', false)
        ->assertSee('value="Typo Co"', false)
        ->assertSee('value="Alex"', false)
        ->assertSee('value="not-an-email"', false);
});

test('a short password and a duplicate address are each refused', function () {
    account(tenant());

    $this->post('/register', [
        'plan' => 'per_seat', 'company' => 'X', 'name' => 'Y',
        'email' => 'y@x.test', 'password' => 'short',
    ])->assertSee('at least 8 characters', false);

    $this->post('/register', [
        'plan' => 'per_seat', 'company' => 'X', 'name' => 'Y',
        'email' => 'owner@acme.test', 'password' => 'a-good-password',
    ])->assertSee('already registered', false);

    expect(User::count())->toBe(1);
});

test('signup gives the new admin a personal share link and alerts sales', function () {
    $this->post('/register', [
        'plan' => 'per_seat', 'company' => 'Linked Co', 'name' => 'Nico',
        'email' => 'nico@linked.test', 'password' => 'a-good-password',
    ]);

    $user = User::where('email', 'nico@linked.test')->firstOrFail();
    $link = ShareLink::where('target_id', $user->id)->firstOrFail();

    expect($link->scope)->toBe('user')
        ->and($link->period_default)->toBe('day')
        ->and($link->label)->toBe('Nico — personal')
        ->and($link->token)->not->toBeEmpty()
        ->and(EmailOutbox::where('subject', 'like', 'New DeskPulse signup%')->exists())->toBeTrue();
});

/* ── Legacy password hashes ──────────────────────────────────────────────── */

test('a sign-in works against a hash written by the legacy application', function () {
    // Every row in the real database was hashed by
    // password_hash($p, PASSWORD_DEFAULT) — bcrypt at cost 10 — while this app
    // is configured for 12. A fixture built with bcrypt() already carries the
    // configured cost and so exercises a different path entirely, which is how
    // a 500 on every real sign-in survived a green suite.
    $organization = org();

    $user = member($organization, UserRole::Member, [
        'password_hash' => password_hash('Demo12345', PASSWORD_BCRYPT, ['cost' => 10]),
    ]);

    expect($user->password_hash)->toStartWith('$2y$10$');

    $this->post('/login', ['email' => $user->email, 'password' => 'Demo12345'])
        ->assertRedirect('/app');

    expect(auth()->id())->toBe($user->id);
});

test('a legacy hash is left exactly as it was', function () {
    // The legacy handle_login() only calls password_verify(); it never rewrites
    // the stored hash. Rehash-on-login is off to match, so signing in must not
    // touch the row. See config/hashing.php.
    $organization = org();

    $user = member($organization, UserRole::Member, [
        'password_hash' => password_hash('Demo12345', PASSWORD_BCRYPT, ['cost' => 10]),
    ]);

    $before = $user->password_hash;

    $this->post('/login', ['email' => $user->email, 'password' => 'Demo12345'])
        ->assertRedirect('/app');

    expect($user->fresh()->password_hash)->toBe($before);
});

test('rehashing on login, if it is ever switched on, writes to the real column', function () {
    // This is the configuration that broke: rehash-on-login is Laravel's
    // default, and it writes through getAuthPasswordName(). Left at the
    // framework default of 'password' it produced
    // "Unknown column 'password'" — a 500 on every real sign-in, invisible to
    // a suite whose fixtures never need rehashing.
    //
    // The flag ships off to match the legacy app. This test exercises it ON so
    // that turning it on stays a one-line decision rather than an outage.
    config(['hashing.rehash_on_login' => true]);

    $organization = org();

    $user = member($organization, UserRole::Member, [
        'password_hash' => password_hash('Demo12345', PASSWORD_BCRYPT, ['cost' => 10]),
    ]);

    $before = $user->password_hash;

    $this->post('/login', ['email' => $user->email, 'password' => 'Demo12345'])
        ->assertRedirect('/app');

    $after = $user->fresh()->password_hash;

    expect((new User)->getAuthPasswordName())->toBe('password_hash')
        ->and(Schema::hasColumn('users', 'password'))->toBeFalse()
        // Upgraded in place, and the account still opens with the same password.
        // The cost is read from config rather than hardcoded: phpunit.xml
        // lowers BCRYPT_ROUNDS so the suite is not spending seconds hashing.
        ->and($after)->not->toBe($before)
        ->and($after)->toStartWith(sprintf('$2y$%02d$', config('hashing.bcrypt.rounds')))
        ->and(Hash::check('Demo12345', $after))->toBeTrue();
});
