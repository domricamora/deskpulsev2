<?php
/** Webhook ingest endpoints — the Python desktop agent ships all monitoring
 *  data here. Every handler except /webhooks/auth requires a valid HMAC
 *  signature (see verify_webhook in auth.php). */

/** Parse an ISO-8601 timestamp into a MySQL DATETIME string (UTC); now() if blank. */
function wh_ts($value): string
{
    if (!$value) {
        return gmdate('Y-m-d H:i:s');
    }
    $ts = strtotime($value);
    return $ts ? gmdate('Y-m-d H:i:s', $ts) : gmdate('Y-m-d H:i:s');
}

/** Fetch a session owned by the device's user, else 404. */
function wh_owned_session(array $device, $sessionId): array
{
    $sess = db_one('SELECT * FROM sessions WHERE id = ?', [$sessionId]);
    if (!$sess || (int) $sess['user_id'] !== (int) $device['user_id']) {
        abort(404, 'session not found');
    }
    // Heartbeat: record that the agent is alive so it isn't auto-closed as stale.
    if ($sess['ended_at'] === null) {
        db_exec('UPDATE sessions SET last_seen_at = UTC_TIMESTAMP() WHERE id = ?', [$sess['id']]);
    }
    return $sess;
}

/** POST /webhooks/auth — register a device using email+password. */
function wh_auth(): void
{
    $data = json_body();
    $email = strtolower($data['email'] ?? '');
    $user = db_one('SELECT * FROM users WHERE email = ?', [$email]);
    if (!$user || !password_verify($data['password'] ?? '', $user['password_hash'])) {
        abort(401, 'invalid credentials');
    }
    // Platform operators are not tracked — they cannot run the desktop agent.
    if (($user['role'] ?? '') === 'super_admin') {
        abort(403, 'platform operators cannot run the tracker');
    }
    $secret = bin2hex(random_bytes(32));
    $devId = db_exec(
        'INSERT INTO devices (user_id, name, secret) VALUES (?, ?, ?)',
        [$user['id'], substr($data['device_name'] ?? 'Desktop', 0, 160), $secret]
    );
    json_out([
        'device_id' => $devId,
        'secret'    => $secret,
        'user'      => ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email']],
    ]);
}

/** GET /webhooks/me — validate the device/session; returns the signed-in user.
 *  401 if the device was revoked or the user/account no longer exists. */
function wh_me(): void
{
    $device = verify_webhook();   // 401s on unknown device / bad signature
    $u = db_one('SELECT id, name, email, role, org_id, work_start, work_end, work_days FROM users WHERE id = ?',
        [$device['user_id']]);
    if (!$u) {
        abort(401, 'account no longer exists');
    }
    $org = db_one('SELECT name, logo_path FROM organizations WHERE id = ?', [$u['org_id']]) ?: [];
    // Work schedule so the desktop agent can auto-start/stop tracking within the
    // worker's scheduled hours. work_days = ISO weekdays (1=Mon..7=Sun).
    $days = array_values(array_filter(array_map('intval', explode(',', (string) ($u['work_days'] ?? '')))));
    // logo_url is relative to the agent's configured server_url (which already includes
    // the app base path); the agent prepends it. Null when no logo is set.
    json_out([
        'user'      => ['id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email'],
                        'role' => $u['role'], 'org_id' => (int) $u['org_id']],
        'device_id' => $device['id'],
        'org'       => [
            'name'     => $org['name'] ?? '',
            'logo_url' => !empty($org['logo_path']) ? '/uploads/' . $org['logo_path'] : null,
        ],
        'schedule'  => [
            'work_start' => $u['work_start'] ?: null,
            'work_end'   => $u['work_end'] ?: null,
            'work_days'  => $days,
        ],
    ]);
}

/** GET /webhooks/clients — client/company list for the device's org (agent bootstrap). */
function wh_clients(): void
{
    $device = verify_webhook();
    $user = db_one('SELECT org_id FROM users WHERE id = ?', [$device['user_id']]);
    $rows = db_all(
        'SELECT id, name FROM clients WHERE org_id = ? AND archived = 0 ORDER BY name',
        [$user['org_id']]
    );
    json_out($rows);
}

/** GET /webhooks/policy — the org's admin-controlled monitoring policy. */
function wh_policy(): void
{
    $device = verify_webhook();
    $user = db_one('SELECT org_id FROM users WHERE id = ?', [$device['user_id']]);
    json_out(org_policy((int) $user['org_id']));
}

/** POST /webhooks/session — open a work session (optionally on a task). */
function wh_session_start(): void
{
    $device = verify_webhook();
    $data = json_body();
    // A reconnecting agent (after a crash/close) starts a fresh session; close any
    // of this user's stale open sessions first so they don't linger forever.
    db_exec("UPDATE sessions SET ended_at = COALESCE(last_seen_at, started_at)
             WHERE user_id = ? AND ended_at IS NULL", [$device['user_id']]);
    $taskId = wh_validate_task($device, $data['task_id'] ?? null);
    $clientId = wh_validate_client($device, $data['client_id'] ?? null);
    $sid = db_exec(
        'INSERT INTO sessions (user_id, device_id, client_id, task_id, started_at, last_seen_at, source, approval_status)
         VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), "agent", "approved")',
        [$device['user_id'], $device['id'], $clientId, $taskId, wh_ts($data['started_at'] ?? null)]
    );
    json_out(['session_id' => $sid]);
}

/** POST /webhooks/session/{id}/task — re-tag an open session with a task. */
function wh_session_set_task(array $p): void
{
    $device = verify_webhook();
    $data = json_body();
    $taskId = wh_validate_task($device, $data['task_id'] ?? null);
    db_exec('UPDATE sessions SET task_id = ? WHERE id = ? AND user_id = ? AND ended_at IS NULL',
        [$taskId, (int) $p['id'], $device['user_id']]);
    json_out(['ok' => true, 'task_id' => $taskId]);
}

/** Ensure a client id belongs to the device user's org; return it or null. */
function wh_validate_client(array $device, $clientId)
{
    if (!$clientId) {
        return null;
    }
    $user = db_one('SELECT org_id FROM users WHERE id = ?', [$device['user_id']]);
    $c = db_one('SELECT id FROM clients WHERE id = ? AND org_id = ?', [$clientId, $user['org_id']]);
    return $c ? (int) $c['id'] : null;
}

/** Ensure a task id belongs to the device's user; return it or null. */
function wh_validate_task(array $device, $taskId)
{
    if (!$taskId) {
        return null;
    }
    $t = db_one('SELECT id FROM tasks WHERE id = ? AND user_id = ?', [$taskId, $device['user_id']]);
    return $t ? (int) $t['id'] : null;
}

/** GET /webhooks/tasks — the device user's open tasks. */
function wh_tasks_list(): void
{
    $device = verify_webhook();
    $rows = db_all(
        'SELECT id, title, client_id, status FROM tasks
         WHERE user_id = ? AND status = "open" ORDER BY created_at DESC',
        [$device['user_id']]
    );
    json_out($rows);
}

/** POST /webhooks/tasks — create a task. Body: {title, client_id?}. */
function wh_task_create(): void
{
    $device = verify_webhook();
    $data = json_body();
    $title = trim($data['title'] ?? '');
    if ($title === '') {
        abort(400, 'title required');
    }
    $user = db_one('SELECT org_id FROM users WHERE id = ?', [$device['user_id']]);
    $id = db_exec(
        'INSERT INTO tasks (org_id, user_id, client_id, title) VALUES (?, ?, ?, ?)',
        [$user['org_id'], $device['user_id'], wh_validate_client($device, $data['client_id'] ?? null),
         substr($title, 0, 240)]
    );
    json_out(['task_id' => $id, 'title' => $title]);
}

/** DELETE /webhooks/tasks/{id} — remove one of the user's tasks. */
function wh_task_delete(array $p): void
{
    $device = verify_webhook();
    db_exec('DELETE FROM tasks WHERE id = ? AND user_id = ?', [$p['id'], $device['user_id']]);
    json_out(['ok' => true]);
}

/** PATCH /webhooks/session/{id} — close a session with totals. */
function wh_session_stop(array $p): void
{
    $device = verify_webhook();
    $sess = wh_owned_session($device, $p['id']);
    $data = json_body();
    db_exec(
        'UPDATE sessions SET ended_at = ?, active_s = ?, inactive_s = ? WHERE id = ?',
        [wh_ts($data['ended_at'] ?? null),
         (int) ($data['active_s'] ?? $sess['active_s']),
         (int) ($data['inactive_s'] ?? $sess['inactive_s']),
         $sess['id']]
    );
    // Now that the session is closed with final totals, split its activity into
    // regular vs overtime (overtime waits for HR before it can be paid).
    recompute_overtime((int) $sess['user_id'], date('Y-m-d 00:00:00', strtotime($sess['started_at']) - 86400));
    json_out(['ok' => true]);
}

/** POST /webhooks/session/{id}/activity — batch of activity samples. */
function wh_activity(array $p): void
{
    $device = verify_webhook();
    $sess = wh_owned_session($device, $p['id']);
    $body = json_body();
    foreach ($body['samples'] ?? [] as $s) {
        db_exec(
            'INSERT INTO activity_samples (session_id, ts, keyboard_count, mouse_count, activity_pct)
             VALUES (?, ?, ?, ?, ?)',
            [$sess['id'], wh_ts($s['ts'] ?? null),
             (int) ($s['keyboard'] ?? 0), (int) ($s['mouse'] ?? 0), (int) ($s['pct'] ?? 0)]
        );
    }
    // Keep the running active/inactive totals fresh so the activity % reflects a
    // live session in realtime. Guard on ended_at IS NULL so a replayed (stale)
    // offline-queued batch can never clobber the finalized totals set at stop.
    if (array_key_exists('active_s', $body) && $sess['ended_at'] === null) {
        db_exec(
            'UPDATE sessions SET active_s = ?, inactive_s = ? WHERE id = ? AND ended_at IS NULL',
            [(int) $body['active_s'], (int) ($body['inactive_s'] ?? 0), $sess['id']]
        );
    }
    json_out(['ok' => true]);
}

/** POST /webhooks/session/{id}/windows — window/app events + running tasks. */
function wh_windows(array $p): void
{
    $device = verify_webhook();
    $sess = wh_owned_session($device, $p['id']);
    $body = json_body();
    foreach ($body['windows'] ?? [] as $w) {
        db_exec(
            'INSERT INTO window_events (session_id, ts, app_name, window_title, focus_seconds)
             VALUES (?, ?, ?, ?, ?)',
            [$sess['id'], wh_ts($w['ts'] ?? null),
             substr($w['app'] ?? '', 0, 160), substr($w['title'] ?? '', 0, 400),
             (int) ($w['focus_seconds'] ?? 0)]
        );
    }
    foreach ($body['processes'] ?? [] as $proc) {
        db_exec(
            'INSERT INTO process_snapshots (session_id, ts, app_name, pid) VALUES (?, ?, ?, ?)',
            [$sess['id'], wh_ts($proc['ts'] ?? null),
             substr($proc['app'] ?? '', 0, 200), (int) ($proc['pid'] ?? 0)]
        );
    }
    json_out(['ok' => true]);
}

/** POST /webhooks/session/{id}/idle — inactive periods (>= threshold). */
function wh_idle(array $p): void
{
    $device = verify_webhook();
    $sess = wh_owned_session($device, $p['id']);
    foreach (json_body()['periods'] ?? [] as $period) {
        $start = wh_ts($period['start'] ?? null);
        $end = wh_ts($period['end'] ?? null);
        $dur = max(0, strtotime($end) - strtotime($start));
        db_exec(
            'INSERT INTO idle_periods (session_id, start_ts, end_ts, duration_s) VALUES (?, ?, ?, ?)',
            [$sess['id'], $start, $end, $dur]
        );
    }
    json_out(['ok' => true]);
}

/**
 * POST /webhooks/session/{id}/screenshot — raw image bytes as the request body
 * (so the HMAC over the raw body stays consistent with every other endpoint;
 * multipart would empty php://input and break the signature). Metadata travels
 * in the query string: ?ext=png&ts=...&blurred=0
 */
function wh_screenshot(array $p): void
{
    $device = verify_webhook();
    $sess = wh_owned_session($device, $p['id']);

    // The free Solo plan does not include screenshots. org_policy() already tells the
    // agent not to capture, but that is a request, not a control — a modified or stale
    // agent posts anyway. Refusing at ingest is the actual enforcement. 402 rather than
    // 403: the request is well-formed and authenticated, the plan just doesn't cover it.
    if (!plan_limits((int) $device['org_id'])['screenshots']) {
        abort(402, 'screenshots are not included on this plan');
    }

    $bytes = raw_body();
    if ($bytes === '') {
        abort(400, 'missing image');
    }
    $ext = strtolower($_GET['ext'] ?? 'png');
    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) {
        abort(400, 'unsupported image type');
    }
    $rel = $sess['user_id'] . '/' . $sess['id'] . '/' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = upload_path($rel);
    @mkdir(dirname($dest), 0775, true);
    file_put_contents($dest, $bytes);

    // Optional monitor index for multi-monitor setups (null/0 for single screen).
    $monitor = isset($_GET['monitor']) ? max(0, (int) $_GET['monitor']) : null;
    $sid = db_exec(
        'INSERT INTO screenshots (session_id, ts, file_path, blurred, monitor) VALUES (?, ?, ?, ?, ?)',
        [$sess['id'], wh_ts($_GET['ts'] ?? null), $rel,
         in_array($_GET['blurred'] ?? '', ['1', 'true', 'True'], true) ? 1 : 0,
         $monitor]
    );
    // Opportunistically enforce the retention window without stalling the upload.
    if (random_int(1, 50) === 1) {
        purge_old_screenshots(100);
    }
    json_out(['screenshot_id' => $sid]);
}
