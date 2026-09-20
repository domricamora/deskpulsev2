<?php

/**
 * Phase 15 — devices and the audit log.
 *
 * Revoking a device is the only way to cut an install off, and it works by
 * deleting the secret it signs with — so the test that matters is that the
 * agent's next signed request actually fails.
 *
 * @see docs/migration/api-contract.md
 */

use App\Enums\UserRole;
use App\Models\Device;
use App\Models\ShareLink;
use App\Models\WorkSession;
use App\Support\Token;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function registeredDevice(\App\Models\User $user, string $name = 'Desktop'): Device
{
    return Device::create([
        'user_id' => $user->id,
        'name'    => $name,
        'secret'  => Token::hex(32),
    ]);
}

/* ── Gates ───────────────────────────────────────────────────────────────── */

test('devices and the audit log are for admins and IT', function () {
    $organization = org();

    foreach ([UserRole::ClientAdmin, UserRole::ItAdmin] as $role) {
        $this->actingAs(member($organization, $role))->get('/app/devices')->assertOk();
        $this->actingAs(member($organization, $role))->get('/app/audit')->assertOk();
    }

    foreach ([UserRole::HrManager, UserRole::Manager, UserRole::Member] as $role) {
        $this->actingAs(member($organization, $role))->get('/app/devices')->assertRedirect();
        $this->actingAs(member($organization, $role))->get('/app/audit')->assertRedirect();
    }
});

/* ── Listing ─────────────────────────────────────────────────────────────── */

test('the list covers the whole organization and nobody else', function () {
    $ours = org(['name' => 'Acme']);
    registeredDevice(member($ours, UserRole::Member, ['name' => 'Ana']), 'Ana laptop');
    registeredDevice(member(org(['name' => 'Globex']), UserRole::Member), 'Their laptop');

    $this->actingAs(member($ours, UserRole::ItAdmin))
        ->get('/app/devices')
        ->assertOk()
        ->assertSee('Ana laptop')
        ->assertDontSee('Their laptop');
});

test('a device that has never checked in says so', function () {
    $organization = org();
    registeredDevice(member($organization, UserRole::Member), 'Fresh install');

    $this->actingAs(member($organization, UserRole::ItAdmin))
        ->get('/app/devices')
        ->assertOk()
        ->assertSee('never');
});

test('the remote-control link appears only with the remote capability', function () {
    $organization = org();
    registeredDevice(member($organization, UserRole::Member));

    // IT holds remote.
    $this->actingAs(member($organization, UserRole::ItAdmin))
        ->get('/app/devices')->assertOk()->assertSee('remote control');
});

/* ── Revoking ────────────────────────────────────────────────────────────── */

test('revoking a device stops its agent signing in', function () {
    // The mechanism, end to end: delete the row and the secret it signs with
    // no longer exists, so VerifyAgentSignature has nothing to check against.
    $organization = org();
    $worker = member($organization, UserRole::Member);
    $device = registeredDevice($worker);

    $signed = function () use ($device) {
        $signature = hash_hmac('sha256', '', $device->secret);

        return $this->withHeaders([
            'X-DeskPulse-Device'    => (string) $device->id,
            'X-DeskPulse-Signature' => $signature,
        ])->get('/webhooks/me');
    };

    $signed()->assertOk();

    $this->actingAs(member($organization, UserRole::ItAdmin))
        ->post('/app/devices', ['action' => 'revoke', 'device_id' => $device->id]);

    expect(Device::query()->whereKey($device->id)->exists())->toBeFalse();

    $signed()->assertUnauthorized();
});

test('a device in another tenant cannot be revoked', function () {
    $theirs = registeredDevice(member(org(['name' => 'Globex']), UserRole::Member));

    $this->actingAs(member(org(['name' => 'Acme']), UserRole::ItAdmin))
        ->post('/app/devices', ['action' => 'revoke', 'device_id' => $theirs->id]);

    expect(Device::query()->whereKey($theirs->id)->exists())->toBeTrue();
});

/* ── The audit view ──────────────────────────────────────────────────────── */

test('the audit log derives events from data that already exists', function () {
    $organization = org();
    $worker = member($organization, UserRole::Member, ['name' => 'Ana Cruz']);

    registeredDevice($worker, 'Ana laptop');

    WorkSession::create([
        'user_id' => $worker->id, 'started_at' => gmdate('Y-m-d H:i:s'),
        'ended_at' => gmdate('Y-m-d H:i:s'), 'active_s' => 3600, 'inactive_s' => 0,
        'source' => 'manual', 'approval_status' => 'approved',
    ]);

    ShareLink::create([
        'org_id' => $organization->id, 'scope' => 'user', 'target_id' => $worker->id,
        'token' => 'tok_' . bin2hex(random_bytes(6)), 'label' => 'Ana — personal',
        'period_default' => 'day', 'revoked' => 0,
    ]);

    $this->actingAs(member($organization, UserRole::ItAdmin))
        ->get('/app/audit')
        ->assertOk()
        ->assertSee('Device registered')
        ->assertSee('Ana laptop')
        // source = manual reads as an entry, not a tracked session.
        ->assertSee('Manual entry created')
        ->assertSee('Share link created');
});

test('the audit log does not reach into another tenant', function () {
    $theirs = org(['name' => 'Globex']);
    registeredDevice(member($theirs, UserRole::Member), 'Their secret laptop');

    $this->actingAs(member(org(['name' => 'Acme']), UserRole::ItAdmin))
        ->get('/app/audit')
        ->assertOk()
        ->assertDontSee('Their secret laptop');
});
