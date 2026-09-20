<?php

/**
 * Phase 11 — timesheets, the two review queues, the efficiency report and the
 * CSV exports.
 *
 * The properties under test are the ones that decide whether time counts:
 * who may file an entry for whom, what state it lands in, who may sign it off,
 * and whether an export says the same thing as the page it came from.
 *
 * @see docs/migration/reports.md §8
 */

use App\Enums\UserRole;
use App\Models\Task;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\WorkSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function entry(User $user, string $startedAt, int $active = 3600, array $attributes = []): WorkSession
{
    return WorkSession::create(array_merge([
        'user_id'         => $user->id,
        'started_at'      => $startedAt,
        'ended_at'        => gmdate('Y-m-d H:i:s', strtotime($startedAt . ' UTC') + $active),
        'active_s'        => $active,
        'inactive_s'      => 0,
        'source'          => 'agent',
        'approval_status' => 'approved',
    ], $attributes));
}

/** Today in UTC, at a fixed hour, so a week/month window always contains it. */
function todayUtc(string $time = '09:00:00'): string
{
    return gmdate('Y-m-d') . ' ' . $time;
}

/* ── Gates ───────────────────────────────────────────────────────────────── */

test('the review queues are held by different roles', function () {
    $organization = org();

    // A team manager accepts that someone worked; they cannot authorise the
    // overtime premium on it.
    $this->actingAs(member($organization, UserRole::Manager))->get('/app/approvals')->assertOk();
    $this->actingAs(member($organization, UserRole::Manager))->get('/app/overtime')->assertRedirect();

    // HR signs off overtime AND time.
    $this->actingAs(member($organization, UserRole::HrManager))->get('/app/overtime')->assertOk();
    $this->actingAs(member($organization, UserRole::HrManager))->get('/app/approvals')->assertOk();

    // A member holds neither.
    $this->actingAs(member($organization, UserRole::Member))->get('/app/approvals')->assertRedirect();
    $this->actingAs(member($organization, UserRole::Member))->get('/app/overtime')->assertRedirect();
});

test('a client portal is turned away from the efficiency report despite holding reports', function () {
    // The capability buys them their own time and billing figures, not an
    // internal performance ranking of the people on their account.
    $this->actingAs(member(org(), UserRole::ClientViewer))
        ->get('/app/reports/efficiency')
        ->assertRedirect('/app/overview');
});

test('timesheets needs only a login', function () {
    $this->actingAs(member(org(), UserRole::Member))->get('/app/timesheets')->assertOk();
});

/* ── Filing manual entries ───────────────────────────────────────────────── */

test('a member files for themselves and it lands pending', function () {
    $worker = member(org(), UserRole::Member);

    $this->actingAs($worker)->post('/app/timesheets', [
        'started_at' => [todayUtc('09:00:00')],
        'ended_at'   => [todayUtc('11:00:00')],
        'note'       => ['client call'],
    ])->assertRedirect('/app/timesheets');

    $entry = WorkSession::query()->where('user_id', $worker->id)->first();

    expect($entry->approval_status)->toBe('pending')
        ->and($entry->source)->toBe('manual')
        ->and($entry->active_s)->toBe(7200)
        ->and($entry->note)->toBe('client call');
});

test('a manager files for someone in scope and it is approved on the spot', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member);

    $this->actingAs($admin)->post('/app/timesheets', [
        'user_id'    => $worker->id,
        'started_at' => [todayUtc('09:00:00')],
        'ended_at'   => [todayUtc('17:00:00')],
    ]);

    $entry = WorkSession::query()->where('user_id', $worker->id)->first();

    expect($entry->approval_status)->toBe('approved');
});

test('a member cannot file an entry against somebody else', function () {
    // The form carries a user_id; a member's is ignored, always.
    $organization = org();
    $worker = member($organization, UserRole::Member);
    $victim = member($organization, UserRole::Member);

    $this->actingAs($worker)->post('/app/timesheets', [
        'user_id'    => $victim->id,
        'started_at' => [todayUtc('09:00:00')],
        'ended_at'   => [todayUtc('10:00:00')],
    ]);

    expect(WorkSession::query()->where('user_id', $victim->id)->exists())->toBeFalse()
        ->and(WorkSession::query()->where('user_id', $worker->id)->exists())->toBeTrue();
});

test('a manager cannot file against another tenant', function () {
    $admin = member(org(['name' => 'Acme']), UserRole::ClientAdmin);
    $outsider = member(org(['name' => 'Globex']), UserRole::Member);

    $this->actingAs($admin)->post('/app/timesheets', [
        'user_id'    => $outsider->id,
        'started_at' => [todayUtc('09:00:00')],
        'ended_at'   => [todayUtc('10:00:00')],
    ]);

    expect(WorkSession::query()->where('user_id', $outsider->id)->exists())->toBeFalse();
});

test('a row whose end is not after its start is skipped, not stored', function () {
    $worker = member(org(), UserRole::Member);

    $this->actingAs($worker)->post('/app/timesheets', [
        'started_at' => [todayUtc('11:00:00'), todayUtc('09:00:00')],
        'ended_at'   => [todayUtc('09:00:00'), todayUtc('10:00:00')],
    ]);

    expect(WorkSession::query()->count())->toBe(1);
});

test('a client portal cannot file at all', function () {
    $this->actingAs(member(org(), UserRole::ClientViewer))
        ->post('/app/timesheets', [
            'started_at' => [todayUtc('09:00:00')],
            'ended_at'   => [todayUtc('10:00:00')],
        ])
        ->assertForbidden();
});

/* ── The queues ──────────────────────────────────────────────────────────── */

test('approving an entry records who decided and when', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member);
    $pending = entry($worker, todayUtc(), 3600, ['source' => 'manual', 'approval_status' => 'pending']);

    $this->actingAs($admin)->post("/app/approvals/{$pending->id}", [
        'decision'    => 'approve',
        'review_note' => 'checked with the client',
    ])->assertRedirect('/app/approvals');

    $decided = $pending->fresh();

    expect($decided->approval_status)->toBe('approved')
        ->and((int) $decided->reviewed_by_id)->toBe((int) $admin->id)
        ->and($decided->review_note)->toBe('checked with the client')
        ->and($decided->reviewed_at)->not->toBeNull();
});

test('anything other than an explicit approval is a rejection', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $pending = entry(member($organization, UserRole::Member), todayUtc(), 3600, [
        'source' => 'manual', 'approval_status' => 'pending',
    ]);

    $this->actingAs($admin)->post("/app/approvals/{$pending->id}", ['decision' => 'anything else']);

    expect($pending->fresh()->approval_status)->toBe('rejected');
});

test('a reviewer cannot decide on an entry outside their scope', function () {
    // The id arrives in the URL. Listing scope is not enough on its own.
    $outsider = member(org(['name' => 'Globex']), UserRole::Member);
    $theirs = entry($outsider, todayUtc(), 3600, ['source' => 'manual', 'approval_status' => 'pending']);

    $this->actingAs(member(org(['name' => 'Acme']), UserRole::ClientAdmin))
        ->post("/app/approvals/{$theirs->id}", ['decision' => 'approve'])
        ->assertNotFound();

    expect($theirs->fresh()->approval_status)->toBe('pending');
});

test('approving overtime records its own separate trail', function () {
    $organization = org();
    $hr = member($organization, UserRole::HrManager);
    $session = entry(member($organization, UserRole::Member), todayUtc(), 36000, [
        'overtime_s' => 7200, 'overtime_status' => 'pending', 'overtime_computed' => 1,
    ]);

    $this->actingAs($hr)->post("/app/overtime/{$session->id}", ['decision' => 'approve']);

    $decided = $session->fresh();

    expect($decided->overtime_status)->toBe('approved')
        ->and((int) $decided->overtime_reviewed_by_id)->toBe((int) $hr->id)
        // The time approval is untouched — they are separate decisions.
        ->and($decided->approval_status)->toBe('approved');
});

/* ── Timesheets content ──────────────────────────────────────────────────── */

test('timesheets shows every status, unlike every other report', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member);

    entry($worker, todayUtc('09:00:00'), 3600, ['approval_status' => 'pending', 'source' => 'manual']);
    entry($worker, todayUtc('11:00:00'), 3600, ['approval_status' => 'rejected', 'source' => 'manual']);

    $this->actingAs($admin)->get('/app/timesheets?period=day')
        ->assertOk()
        ->assertSee('pending')
        ->assertSee('rejected');
});

/* ── Efficiency ──────────────────────────────────────────────────────────── */

test('someone with neither time nor tasks scores null, not zero', function () {
    // An IT admin does not track time. Ranking them last for that would be
    // measuring the wrong thing.
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    member($organization, UserRole::ItAdmin, ['name' => 'Ida IT']);

    $this->actingAs($admin)->get('/app/reports/efficiency')
        ->assertOk()
        ->assertSee('no data');
});

test('effectiveness blends activity with task completion when tasks exist', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member, ['name' => 'Ava']);

    // 100% activity — no inactive time at all.
    entry($worker, todayUtc(), 3600);

    Task::create(['org_id' => $organization->id, 'user_id' => $worker->id, 'title' => 'Done', 'status' => 'done']);
    Task::create(['org_id' => $organization->id, 'user_id' => $worker->id, 'title' => 'Open', 'status' => 'open']);

    $rows = app(\App\Services\Reporting\Efficiency::class)
        ->rows($admin, gmdate('Y-m-d') . ' 00:00:00', gmdate('Y-m-d') . ' 23:59:59');

    $ava = collect($rows)->firstWhere('name', 'Ava');

    // (100% activity + 50% tasks) / 2
    expect($ava['activity_pct'])->toBe(100)
        ->and($ava['task_completion_pct'])->toBe(50)
        ->and($ava['effectiveness_pct'])->toBe(75);
});

test('the efficiency report counts approved time only', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $worker = member($organization, UserRole::Member, ['name' => 'Ava']);

    entry($worker, todayUtc('09:00:00'), 3600, ['approval_status' => 'pending', 'source' => 'manual']);

    $rows = app(\App\Services\Reporting\Efficiency::class)
        ->rows($admin, gmdate('Y-m-d') . ' 00:00:00', gmdate('Y-m-d') . ' 23:59:59');

    expect(collect($rows)->firstWhere('name', 'Ava')['active_s'])->toBe(0);
});

/* ── Exports ─────────────────────────────────────────────────────────────── */

test('the session export names the period it covers', function () {
    // Regression 2 in reports.md §4: the legacy interpolated an undefined
    // $period, so every download was called the same thing.
    $response = $this->actingAs(member(org(), UserRole::ClientAdmin))
        ->get('/app/export.csv?period=month')
        ->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('deskpulse-month-');
});

test('an export carries plain numbers, never the display helpers', function () {
    // Format::hms() and money() join with a NON-BREAKING SPACE so figures do
    // not wrap on screen. In a CSV that turns every number into text.
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    entry(member($organization, UserRole::Member), todayUtc(), 3600);

    $csv = $this->actingAs($admin)->get('/app/export.csv?period=day')->streamedContent();

    expect($csv)->not->toContain("\u{00A0}")
        ->and($csv)->toContain('Active (h)');
});

test('the efficiency export agrees with the page it came from', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    member($organization, UserRole::Member, ['name' => 'Ava Reyes']);

    $csv = $this->actingAs($admin)->get('/app/reports/efficiency.csv?period=month')->streamedContent();

    expect($csv)->toContain('Ava Reyes')
        ->and($csv)->toContain('period: month')
        // Unscored people read "n/a", matching the page's "no data".
        ->and($csv)->toContain('n/a');
});

test('an export is scoped like the page', function () {
    $ours = org(['name' => 'Acme']);
    entry(member($ours, UserRole::Member, ['name' => 'Our Worker']), todayUtc());
    entry(member(org(['name' => 'Globex']), UserRole::Member, ['name' => 'Their Worker']), todayUtc());

    $csv = $this->actingAs(member($ours, UserRole::ClientAdmin))
        ->get('/app/export.csv?period=day')
        ->streamedContent();

    expect($csv)->toContain('Our Worker')->not->toContain('Their Worker');
});

/* ── Session detail ──────────────────────────────────────────────────────── */

test('a session outside the viewer scope sends them back rather than erroring', function () {
    // The usual way to land on a stale session link is switching accounts.
    // An error page for that is a dead end.
    $theirs = entry(member(org(['name' => 'Globex']), UserRole::Member), todayUtc());

    $this->actingAs(member(org(['name' => 'Acme']), UserRole::ClientAdmin))
        ->get("/app/session/{$theirs->id}")
        ->assertRedirect('/app/timesheets');
});

test('a session detail renders its monitoring data', function () {
    $organization = org();
    $admin = member($organization, UserRole::ClientAdmin);
    $session = entry(member($organization, UserRole::Member), todayUtc());

    DB::table('window_events')->insert([
        'session_id' => $session->id, 'ts' => todayUtc('09:05:00'),
        'app_name' => 'Code.exe', 'window_title' => 'ReportsTest.php', 'focus_seconds' => 300,
    ]);

    $this->actingAs($admin)->get("/app/session/{$session->id}")
        ->assertOk()
        ->assertSee('Code.exe')
        ->assertSee('ReportsTest.php');
});
