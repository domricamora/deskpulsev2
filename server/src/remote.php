<?php
/**
 * Remote desktop control.
 *
 * An admin opens a control session against a worker's device. The desktop agent
 * polls for pending sessions, then streams JPEG frames of the primary screen and
 * applies input commands the admin queues. All auto-expiry is enforced server-side
 * (remote_gc()); the server never trusts the agent to end a session.
 *
 * Agent endpoints (/webhooks/remote/*) authenticate with verify_webhook().
 * Admin endpoints (/app/remote/*) require the `remote` capability (super admins +
 * company/IT admins). Super admins reach any device; other admins are scoped to
 * their own organization via remote_scope_or_deny().
 */

const REMOTE_AGENT_TIMEOUT_S   = 15;   // active session with no frame for this long → agent_gone
const REMOTE_IDLE_MAX_S        = 600;  // active session with no admin input for this long → expired
const REMOTE_PENDING_TIMEOUT_S = 30;   // pending session never picked up by the agent → expired

/** Auto-end stale sessions. Called at the top of every endpoint (UTC throughout). */
function remote_gc(PDO $pdo): void
{
    $pdo->exec("UPDATE remote_sessions SET status='ended', ended_at=UTC_TIMESTAMP(), end_reason='agent_gone'
                WHERE status='active' AND last_frame_at IS NOT NULL
                  AND last_frame_at < UTC_TIMESTAMP() - INTERVAL " . REMOTE_AGENT_TIMEOUT_S . " SECOND");
    $pdo->exec("UPDATE remote_sessions SET status='ended', ended_at=UTC_TIMESTAMP(), end_reason='expired'
                WHERE status='active' AND last_input_at IS NOT NULL
                  AND last_input_at < UTC_TIMESTAMP() - INTERVAL " . REMOTE_IDLE_MAX_S . " SECOND");
    $pdo->exec("UPDATE remote_sessions SET status='ended', ended_at=UTC_TIMESTAMP(), end_reason='expired'
                WHERE status='pending'
                  AND started_at < UTC_TIMESTAMP() - INTERVAL " . REMOTE_PENDING_TIMEOUT_S . " SECOND");
}

/** Confirm the caller may control this target. Super admins are cross-org; every
 *  other `remote`-capable admin is confined to their own organization. A mismatch
 *  is reported as "not found" so cross-org devices aren't even revealed. */
function remote_scope_or_deny(array $u, ?int $targetOrgId): void
{
    if (!is_super($u) && (int) $targetOrgId !== (int) $u['org_id']) {
        abort(404, 'device not found');
    }
}

// ─────────────────────────── Agent-side (HMAC) ───────────────────────────

/** GET /webhooks/remote/poll — the agent asks whether it should stream. Activates
 *  a pending session for this device and returns its stream parameters. */
function wh_remote_poll(): void
{
    remote_gc(db());
    $device = verify_webhook();
    $sess = db_one(
        "SELECT * FROM remote_sessions WHERE device_id = ? AND status IN ('pending','active')
         ORDER BY id DESC LIMIT 1",
        [$device['id']]
    );
    if (!$sess) {
        json_out(['session' => null]);
    }
    if ($sess['status'] === 'pending') {
        db_exec("UPDATE remote_sessions SET status='active', activated_at=UTC_TIMESTAMP(),
                 last_frame_at=UTC_TIMESTAMP() WHERE id = ?", [$sess['id']]);
    }
    json_out(['session' => [
        'id'        => (int) $sess['id'],
        'token'     => $sess['token'],
        'fps'       => 3,
        'max_width' => 1280,
        'quality'   => 55,
    ]]);
}

/** POST /webhooks/remote/{id}/frame — raw JPEG bytes as the body (metadata in the
 *  query: ?w=&h=). Returns queued input commands and the session status. */
function wh_remote_frame(array $p): void
{
    remote_gc(db());
    $device = verify_webhook();
    $id = (int) $p['id'];
    $sess = db_one('SELECT * FROM remote_sessions WHERE id = ?', [$id]);
    if (!$sess || (int) $sess['device_id'] !== (int) $device['id'] || $sess['status'] !== 'active') {
        json_out(['status' => 'ended']);
    }

    $bytes = raw_body();
    if ($bytes !== '') {
        $dest = upload_path('remote/' . $id . '.jpg');
        @mkdir(dirname($dest), 0775, true);
        file_put_contents($dest, $bytes);
    }

    // On the first frame, record the real screen resolution (used to map the
    // admin's normalized input coordinates back to pixels on the worker's screen).
    if ((int) $sess['frame_seq'] === 0 && isset($_GET['w'], $_GET['h'])) {
        db_exec("UPDATE remote_sessions SET frame_seq = frame_seq + 1, last_frame_at = UTC_TIMESTAMP(),
                 screen_w = ?, screen_h = ? WHERE id = ?",
            [(int) $_GET['w'], (int) $_GET['h'], $id]);
    } else {
        db_exec("UPDATE remote_sessions SET frame_seq = frame_seq + 1, last_frame_at = UTC_TIMESTAMP()
                 WHERE id = ?", [$id]);
    }

    // Drain any queued input events for this session (decode → return → delete).
    $rows = db_all('SELECT id, payload FROM remote_input_events WHERE session_id = ? ORDER BY id', [$id]);
    $cmds = [];
    if ($rows) {
        $ids = [];
        foreach ($rows as $r) {
            $ids[] = (int) $r['id'];
            $decoded = json_decode($r['payload'], true);
            if (is_array($decoded)) {
                $cmds[] = $decoded;
            }
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        db_exec("DELETE FROM remote_input_events WHERE id IN ($in)", $ids);
    }
    json_out(['status' => 'active', 'commands' => $cmds]);
}

/** POST /webhooks/remote/{id}/end — the agent reports the stream has stopped. */
function wh_remote_end(array $p): void
{
    remote_gc(db());
    $device = verify_webhook();
    $id = (int) $p['id'];
    db_exec("UPDATE remote_sessions SET status='ended', ended_at=UTC_TIMESTAMP(), end_reason='agent_gone'
             WHERE id = ? AND device_id = ? AND status <> 'ended'", [$id, $device['id']]);
    json_out(['ok' => true]);
}

// ─────────────────────────── Admin-side (super only) ───────────────────────────

/** GET /app/remote/{device} — the control console for one device. */
function dash_remote_view(array $p): void
{
    $u = require_cap('remote');
    remote_gc(db());
    $deviceId = (int) $p['device'];
    $device = db_one('SELECT * FROM devices WHERE id = ?', [$deviceId]);
    if (!$device) {
        abort(404, 'device not found');
    }
    $worker = db_one('SELECT u.*, o.name AS org_name FROM users u
                      JOIN organizations o ON o.id = u.org_id WHERE u.id = ?', [$device['user_id']]);
    remote_scope_or_deny($u, $worker ? (int) $worker['org_id'] : null);
    // nav_context() renders the correct sidebar per role (platform for super admins,
    // the org nav for company/IT admins) instead of hardcoding the platform shell.
    view('dashboard/remote', array_merge(nav_context($u), [
        'title' => 'Remote control', 'active' => 'devices',
        'device' => $device, 'worker' => $worker,
    ]));
}

/** POST /app/remote/{device}/start — open a pending control session. */
function dash_remote_start(array $p): void
{
    $u = require_cap('remote');
    check_csrf();
    remote_gc(db());
    $deviceId = (int) $p['device'];
    $device = db_one('SELECT * FROM devices WHERE id = ?', [$deviceId]);
    if (!$device) {
        abort(404, 'device not found');
    }
    $worker = db_one('SELECT id, org_id FROM users WHERE id = ?', [$device['user_id']]);
    if (!$worker) {
        abort(404, 'worker not found');
    }
    remote_scope_or_deny($u, (int) $worker['org_id']);
    // Single active session per device: end any existing pending/active one first.
    db_exec("UPDATE remote_sessions SET status='ended', ended_at=UTC_TIMESTAMP(), end_reason='admin'
             WHERE device_id = ? AND status IN ('pending','active')", [$deviceId]);
    $token = bin2hex(random_bytes(24));
    $id = db_exec(
        "INSERT INTO remote_sessions (device_id, user_id, admin_user_id, org_id, token, status)
         VALUES (?, ?, ?, ?, ?, 'pending')",
        [$deviceId, (int) $device['user_id'], (int) $u['id'], (int) $worker['org_id'], $token]
    );
    json_out(['session_id' => $id, 'token' => $token]);
}

/** POST /app/remote/{id}/stop — the admin ends the session. */
function dash_remote_stop(array $p): void
{
    $u = require_cap('remote');
    check_csrf();
    $id = (int) $p['id'];
    $s = db_one('SELECT org_id FROM remote_sessions WHERE id = ?', [$id]);
    if ($s) {
        remote_scope_or_deny($u, (int) $s['org_id']);
    }
    db_exec("UPDATE remote_sessions SET status='ended', ended_at=UTC_TIMESTAMP(), end_reason='admin'
             WHERE id = ? AND status <> 'ended'", [$id]);
    @unlink(upload_path('remote/' . $id . '.jpg'));
    json_out(['ok' => true]);
}

/** GET /app/remote/{id}/status — poll session state for the console UI. */
function dash_remote_status(array $p): void
{
    $u = require_cap('remote');
    remote_gc(db());
    $id = (int) $p['id'];
    $s = db_one('SELECT * FROM remote_sessions WHERE id = ?', [$id]);
    if (!$s) {
        json_out(['status' => 'ended', 'seq' => 0, 'screen_w' => null,
                  'screen_h' => null, 'last_frame_age' => null]);
    }
    remote_scope_or_deny($u, (int) $s['org_id']);
    $age = null;
    if ($s['last_frame_at']) {
        $age = (int) (db_one('SELECT TIMESTAMPDIFF(SECOND, ?, UTC_TIMESTAMP()) a',
            [$s['last_frame_at']])['a'] ?? 0);
        $age = max(0, $age);
    }
    json_out([
        'status'         => $s['status'],
        'seq'            => (int) $s['frame_seq'],
        'screen_w'       => $s['screen_w'] !== null ? (int) $s['screen_w'] : null,
        'screen_h'       => $s['screen_h'] !== null ? (int) $s['screen_h'] : null,
        'last_frame_age' => $age,
    ]);
}

/** GET /app/remote/{id}/frame — the latest JPEG frame (204 if none yet). */
function dash_remote_frame(array $p): void
{
    $u = require_cap('remote');
    $id = (int) $p['id'];
    $s = db_one('SELECT org_id FROM remote_sessions WHERE id = ?', [$id]);
    if (!$s) {
        http_response_code(204);
        exit;
    }
    remote_scope_or_deny($u, (int) $s['org_id']);
    $path = upload_path('remote/' . $id . '.jpg');
    if (!is_file($path)) {
        http_response_code(204);
        exit;
    }
    header('Content-Type: image/jpeg');
    header('Cache-Control: no-store');
    readfile($path);
    exit;
}

/** POST /app/remote/{id}/input — queue a batch of input events for the agent.
 *  CSRF is a POST field (_csrf); the events travel as a JSON string in `payload`
 *  so check_csrf() (which reads $_POST) works unchanged. */
function dash_remote_input(array $p): void
{
    $u = require_cap('remote');
    check_csrf();
    remote_gc(db());
    $id = (int) $p['id'];
    $s = db_one('SELECT id, status, org_id FROM remote_sessions WHERE id = ?', [$id]);
    if (!$s || $s['status'] !== 'active') {
        json_out(['ok' => false, 'status' => $s['status'] ?? 'ended', 'queued' => 0]);
    }
    remote_scope_or_deny($u, (int) $s['org_id']);
    $payload = json_decode($_POST['payload'] ?? '', true);
    $events = (is_array($payload) && isset($payload['events']) && is_array($payload['events']))
        ? $payload['events'] : [];
    $n = 0;
    foreach ($events as $ev) {
        if (!is_array($ev)) {
            continue;
        }
        db_exec('INSERT INTO remote_input_events (session_id, payload) VALUES (?, ?)',
            [$id, json_encode($ev)]);
        $n++;
    }
    if ($n > 0) {
        db_exec('UPDATE remote_sessions SET last_input_at = UTC_TIMESTAMP() WHERE id = ?', [$id]);
    }
    json_out(['ok' => true, 'queued' => $n]);
}
