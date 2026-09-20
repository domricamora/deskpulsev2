<?php

/**
 * Password reset on the legacy `password_resets` table.
 *
 * The properties under test are the ones that make a reset token safe to email:
 * it is stored hashed, it works once, it expires, it cannot be used to discover
 * which addresses have accounts, and the mail goes out now rather than waiting
 * for a drain that this deployment does not run.
 *
 * @see docs/migration/authentication.md §5
 */

use App\Enums\UserRole;
use App\Models\EmailOutbox;
use App\Models\Organization;
use App\Models\PasswordReset;
use App\Models\User;
use App\Services\Auth\PasswordResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function resetUser(string $email = 'reset@example.test'): User
{
    $org = Organization::create(['name' => 'Acme', 'status' => 'approved']);

    return User::create([
        'org_id'        => $org->id,
        'name'          => 'Reset Tester',
        'email'         => $email,
        'password_hash' => bcrypt('old-password'),
        'role'          => UserRole::Member,
    ]);
}

/** Request a link and dig the raw token back out of the emailed URL. */
function requestResetToken(User $user): string
{
    app(PasswordResetService::class)->send($user, '203.0.113.7');

    $html = (string) EmailOutbox::query()->latest('id')->value('body_html');

    expect($html)->toContain('reset-password?token=');
    preg_match('/reset-password\?token=([A-Za-z0-9]+)/', $html, $m);

    return $m[1];
}

beforeEach(function () {
    Mail::fake();
});

/* ── Storage ─────────────────────────────────────────────────────────────── */

test('the token is stored hashed, never in the clear', function () {
    $user = resetUser();
    $token = requestResetToken($user);

    $row = PasswordReset::query()->where('user_id', $user->id)->firstOrFail();

    expect($row->token_hash)->toBe(hash('sha256', $token))
        ->and($row->token_hash)->not->toBe($token)
        ->and(strlen($row->token_hash))->toBe(64);
});

test('the requesting address and an expiry are recorded', function () {
    $user = resetUser();
    requestResetToken($user);

    $row = PasswordReset::query()->where('user_id', $user->id)->firstOrFail();

    expect($row->requested_ip)->toBe('203.0.113.7')
        ->and($row->expires_at->isFuture())->toBeTrue()
        ->and($row->used_at)->toBeNull();
});

/* ── Enumeration ─────────────────────────────────────────────────────────── */

test('the answer is identical whether or not the address exists', function () {
    resetUser('known@example.test');

    $known = $this->post('/forgot-password', ['email' => 'known@example.test']);
    $unknown = $this->post('/forgot-password', ['email' => 'nobody@example.test']);

    $known->assertSuccessful();
    $unknown->assertSuccessful();

    expect($known->getContent())->toBe($unknown->getContent());
});

test('an unknown address produces no row and no mail', function () {
    $this->post('/forgot-password', ['email' => 'nobody@example.test'])->assertSuccessful();

    expect(PasswordReset::count())->toBe(0)
        ->and(EmailOutbox::count())->toBe(0);
});

/* ── Rate limiting ───────────────────────────────────────────────────────── */

test('a single address is limited per hour', function () {
    $user = resetUser();
    $service = app(PasswordResetService::class);
    $limit = (int) config('deskpulse.password_reset.max_per_hour');

    for ($i = 0; $i < $limit + 3; $i++) {
        $service->request($user->email, '198.51.100.4');
    }

    expect(PasswordReset::count())->toBe($limit);
});

test('the per-IP limit counts unknown addresses too', function () {
    // Otherwise the limiter itself becomes the oracle: an address that never
    // consumed quota is an address with no account.
    $user = resetUser();
    $service = app(PasswordResetService::class);
    $ipLimit = (int) config('deskpulse.password_reset.max_per_hour')
        * (int) config('deskpulse.password_reset.ip_multiplier');

    for ($i = 0; $i < $ipLimit; $i++) {
        PasswordReset::create([
            'user_id'      => $user->id,
            'token_hash'   => hash('sha256', 'filler-' . $i),
            'requested_ip' => '198.51.100.9',
            'expires_at'   => now()->addHour(),
        ]);
    }

    $service->request($user->email, '198.51.100.9');

    expect(PasswordReset::count())->toBe($ipLimit);
});

/* ── Using the link ──────────────────────────────────────────────────────── */

test('a valid token sets the password and signs the user in', function () {
    $user = resetUser();
    $token = requestResetToken($user);

    $this->post('/reset-password', [
        'token'            => $token,
        'password'         => 'a-brand-new-password',
        'password_confirm' => 'a-brand-new-password',
    ])->assertRedirect('/app');

    expect(Hash::check('a-brand-new-password', $user->fresh()->password_hash))->toBeTrue()
        ->and(auth()->id())->toBe($user->id);
});

test('a reset clears a pending forced password change', function () {
    $user = resetUser();
    $user->forceFill(['must_change_password' => 1])->save();
    $token = requestResetToken($user);

    $this->post('/reset-password', [
        'token'            => $token,
        'password'         => 'chosen-by-the-user',
        'password_confirm' => 'chosen-by-the-user',
    ])->assertRedirect('/app');

    expect($user->fresh()->must_change_password)->toBeFalsy();
});

test('a token works exactly once', function () {
    $user = resetUser();
    $token = requestResetToken($user);

    $this->post('/reset-password', [
        'token'            => $token,
        'password'         => 'first-attempt-wins',
        'password_confirm' => 'first-attempt-wins',
    ])->assertRedirect('/app');

    auth()->logout();

    $this->post('/reset-password', [
        'token'            => $token,
        'password'         => 'second-attempt-loses',
        'password_confirm' => 'second-attempt-loses',
    ])->assertSee('already been used', false);

    expect(Hash::check('first-attempt-wins', $user->fresh()->password_hash))->toBeTrue();
});

test('using one token burns every other outstanding token for the account', function () {
    // A second request sitting in the inbox must not stay usable.
    $user = resetUser();
    $stale = requestResetToken($user);
    $fresh = requestResetToken($user);

    $this->post('/reset-password', [
        'token'            => $fresh,
        'password'         => 'the-new-password',
        'password_confirm' => 'the-new-password',
    ])->assertRedirect('/app');

    auth()->logout();

    $this->post('/reset-password', [
        'token'            => $stale,
        'password'         => 'should-not-work',
        'password_confirm' => 'should-not-work',
    ])->assertSee('already been used', false);

    expect(PasswordReset::whereNull('used_at')->count())->toBe(0);
});

test('an expired token is refused and says so', function () {
    $user = resetUser();
    $token = requestResetToken($user);

    PasswordReset::query()->update(['expires_at' => now()->subMinute()]);

    $this->get('/reset-password?token=' . $token)->assertSee('has expired', false);
});

test('a token that was never issued is refused', function () {
    resetUser();

    $this->get('/reset-password?token=never-minted')->assertSee('not valid', false);
    $this->get('/reset-password')->assertSee('incomplete', false);
});

test('the two passwords must match and must be long enough', function () {
    $user = resetUser();
    $token = requestResetToken($user);

    $this->post('/reset-password', [
        'token'            => $token,
        'password'         => 'short',
        'password_confirm' => 'short',
    ])->assertSee('at least 8 characters', false);

    $this->post('/reset-password', [
        'token'            => $token,
        'password'         => 'long-enough-one',
        'password_confirm' => 'long-enough-two',
    ])->assertSee('do not match', false);

    expect(Hash::check('old-password', $user->fresh()->password_hash))->toBeTrue();
});

/* ── Delivery ────────────────────────────────────────────────────────────── */

test('the reset mail is queued as a durable row and sent immediately', function () {
    // Queued first: the outbox row is the retry state and the admin-visible
    // email log. Sent now: a reset link that waits for cron is a support ticket,
    // and this deployment has no cron drainer.
    $user = resetUser();
    requestResetToken($user);

    $row = EmailOutbox::query()->latest('id')->firstOrFail();

    expect($row->kind)->toBe('password_reset')
        ->and($row->to_email)->toBe($user->email)
        ->and($row->status)->toBe('sent')
        ->and($row->sent_at)->not->toBeNull()
        ->and($row->body_text)->toContain('valid for 60 minutes');

    Mail::assertSentCount(1);
});
