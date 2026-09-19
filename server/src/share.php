<?php
/** Public, read-only summary pages reachable via a share token (no login). */

function share_view(array $p): void
{
    $link = db_one('SELECT * FROM share_links WHERE token = ?', [$p['token']]);
    if (!$link || !share_link_active($link)) {
        abort(404, 'This share link is invalid or has expired.');
    }

    [$start, $end, $period, $rangeLabel] = share_resolve_range($link);

    // Resolve which users this link covers.
    $userIds = share_target_user_ids($link);
    $usersById = users_by_id((int) $link['org_id']);
    $sessions = sessions_for_users($userIds, $start, $end);

    $summary = summarize($sessions);
    $series = daily_series($sessions, $start, $end, report_tz((int) $link['org_id']));
    $apps = top_apps(array_column($sessions, 'id'));
    $taskTimes = task_time_rows($userIds, $start, $end);

    $orgName = db_one('SELECT name FROM organizations WHERE id = ?', [$link['org_id']])['name'] ?? '';

    // A share URL is a capability token: anyone holding it can read the summary. Keep it
    // out of every index. robots.txt Disallow is not enough on its own — a crawler that
    // is told not to fetch the page never reads a noindex meta tag, so anything already
    // indexed would stay indexed. A response header is always honoured.
    header('X-Robots-Tag: noindex, nofollow, noarchive');

    // Public pages never expose screenshots (privacy) — stats & graphs only.
    view('share/summary', [
        'title' => ($link['label'] ?: 'DeskPulse summary'),
        'link' => $link, 'period' => $period, 'range_label' => $rangeLabel,
        'from' => $_GET['from'] ?? '', 'to' => $_GET['to'] ?? '', 'week' => $_GET['week'] ?? '',
        'summary' => $summary, 'series' => $series, 'apps' => $apps, 'task_times' => $taskTimes,
        'org_name' => $orgName, 'token' => $link['token'],
        'multi_user' => $link['scope'] !== 'user',
    ], 'layout_public');
}

/** Resolve the date window for a share page from ?period, ?from/?to, or ?week.
 *  Returns [startUTC, endUTC, mode, label]. */
function share_resolve_range(array $link): array
{
    // A share page has no signed-in user, so the window is cut in the *owning
    // org's* reporting timezone (not the server's) to match what that org sees
    // on its own dashboard.
    $orgId = (int) $link['org_id'];
    $tz    = report_tz($orgId);
    $from = $_GET['from'] ?? '';
    $to = $_GET['to'] ?? '';
    $week = $_GET['week'] ?? '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        if (strcmp($from, $to) > 0) {
            [$from, $to] = [$to, $from];
        }
        $start = local_to_utc($from . ' 00:00:00', $tz);
        $end   = local_to_utc(date_add_days($to, 1) . ' 00:00:00', $tz);
        return [$start, $end, 'range', range_label($from, $to)];
    }
    if (preg_match('/^(\d{4})-W(\d{2})$/', $week, $m)) {
        $d = new DateTime();
        $d->setISODate((int) $m[1], (int) $m[2]);
        $startDate = $d->format('Y-m-d');
        $endDate   = date_add_days($startDate, 7);
        return [local_to_utc($startDate . ' 00:00:00', $tz), local_to_utc($endDate . ' 00:00:00', $tz),
                'week', 'Week ' . $m[2] . ', ' . $m[1]];
    }
    $period = $_GET['period'] ?? $link['period_default'];
    if (!in_array($period, ['day', 'week', 'month'], true)) {
        $period = 'week';
    }
    $anchorTs = null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date'] ?? ''))) {
        $anchorTs = strtotime($_GET['date'] . ' 12:00:00');
    }
    [$start, $end] = period_range($period, $anchorTs, $orgId);
    $cfg = report_org_cfg($orgId);
    [$sd, $ed] = period_bounds($period, $anchorTs !== null ? date('Y-m-d', $anchorTs)
        : (new DateTime('now', new DateTimeZone($tz)))->format('Y-m-d'), $cfg);
    return [$start, $end, $period, range_label($sd, date_add_days($ed, -1))];
}

/** Auto-assign a personal public share link to every org user that lacks one. */
function ensure_personal_links(int $orgId): void
{
    $missing = db_all(
        'SELECT u.id, u.name FROM users u
         LEFT JOIN share_links sl
           ON sl.scope = "user" AND sl.target_id = u.id AND sl.org_id = u.org_id AND sl.revoked = 0
         WHERE u.org_id = ? AND sl.id IS NULL',
        [$orgId]
    );
    foreach ($missing as $m) {
        db_exec(
            'INSERT INTO share_links (org_id, scope, target_id, token, label, period_default)
             VALUES (?, "user", ?, ?, ?, "day")',
            [$orgId, $m['id'], random_token(18), $m['name'] . ' — personal']
        );
    }
}

/** Map of user_id => active personal share token for an org. */
function personal_links_map(int $orgId): array
{
    $map = [];
    foreach (db_all(
        'SELECT target_id, token FROM share_links
         WHERE org_id = ? AND scope = "user" AND revoked = 0', [$orgId]) as $r) {
        $map[$r['target_id']] = $r['token'];
    }
    return $map;
}

function share_link_active(array $link): bool
{
    if ((int) $link['revoked'] === 1) {
        return false;
    }
    if (!empty($link['expires_at']) && strtotime($link['expires_at']) < time()) {
        return false;
    }
    return true;
}

function share_target_user_ids(array $link): array
{
    if ($link['scope'] === 'org') {
        return org_user_ids((int) $link['org_id']);
    }
    if ($link['scope'] === 'team') {
        $rows = db_all('SELECT user_id FROM team_members WHERE team_id = ?', [$link['target_id']]);
        return array_map('intval', array_column($rows, 'user_id'));
    }
    return [(int) $link['target_id']];
}
