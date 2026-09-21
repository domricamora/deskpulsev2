<?php

/**
 * Phase 17a — settings, profile, download and the first-run role guide.
 *
 * Three of these four are login-only routes that branch INSIDE the handler, so
 * a middleware test proves nothing about them. What is worth asserting is the
 * branching itself, and the three refusals that exist to stop an administrator
 * locking themselves — or everybody else — out.
 *
 * @see docs/migration/routes.md §2
 * @see docs/migration/authorization.md §2
 */

use App\Enums\UserRole;
use App\Models\Device;
use App\Models\Organization;
use App\Models\User;
use App\Support\LegacyCipher;
use App\Support\Token;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/* ── Settings: who gets the page, and how much of it ─────────────────────── */

test('a manager has no settings page and is sent home, not 403ed', function () {
    $this->actingAs(member(org(), UserRole::Manager))
        ->get('/app/settings')
        ->assertRedirect('/app');

    // The POST is a hard refusal — there is no page to bounce back to.
    $this->actingAs(member(org(), UserRole::Manager))
        ->post('/app/settings', ['action' => 'policy'])
        ->assertForbidden();
});

test('the four admin panels open only with org_settings', function () {
    $organization = org();

    // The panels are identified by the action each form posts, not by their
    // heading: the sidebar TOOLTIP for Settings contains the words "Monitoring
    // policy" and "company branding" for every role, so a heading match would
    // pass on a page that rendered no panels at all.
    $panels = ['value="policy"', 'value="upload_logo"', 'value="period_policy"', 'value="sso"'];

    // ClientAdmin and ItAdmin hold org_settings; a member and HR do not.
    foreach ([UserRole::ClientAdmin, UserRole::ItAdmin] as $role) {
        $page = $this->actingAs(member($organization, $role))->get('/app/settings')->assertOk();

        foreach ($panels as $panel) {
            $page->assertSee($panel, false);
        }
    }

    foreach ([UserRole::Member, UserRole::HrManager] as $role) {
        $page = $this->actingAs(member($organization, $role))
            ->get('/app/settings')
            ->assertOk()
            ->assertSee('Desktop agent');        // everybody gets this one

        foreach ($panels as $panel) {
            $page->assertDontSee($panel, false);
        }
    }
});

test('a member cannot save the monitoring policy by posting at it', function () {
    $organization = org(['screenshot_interval_min' => 10]);

    $this->actingAs(member($organization, UserRole::Member))
        ->post('/app/settings', ['action' => 'policy', 'screenshot_interval_min' => 1])
        ->assertForbidden();

    expect($organization->fresh()->screenshot_interval_min)->toBe(10);
});

test('the policy is clamped, because it drives what runs on somebody else machine', function () {
    $organization = org();

    $this->actingAs(member($organization, UserRole::ClientAdmin))
        ->post('/app/settings', [
            'action'                  => 'policy',
            'screenshot_interval_min' => 9999,
            'idle_threshold_min'      => 0,
            'sync_interval_s'         => 1,
            'track_screenshots'       => 'on',
        ])
        ->assertRedirect('/app/settings');

    $organization->refresh();

    expect($organization->screenshot_interval_min)->toBe(120)
        ->and($organization->idle_threshold_min)->toBe(1)
        ->and($organization->sync_interval_s)->toBe(15)
        // Absent checkboxes are OFF, not unchanged.
        ->and((int) $organization->track_windows)->toBe(0);
});

/* ── Single sign-on: the two refusals ────────────────────────────────────── */

test('single sign-on cannot be enforced unless it is first enabled', function () {
    $organization = org();

    $this->actingAs(member($organization, UserRole::ClientAdmin))
        ->post('/app/settings', [
            'action'        => 'sso',
            'sso_enforce'   => '1',
            'sso_issuer'    => 'https://login.example.com/v2.0',
            'sso_client_id' => 'abc123',
        ])
        ->assertRedirect('/app/settings');

    $organization->refresh();

    // Nothing was written at all — not the enforce flag, not the issuer.
    expect((int) $organization->sso_enforce)->toBe(0)
        ->and((int) $organization->sso_enabled)->toBe(0)
        ->and($organization->sso_issuer)->toBeNull();
});

test('single sign-on cannot be enabled without an issuer and a client id', function () {
    $organization = org();

    $this->actingAs(member($organization, UserRole::ClientAdmin))
        ->post('/app/settings', ['action' => 'sso', 'sso_enabled' => '1', 'sso_issuer' => 'https://idp.example.com'])
        ->assertRedirect('/app/settings');

    expect((int) $organization->fresh()->sso_enabled)->toBe(0);
});

test('the issuer must be https, because discovery drives every endpoint after it', function () {
    $organization = org();

    $this->actingAs(member($organization, UserRole::ClientAdmin))
        ->post('/app/settings', [
            'action'        => 'sso',
            'sso_issuer'    => 'http://idp.example.com',
            'sso_client_id' => 'abc123',
        ])
        ->assertRedirect('/app/settings');

    expect($organization->fresh()->sso_issuer)->toBeNull();
});

test('a blank client secret keeps the stored one rather than wiping it', function () {
    $organization = org(['sso_client_secret' => LegacyCipher::encrypt('the-real-secret')]);
    $stored = $organization->sso_client_secret;

    $this->actingAs(member($organization, UserRole::ClientAdmin))
        ->post('/app/settings', [
            'action'            => 'sso',
            'sso_enabled'       => '1',
            'sso_issuer'        => 'https://idp.example.com',
            'sso_client_id'     => 'abc123',
            'sso_client_secret' => '',          // the field renders masked
            'sso_domains'       => 'ACME.com, not a domain, acme.co.uk',
        ])
        ->assertRedirect('/app/settings');

    $organization->refresh();

    expect($organization->sso_client_secret)->toBe($stored)
        ->and(LegacyCipher::decrypt($organization->sso_client_secret))->toBe('the-real-secret')
        ->and((int) $organization->sso_enabled)->toBe(1)
        // Lowercased, and the junk entry dropped.
        ->and($organization->sso_domains)->toBe('acme.com,acme.co.uk');
});

test('a non-blank client secret replaces it', function () {
    $organization = org(['sso_client_secret' => LegacyCipher::encrypt('old')]);

    $this->actingAs(member($organization, UserRole::ClientAdmin))
        ->post('/app/settings', [
            'action'            => 'sso',
            'sso_enabled'       => '1',
            'sso_issuer'        => 'https://idp.example.com',
            'sso_client_id'     => 'abc123',
            'sso_client_secret' => 'new',
        ]);

    expect(LegacyCipher::decrypt($organization->fresh()->sso_client_secret))->toBe('new');
});

test('the stored secret never reaches the rendered page', function () {
    $organization = org(['sso_client_secret' => LegacyCipher::encrypt('the-real-secret')]);

    $this->actingAs(member($organization, UserRole::ClientAdmin))
        ->get('/app/settings')
        ->assertOk()
        ->assertDontSee('the-real-secret')
        // Neither the plaintext nor the ciphertext.
        ->assertDontSee($organization->sso_client_secret)
        ->assertSee('(stored)');
});

/* ── Period policy: the clock everything else is cut in ──────────────────── */

test('period settings are validated, not trusted', function () {
    $organization = org();

    $this->actingAs(member($organization, UserRole::ClientAdmin))
        ->post('/app/settings', [
            'action'           => 'period_policy',
            'report_tz'        => 'Mars/Olympus',
            'week_start'       => 99,
            'pay_cycle'        => 'whenever',
            'pay_cycle_anchor' => 'not-a-date',
            'pay_currency'     => 'php',
        ])
        ->assertRedirect('/app/settings');

    $organization->refresh();

    expect($organization->report_tz)->toBe('UTC')
        ->and((int) $organization->week_start)->toBe(7)
        ->and($organization->pay_cycle)->toBe('semimonthly')
        ->and($organization->pay_cycle_anchor)->toBeNull()
        ->and($organization->pay_currency)->toBe('PHP');
});

test('a real timezone is accepted and shown back', function () {
    $organization = org();

    $this->actingAs(member($organization, UserRole::ClientAdmin))
        ->post('/app/settings', [
            'action'     => 'period_policy',
            'report_tz'  => 'Asia/Manila',
            'week_start' => 7,
            'pay_cycle'  => 'biweekly',
            'pay_cycle_anchor' => '2026-01-05',
        ]);

    expect($organization->fresh()->report_tz)->toBe('Asia/Manila');
});

/* ── Devices on settings are your OWN ────────────────────────────────────── */

test('the settings device list is only yours, and removal is scoped to the owner', function () {
    $organization = org();
    $mine = member($organization, UserRole::Member, ['name' => 'Ana']);
    $theirs = member($organization, UserRole::Member, ['name' => 'Bo']);

    $myDevice = Device::create(['user_id' => $mine->id, 'name' => 'Ana laptop', 'secret' => Token::hex(32)]);
    $theirDevice = Device::create(['user_id' => $theirs->id, 'name' => 'Bo laptop', 'secret' => Token::hex(32)]);

    $this->actingAs($mine)
        ->get('/app/settings')
        ->assertOk()
        ->assertSee('Ana laptop')
        ->assertDontSee('Bo laptop');

    // Even a colleague in the same tenant cannot remove somebody else's install
    // from here. /app/devices is where that happens, behind `devices`.
    $this->actingAs($mine)->post('/app/settings', [
        'action' => 'device_delete', 'device_id' => $theirDevice->id,
    ])->assertRedirect('/app/settings');

    expect(Device::query()->whereKey($theirDevice->id)->exists())->toBeTrue();

    $this->actingAs($mine)->post('/app/settings', [
        'action' => 'device_delete', 'device_id' => $myDevice->id,
    ]);

    expect(Device::query()->whereKey($myDevice->id)->exists())->toBeFalse();
});

/* ── Profile ─────────────────────────────────────────────────────────────── */

test('every role has a profile, including a client portal viewer', function () {
    $organization = org();

    foreach (UserRole::cases() as $role) {
        $this->actingAs(member($organization, $role))
            ->get('/app/profile')
            ->assertOk()
            ->assertSee('Profile information');
    }
});

test('the profile saves name, contact details and an optional password', function () {
    $user = member(org(), UserRole::Member);

    $this->actingAs($user)->post('/app/profile', [
        'name'      => 'Ana Cruz',
        'email'     => 'ana@example.test',
        'phone'     => 'x',
        'job_title' => 'Analyst',
    ])->assertRedirect('/app/profile');

    $user->refresh();

    expect($user->name)->toBe('Ana Cruz')
        ->and($user->email)->toBe('ana@example.test')
        ->and($user->job_title)->toBe('Analyst');
});

test('the duplicate email check is global and excludes the person editing', function () {
    $taken = member(org(), UserRole::Member, ['email' => 'taken@example.test']);
    $user = member(org(), UserRole::Member, ['email' => 'mine@example.test', 'name' => 'Before']);

    // Somebody else's address, in a DIFFERENT tenant — users.email is unique
    // table-wide because it is the login identifier.
    $this->actingAs($user)
        ->post('/app/profile', ['name' => 'After', 'email' => $taken->email])
        ->assertRedirect('/app/profile');

    expect($user->fresh()->name)->toBe('Before');

    // Your own address is not a duplicate of itself.
    $this->actingAs($user)->post('/app/profile', ['name' => 'After', 'email' => 'mine@example.test']);

    expect($user->fresh()->name)->toBe('After');
});

test('a short password is refused and nothing else is saved either', function () {
    $user = member(org(), UserRole::Member, ['name' => 'Before']);
    $hash = $user->password_hash;

    $this->actingAs($user)->post('/app/profile', [
        'name' => 'After', 'email' => $user->email, 'password' => 'short',
    ])->assertRedirect('/app/profile');

    $user->refresh();

    expect($user->name)->toBe('Before')
        ->and($user->password_hash)->toBe($hash);
});

test('a client portal viewer sees no pay figures on their own profile', function () {
    $organization = org();
    $viewer = member($organization, UserRole::ClientViewer, ['pay_rate' => 99.5]);

    $this->actingAs($viewer)
        ->get('/app/profile')
        ->assertOk()
        ->assertDontSee('Pay rate')
        ->assertDontSee('99.5');
});

test('the client bill rate is shown only to someone who may see rates', function () {
    $organization = org();
    $rate = member($organization, UserRole::Member, ['pay_rate' => 20, 'bill_rate' => 75]);

    $this->actingAs($rate)
        ->get('/app/profile')
        ->assertOk()
        ->assertSee('Pay rate')
        ->assertDontSee('Bill (client)');

    $admin = member($organization, UserRole::ClientAdmin, ['pay_rate' => 20, 'bill_rate' => 75]);

    $this->actingAs($admin)->get('/app/profile')->assertOk()->assertSee('Bill (client)');
});

/* ── Download ────────────────────────────────────────────────────────────── */

test('the download page shows the public base URL the agent asks for', function () {
    $user = member(org(), UserRole::Member);

    $this->actingAs($user)
        ->get('/app/download')
        ->assertOk()
        ->assertSee(rtrim(url('/'), '/'))
        ->assertSee('Download for Windows')
        ->assertSee('No devices yet');
});

/* ── The first-run role guide ────────────────────────────────────────────── */

test('the guide is tailored to the role that opens it', function () {
    $organization = org();

    $this->actingAs(member($organization, UserRole::ItAdmin))
        ->get('/app/welcome')
        ->assertOk()
        ->assertSee('Welcome, IT admin')
        ->assertSee('Devices');

    $this->actingAs(member($organization, UserRole::ClientViewer))
        ->get('/app/welcome')
        ->assertOk()
        ->assertSee('Welcome to your client portal')
        ->assertDontSee('Welcome, IT admin');
});

test('a platform operator has no tenant guide to read', function () {
    $this->actingAs(member(org(), UserRole::SuperAdmin))
        ->get('/app/welcome')
        ->assertRedirect('/app/platform');
});

test('the step is clamped into the guide rather than trusted', function () {
    $user = member(org(), UserRole::Member);

    // One past the last card IS the done step; anything beyond it lands there.
    $this->actingAs($user)->get('/app/welcome?step=999')->assertOk()->assertSee("You're all set", false);
    $this->actingAs($user)->get('/app/welcome?step=-5')->assertOk()->assertSee('Your role');
});

test('welcomed_at is stamped once and never restamped', function () {
    $user = member(org(), UserRole::Member);

    expect($user->welcomed_at)->toBeNull();

    $this->actingAs($user)->post('/app/welcome', ['action' => 'dismiss', 'to' => '/app/overview'])
        ->assertRedirect('/app/overview');

    $first = $user->fresh()->welcomed_at;

    expect($first)->not->toBeNull();

    // Reopening the guide later must not move the column — the first-run
    // redirect reads it as "when did this person first sign in".
    $user->forceFill(['welcomed_at' => '2020-01-01 00:00:00'])->save();

    $this->actingAs($user)->post('/app/welcome', ['action' => 'dismiss']);

    expect($user->fresh()->welcomed_at->format('Y'))->toBe('2020');
});

test('the dismiss destination cannot be pointed off-site', function () {
    $user = member(org(), UserRole::Member);

    $this->actingAs($user)
        ->post('/app/welcome', ['action' => 'dismiss', 'to' => 'https://evil.example.com/steal'])
        ->assertRedirect('/app/overview');
});

/* ── All four need a login ───────────────────────────────────────────────── */

test('none of the account pages is reachable signed out', function () {
    foreach (['/app/settings', '/app/profile', '/app/download', '/app/welcome'] as $path) {
        // The `next` parameter is what returns somebody to the page they asked
        // for after signing in.
        $this->get($path)->assertRedirect('/login?next=' . rawurlencode($path));
    }
});
