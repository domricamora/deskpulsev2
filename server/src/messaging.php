<?php
/**
 * Messaging: custom messages, reminder mail, in-app notices and the email log.
 *
 * Notices are both in-app and (optionally) email, because an emailed-only notice
 * is invisible to anyone who has muted non-transactional mail, and an in-app-only
 * notice is invisible to someone who isn't logging in — which is exactly the
 * person a reminder is aimed at.
 */

// ─────────────────────────── Merge tokens ───────────────────────────

/**
 * Substitute {{token}} placeholders in a message body.
 * Unknown tokens are left alone so a typo is visible rather than silently blank.
 */
function msg_merge(string $body, array $vars): string
{
    return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/i', function ($m) use ($vars) {
        $k = strtolower($m[1]);
        return array_key_exists($k, $vars) ? (string) $vars[$k] : $m[0];
    }, $body);
}

function msg_tokens_for(array $member, array $org, array $extra = []): array
{
    $name = (string) ($member['name'] ?? '');
    return array_merge([
        'name'       => $name,
        'first_name' => trim(explode(' ', $name)[0] ?? $name),
        'email'      => (string) ($member['email'] ?? ''),
        'job_title'  => (string) ($member['job_title'] ?? ''),
        'org'        => (string) ($org['name'] ?? 'DeskPulse'),
        'link'       => rtrim((string) public_base_url(), '/') . url('/app'),
    ], $extra);
}

function msg_token_help(): array
{
    return ['{{name}}' => 'Full name', '{{first_name}}' => 'First name only',
            '{{email}}' => 'Their email address', '{{job_title}}' => 'Job title',
            '{{org}}' => 'Your company name', '{{link}}' => 'Link to their dashboard',
            '{{period}}' => 'Current pay period (reminders only)',
            '{{hours}}' => 'Hours logged this period (reminders only)'];
}

// ─────────────────────────── Reminder registry ───────────────────────────

/**
 * Reminder types. Each resolves its OWN recipient list from a query, so the
 * admin never has to work out who is behind on what — that is the entire point
 * of a reminder as opposed to a broadcast.
 *
 * resolve(array $u, array $ids): array of ['user'=>row, 'vars'=>[…]]
 */
function reminder_types(): array
{
    return [
        'no_time_today' => [
            'label'   => 'No time logged today',
            'desc'    => 'Employees with no tracked session so far today.',
            'subject' => 'Reminder: no time logged today',
            'body'    => "Hi {{first_name}},\n\nWe haven't seen any tracked time from you today. "
                       . "If you're working, please start the DeskPulse tracker so your hours are recorded.\n\n"
                       . "Open your dashboard: {{link}}\n\n— {{org}}",
            'resolve' => 'reminder_resolve_no_time_today',
        ],
        'low_hours_period' => [
            'label'   => 'Below expected hours this pay period',
            'desc'    => 'Employees under their pay-period hour cap (or under 80% of their schedule).',
            'subject' => 'Your hours for {{period}}',
            'body'    => "Hi {{first_name}},\n\nYou've logged {{hours}} so far for {{period}}. "
                       . "Please check your timesheet is complete before the cut-off.\n\n{{link}}\n\n— {{org}}",
            'resolve' => 'reminder_resolve_low_hours',
        ],
        'missing_wise' => [
            'label'   => 'Missing Wise payout details',
            'desc'    => 'Employees who cannot be included in a salary run yet.',
            'subject' => 'We need your payout details',
            'body'    => "Hi {{first_name}},\n\nWe don't have your Wise payout details on file, so you "
                       . "can't be included in the next salary run. Please send them over as soon as you can.\n\n— {{org}}",
            'resolve' => 'reminder_resolve_missing_wise',
        ],
        'no_device' => [
            'label'   => 'Desktop app not installed',
            'desc'    => 'Employees who have never registered a device.',
            'subject' => 'Install the DeskPulse desktop app',
            'body'    => "Hi {{first_name}},\n\nYou haven't installed the DeskPulse desktop app yet. "
                       . "You can download it from your dashboard: {{link}}\n\n— {{org}}",
            'resolve' => 'reminder_resolve_no_device',
        ],
        'pending_leave' => [
            'label'   => 'Leave requests awaiting your review',
            'desc'    => 'Managers and HR with leave requests still pending.',
            'subject' => 'Leave requests are waiting for review',
            'body'    => "Hi {{first_name}},\n\nThere are leave requests waiting for your decision.\n\n{{link}}\n\n— {{org}}",
            'resolve' => 'reminder_resolve_pending_leave',
        ],
    ];
}

function reminder_resolve_no_time_today(array $u, array $ids): array
{
    if (!$ids) {
        return [];
    }
    $cfg = report_org_cfg((int) $u['org_id']);
    $today = (new DateTime('now', new DateTimeZone($cfg['tz'])))->format('Y-m-d');
    [$s, $e] = [local_to_utc($today . ' 00:00:00', $cfg['tz']), local_to_utc(date_add_days($today, 1) . ' 00:00:00', $cfg['tz'])];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $active = array_column(db_all(
        "SELECT DISTINCT user_id FROM sessions WHERE user_id IN ($in) AND started_at >= ? AND started_at < ?",
        array_merge($ids, [$s, $e])), 'user_id');
    $active = array_map('intval', $active);
    $out = [];
    foreach (users_by_id((int) $u['org_id']) as $id => $m) {
        if (in_array((int) $id, $ids, true) && !in_array((int) $id, $active, true)
            && $m['role'] !== 'client_viewer') {
            $out[] = ['user' => $m, 'vars' => []];
        }
    }
    return $out;
}

function reminder_resolve_low_hours(array $u, array $ids): array
{
    $ctx = period_ctx('pay', ['pay'], (int) $u['org_id']);
    $run = compute_pay_run($u, $ctx);
    $out = [];
    foreach ($run['rows'] as $r) {
        $cap = (float) ($r['user']['period_hours_cap'] ?? 0);
        $target = $cap > 0 ? $cap : 0.0;
        if ($target <= 0) {
            continue;                       // no expectation recorded → nothing to chase
        }
        if ($r['hours'] < $target * 0.8) {
            $out[] = ['user' => $r['user'], 'vars' => [
                'period' => $ctx['label'],
                'hours'  => number_format($r['hours'], 2) . ' h',
            ]];
        }
    }
    return $out;
}

function reminder_resolve_missing_wise(array $u, array $ids): array
{
    $accounts = wise_accounts_for((int) $u['org_id']);
    $out = [];
    foreach (users_by_id((int) $u['org_id']) as $id => $m) {
        if (!in_array((int) $id, $ids, true) || $m['role'] === 'client_viewer') {
            continue;
        }
        $a = $accounts[(int) $id] ?? null;
        if (!$a || (!$a['recipient_id'] && !$a['email']) || empty($a['active'])) {
            $out[] = ['user' => $m, 'vars' => []];
        }
    }
    return $out;
}

function reminder_resolve_no_device(array $u, array $ids): array
{
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $have = array_map('intval', array_column(
        db_all("SELECT DISTINCT user_id FROM devices WHERE user_id IN ($in)", $ids), 'user_id'));
    $out = [];
    foreach (users_by_id((int) $u['org_id']) as $id => $m) {
        if (in_array((int) $id, $ids, true) && !in_array((int) $id, $have, true)
            && $m['role'] === 'member') {
            $out[] = ['user' => $m, 'vars' => []];
        }
    }
    return $out;
}

function reminder_resolve_pending_leave(array $u, array $ids): array
{
    $pending = db_one("SELECT COUNT(*) AS n FROM leave_requests WHERE org_id = ? AND status = 'pending'",
        [(int) $u['org_id']]);
    if (!$pending || (int) $pending['n'] === 0) {
        return [];
    }
    $out = [];
    foreach (users_by_id((int) $u['org_id']) as $m) {
        if (can($m, 'leave_approve')) {
            $out[] = ['user' => $m, 'vars' => ['count' => (int) $pending['n']]];
        }
    }
    return $out;
}

// ─────────────────────────── Recipient resolution ───────────────────────────

/**
 * Everyone a message may go to, honouring the sender's visibility scope and
 * excluding client portal logins and unusable/synthetic addresses.
 */
function msg_candidates(array $u): array
{
    $ids = visible_user_ids($u);
    $out = [];
    foreach (users_by_id((int) $u['org_id']) as $id => $m) {
        if (!in_array((int) $id, $ids, true) || $m['role'] === 'client_viewer') {
            continue;
        }
        $out[(int) $id] = $m;
    }
    uasort($out, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
    return $out;
}

/** Resolve an audience selection to a list of member rows. */
function msg_resolve_audience(array $u, string $audience, $ref): array
{
    $all = msg_candidates($u);
    switch ($audience) {
        case 'role':
            return array_values(array_filter($all, fn ($m) => $m['role'] === $ref));
        case 'team':
            $rows = db_all('SELECT user_id FROM team_members WHERE team_id = ?', [(int) $ref]);
            $ids = array_map(fn ($r) => (int) $r['user_id'], $rows);
            return array_values(array_filter($all, fn ($m) => in_array((int) $m['id'], $ids, true)));
        case 'users':
            $ids = array_map('intval', (array) $ref);
            return array_values(array_filter($all, fn ($m) => in_array((int) $m['id'], $ids, true)));
        case 'all':
        default:
            return array_values($all);
    }
}

// ─────────────────────────── Page: Messages ───────────────────────────

function dash_messages(): void
{
    $u = require_cap('messaging');
    $orgId = (int) $u['org_id'];
    $org = db_one('SELECT * FROM organizations WHERE id = ?', [$orgId]) ?: ['name' => 'DeskPulse'];
    $preview = null;

    if (request_method() === 'POST') {
        check_csrf();
        $action = $_POST['action'] ?? '';

        if ($action === 'send' || $action === 'preview') {
            $audience = (string) ($_POST['audience'] ?? 'all');
            $ref = $audience === 'users' ? ($_POST['user_ids'] ?? []) : ($_POST['audience_ref'] ?? '');
            $subject = trim((string) ($_POST['subject'] ?? ''));
            $body = (string) ($_POST['body'] ?? '');
            $reminder = (string) ($_POST['reminder_type'] ?? '');
            $types = reminder_types();

            // A reminder type computes its own recipients; a custom message uses the picker.
            if ($reminder !== '' && isset($types[$reminder])) {
                $resolved = call_user_func($types[$reminder]['resolve'], $u, visible_user_ids($u));
                $subject = $subject ?: $types[$reminder]['subject'];
                $body = trim($body) !== '' ? $body : $types[$reminder]['body'];
            } else {
                $resolved = array_map(fn ($m) => ['user' => $m, 'vars' => []],
                    msg_resolve_audience($u, $audience, $ref));
            }

            // Non-transactional mail respects the per-user mute and skips synthetic addresses.
            $skipped = [];
            $targets = [];
            foreach ($resolved as $r) {
                $m = $r['user'];
                if (!empty($m['email_opt_out'])) {
                    $skipped[] = $m['name'] . ' (muted non-essential email)';
                } elseif (!mail_address_ok($m['email'])) {
                    $skipped[] = $m['name'] . ' (no usable email address)';
                } else {
                    $targets[] = $r;
                }
            }

            if ($subject === '' || trim($body) === '') {
                flash('A subject and a message body are both required.', 'error');
                redirect('/app/messages');
            }

            if ($action === 'preview') {
                $sample = $targets[0] ?? null;
                $preview = [
                    'count' => count($targets), 'skipped' => $skipped,
                    'subject' => $sample ? msg_merge($subject, msg_tokens_for($sample['user'], $org, $sample['vars'])) : $subject,
                    'body' => $sample ? msg_merge($body, msg_tokens_for($sample['user'], $org, $sample['vars'])) : $body,
                    'to' => $sample ? ($sample['user']['name'] . ' <' . $sample['user']['email'] . '>') : '—',
                    'raw_subject' => $subject, 'raw_body' => $body,
                    'audience' => $audience, 'audience_ref' => $ref, 'reminder_type' => $reminder,
                ];
            } else {
                $queued = 0;
                foreach ($targets as $r) {
                    $vars = msg_tokens_for($r['user'], $org, $r['vars']);
                    $subj = msg_merge($subject, $vars);
                    $text = msg_merge($body, $vars);
                    $html = mail_render_html([
                        'org' => $org, 'heading' => $subj,
                        'body_html' => nl2br(e($text)),
                        'cta_url' => rtrim((string) public_base_url(), '/') . url('/app'),
                        'cta_label' => 'Open DeskPulse',
                    ]);
                    $queued += mail_queue([
                        'org_id' => $orgId, 'user_id' => (int) $r['user']['id'],
                        'to_email' => $r['user']['email'], 'to_name' => $r['user']['name'],
                        'subject' => $subj, 'html' => $html, 'text' => $text,
                        'kind' => $reminder !== '' ? 'reminder' : 'message',
                        'created_by_id' => (int) $u['id'],
                    ]) ? 1 : 0;
                }
                flash($queued . ' message(s) queued'
                    . (count($skipped) ? ', ' . count($skipped) . ' skipped' : '')
                    . (mail_enabled() ? '.' : ' — but no SMTP transport is configured yet, so nothing will send.'),
                    $queued ? 'success' : 'error');
                redirect('/app/messages');
            }
        } elseif ($action === 'test') {
            $subject = trim((string) ($_POST['subject'] ?? '')) ?: 'DeskPulse test email';
            $body = (string) ($_POST['body'] ?? 'This is a test message from DeskPulse.');
            $vars = msg_tokens_for($u, $org);
            $id = mail_queue([
                'org_id' => $orgId, 'user_id' => (int) $u['id'],
                'to_email' => $u['email'], 'to_name' => $u['name'],
                'subject' => msg_merge($subject, $vars),
                'text' => msg_merge($body, $vars),
                'html' => mail_render_html(['org' => $org, 'heading' => msg_merge($subject, $vars),
                                            'body_html' => nl2br(e(msg_merge($body, $vars)))]),
                'kind' => 'test', 'created_by_id' => (int) $u['id'],
            ]);
            if ($id) {
                $row = db_one('SELECT * FROM email_outbox WHERE id = ?', [$id]);
                [$ok, $err] = mail_send_row($row);
                flash($ok ? 'Test email sent to ' . $u['email'] . '.' : 'Test send failed: ' . $err,
                    $ok ? 'success' : 'error');
            } else {
                flash('Your own account has no usable email address.', 'error');
            }
            redirect('/app/messages');
        } elseif ($action === 'flush') {
            [$sent, $failed] = mail_flush(50);
            flash("Queue drained — $sent sent, $failed failed.", $failed ? 'error' : 'success');
            redirect('/app/messages');
        } elseif ($action === 'retry') {
            db_exec("UPDATE email_outbox SET status = 'queued', attempts = 0, last_error = NULL
                     WHERE id = ? AND org_id = ?", [(int) ($_POST['email_id'] ?? 0), $orgId]);
            flash('Message re-queued.', 'success');
            redirect('/app/messages');
        } elseif ($action === 'notice_create') {
            $title = substr(trim((string) ($_POST['title'] ?? '')), 0, 160);
            $body  = trim((string) ($_POST['notice_body'] ?? ''));
            if ($title === '' || $body === '') {
                flash('A notice needs a title and a body.', 'error');
                redirect('/app/messages');
            }
            $audience = (string) ($_POST['n_audience'] ?? 'all');
            $ref = $audience === 'users'
                ? implode(',', array_map('intval', (array) ($_POST['n_user_ids'] ?? [])))
                : (string) ($_POST['n_audience_ref'] ?? '');
            $ends = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['ends_at'] ?? '')
                ? $_POST['ends_at'] . ' 23:59:59' : null;
            $noticeId = db_exec(
                'INSERT INTO notices (org_id, title, body, level, audience, audience_ref, starts_at, ends_at,
                        emailed, created_by_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$orgId, $title, $body,
                 in_array($_POST['level'] ?? '', ['info', 'success', 'warn', 'urgent'], true) ? $_POST['level'] : 'info',
                 $audience, $ref ?: null, gmdate('Y-m-d H:i:s'), $ends,
                 isset($_POST['also_email']) ? 1 : 0, $u['id']]
            );
            $emailed = 0;
            if (isset($_POST['also_email'])) {
                foreach (msg_resolve_audience($u, $audience, $audience === 'users' ? explode(',', (string) $ref) : $ref) as $m) {
                    if (!empty($m['email_opt_out']) || !mail_address_ok($m['email'])) {
                        continue;
                    }
                    $vars = msg_tokens_for($m, $org);
                    $emailed += mail_queue([
                        'org_id' => $orgId, 'user_id' => (int) $m['id'],
                        'to_email' => $m['email'], 'to_name' => $m['name'],
                        'subject' => msg_merge($title, $vars),
                        'text' => msg_merge($body, $vars),
                        'html' => mail_render_html(['org' => $org, 'heading' => msg_merge($title, $vars),
                                                    'body_html' => nl2br(e(msg_merge($body, $vars)))]),
                        'kind' => 'notice', 'created_by_id' => (int) $u['id'],
                    ]) ? 1 : 0;
                }
            }
            flash('Notice published' . ($emailed ? " and emailed to $emailed people." : '.'), 'success');
            redirect('/app/messages');
        } elseif ($action === 'notice_delete') {
            db_exec('DELETE FROM notices WHERE id = ? AND org_id = ?',
                [(int) ($_POST['notice_id'] ?? 0), $orgId]);
            flash('Notice removed.', 'success');
            redirect('/app/messages');
        }
    }

    $members = msg_candidates($u);
    $teams = db_all('SELECT id, name FROM teams WHERE org_id = ? ORDER BY name', [$orgId]);
    $outbox = db_all('SELECT * FROM email_outbox WHERE org_id = ? ORDER BY id DESC LIMIT 100', [$orgId]);
    $notices = db_all('SELECT n.*, u.name AS author FROM notices n LEFT JOIN users u ON u.id = n.created_by_id
                       WHERE n.org_id = ? ORDER BY n.id DESC LIMIT 50', [$orgId]);
    $counts = db_one("SELECT
            SUM(status='queued') AS queued, SUM(status='sent') AS sent, SUM(status='failed') AS failed
            FROM email_outbox WHERE org_id = ?", [$orgId]) ?: [];

    view('dashboard/messages', array_merge(nav_context($u), [
        'title' => 'Messages & notices', 'active' => 'messages',
        'members' => $members, 'teams' => $teams, 'outbox' => $outbox, 'notices' => $notices,
        'counts' => $counts, 'preview' => $preview, 'reminders' => reminder_types(),
        'tokens' => msg_token_help(), 'mail_ready' => mail_enabled(),
        'roles' => ['member' => 'Employee', 'manager' => 'Team manager', 'hr_manager' => 'HR admin',
                    'it_admin' => 'IT admin', 'client_admin' => 'Company admin'],
    ]));
}

// ─────────────────────────── In-app notices ───────────────────────────

/** Active, undismissed notices for a user — rendered as banners by the layout. */
function notices_for_user(array $u): array
{
    $now = gmdate('Y-m-d H:i:s');
    $rows = db_all(
        "SELECT n.* FROM notices n
         LEFT JOIN notice_reads r ON r.notice_id = n.id AND r.user_id = ?
         WHERE n.org_id = ? AND r.id IS NULL
           AND (n.starts_at IS NULL OR n.starts_at <= ?)
           AND (n.ends_at IS NULL OR n.ends_at >= ?)
         ORDER BY n.id DESC LIMIT 5",
        [(int) $u['id'], (int) $u['org_id'], $now, $now]
    );
    $out = [];
    foreach ($rows as $n) {
        if (notice_targets_user($n, $u)) {
            $out[] = $n;
        }
    }
    return $out;
}

function notice_targets_user(array $n, array $u): bool
{
    switch ($n['audience']) {
        case 'role':
            return $u['role'] === $n['audience_ref'];
        case 'team':
            return (bool) db_one('SELECT id FROM team_members WHERE team_id = ? AND user_id = ?',
                [(int) $n['audience_ref'], (int) $u['id']]);
        case 'users':
            return in_array((string) $u['id'], array_map('trim', explode(',', (string) $n['audience_ref'])), true);
        default:
            return true;
    }
}

function dash_notice_dismiss(array $p): void
{
    $u = require_login();
    check_csrf();
    db_exec('INSERT IGNORE INTO notice_reads (notice_id, user_id) VALUES (?, ?)',
        [(int) $p['id'], (int) $u['id']]);
    redirect($_POST['back'] ?? '/app');
}
