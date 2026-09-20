<?php

/**
 * The parts of the frozen agent contract that are easy to "improve" by
 * accident, and that would each break a deployed agent if improved.
 *
 * Companion to AgentApiTest, which covers registration, signing and the happy
 * path. Everything here asserts a behaviour that looks wrong until you know
 * why it is that way — several of them assert that the server does NOT do the
 * obviously better thing.
 *
 * @see docs/migration/api-contract.md §3, §4
 * @see docs/migration/agent-protocol.md §4
 */

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Device;
use App\Models\Task;
use App\Models\WorkSession;
use App\Support\PlanLimits;
use App\Support\Uploads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** An organization, a user and a registered device, ready to sign. */
function agentFixture(array $organizationAttributes = []): array
{
    $organization = org($organizationAttributes);
    $user = member($organization, UserRole::Member);
    $device = Device::create([
        'user_id' => $user->id,
        'name'    => 'Laptop',
        'secret'  => str_repeat('a', 64),
    ]);

    return [$organization, $user, $device];
}

function openSession(int $userId, array $attributes = []): WorkSession
{
    return WorkSession::create(array_merge([
        'user_id'         => $userId,
        'started_at'      => '2026-09-20 09:00:00',
        'source'          => 'agent',
        'approval_status' => 'approved',
    ], $attributes));
}

/* ── Signing ─────────────────────────────────────────────────────────────── */

test('the signature covers the raw bytes, not a re-encoded body', function () {
    // Laravel must verify $request->getContent(). Re-serializing the decoded
    // array reorders keys and respaces separators, changing the bytes and
    // failing every signature. This body is spaced so that json_encode() of
    // its decoded form provably would NOT reproduce it.
    [, , $device] = agentFixture();

    $raw = '{ "started_at" : "2026-09-20T09:00:00Z",  "client_id" : null }';

    expect(json_encode(json_decode($raw, true)))->not->toBe($raw);

    signedCall($device, 'POST', '/webhooks/session', $raw)->assertOk();
});

test('an identical signed request replays successfully', function () {
    // There is no nonce, timestamp or window: a captured request replays
    // forever. This asserts TODAY'S behaviour, not a desirable one. Rejecting
    // a replay is a change the agent has not been built for — it retries on
    // any non-2xx, so a rejected replay becomes an infinite loop.
    [, , $device] = agentFixture();

    $body = json_encode(['title' => 'Replayed task']);

    signedCall($device, 'POST', '/webhooks/tasks', $body)->assertOk();
    signedCall($device, 'POST', '/webhooks/tasks', $body)->assertOk();

    expect(Task::where('title', 'Replayed task')->count())->toBe(2);
});

/* ── Permissive validation ───────────────────────────────────────────────── */

test('another organizations client id is stored as null, with a 200', function () {
    // The tenant-isolation boundary for ingest is fail-quiet. An `exists:` rule
    // returning 422 here would re-queue a call that can never succeed.
    [, , $device] = agentFixture(['name' => 'Mine']);
    $theirs = org(['name' => 'Theirs']);

    $foreign = Client::create(['org_id' => $theirs->id, 'name' => 'Theirs Customer']);

    $body = json_encode(['client_id' => $foreign->id, 'started_at' => '2026-09-20T09:00:00Z']);
    $sessionId = signedCall($device, 'POST', '/webhooks/session', $body)->assertOk()->json('session_id');

    expect(WorkSession::findOrFail($sessionId)->client_id)->toBeNull();
});

test('another users task id is stored as null, with a 200', function () {
    [$organization, , $device] = agentFixture();
    $someoneElse = member($organization, UserRole::Member);

    $foreign = Task::create([
        'org_id' => $organization->id, 'user_id' => $someoneElse->id, 'title' => 'Theirs',
    ]);

    $body = json_encode(['task_id' => $foreign->id, 'started_at' => '2026-09-20T09:00:00Z']);
    $sessionId = signedCall($device, 'POST', '/webhooks/session', $body)->assertOk()->json('session_id');

    expect(WorkSession::findOrFail($sessionId)->task_id)->toBeNull();
});

/* ── Tasks ───────────────────────────────────────────────────────────────── */

test('a task is created and deleted, and deleting a missing one is still ok', function () {
    [, , $device] = agentFixture();

    $created = signedCall($device, 'POST', '/webhooks/tasks', json_encode(['title' => 'Write the thing']))
        ->assertOk()
        ->json();

    expect($created['title'])->toBe('Write the thing');

    signedCall($device, 'DELETE', '/webhooks/tasks/' . $created['task_id'])
        ->assertOk()
        ->assertExactJson(['ok' => true]);

    // Already gone. Still ok — a 404 would make the agent re-queue forever.
    signedCall($device, 'DELETE', '/webhooks/tasks/' . $created['task_id'])
        ->assertOk()
        ->assertExactJson(['ok' => true]);
});

test('an empty task title is refused with a 400', function () {
    [, , $device] = agentFixture();

    signedCall($device, 'POST', '/webhooks/tasks', json_encode(['title' => '   ']))
        ->assertStatus(400)
        ->assertExactJson(['error' => 'title required']);
});

test('a task belonging to someone else is not deleted', function () {
    [$organization, , $device] = agentFixture();
    $someoneElse = member($organization, UserRole::Member);

    $foreign = Task::create([
        'org_id' => $organization->id, 'user_id' => $someoneElse->id, 'title' => 'Theirs',
    ]);

    signedCall($device, 'DELETE', '/webhooks/tasks/' . $foreign->id)->assertOk();

    expect(Task::whereKey($foreign->id)->exists())->toBeTrue();
});

test('only open tasks are listed, newest first', function () {
    [$organization, $user, $device] = agentFixture();

    Task::create(['org_id' => $organization->id, 'user_id' => $user->id, 'title' => 'Done', 'status' => 'done']);
    Task::create(['org_id' => $organization->id, 'user_id' => $user->id, 'title' => 'Open', 'status' => 'open']);

    $titles = array_column(signedCall($device, 'GET', '/webhooks/tasks')->assertOk()->json(), 'title');

    expect($titles)->toBe(['Open']);
});

/* ── Session behaviour ───────────────────────────────────────────────────── */

test('opening a session closes the ones the agent left behind', function () {
    // A reconnecting agent always opens a FRESH session. The stale one closes
    // at its last heartbeat, not at now, so the recorded duration reflects time
    // actually tracked rather than the dead gap.
    [, $user, $device] = agentFixture();

    $stale = openSession($user->id, [
        'started_at'   => '2026-09-20 08:00:00',
        'last_seen_at' => '2026-09-20 08:30:00',
    ]);

    signedCall($device, 'POST', '/webhooks/session', json_encode(['started_at' => '2026-09-20T09:00:00Z']))
        ->assertOk();

    expect($stale->fresh()->ended_at?->format('Y-m-d H:i:s'))->toBe('2026-09-20 08:30:00');
});

test('a stale session with no heartbeat closes at its start', function () {
    [, $user, $device] = agentFixture();

    $stale = openSession($user->id, ['started_at' => '2026-09-20 08:00:00', 'last_seen_at' => null]);

    signedCall($device, 'POST', '/webhooks/session', '{}')->assertOk();

    expect($stale->fresh()->ended_at?->format('Y-m-d H:i:s'))->toBe('2026-09-20 08:00:00');
});

test('a replayed activity batch cannot overwrite finalized totals', function () {
    // The `ended_at IS NULL` guard is the one piece of replay safety in the
    // system: a batch queued offline and delivered after the session closed
    // must not clobber the figures written at stop.
    [, $user, $device] = agentFixture();

    $session = openSession($user->id, [
        'ended_at' => '2026-09-20 10:00:00', 'active_s' => 3000, 'inactive_s' => 600,
    ]);

    $body = json_encode(['samples' => [], 'active_s' => 11, 'inactive_s' => 22]);
    signedCall($device, 'POST', "/webhooks/session/{$session->id}/activity", $body)->assertOk();

    expect((int) $session->fresh()->active_s)->toBe(3000)
        ->and((int) $session->fresh()->inactive_s)->toBe(600);
});

test('an open session does take the live totals', function () {
    [, $user, $device] = agentFixture();
    $session = openSession($user->id);

    $body = json_encode(['samples' => [], 'active_s' => 120, 'inactive_s' => 30]);
    signedCall($device, 'POST', "/webhooks/session/{$session->id}/activity", $body)->assertOk();

    expect((int) $session->fresh()->active_s)->toBe(120)
        ->and((int) $session->fresh()->inactive_s)->toBe(30);
});

test('a call on an open session stamps the heartbeat', function () {
    // The heartbeat is what keeps a session from being auto-closed as stale
    // and what drives the live view.
    [, $user, $device] = agentFixture();
    $session = openSession($user->id, ['last_seen_at' => null]);

    signedCall($device, 'POST', "/webhooks/session/{$session->id}/activity", '{}')->assertOk();

    expect($session->fresh()->last_seen_at)->not->toBeNull();
});

test('idle durations are computed server side and floored at zero', function () {
    [, $user, $device] = agentFixture();
    $session = openSession($user->id);

    $body = json_encode(['periods' => [
        ['start' => '2026-09-20T09:00:00Z', 'end' => '2026-09-20T09:05:00Z'],
        // Reversed. The agent does not send a duration, and a negative span
        // must not become a negative stored value.
        ['start' => '2026-09-20T09:20:00Z', 'end' => '2026-09-20T09:10:00Z'],
    ]]);

    signedCall($device, 'POST', "/webhooks/session/{$session->id}/idle", $body)->assertOk();

    $durations = DB::table('idle_periods')
        ->where('session_id', $session->id)
        ->orderBy('id')
        ->pluck('duration_s')
        ->map(fn ($value) => (int) $value)
        ->all();

    expect($durations)->toBe([300, 0]);
});

test('window and process fields are truncated to their column widths', function () {
    [, $user, $device] = agentFixture();
    $session = openSession($user->id);

    $body = json_encode(['windows' => [[
        'ts' => '2026-09-20T09:05:00Z',
        'app' => str_repeat('a', 300),
        'title' => str_repeat('t', 500),
        'focus_seconds' => 60,
    ]], 'processes' => [[
        'ts' => '2026-09-20T09:05:00Z', 'app' => str_repeat('p', 300), 'pid' => 42,
    ]]]);

    signedCall($device, 'POST', "/webhooks/session/{$session->id}/windows", $body)->assertOk();

    $window = DB::table('window_events')->where('session_id', $session->id)->first();
    $process = DB::table('process_snapshots')->where('session_id', $session->id)->first();

    expect(strlen($window->app_name))->toBe(160)
        ->and(strlen($window->window_title))->toBe(400)
        ->and(strlen($process->app_name))->toBe(200);
});

/* ── Screenshots ─────────────────────────────────────────────────────────── */

test('a screenshot is stored from the raw request body', function () {
    [, $user, $device] = agentFixture();
    $session = openSession($user->id);

    // A 1x1 PNG — the same bytes tools/test_webhook.py sends.
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

    $id = signedCall(
        $device,
        'POST',
        "/webhooks/session/{$session->id}/screenshot?ext=png&ts=2026-09-20T09:05:00Z&blurred=1",
        $png
    )->assertOk()->json('screenshot_id');

    $row = DB::table('screenshots')->where('id', $id)->first();
    $path = Uploads::path($row->file_path);

    expect((int) $row->blurred)->toBe(1)
        ->and($row->file_path)->toStartWith($user->id . '/' . $session->id . '/')
        ->and($row->file_path)->toEndWith('.png')
        ->and(is_file($path))->toBeTrue()
        // Byte-identical: the body is the image, never a multipart part.
        ->and(file_get_contents($path))->toBe($png);

    @unlink($path);
});

test('blurred is truthy for exactly three spellings', function () {
    [, $user, $device] = agentFixture();
    $session = openSession($user->id);

    foreach (['1' => 1, 'true' => 1, 'True' => 1, 'yes' => 0, '0' => 0] as $sent => $expected) {
        $id = signedCall(
            $device,
            'POST',
            "/webhooks/session/{$session->id}/screenshot?ext=png&blurred={$sent}",
            'bytes'
        )->assertOk()->json('screenshot_id');

        $row = DB::table('screenshots')->where('id', $id)->first();

        expect((int) $row->blurred)->toBe($expected, "blurred={$sent}");

        @unlink(Uploads::path($row->file_path));
    }
});

test('an empty body and an unsupported extension are refused', function () {
    [, $user, $device] = agentFixture();
    $session = openSession($user->id);

    signedCall($device, 'POST', "/webhooks/session/{$session->id}/screenshot?ext=png", '')
        ->assertStatus(400)
        ->assertExactJson(['error' => 'missing image']);

    signedCall($device, 'POST', "/webhooks/session/{$session->id}/screenshot?ext=gif", 'bytes')
        ->assertStatus(400)
        ->assertExactJson(['error' => 'unsupported image type']);
});

test('a plan without screenshots refuses the upload with a 402', function () {
    // 402, not 403: the call is authenticated and well-formed, the plan simply
    // does not cover it. The monitoring policy asks the agent not to capture,
    // but that is a request to a program on someone else's machine — refusing
    // at ingest is the actual enforcement.
    app(PlanLimits::class)->flush();

    [, $user, $device] = agentFixture(['plan_type' => 'solo']);
    $session = openSession($user->id);

    signedCall($device, 'POST', "/webhooks/session/{$session->id}/screenshot?ext=png", 'bytes')
        ->assertStatus(402)
        ->assertExactJson(['error' => 'screenshots are not included on this plan']);

    expect(DB::table('screenshots')->count())->toBe(0);
});

test('the monitoring policy reports screenshots off on a solo plan', function () {
    // The plan overrides the admin toggle, one way only. An admin turning
    // track_screenshots on does not buy the feature.
    app(PlanLimits::class)->flush();

    [, , $device] = agentFixture(['plan_type' => 'solo', 'track_screenshots' => 1]);

    signedCall($device, 'GET', '/webhooks/policy')
        ->assertOk()
        ->assertJsonPath('track_screenshots', false);
});

/* ── Framework behaviour that must not apply here ────────────────────────── */

test('input is neither trimmed nor nulled before it is stored', function () {
    // TrimStrings and ConvertEmptyStringsToNull rewrite input the legacy
    // handlers accept verbatim, so both are excluded for /webhooks/*.
    // Otherwise a window title of "  spaced  " silently becomes "spaced".
    [, $user, $device] = agentFixture();
    $session = openSession($user->id);

    $body = json_encode(['windows' => [[
        'ts' => '2026-09-20T09:05:00Z',
        'app' => '  Code.exe  ',
        'title' => '  spaced title  ',
        'focus_seconds' => 60,
    ]]]);

    signedCall($device, 'POST', "/webhooks/session/{$session->id}/windows", $body)->assertOk();

    $row = DB::table('window_events')->where('session_id', $session->id)->first();

    expect($row->app_name)->toBe('  Code.exe  ')
        ->and($row->window_title)->toBe('  spaced title  ');
});

test('no session endpoint is protected by CSRF', function () {
    // There is no session cookie and no token to carry. A 419 would be
    // indistinguishable from a server fault to the agent, which would re-queue.
    [, , $device] = agentFixture();

    signedCall($device, 'POST', '/webhooks/session', '{}')->assertOk();
});

test('a malformed JSON body is tolerated rather than rejected', function () {
    // Every field is optional in the legacy handlers, and a 400 here would
    // re-queue a batch that can never parse.
    [, , $device] = agentFixture();

    signedCall($device, 'POST', '/webhooks/session', 'not json at all')->assertOk();
});

test('errors under webhooks answer JSON, never an HTML page', function () {
    // The agent calls .json() on every response. An HTML error page raises a
    // decode error it reports as a generic failure and re-queues.
    $response = $this->call('PUT', '/webhooks/me');

    expect($response->headers->get('content-type'))->toContain('json');
});

test('every status the agent can receive is in the allowed set', function () {
    // 201, 422 and 429 are never returned. Each would change retry behaviour:
    // the agent cannot distinguish error classes and treats any non-2xx as a
    // failure to re-queue.
    [$organization, $user, $device] = agentFixture();
    $session = openSession($user->id);
    $someoneElse = member($organization, UserRole::Member);
    $theirSession = openSession($someoneElse->id);

    $statuses = [
        $this->postJson('/webhooks/auth', ['email' => 'x@y.test', 'password' => 'z'])->status(),
        $this->getJson('/webhooks/me')->status(),
        signedCall($device, 'GET', '/webhooks/me')->status(),
        signedCall($device, 'POST', '/webhooks/tasks', json_encode(['title' => '']))->status(),
        signedCall($device, 'POST', "/webhooks/session/{$theirSession->id}/idle", '{}')->status(),
        signedCall($device, 'POST', "/webhooks/session/{$session->id}/screenshot?ext=bmp", 'x')->status(),
    ];

    foreach ($statuses as $status) {
        expect($status)->toBeIn(App\Support\AgentResponse::ALLOWED);
    }
});
