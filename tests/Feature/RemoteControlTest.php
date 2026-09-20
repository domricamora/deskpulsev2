<?php

/**
 * Phase 16 — remote desktop control.
 *
 * Somebody watching and driving another person's machine. The tests that
 * matter are the containment ones: a session cannot outlive its limits, a
 * foreign device is not even visible, and the live screen is not fetchable
 * without authorization.
 *
 * @see docs/migration/remote-control.md
 */

use App\Enums\UserRole;
use App\Models\Device;
use App\Models\RemoteSession;
use App\Models\User;
use App\Services\Remote\RemoteSessions;
use App\Support\Token;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('private');
});

function controllable(User $worker, string $name = 'Desktop'): Device
{
    return Device::create(['user_id' => $worker->id, 'name' => $name, 'secret' => Token::hex(32)]);
}

function controlSession(Device $device, User $worker, array $attributes = []): RemoteSession
{
    return RemoteSession::create(array_merge([
        'device_id'     => $device->id,
        'user_id'       => $worker->id,
        // The column has no default: every session records who started it.
        'admin_user_id' => $worker->id,
        'org_id'        => $worker->org_id,
        'token'         => Token::hex(24),
        'status'        => 'pending',
    ], $attributes));
}

/**
 * A signed agent request, as agent/monitor/remote.py makes them.
 *
 * The headers go in the SERVER array rather than through withHeaders(): the
 * signature covers the raw body, and this is the form that reaches the
 * middleware with the body intact.
 */
function agentCall(Device $device, string $method, string $path, string $body = '')
{
    return test()->call($method, $path, [], [], [], [
        'HTTP_X-DeskPulse-Device'    => (string) $device->id,
        'HTTP_X-DeskPulse-Signature' => hash_hmac('sha256', $body, $device->secret),
    ], $body);
}

/* ── Expiry is the containment mechanism ─────────────────────────────────── */

test('an active session with no frames is ended as agent_gone', function () {
    $organization = org();
    $worker = member($organization, UserRole::Member);
    $session = controlSession(controllable($worker), $worker, [
        'status'        => 'active',
        'last_frame_at' => gmdate('Y-m-d H:i:s', time() - RemoteSessions::AGENT_TIMEOUT_S - 5),
    ]);

    app(RemoteSessions::class)->collect();

    expect($session->fresh()->status)->toBe('ended')
        ->and($session->fresh()->end_reason)->toBe('agent_gone');
});

test('an active session with no admin input expires', function () {
    $organization = org();
    $worker = member($organization, UserRole::Member);
    $session = controlSession(controllable($worker), $worker, [
        'status'        => 'active',
        'last_frame_at' => gmdate('Y-m-d H:i:s'),
        'last_input_at' => gmdate('Y-m-d H:i:s', time() - RemoteSessions::IDLE_MAX_S - 5),
    ]);

    app(RemoteSessions::class)->collect();

    expect($session->fresh()->status)->toBe('ended')
        ->and($session->fresh()->end_reason)->toBe('expired');
});

test('a pending session nobody picked up expires', function () {
    $organization = org();
    $worker = member($organization, UserRole::Member);
    $session = controlSession(controllable($worker), $worker, [
        'started_at' => gmdate('Y-m-d H:i:s', time() - RemoteSessions::PENDING_TIMEOUT_S - 5),
    ]);

    app(RemoteSessions::class)->collect();

    expect($session->fresh()->status)->toBe('ended');
});

test('a healthy session survives collection', function () {
    $organization = org();
    $worker = member($organization, UserRole::Member);
    $session = controlSession(controllable($worker), $worker, [
        'status'        => 'active',
        'last_frame_at' => gmdate('Y-m-d H:i:s'),
        'last_input_at' => gmdate('Y-m-d H:i:s'),
    ]);

    app(RemoteSessions::class)->collect();

    expect($session->fresh()->status)->toBe('active');
});

/* ── The agent side ──────────────────────────────────────────────────────── */

test('polling activates a pending session and dictates the stream', function () {
    $organization = org();
    $worker = member($organization, UserRole::Member);
    $device = controllable($worker);
    $session = controlSession($device, $worker);

    $response = agentCall($device, 'GET', '/webhooks/remote/poll')->assertOk();

    expect($session->fresh()->status)->toBe('active')
        ->and($response->json('session.id'))->toBe($session->id)
        // Server-dictated, not negotiable by the agent.
        ->and($response->json('session.fps'))->toBe(RemoteSessions::FPS)
        ->and($response->json('session.max_width'))->toBe(RemoteSessions::MAX_WIDTH)
        ->and($response->json('session.quality'))->toBe(RemoteSessions::QUALITY);
});

test('an idle poll answers with a null session', function () {
    $organization = org();
    $device = controllable(member($organization, UserRole::Member));

    agentCall($device, 'GET', '/webhooks/remote/poll')
        ->assertOk()
        ->assertJsonPath('session', null);
});

test('a frame is stored privately and records the screen size once', function () {
    $organization = org();
    $worker = member($organization, UserRole::Member);
    $device = controllable($worker);
    $session = controlSession($device, $worker, ['status' => 'active']);

    agentCall($device, 'POST', "/webhooks/remote/{$session->id}/frame?w=1920&h=1080", 'JPEGBYTES')
        ->assertOk()
        ->assertJsonPath('status', 'active');

    $fresh = $session->fresh();

    expect($fresh->screen_w)->toBe(1920)
        ->and($fresh->screen_h)->toBe(1080)
        ->and($fresh->frame_seq)->toBe(1)
        ->and(Storage::disk('private')->get("remote/{$session->id}.jpg"))->toBe('JPEGBYTES')
        // Never under the document root — the legacy wrote it there at a
        // sequential, guessable id.
        ->and(is_file(public_path("uploads/remote/{$session->id}.jpg")))->toBeFalse();

    // A later frame must not overwrite the recorded resolution.
    agentCall($device, 'POST', "/webhooks/remote/{$session->id}/frame?w=800&h=600", 'MORE');

    expect($session->fresh()->screen_w)->toBe(1920);
});

test('a frame for somebody else device is refused as ended', function () {
    $organization = org();
    $worker = member($organization, UserRole::Member);
    $session = controlSession(controllable($worker), $worker, ['status' => 'active']);

    $otherDevice = controllable(member($organization, UserRole::Member));

    agentCall($otherDevice, 'POST', "/webhooks/remote/{$session->id}/frame", 'JPEG')
        ->assertOk()
        ->assertJsonPath('status', 'ended');
});

test('input is delivered at most once', function () {
    // Commands are deleted as they are handed over. Re-delivering them would
    // risk replaying clicks and keystrokes onto somebody's machine.
    $organization = org();
    $worker = member($organization, UserRole::Member);
    $device = controllable($worker);
    $session = controlSession($device, $worker, ['status' => 'active']);

    DB::table('remote_input_events')->insert([
        'session_id' => $session->id,
        'payload'    => json_encode(['type' => 'click', 'x' => 0.5, 'y' => 0.5]),
    ]);

    $first = agentCall($device, 'POST', "/webhooks/remote/{$session->id}/frame", 'JPEG')->assertOk();
    $second = agentCall($device, 'POST', "/webhooks/remote/{$session->id}/frame", 'JPEG')->assertOk();

    expect($first->json('commands'))->toHaveCount(1)
        ->and($second->json('commands'))->toBe([])
        ->and(DB::table('remote_input_events')->count())->toBe(0);
});

test('the agent can end its own session and the frame goes with it', function () {
    $organization = org();
    $worker = member($organization, UserRole::Member);
    $device = controllable($worker);
    $session = controlSession($device, $worker, ['status' => 'active']);

    Storage::disk('private')->put("remote/{$session->id}.jpg", 'JPEG');

    agentCall($device, 'POST', "/webhooks/remote/{$session->id}/end")->assertOk();

    expect($session->fresh()->status)->toBe('ended')
        ->and($session->fresh()->end_reason)->toBe('agent_gone')
        // The live screen does not outlive the session.
        ->and(Storage::disk('private')->exists("remote/{$session->id}.jpg"))->toBeFalse();
});

/* ── The admin side ──────────────────────────────────────────────────────── */

test('remote control needs the capability', function () {
    $organization = org();
    $device = controllable(member($organization, UserRole::Member));

    foreach ([UserRole::ClientAdmin, UserRole::ItAdmin] as $role) {
        $this->actingAs(member($organization, $role))->get("/app/remote/{$device->id}")->assertOk();
    }

    foreach ([UserRole::HrManager, UserRole::Manager, UserRole::Member] as $role) {
        $this->actingAs(member($organization, $role))->get("/app/remote/{$device->id}")->assertRedirect();
    }
});

test('a device in another tenant is a 404, not a 403', function () {
    // A 403 would confirm the device exists.
    $theirs = controllable(member(org(['name' => 'Globex']), UserRole::Member));

    $this->actingAs(member(org(['name' => 'Acme']), UserRole::ItAdmin))
        ->get("/app/remote/{$theirs->id}")
        ->assertNotFound();
});

test('a platform operator reaches any tenant device', function () {
    $super = member(org(['name' => 'Platform']), UserRole::SuperAdmin);
    $device = controllable(member(org(['name' => 'Acme']), UserRole::Member));

    $this->actingAs($super)->get("/app/remote/{$device->id}")->assertOk();
});

test('starting a session ends any other one on that device', function () {
    $organization = org();
    $worker = member($organization, UserRole::Member);
    $device = controllable($worker);
    $existing = controlSession($device, $worker, ['status' => 'active']);

    $response = $this->actingAs(member($organization, UserRole::ItAdmin))
        ->post("/app/remote/{$device->id}/start")
        ->assertOk();

    expect($existing->fresh()->status)->toBe('ended')
        ->and($existing->fresh()->end_reason)->toBe('admin')
        ->and($response->json('session_id'))->toBeGreaterThan($existing->id);
});

test('a frame is not readable without the capability', function () {
    $organization = org();
    $worker = member($organization, UserRole::Member);
    $session = controlSession(controllable($worker), $worker, ['status' => 'active']);

    Storage::disk('private')->put("remote/{$session->id}.jpg", 'JPEG');

    // An admin who holds `remote` gets it…
    $this->actingAs(member($organization, UserRole::ItAdmin))
        ->get("/app/remote/{$session->id}/frame")
        ->assertOk()
        ->assertHeader('content-type', 'image/jpeg');

    // …a manager does not, and neither does another tenant's admin.
    $this->actingAs(member($organization, UserRole::Manager))
        ->get("/app/remote/{$session->id}/frame")
        ->assertRedirect();

    $this->actingAs(member(org(['name' => 'Globex']), UserRole::ItAdmin))
        ->get("/app/remote/{$session->id}/frame")
        ->assertNotFound();
});

test('input is only queued for an active session', function () {
    $organization = org();
    $worker = member($organization, UserRole::Member);
    $admin = member($organization, UserRole::ItAdmin);

    $pending = controlSession(controllable($worker), $worker);
    $payload = json_encode(['events' => [['type' => 'click', 'x' => 0.5, 'y' => 0.5]]]);

    $this->actingAs($admin)
        ->post("/app/remote/{$pending->id}/input", ['payload' => $payload])
        ->assertOk()
        ->assertJsonPath('ok', false);

    expect(DB::table('remote_input_events')->count())->toBe(0);

    $pending->forceFill(['status' => 'active'])->save();

    $this->actingAs($admin)
        ->post("/app/remote/{$pending->id}/input", ['payload' => $payload])
        ->assertOk()
        ->assertJsonPath('queued', 1);

    expect(DB::table('remote_input_events')->count())->toBe(1)
        // Queuing input resets the ten-minute idle clock.
        ->and($pending->fresh()->last_input_at)->not->toBeNull();
});

test('stopping ends the session and deletes the frame', function () {
    $organization = org();
    $worker = member($organization, UserRole::Member);
    $session = controlSession(controllable($worker), $worker, ['status' => 'active']);

    Storage::disk('private')->put("remote/{$session->id}.jpg", 'JPEG');

    $this->actingAs(member($organization, UserRole::ItAdmin))
        ->post("/app/remote/{$session->id}/stop")
        ->assertOk();

    expect($session->fresh()->status)->toBe('ended')
        ->and($session->fresh()->end_reason)->toBe('admin')
        ->and(Storage::disk('private')->exists("remote/{$session->id}.jpg"))->toBeFalse();
});
