<?php

/**
 * Overtime — the split between regular and overtime active time.
 *
 * The property under test is WHICH CLOCK. A schedule of 09:00–17:00 belongs to
 * the organization, not to the machine the code runs on, and getting that wrong
 * does not produce a visibly broken page — it produces a plausible number that
 * is wrong, on the one calculation that decides what people are paid.
 *
 * Every timezone test here runs with the process default set to a THIRD zone,
 * so a pass means the answer came from `report_tz` and not from the host.
 *
 * @see docs/migration/monitoring.md §4
 */

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\Reporting\Overtime;
use App\Support\Format;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Somebody on a 09:00–17:00, Monday-to-Friday schedule in their org's timezone. */
function scheduled(Organization $organization, string $start = '09:00:00', string $end = '17:00:00'): User
{
    return member($organization, UserRole::Member, [
        'work_start' => $start,
        'work_end'   => $end,
        'work_days'  => '1,2,3,4,5',
    ]);
}

/** A closed session, stored in UTC as the agent posts it. */
function closed(User $user, string $startedAtUtc, string $endedAtUtc, ?int $active = null): WorkSession
{
    $span = strtotime($endedAtUtc . ' UTC') - strtotime($startedAtUtc . ' UTC');

    return WorkSession::create([
        'user_id'         => $user->id,
        'started_at'      => $startedAtUtc,
        'ended_at'        => $endedAtUtc,
        'active_s'        => $active ?? $span,
        'inactive_s'      => 0,
        'source'          => 'agent',
        'approval_status' => 'approved',
    ]);
}

/** Run the body with the PROCESS timezone set somewhere else entirely. */
function inHostTimezone(string $timezone, callable $body): void
{
    $was = date_default_timezone_get();
    date_default_timezone_set($timezone);

    try {
        $body();
    } finally {
        date_default_timezone_set($was);
    }
}

function overtimeOf(WorkSession $session): int
{
    return (int) $session->fresh()->overtime_s;
}

/* ── The clock ───────────────────────────────────────────────────────────── */

test('a normal working day in the organization timezone is not overtime', function () {
    // Monday 09:00-17:00 in Manila is 01:00-09:00 UTC. Read against the host's
    // clock instead, the window lands hours away from the session and the whole
    // shift is billed as overtime — eight hours of it, for turning up on time.
    inHostTimezone('America/Chicago', function () {
        $worker = scheduled(org(['report_tz' => 'Asia/Manila']));
        $session = closed($worker, '2026-09-21 01:00:00', '2026-09-21 09:00:00');

        app(Overtime::class)->recompute($worker->id);

        expect(overtimeOf($session))->toBe(0);
    });
});

test('the same UTC instants ARE overtime for an organization on UTC', function () {
    // The mirror image, and the point of the whole exercise: identical stored
    // rows, different tenant timezone, different answer. 01:00-09:00 UTC is the
    // middle of the night for a UTC organization on a 09:00-17:00 schedule.
    inHostTimezone('America/Chicago', function () {
        $worker = scheduled(org(['report_tz' => 'UTC']));
        $session = closed($worker, '2026-09-21 01:00:00', '2026-09-21 09:00:00');

        app(Overtime::class)->recompute($worker->id);

        expect(overtimeOf($session))->toBe(28800);
    });
});

test('a session is bucketed into the organization calendar day, not the UTC one', function () {
    // 15:30 and 16:30 UTC on Sunday are 23:30 Sunday and 00:30 Monday in
    // Manila. One is a weekend, the other is the start of the working week, and
    // bucketing them both on the UTC date would put them on the same day.
    inHostTimezone('Pacific/Auckland', function () {
        $worker = scheduled(org(['report_tz' => 'Asia/Manila']));

        $sunday = closed($worker, '2026-09-20 15:20:00', '2026-09-20 15:50:00', 1800);
        $monday = closed($worker, '2026-09-20 16:20:00', '2026-09-20 16:50:00', 1800);

        app(Overtime::class)->recompute($worker->id);

        // Sunday is not a work day, so all of it is overtime. Monday 00:30 is a
        // work day but outside 09:00-17:00, so all of that is too — the point
        // is that they were weighed against DIFFERENT days.
        expect(overtimeOf($sunday))->toBe(1800)
            ->and(overtimeOf($monday))->toBe(1800);
    });
});

test('the answer does not move when the host timezone does', function () {
    // The same fixture through four hosts. Before the correction this produced
    // four different numbers, and which one you got depended on php.ini.
    $results = [];

    foreach (['UTC', 'America/Chicago', 'Asia/Tokyo', 'Pacific/Auckland'] as $hostTimezone) {
        inHostTimezone($hostTimezone, function () use (&$results, $hostTimezone) {
            $worker = scheduled(org(['report_tz' => 'Europe/Berlin']));
            $session = closed($worker, '2026-09-21 07:00:00', '2026-09-21 15:00:00');

            app(Overtime::class)->recompute($worker->id);

            $results[$hostTimezone] = overtimeOf($session);
        });
    }

    // Berlin is UTC+2 in September, so 07:00-15:00 UTC is 09:00-17:00 local:
    // the scheduled day exactly, from every host.
    expect(array_values(array_unique($results)))->toBe([0], json_encode($results));
});

/* ── The two rules ───────────────────────────────────────────────────────── */

test('a worker with no schedule has no overtime at all', function () {
    // Nothing can be beyond a schedule that does not exist, and treating an
    // unset one as a zero allowance would make every second overtime for
    // everybody who has not been set up yet.
    $worker = member(org(['report_tz' => 'Asia/Manila']), UserRole::Member);
    $session = closed($worker, '2026-09-21 01:00:00', '2026-09-21 09:00:00');

    app(Overtime::class)->recompute($worker->id);

    expect(overtimeOf($session))->toBe(0);
});

test('work on a day that is not scheduled is entirely overtime', function () {
    $worker = scheduled(org(['report_tz' => 'Asia/Manila']));
    // Saturday 09:00-13:00 Manila.
    $session = closed($worker, '2026-09-19 01:00:00', '2026-09-19 05:00:00');

    app(Overtime::class)->recompute($worker->id);

    expect(overtimeOf($session))->toBe(14400);
});

test('active time is apportioned when a session straddles the window edge', function () {
    // 07:00-11:00 Manila against a 09:00-17:00 schedule: half the span is
    // inside, so half the active time is regular and half is overtime. The
    // agent reports a total, not a per-second timeline, so overlap is the only
    // honest way to divide it.
    $worker = scheduled(org(['report_tz' => 'Asia/Manila']));
    $session = closed($worker, '2026-09-20 23:00:00', '2026-09-21 03:00:00', 14400);

    app(Overtime::class)->recompute($worker->id);

    expect(overtimeOf($session))->toBe(7200);
});

test('a second device on the same day runs past the daily allowance', function () {
    // Rule 2. Two sessions both sitting inside 09:00-17:00 can still produce
    // overtime between them, because the day is only eight hours long however
    // many machines are reporting.
    $organization = org(['report_tz' => 'Asia/Manila']);
    $worker = scheduled($organization);

    $desktop = closed($worker, '2026-09-21 01:00:00', '2026-09-21 09:00:00');   // 09:00-17:00
    $laptop = closed($worker, '2026-09-21 02:00:00', '2026-09-21 06:00:00');    // 10:00-14:00

    app(Overtime::class)->recompute($worker->id);

    // The desktop consumes the whole allowance first; the laptop's four hours
    // are inside the window but have nothing left to draw on.
    expect(overtimeOf($desktop))->toBe(0)
        ->and(overtimeOf($laptop))->toBe(14400);
});

/* ── HR decisions survive ────────────────────────────────────────────────── */

test('an approved decision keeps its status and only refreshes the amount', function () {
    // A late offline batch must not silently reopen something HR signed off.
    $worker = scheduled(org(['report_tz' => 'Asia/Manila']));
    $session = closed($worker, '2026-09-19 01:00:00', '2026-09-19 05:00:00');

    app(Overtime::class)->recompute($worker->id);
    $session->forceFill(['overtime_status' => 'approved'])->save();

    // More time arrives on the same session.
    $session->forceFill(['active_s' => 18000, 'ended_at' => '2026-09-19 06:00:00'])->save();
    app(Overtime::class)->recompute($worker->id);

    expect($session->fresh()->overtime_status)->toBe('approved')
        ->and(overtimeOf($session))->toBe(18000);
});

test('a rejected decision survives recomputation too', function () {
    $worker = scheduled(org(['report_tz' => 'Asia/Manila']));
    $session = closed($worker, '2026-09-19 01:00:00', '2026-09-19 05:00:00');

    app(Overtime::class)->recompute($worker->id);
    $session->forceFill(['overtime_status' => 'rejected'])->save();

    app(Overtime::class)->recompute($worker->id);

    expect($session->fresh()->overtime_status)->toBe('rejected');
});

test('a split that falls to zero is cleared rather than left pending', function () {
    $organization = org(['report_tz' => 'Asia/Manila']);
    $worker = scheduled($organization);
    $session = closed($worker, '2026-09-19 01:00:00', '2026-09-19 05:00:00');

    app(Overtime::class)->recompute($worker->id);
    expect($session->fresh()->overtime_status)->toBe('pending');

    // Saturday becomes a working day.
    $worker->forceFill(['work_days' => '1,2,3,4,5,6'])->save();
    app(Overtime::class)->recompute($worker->id);

    expect(overtimeOf($session))->toBe(0)
        ->and($session->fresh()->overtime_status)->toBe('none');
});

/* ── The badge reads the same clock ──────────────────────────────────────── */

test('the in-schedule badge answers for the organization, not the host', function () {
    inHostTimezone('America/Chicago', function () {
        $worker = scheduled(org(['report_tz' => 'Asia/Manila']));

        // Monday 10:00 in Manila is 02:00 UTC — inside the schedule.
        $insideHours = strtotime('2026-09-21 02:00:00 UTC');
        // Monday 22:00 in Manila is 14:00 UTC — outside it.
        $outsideHours = strtotime('2026-09-21 14:00:00 UTC');

        expect(Format::withinWorkSchedule($worker, 'Asia/Manila', $insideHours))->toBeTrue()
            ->and(Format::withinWorkSchedule($worker, 'Asia/Manila', $outsideHours))->toBeFalse();
    });
});

test('the badge is null when no schedule is set', function () {
    $worker = member(org(), UserRole::Member);

    expect(Format::withinWorkSchedule($worker, 'UTC'))->toBeNull();
});

/* ── The restatement command ─────────────────────────────────────────────── */

test('a dry run reports the impact and writes nothing', function () {
    $worker = scheduled(org(['report_tz' => 'Asia/Manila']));
    $session = closed($worker, '2026-09-21 01:00:00', '2026-09-21 09:00:00');

    // A stale split, as a row computed under the old clock would carry.
    $session->forceFill(['overtime_s' => 28800, 'overtime_status' => 'approved', 'overtime_computed' => 1])->save();

    $this->artisan('overtime:recompute --dry-run')
        ->expectsOutputToContain('this moves pay')
        ->assertSuccessful();

    expect(overtimeOf($session))->toBe(28800);

    $this->artisan('overtime:recompute')->assertSuccessful();

    expect(overtimeOf($session))->toBe(0)
        ->and($session->fresh()->overtime_status)->toBe('none');
});
