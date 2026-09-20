<?php

/**
 * Screenshots — the gallery, the images, and who may read them.
 *
 * The property under test is that the PAGE and the IMAGE are gated on
 * different things, on purpose. The page needs the `screenshots` capability;
 * the image needs only that the viewer can see the person in it, because
 * `/app/overview` shows recent screenshots to a member and to an HR manager,
 * neither of whom may open the gallery.
 *
 * The test that matters most is the cross-tenant one. In the legacy app it
 * fails: screenshots live under the document root, `.htaccess` serves any real
 * file, and the only protection is an unguessable filename. Decision D4 moved
 * them to a private disk behind this controller.
 *
 * @see docs/migration/screenshots.md §4
 */

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\Screenshot;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\Agent\ScreenshotIngest;
use App\Services\Agent\SessionIngest;
use App\Support\Uploads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('private');
});

/** A screenshot for this person, bytes on the private disk, at a UTC instant. */
function shotFor(User $user, string $ts = '2026-09-20 09:00:00', array $attributes = []): Screenshot
{
    $session = WorkSession::create([
        'user_id'         => $user->id,
        'started_at'      => $ts,
        'ended_at'        => date('Y-m-d H:i:s', strtotime($ts) + 3600),
        'active_s'        => 3600,
        'source'          => 'agent',
        'approval_status' => 'approved',
    ]);

    $relative = $user->id . '/' . $session->id . '/' . bin2hex(random_bytes(8)) . '.png';

    ScreenshotIngest::disk()->put(ScreenshotIngest::diskPath($relative), 'PNGBYTES');

    return Screenshot::create(array_merge([
        'session_id' => $session->id,
        'ts'         => $ts,
        'file_path'  => $relative,
        'blurred'    => 0,
    ], $attributes));
}

/* ── The page is gated on the capability ─────────────────────────────────── */

test('a role holding the screenshots capability opens the gallery', function (UserRole $role) {
    $this->actingAs(member(org(), $role))
        ->get('/app/screenshots')
        ->assertOk()
        ->assertSee('Screenshots');
})->with([
    UserRole::ClientAdmin,
    UserRole::Manager,
    UserRole::ClientViewer,
]);

test('an HR manager is refused the gallery', function () {
    // HR sees time and money, never monitoring imagery. This is the one role
    // with full people access and no screenshot access, and it is deliberate.
    $this->actingAs(member(org(), UserRole::HrManager))
        ->get('/app/screenshots')
        ->assertRedirect();
});

test('a member is refused the gallery', function () {
    // A member holds no capabilities at all; their own screenshots reach them
    // through the overview panel, not through this page.
    $this->actingAs(member(org(), UserRole::Member))
        ->get('/app/screenshots')
        ->assertRedirect();
});

/* ── The image is gated on visibility, not the capability ────────────────── */

test('a member fetches their own screenshot without holding any capability', function () {
    $user = member(org(), UserRole::Member);
    $shot = shotFor($user);

    $this->actingAs($user)
        ->get("/app/screenshots/{$shot->id}/image")
        ->assertOk()
        ->assertHeader('content-type', 'image/png');
});

test('an HR manager fetches a screenshot although the gallery refuses them', function () {
    // The overview panel shows these, so refusing the image would blank it.
    $organization = org();
    $worker = member($organization, UserRole::Member);
    $shot = shotFor($worker);

    $this->actingAs(member($organization, UserRole::HrManager))
        ->get("/app/screenshots/{$shot->id}/image")
        ->assertOk();
});

test('another organization gets a 404, not the image', function () {
    $worker = member(org(['name' => 'Acme']), UserRole::Member);
    $shot = shotFor($worker);

    // A company admin — every capability their role has — of a DIFFERENT
    // tenant. 404 rather than 403: a 403 confirms the id exists.
    $this->actingAs(member(org(['name' => 'Globex']), UserRole::ClientAdmin))
        ->get("/app/screenshots/{$shot->id}/image")
        ->assertNotFound();
});

test('a manager outside the team gets a 404', function () {
    $organization = org();
    $worker = member($organization, UserRole::Member);
    $shot = shotFor($worker);

    // Team-scoped, and assigned to no team, so they see nobody.
    $this->actingAs(member($organization, UserRole::Manager))
        ->get("/app/screenshots/{$shot->id}/image")
        ->assertNotFound();
});

test('a signed-out request is sent to sign in, never to the file', function () {
    $shot = shotFor(member(org(), UserRole::Member));

    $this->get("/app/screenshots/{$shot->id}/image")->assertRedirectContains('/login');
});

test('a screenshot whose file is gone is a 404, not a broken stream', function () {
    $user = member(org(), UserRole::Member);
    $shot = shotFor($user);

    ScreenshotIngest::disk()->delete(ScreenshotIngest::diskPath($shot->file_path));

    $this->actingAs($user)
        ->get("/app/screenshots/{$shot->id}/image")
        ->assertNotFound();
});

/* ── Filters ─────────────────────────────────────────────────────────────── */

test('the day filter is cut in the organization reporting timezone', function () {
    // Manila is UTC+8, so the local day starts at 16:00 UTC the day before.
    // 15:00 UTC on the 19th is already the 20th in Manila; 16:59 UTC on the
    // 20th is still the 20th. Cutting in UTC would get both of these wrong.
    $organization = org(['report_tz' => 'Asia/Manila']);
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member);

    $inside = shotFor($worker, '2026-09-19 16:30:00');   // 00:30 on the 20th, local
    $alsoInside = shotFor($worker, '2026-09-20 15:00:00');  // 23:00 on the 20th, local
    $outside = shotFor($worker, '2026-09-20 16:30:00');  // 00:30 on the 21st, local

    $response = $this->actingAs($admin)->get('/app/screenshots?date=2026-09-20');

    $response->assertOk()
        ->assertSee("/app/screenshots/{$inside->id}/image", false)
        ->assertSee("/app/screenshots/{$alsoInside->id}/image", false)
        ->assertDontSee("/app/screenshots/{$outside->id}/image", false);
});

test('the member dropdown keeps the full visible scope while a filter is on', function () {
    // Narrowing the scope before building the dropdown used to leave the
    // filtered person as the only option, so switching people meant hitting
    // Clear first.
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $ava = member($organization, UserRole::Member, ['name' => 'Ava Reyes']);
    $ben = member($organization, UserRole::Member, ['name' => 'Ben Okoro']);

    shotFor($ava);
    shotFor($ben);

    $this->actingAs($admin)
        ->get("/app/screenshots?user_id={$ava->id}")
        ->assertOk()
        ->assertSee('Ava Reyes')
        ->assertSee('Ben Okoro');
});

test('a user_id outside the visible scope is ignored rather than obeyed', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $ours = member($organization, UserRole::Member);
    $theirs = member(org(['name' => 'Globex']), UserRole::Member);

    $mine = shotFor($ours);
    $foreign = shotFor($theirs);

    $this->actingAs($admin)
        ->get("/app/screenshots?user_id={$theirs->id}")
        ->assertOk()
        ->assertSee("/app/screenshots/{$mine->id}/image", false)
        ->assertDontSee("/app/screenshots/{$foreign->id}/image", false);
});

/* ── Storage and retention ───────────────────────────────────────────────── */

test('nothing is written under the document root', function () {
    $shot = shotFor(member(org(), UserRole::Member));

    expect(is_file(Uploads::path($shot->file_path)))->toBeFalse();
});

test('retention deletes the row and the file together', function () {
    // Retention is an operator setting: it is read from the organization the
    // first super admin belongs to, and it governs every tenant.
    member(org(['name' => 'Platform', 'screenshot_retention_days' => 30]), UserRole::SuperAdmin);

    $worker = member(org(), UserRole::Member);
    $old = shotFor($worker, gmdate('Y-m-d H:i:s', strtotime('-90 days')));
    $fresh = shotFor($worker, gmdate('Y-m-d H:i:s', strtotime('-1 day')));

    $deleted = app(ScreenshotIngest::class)->purge();

    expect($deleted)->toBe(1)
        ->and(Screenshot::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(ScreenshotIngest::disk()->exists(ScreenshotIngest::diskPath($old->file_path)))->toBeFalse()
        ->and(Screenshot::query()->whereKey($fresh->id)->exists())->toBeTrue()
        ->and(ScreenshotIngest::disk()->exists(ScreenshotIngest::diskPath($fresh->file_path)))->toBeTrue();
});

test('a retention of zero days keeps screenshots forever', function () {
    member(org(['name' => 'Platform', 'screenshot_retention_days' => 0]), UserRole::SuperAdmin);

    $ancient = shotFor(member(org(), UserRole::Member), '2019-01-01 00:00:00');

    expect(app(ScreenshotIngest::class)->purge())->toBe(0)
        ->and(Screenshot::query()->whereKey($ancient->id)->exists())->toBeTrue();
});

/* ── Stale sessions ──────────────────────────────────────────────────────── */

test('a stale session is closed at its last heartbeat, never at now', function () {
    // A machine that crashed at lunch must not bank the afternoon.
    $user = member(org(), UserRole::Member);
    $heartbeat = gmdate('Y-m-d H:i:s', time() - 3600);

    $session = WorkSession::create([
        'user_id'         => $user->id,
        'started_at'      => gmdate('Y-m-d H:i:s', time() - 7200),
        'last_seen_at'    => $heartbeat,
        'source'          => 'agent',
        'approval_status' => 'approved',
    ]);

    app(SessionIngest::class)->closeStale();

    expect(DB::table('sessions')->where('id', $session->id)->value('ended_at'))->toBe($heartbeat);
});

test('a session that is still beating is left open', function () {
    $user = member(org(), UserRole::Member);

    $session = WorkSession::create([
        'user_id'         => $user->id,
        'started_at'      => gmdate('Y-m-d H:i:s', time() - 7200),
        'last_seen_at'    => gmdate('Y-m-d H:i:s', time() - 60),
        'source'          => 'agent',
        'approval_status' => 'approved',
    ]);

    app(SessionIngest::class)->closeStale();

    expect(DB::table('sessions')->where('id', $session->id)->value('ended_at'))->toBeNull();
});

test('a session with no heartbeat at all is closed at its start', function () {
    // COALESCE(last_seen_at, started_at) — an agent that died before its first
    // heartbeat records no time rather than an open-ended session.
    $user = member(org(), UserRole::Member);
    $startedAt = gmdate('Y-m-d H:i:s', time() - 7200);

    $session = WorkSession::create([
        'user_id'         => $user->id,
        'started_at'      => $startedAt,
        'source'          => 'agent',
        'approval_status' => 'approved',
    ]);

    app(SessionIngest::class)->closeStale();

    expect(DB::table('sessions')->where('id', $session->id)->value('ended_at'))->toBe($startedAt);
});
