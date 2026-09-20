<?php

/**
 * The period resolver — the single place every dated page, CSV export and
 * share link gets its window from.
 *
 * The property that matters most: the window is cut in the ORGANIZATION's
 * reporting timezone and only then converted to UTC. Cutting it in the
 * server's timezone made "today" depend on php.ini, and the same session could
 * land on different days for two people looking at the same screen.
 *
 * @see docs/migration/reports.md
 */

use App\Support\Period;
use App\Support\PlanLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

/** The resolved context for a query string, against a real organization row. */
function periodFor(array $query, array $organizationAttributes = [], string $default = 'week', array $allowed = []): array
{
    $organization = org($organizationAttributes);

    return app(Period::class)->context(
        Request::create('/app/overview', 'GET', $query),
        (int) $organization->id,
        $default,
        $allowed
    );
}

/* ── Calendar arithmetic ─────────────────────────────────────────────────── */

test('dates shift without being dragged across a DST boundary', function () {
    // Anchored at midday for exactly this reason: a naive +1 day at midnight
    // lands on the same date again when the clocks go forward.
    expect(Period::addDays('2026-03-28', 1))->toBe('2026-03-29')
        ->and(Period::addDays('2026-10-25', 1))->toBe('2026-10-26')
        ->and(Period::addDays('2026-01-01', -1))->toBe('2025-12-31')
        ->and(Period::addDays('2026-02-28', 1))->toBe('2026-03-01');
});

test('a range label shortens as far as it can without becoming ambiguous', function () {
    expect(Period::rangeLabel('2026-08-03', '2026-08-03'))->toBe('Mon 3 Aug 2026')
        ->and(Period::rangeLabel('2026-08-03', '2026-08-09'))->toBe('3–9 Aug 2026')
        ->and(Period::rangeLabel('2026-07-28', '2026-08-03'))->toBe('28 Jul – 3 Aug 2026')
        ->and(Period::rangeLabel('2025-12-29', '2026-01-04'))->toBe('29 Dec 2025 – 4 Jan 2026');
});

/* ── Timezone ────────────────────────────────────────────────────────────── */

test('a day is cut in the organization timezone, not the server one', function () {
    $ctx = periodFor(
        ['period' => 'day', 'date' => '2026-09-20'],
        ['report_tz' => 'Asia/Manila'],
        'day',
        ['day']
    );

    // Manila is UTC+8, so its 20 September begins at 16:00 UTC on the 19th.
    expect($ctx['start'])->toBe('2026-09-19 16:00:00')
        ->and($ctx['end'])->toBe('2026-09-20 16:00:00')
        ->and($ctx['tz'])->toBe('Asia/Manila');
});

test('an unknown timezone falls back to UTC rather than throwing', function () {
    // DateTimeZone would throw on every page load, so the value is validated
    // when it is read rather than trusted from the settings form.
    $ctx = periodFor(['period' => 'day', 'date' => '2026-09-20'], ['report_tz' => 'Mars/Olympus'], 'day', ['day']);

    expect($ctx['tz'])->toBe('UTC')
        ->and($ctx['start'])->toBe('2026-09-20 00:00:00');
});

/* ── Period bounds ───────────────────────────────────────────────────────── */

test('a week honours the organization week start', function () {
    $monday = periodFor(['period' => 'week', 'date' => '2026-09-16'], ['week_start' => 1], 'week', ['week']);
    $sunday = periodFor(['period' => 'week', 'date' => '2026-09-16'], ['week_start' => 7], 'week', ['week']);

    // 16 September 2026 is a Wednesday.
    expect($monday['start_date'])->toBe('2026-09-14')
        ->and($monday['end_date'])->toBe('2026-09-20')
        ->and($sunday['start_date'])->toBe('2026-09-13')
        ->and($sunday['end_date'])->toBe('2026-09-19');
});

test('a month covers the calendar month and steps a month at a time', function () {
    $ctx = periodFor(['period' => 'month', 'date' => '2026-09-16'], [], 'month', ['month']);

    expect($ctx['start_date'])->toBe('2026-09-01')
        ->and($ctx['end_date'])->toBe('2026-09-30')
        ->and($ctx['prev'])->toBe('2026-08-01')
        ->and($ctx['label'])->toBe('September 2026');
});

test('a semimonthly pay period splits the month at the fifteenth', function () {
    $first = periodFor(['period' => 'pay', 'date' => '2026-09-10'], ['pay_cycle' => 'semimonthly'], 'pay', ['pay']);
    $second = periodFor(['period' => 'pay', 'date' => '2026-09-20'], ['pay_cycle' => 'semimonthly'], 'pay', ['pay']);

    expect($first['start_date'])->toBe('2026-09-01')
        ->and($first['end_date'])->toBe('2026-09-15')
        ->and($second['start_date'])->toBe('2026-09-16')
        ->and($second['end_date'])->toBe('2026-09-30');
});

test('a fixed-block pay cycle counts from the organization anchor', function () {
    $ctx = periodFor(
        ['period' => 'pay', 'date' => '2026-09-20'],
        ['pay_cycle' => 'biweekly', 'pay_cycle_anchor' => '2026-01-05'],
        'pay',
        ['pay']
    );

    // 2026-01-05 plus whole 14-day blocks.
    expect($ctx['days'])->toBe(14)
        ->and((strtotime($ctx['start_date']) - strtotime('2026-01-05')) / 86400 % 14)->toBe(0);
});

test('a weekly pay cycle resolves to the week', function () {
    $pay = periodFor(['period' => 'pay', 'date' => '2026-09-16'], ['pay_cycle' => 'weekly'], 'pay', ['pay']);
    $week = periodFor(['period' => 'week', 'date' => '2026-09-16'], ['pay_cycle' => 'weekly'], 'week', ['week']);

    expect($pay['start_date'])->toBe($week['start_date'])
        ->and($pay['end_date'])->toBe($week['end_date']);
});

/* ── Query handling ──────────────────────────────────────────────────────── */

test('a period this page does not offer falls back to its default', function () {
    // Otherwise the page renders data with no pill lit and no way to tell
    // which window is on screen.
    $ctx = periodFor(['period' => 'month'], [], 'day', ['day', 'week']);

    expect($ctx['period'])->toBe('day');
});

test('a custom range is honoured, and reversed inputs are tolerated', function () {
    $ctx = periodFor(['period' => 'range', 'from' => '2026-09-10', 'to' => '2026-09-01']);

    expect($ctx['period'])->toBe('range')
        ->and($ctx['start_date'])->toBe('2026-09-01')
        ->and($ctx['end_date'])->toBe('2026-09-10')
        ->and($ctx['days'])->toBe(10);
});

test('an incomplete range falls back rather than rendering an empty window', function () {
    $ctx = periodFor(['period' => 'range', 'from' => '2026-09-10'], [], 'week', ['week']);

    expect($ctx['period'])->toBe('week');
});

test('a malformed anchor date is replaced with today', function () {
    $ctx = periodFor(['period' => 'day', 'date' => 'yesterday-ish'], [], 'day', ['day']);

    expect($ctx['anchor'])->toBe($ctx['today']);
});

test('a range steps by its own length', function () {
    $ctx = periodFor(['period' => 'range', 'from' => '2026-09-01', 'to' => '2026-09-07']);

    expect($ctx['days'])->toBe(7)
        ->and($ctx['prev'])->toBe('2026-08-25')
        ->and($ctx['next'])->toBe('2026-09-08');
});

/* ── The plan clamp ──────────────────────────────────────────────────────── */

test('the solo plan clamps history to seven days', function () {
    // Applied in the resolver so every page, export and share link inherits
    // one clamp instead of each re-deriving the rule.
    app(PlanLimits::class)->flush();

    $ctx = periodFor(['period' => 'month'], ['plan_type' => 'solo'], 'month', ['month']);

    expect($ctx['start_date'])->toBe(Period::addDays($ctx['today'], -6))
        ->and($ctx['history_capped'])->toBe(7);
});

test('a paid plan is not clamped', function () {
    app(PlanLimits::class)->flush();

    $ctx = periodFor(['period' => 'month', 'date' => '2026-09-16'], ['plan_type' => 'organization'], 'month', ['month']);

    expect($ctx['start_date'])->toBe('2026-09-01')
        ->and($ctx)->not->toHaveKey('history_capped');
});

test('a solo window already inside the limit is left alone', function () {
    app(PlanLimits::class)->flush();

    $ctx = periodFor(['period' => 'day'], ['plan_type' => 'solo'], 'day', ['day']);

    expect($ctx['start_date'])->toBe($ctx['today'])
        ->and($ctx)->not->toHaveKey('history_capped');
});

/* ── Export links ────────────────────────────────────────────────────────── */

test('the export query string mirrors what is on screen', function () {
    $pills = periodFor(['period' => 'month', 'date' => '2026-09-16'], [], 'month', ['month']);
    $range = periodFor(['period' => 'range', 'from' => '2026-09-01', 'to' => '2026-09-07']);

    expect(Period::queryString($pills))->toBe('period=month&date=2026-09-16')
        ->and(Period::queryString($range))->toBe('period=range&from=2026-09-01&to=2026-09-07');
});
