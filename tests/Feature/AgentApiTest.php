<?php

/**
 * The desktop agent's frozen API — `/webhooks/*`.
 *
 * Registration, signing and the happy path. The behaviours that are easy to
 * "improve" by accident — permissive validation, the replay that is accepted,
 * the 402, the raw-body screenshot — are in AgentContractTest.
 *
 * The end-to-end gate is neither file: `tools/test_webhook.py` must pass
 * UNMODIFIED against this API, and CI runs it (docs/migration/testing.md §1).
 */

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Device;
use App\Models\Task;
use App\Models\WorkSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * Call an endpoint the way the agent does: the signature is computed over the
 * RAW body, and the header is always sent — including for GET and DELETE,
 * where the body is the empty string.
 */
function signedCall(Device $device, string $method, string $uri, string $body = ''): TestResponse
{
    return test()->call($method, $uri, [], [], [], [
        'HTTP_X-DeskPulse-Device'    => (string) $device->id,
        'HTTP_X-DeskPulse-Signature' => hash_hmac('sha256', $body, $device->secret),
        'CONTENT_TYPE'               => 'application/json',
    ], $body);
}

/** A user whose password is stored the way the legacy application stores it. */
function agentUser($organization, string $email, string $password = 'pw12345')
{
    $user = member($organization, UserRole::Member, ['email' => $email]);

    $user->forceFill([
        // cost 4 to keep the suite fast; the point is that it is a real bcrypt
        // hash written outside Laravel, as every real row is.
        'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]),
    ])->save();

    return $user;
}

test('a device registers and can then sign calls', function () {
    $organization = org();
    $user = agentUser($organization, 'agent@acme.test');

    $registration = $this->postJson('/webhooks/auth', [
        'email'       => 'agent@acme.test',
        'password'    => 'pw12345',
        'device_name' => 'Laptop',
    ])->assertOk()->json();

    expect($registration['secret'])->toHaveLength(64)
        ->and($registration['secret'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($registration['user']['email'])->toBe('agent@acme.test');

    $device = Device::findOrFail($registration['device_id']);
    $device->secret = $registration['secret'];

    // Each of these signs an EMPTY body, which is a valid signature and not a
    // reason to skip verification.
    signedCall($device, 'GET', '/webhooks/me')->assertOk()->assertJsonPath('user.id', $user->id);
    signedCall($device, 'GET', '/webhooks/policy')->assertOk();
    signedCall($device, 'GET', '/webhooks/clients')->assertOk();
    signedCall($device, 'GET', '/webhooks/tasks')->assertOk();
});

test('registration is deliberately not idempotent', function () {
    // Every call inserts a NEW device row. The agent keeps whichever secret it
    // was last handed; de-duplicating would orphan a secret an installed agent
    // is still signing with.
    $organization = org();
    agentUser($organization, 'agent@acme.test');

    $first = $this->postJson('/webhooks/auth', ['email' => 'agent@acme.test', 'password' => 'pw12345'])->json();
    $second = $this->postJson('/webhooks/auth', ['email' => 'agent@acme.test', 'password' => 'pw12345'])->json();

    expect($second['device_id'])->not->toBe($first['device_id'])
        ->and($second['secret'])->not->toBe($first['secret'])
        ->and(Device::count())->toBe(2);
});

test('bad credentials are refused', function () {
    $organization = org();
    agentUser($organization, 'agent@acme.test');

    $this->postJson('/webhooks/auth', ['email' => 'agent@acme.test', 'password' => 'wrong'])
        ->assertStatus(401)
        ->assertExactJson(['error' => 'invalid credentials']);

    $this->postJson('/webhooks/auth', ['email' => 'nobody@acme.test', 'password' => 'pw12345'])
        ->assertStatus(401)
        ->assertExactJson(['error' => 'invalid credentials']);
});

test('a platform operator cannot register a device', function () {
    // Deferred from Phase 5, where the endpoint did not exist yet. A super
    // admin has no tracked time and must not appear in a tenant's monitoring.
    $platform = org(['name' => 'DeskPulse platform']);
    $super = member($platform, UserRole::SuperAdmin, ['email' => 'super@acme.test']);

    $super->forceFill([
        'password_hash' => password_hash('pw12345', PASSWORD_BCRYPT, ['cost' => 4]),
    ])->save();

    $this->postJson('/webhooks/auth', ['email' => 'super@acme.test', 'password' => 'pw12345'])
        ->assertStatus(403)
        ->assertExactJson(['error' => 'platform operators cannot run the tracker']);

    expect(Device::count())->toBe(0);
});

test('a tampered signature is refused', function () {
    $organization = org();
    $user = agentUser($organization, 'agent@acme.test');
    $device = Device::create(['user_id' => $user->id, 'name' => 'L', 'secret' => str_repeat('a', 64)]);

    $this->call('GET', '/webhooks/me', [], [], [], [
        'HTTP_X-DeskPulse-Device'    => (string) $device->id,
        'HTTP_X-DeskPulse-Signature' => str_repeat('0', 64),
    ])->assertStatus(401)->assertExactJson(['error' => 'bad signature']);
});

test('missing and unknown device headers are refused', function () {
    $this->getJson('/webhooks/me')
        ->assertStatus(401)
        ->assertExactJson(['error' => 'missing device header']);

    $this->call('GET', '/webhooks/me', [], [], [], [
        'HTTP_X-DeskPulse-Device'    => '999999',
        'HTTP_X-DeskPulse-Signature' => str_repeat('0', 64),
    ])->assertStatus(401)->assertExactJson(['error' => 'unknown device']);
});

test('a session runs its full lifecycle', function () {
    $organization = org();
    $user = agentUser($organization, 'agent@acme.test');
    $device = Device::create(['user_id' => $user->id, 'name' => 'L', 'secret' => str_repeat('a', 64)]);

    $body = json_encode(['started_at' => '2026-09-20T09:00:00Z']);
    $sessionId = signedCall($device, 'POST', '/webhooks/session', $body)->assertOk()->json('session_id');

    $session = WorkSession::findOrFail($sessionId);

    // Agent sessions are pre-approved; only manual entries wait on a manager.
    expect($session->source)->toBe('agent')
        ->and($session->approval_status)->toBe('approved');

    $body = json_encode([
        'samples'    => [['ts' => '2026-09-20T09:05:00Z', 'keyboard' => 5, 'mouse' => 3, 'pct' => 80]],
        'active_s'   => 300,
        'inactive_s' => 0,
    ]);
    signedCall($device, 'POST', "/webhooks/session/{$sessionId}/activity", $body)->assertOk();

    expect(DB::table('activity_samples')->where('session_id', $sessionId)->count())->toBe(1)
        ->and((int) $session->fresh()->active_s)->toBe(300);

    $body = json_encode(['ended_at' => '2026-09-20T10:00:00Z', 'active_s' => 3000, 'inactive_s' => 600]);
    signedCall($device, 'PATCH', "/webhooks/session/{$sessionId}", $body)->assertOk();

    expect((int) $session->fresh()->active_s)->toBe(3000)
        ->and($session->fresh()->ended_at)->not->toBeNull()
        // The overtime split runs on the ingest path, not at report time.
        ->and((int) $session->fresh()->overtime_computed)->toBe(1);
});

test('another users session is not found', function () {
    // "Not found" rather than "forbidden": the agent is told no more than that
    // the session is not its own.
    $organization = org();
    $mine = agentUser($organization, 'mine@acme.test');
    $theirs = agentUser($organization, 'theirs@acme.test');

    $device = Device::create(['user_id' => $mine->id, 'name' => 'L', 'secret' => str_repeat('a', 64)]);

    $other = WorkSession::create([
        'user_id'         => $theirs->id,
        'started_at'      => '2026-09-20 09:00:00',
        'source'          => 'agent',
        'approval_status' => 'approved',
    ]);

    signedCall($device, 'POST', "/webhooks/session/{$other->id}/activity", '{}')
        ->assertStatus(404)
        ->assertExactJson(['error' => 'session not found']);
});

test('a deleted account takes its devices with it', function () {
    // /webhooks/me answers 401 "account no longer exists" when the user row is
    // gone, and that 401 is how the agent detects a revoked account and stops.
    //
    // It is unreachable through user deletion: devices_ibfk_1 is ON DELETE
    // CASCADE, so the device disappears with its owner and the request fails
    // one step earlier, at "unknown device". The branch is kept because it is
    // the legacy behaviour, but what actually happens is asserted here.
    $organization = org();
    $user = agentUser($organization, 'agent@acme.test');
    $device = Device::create(['user_id' => $user->id, 'name' => 'L', 'secret' => str_repeat('a', 64)]);

    DB::table('users')->where('id', $user->id)->delete();

    expect(Device::whereKey($device->id)->exists())->toBeFalse();

    signedCall($device, 'GET', '/webhooks/me')
        ->assertStatus(401)
        ->assertExactJson(['error' => 'unknown device']);
});

test('a verified call stamps the device last seen', function () {
    $organization = org();
    $user = agentUser($organization, 'agent@acme.test');
    $device = Device::create([
        'user_id' => $user->id, 'name' => 'L', 'secret' => str_repeat('a', 64),
    ]);

    expect($device->fresh()->last_seen)->toBeNull();

    signedCall($device, 'GET', '/webhooks/me')->assertOk();

    expect($device->fresh()->last_seen)->not->toBeNull();
});
