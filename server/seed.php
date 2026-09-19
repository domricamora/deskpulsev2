<?php
/**
 * DeskPulse demo seeder. Creates an admin, managers, members, clients, projects,
 * tasks and a week of sample sessions/activity so every dashboard has data.
 *
 * Run from the repo root:
 *     "c:/wamp64/bin/php/php8.2.26/php.exe" server/seed.php
 *
 * Idempotent: if the admin already exists it exits without duplicating data.
 */
$SERVER_DIR = __DIR__;
require_once $SERVER_DIR . '/src/helpers.php';

if (!isset($GLOBALS['CONFIG'])) {
    $cfgFile = file_exists($SERVER_DIR . '/config.php')
        ? $SERVER_DIR . '/config.php' : $SERVER_DIR . '/config.example.php';
    $GLOBALS['CONFIG'] = require $cfgFile;
}

require_once $SERVER_DIR . '/src/db.php';

// Admin credentials come from the environment so no real secret lives in the repo.
// Override with DESKPULSE_ADMIN_EMAIL / DESKPULSE_ADMIN_PASS; otherwise these
// non-secret demo defaults are used (change the password after first login).
$ADMIN_EMAIL = getenv('DESKPULSE_ADMIN_EMAIL') ?: 'admin@demo.test';
$ADMIN_PASS  = getenv('DESKPULSE_ADMIN_PASS')  ?: 'ChangeMe!123';

if (db_one('SELECT id FROM users WHERE email = ?', [$ADMIN_EMAIL])) {
    fwrite(STDOUT, "Demo data already present (admin {$ADMIN_EMAIL} exists). Nothing to do.\n");
    exit(0);
}

db()->beginTransaction();

// ── Organization ──
$orgId = db_exec('INSERT INTO organizations (name) VALUES (?)', ['DeskPulse Demo Co']);

// ── Users ──
function mk_user($orgId, $name, $email, $pass, $role, $payType, $payRate, $billType, $billRate) {
    return db_exec(
        'INSERT INTO users (org_id, name, email, password_hash, role, pay_type, pay_rate, bill_type, bill_rate, currency)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "USD")',
        [$orgId, $name, $email, password_hash($pass, PASSWORD_DEFAULT), $role,
         $payType, $payRate, $billType, $billRate]
    );
}

$adminId   = mk_user($orgId, 'Nick (Admin)', $ADMIN_EMAIL, $ADMIN_PASS, 'admin', 'monthly', 7000, 'monthly', 12000);
$managerId = mk_user($orgId, 'Maria Manager', 'manager@demo.test', 'Demo12345', 'manager', 'monthly', 4500, 'hourly', 45);
$ava       = mk_user($orgId, 'Ava Reyes',  'ava@demo.test',  'Demo12345', 'member', 'hourly', 22, 'hourly', 40);
$ben       = mk_user($orgId, 'Ben Cruz',   'ben@demo.test',  'Demo12345', 'member', 'hourly', 25, 'hourly', 45);
$carla     = mk_user($orgId, 'Carla Diaz', 'carla@demo.test','Demo12345', 'member', 'monthly', 3200, 'monthly', 5500);
$members = [$ava, $ben, $carla];

// ── Team ──
$teamId = db_exec('INSERT INTO teams (org_id, name) VALUES (?, ?)', [$orgId, 'Remote Squad']);
foreach ([$managerId, $ava, $ben, $carla] as $uid) {
    db_exec('INSERT INTO team_members (team_id, user_id) VALUES (?, ?)', [$teamId, $uid]);
}

// ── Clients + contracts ──
$acme  = db_exec('INSERT INTO clients (org_id, name, contact_email, notes) VALUES (?, ?, ?, ?)',
    [$orgId, 'Acme Corp', 'ops@acme.test', 'Flagship client']);
$globex = db_exec('INSERT INTO clients (org_id, name, contact_email) VALUES (?, ?, ?)',
    [$orgId, 'Globex LLC', 'pm@globex.test']);
db_exec('INSERT INTO contracts (org_id, client_id, title, bill_rate, currency, status, start_date)
         VALUES (?, ?, ?, ?, "USD", "active", ?)',
    [$orgId, $acme, 'Acme Support Retainer', 50, date('Y-m-01')]);
db_exec('INSERT INTO contracts (org_id, client_id, title, bill_rate, currency, status, start_date)
         VALUES (?, ?, ?, ?, "USD", "active", ?)',
    [$orgId, $globex, 'Globex Web Build', 60, date('Y-m-01')]);

// Work is tracked directly against clients (Projects retired).
$clients = [$acme, $globex];

// ── Tasks ──
$taskByUser = [];
$taskTitles = ['Triage support tickets', 'Build landing page', 'Weekly client report'];
foreach ($members as $i => $uid) {
    $taskByUser[$uid] = db_exec('INSERT INTO tasks (org_id, user_id, client_id, title) VALUES (?, ?, ?, ?)',
        [$orgId, $uid, $clients[$i % 2], $taskTitles[$i % 3]]);
}

// ── A week of sample sessions with activity / windows / idle ──
$apps = [
    ['chrome.exe', 'Acme Helpdesk — Google Chrome'],
    ['Code.exe', 'project — Visual Studio Code'],
    ['slack.exe', 'Slack | #remote-squad'],
    ['zoom.exe', 'Zoom Meeting'],
];
$daysBack = 6;
for ($d = $daysBack; $d >= 0; $d--) {
    foreach ($members as $i => $uid) {
        $dayStart = strtotime("-$d days", strtotime(date('Y-m-d') . ' 09:00:00'));
        if (date('N', $dayStart) >= 6) { continue; }   // skip weekends
        $activeS = 3600 * (5 + ($i + $d) % 3);          // 5–7h
        $inactiveS = 600 * (1 + ($i + $d) % 4);         // 10–40m
        $ended = $dayStart + $activeS + $inactiveS;
        $sid = db_exec(
            'INSERT INTO sessions (user_id, client_id, task_id, started_at, ended_at, active_s, inactive_s, source, approval_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, "agent", "approved")',
            [$uid, $clients[$i % 2], $taskByUser[$uid] ?? null,
             gmdate('Y-m-d H:i:s', $dayStart), gmdate('Y-m-d H:i:s', $ended), $activeS, $inactiveS]
        );
        // window events
        $t = $dayStart;
        foreach ($apps as $a) {
            $focus = 1800 + 600 * ($i % 3);
            db_exec('INSERT INTO window_events (session_id, ts, app_name, window_title, focus_seconds) VALUES (?, ?, ?, ?, ?)',
                [$sid, gmdate('Y-m-d H:i:s', $t), $a[0], $a[1], $focus]);
            $t += $focus;
        }
        // activity samples (for the per-session line chart)
        for ($s = 0; $s < 8; $s++) {
            db_exec('INSERT INTO activity_samples (session_id, ts, keyboard_count, mouse_count, activity_pct) VALUES (?, ?, ?, ?, ?)',
                [$sid, gmdate('Y-m-d H:i:s', $dayStart + $s * 1800),
                 50 + ($s * 7) % 60, 30 + ($s * 5) % 40, 40 + ($s * 11) % 55]);
        }
        // process snapshot
        foreach (['chrome.exe', 'Code.exe', 'slack.exe', 'explorer.exe'] as $pi => $pn) {
            db_exec('INSERT INTO process_snapshots (session_id, ts, app_name, pid) VALUES (?, ?, ?, ?)',
                [$sid, gmdate('Y-m-d H:i:s', $dayStart), $pn, 1000 + $pi]);
        }
        // one idle period
        db_exec('INSERT INTO idle_periods (session_id, start_ts, end_ts, duration_s) VALUES (?, ?, ?, ?)',
            [$sid, gmdate('Y-m-d H:i:s', $dayStart + 3600), gmdate('Y-m-d H:i:s', $dayStart + 3600 + $inactiveS), $inactiveS]);
    }
}

// ── A pending manual entry (so Approvals has something) ──
db_exec(
    'INSERT INTO sessions (user_id, client_id, started_at, ended_at, active_s, inactive_s, source, note, approval_status)
     VALUES (?, ?, ?, ?, ?, 0, "manual", ?, "pending")',
    [$ava, $acme, gmdate('Y-m-d H:i:s', strtotime('-1 day 14:00')),
     gmdate('Y-m-d H:i:s', strtotime('-1 day 15:30')), 5400, 'Client call I forgot to track']
);

// ── A public share link ──
db_exec('INSERT INTO share_links (org_id, scope, target_id, token, label, period_default) VALUES (?, "team", ?, ?, ?, "week")',
    [$orgId, $teamId, random_token(18), 'Remote Squad — weekly']);

db()->commit();

fwrite(STDOUT, "Seeded DeskPulse demo org.\n");
fwrite(STDOUT, "  Admin:   {$ADMIN_EMAIL} / {$ADMIN_PASS}\n");
fwrite(STDOUT, "  Manager: manager@demo.test / Demo12345\n");
fwrite(STDOUT, "  Members: ava@demo.test, ben@demo.test, carla@demo.test / Demo12345\n");
