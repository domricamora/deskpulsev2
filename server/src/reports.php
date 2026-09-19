<?php
/** Aggregation: period ranges, time/activity rollups, salary cost, chart
 *  series, and CSV. Shared by the dashboard and the public share view. */

// ── Reporting periods ─────────────────────────────────────────────────────────
// Sessions are stored in UTC. Every period window is therefore cut in the ORG's
// reporting timezone and then converted to UTC for the query — previously the
// window was cut in PHP's default timezone (which the repo never sets), so which
// sessions landed in "today" depended on php.ini. The org timezone is set on
// Settings; it defaults to UTC, which is the pre-existing behaviour on a UTC host.

/** Org reporting config (timezone, week start, pay cycle). Cached per org id. */
function report_org_cfg(?int $orgId = null): array
{
    static $cache = [];
    if ($orgId === null) {
        $u = function_exists('current_user') ? current_user() : null;
        $orgId = $u ? (int) ($u['org_id'] ?? 0) : 0;
    }
    $orgId = (int) $orgId;
    if (isset($cache[$orgId])) {
        return $cache[$orgId];
    }
    $cfg = ['tz' => 'UTC', 'week_start' => 1, 'pay_cycle' => 'semimonthly',
            'pay_cycle_anchor' => null, 'pay_currency' => 'USD'];
    if ($orgId > 0) {
        $row = db_one('SELECT report_tz, week_start, pay_cycle, pay_cycle_anchor, pay_currency
                       FROM organizations WHERE id = ?', [$orgId]);
        if ($row) {
            if (!empty($row['report_tz']) && in_array($row['report_tz'], timezone_identifiers_list(), true)) {
                $cfg['tz'] = $row['report_tz'];
            }
            $ws = (int) ($row['week_start'] ?? 1);
            $cfg['week_start']       = ($ws >= 1 && $ws <= 7) ? $ws : 1;
            $cfg['pay_cycle']        = in_array($row['pay_cycle'] ?? '', ['weekly', 'biweekly', 'semimonthly', 'rolling15', 'monthly'], true)
                                       ? $row['pay_cycle'] : 'semimonthly';
            $cfg['pay_cycle_anchor'] = $row['pay_cycle_anchor'] ?: null;
            $cfg['pay_currency']     = $row['pay_currency'] ?: 'USD';
        }
    }
    return $cache[$orgId] = $cfg;
}

/** The org's reporting timezone (IANA). */
function report_tz(?int $orgId = null): string
{
    return report_org_cfg($orgId)['tz'];
}

/** 'Y-m-d H:i:s' wall-clock in $tz → the same instant as a UTC 'Y-m-d H:i:s'. */
function local_to_utc(string $localDatetime, string $tz): string
{
    try {
        $dt = new DateTime($localDatetime, new DateTimeZone($tz));
    } catch (\Exception $e) {
        return $localDatetime;
    }
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Y-m-d H:i:s');
}

/** A UTC 'Y-m-d H:i:s' → the local calendar date ('Y-m-d') it falls on in $tz. */
function utc_to_local_date(string $utcDatetime, string $tz): string
{
    try {
        $dt = new DateTime($utcDatetime, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone($tz));
        return $dt->format('Y-m-d');
    } catch (\Exception $e) {
        return substr($utcDatetime, 0, 10);
    }
}

/** Shift a Y-m-d date by N days (calendar-safe, DST-immune). */
function date_add_days(string $ymd, int $days): string
{
    return date('Y-m-d', strtotime($ymd . ' 12:00:00 +' . $days . ' days'));
}

/** Human label for an inclusive local date span: "3–9 Aug 2026", "28 Jul – 3 Aug 2026". */
function range_label(string $startDate, string $endDateInclusive): string
{
    $a = strtotime($startDate . ' 12:00:00');
    $b = strtotime($endDateInclusive . ' 12:00:00');
    if ($a === $b) {
        return date('D j M Y', $a);
    }
    if (date('Y-m', $a) === date('Y-m', $b)) {
        return date('j', $a) . '–' . date('j M Y', $b);
    }
    if (date('Y', $a) === date('Y', $b)) {
        return date('j M', $a) . ' – ' . date('j M Y', $b);
    }
    return date('j M Y', $a) . ' – ' . date('j M Y', $b);
}

/**
 * Resolve a period into local calendar bounds plus navigation.
 * Returns [startDate, endDateExclusive, label, prevAnchor, nextAnchor] — all Y-m-d.
 * $anchorDate is any local date inside the wanted window.
 */
function period_bounds(string $period, string $anchorDate, array $cfg): array
{
    $ts = strtotime($anchorDate . ' 12:00:00');
    switch ($period) {
        case 'day':
            $start = date('Y-m-d', $ts);
            $end   = date_add_days($start, 1);
            return [$start, $end, date('D j M Y', $ts), date_add_days($start, -1), $end];

        case 'month':
            $start = date('Y-m-01', $ts);
            $end   = date('Y-m-d', strtotime($start . ' 12:00:00 first day of next month'));
            $prev  = date('Y-m-d', strtotime($start . ' 12:00:00 first day of last month'));
            return [$start, $end, date('F Y', $ts), $prev, $end];

        case 'pay':
            return pay_period_bounds($anchorDate, $cfg);

        case 'week':
        default:
            $dow    = (int) date('N', $ts);                       // 1=Mon..7=Sun
            $offset = ($dow - $cfg['week_start'] + 7) % 7;
            $start  = date_add_days(date('Y-m-d', $ts), -$offset);
            $end    = date_add_days($start, 7);
            return [$start, $end, range_label($start, date_add_days($end, -1)),
                    date_add_days($start, -7), $end];
    }
}

/**
 * The org's configured payroll window containing $anchorDate.
 *  semimonthly — 1st–15th and 16th–end of month (the standard PH/Wise cut-off)
 *  rolling15   — fixed 15-day blocks counted from the org's anchor date
 *  biweekly    — fixed 14-day blocks counted from the org's anchor date
 *  weekly / monthly — as the matching calendar period
 */
function pay_period_bounds(string $anchorDate, array $cfg): array
{
    $cycle = $cfg['pay_cycle'];
    $ts    = strtotime($anchorDate . ' 12:00:00');

    if ($cycle === 'weekly' || $cycle === 'monthly') {
        return period_bounds($cycle === 'weekly' ? 'week' : 'month', $anchorDate, $cfg);
    }

    if ($cycle === 'semimonthly') {
        $day = (int) date('j', $ts);
        if ($day <= 15) {
            $start = date('Y-m-01', $ts);
            $end   = date('Y-m-16', $ts);
            $prev  = date('Y-m-16', strtotime($start . ' 12:00:00 first day of last month'));
        } else {
            $start = date('Y-m-16', $ts);
            $end   = date('Y-m-d', strtotime(date('Y-m-01', $ts) . ' 12:00:00 first day of next month'));
            $prev  = date('Y-m-01', $ts);
        }
        return [$start, $end, range_label($start, date_add_days($end, -1)), $prev, $end];
    }

    // Fixed-length blocks (rolling15 / biweekly) counted from the org's anchor.
    $len    = $cycle === 'biweekly' ? 14 : 15;
    $origin = $cfg['pay_cycle_anchor'] ?: '2024-01-01';
    $o      = strtotime($origin . ' 12:00:00');
    $diff   = (int) floor(($ts - $o) / 86400);
    $n      = (int) floor($diff / $len);
    $start  = date_add_days(date('Y-m-d', $o), $n * $len);
    $end    = date_add_days($start, $len);
    return [$start, $end, range_label($start, date_add_days($end, -1)),
            date_add_days($start, -$len), $end];
}

/**
 * Return [startUTC, endUTC] MySQL datetimes for a period.
 * Kept signature-compatible with the original: $anchor is a unix timestamp (the
 * caller's clock) and defaults to now.
 */
function period_range(string $period, ?int $anchor = null, ?int $orgId = null): array
{
    $cfg = report_org_cfg($orgId);
    $anchorDate = $anchor !== null
        ? date('Y-m-d', $anchor)
        : (new DateTime('now', new DateTimeZone($cfg['tz'])))->format('Y-m-d');
    [$start, $end] = period_bounds($period, $anchorDate, $cfg);
    return [local_to_utc($start . ' 00:00:00', $cfg['tz']), local_to_utc($end . ' 00:00:00', $cfg['tz'])];
}

/** The period keys a page may offer, with their pill labels. */
function period_options(array $only = []): array
{
    $all = ['day' => 'Today', 'week' => 'This week', 'pay' => 'Pay period', 'month' => 'This month'];
    if (!$only) {
        return $all;
    }
    $out = [];
    foreach ($only as $k) {
        if (isset($all[$k])) {
            $out[$k] = $all[$k];
        }
    }
    return $out;
}

/**
 * Resolve the full period context from the query string. This is the single
 * resolver for every dated page: it validates ?period, honours ?date as the
 * in-window anchor (for any period, including day — previously only week/month
 * could be navigated), handles ?period=range&from&to, and returns the prev/next
 * anchors that drive the ◀ ▶ buttons.
 *
 * $allowed restricts which pills this page accepts; an out-of-range ?period
 * falls back to $default instead of silently rendering data with no pill lit.
 */
function period_ctx(string $default = 'week', array $allowed = [], ?int $orgId = null): array
{
    $cfg   = report_org_cfg($orgId);
    $tz    = $cfg['tz'];
    $today = (new DateTime('now', new DateTimeZone($tz)))->format('Y-m-d');
    $valid = $allowed ?: ['day', 'week', 'pay', 'month'];

    $period = (string) ($_GET['period'] ?? $default);
    if ($period !== 'range' && !in_array($period, $valid, true)) {
        $period = $default;
    }

    $ymd = '/^\d{4}-\d{2}-\d{2}$/';
    if ($period === 'range') {
        $from = (string) ($_GET['from'] ?? '');
        $to   = (string) ($_GET['to'] ?? '');
        if (preg_match($ymd, $from) && preg_match($ymd, $to)) {
            if (strcmp($from, $to) > 0) {
                [$from, $to] = [$to, $from];       // tolerate reversed inputs
            }
            $endEx = date_add_days($to, 1);
            $span  = max(1, (int) round((strtotime($to . ' 12:00:00') - strtotime($from . ' 12:00:00')) / 86400) + 1);
            return [
                'period' => 'range', 'tz' => $tz, 'cycle' => $cfg['pay_cycle'],
                'start'  => local_to_utc($from . ' 00:00:00', $tz),
                'end'    => local_to_utc($endEx . ' 00:00:00', $tz),
                'start_date' => $from, 'end_date' => $to, 'end_date_ex' => $endEx,
                'anchor' => $from, 'from' => $from, 'to' => $to,
                'label'  => range_label($from, $to),
                'prev'   => date_add_days($from, -$span), 'next' => date_add_days($from, $span),
                'today'  => $today, 'days' => $span,
            ];
        }
        $period = $default;                        // incomplete range → fall back
    }

    $anchor = (string) ($_GET['date'] ?? '');
    if (!preg_match($ymd, $anchor)) {
        $anchor = $today;
    }
    [$start, $endEx, $label, $prev, $next] = period_bounds($period, $anchor, $cfg);
    return period_apply_plan_limit([
        'period' => $period, 'tz' => $tz, 'cycle' => $cfg['pay_cycle'],
        'start'  => local_to_utc($start . ' 00:00:00', $tz),
        'end'    => local_to_utc($endEx . ' 00:00:00', $tz),
        'start_date' => $start, 'end_date' => date_add_days($endEx, -1), 'end_date_ex' => $endEx,
        'anchor' => $anchor, 'from' => null, 'to' => null,
        'label'  => $label, 'prev' => $prev, 'next' => $next, 'today' => $today,
        'days'   => max(1, (int) round((strtotime($endEx . ' 12:00:00') - strtotime($start . ' 12:00:00')) / 86400)),
    ], $orgId, $today);
}

/**
 * Clamp a resolved period to the plan's history window (Solo: 7 days).
 *
 * Applied here, in the single resolver, so every page, CSV export and share link
 * inherits it rather than each re-implementing the rule and one of them forgetting.
 * Sets 'history_capped' so a template can say why the window is short instead of
 * silently showing less than was asked for.
 */
function period_apply_plan_limit(array $ctx, ?int $orgId, string $today): array
{
    $orgId = $orgId ?? (int) (current_user()['org_id'] ?? 0);
    if (!$orgId) {
        return $ctx;
    }
    $days = plan_limits($orgId)['history_days'];
    if ($days <= 0) {
        return $ctx;
    }
    $earliest = date_add_days($today, -($days - 1));
    if (strcmp($ctx['start_date'], $earliest) >= 0) {
        return $ctx;                       // already inside the window
    }
    $tz = $ctx['tz'];
    $ctx['start_date'] = $earliest;
    $ctx['start'] = local_to_utc($earliest . ' 00:00:00', $tz);
    $ctx['history_capped'] = $days;
    $ctx['days'] = max(1, (int) round(
        (strtotime($ctx['end_date_ex'] . ' 12:00:00') - strtotime($earliest . ' 12:00:00')) / 86400));
    return $ctx;
}

/**
 * Legacy list form of period_ctx(), kept so existing call sites keep working:
 * [start, end, period, anchorDate, from, to]. The resolved context is also
 * stashed for period_switch_html() so the widget can render the period label and
 * prev/next links without re-parsing the query string.
 */
function period_range_from_request(string $default = 'week', array $allowed = []): array
{
    $c = period_ctx($default, $allowed);
    $GLOBALS['DP_PERIOD_CTX'] = $c;
    return [$c['start'], $c['end'], $c['period'], $c['anchor'], $c['from'], $c['to']];
}

/** Sessions for a set of users overlapping [start, end). */
function sessions_for_users(array $userIds, string $start, string $end, bool $approvedOnly = true): array
{
    if (!$userIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($userIds), '?'));
    $sql = "SELECT * FROM sessions
            WHERE user_id IN ($in) AND started_at >= ? AND started_at < ?";
    $params = array_merge($userIds, [$start, $end]);
    if ($approvedOnly) {
        $sql .= " AND approval_status = 'approved'";
    }
    $sql .= ' ORDER BY started_at ASC';
    return db_all($sql, $params);
}

/** All user ids in an org. */
/**
 * Close sessions whose agent has gone silent (offline, closed or crashed). The
 * end time is set to the last time we heard from the agent, so the recorded
 * duration reflects real tracked time rather than the dead gap. A new agent
 * instance always opens a *fresh* session, so closing the stale one is safe.
 * Called opportunistically on dashboard loads, the live poll and agent reconnect.
 */
/** ISO weekday number (1=Mon..7=Sun) → short label. */
function weekday_label(string $n): string
{
    return ['1' => 'Mon', '2' => 'Tue', '3' => 'Wed', '4' => 'Thu',
            '5' => 'Fri', '6' => 'Sat', '7' => 'Sun'][$n] ?? $n;
}

/** Human-readable standard work schedule, e.g. "Mon, Tue, Wed, Thu, Fri · 09:00–17:00". */
function work_schedule_label(array $u): string
{
    $ws = $u['work_start'] ?? null;
    $we = $u['work_end'] ?? null;
    $days = array_values(array_filter(explode(',', (string) ($u['work_days'] ?? ''))));
    if (!$ws || !$we || !$days) {
        return 'Not set';
    }
    $dl = implode(', ', array_map('weekday_label', $days));
    return $dl . ' · ' . substr($ws, 0, 5) . '–' . substr($we, 0, 5);
}

/** Is the given moment within the user's standard schedule? null if none set.
 *  Uses server local wall-clock time as a best-effort approximation. */
function within_work_schedule(array $u, ?int $ts = null): ?bool
{
    $ws = $u['work_start'] ?? null;
    $we = $u['work_end'] ?? null;
    $days = array_values(array_filter(explode(',', (string) ($u['work_days'] ?? ''))));
    if (!$ws || !$we || !$days) {
        return null;
    }
    $ts = $ts ?? time();
    if (!in_array((string) ((int) date('N', $ts)), $days, true)) {
        return false;
    }
    $hm = date('H:i:s', $ts);
    return $hm >= $ws && $hm <= $we;
}

/** Creditable active seconds for pay: the overtime portion is excluded unless HR has
 *  approved it. So 'pending'/'rejected' overtime never reaches payroll. */
function creditable_active_s(array $s): int
{
    if (($s['overtime_status'] ?? 'none') === 'approved') {
        return (int) $s['active_s'];
    }
    return max(0, (int) $s['active_s'] - (int) ($s['overtime_s'] ?? 0));
}

/**
 * Recalculate the overtime split for a worker's CLOSED sessions, grouped by local day.
 * Overtime = active time worked outside the scheduled window/days (rule 1) OR beyond
 * the scheduled daily length (rule 2) — whichever applies. The overtime portion is
 * held at overtime_status='pending' for HR; an existing approved/rejected decision is
 * preserved (only the amount is refreshed). Marks each row overtime_computed=1.
 * Uses server local wall-clock, consistent with within_work_schedule()/daily_series().
 */
function recompute_overtime(int $userId, ?string $sinceUtc = null): void
{
    $u = db_one('SELECT work_start, work_end, work_days FROM users WHERE id = ?', [$userId]);
    if (!$u) {
        return;
    }
    $params = [$userId];
    $sql = 'SELECT * FROM sessions WHERE user_id = ? AND ended_at IS NOT NULL';
    if ($sinceUtc !== null) {
        $sql .= ' AND started_at >= ?';
        $params[] = $sinceUtc;
    }
    $sql .= ' ORDER BY started_at ASC';
    $sessions = db_all($sql, $params);
    if (!$sessions) {
        return;
    }

    $ws = $u['work_start'] ?? null;
    $we = $u['work_end'] ?? null;
    $days = array_values(array_filter(explode(',', (string) ($u['work_days'] ?? ''))));
    $scheduleSet = $ws && $we && $days;

    // Group by day, then walk each day in order. Uses the naive stored value as
    // server-local wall-clock, exactly like daily_series()/within_work_schedule().
    $byDay = [];
    foreach ($sessions as $s) {
        $byDay[date('Y-m-d', strtotime($s['started_at']))][] = $s;
    }

    foreach ($byDay as $day => $rows) {
        $dow = (string) ((int) date('N', strtotime($day)));
        $scheduled = $scheduleSet && in_array($dow, $days, true);
        $winStart  = $scheduled ? strtotime($day . ' ' . $ws) : 0;
        $winEnd    = $scheduled ? strtotime($day . ' ' . $we) : 0;
        $allowance = $scheduled ? max(0, $winEnd - $winStart) : 0;   // regular seconds available
        $regularUsed = 0;

        foreach ($rows as $s) {
            if (!$scheduleSet) {
                overtime_persist($s, 0);   // no schedule defined → nothing is overtime
                continue;
            }
            $active = (int) $s['active_s'];
            $startU = strtotime($s['started_at']);
            $endU   = strtotime($s['ended_at']);
            $span   = max(0, $endU - $startU);

            // Active seconds whose wall-clock falls inside the scheduled window.
            if (!$scheduled) {
                $insideActive = 0;                                   // non-work day → all outside
            } elseif ($span > 0) {
                $overlap = max(0, min($endU, $winEnd) - max($startU, $winStart));
                $insideActive = (int) round($active * $overlap / $span);
            } else {
                $insideActive = ($startU >= $winStart && $startU <= $winEnd) ? $active : 0;
            }
            $insideActive  = max(0, min($active, $insideActive));
            $outsideActive = $active - $insideActive;                // rule 1: outside window/day

            $regularPart = min($insideActive, max(0, $allowance - $regularUsed));
            $regularUsed += $regularPart;
            $overtime = $outsideActive + ($insideActive - $regularPart);   // rule 1 + rule 2

            overtime_persist($s, $overtime);
        }
    }
}

/** Persist one session's recomputed overtime, preserving any HR decision already made. */
function overtime_persist(array $s, int $overtime): void
{
    $overtime = max(0, $overtime);
    $cur = $s['overtime_status'] ?? 'none';
    if ($overtime <= 0) {
        $status = 'none';
    } elseif ($cur === 'approved' || $cur === 'rejected') {
        $status = $cur;          // keep HR's call; just refresh the amount
    } else {
        $status = 'pending';
    }
    db_exec(
        'UPDATE sessions SET overtime_s = ?, overtime_status = ?, overtime_computed = 1 WHERE id = ?',
        [$overtime, $status, (int) $s['id']]
    );
}

/** Process closed sessions whose overtime split hasn't been computed yet (e.g. after a
 *  bulk stale-session close). Cheap no-op when nothing is outstanding. */
function recompute_pending_overtime(): void
{
    $rows = db_all(
        "SELECT user_id, MIN(started_at) AS since FROM sessions
         WHERE ended_at IS NOT NULL AND overtime_computed = 0
         GROUP BY user_id"
    );
    foreach ($rows as $r) {
        // Start a day earlier so the full day's sessions are summed for the
        // daily-allowance walk (boundary slack).
        $since = date('Y-m-d 00:00:00', strtotime($r['since']) - 86400);
        recompute_overtime((int) $r['user_id'], $since);
    }
}

function close_stale_sessions(int $minutes = 15): void
{
    $minutes = max(1, $minutes);
    db_exec(
        "UPDATE sessions
            SET ended_at = COALESCE(last_seen_at, started_at)
          WHERE ended_at IS NULL
            AND COALESCE(last_seen_at, started_at) < (UTC_TIMESTAMP() - INTERVAL $minutes MINUTE)"
    );
}

function org_user_ids(int $orgId): array
{
    $ids = array_column(
        db_all('SELECT id FROM users WHERE org_id = ? AND role <> "super_admin"', [$orgId]),
        'id'
    );
    // Sentinel so callers building `IN (...)` never produce an empty `IN ()`
    // (e.g. a super admin's Platform org has no ordinary members). 0 matches no row.
    return $ids ?: [0];
}

/** User ids in the same team(s) as $userId (for team-scoped managers/viewers). */
function team_member_ids(int $userId): array
{
    $rows = db_all(
        'SELECT DISTINCT tm2.user_id FROM team_members tm1
         JOIN team_members tm2 ON tm2.team_id = tm1.team_id
         JOIN users u ON u.id = tm2.user_id
         WHERE tm1.user_id = ? AND u.role <> "super_admin"',
        [$userId]
    );
    $ids = array_map('intval', array_column($rows, 'user_id'));
    return $ids ?: [0];   // no team assigned → sees no one
}

function session_total_s(array $s): int
{
    $tot = (int) $s['active_s'] + (int) $s['inactive_s'];
    if ($tot > 0) {
        return $tot;
    }
    if (!empty($s['ended_at'])) {
        return max(0, strtotime($s['ended_at']) - strtotime($s['started_at']));
    }
    return 0;
}

function session_activity_pct(array $s): int
{
    $tot = session_total_s($s);
    return $tot ? (int) round(100 * (int) $s['active_s'] / $tot) : 0;
}

/** Roll a list of sessions into totals. */
function summarize(array $sessions): array
{
    $active = array_sum(array_map(fn($s) => (int) $s['active_s'], $sessions));
    $inactive = array_sum(array_map(fn($s) => (int) $s['inactive_s'], $sessions));
    $total = $active + $inactive;
    return [
        'active_s'     => $active,
        'inactive_s'   => $inactive,
        'total_s'      => $total,
        'activity_pct' => $total ? (int) round(100 * $active / $total) : 0,
        'count'        => count($sessions),
    ];
}

/** Sum active-time labor cost using each user's normalized hourly rate. */
function labor_cost(array $sessions, array $usersById): array
{
    $total = 0.0;
    $currency = 'USD';
    foreach ($sessions as $s) {
        $u = $usersById[$s['user_id']] ?? null;
        if (!$u) {
            continue;
        }
        $currency = $u['currency'] ?: $currency;
        $total += creditable_active_s($s) / 3600.0 * user_hourly_rate($u);
    }
    return ['amount' => round($total, 2), 'currency' => $currency];
}

/** Per-day active vs inactive hours for a chart. */
function daily_series(array $sessions, string $start, string $end, ?string $tz = null): array
{
    // Bucket by the org's reporting day, not the server's — $start/$end are UTC, so
    // bucketing on the naive string put bars in a different day than the table rows.
    $tz = $tz ?: report_tz();
    $firstDay = utc_to_local_date($start, $tz);
    $lastDay  = utc_to_local_date(date('Y-m-d H:i:s', strtotime($end) - 1), $tz);

    $labels = [];
    $buckets = [];
    $day = $firstDay;
    for ($i = 0; $i < 400 && strcmp($day, $lastDay) <= 0; $i++) {
        $labels[] = $day;
        $buckets[$day] = ['active' => 0, 'inactive' => 0];
        $day = date_add_days($day, 1);
    }
    foreach ($sessions as $s) {
        $key = utc_to_local_date($s['started_at'], $tz);
        if (isset($buckets[$key])) {
            $buckets[$key]['active'] += (int) $s['active_s'];
            $buckets[$key]['inactive'] += (int) $s['inactive_s'];
        }
    }
    return [
        'labels'   => $labels,
        'active'   => array_map(fn($k) => round($buckets[$k]['active'] / 3600, 2), $labels),
        'inactive' => array_map(fn($k) => round($buckets[$k]['inactive'] / 3600, 2), $labels),
    ];
}

/**
 * Time spent per task across a set of users within a period (approved sessions).
 * Returns rows with task title, owner, client name, total active seconds and
 * session count — used by the Tasks page, public share pages and client viewers.
 */
function task_time_rows(array $userIds, string $start, string $end, int $limit = 50): array
{
    if (!$userIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($userIds), '?'));
    $limit = max(1, (int) $limit);
    return db_all(
        "SELECT t.id, t.title, t.status, us.name AS owner_name, c.name AS client_name,
                SUM(s.active_s) AS secs, COUNT(*) AS cnt
         FROM sessions s
         JOIN tasks t ON t.id = s.task_id
         JOIN users us ON us.id = t.user_id
         LEFT JOIN clients c ON c.id = t.client_id
         WHERE s.user_id IN ($in) AND s.task_id IS NOT NULL
           AND s.approval_status = 'approved'
           AND s.started_at >= ? AND s.started_at < ?
         GROUP BY t.id, t.title, t.status, us.name, c.name
         ORDER BY secs DESC LIMIT $limit",
        array_merge($userIds, [$start, $end])
    );
}

/** Aggregate focused-window seconds by app across the given sessions. */
function top_apps(array $sessionIds, int $limit = 8): array
{
    if (!$sessionIds) {
        return ['labels' => [], 'seconds' => []];
    }
    $in = implode(',', array_fill(0, count($sessionIds), '?'));
    $rows = db_all(
        "SELECT app_name, SUM(focus_seconds) AS secs FROM window_events
         WHERE session_id IN ($in) GROUP BY app_name ORDER BY secs DESC LIMIT $limit",
        $sessionIds
    );
    return [
        'labels'  => array_map(fn($r) => $r['app_name'] ?: 'Unknown', $rows),
        'seconds' => array_map(fn($r) => (int) $r['secs'], $rows),
    ];
}

/**
 * Activity timeline for an interactive chart: a series of activity-% points over
 * time plus screenshot markers, each annotated with the app/window that was active
 * and the worker, so the UI can show hover tooltips + a screenshot preview.
 * Returns ['points'=>[{x,pct}], 'markers'=>[{x,url,app,title,who}], 'start', 'end'].
 */
function activity_timeline(array $userIds, string $start, string $end): array
{
    $startE = strtotime($start . ' UTC');
    $endE = strtotime($end . ' UTC');
    $empty = ['points' => [], 'markers' => [], 'start' => $startE, 'end' => $endE];
    if (!$userIds) {
        return $empty;
    }
    $in = implode(',', array_fill(0, count($userIds), '?'));
    $sess = db_all(
        "SELECT id, user_id FROM sessions WHERE user_id IN ($in)
         AND started_at >= ? AND started_at < ? AND approval_status = 'approved'",
        array_merge($userIds, [$start, $end])
    );
    if (!$sess) {
        return $empty;
    }
    $sids = array_column($sess, 'id');
    $sin = implode(',', array_fill(0, count($sids), '?'));
    $userBySession = [];
    foreach ($sess as $s) { $userBySession[$s['id']] = $s['user_id']; }
    $names = [];
    foreach (db_all("SELECT id, name FROM users WHERE id IN ($in)", $userIds) as $r) {
        $names[$r['id']] = $r['name'];
    }

    $points = [];
    foreach (db_all("SELECT ts, activity_pct FROM activity_samples WHERE session_id IN ($sin)
                     ORDER BY ts LIMIT 800", $sids) as $a) {
        $points[] = ['x' => strtotime($a['ts'] . ' UTC'), 'pct' => (int) $a['activity_pct']];
    }

    // Focused-window observations per session (sorted) to label each screenshot.
    $winBySession = [];
    foreach (db_all("SELECT session_id, ts, app_name, window_title FROM window_events
                     WHERE session_id IN ($sin) ORDER BY ts", $sids) as $w) {
        $winBySession[$w['session_id']][] =
            ['t' => strtotime($w['ts'] . ' UTC'), 'app' => $w['app_name'], 'title' => $w['window_title']];
    }
    $markers = [];
    foreach (db_all("SELECT session_id, ts, file_path FROM screenshots WHERE session_id IN ($sin)
                     ORDER BY ts", $sids) as $sc) {
        $t = strtotime($sc['ts'] . ' UTC');
        $app = ''; $title = '';
        foreach (($winBySession[$sc['session_id']] ?? []) as $we) {
            if ($we['t'] <= $t + 60) { $app = $we['app']; $title = $we['title']; } else { break; }
        }
        $markers[] = ['x' => $t, 'url' => url('/uploads/' . $sc['file_path']),
                      'app' => $app, 'title' => $title,
                      'who' => $names[$userBySession[$sc['session_id']]] ?? ''];
    }
    return ['points' => $points, 'markers' => $markers, 'start' => $startE, 'end' => $endE];
}

/** Build a CSV string for a set of sessions. */
function sessions_csv(array $sessions, array $usersById): string
{
    $out = fopen('php://temp', 'r+');
    fputcsv($out, ['Date (UTC)', 'User', 'Client', 'Source', 'Status',
                   'Active (h)', 'Inactive (h)', 'Activity %', 'Cost', 'Note']);
    foreach ($sessions as $s) {
        $u = $usersById[$s['user_id']] ?? null;
        $cost = (int) $s['active_s'] / 3600.0 * ($u ? user_hourly_rate($u) : 0);
        $client = $s['client_id']
            ? (db_one('SELECT name FROM clients WHERE id = ?', [$s['client_id']])['name'] ?? '')
            : '';
        fputcsv($out, [
            gmdate('Y-m-d H:i', strtotime($s['started_at'] . ' UTC')),
            $u['name'] ?? $s['user_id'], $client, $s['source'], $s['approval_status'],
            round((int) $s['active_s'] / 3600, 2),
            round((int) $s['inactive_s'] / 3600, 2),
            session_activity_pct($s), round($cost, 2),
            str_replace("\n", ' ', (string) $s['note']),
        ]);
    }
    rewind($out);
    return stream_get_contents($out);
}

// ─────────────────────────── Plan limits (Solo) ───────────────────────────
//
// The free Solo plan is advertised as "1 user, 7 days of history, no screenshots".
// Advertising a limit that nothing enforces is a promise you cannot keep, so these
// are the three places it is actually applied:
//   • org_policy()  — the agent is told not to capture (and webhooks.php refuses
//                     an upload anyway, since a modified agent can post regardless)
//   • period_ctx()  — every report, export and share link inherits one clamp
//   • dash_team     — the invite path refuses a second seat
// Everything else is unrestricted: features are not gated by tier on the paid plans.

/** Limits for an organization's plan. history_days 0 = unlimited. */
function plan_limits(int $orgId): array
{
    static $cache = [];
    if (isset($cache[$orgId])) {
        return $cache[$orgId];
    }
    $o = db_one('SELECT plan_type FROM organizations WHERE id = ?', [$orgId]) ?: [];
    $solo = ($o['plan_type'] ?? '') === 'solo';
    return $cache[$orgId] = [
        'solo'         => $solo,
        'history_days' => $solo ? 7 : 0,
        'screenshots'  => !$solo,
        'max_seats'    => $solo ? 1 : 0,
    ];
}

/** The admin-controlled monitoring policy for an org (normalized types). */
function org_policy(int $orgId): array
{
    $o = db_one('SELECT screenshot_interval_min, screenshot_blur, idle_threshold_min,
                        sync_interval_s, track_screenshots, track_windows, track_processes
                 FROM organizations WHERE id = ?', [$orgId]) ?: [];
    $limits = plan_limits($orgId);
    return [
        'screenshot_interval_min' => (int) ($o['screenshot_interval_min'] ?? 10),
        'screenshot_blur'         => (bool) ($o['screenshot_blur'] ?? 0),
        'idle_threshold_min'      => (int) ($o['idle_threshold_min'] ?? 15),
        'sync_interval_s'         => (int) ($o['sync_interval_s'] ?? 60),
        // Plan limit overrides the admin toggle — it can only ever turn capture OFF.
        'track_screenshots'       => (bool) ($o['track_screenshots'] ?? 1) && $limits['screenshots'],
        'track_windows'           => (bool) ($o['track_windows'] ?? 1),
        'track_processes'         => (bool) ($o['track_processes'] ?? 1),
    ];
}

/** Map of user id => user row for an org. */
function users_by_id(int $orgId): array
{
    $map = [];
    foreach (db_all('SELECT * FROM users WHERE org_id = ? AND role <> "super_admin"', [$orgId]) as $u) {
        $map[$u['id']] = $u;
    }
    return $map;
}
