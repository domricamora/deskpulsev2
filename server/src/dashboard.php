<?php
/** Authenticated dashboard pages under /app. Data is always scoped to the
 *  current user's organization; members see only their own data, managers and
 *  admins see everyone in the org. */

/** The client record a client_viewer login is attached to (clients.user_id), or null. */
function client_for_viewer(array $u): ?array
{
    if ($u['role'] !== 'client_viewer') {
        return null;
    }
    return db_one('SELECT * FROM clients WHERE user_id = ? AND org_id = ?', [$u['id'], $u['org_id']]);
}

/** Agent ids a client_viewer may see: agents assigned to their client (agent_clients)
 *  plus anyone who has logged time against that client. Returns [0] (a no-match
 *  sentinel) when the login isn't linked to a client. */
function client_viewer_user_ids(array $u): array
{
    $c = client_for_viewer($u);
    if (!$c) {
        return [0];
    }
    $cid = (int) $c['id'];
    $ids = [];
    foreach (db_all('SELECT agent_id FROM agent_clients WHERE client_id = ?', [$cid]) as $r) {
        $ids[(int) $r['agent_id']] = true;
    }
    foreach (db_all('SELECT DISTINCT user_id FROM sessions WHERE client_id = ?', [$cid]) as $r) {
        $ids[(int) $r['user_id']] = true;
    }
    return $ids ? array_keys($ids) : [0];
}

/** User ids the current user may view (capability-, team- and client-scoped). */
function visible_user_ids(array $u): array
{
    if ($u['role'] === 'client_viewer') {
        $ids = client_viewer_user_ids($u);   // scoped to their own client engagement
    } elseif (can($u, 'view_all')) {
        $ids = org_user_ids((int) $u['org_id']);
    } elseif (can($u, 'view_team')) {
        $ids = team_member_ids((int) $u['id']);
    } else {
        $ids = [(int) $u['id']];
    }
    // Normalize to ints: PDO returns integer columns as strings on some MySQL/MariaDB
    // builds (notably the shared-host DB), which broke strict in_array() scope checks
    // like the session/agent detail pages (they'd wrongly report "not available").
    return array_map('intval', $ids);
}

function nav_context(array $u): array
{
    require_approved_org($u);   // hold members of unapproved orgs at the gate
    close_stale_sessions();     // auto-close agents that went offline/crashed
    recompute_pending_overtime();   // split overtime on any freshly/stale-closed sessions
    // First-run: the company admin must set up the org before using the app. The
    // wizard also reappears whenever the org has no accounts besides the admin.
    if ($u['role'] === 'client_admin' && !str_contains(current_route_path(), '/app/onboarding')
            && !str_contains(current_route_path(), '/app/subscription')) {
        $onb = db_one('SELECT onboarded_at FROM organizations WHERE id = ?', [$u['org_id']]);
        // Force the wizard on first login until it's completed or skipped (onboarded_at
        // set). Setup is optional — don't drag the admin back just for an empty roster.
        if ($onb && empty($onb['onboarded_at'])) {
            redirect('/app/onboarding');
        }
    }
    // First-run for a team manager: build a roster before using the app. Once they
    // finish or skip the wizard (welcomed_at stamped) they're no longer forced back.
    if ($u['role'] === 'manager' && empty($u['welcomed_at'])
            && !str_contains(current_route_path(), '/app/onboarding')
            && !str_contains(current_route_path(), '/app/subscription')) {
        if (!manager_roster_ids($u)) {
            redirect('/app/onboarding');
        }
    }
    // First-run for employees / HR / IT / client viewers: a one-time role guide.
    // These roles don't configure anything, so instead of a wizard they get a short
    // "getting started" tour they can dismiss (and reopen later from the sidebar).
    if (is_welcome_role($u['role'])) {
        $path = current_route_path();
        if (!str_contains($path, '/app/welcome') && !str_contains($path, '/app/change-password')
                && !str_contains($path, '/app/subscription')) {
            $w = db_one('SELECT welcomed_at FROM users WHERE id = ?', [$u['id']]);
            if ($w && empty($w['welcomed_at'])) {
                redirect('/app/welcome');
            }
        }
    }
    $pending = 0;
    if (can($u, 'approve_time')) {
        $ids = visible_user_ids($u);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $row = db_one(
            "SELECT COUNT(*) c FROM sessions WHERE user_id IN ($in) AND approval_status = 'pending'",
            $ids
        );
        $pending = (int) ($row['c'] ?? 0);
    }
    $overtimePending = 0;
    if (can($u, 'approve_overtime')) {
        $ids = visible_user_ids($u);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $row = db_one(
            "SELECT COUNT(*) c FROM sessions WHERE user_id IN ($in) AND overtime_status = 'pending'",
            $ids
        );
        $overtimePending = (int) ($row['c'] ?? 0);
    }
    // When a super admin is "acting as" an org, surface a banner with its name.
    $actingOrg = null;
    if ($u['role'] === 'super_admin' && !empty($_SESSION['act_org'])) {
        $actingOrg = db_one('SELECT name FROM organizations WHERE id = ?', [$_SESSION['act_org']])['name'] ?? null;
    }
    // Company branding logo (shown in the sidebar for everyone in the org).
    $logoPath = db_one('SELECT logo_path FROM organizations WHERE id = ?', [$u['org_id']])['logo_path'] ?? null;
    // Opportunistic mail drain for hosts without cron (opt-in, capped at 2/request).
    mail_maybe_flush();
    return ['user' => $u, 'pending_count' => $pending, 'overtime_pending_count' => $overtimePending,
            'is_manager' => is_manager($u), 'is_super' => is_super($u), 'acting_org' => $actingOrg,
            'org_logo' => org_logo_url($logoPath), 'notices' => notices_for_user($u)];
}

// ─────────────────────────── Pending-approval gate ───────────────────────────

function dash_pending(): void
{
    $u = require_login();
    if ($u['role'] === 'super_admin') {
        redirect('/app/platform');
    }
    $org = db_one('SELECT name, status FROM organizations WHERE id = ?', [$u['org_id']]);
    if (($org['status'] ?? 'approved') === 'approved') {
        redirect('/app/overview');
    }
    view('dashboard/pending', ['title' => 'Pending approval', 'org' => $org, 'user' => $u],
        'layout_public');
}

// ─────────────────────────── Subscription / paywall (company admin) ───────────────────────────

/** The org's own subscription page: trial/paywall status, amount due, US bank-deposit
 *  instructions, and (later phases) invoices + promo. Reachable by any org member (the
 *  paywall redirects them here); only a company admin (`subscription` cap) can act. */
function dash_subscription(): void
{
    $u = require_login();
    require_approved_org($u);   // exempt path — won't loop; still bounces unapproved orgs
    $org = db_one('SELECT * FROM organizations WHERE id = ?', [$u['org_id']]);
    $canManage = can($u, 'subscription');
    if ($canManage) {
        ensure_pay_reference($org);
        $org = db_one('SELECT * FROM organizations WHERE id = ?', [$u['org_id']]);
    }

    if (request_method() === 'POST') {
        check_csrf();
        if (!$canManage) {
            abort(403, 'Not allowed');
        }
        // Self-serve plan change. Without this a customer who outgrows Solo, or wants to
        // step down after a seasonal ramp, has to email support — and "email support to
        // give us more money" is where expansion revenue goes to die.
        // Enterprise is excluded: it carries a negotiated fee only a super admin sets.
        if (($_POST['action'] ?? '') === 'change_plan') {
            $newPlan = $_POST['plan_type'] ?? '';
            if (!in_array($newPlan, ['solo', 'individual', 'per_seat', 'organization'], true)) {
                flash('Pick a plan to switch to.', 'error');
                redirect('/app/subscription');
            }
            $limits = ['solo' => 1, 'individual' => 1];
            $seats = org_seat_count((int) $org['id']);
            // Refuse a downgrade the org cannot fit into rather than silently locking
            // people out of an account they are already using.
            if (isset($limits[$newPlan]) && $seats > $limits[$newPlan]) {
                flash('That plan covers ' . $limits[$newPlan] . ' user and you have ' . $seats
                    . '. Remove the extra people first, or choose Team.', 'error');
                redirect('/app/subscription');
            }
            $oldPlan = plan_type_clean($org['plan_type'] ?? null);
            $fee = effective_monthly_fee(array_merge($org, ['plan_type' => $newPlan]));
            db_exec('UPDATE organizations SET plan_type = ?, monthly_fee = ? WHERE id = ?',
                [$newPlan, $fee, (int) $org['id']]);
            notify_sales('subscription.self_serve_change', 'Plan changed by customer: ' . $org['name'], [
                'Organization' => $org['name'],
                'From'         => plan_labels()[$oldPlan] ?? $oldPlan,
                'To'           => plan_labels()[$newPlan] ?? $newPlan,
                'Seats'        => (string) $seats,
                'New fee'      => number_format($fee, 2) . ' ' . ($org['billing_currency'] ?: 'USD'),
                'Changed by'   => $u['name'] . ' (' . $u['email'] . ')',
            ], (int) $org['id']);
            $_SESSION['dp_track'][] = ['plan_change', ['from' => $oldPlan, 'to' => $newPlan]];
            flash('You are now on the ' . (plan_labels()[$newPlan] ?? $newPlan)
                . ' plan. Your next invoice reflects it.', 'success');
            redirect('/app/subscription');
        }
        if (($_POST['action'] ?? '') === 'submit_payment') {
            $cents = (int) round(((float) ($_POST['amount'] ?? 0)) * 100);
            $paidOn = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['paid_on'] ?? '') ? $_POST['paid_on'] : null;
            if ($cents <= 0) {
                flash('Enter the amount you sent.', 'error');
                redirect('/app/subscription');
            }
            [$receipt, $rErr] = store_payment_receipt($_FILES['receipt'] ?? [], (int) $org['id']);
            if ($rErr !== null) {
                flash($rErr, 'error');
                redirect('/app/subscription');
            }
            $method = array_key_exists($_POST['method'] ?? '', claim_methods()) ? $_POST['method'] : 'bank_transfer';
            db_exec(
                'INSERT INTO payment_claims (org_id, submitted_by_id, amount_cents, currency, paid_on, method,
                        sender_name, sender_bank, reference, note, receipt_path)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [(int) $org['id'], (int) $u['id'], $cents,
                 substr(strtoupper((string) ($_POST['currency'] ?? 'USD')), 0, 8) ?: 'USD',
                 $paidOn, $method,
                 substr(trim((string) ($_POST['sender_name'] ?? '')), 0, 160) ?: null,
                 substr(trim((string) ($_POST['sender_bank'] ?? '')), 0, 160) ?: null,
                 substr(trim((string) ($_POST['reference'] ?? $org['pay_reference'] ?? '')), 0, 120) ?: null,
                 substr((string) ($_POST['note'] ?? ''), 0, 2000) ?: null,
                 $receipt]
            );
            notify_sales('subscription.payment_notified', 'Payment notification: ' . $org['name'], [
                'Organization' => $org['name'],
                'Amount'       => number_format($cents / 100, 2) . ' ' . strtoupper((string) ($_POST['currency'] ?? 'USD')),
                'Sent on'      => $paidOn ?: 'not stated',
                'Reference'    => $org['pay_reference'] ?? '',
                'Submitted by' => $u['name'] . ' <' . $u['email'] . '>',
                'Receipt'      => $receipt ? 'attached' : 'none',
                'Action'       => 'Verify against the bank, then confirm it on the Accounting page.',
            ], (int) $org['id']);
            flash('Thanks — we\'ve recorded your payment notification and will confirm it once the '
                . 'transfer shows in our account.', 'success');
            redirect('/app/subscription');
        }
        redirect('/app/subscription');
    }

    $claims = db_all('SELECT * FROM payment_claims WHERE org_id = ? ORDER BY id DESC LIMIT 20', [$org['id']]);

    view('dashboard/subscription', array_merge(nav_context($u), [
        'title'        => 'Subscription',
        'claims'       => $claims,
        'methods'      => claim_methods(),
        'active'       => 'subscription',
        'org'          => $org,
        'can_manage'   => $canManage,
        'is_current'   => sub_is_current($org),
        'days_left'    => sub_days_left($org),
        'state_label'  => sub_state_label($org),
        'amount_cents' => plan_amount_cents($org),
        'reference'    => $org['pay_reference'] ?? '',
        'deposit'      => wise_usd_deposit_details(),
        'trial_days'   => platform_trial_days(),
        'plan_period'  => plan_period_label(),
    ]));
}

// ─────────────────────────── First-run onboarding (company admin) ───────────────────────────

/** A manager's actual roster: real teammate ids, excluding self and the "no team"
 *  sentinel (0) that team_member_ids() returns when the manager has no team. */
function manager_roster_ids(array $u): array
{
    return array_values(array_filter(
        team_member_ids((int) $u['id']),
        fn($id) => $id > 0 && $id !== (int) $u['id']
    ));
}

/** The team a manager owns; optionally create one (named after them) if none. */
function manager_team_id(array $u, bool $create = false): ?int
{
    $t = db_one('SELECT t.id FROM team_members tm JOIN teams t ON t.id = tm.team_id
                 WHERE tm.user_id = ? ORDER BY t.id LIMIT 1', [$u['id']]);
    if ($t) {
        return (int) $t['id'];
    }
    if (!$create) {
        return null;
    }
    $tid = db_exec('INSERT INTO teams (org_id, name) VALUES (?, ?)',
        [$u['org_id'], substr($u['name'], 0, 150) . "'s Team"]);
    db_exec('INSERT INTO team_members (team_id, user_id) VALUES (?, ?)', [$tid, $u['id']]);
    return (int) $tid;
}

/** Team-manager first run: guide them to build a roster of agents, then tasks. */
function dash_onboarding_manager(array $u): void
{
    $oid = (int) $u['org_id'];
    $steps = ['roster', 'tasks', 'done'];

    if (request_method() === 'POST') {
        check_csrf();
        $action = $_POST['action'] ?? '';
        $step = in_array($_POST['step'] ?? '', $steps, true) ? $_POST['step'] : 'roster';
        if ($action === 'add_agent') {
            $email = strtolower(trim($_POST['email'] ?? ''));
            if ($email && filter_var($email, FILTER_VALIDATE_EMAIL) && !db_one('SELECT id FROM users WHERE email=?', [$email])) {
                $newId = db_exec('INSERT INTO users (org_id, name, email, password_hash, role, currency) VALUES (?,?,?,?,?,?)',
                    [$oid, substr(trim($_POST['name'] ?? 'New User'), 0, 120) ?: 'New User', $email,
                     password_hash(($_POST['password'] ?? '') ?: random_token(10), PASSWORD_DEFAULT), 'member', 'USD']);
                $teamId = manager_team_id($u, true);
                db_exec('INSERT INTO team_members (team_id, user_id) VALUES (?, ?)', [$teamId, $newId]);
                flash('Agent added to your roster.', 'success');
            } else {
                flash('Could not add agent — missing or duplicate email.', 'error');
            }
        } elseif ($action === 'add_task') {
            $title = trim($_POST['title'] ?? '');
            $roster = team_member_ids((int) $u['id']);
            $ownerId = (int) ($_POST['user_id'] ?? 0);
            $owner = (in_array($ownerId, $roster, true))
                ? db_one("SELECT id FROM users WHERE id=? AND role IN ('member','manager')", [$ownerId]) : null;
            $clientId = null;
            if (!empty($_POST['client_id'])) {
                $c = db_one('SELECT id FROM clients WHERE id=? AND org_id=?', [(int) $_POST['client_id'], $oid]);
                $clientId = $c ? (int) $c['id'] : null;
            }
            if ($title !== '' && $owner) {
                db_exec('INSERT INTO tasks (org_id, user_id, client_id, title) VALUES (?,?,?,?)',
                    [$oid, $owner['id'], $clientId, substr($title, 0, 240)]);
                flash('Task added.', 'success');
            } else {
                flash('Add a title and pick someone in your roster.', 'error');
            }
        } elseif ($action === 'finish') {
            // Finish or skip: stamp welcomed_at so the roster wizard won't reappear.
            db_exec('UPDATE users SET welcomed_at = NOW() WHERE id = ? AND welcomed_at IS NULL', [(int) $u['id']]);
            $roster = array_diff(team_member_ids((int) $u['id']), [(int) $u['id']]);
            flash($roster ? 'Your roster is ready.' : 'Skipped — add agents any time from your dashboard.', 'success');
            redirect('/app/overview');
        }
        redirect('/app/onboarding?step=' . $step);
    }

    $step = in_array($_GET['step'] ?? '', $steps, true) ? $_GET['step'] : 'roster';
    $rosterIds = array_values(array_diff(team_member_ids((int) $u['id']), [(int) $u['id']]));
    $roster = [];
    if ($rosterIds) {
        $in = implode(',', array_fill(0, count($rosterIds), '?'));
        $roster = db_all("SELECT id, name, email, role FROM users WHERE id IN ($in) ORDER BY name", $rosterIds);
    }
    $clients = db_all('SELECT id, name FROM clients WHERE org_id=? AND archived=0 ORDER BY name', [$oid]);
    $tasks = [];
    if ($rosterIds) {
        $in = implode(',', array_fill(0, count($rosterIds), '?'));
        $tasks = db_all("SELECT t.title, us.name owner, c.name client FROM tasks t
                         JOIN users us ON us.id=t.user_id LEFT JOIN clients c ON c.id=t.client_id
                         WHERE t.user_id IN ($in) ORDER BY t.created_at DESC", $rosterIds);
    }
    view('dashboard/onboarding_manager', array_merge(nav_context($u), [
        'title' => 'Build your roster', 'active' => '', 'step' => $step, 'steps' => $steps,
        'roster' => $roster, 'clients' => $clients, 'tasks' => $tasks,
    ]));
}

function dash_onboarding(): void
{
    $u = require_login();
    require_approved_org($u);
    if ($u['role'] === 'manager') {
        dash_onboarding_manager($u);
        return;
    }
    if ($u['role'] !== 'client_admin') {
        redirect('/app/overview');   // only the company admin / managers run setup
    }
    $oid = (int) $u['org_id'];
    $org = db_one('SELECT * FROM organizations WHERE id = ?', [$oid]);
    $steps = ['org', 'clients', 'teams', 'agents', 'tasks', 'done'];

    if (request_method() === 'POST') {
        check_csrf();
        $action = $_POST['action'] ?? '';
        $step = in_array($_POST['step'] ?? '', $steps, true) ? $_POST['step'] : 'org';

        if ($action === 'save_org') {
            $name = substr(trim($_POST['name'] ?? ''), 0, 160) ?: $org['name'];
            db_exec('UPDATE organizations SET name=?, screenshot_interval_min=?, screenshot_blur=?,
                     idle_threshold_min=?, track_screenshots=?, track_windows=?, track_processes=? WHERE id=?',
                [$name, max(1, min(120, (int) ($_POST['screenshot_interval_min'] ?? 10))),
                 isset($_POST['screenshot_blur']) ? 1 : 0, max(1, min(120, (int) ($_POST['idle_threshold_min'] ?? 15))),
                 isset($_POST['track_screenshots']) ? 1 : 0, isset($_POST['track_windows']) ? 1 : 0,
                 isset($_POST['track_processes']) ? 1 : 0, $oid]);
            flash('Organization saved.', 'success');
            $step = 'clients';
        } elseif ($action === 'add_client') {
            $name = trim($_POST['name'] ?? '');
            $email = strtolower(trim($_POST['contact_email'] ?? ''));
            if ($name !== '') {
                $cid = db_exec('INSERT INTO clients (org_id, name, contact_email) VALUES (?, ?, ?)',
                    [$oid, substr($name, 0, 200), substr($email, 0, 190)]);
                // With a contact email, also provision the client's read-only portal login.
                if ($email !== '') {
                    [$ok, $msg] = create_client_login($u, $cid, $name, $email);
                    flash('Client added.' . ($msg ? ' ' . $msg : ''), 'success');
                } else {
                    flash('Client added.', 'success');
                }
            }
        } elseif ($action === 'create_team') {
            $name = trim($_POST['team_name'] ?? '');
            if ($name !== '') {
                db_exec('INSERT INTO teams (org_id, name) VALUES (?, ?)', [$oid, substr($name, 0, 160)]);
                flash('Team created.', 'success');
            }
        } elseif ($action === 'add_team_member') {
            $team = db_one('SELECT id FROM teams WHERE id=? AND org_id=?', [(int) ($_POST['team_id'] ?? 0), $oid]);
            $member = db_one('SELECT id FROM users WHERE id=? AND org_id=?', [(int) ($_POST['user_id'] ?? 0), $oid]);
            if ($team && $member && !db_one('SELECT id FROM team_members WHERE team_id=? AND user_id=?', [$team['id'], $member['id']])) {
                db_exec('INSERT INTO team_members (team_id, user_id) VALUES (?, ?)', [$team['id'], $member['id']]);
                flash('Added to team.', 'success');
            }
        } elseif ($action === 'add_account') {
            $email = strtolower(trim($_POST['email'] ?? ''));
            // Client portal logins are provisioned on the Clients page, not here.
            $assignable = ['member', 'manager', 'hr_manager', 'it_admin'];
            if ($email && filter_var($email, FILTER_VALIDATE_EMAIL) && !db_one('SELECT id FROM users WHERE email=?', [$email])) {
                db_exec('INSERT INTO users (org_id, name, email, password_hash, role, currency) VALUES (?,?,?,?,?,?)',
                    [$oid, substr(trim($_POST['name'] ?? 'New User'), 0, 120) ?: 'New User', $email,
                     password_hash(($_POST['password'] ?? '') ?: random_token(10), PASSWORD_DEFAULT),
                     in_array($_POST['role'] ?? '', $assignable, true) ? $_POST['role'] : 'member',
                     substr($org['billing_currency'] ?: 'USD', 0, 8)]);
                flash('Account created.', 'success');
            } else {
                flash('Could not create account — missing or duplicate email.', 'error');
            }
        } elseif ($action === 'add_task') {
            $title = trim($_POST['title'] ?? '');
            // Tasks belong to the people who actually do the work: agents & team managers.
            $owner = db_one("SELECT id FROM users WHERE id=? AND org_id=? AND role IN ('member','manager')",
                [(int) ($_POST['user_id'] ?? 0), $oid]);
            $clientId = null;
            if (!empty($_POST['client_id'])) {
                $c = db_one('SELECT id FROM clients WHERE id=? AND org_id=?', [(int) $_POST['client_id'], $oid]);
                $clientId = $c ? (int) $c['id'] : null;
            }
            if ($title !== '' && $owner) {
                db_exec('INSERT INTO tasks (org_id, user_id, client_id, title) VALUES (?,?,?,?)',
                    [$oid, $owner['id'], $clientId, substr($title, 0, 240)]);
                flash('Task added.', 'success');
            } else {
                flash('Add a title and pick who the task is for.', 'error');
            }
        } elseif ($action === 'finish') {
            // Finish or skip: stamp onboarded_at so the wizard won't reappear. Setup is
            // optional — clients, teams and accounts can all be added later from the app.
            db_exec('UPDATE organizations SET onboarded_at = NOW() WHERE id = ?', [$oid]);
            $others = (int) db_one("SELECT COUNT(*) c FROM users
                WHERE org_id=? AND role NOT IN ('client_admin','super_admin')", [$oid])['c'];
            flash($others > 0 ? 'Setup complete — welcome to DeskPulse!'
                              : 'Setup skipped — you can add your team any time.', 'success');
            redirect('/app/overview');
        }
        redirect('/app/onboarding?step=' . $step);
    }

    // Only bounce a fully set-up org (onboarded AND has team accounts) back to the
    // app; otherwise show the wizard — this avoids an onboarding↔overview redirect
    // loop when an onboarded org has no accounts besides the admin.
    $others = (int) db_one("SELECT COUNT(*) c FROM users
        WHERE org_id=? AND role NOT IN ('client_admin','super_admin')", [$oid])['c'];
    if (!empty($org['onboarded_at']) && $others > 0 && empty($_GET['step'])) {
        redirect('/app/overview');   // already set up (still reachable via ?step= to revisit)
    }
    $step = in_array($_GET['step'] ?? '', $steps, true) ? $_GET['step'] : 'org';
    $members = db_all("SELECT id, name, email, role FROM users WHERE org_id=? AND id<>? ORDER BY name", [$oid, $u['id']]);
    $clients = db_all('SELECT id, name, contact_email, user_id FROM clients WHERE org_id=? AND archived=0 ORDER BY name', [$oid]);
    $teams = db_all('SELECT id, name FROM teams WHERE org_id=? ORDER BY name', [$oid]);
    $teamMembers = [];
    foreach (db_all('SELECT tm.team_id, us.name FROM team_members tm JOIN users us ON us.id=tm.user_id
                     JOIN teams t ON t.id=tm.team_id WHERE t.org_id=? ORDER BY us.name', [$oid]) as $r) {
        $teamMembers[$r['team_id']][] = $r['name'];
    }
    $tasks = db_all('SELECT t.title, us.name owner, c.name client FROM tasks t
                     JOIN users us ON us.id=t.user_id LEFT JOIN clients c ON c.id=t.client_id
                     WHERE t.org_id=? ORDER BY t.created_at DESC', [$oid]);

    view('dashboard/onboarding', array_merge(nav_context($u), [
        'title' => 'Set up DeskPulse', 'active' => '', 'org' => $org, 'policy' => org_policy($oid),
        'step' => $step, 'steps' => $steps, 'members' => $members, 'clients' => $clients,
        'teams' => $teams, 'team_members' => $teamMembers, 'tasks' => $tasks,
    ]));
}

// ─────────────────────────── First-run welcome guide (employees, HR, IT, client viewers) ───────────────────────────

/** Roles that get a one-time role guide on first login instead of a setup wizard. */
function is_welcome_role(string $role): bool
{
    return in_array($role, ['member', 'hr_manager', 'it_admin', 'client_viewer'], true);
}

/** A short, role-specific "getting started" guide. Auto-shown once to the roles
 *  above; reachable any time via the sidebar "Getting started" link (admins and
 *  managers can open it too — their first run is the setup wizard). */
function dash_welcome(): void
{
    $u = require_login();
    require_approved_org($u);
    if (request_method() === 'POST') {
        check_csrf();
        if (($_POST['action'] ?? '') === 'dismiss') {
            db_exec('UPDATE users SET welcomed_at = NOW() WHERE id = ? AND welcomed_at IS NULL', [$u['id']]);
        }
        redirect($_POST['to'] ?? '/app/overview');
    }
    if ($u['role'] === 'super_admin' && empty($_SESSION['act_org'])) {
        redirect('/app/platform');
    }
    view('dashboard/welcome', array_merge(nav_context($u), [
        'title' => 'Getting started', 'active' => 'welcome',
    ]));
}

// ─────────────────────────── Overview ───────────────────────────

function dash_overview(): void
{
    $u = require_login();
    // Platform operator gets a business-owner home (revenue, leads, customers).
    if ($u['role'] === 'super_admin' && empty($_SESSION['act_org'])) {
        dash_platform_overview($u);
        return;
    }
    // Client portals have no Overview — their home is the Agents roster.
    if ($u['role'] === 'client_viewer') {
        redirect('/app/agents');
    }
    [$start, $end, $period, $periodDate] = period_range_from_request('day', ['day', 'week', 'pay', 'month']);   // overview opens on Today
    // Graphs & stats reflect the viewer's scope (visible_user_ids): a member sees
    // only their own tracked data; a team manager sees their team roster of agents
    // aggregated; a company/HR/IT admin sees the whole organization (all teams).
    $ids = visible_user_ids($u);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $sessions = sessions_for_users($ids, $start, $end);
    $usersById = users_by_id((int) $u['org_id']);

    $summary = summarize($sessions);
    $cost = labor_cost($sessions, $usersById);
    $timeline = activity_timeline($ids, $start, $end);
    $apps = top_apps(array_column($sessions, 'id'));
    $recentShots = db_all(
        "SELECT sc.* FROM screenshots sc JOIN sessions s ON s.id = sc.session_id
         WHERE s.user_id IN ($in) ORDER BY sc.ts DESC LIMIT 12", $ids);

    $days = [];
    foreach ($sessions as $s) {
        $days[substr($s['started_at'], 0, 10)] = true;
    }
    $daysTracked = count($days);
    $teamView = can($u, 'view_all') || can($u, 'view_team');
    $stats = [
        'days_tracked' => $daysTracked,
        'avg_daily_s'  => $daysTracked ? (int) round($summary['active_s'] / $daysTracked) : 0,
        'top_app'      => $apps['labels'][0] ?? null,
        'screenshots'  => (int) db_one(
            "SELECT COUNT(*) c FROM screenshots sc JOIN sessions s ON s.id = sc.session_id
             WHERE s.user_id IN ($in) AND sc.ts >= ? AND sc.ts < ?",
            array_merge($ids, [$start, $end]))['c'],
        'open_tasks'   => (int) db_one(
            "SELECT COUNT(*) c FROM tasks WHERE user_id IN ($in) AND status = 'open'", $ids)['c'],
        'team_view'    => $teamView,
        'headcount'    => count($usersById),
        'people_active'=> count(array_unique(array_column($sessions, 'user_id'))),
        'tracking_now' => (int) db_one(
            "SELECT COUNT(*) c FROM sessions WHERE user_id IN ($in) AND ended_at IS NULL", $ids)['c'],
    ];

    // Activity vs inactivity (viewer scope): per-day hours, idle, session-shape.
    $daily = daily_series($sessions, $start, $end);
    $sessIds = array_column($sessions, 'id');
    if ($sessIds) {
        $sin = implode(',', array_fill(0, count($sessIds), '?'));
        $row = db_one("SELECT COUNT(*) c, COALESCE(SUM(duration_s),0) s
                       FROM idle_periods WHERE session_id IN ($sin)", $sessIds);
        $stats['idle_count'] = (int) $row['c'];
        $stats['idle_total_s'] = (int) $row['s'];
    } else {
        $stats['idle_count'] = 0;
        $stats['idle_total_s'] = 0;
    }
    $activeArr = array_map(fn($s) => (int) $s['active_s'], $sessions);
    $stats['avg_session_s']     = $sessions ? (int) round(array_sum($activeArr) / count($sessions)) : 0;
    $stats['longest_session_s'] = $activeArr ? max($activeArr) : 0;

    // Member ("agent") self-dashboard: the current user's own info + tracking setup.
    // Deliberately personal only — no team, company or client data here.
    $isAgent = $u['role'] === 'member';
    $agent = null;
    if ($isAgent) {
        $me = db_one('SELECT * FROM users WHERE id = ?', [$u['id']]);
        $device = db_one('SELECT name, last_seen FROM devices WHERE user_id = ?
                          ORDER BY (last_seen IS NULL), last_seen DESC LIMIT 1', [$u['id']]);
        $curTask = db_one("SELECT t.title FROM sessions s JOIN tasks t ON t.id = s.task_id
                           WHERE s.user_id = ? AND s.task_id IS NOT NULL
                           ORDER BY s.started_at DESC LIMIT 1", [$u['id']]);
        $agent = [
            'name'         => $me['name'],
            'role'         => $me['role'],
            'email'        => $me['email'],
            'phone'        => $me['phone'] ?? '',
            'job_title'    => $me['job_title'] ?? '',
            'pay'          => money((float) $me['pay_rate'], $me['currency']) . ' / ' . ($me['pay_type'] ?: 'hourly'),
            'schedule'     => work_schedule_label($me),
            'in_schedule'  => within_work_schedule($me),
            'device'       => $device,
            'current_task' => $curTask['title'] ?? null,
            'live'         => (bool) db_one('SELECT id FROM sessions WHERE user_id = ? AND ended_at IS NULL LIMIT 1', [$u['id']]),
        ];
    }

    // Roster: the agents under this viewer (team managers / admins), with their
    // tracked time, activity and live status for the period — each opens a modal.
    $roster = [];
    if ($teamView && count($ids) > 1) {
        $liveSet = array_flip(array_map('intval', array_column(
            db_all("SELECT DISTINCT user_id FROM sessions WHERE user_id IN ($in) AND ended_at IS NULL", $ids),
            'user_id')));
        $byUser = [];
        foreach ($sessions as $s) {
            $byUser[(int) $s['user_id']][] = $s;
        }
        foreach ($ids as $uid) {
            if ((int) $uid === (int) $u['id']) {
                continue;   // the viewer themselves isn't listed under themselves
            }
            $info = $usersById[(int) $uid] ?? null;
            if (!$info) {
                continue;
            }
            $sum = summarize($byUser[(int) $uid] ?? []);
            $roster[] = [
                'id' => (int) $uid, 'name' => $info['name'], 'role' => $info['role'],
                'active_s' => $sum['active_s'], 'activity_pct' => $sum['activity_pct'],
                'sessions' => $sum['count'], 'live' => isset($liveSet[(int) $uid]),
            ];
        }
        usort($roster, fn($a, $b) => $b['active_s'] <=> $a['active_s']);
    }

    view('dashboard/overview', array_merge(nav_context($u), [
        'title' => $isAgent ? 'Agent dashboard' : 'Overview', 'active' => 'overview', 'period' => $period,
        'period_date' => $periodDate,
        'summary' => $summary, 'cost' => $cost, 'timeline' => $timeline,
        'apps' => $apps, 'recent_shots' => $recentShots, 'stats' => $stats,
        'can_rates' => can($u, 'view_rates'), 'roster' => $roster,
        'daily' => $daily, 'agent' => $agent, 'is_agent' => $isAgent,
    ]));
}

/**
 * JSON agent details for the overview roster modal — scoped to the viewer's
 * visible team. Rates are included only when the viewer holds `view_rates`.
 */
function dash_agent_info(array $p): void
{
    $u = require_login();
    if (!can($u, 'view_all') && !can($u, 'view_team')) {
        abort(403, 'Not allowed.');
    }
    $uid = (int) $p['id'];
    if (!in_array($uid, visible_user_ids($u), true)) {
        abort(403, 'Out of scope.');
    }
    $usr = db_one('SELECT u.*, o.name org_name FROM users u JOIN organizations o ON o.id = u.org_id
                   WHERE u.id = ?', [$uid]);
    if (!$usr) {
        abort(404, 'user not found');
    }
    [$wkStart, $wkEnd] = period_range('week');
    [$dayStart, $dayEnd] = period_range('day');
    $week = summarize(sessions_for_users([$uid], $wkStart, $wkEnd, false));
    $day  = summarize(sessions_for_users([$uid], $dayStart, $dayEnd, false));
    $live = db_one('SELECT id FROM sessions WHERE user_id = ? AND ended_at IS NULL
                    ORDER BY started_at DESC LIMIT 1', [$uid]);
    $last = db_one('SELECT started_at FROM sessions WHERE user_id = ? ORDER BY started_at DESC LIMIT 1', [$uid]);

    $out = [
        'id'        => $uid,
        'name'      => $usr['name'],
        'email'     => $usr['email'],
        'phone'     => $usr['phone'] ?: '—',
        'job_title' => $usr['job_title'] ?: '—',
        'role'      => role_label($usr['role']),
        'org'       => $usr['org_name'],
        'teams'     => array_column(db_all('SELECT t.name FROM team_members tm
                         JOIN teams t ON t.id = tm.team_id WHERE tm.user_id = ? ORDER BY t.name', [$uid]), 'name'),
        'clients'   => array_column(db_all('SELECT DISTINCT c.name FROM sessions s
                         JOIN clients c ON c.id = s.client_id WHERE s.user_id = ? ORDER BY c.name', [$uid]), 'name'),
        'devices'   => db_all('SELECT id, name, created_at FROM devices WHERE user_id = ? ORDER BY created_at DESC', [$uid]),
        'today'     => ['active' => fmt_hms($day['active_s']), 'pct' => $day['activity_pct']],
        'week'      => ['active' => fmt_hms($week['active_s']), 'pct' => $week['activity_pct'], 'sessions' => $week['count']],
        'total_active' => fmt_hms((int) db_one('SELECT COALESCE(SUM(active_s),0) s FROM sessions WHERE user_id = ?', [$uid])['s']),
        'open_tasks'   => (int) db_one("SELECT COUNT(*) c FROM tasks WHERE user_id = ? AND status = 'open'", [$uid])['c'],
        'live'         => (bool) $live,
        'last_seen'    => $last['started_at'] ?? null,
    ];
    if (can($u, 'view_rates')) {
        $out['pay']  = money((float) $usr['pay_rate'], $usr['currency']) . ' / ' . ($usr['pay_type'] ?: 'hourly');
        $out['bill'] = money((float) $usr['bill_rate'], $usr['currency']) . ' / ' . ($usr['bill_type'] ?: 'hourly');
    }
    json_out($out);
}

// ─────────────────────────── Timesheets + manual entry ───────────────────────────

function dash_timesheets(): void
{
    $u = require_login();
    if (request_method() === 'POST') {
        check_csrf();
        if ($u['role'] === 'client_viewer') {
            abort(403, 'Read-only access.');
        }
        // A member files one or more manual entries / adjustments -> pending review.
        $targetUserId = (int) ($_POST['user_id'] ?? $u['id']);
        if (!is_manager($u)) {
            $targetUserId = (int) $u['id'];   // members can only file for themselves
        }
        $status = is_manager($u) ? 'approved' : 'pending';

        // The form submits parallel arrays (one set per "Add more" row). Start/end
        // arrive as UTC ISO from the browser (computed from the user's local inputs);
        // fall back to the local strings interpreted as UTC if JS didn't populate them.
        $startsU = (array) ($_POST['started_at_utc'] ?? []);
        $endsU   = (array) ($_POST['ended_at_utc'] ?? []);
        $startsL = (array) ($_POST['started_at'] ?? []);
        $endsL   = (array) ($_POST['ended_at'] ?? []);
        $clientIds = (array) ($_POST['client_id'] ?? []);
        $notes   = (array) ($_POST['note'] ?? []);

        $count = max(count($startsU), count($startsL));
        $created = 0;
        $skipped = 0;
        for ($i = 0; $i < $count; $i++) {
            $startUtc = utc_store($startsU[$i] ?? $startsL[$i] ?? '');
            $endUtc   = utc_store($endsU[$i] ?? $endsL[$i] ?? '');
            $activeS = max(0, strtotime($endUtc . ' UTC') - strtotime($startUtc . ' UTC'));
            if (empty($startsL[$i]) && empty($startsU[$i])) {
                continue;   // blank row
            }
            if ($activeS <= 0) {
                $skipped++;
                continue;   // end not after start
            }
            db_exec(
                'INSERT INTO sessions
                    (user_id, client_id, started_at, ended_at, active_s, inactive_s, source, note, approval_status)
                 VALUES (?, ?, ?, ?, ?, 0, "manual", ?, ?)',
                [$targetUserId, ($clientIds[$i] ?? null) ?: null, $startUtc, $endUtc,
                 $activeS, substr($notes[$i] ?? '', 0, 1000), $status]
            );
            $created++;
        }
        if ($created) {
            recompute_overtime($targetUserId);   // split any of the new entries into regular/overtime
            flash($created . ($created === 1 ? ' entry' : ' entries')
                . ($status === 'pending' ? ' submitted for approval.' : ' added.')
                . ($skipped ? " ($skipped skipped — end not after start.)" : ''), 'success');
        } else {
            flash('No entries added — check that each end time is after its start.', 'error');
        }
        redirect('/app/timesheets');
    }

    [$start, $end, $period, $periodDate] = period_range_from_request('week', ['day', 'week', 'pay', 'month']);
    $ids = visible_user_ids($u);
    $sessions = sessions_for_users($ids, $start, $end, false);   // all statuses
    // A client portal only sees time logged against its own engagement — not the
    // agent's work for other clients (-1 = login not linked to a client → nothing).
    $clientFilter = billing_client_filter($u);
    if ($clientFilter !== null) {
        $sessions = array_values(array_filter($sessions,
            fn($s) => (int) $s['client_id'] === $clientFilter));
    }
    $usersById = users_by_id((int) $u['org_id']);
    $clients = db_all('SELECT id, name FROM clients WHERE org_id = ? AND archived = 0 ORDER BY name',
        [$u['org_id']]);

    view('dashboard/timesheets', array_merge(nav_context($u), [
        'title' => 'Timesheets', 'active' => 'timesheets', 'period' => $period, 'period_date' => $periodDate,
        'sessions' => $sessions, 'users_by_id' => $usersById, 'clients' => $clients,
        'can_log' => $u['role'] !== 'client_viewer',
    ]));
}

// ─────────────────────────── Approvals (manager) ───────────────────────────

function dash_approvals(): void
{
    $u = require_cap('approve_time');
    $ids = visible_user_ids($u);   // team-scoped for team managers
    $in = implode(',', array_fill(0, count($ids), '?'));
    $pending = db_all(
        "SELECT * FROM sessions WHERE user_id IN ($in) AND approval_status = 'pending'
         ORDER BY started_at DESC",
        $ids
    );
    view('dashboard/approvals', array_merge(nav_context($u), [
        'title' => 'Approvals', 'active' => 'approvals',
        'pending' => $pending, 'users_by_id' => users_by_id((int) $u['org_id']),
    ]));
}

/** POST /app/approvals/{id} — approve or reject a manual entry. */
function dash_approval_action(array $p): void
{
    $u = require_cap('approve_time');
    check_csrf();
    $sess = db_one('SELECT * FROM sessions WHERE id = ?', [$p['id']]);
    if (!$sess || !in_array((int) $sess['user_id'], visible_user_ids($u), true)) {
        abort(404, 'Entry not found');
    }
    $decision = ($_POST['decision'] ?? '') === 'approve' ? 'approved' : 'rejected';
    db_exec(
        'UPDATE sessions SET approval_status = ?, reviewed_by_id = ?, reviewed_at = NOW(), review_note = ?
         WHERE id = ?',
        [$decision, $u['id'], substr($_POST['review_note'] ?? '', 0, 1000), $sess['id']]
    );
    flash("Entry {$decision}.", 'success');
    redirect('/app/approvals');
}

// ─────────────────────────── Overtime approvals (HR) ───────────────────────────

/** GET /app/overtime — sessions whose overtime portion awaits HR sign-off. */
function dash_overtime(): void
{
    $u = require_cap('approve_overtime');
    $ids = visible_user_ids($u);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $pending = db_all(
        "SELECT * FROM sessions WHERE user_id IN ($in) AND overtime_status = 'pending'
         ORDER BY started_at DESC",
        $ids
    );
    view('dashboard/overtime', array_merge(nav_context($u), [
        'title' => 'Overtime', 'active' => 'overtime',
        'pending' => $pending, 'users_by_id' => users_by_id((int) $u['org_id']),
    ]));
}

/** POST /app/overtime/{id} — HR approves or rejects a session's overtime. Only
 *  approved overtime is credited to payroll (see creditable_active_s()). */
function dash_overtime_action(array $p): void
{
    $u = require_cap('approve_overtime');
    check_csrf();
    $sess = db_one('SELECT * FROM sessions WHERE id = ?', [$p['id']]);
    if (!$sess || !in_array((int) $sess['user_id'], visible_user_ids($u), true)) {
        abort(404, 'Entry not found');
    }
    $decision = ($_POST['decision'] ?? '') === 'approve' ? 'approved' : 'rejected';
    db_exec(
        'UPDATE sessions SET overtime_status = ?, overtime_reviewed_by_id = ?,
                overtime_reviewed_at = NOW(), overtime_review_note = ?
         WHERE id = ?',
        [$decision, $u['id'], substr($_POST['review_note'] ?? '', 0, 1000), $sess['id']]
    );
    flash("Overtime {$decision}.", 'success');
    redirect('/app/overtime');
}

// ─────────────────────────── Live team view (manager) ───────────────────────────

function dash_live(): void
{
    $u = require_cap('live');
    view('dashboard/live', array_merge(nav_context($u), [
        'title' => 'Live', 'active' => 'live',
    ]));
}

/** Build a live-agent card from an open session (latest window/activity/screenshot
 *  + today's totals). */
function live_agent_card(array $live, bool $canShots, string $dayStart, string $dayEnd): array
{
    $w = db_one('SELECT app_name, window_title FROM window_events
                 WHERE session_id = ? ORDER BY ts DESC LIMIT 1', [$live['id']]);
    $a = db_one('SELECT activity_pct FROM activity_samples
                 WHERE session_id = ? ORDER BY ts DESC LIMIT 1', [$live['id']]);
    $shot = null;
    if ($canShots) {
        $sc = db_one('SELECT file_path FROM screenshots
                      WHERE session_id = ? ORDER BY ts DESC LIMIT 1', [$live['id']]);
        $shot = $sc ? url('/uploads/' . $sc['file_path']) : null;
    }
    $sum = summarize(sessions_for_users([(int) $live['user_id']], $dayStart, $dayEnd, false));
    return [
        'user_id'      => (int) $live['user_id'],
        'name'         => $live['user_name'],
        'app'          => $w['app_name'] ?? '',
        'title'        => $w['window_title'] ?? '',
        'activity'     => (int) ($a['activity_pct'] ?? 0),
        'since'        => $live['started_at'],
        'client'       => $live['client_name'] ?: 'No client / direct',
        'screenshot'   => $shot,
        'today_active' => fmt_hms($sum['active_s']),
        'today_pct'    => $sum['activity_pct'],
    ];
}

/** First team name (alphabetical) for each given user id. */
function live_team_by_user(array $ids): array
{
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $map = [];
    foreach (db_all("SELECT tm.user_id, t.name FROM team_members tm
                     JOIN teams t ON t.id = tm.team_id
                     WHERE tm.user_id IN ($in) ORDER BY t.name", $ids) as $r) {
        $map[$r['user_id']] = $map[$r['user_id']] ?? $r['name'];
    }
    return $map;
}

/** GET /app/live/data — JSON snapshot of current activity (polled). Super admins
 *  get every live agent across all orgs (org → team → client); everyone else gets
 *  their visible team grouped client → team. */
function dash_live_data(): void
{
    $u = require_cap('live');
    close_stale_sessions();   // so "live now" doesn't show offline/crashed agents
    [$dayStart, $dayEnd] = period_range('day');
    $canShots = can($u, 'screenshots');

    if ($u['role'] === 'super_admin' && empty($_SESSION['act_org'])) {
        dash_live_data_platform($canShots, $dayStart, $dayEnd);
        return;
    }

    $ids = visible_user_ids($u);
    if (!$ids) {
        json_out(['now' => gmdate('c'), 'mode' => 'team', 'groups' => [], 'total' => 0]);
        return;
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $open = db_all(
        "SELECT s.*, u.name AS user_name, c.name AS client_name FROM sessions s
         JOIN users u ON u.id = s.user_id
         LEFT JOIN clients c ON c.id = s.client_id
         WHERE s.user_id IN ($in) AND s.ended_at IS NULL ORDER BY u.name", $ids);
    if (!$open) {
        json_out(['now' => gmdate('c'), 'mode' => 'team', 'groups' => [], 'total' => 0]);
        return;
    }
    $teamByUser = live_team_by_user($ids);

    // groups[client][team] = [card, …]
    $groups = [];
    $total = 0;
    foreach ($open as $live) {
        $card = live_agent_card($live, $canShots, $dayStart, $dayEnd);
        $team = $teamByUser[$card['user_id']] ?? 'No team';
        $groups[$card['client']][$team][] = $card;
        $total++;
    }
    ksort($groups);
    $out = [];
    foreach ($groups as $clientName => $teams) {
        ksort($teams);
        $teamList = [];
        $count = 0;
        foreach ($teams as $teamName => $members) {
            $teamList[] = ['team' => $teamName, 'members' => $members];
            $count += count($members);
        }
        $out[] = ['client' => $clientName, 'count' => $count, 'teams' => $teamList];
    }
    json_out(['now' => gmdate('c'), 'mode' => 'team', 'groups' => $out, 'total' => $total]);
}

/** Super-admin live feed: every live agent across every organization, grouped
 *  Organization → Team → Client. */
function dash_live_data_platform(bool $canShots, string $dayStart, string $dayEnd): void
{
    $open = db_all(
        "SELECT s.*, u.name AS user_name, o.name AS org_name, c.name AS client_name
         FROM sessions s
         JOIN users u ON u.id = s.user_id
         JOIN organizations o ON o.id = u.org_id
         LEFT JOIN clients c ON c.id = s.client_id
         WHERE s.ended_at IS NULL AND u.role <> 'super_admin'
         ORDER BY o.name, u.name");
    if (!$open) {
        json_out(['now' => gmdate('c'), 'mode' => 'platform', 'orgs' => [], 'total' => 0]);
        return;
    }
    $teamByUser = live_team_by_user(array_map(fn($r) => (int) $r['user_id'], $open));

    // tree[org][team][client] = [card, …]
    $tree = [];
    $total = 0;
    foreach ($open as $live) {
        $card = live_agent_card($live, $canShots, $dayStart, $dayEnd);
        $team = $teamByUser[$card['user_id']] ?? 'No team';
        $tree[$live['org_name']][$team][$card['client']][] = $card;
        $total++;
    }
    ksort($tree);
    $orgs = [];
    foreach ($tree as $orgName => $teams) {
        ksort($teams);
        $teamList = [];
        $orgCount = 0;
        foreach ($teams as $teamName => $clients) {
            ksort($clients);
            $clientList = [];
            $teamCount = 0;
            foreach ($clients as $clientName => $members) {
                $clientList[] = ['client' => $clientName, 'members' => $members];
                $teamCount += count($members);
            }
            $teamList[] = ['team' => $teamName, 'count' => $teamCount, 'clients' => $clientList];
            $orgCount += $teamCount;
        }
        $orgs[] = ['org' => $orgName, 'count' => $orgCount, 'teams' => $teamList];
    }
    json_out(['now' => gmdate('c'), 'mode' => 'platform', 'orgs' => $orgs, 'total' => $total]);
}

// ─────────────────────────── Session detail ───────────────────────────

function dash_session_detail(array $p): void
{
    $u = require_login();
    $sess = db_one('SELECT * FROM sessions WHERE id = ?', [(int) $p['id']]);
    if (!$sess || !in_array((int) $sess['user_id'], visible_user_ids($u), true)) {
        // Out of scope / gone (e.g. after switching accounts) — back to Timesheets,
        // not a hard error page.
        flash('That session is not available.', 'error');
        redirect('/app/timesheets');
    }
    $samples = db_all('SELECT * FROM activity_samples WHERE session_id = ? ORDER BY ts', [$sess['id']]);
    $windowsAgg = db_all(
        'SELECT app_name, window_title, SUM(focus_seconds) secs FROM window_events
         WHERE session_id = ? GROUP BY app_name, window_title ORDER BY secs DESC',
        [$sess['id']]
    );
    $windowTimeline = db_all('SELECT * FROM window_events WHERE session_id = ? ORDER BY ts', [$sess['id']]);
    $procs = db_all('SELECT DISTINCT app_name FROM process_snapshots WHERE session_id = ? ORDER BY app_name',
        [$sess['id']]);
    $idles = db_all('SELECT * FROM idle_periods WHERE session_id = ? ORDER BY start_ts', [$sess['id']]);
    $shots = db_all('SELECT * FROM screenshots WHERE session_id = ? ORDER BY ts', [$sess['id']]);
    $owner = db_one('SELECT name FROM users WHERE id = ?', [$sess['user_id']]);

    view('dashboard/session', array_merge(nav_context($u), [
        'title' => 'Session detail', 'active' => 'timesheets',
        'sess' => $sess, 'owner' => $owner, 'samples' => $samples,
        'windows_agg' => $windowsAgg, 'window_timeline' => $windowTimeline,
        'procs' => $procs, 'idles' => $idles, 'shots' => $shots,
    ]));
}

// ─────────────────────────── Screenshots gallery ───────────────────────────

function dash_screenshots(): void
{
    $u = require_cap('screenshots');
    $ids = visible_user_ids($u);

    // Optional filters: ?date=YYYY-MM-DD (a local day) and ?user_id=N (managers).
    // $ids stays the FULL visible scope — it's what the dropdown is built from.
    // Narrowing it before building the dropdown used to leave the filtered person
    // as the only selectable option, so you had to hit Clear to switch people.
    $filterUser = (int) ($_GET['user_id'] ?? 0);
    $queryIds = ($filterUser && in_array($filterUser, $ids, true)) ? [$filterUser] : $ids;

    $qin = implode(',', array_fill(0, count($queryIds), '?'));
    $params = $queryIds;
    $where = "s.user_id IN ($qin)";
    $date = $_GET['date'] ?? '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        // A calendar day in the ORG's reporting timezone, converted to the UTC
        // instants actually stored in screenshots.ts.
        $tz = report_tz();
        $where .= " AND sc.ts >= ? AND sc.ts < ?";
        $params[] = local_to_utc($date . ' 00:00:00', $tz);
        $params[] = local_to_utc(date_add_days($date, 1) . ' 00:00:00', $tz);
    }
    $shots = db_all(
        "SELECT sc.*, s.user_id, us.name AS user_name FROM screenshots sc
         JOIN sessions s ON s.id = sc.session_id JOIN users us ON us.id = s.user_id
         WHERE $where ORDER BY sc.ts DESC LIMIT 200",
        $params
    );
    // Filter dropdown = only the people this viewer may actually see (their own
    // visible scope), NOT the whole org. Matters for a client_viewer (which holds
    // view_team, so is_manager() is true) and scopes a manager to their team too.
    $in = implode(',', array_fill(0, count($ids), '?'));
    $members = is_manager($u)
        ? db_all("SELECT id, name FROM users WHERE id IN ($in) ORDER BY name", $ids) : [];
    view('dashboard/screenshots', array_merge(nav_context($u), [
        'title' => 'Screenshots', 'active' => 'screenshots', 'shots' => $shots,
        'members' => $members, 'filter_user' => $filterUser, 'filter_date' => $date,
    ]));
}

// ─────────────────────────── Team ───────────────────────────

function dash_team(): void
{
    $u = require_cap('view_all');
    // Platform operator: a read-only, per-organization overview of every team and
    // the agents under it (org management still happens inside each org / act-as).
    if ($u['role'] === 'super_admin' && empty($_SESSION['act_org'])) {
        dash_platform_teams($u);
        return;
    }
    $canManage = can($u, 'users_manage');
    $canProfiles = can($u, 'profiles_manage');
    $ASSIGNABLE = ['member', 'manager', 'hr_manager', 'it_admin', 'client_admin', 'client_viewer'];
    // Client (read-only portal) logins are NOT created here — they're provisioned on
    // the Clients page when a client is added. Keep them out of the create dropdown.
    $CREATABLE = array_values(array_diff($ASSIGNABLE, ['client_viewer']));
    if (request_method() === 'POST') {
        check_csrf();
        $action = $_POST['action'] ?? '';
        $target = db_one('SELECT id FROM users WHERE id = ? AND org_id = ?',
            [(int) ($_POST['user_id'] ?? 0), $u['org_id']]);
        // Save a member's standard work schedule (HR/admin) when the form posts it.
        $saveSchedule = function ($targetId) {
            if (!isset($_POST['work_start']) && !isset($_POST['work_days'])) {
                return;
            }
            $wd = implode(',', array_values(array_intersect(['1', '2', '3', '4', '5', '6', '7'],
                array_map('strval', (array) ($_POST['work_days'] ?? [])))));
            $ws = preg_match('/^\d{2}:\d{2}$/', $_POST['work_start'] ?? '') ? $_POST['work_start'] . ':00' : null;
            $we = preg_match('/^\d{2}:\d{2}$/', $_POST['work_end'] ?? '') ? $_POST['work_end'] . ':00' : null;
            db_exec('UPDATE users SET work_start = ?, work_end = ?, work_days = ? WHERE id = ?',
                [$ws, $we, $wd ?: '1,2,3,4,5', (int) $targetId]);
        };
        // Save employment type + contractual hour caps when the form posts them.
        $saveEmployment = function ($targetId) {
            if (!isset($_POST['employment_type'])) {
                return;
            }
            $type = array_key_exists($_POST['employment_type'], employment_types())
                ? $_POST['employment_type'] : 'full_time';
            $hired = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['hired_on'] ?? '') ? $_POST['hired_on'] : null;
            $cap = fn ($k) => max(0.0, min(999.0, (float) ($_POST[$k] ?? 0)));
            db_exec('UPDATE users SET employment_type = ?, hired_on = ?, daily_hours_cap = ?,
                            weekly_hours_cap = ?, period_hours_cap = ? WHERE id = ?',
                [$type, $hired, $cap('daily_hours_cap'), $cap('weekly_hours_cap'),
                 $cap('period_hours_cap'), (int) $targetId]);
        };

        if ($action === 'update_profile') {
            // HR (or admin) edits a member's profile fields. HR/admin may also set the
            // member's pay rate (capability set_pay_rate) — but never the bill rate.
            if (!$canProfiles) {
                abort(403, 'Not allowed');
            }
            $email = strtolower(trim($_POST['email'] ?? ''));
            if ($target && $email && filter_var($email, FILTER_VALIDATE_EMAIL)
                && !db_one('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $target['id']])) {
                db_exec('UPDATE users SET name = ?, email = ?, phone = ?, job_title = ? WHERE id = ?',
                    [trim($_POST['name'] ?? ''), $email, substr($_POST['phone'] ?? '', 0, 40),
                     substr($_POST['job_title'] ?? '', 0, 120), $target['id']]);
                if (can($u, 'set_pay_rate') && isset($_POST['pay_rate'])) {
                    db_exec('UPDATE users SET pay_type = ?, pay_rate = ?, currency = ? WHERE id = ?',
                        [($_POST['pay_type'] ?? 'hourly') === 'monthly' ? 'monthly' : 'hourly',
                         (float) ($_POST['pay_rate'] ?? 0), substr($_POST['currency'] ?? 'USD', 0, 8), $target['id']]);
                }
                $saveSchedule($target['id']);
                $saveEmployment($target['id']);
                flash('Profile updated.', 'success');
            } else {
                flash('Could not update profile (missing or duplicate email).', 'error');
            }
            redirect('/app/team');
        }

        if (!$canManage) {
            abort(403, 'You cannot manage team members.');
        }
        if ($action === 'create_user') {
            $email = strtolower(trim($_POST['email'] ?? ''));
            // Solo is a one-user plan. Enforce it here rather than only advertising it.
            $seatCap = plan_limits((int) $u['org_id'])['max_seats'];
            if ($seatCap > 0 && org_seat_count((int) $u['org_id']) >= $seatCap) {
                flash('The free Solo plan covers one user. Upgrade to add your team.', 'error');
                redirect('/app/subscription');
            }
            if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)
                && !db_one('SELECT id FROM users WHERE email = ?', [$email])) {
                $newId = db_exec(
                    'INSERT INTO users (org_id, name, email, password_hash, role, pay_type, pay_rate,
                                        bill_type, bill_rate, currency)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$u['org_id'], trim($_POST['name'] ?? 'New User'), $email,
                     password_hash($_POST['password'] ?: random_token(10), PASSWORD_DEFAULT),
                     in_array($_POST['role'] ?? '', $CREATABLE, true) ? $_POST['role'] : 'member',
                     ($_POST['pay_type'] ?? 'hourly') === 'monthly' ? 'monthly' : 'hourly',
                     (float) ($_POST['pay_rate'] ?? 0),
                     ($_POST['bill_type'] ?? 'hourly') === 'monthly' ? 'monthly' : 'hourly',
                     (float) ($_POST['bill_rate'] ?? 0),
                     substr($_POST['currency'] ?? 'USD', 0, 8)]
                );
                if ($newId) {
                    $saveSchedule($newId);
                    $saveEmployment($newId);
                }
                flash('Team member created.', 'success');
            } else {
                flash('Could not create member (missing or duplicate email).', 'error');
            }
        } elseif ($action === 'update_user') {
            if ($target) {
                $role = in_array($_POST['role'] ?? '', $ASSIGNABLE, true) ? $_POST['role'] : 'member';
                $roleSql = is_admin($u) ? ', role = ?' : '';   // only admins change roles
                // bill_type was rendered on the form but never written — a monthly
                // service charge could not actually be set from the UI.
                $params = [($_POST['pay_type'] ?? 'hourly') === 'monthly' ? 'monthly' : 'hourly',
                           (float) ($_POST['pay_rate'] ?? 0),
                           ($_POST['bill_type'] ?? 'hourly') === 'monthly' ? 'monthly' : 'hourly',
                           (float) ($_POST['bill_rate'] ?? 0),
                           substr($_POST['currency'] ?? 'USD', 0, 8)];
                if ($roleSql) { $params[] = $role; }
                $params[] = $target['id'];
                db_exec("UPDATE users SET pay_type = ?, pay_rate = ?, bill_type = ?, bill_rate = ?, currency = ?{$roleSql} WHERE id = ?",
                    $params);
                $saveSchedule($target['id']);
                $saveEmployment($target['id']);
                flash('Member updated.', 'success');
            }
        } elseif ($action === 'create_team') {
            db_exec('INSERT INTO teams (org_id, name) VALUES (?, ?)',
                [$u['org_id'], trim($_POST['team_name'] ?? 'New Team')]);
            flash('Team created.', 'success');
        } elseif ($action === 'add_team_member') {
            $team = db_one('SELECT id FROM teams WHERE id = ? AND org_id = ?',
                [(int) ($_POST['team_id'] ?? 0), $u['org_id']]);
            if ($team && $target
                && !db_one('SELECT id FROM team_members WHERE team_id = ? AND user_id = ?', [$team['id'], $target['id']])) {
                db_exec('INSERT INTO team_members (team_id, user_id) VALUES (?, ?)', [$team['id'], $target['id']]);
                flash('Added to team.', 'success');
            }
        } elseif ($action === 'remove_team_member') {
            db_exec('DELETE tm FROM team_members tm JOIN teams t ON t.id = tm.team_id
                     WHERE tm.id = ? AND t.org_id = ?', [(int) ($_POST['tm_id'] ?? 0), $u['org_id']]);
            flash('Removed from team.', 'success');
        }
        redirect('/app/team');
    }

    [$start, $end, $period, $periodDate] = period_range_from_request('week', ['week', 'pay', 'month']);
    ensure_personal_links((int) $u['org_id']);
    $personalLinks = personal_links_map((int) $u['org_id']);
    $usersById = users_by_id((int) $u['org_id']);
    $ids = array_keys($usersById);
    $sessions = sessions_for_users($ids, $start, $end);
    // Per-member rollup.
    $perUser = [];
    foreach ($ids as $id) {
        $perUser[$id] = ['active_s' => 0, 'inactive_s' => 0];
    }
    foreach ($sessions as $s) {
        $perUser[$s['user_id']]['active_s'] += (int) $s['active_s'];
        $perUser[$s['user_id']]['inactive_s'] += (int) $s['inactive_s'];
    }
    $teams = db_all('SELECT * FROM teams WHERE org_id = ? ORDER BY name', [$u['org_id']]);
    // Team membership map: team_id => [{tm_id, user_id, name}]
    $teamMembers = [];
    foreach (db_all(
        'SELECT tm.id tm_id, tm.team_id, tm.user_id, us.name FROM team_members tm
         JOIN teams t ON t.id = tm.team_id JOIN users us ON us.id = tm.user_id
         WHERE t.org_id = ? ORDER BY us.name', [$u['org_id']]) as $r) {
        $teamMembers[$r['team_id']][] = $r;
    }

    // Chart series: team-wide hours per day (work hours vertical, days horizontal).
    $daily = daily_series($sessions, $start, $end);

    view('dashboard/team', array_merge(nav_context($u), [
        'title' => 'Team', 'active' => 'team', 'period' => $period, 'period_date' => $periodDate,
        'daily' => $daily,
        'users_by_id' => $usersById, 'per_user' => $perUser, 'teams' => $teams,
        'personal_links' => $personalLinks,
        'public_base' => public_base_url(), 'team_members' => $teamMembers,
        'can_manage' => $canManage, 'can_profiles' => $canProfiles,
        'can_rates' => can($u, 'view_rates'), 'can_set_pay' => can($u, 'set_pay_rate'),
        'assignable' => $ASSIGNABLE, 'creatable' => $CREATABLE,
    ]));
}

// ─────────────────────────── Clients & contracts (manager/admin) ───────────────────────────

/**
 * Give a client a read-only portal login (role client_viewer) with a temporary
 * password they must change on first sign-in. Links the new user to the client via
 * clients.user_id. Returns [ok, message-for-the-admin].
 */
function create_client_login(array $u, int $clientId, string $name, string $email): array
{
    $email = strtolower(trim($email));
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [false, 'No login account was created — add a valid contact email first.'];
    }
    if (db_one('SELECT id FROM users WHERE email = ?', [$email])) {
        return [false, 'No login account was created — that email is already registered.'];
    }
    $temp = random_token(9);
    $uid = db_exec(
        'INSERT INTO users (org_id, name, email, password_hash, role, currency, must_change_password)
         VALUES (?, ?, ?, ?, ?, ?, 1)',
        [(int) $u['org_id'], substr($name ?: 'Client', 0, 120), $email,
         password_hash($temp, PASSWORD_DEFAULT), 'client_viewer', 'USD']
    );
    db_exec('UPDATE clients SET user_id = ? WHERE id = ? AND org_id = ?', [$uid, $clientId, $u['org_id']]);
    return [true, "Login created for {$email} — temporary password: {$temp} "
        . "(they'll be asked to change it on first login)."];
}

function dash_clients(): void
{
    $u = require_cap('clients_manage');
    // Platform operator: a read-only, per-organization overview of every client and
    // the agents working under it.
    if ($u['role'] === 'super_admin' && empty($_SESSION['act_org'])) {
        dash_platform_clients($u);
        return;
    }
    if (request_method() === 'POST') {
        check_csrf();
        $action = $_POST['action'] ?? '';
        $ownClient = fn(int $cid) => $cid > 0 && db_one('SELECT id FROM clients WHERE id = ? AND org_id = ?', [$cid, $u['org_id']]);
        $ownContract = fn(int $id) => $id > 0 && db_one('SELECT id FROM contracts WHERE id = ? AND org_id = ?', [$id, $u['org_id']]);
        $status = fn() => ($_POST['status'] ?? 'active') === 'ended' ? 'ended' : 'active';

        // Standard/quoted bill rate for a client or contract — reference only; the
        // actual per-agent billing math in compute_billing() is untouched.
        $billRate = fn() => max(0.0, round((float) ($_POST['bill_rate'] ?? 0), 2));
        $rateCurrency = fn() => strtoupper(substr(trim($_POST['currency'] ?? 'USD'), 0, 8)) ?: 'USD';

        if ($action === 'add_client') {
            $name = trim($_POST['name'] ?? '');
            $email = strtolower(trim($_POST['contact_email'] ?? ''));
            if ($name !== '') {
                $cid = db_exec('INSERT INTO clients (org_id, name, contact_email, notes, bill_rate, currency) VALUES (?, ?, ?, ?, ?, ?)',
                    [$u['org_id'], substr($name, 0, 200), substr($email, 0, 190),
                     substr($_POST['notes'] ?? '', 0, 2000), $billRate(), $rateCurrency()]);
                // Treat the client as a user: give them a read-only portal login with a
                // temporary password they must change on first sign-in (when an email is given).
                if ($email !== '') {
                    [$ok, $msg] = create_client_login($u, $cid, $name, $email);
                    flash('Client added.' . ($msg ? ' ' . $msg : ''), 'success');
                } else {
                    flash('Client added. Add a contact email to give them a portal login.', 'success');
                }
            }
        } elseif ($action === 'create_client_login') {
            $cid = (int) ($_POST['client_id'] ?? 0);
            $c = $ownClient($cid) ? db_one('SELECT * FROM clients WHERE id = ?', [$cid]) : null;
            if ($c && empty($c['user_id'])) {
                [$ok, $msg] = create_client_login($u, $cid, $c['name'], strtolower(trim($_POST['contact_email'] ?? $c['contact_email'])));
                flash($msg ?: 'Could not create a login for this client.', $ok ? 'success' : 'error');
            }
        } elseif ($action === 'reset_client_password') {
            $cid = (int) ($_POST['client_id'] ?? 0);
            $c = $ownClient($cid) ? db_one('SELECT * FROM clients WHERE id = ?', [$cid]) : null;
            if ($c && !empty($c['user_id'])) {
                $temp = random_token(9);
                db_exec("UPDATE users SET password_hash = ?, must_change_password = 1
                         WHERE id = ? AND org_id = ? AND role = 'client_viewer'",
                    [password_hash($temp, PASSWORD_DEFAULT), $c['user_id'], $u['org_id']]);
                flash("Temporary password reset — share it with the client: {$temp} "
                    . "(they'll be asked to change it at next login).", 'success');
            }
        } elseif ($action === 'update_client') {
            $cid = (int) ($_POST['client_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            if ($name !== '' && $ownClient($cid)) {
                db_exec('UPDATE clients SET name = ?, contact_email = ?, notes = ?, bill_rate = ?, currency = ? WHERE id = ? AND org_id = ?',
                    [substr($name, 0, 200), substr($_POST['contact_email'] ?? '', 0, 190),
                     substr($_POST['notes'] ?? '', 0, 2000), $billRate(), $rateCurrency(), $cid, $u['org_id']]);
                flash('Client updated.', 'success');
            }
        } elseif ($action === 'delete_client') {
            $cid = (int) ($_POST['client_id'] ?? 0);
            if ($ownClient($cid)) {
                // Keep historical time, just detach the client tag; contracts and
                // agent assignments cascade away via their foreign keys.
                $row = db_one('SELECT user_id FROM clients WHERE id = ?', [$cid]);
                db_exec('UPDATE sessions SET client_id = NULL WHERE client_id = ?', [$cid]);
                db_exec('UPDATE tasks SET client_id = NULL WHERE client_id = ?', [$cid]);
                db_exec('DELETE FROM clients WHERE id = ? AND org_id = ?', [$cid, $u['org_id']]);
                // Remove the client's portal login along with the client.
                if (!empty($row['user_id'])) {
                    db_exec("DELETE FROM users WHERE id = ? AND org_id = ? AND role = 'client_viewer'",
                        [$row['user_id'], $u['org_id']]);
                }
                flash('Client deleted.', 'success');
            }
        } elseif ($action === 'archive_client' || $action === 'unarchive_client') {
            $arch = $action === 'archive_client' ? 1 : 0;
            db_exec('UPDATE clients SET archived = ? WHERE id = ? AND org_id = ?',
                [$arch, (int) ($_POST['client_id'] ?? 0), $u['org_id']]);
            flash($arch ? 'Client archived.' : 'Client restored.', 'success');
        } elseif ($action === 'add_contract') {
            $cid = (int) ($_POST['client_id'] ?? 0);
            if ($ownClient($cid)) {
                // The contract's bill rate is a quoted/reference figure for this
                // engagement (defaults to the client's standard rate if left blank).
                // Actual billing still rolls up from each agent's own bill rate —
                // see compute_billing().
                $client = db_one('SELECT bill_rate, currency FROM clients WHERE id = ?', [$cid]);
                $rate = ($_POST['bill_rate'] ?? '') !== '' ? $billRate() : (float) ($client['bill_rate'] ?? 0);
                $curr = trim((string) ($_POST['currency'] ?? '')) !== '' ? $rateCurrency() : ($client['currency'] ?? 'USD');
                db_exec(
                    'INSERT INTO contracts (org_id, client_id, title, bill_rate, currency, status, start_date, end_date)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$u['org_id'], $cid, substr($_POST['title'] ?? 'Contract', 0, 200), $rate, $curr, $status(),
                     ($_POST['start_date'] ?? null) ?: null, ($_POST['end_date'] ?? null) ?: null]
                );
                flash('Contract added.', 'success');
            }
        } elseif ($action === 'update_contract') {
            $ctid = (int) ($_POST['contract_id'] ?? 0);
            if ($ownContract($ctid)) {
                db_exec('UPDATE contracts SET title = ?, bill_rate = ?, status = ?, start_date = ?, end_date = ? WHERE id = ? AND org_id = ?',
                    [substr($_POST['title'] ?? 'Contract', 0, 200), $billRate(), $status(),
                     ($_POST['start_date'] ?? null) ?: null, ($_POST['end_date'] ?? null) ?: null,
                     $ctid, $u['org_id']]);
                flash('Contract updated.', 'success');
            }
        } elseif ($action === 'delete_contract') {
            $ctid = (int) ($_POST['contract_id'] ?? 0);
            if ($ownContract($ctid)) {
                db_exec('DELETE FROM contracts WHERE id = ? AND org_id = ?', [$ctid, $u['org_id']]);
                flash('Contract deleted.', 'success');
            }
        }
        redirect('/app/clients');
    }

    $clients = db_all('SELECT * FROM clients WHERE org_id = ? ORDER BY archived, name', [$u['org_id']]);
    // Map client login accounts: client_id => login email (client_viewer users).
    $clientLogins = [];
    $loginIds = array_filter(array_map(fn($c) => (int) ($c['user_id'] ?? 0), $clients));
    if ($loginIds) {
        $in = implode(',', array_fill(0, count($loginIds), '?'));
        $byUser = [];
        foreach (db_all("SELECT id, email FROM users WHERE id IN ($in)", array_values($loginIds)) as $r) {
            $byUser[(int) $r['id']] = $r['email'];
        }
        foreach ($clients as $c) {
            if (!empty($c['user_id']) && isset($byUser[(int) $c['user_id']])) {
                $clientLogins[(int) $c['id']] = $byUser[(int) $c['user_id']];
            }
        }
    }
    $contracts = db_all('SELECT * FROM contracts WHERE org_id = ? ORDER BY created_at DESC', [$u['org_id']]);
    // Time + billable amount per client (active hours x the worker's bill rate).
    $rollup = [];
    foreach (db_all(
        'SELECT s.client_id, SUM(s.active_s) secs,
                SUM(s.active_s / 3600 * u.bill_rate) amount
         FROM sessions s JOIN users u ON u.id = s.user_id
         WHERE u.org_id = ? AND s.client_id IS NOT NULL AND s.approval_status = "approved"
         GROUP BY s.client_id', [$u['org_id']]) as $r) {
        $rollup[$r['client_id']] = ['secs' => (int) $r['secs'], 'amount' => (float) $r['amount']];
    }

    view('dashboard/clients', array_merge(nav_context($u), [
        'title' => 'Clients', 'active' => 'clients', 'clients' => $clients,
        'contracts' => $contracts, 'rollup' => $rollup, 'client_logins' => $clientLogins,
    ]));
}

// ─────────────────────────── Contracts (company admin / HR admin) ───────────────────────────

/**
 * First-class Contracts page: an org-wide list of every contract (across all
 * clients) with its assigned member roster. Contract terms/rates/status are
 * still edited from the Clients page (nested contract CRUD, unchanged); this
 * page is specifically for assigning/removing the team members who work under
 * each contract, per the 'contracts_manage' capability (company admin + HR admin).
 */
function dash_contracts(): void
{
    $u = require_cap('contracts_manage');

    if (request_method() === 'POST') {
        check_csrf();
        $action = $_POST['action'] ?? '';
        $ownContract = fn(int $id) => $id > 0 && db_one('SELECT id FROM contracts WHERE id = ? AND org_id = ?', [$id, $u['org_id']]);
        $ownUser = fn(int $id) => $id > 0 && db_one('SELECT id FROM users WHERE id = ? AND org_id = ?', [$id, $u['org_id']]);
        $ctid = (int) ($_POST['contract_id'] ?? 0);
        $uid = (int) ($_POST['user_id'] ?? 0);

        if ($action === 'assign_member') {
            if ($ownContract($ctid) && $ownUser($uid)) {
                db_exec('INSERT IGNORE INTO contract_members (contract_id, user_id) VALUES (?, ?)', [$ctid, $uid]);
                flash('Member assigned to the contract.', 'success');
            }
        } elseif ($action === 'unassign_member') {
            if ($ownContract($ctid)) {
                db_exec('DELETE FROM contract_members WHERE contract_id = ? AND user_id = ?', [$ctid, $uid]);
                flash('Member removed from the contract.', 'success');
            }
        }
        redirect('/app/contracts');
    }

    $contracts = db_all(
        'SELECT c.*, cl.name AS client_name FROM contracts c
         JOIN clients cl ON cl.id = c.client_id
         WHERE c.org_id = ? ORDER BY (c.status = "ended"), cl.name, c.title', [$u['org_id']]);

    $membersByContract = [];
    foreach (db_all(
        'SELECT cm.contract_id, cm.user_id, us.name, us.email FROM contract_members cm
         JOIN contracts c ON c.id = cm.contract_id
         JOIN users us ON us.id = cm.user_id
         WHERE c.org_id = ? ORDER BY us.name', [$u['org_id']]) as $r) {
        $membersByContract[$r['contract_id']][] = $r;
    }

    view('dashboard/contracts', array_merge(nav_context($u), [
        'title' => 'Contracts', 'active' => 'contracts', 'contracts' => $contracts,
        'members_by_contract' => $membersByContract, 'users_by_id' => users_by_id((int) $u['org_id']),
    ]));
}

// ─────────────────────────── Billing (manager/admin) ───────────────────────────

/**
 * Compute billing for a period: per agent and per customer (client).
 *
 * Hourly agents are billed active_hours x bill_rate. Monthly agents incur a flat
 * monthly service charge (bill_rate), prorated to the selected period and, for
 * the per-customer view, distributed across clients in proportion to where they
 * logged time (falling to "Unassigned" if they logged none).
 */
function compute_billing(array $u, string $start, string $end, ?int $clientFilter = null): array
{
    // Scope to the agents this viewer may see (whole org for admins; the client's own
    // agents for a client portal login).
    $ids = visible_user_ids($u);
    $usersById = array_intersect_key(users_by_id((int) $u['org_id']), array_flip($ids));
    $sessions = sessions_for_users($ids, $start, $end);   // approved only

    $clientName = [];
    foreach (db_all('SELECT id, name FROM clients WHERE org_id = ?', [$u['org_id']]) as $c) {
        $clientName[$c['id']] = $c['name'];
    }

    // Proration factor for flat monthly charges.
    $days = max(1, (strtotime($end) - strtotime($start)) / 86400);
    $daysInMonth = (int) date('t', strtotime($start));
    $monthFactor = min(1.0, $days / $daysInMonth);

    // Aggregate seconds per agent and per (agent,client). When a client filter is set
    // (client portal), only that client's time is billed; the agent's total time is
    // still tracked separately so a monthly agent's flat charge can be prorated to the
    // share of time spent on this client.
    $agentTotalSecs = [];
    $agentSecs = [];
    $agentClientSecs = [];
    foreach ($sessions as $s) {
        $uid = $s['user_id'];
        $cid = $s['client_id'] ?: 0;
        $agentTotalSecs[$uid] = ($agentTotalSecs[$uid] ?? 0) + (int) $s['active_s'];
        if ($clientFilter !== null && $cid !== $clientFilter) {
            continue;
        }
        $agentSecs[$uid] = ($agentSecs[$uid] ?? 0) + (int) $s['active_s'];
        $agentClientSecs[$uid][$cid] = ($agentClientSecs[$uid][$cid] ?? 0) + (int) $s['active_s'];
    }

    $byAgent = [];
    $byClient = [];
    $addClient = function ($cid, $secs, $amount) use (&$byClient, $clientName) {
        $key = $cid ?: 0;
        if (!isset($byClient[$key])) {
            $byClient[$key] = ['name' => $cid ? ($clientName[$cid] ?? 'Client') : 'Unassigned',
                               'secs' => 0, 'amount' => 0];
        }
        $byClient[$key]['secs'] += $secs;
        $byClient[$key]['amount'] += $amount;
    };

    foreach ($usersById as $uid => $usr) {
        $secs = $agentSecs[$uid] ?? 0;
        $monthly = ($usr['bill_type'] ?? 'hourly') === 'monthly';
        $rate = user_bill_rate($usr);
        if ($monthly) {
            $amount = $rate * $monthFactor;       // flat service charge, prorated
            // For a single-client view, charge only this client's share of the agent's time.
            if ($clientFilter !== null) {
                $total = $agentTotalSecs[$uid] ?? 0;
                $amount = $total > 0 ? $amount * ($secs / $total) : 0;
            }
        } else {
            $amount = $secs / 3600.0 * $rate;     // hourly
        }
        // Skip agents that produce nothing (no charge and no time).
        if ($amount <= 0 && $secs <= 0) {
            continue;
        }
        $byAgent[$uid] = ['name' => $usr['name'], 'secs' => $secs, 'rate' => $rate,
                          'bill_type' => $monthly ? 'monthly' : 'hourly',
                          'currency' => $usr['currency'], 'amount' => $amount];

        // Distribute to clients.
        $perClient = $agentClientSecs[$uid] ?? [];
        if (!$monthly) {
            foreach ($perClient as $cid => $cs) {
                $addClient($cid, $cs, $cs / 3600.0 * $rate);
            }
        } else {
            // Flat charge split proportionally to logged time, else Unassigned.
            if ($secs > 0) {
                foreach ($perClient as $cid => $cs) {
                    $addClient($cid, $cs, $amount * ($cs / $secs));
                }
            } else {
                $addClient(0, 0, $amount);
            }
        }
    }
    return ['by_agent' => $byAgent, 'by_client' => $byClient];
}

/** Resolve the client filter for billing: a client portal login is locked to its own
 *  client record (-1 = linked to none, so nothing is billed); everyone else sees all. */
function billing_client_filter(array $u): ?int
{
    if ($u['role'] !== 'client_viewer') {
        return null;
    }
    $c = client_for_viewer($u);
    return $c ? (int) $c['id'] : -1;
}

/**
 * Per-contract breakdown of a client's own billing for a period, for the client
 * portal's Billing page. Sessions are tagged by client, not by individual
 * contract (no session <-> contract link exists), so the client's total billed
 * hours/amount for the period (already computed by compute_billing(), scoped to
 * just this client) is split across its contracts in proportion to how many days
 * each contract overlaps the period — contracts with no dates set are treated as
 * in effect for the whole period. Rows always sum back to the client's total, so
 * this is a proration of the same figures shown above it, grouped by engagement,
 * not a second independent calculation.
 */
function compute_contract_billing(array $u, string $start, string $end, int $clientId): array
{
    if ($clientId <= 0) {
        return [];
    }
    $contracts = db_all(
        'SELECT * FROM contracts WHERE org_id = ? AND client_id = ? ORDER BY start_date, created_at',
        [$u['org_id'], $clientId]);
    if (!$contracts) {
        return [];
    }

    $totals = compute_billing($u, $start, $end, $clientId)['by_client'];
    $totalSecs = 0;
    $totalAmount = 0.0;
    foreach ($totals as $row) {
        $totalSecs += $row['secs'];
        $totalAmount += $row['amount'];
    }

    $periodStart = strtotime($start);
    $periodEnd = strtotime($end);
    $weights = [];
    foreach ($contracts as $ct) {
        $cs = $ct['start_date'] ? strtotime($ct['start_date'] . ' 00:00:00') : $periodStart;
        $ce = $ct['end_date'] ? strtotime($ct['end_date'] . ' 23:59:59') : $periodEnd;
        $ovStart = max($cs, $periodStart);
        $ovEnd = min($ce, $periodEnd);
        $weights[$ct['id']] = $ovEnd > $ovStart ? ($ovEnd - $ovStart) / 86400 : 0;
    }
    $weightSum = array_sum($weights);
    // Nothing overlapped the period (e.g. every contract's dates fall outside it) —
    // fall back to an even split so billing isn't silently dropped.
    if ($weightSum <= 0) {
        $weights = array_fill_keys(array_keys($weights), 1);
        $weightSum = count($weights);
    }

    $rows = [];
    foreach ($contracts as $ct) {
        $share = $weights[$ct['id']] / $weightSum;
        $rows[] = [
            'id' => $ct['id'], 'title' => $ct['title'], 'status' => $ct['status'],
            'start_date' => $ct['start_date'], 'end_date' => $ct['end_date'],
            'currency' => $ct['currency'],
            'secs' => (int) round($totalSecs * $share),
            'amount' => $totalAmount * $share,
        ];
    }
    return $rows;
}

function dash_billing(): void
{
    $u = require_cap('billing');
    [$start, $end, $period, $periodDate] = period_range_from_request('month', ['day', 'week', 'pay', 'month']);
    $clientFilter = billing_client_filter($u);
    $b = compute_billing($u, $start, $end, $clientFilter);
    // Per-contract breakdown is only meaningful for a client portal login looking
    // at its own engagement (never for admins viewing the whole org's billing).
    $contractBilling = ($u['role'] === 'client_viewer' && (int) $clientFilter > 0)
        ? compute_contract_billing($u, $start, $end, (int) $clientFilter) : [];
    view('dashboard/billing', array_merge(nav_context($u), [
        'title' => 'Billing', 'active' => 'billing', 'period' => $period, 'period_date' => $periodDate,
        'by_agent' => $b['by_agent'], 'by_client' => $b['by_client'],
        'contract_billing' => $contractBilling,
        'total' => array_sum(array_map(fn($a) => $a['amount'], $b['by_agent'])),
    ]));
}

function dash_billing_csv(): void
{
    $u = require_cap('billing');
    [$start, $end, $period] = period_range_from_request('month', ['day', 'week', 'pay', 'month']);
    $b = compute_billing($u, $start, $end, billing_client_filter($u));
    $out = fopen('php://temp', 'r+');
    fputcsv($out, ['Billing by agent — period: ' . $period]);
    fputcsv($out, ['Agent', 'Active hours', 'Bill rate/hr', 'Amount', 'Currency']);
    foreach ($b['by_agent'] as $a) {
        fputcsv($out, [$a['name'], round($a['secs'] / 3600, 2), $a['rate'],
                       round($a['amount'], 2), $a['currency']]);
    }
    fputcsv($out, []);
    fputcsv($out, ['Billing by customer']);
    fputcsv($out, ['Customer', 'Active hours', 'Amount']);
    foreach ($b['by_client'] as $c) {
        fputcsv($out, [$c['name'], round($c['secs'] / 3600, 2), round($c['amount'], 2)]);
    }
    rewind($out);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="deskpulse-billing-' . $period . '.csv"');
    echo stream_get_contents($out);
    exit;
}

// ─────────────────────────── Salary / pay breakdown (admin + HR) ───────────────────────────

/**
 * Accounting summary of salary/pay for organization members over a period:
 * each member's pay config, active hours and computed labor cost, with totals.
 * Visible to company admin and HR admin (capability: payroll).
 */
function dash_payroll(): void
{
    $u = require_cap('payroll');
    $orgId = (int) $u['org_id'];
    [$start, $end, $period, $periodDate] = period_range_from_request('pay', ['day', 'week', 'pay', 'month']);

    $usersById = users_by_id($orgId);
    $ids = array_keys($usersById);
    $sessions = sessions_for_users($ids, $start, $end);   // approved
    // Credited (payable) active time excludes overtime HR hasn't approved; track the
    // pending-overtime hours separately so admins see what is being held back.
    $activeByUser = array_fill_keys($ids, 0);
    $otPendingByUser = array_fill_keys($ids, 0);
    foreach ($sessions as $s) {
        $activeByUser[$s['user_id']] += creditable_active_s($s);
        if (($s['overtime_status'] ?? 'none') === 'pending') {
            $otPendingByUser[$s['user_id']] += (int) $s['overtime_s'];
        }
    }

    // Adjustments, paid leave, caps and net pay come from the shared pay-run
    // calculation so this page, the payslip PDF and the salary run can never
    // disagree about what somebody is owed.
    $ctx = $GLOBALS['DP_PERIOD_CTX'];
    $run = compute_pay_run($u, $ctx);
    $byUser = [];
    foreach ($run['rows'] as $r) {
        $byUser[(int) $r['user_id']] = $r;
    }

    $rows = [];
    $totalCost = 0.0;
    $totalActive = 0;
    $totalOtPending = 0;
    $totalExtras = 0.0;
    $totalDeductions = 0.0;
    $totalNet = 0.0;
    $currency = $run['currency'];
    foreach ($usersById as $id => $m) {
        $pr = $byUser[(int) $id] ?? null;
        $activeS = $activeByUser[$id] ?? 0;
        $otPendingS = $otPendingByUser[$id] ?? 0;
        $hourly = user_hourly_rate($m);
        $cost = $pr ? $pr['base'] : $activeS / 3600.0 * $hourly;
        $extras = $pr ? $pr['earnings'] + $pr['leave_pay'] : 0.0;
        $deduct = $pr ? $pr['deductions'] : 0.0;
        $net = $pr ? $pr['net'] : $cost;
        $totalCost += $cost;
        $totalActive += $activeS;
        $totalOtPending += $otPendingS;
        $totalExtras += $extras;
        $totalDeductions += $deduct;
        $totalNet += $net;
        $currency = $m['currency'] ?: $currency;
        $rows[] = [
            'user_id' => (int) $id,
            'name' => $m['name'], 'role' => $m['role'],
            'employment_type' => $m['employment_type'] ?? 'full_time',
            'pay_type' => $m['pay_type'] ?: 'hourly', 'pay_rate' => (float) $m['pay_rate'],
            'currency' => $m['currency'] ?: 'USD', 'active_s' => $activeS,
            'overtime_pending_s' => $otPendingS,
            'hourly' => $hourly, 'cost' => $cost,
            'leave_days' => $pr['leave_days'] ?? 0.0,
            'extras' => $extras, 'deductions' => $deduct, 'net' => $net,
            'flags' => $pr['flags'] ?? [],
        ];
    }
    usort($rows, fn($a, $b) => $b['net'] <=> $a['net']);

    view('dashboard/payroll', array_merge(nav_context($u), [
        'title' => 'Pay breakdown', 'active' => 'payroll', 'period' => $period, 'period_date' => $periodDate,
        'rows' => $rows, 'total_cost' => $totalCost, 'total_active_s' => $totalActive,
        'total_overtime_pending_s' => $totalOtPending, 'currency' => $currency,
        'total_extras' => $totalExtras, 'total_deductions' => $totalDeductions,
        'total_net' => $totalNet, 'ctx' => $ctx,
        'can_adjust' => can($u, 'pay_adjustments'),
    ]));
}

/**
 * Personal payslip: the logged-in user's own pay per day for the period
 * (active hours × their hourly-equivalent rate). Available to every tracked
 * user — agents, team managers and admin staff.
 */
function dash_payslip(): void
{
    // Staff only — a client portal login is a customer, not an employee.
    $u = require_staff();
    $me = db_one('SELECT * FROM users WHERE id = ?', [$u['id']]);
    [$start, $end, $period, $periodDate] = period_range_from_request('pay', ['day', 'week', 'pay', 'month']);
    $sessions = sessions_for_users([(int) $u['id']], $start, $end);   // own, approved
    $hourly = user_hourly_rate($me);

    // Bucket *creditable* (payable) seconds per day — overtime only counts once HR has
    // approved it. Track pending/approved overtime totals to show what's held back.
    $buckets = [];
    $otPendingS = 0;
    $otApprovedS = 0;
    foreach ($sessions as $s) {
        $day = date('Y-m-d', strtotime($s['started_at']));
        $buckets[$day] = ($buckets[$day] ?? 0) + creditable_active_s($s);
        $st = $s['overtime_status'] ?? 'none';
        if ($st === 'pending') {
            $otPendingS += (int) $s['overtime_s'];
        } elseif ($st === 'approved') {
            $otApprovedS += (int) $s['overtime_s'];
        }
    }

    $rows = [];
    $totalPay = 0.0;
    $totalActive = 0;
    ksort($buckets);
    foreach ($buckets as $day => $secs) {
        if ($secs <= 0) {
            continue;   // only show days actually credited
        }
        $hrs = round($secs / 3600, 2);
        $pay = $hrs * $hourly;
        $totalPay += $pay;
        $totalActive += $secs;
        $rows[] = ['date' => $day, 'hours' => $hrs, 'pay' => $pay];
    }

    view('dashboard/payslip', array_merge(nav_context($u), [
        'title' => 'Payslip', 'active' => 'payslip', 'period' => $period, 'period_date' => $periodDate,
        'rows' => $rows, 'me' => $me, 'hourly' => $hourly,
        'total_pay' => $totalPay, 'total_active_s' => $totalActive,
        'overtime_pending_s' => $otPendingS, 'overtime_approved_s' => $otApprovedS,
        'currency' => $me['currency'] ?: 'USD',
        'pay_type' => $me['pay_type'] ?: 'hourly', 'pay_rate' => (float) $me['pay_rate'],
    ]));
}

// ─────────────────────────── Download (logged-in users) ───────────────────────────

function dash_download(): void
{
    $u = require_login();
    $devices = db_all('SELECT * FROM devices WHERE user_id = ? ORDER BY created_at DESC', [$u['id']]);
    view('dashboard/download', array_merge(nav_context($u), [
        'title' => 'Download the agent', 'active' => 'download',
        'download_url' => config('download_url', ''),
        'server_url' => public_base_url(), 'devices' => $devices,
    ]));
}

// ─────────────────────────── Tasks ───────────────────────────

function dash_tasks(): void
{
    $u = require_login();
    // Tasks are owned and managed by the agent (worker) only. Managers, admins and
    // client viewers see them read-only with time-per-task stats.
    $canManage = $u['role'] === 'member';

    if (request_method() === 'POST') {
        check_csrf();
        if (!$canManage) {
            abort(403, 'Only agents can manage their own tasks.');
        }
        $action = $_POST['action'] ?? '';
        $clientOf = function ($cid) use ($u) {
            $cid = (int) $cid;
            return ($cid && db_one('SELECT id FROM clients WHERE id = ? AND org_id = ?', [$cid, $u['org_id']])) ? $cid : null;
        };
        if ($action === 'add') {
            $title = trim($_POST['title'] ?? '');
            if ($title !== '') {
                db_exec('INSERT INTO tasks (org_id, user_id, client_id, title) VALUES (?, ?, ?, ?)',
                    [$u['org_id'], $u['id'], $clientOf($_POST['client_id'] ?? 0), substr($title, 0, 240)]);
                flash('Task added.', 'success');
            }
        } elseif ($action === 'edit') {
            $taskId = (int) ($_POST['task_id'] ?? 0);
            $task = db_one('SELECT * FROM tasks WHERE id = ? AND user_id = ?', [$taskId, $u['id']]);
            if ($task) {
                $title = trim($_POST['title'] ?? '') ?: $task['title'];
                $status = (($_POST['status'] ?? $task['status']) === 'done') ? 'done' : 'open';
                db_exec('UPDATE tasks SET title = ?, client_id = ?, status = ? WHERE id = ? AND user_id = ?',
                    [substr($title, 0, 240), $clientOf($_POST['client_id'] ?? 0), $status, $taskId, $u['id']]);
                flash('Task updated.', 'success');
            }
        } elseif ($action === 'delete') {
            db_exec('DELETE FROM tasks WHERE id = ? AND user_id = ?',
                [(int) ($_POST['task_id'] ?? 0), $u['id']]);
            flash('Task removed.', 'success');
        } elseif ($action === 'done') {
            db_exec('UPDATE tasks SET status = "done" WHERE id = ? AND user_id = ?',
                [(int) ($_POST['task_id'] ?? 0), $u['id']]);
        }
        redirect('/app/tasks');
    }

    // Tasks visible to this user (own for agents; team/org tasks for managers & viewers).
    $ids = visible_user_ids($u);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $tasks = db_all(
        "SELECT t.*, us.name AS owner_name FROM tasks t JOIN users us ON us.id = t.user_id
         WHERE t.user_id IN ($in) ORDER BY t.status, t.created_at DESC",
        $ids
    );
    // A client portal only sees tasks tagged to its own engagement (-1 = login not
    // linked to a client → nothing; untagged tasks are not "related to this client").
    $clientFilter = billing_client_filter($u);
    if ($clientFilter !== null) {
        $tasks = array_values(array_filter($tasks, fn($t) => (int) $t['client_id'] === $clientFilter));
    }
    // Time spent per task = SUM of active seconds across approved sessions.
    $timeByTask = [];
    foreach (db_all(
        "SELECT task_id, SUM(active_s) secs, COUNT(*) cnt FROM sessions
         WHERE task_id IS NOT NULL AND approval_status = 'approved'
           AND user_id IN ($in) GROUP BY task_id", $ids) as $row) {
        $timeByTask[$row['task_id']] = ['secs' => (int) $row['secs'], 'cnt' => (int) $row['cnt']];
    }
    $clients = db_all('SELECT id, name FROM clients WHERE org_id = ? AND archived = 0 ORDER BY name',
        [$u['org_id']]);

    view('dashboard/tasks', array_merge(nav_context($u), [
        'title' => 'Tasks', 'active' => 'tasks', 'tasks' => $tasks,
        'time_by_task' => $timeByTask, 'clients' => $clients, 'me_id' => (int) $u['id'],
        'can_manage' => $canManage,
    ]));
}

// ─────────────────────────── Agent management ───────────────────────────

/**
 * Agents page (managers/admins): list the agents under the viewer and manage
 * their client assignment (one or many), and create/update/delete their tasks.
 */
function dash_agents(): void
{
    $u = require_cap('view_agents');
    $canManage = can($u, 'manage_agents');       // client portals see a read-only roster
    $orgId = (int) $u['org_id'];
    $ids = visible_user_ids($u);                 // team-scoped for managers, client's agents for a portal
    $inScope = fn(int $id) => in_array($id, $ids, true);

    if (request_method() === 'POST') {
        check_csrf();
        if (!$canManage) {
            redirect('/app/agents');             // read-only roles can't edit assignments
        }
        $action = $_POST['action'] ?? '';
        $agentId = (int) ($_POST['agent_id'] ?? 0);

        // Managers assign clients to agents here. Tasks themselves are owned and
        // edited only by the agent (on their own Tasks page) — shown read-only below.
        if ($action === 'assign_clients' && $inScope($agentId)) {
            $wanted = array_values(array_unique(array_map('intval', (array) ($_POST['client_ids'] ?? []))));
            $valid = [];
            if ($wanted) {
                $ph = implode(',', array_fill(0, count($wanted), '?'));
                $valid = array_map('intval', array_column(
                    db_all("SELECT id FROM clients WHERE org_id = ? AND id IN ($ph)",
                        array_merge([$orgId], $wanted)), 'id'));
            }
            db_exec('DELETE FROM agent_clients WHERE agent_id = ?', [$agentId]);
            foreach ($valid as $cid) {
                db_exec('INSERT IGNORE INTO agent_clients (agent_id, client_id) VALUES (?, ?)', [$agentId, $cid]);
            }
            flash('Client assignment updated.', 'success');
        }
        redirect('/app/agents');
    }

    // ── GET: assemble the roster with assignments, tasks and weekly activity ──
    $in = implode(',', array_fill(0, count($ids), '?'));
    $usersById = users_by_id($orgId);
    $clients = db_all('SELECT id, name FROM clients WHERE org_id = ? AND archived = 0 ORDER BY name', [$orgId]);

    [$wkStart, $wkEnd] = period_range('week');
    $weekActive = [];
    foreach ($ids as $aid) {
        $weekActive[(int) $aid] = 0;
    }
    foreach (sessions_for_users($ids, $wkStart, $wkEnd) as $s) {
        $weekActive[(int) $s['user_id']] = ($weekActive[(int) $s['user_id']] ?? 0) + (int) $s['active_s'];
    }
    $liveSet = array_flip(array_map('intval', array_column(
        db_all("SELECT DISTINCT user_id FROM sessions WHERE user_id IN ($in) AND ended_at IS NULL", $ids), 'user_id')));

    $assignMap = [];
    foreach (db_all("SELECT agent_id, client_id FROM agent_clients WHERE agent_id IN ($in)", $ids) as $r) {
        $assignMap[(int) $r['agent_id']][] = (int) $r['client_id'];
    }
    $tasksByAgent = [];
    foreach (db_all("SELECT t.* FROM tasks t WHERE t.user_id IN ($in)
                     ORDER BY t.status, t.created_at DESC", $ids) as $t) {
        $tasksByAgent[(int) $t['user_id']][] = $t;
    }
    $timeByTask = [];
    foreach (db_all("SELECT task_id, SUM(active_s) secs, COUNT(*) cnt FROM sessions
                     WHERE task_id IS NOT NULL AND approval_status = 'approved'
                       AND user_id IN ($in) GROUP BY task_id", $ids) as $row) {
        $timeByTask[(int) $row['task_id']] = ['secs' => (int) $row['secs'], 'cnt' => (int) $row['cnt']];
    }

    $agents = [];
    foreach ($ids as $aid) {
        $aid = (int) $aid;
        if ($aid === (int) $u['id']) {
            continue;   // the manager isn't an agent under themselves
        }
        $info = $usersById[$aid] ?? null;
        if (!$info) {
            continue;
        }
        $agents[] = [
            'id' => $aid, 'name' => $info['name'], 'role' => $info['role'],
            'email' => $info['email'], 'job_title' => $info['job_title'] ?? '',
            'week_active_s' => $weekActive[$aid] ?? 0, 'live' => isset($liveSet[$aid]),
            'client_ids' => $assignMap[$aid] ?? [], 'tasks' => $tasksByAgent[$aid] ?? [],
        ];
    }
    usort($agents, fn($a, $b) => strcasecmp($a['name'], $b['name']));

    view('dashboard/agents', array_merge(nav_context($u), [
        'title' => 'Agents', 'active' => 'agents', 'agents' => $agents,
        'clients' => $clients, 'time_by_task' => $timeByTask, 'can_manage' => $canManage,
    ]));
}

/**
 * Full agent detail page (managers/admins): info, rates, activity graphs,
 * activity/idle stats, screenshots and tasks (status + time spent) for one agent.
 */
function dash_agent_detail(array $p): void
{
    $u = require_cap('view_agents');
    $uid = (int) $p['id'];
    $usr = db_one('SELECT * FROM users WHERE id = ?', [$uid]);
    if (!$usr || $usr['role'] === 'super_admin') {
        abort(404, 'Agent not found.');
    }
    if ($u['role'] === 'super_admin') {
        // A super admin's own org_id is the Platform org unless they've clicked
        // "Act as" on a tenant — but manage_agents (via the '*' capability) means
        // they can open any agent's detail directly (e.g. from a support link),
        // so scope this request to the agent's own org rather than 403ing.
        $u['org_id'] = (int) $usr['org_id'];
    } elseif (!in_array($uid, visible_user_ids($u), true) || (int) $usr['org_id'] !== (int) $u['org_id']) {
        deny_access();   // out of scope (e.g. after switching accounts) — go home, not a 403
    }

    [$start, $end, $period, $periodDate] = period_range_from_request('week', ['day', 'week', 'pay', 'month']);
    $sessions = sessions_for_users([$uid], $start, $end);
    $summary  = summarize($sessions);
    $timeline = activity_timeline([$uid], $start, $end);
    $daily    = daily_series($sessions, $start, $end);
    $apps     = top_apps(array_column($sessions, 'id'));

    $sessIds = array_column($sessions, 'id');
    $idle = ['count' => 0, 'total_s' => 0];
    $shots = [];
    if ($sessIds) {
        $sin = implode(',', array_fill(0, count($sessIds), '?'));
        $r = db_one("SELECT COUNT(*) c, COALESCE(SUM(duration_s),0) s FROM idle_periods
                     WHERE session_id IN ($sin)", $sessIds);
        $idle = ['count' => (int) $r['c'], 'total_s' => (int) $r['s']];
        if (can($u, 'screenshots')) {
            $shots = db_all("SELECT sc.* FROM screenshots sc WHERE sc.session_id IN ($sin)
                             ORDER BY sc.ts DESC LIMIT 24", $sessIds);
        }
    }
    $activeArr = array_map(fn($s) => (int) $s['active_s'], $sessions);

    // Recent sessions (activity times) for the period.
    $recentSessions = db_all(
        "SELECT s.*, c.name AS client_name FROM sessions s
         LEFT JOIN clients c ON c.id = s.client_id
         WHERE s.user_id = ? AND s.started_at >= ? AND s.started_at < ?
         ORDER BY s.started_at DESC LIMIT 25",
        [$uid, $start, $end]);

    // All tasks owned by the agent + their lifetime time spent.
    $tasks = db_all("SELECT * FROM tasks WHERE user_id = ? ORDER BY status, created_at DESC", [$uid]);
    $taskTime = [];
    foreach (db_all("SELECT task_id, SUM(active_s) secs, COUNT(*) cnt FROM sessions
                     WHERE user_id = ? AND task_id IS NOT NULL AND approval_status = 'approved'
                     GROUP BY task_id", [$uid]) as $row) {
        $taskTime[$row['task_id']] = ['secs' => (int) $row['secs'], 'cnt' => (int) $row['cnt']];
    }

    $teams = array_column(db_all('SELECT t.name FROM team_members tm JOIN teams t ON t.id = tm.team_id
                                  WHERE tm.user_id = ? ORDER BY t.name', [$uid]), 'name');
    $assignedClients = array_column(db_all('SELECT c.name FROM agent_clients ac JOIN clients c ON c.id = ac.client_id
                                  WHERE ac.agent_id = ? ORDER BY c.name', [$uid]), 'name');
    $devices = db_all('SELECT name, last_seen, created_at FROM devices WHERE user_id = ?
                       ORDER BY (last_seen IS NULL), last_seen DESC', [$uid]);

    $stats = [
        'idle_count'        => $idle['count'],
        'idle_total_s'      => $idle['total_s'],
        'avg_session_s'     => $sessions ? (int) round(array_sum($activeArr) / count($sessions)) : 0,
        'longest_session_s' => $activeArr ? max($activeArr) : 0,
        'screenshots'       => $sessIds ? (int) db_one("SELECT COUNT(*) c FROM screenshots
                                 WHERE session_id IN (" . implode(',', array_fill(0, count($sessIds), '?')) . ")",
                                 $sessIds)['c'] : 0,
        'live'              => (bool) db_one('SELECT id FROM sessions WHERE user_id = ? AND ended_at IS NULL LIMIT 1', [$uid]),
        'total_active_s'    => (int) db_one('SELECT COALESCE(SUM(active_s),0) s FROM sessions WHERE user_id = ?', [$uid])['s'],
    ];

    view('dashboard/agent_detail', array_merge(nav_context($u), [
        'title' => $usr['name'], 'active' => 'agents', 'period' => $period, 'period_date' => $periodDate,
        'agent_user' => $usr, 'summary' => $summary, 'timeline' => $timeline, 'daily' => $daily,
        'apps' => $apps, 'stats' => $stats, 'shots' => $shots, 'recent_sessions' => $recentSessions,
        'tasks' => $tasks, 'task_time' => $taskTime, 'teams' => $teams,
        'assigned_clients' => $assignedClients, 'devices' => $devices,
        'can_rates' => can($u, 'view_rates'), 'can_shots' => can($u, 'screenshots'),
    ]));
}

/**
 * Public share-links directory, scoped by role:
 *   - agent/employee  → their own personal link only
 *   - team manager    → their team roster's links
 *   - company admin   → every agent's link, grouped by team (+ Unassigned)
 */
function dash_share_links(): void
{
    $u = require_cap('view_all');   // company admin / HR / IT — not managers or agents
    $orgId = (int) $u['org_id'];
    ensure_personal_links($orgId);
    $base = public_base_url();
    $tokens = personal_links_map($orgId);                 // user_id => token
    $linkUrl = fn($uid) => isset($tokens[$uid]) ? $base . '/share/' . $tokens[$uid] : null;

    $self = ['name' => $u['name'], 'url' => $linkUrl((int) $u['id'])];

    $mode = 'self';
    $groups = [];
    if (can($u, 'view_all') || can($u, 'view_team')) {
        $mode = can($u, 'view_all') ? 'all' : 'team';
        $allowed = array_map('intval', can($u, 'view_all')
            ? org_user_ids($orgId) : team_member_ids((int) $u['id']));
        $allowedSet = array_flip($allowed);
        $usersById = users_by_id($orgId);

        $row = function (int $uid) use ($usersById, $linkUrl) {
            $info = $usersById[$uid] ?? null;
            return $info ? ['id' => $uid, 'name' => $info['name'], 'role' => $info['role'],
                            'url' => $linkUrl($uid)] : null;
        };
        // Group allowed users by team; collect a leftover "Unassigned" bucket.
        $membersByTeam = [];
        $assigned = [];
        foreach (db_all('SELECT tm.team_id, tm.user_id FROM team_members tm
                         JOIN teams t ON t.id = tm.team_id WHERE t.org_id = ?', [$orgId]) as $r) {
            $uid = (int) $r['user_id'];
            if (!isset($allowedSet[$uid])) {
                continue;
            }
            $membersByTeam[(int) $r['team_id']][$uid] = true;   // dedupe across teams
            $assigned[$uid] = true;
        }
        foreach (db_all('SELECT id, name FROM teams WHERE org_id = ? ORDER BY name', [$orgId]) as $t) {
            $uids = array_keys($membersByTeam[(int) $t['id']] ?? []);
            $members = array_values(array_filter(array_map($row, $uids)));
            usort($members, fn($a, $b) => strcasecmp($a['name'], $b['name']));
            if ($members) {
                $groups[] = ['name' => $t['name'], 'members' => $members];
            }
        }
        $unassigned = [];
        foreach ($allowed as $uid) {
            if (!isset($assigned[$uid]) && ($r = $row($uid))) {
                $unassigned[] = $r;
            }
        }
        usort($unassigned, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        if ($unassigned) {
            $groups[] = ['name' => 'Unassigned', 'members' => $unassigned];
        }
    }

    view('dashboard/share_links', array_merge(nav_context($u), [
        'title' => 'Share links', 'active' => 'share_links',
        'mode' => $mode, 'self' => $self, 'groups' => $groups,
    ]));
}

// ─────────────────────────── Settings ───────────────────────────

function dash_settings(): void
{
    $u = require_login();
    if ($u['role'] === 'manager') {
        deny_access();   // managers have no Settings page — bounce home, not a 403
    }
    $canShare = can($u, 'view_all');   // share-link management is admin-only
    if (request_method() === 'POST') {
        check_csrf();
        $action = $_POST['action'] ?? '';
        if ($action === 'share_create') {
            if (!$canShare) {
                abort(403, 'Not allowed');
            }
            $scope = in_array($_POST['scope'] ?? '', ['user', 'team', 'org'], true) ? $_POST['scope'] : 'user';
            $target = $scope === 'org' ? null : (int) ($_POST['target_id'] ?? $u['id']);
            $expires = !empty($_POST['expires_at'])
                ? date('Y-m-d H:i:s', strtotime($_POST['expires_at'])) : null;
            db_exec(
                'INSERT INTO share_links (org_id, scope, target_id, token, label, period_default, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$u['org_id'], $scope, $target, random_token(18),
                 substr($_POST['label'] ?? '', 0, 160),
                 in_array($_POST['period'] ?? 'week', ['day', 'week', 'month'], true) ? $_POST['period'] : 'week',
                 $expires]
            );
            flash('Public share link created.', 'success');
        } elseif ($action === 'share_revoke') {
            if (!$canShare) {
                abort(403, 'Not allowed');
            }
            db_exec('UPDATE share_links SET revoked = 1 WHERE id = ? AND org_id = ?',
                [(int) ($_POST['link_id'] ?? 0), $u['org_id']]);
            flash('Share link revoked.', 'success');
        } elseif ($action === 'device_delete') {
            // Owners can remove their own registered devices (revokes the agent).
            db_exec('DELETE FROM devices WHERE id = ? AND user_id = ?',
                [(int) ($_POST['device_id'] ?? 0), $u['id']]);
            flash('Device removed. That copy of the desktop app will need to sign in again.', 'success');
        } elseif ($action === 'policy') {
            if (!can($u, 'org_settings')) {
                abort(403, 'Not allowed');
            }
            db_exec(
                'UPDATE organizations SET screenshot_interval_min = ?, screenshot_blur = ?,
                        idle_threshold_min = ?, sync_interval_s = ?, track_screenshots = ?,
                        track_windows = ?, track_processes = ? WHERE id = ?',
                [max(1, min(120, (int) ($_POST['screenshot_interval_min'] ?? 10))),
                 isset($_POST['screenshot_blur']) ? 1 : 0,
                 max(1, min(120, (int) ($_POST['idle_threshold_min'] ?? 15))),
                 max(15, min(600, (int) ($_POST['sync_interval_s'] ?? 60))),
                 isset($_POST['track_screenshots']) ? 1 : 0,
                 isset($_POST['track_windows']) ? 1 : 0,
                 isset($_POST['track_processes']) ? 1 : 0,
                 $u['org_id']]
            );
            flash('Monitoring policy saved. Agents apply it on the next session.', 'success');
        } elseif ($action === 'sso') {
            if (!can($u, 'org_settings')) {
                abort(403, 'Not allowed');
            }
            $issuer = trim((string) ($_POST['sso_issuer'] ?? ''));
            // Discovery drives every downstream endpoint, so the issuer must be HTTPS.
            if ($issuer !== '' && !preg_match('~^https://[\w.-]+(/[\w./-]*)?$~', $issuer)) {
                flash('The issuer URL must be an https:// address.', 'error');
                redirect('/app/settings');
            }
            $clientId = substr(trim((string) ($_POST['sso_client_id'] ?? '')), 0, 255);
            // Blank secret means "keep the stored one" — the field renders masked, so a
            // blank submit is a save of the other fields, not an instruction to wipe it.
            $secretIn = trim((string) ($_POST['sso_client_secret'] ?? ''));
            $enabled = isset($_POST['sso_enabled']) ? 1 : 0;
            $enforce = isset($_POST['sso_enforce']) ? 1 : 0;
            $domains = strtolower(substr(trim((string) ($_POST['sso_domains'] ?? '')), 0, 255));
            $domains = implode(',', array_filter(array_map(
                fn($d) => preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', trim($d)) ? trim($d) : '',
                explode(',', $domains)
            )));

            // Refuse to enforce SSO that has not been proven to work — locking every
            // admin out of their own workspace is not a recoverable mistake.
            if ($enforce && !$enabled) {
                flash('Enable single sign-on before enforcing it.', 'error');
                redirect('/app/settings');
            }
            if ($enabled && ($issuer === '' || $clientId === '')) {
                flash('An issuer URL and client ID are required to enable single sign-on.', 'error');
                redirect('/app/settings');
            }
            if ($secretIn !== '') {
                db_exec('UPDATE organizations SET sso_client_secret = ? WHERE id = ?',
                    [dp_encrypt($secretIn), $u['org_id']]);
            }
            db_exec('UPDATE organizations SET sso_enabled = ?, sso_issuer = ?, sso_client_id = ?,
                     sso_domains = ?, sso_enforce = ? WHERE id = ?',
                [$enabled, $issuer ?: null, $clientId ?: null, $domains ?: null, $enforce, $u['org_id']]);
            flash($enabled
                ? 'Single sign-on saved. Test it in a private window before enforcing it.'
                : 'Single sign-on disabled.', 'success');
        } elseif ($action === 'period_policy') {
            if (!can($u, 'org_settings')) {
                abort(403, 'Not allowed');
            }
            $tz = (string) ($_POST['report_tz'] ?? 'UTC');
            if (!in_array($tz, timezone_identifiers_list(), true)) {
                $tz = 'UTC';
            }
            $cycle = (string) ($_POST['pay_cycle'] ?? 'semimonthly');
            if (!in_array($cycle, ['weekly', 'biweekly', 'semimonthly', 'rolling15', 'monthly'], true)) {
                $cycle = 'semimonthly';
            }
            $anchor = (string) ($_POST['pay_cycle_anchor'] ?? '');
            $anchor = preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchor) ? $anchor : null;
            db_exec(
                'UPDATE organizations SET report_tz = ?, week_start = ?, pay_cycle = ?,
                        pay_cycle_anchor = ?, pay_currency = ? WHERE id = ?',
                [$tz,
                 max(1, min(7, (int) ($_POST['week_start'] ?? 1))),
                 $cycle,
                 $anchor,
                 strtoupper(substr(trim((string) ($_POST['pay_currency'] ?? 'USD')), 0, 8)) ?: 'USD',
                 $u['org_id']]
            );
            flash('Reporting and payroll period settings saved.', 'success');
        } elseif ($action === 'upload_logo') {
            if (!can($u, 'org_settings')) {
                abort(403, 'Not allowed');
            }
            [$rel, $err] = store_logo_webp($_FILES['logo'] ?? [], 'org' . $u['org_id']);
            if ($rel) {
                $old = db_one('SELECT logo_path FROM organizations WHERE id = ?', [$u['org_id']]);
                db_exec('UPDATE organizations SET logo_path = ? WHERE id = ?', [$rel, $u['org_id']]);
                if (!empty($old['logo_path']) && $old['logo_path'] !== $rel) {
                    @unlink(upload_path($old['logo_path']));
                }
                flash('Company logo updated.', 'success');
            } else {
                flash($err ?: 'Could not upload the logo.', 'error');
            }
        } elseif ($action === 'remove_logo') {
            if (!can($u, 'org_settings')) {
                abort(403, 'Not allowed');
            }
            $old = db_one('SELECT logo_path FROM organizations WHERE id = ?', [$u['org_id']]);
            if (!empty($old['logo_path'])) {
                @unlink(upload_path($old['logo_path']));
            }
            db_exec('UPDATE organizations SET logo_path = NULL WHERE id = ?', [$u['org_id']]);
            flash('Company logo removed.', 'success');
        }
        redirect('/app/settings');
    }

    if (is_manager($u)) {
        ensure_personal_links((int) $u['org_id']);
    }
    $fresh = db_one('SELECT * FROM users WHERE id = ?', [$u['id']]);
    $devices = db_all('SELECT * FROM devices WHERE user_id = ? ORDER BY created_at DESC', [$u['id']]);
    $links = db_all('SELECT * FROM share_links WHERE org_id = ? ORDER BY created_at DESC', [$u['org_id']]);
    $members = is_manager($u)
        ? db_all('SELECT id, name FROM users WHERE org_id = ? ORDER BY name', [$u['org_id']]) : [];
    $teams = db_all('SELECT id, name FROM teams WHERE org_id = ? ORDER BY name', [$u['org_id']]);

    view('dashboard/settings', array_merge(nav_context($u), [
        'title' => 'Settings', 'active' => 'settings', 'me' => $fresh,
        'devices' => $devices, 'links' => $links, 'members' => $members,
        'teams' => $teams, 'public_base' => public_base_url(),
        'policy' => org_policy((int) $u['org_id']), 'is_admin' => can($u, 'org_settings'),
        'can_share' => can($u, 'view_all'), 'can_rates' => can($u, 'view_rates'),
        'period_cfg' => period_settings_ctx((int) $u['org_id']),
        'sso' => db_one('SELECT sso_enabled, sso_issuer, sso_client_id, sso_domains,
                                sso_enforce, sso_client_secret
                         FROM organizations WHERE id = ?', [$u['org_id']]) ?: [],
        'identities' => db_all('SELECT provider, email, created_at, last_login_at
                                FROM user_identities WHERE user_id = ? ORDER BY provider',
            [(int) $u['id']]),
        'oauth_providers' => oauth_providers(),
    ]));
}

/** Org period config plus a preview label of the current pay period, for Settings. */
function period_settings_ctx(int $orgId): array
{
    $cfg = report_org_cfg($orgId);
    $today = (new DateTime('now', new DateTimeZone($cfg['tz'])))->format('Y-m-d');
    [$s, $e, $label] = pay_period_bounds($today, $cfg);
    return $cfg + ['current_label' => $label, 'current_start' => $s, 'current_end' => $e];
}

// ─────────────────────────── CSV export ───────────────────────────

function dash_export_csv(): void
{
    $u = require_login();
    [$start, $end, $period, $periodDate] = period_range_from_request('week', ['day', 'week', 'pay', 'month']);
    $ids = visible_user_ids($u);
    $sessions = sessions_for_users($ids, $start, $end, false);
    $csv = sessions_csv($sessions, users_by_id((int) $u['org_id']));
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="deskpulse-' . $period . '-' . $periodDate . '.csv"');
    echo $csv;
    exit;
}

// ─────────────────────────── Payroll Excel import ───────────────────────────
//
// Company + HR admins upload a payroll spreadsheet (see the "Nick - Payroll
// Dry-Run.xlsx" layout) and it is ingested into their OWN org: one client per
// distinct "Client", one employee (user) per distinct "VT ID" with the sheet's
// hourly Payroll Rate, and one approved time-entry session per employee/day/
// client. Re-importing the same file is idempotent (employees matched on
// external_ref = VT ID; sessions on user+date+client with source='import').

/** Normalise a header label for matching (lowercase, single-spaced). */
function import_norm_header(string $s): string
{
    return trim(preg_replace('/\s+/', ' ', mb_strtolower($s)));
}

/**
 * Locate the payroll data sheet + header row in an uploaded workbook and build
 * a header-label -> column-letter map. The data sheet is the one whose header
 * row carries both "Contract Name" and "NET PAYROLL"; scanning by label (not a
 * fixed sheet name / row) keeps the import working across payroll periods.
 * Returns [sheetName, rows, headerRow, colByHeader] or null if not found.
 */
function import_locate(string $path): ?array
{
    foreach (xlsx_sheet_names($path) as $sheet) {
        $rows = xlsx_rows($path, $sheet);
        foreach ($rows as $rn => $row) {
            if ($rn > 60) {
                break;   // header sits near the top; don't scan the whole sheet
            }
            $labels = [];
            foreach ($row as $col => $val) {
                $n = import_norm_header((string) $val);
                if ($n !== '') {
                    $labels[$n] = $col;
                }
            }
            if (isset($labels['contract name'], $labels['net payroll'])) {
                return [$sheet, $rows, $rn, $labels];
            }
        }
    }
    return null;
}

/**
 * Parse the workbook into validated row structs. Returns
 * ['rows'=>[...structs], 'sheet'=>name, 'skipped'=>['count'=>n,'reasons'=>[...]],
 *  'error'=>?string]. Never throws on bad rows — they are counted as skips.
 */
function import_parse_file(string $path): array
{
    $loc = import_locate($path);
    if ($loc === null) {
        return ['rows' => [], 'sheet' => null, 'skipped' => ['count' => 0, 'reasons' => []],
                'error' => 'Could not find a payroll data sheet (no header row with "Contract Name" and "NET PAYROLL").'];
    }
    [$sheet, $rows, $headerRow, $labels] = $loc;

    $col = function (array $row, string $label) use ($labels): string {
        $c = $labels[import_norm_header($label)] ?? null;
        return $c !== null ? trim((string) ($row[$c] ?? '')) : '';
    };

    $out = [];
    $skipCount = 0;
    $reasons = [];
    $addReason = function (string $r) use (&$reasons) {
        $reasons[$r] = ($reasons[$r] ?? 0) + 1;
    };

    foreach ($rows as $rn => $row) {
        if ($rn <= $headerRow) {
            continue;
        }
        $vt = $col($row, 'VT ID');
        $date = xlsx_serial_to_date($col($row, 'Date'));
        $name = $col($row, 'Wise Name') ?: $col($row, 'Contract Name');
        // Fully blank / padding rows are ignored silently.
        if ($vt === '' && $name === '' && $date === null) {
            continue;
        }
        if ($vt === '' || strtoupper($vt) === '#N/A') {
            $skipCount++; $addReason('missing or invalid VT ID'); continue;
        }
        if ($date === null) {
            $skipCount++; $addReason('missing or invalid date'); continue;
        }
        if ($name === '') {
            $skipCount++; $addReason('missing employee name'); continue;
        }
        $wRaw = $col($row, 'Adj Credited Hrs');
        $uRaw = $col($row, 'Credited Time');
        $hours = is_numeric($wRaw) ? (float) $wRaw : (is_numeric($uRaw) ? (float) $uRaw : null);
        if ($hours === null) {
            $skipCount++; $addReason('no credited hours'); continue;
        }
        $activeS = (int) round(max(0.0, $hours) * 3600);
        if ($activeS <= 0) {
            $skipCount++; $addReason('zero credited hours'); continue;
        }
        $rate = is_numeric($col($row, 'Payroll Rate')) ? (float) $col($row, 'Payroll Rate') : 0.0;
        $dispute = $col($row, 'Dispute / Adjustments');
        $note = 'Imported payroll (' . $vt . ')'
            . (is_numeric($dispute) && (float) $dispute != 0.0 ? '; dispute/adj ' . $dispute . 'h' : '');

        $out[] = [
            'vt_id'       => substr($vt, 0, 40),
            'name'        => substr($name, 0, 120),
            'job_title'   => substr($col($row, 'Role Name'), 0, 120),
            'pay_rate'    => $rate,
            'wise_id'     => substr($col($row, 'Wise ID'), 0, 64),
            'wise_name'   => substr($col($row, 'Wise Name'), 0, 160),
            'client_name' => substr($col($row, 'Client'), 0, 200),
            'client_code' => $col($row, 'Client ID'),
            'industry'    => $col($row, 'Industry'),
            'date'        => $date,
            'active_s'    => $activeS,
            'note'        => substr($note, 0, 1000),
        ];
    }
    return ['rows' => $out, 'sheet' => $sheet,
            'skipped' => ['count' => $skipCount, 'reasons' => $reasons], 'error' => null];
}

/**
 * Upsert the parsed rows into the uploader's org. With $commit=false it only
 * computes the would-be counts (dry run, no writes); with $commit=true it does
 * the writes inside a single transaction. Returns a summary array.
 */
function import_ingest(array $u, array $rows, bool $commit): array
{
    $orgId = (int) $u['org_id'];
    $sum = [
        'clients_new' => 0, 'clients_existing' => 0,
        'emps_new' => 0, 'emps_updated' => 0, 'rate_changes' => [],
        'sessions_new' => 0, 'sessions_updated' => 0,
        'errors' => [],
    ];

    // Distinct clients (by name) and employees (by VT ID), first occurrence wins.
    $clients = [];
    $emps = [];
    foreach ($rows as $r) {
        if ($r['client_name'] !== '') {
            $clients[mb_strtolower($r['client_name'])] ??= $r;
        }
        $emps[strtolower($r['vt_id'])] ??= $r;
    }

    // Preload existing rows so we do a handful of queries, not thousands.
    $clientIdByKey = [];
    foreach (db_all('SELECT id, name FROM clients WHERE org_id = ?', [$orgId]) as $c) {
        $clientIdByKey[mb_strtolower(trim($c['name']))] = (int) $c['id'];
    }
    $userByRef = [];
    $userByEmail = [];
    foreach (db_all('SELECT id, external_ref, email, pay_rate FROM users WHERE org_id = ?', [$orgId]) as $usr) {
        if (($usr['external_ref'] ?? '') !== '') {
            $userByRef[strtolower($usr['external_ref'])] = $usr;
        }
        $userByEmail[strtolower($usr['email'])] = $usr;
    }

    $placeholder = -1;   // negative ids stand in for not-yet-created rows during dry runs
    $emailFor = fn(string $vt) => 'vt' . preg_replace('/[^a-z0-9]/i', '', strtolower($vt)) . '.org' . $orgId . '@import.deskpulse.local';

    if ($commit) {
        db()->beginTransaction();
    }
    try {
        // ── Clients ──
        foreach ($clients as $key => $r) {
            if (isset($clientIdByKey[$key])) {
                $sum['clients_existing']++;
                continue;
            }
            $sum['clients_new']++;
            if ($commit) {
                $notes = 'Imported.'
                    . ($r['client_code'] !== '' ? ' Client ID: ' . $r['client_code'] : '')
                    . ($r['industry'] !== '' ? ' | Industry: ' . $r['industry'] : '');
                $clientIdByKey[$key] = db_exec(
                    'INSERT INTO clients (org_id, name, contact_email, notes, currency) VALUES (?, ?, ?, ?, ?)',
                    [$orgId, $r['client_name'], '', substr($notes, 0, 65535), 'USD']
                );
            } else {
                $clientIdByKey[$key] = $placeholder--;
            }
        }

        // ── Employees (users) ──
        $userIdByRef = [];
        foreach ($emps as $refKey => $r) {
            $email = $emailFor($r['vt_id']);
            $existing = $userByRef[$refKey] ?? ($userByEmail[strtolower($email)] ?? null);
            if ($existing) {
                $id = (int) $existing['id'];
                $userIdByRef[$refKey] = $id;
                $sum['emps_updated']++;
                if ((float) $existing['pay_rate'] !== (float) $r['pay_rate']) {
                    $sum['rate_changes'][] = ['name' => $r['name'],
                        'from' => (float) $existing['pay_rate'], 'to' => (float) $r['pay_rate']];
                }
                if ($commit) {
                    db_exec(
                        'UPDATE users SET name = ?, job_title = ?, pay_type = "hourly", pay_rate = ?,
                            wise_id = ?, wise_name = ?, external_ref = COALESCE(external_ref, ?) WHERE id = ?',
                        [$r['name'], $r['job_title'], $r['pay_rate'],
                         $r['wise_id'] ?: null, $r['wise_name'] ?: null, $r['vt_id'], $id]
                    );
                }
                continue;
            }
            $sum['emps_new']++;
            if ($commit) {
                try {
                    $userIdByRef[$refKey] = db_exec(
                        'INSERT INTO users (org_id, name, email, password_hash, role, pay_type, pay_rate,
                            currency, external_ref, wise_id, wise_name, job_title, must_change_password)
                         VALUES (?, ?, ?, ?, "member", "hourly", ?, "USD", ?, ?, ?, ?, 1)',
                        [$orgId, $r['name'], $email, password_hash(random_token(12), PASSWORD_DEFAULT),
                         $r['pay_rate'], $r['vt_id'], $r['wise_id'] ?: null, $r['wise_name'] ?: null, $r['job_title']]
                    );
                } catch (\PDOException $e) {
                    $sum['emps_new']--;
                    $sum['errors'][] = 'Employee ' . $r['name'] . ' (' . $r['vt_id'] . '): ' . $e->getMessage();
                    $userIdByRef[$refKey] = 0;   // rows for this employee will be skipped below
                }
            } else {
                $userIdByRef[$refKey] = $placeholder--;
            }
        }

        // ── Sessions (one per employee/day/client) ──
        // Preload existing import sessions for the resolved (real) employees so
        // re-imports update in place instead of duplicating.
        $realIds = array_values(array_filter($userIdByRef, fn($id) => $id > 0));
        $sessionMap = [];   // "userId|date|clientId" => session id
        if ($realIds) {
            $in = implode(',', array_fill(0, count($realIds), '?'));
            foreach (db_all(
                "SELECT id, user_id, client_id, DATE(started_at) d FROM sessions
                 WHERE source = 'import' AND user_id IN ($in)", $realIds) as $s) {
                $sessionMap[$s['user_id'] . '|' . $s['d'] . '|' . ((int) ($s['client_id'] ?? 0))] = (int) $s['id'];
            }
        }

        $affected = [];   // userId => earliest date
        foreach ($rows as $r) {
            $userId = $userIdByRef[strtolower($r['vt_id'])] ?? 0;
            if ($userId === 0) {
                continue;   // employee insert failed
            }
            $clientId = $r['client_name'] !== '' ? ($clientIdByKey[mb_strtolower($r['client_name'])] ?? null) : null;
            $mapKey = $userId . '|' . $r['date'] . '|' . ((int) ($clientId ?? 0));
            $startAt = $r['date'] . ' 09:00:00';
            $endAt = date('Y-m-d H:i:s', strtotime($startAt) + $r['active_s']);

            if (isset($sessionMap[$mapKey])) {
                $sum['sessions_updated']++;
                if ($commit) {
                    db_exec('UPDATE sessions SET active_s = ?, ended_at = ?, note = ?, overtime_computed = 0 WHERE id = ?',
                        [$r['active_s'], $endAt, $r['note'], $sessionMap[$mapKey]]);
                }
            } else {
                $sum['sessions_new']++;
                if ($commit) {
                    $newId = db_exec(
                        'INSERT INTO sessions
                            (user_id, client_id, started_at, ended_at, active_s, inactive_s, source, note, approval_status)
                         VALUES (?, ?, ?, ?, ?, 0, "import", ?, "approved")',
                        [$userId, ($clientId && $clientId > 0) ? $clientId : null, $startAt, $endAt, $r['active_s'], $r['note']]
                    );
                    $sessionMap[$mapKey] = $newId;
                } else {
                    $sessionMap[$mapKey] = $placeholder--;
                }
            }
            if ($userId > 0 && (!isset($affected[$userId]) || $r['date'] < $affected[$userId])) {
                $affected[$userId] = $r['date'];
            }
        }

        if ($commit) {
            db()->commit();
            // Split imported sessions into regular/overtime per affected employee.
            foreach ($affected as $userId => $earliest) {
                recompute_overtime((int) $userId, $earliest . ' 00:00:00');
            }
        }
    } catch (\Throwable $e) {
        if ($commit && db()->inTransaction()) {
            db()->rollBack();
        }
        $sum['errors'][] = 'Import aborted: ' . $e->getMessage();
        $sum['fatal'] = true;
    }
    return $sum;
}

/** Delete import temp files older than an hour. */
function import_gc(): void
{
    $dir = upload_path('imports');
    if (!is_dir($dir)) {
        return;
    }
    foreach (glob($dir . '/*.xlsx') ?: [] as $f) {
        if (is_file($f) && filemtime($f) < time() - 3600) {
            @unlink($f);
        }
    }
}

function dash_import(): void
{
    $u = require_cap('data_import');
    $ctx = ['title' => 'Import data', 'active' => 'import', 'preview' => null];

    if (request_method() === 'POST') {
        check_csrf();
        import_gc();
        $action = $_POST['action'] ?? '';

        if ($action === 'preview') {
            $file = $_FILES['file'] ?? [];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                flash('No file was uploaded.', 'error');
                redirect('/app/import');
            }
            if (($file['size'] ?? 0) > 20 * 1024 * 1024) {
                flash('File is too large (max 20 MB).', 'error');
                redirect('/app/import');
            }
            if (!preg_match('/\.xlsx$/i', $file['name'] ?? '')) {
                flash('Please upload an .xlsx file.', 'error');
                redirect('/app/import');
            }
            $dir = upload_path('imports');
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $token = random_token(16);
            $dest = $dir . '/' . $token . '.xlsx';
            if (!@move_uploaded_file($file['tmp_name'], $dest)) {
                flash('Could not save the uploaded file.', 'error');
                redirect('/app/import');
            }
            $parse = import_parse_file($dest);
            if ($parse['error']) {
                @unlink($dest);
                flash($parse['error'], 'error');
                redirect('/app/import');
            }
            $summary = import_ingest($u, $parse['rows'], false);
            $ctx['preview'] = [
                'token'   => $token,
                'sheet'   => $parse['sheet'],
                'total'   => count($parse['rows']),
                'skipped' => $parse['skipped'],
                'summary' => $summary,
                'sample'  => array_slice($parse['rows'], 0, 20),
            ];
            view('dashboard/import', array_merge(nav_context($u), $ctx));
            return;
        }

        if ($action === 'commit') {
            // random_token() is URL-safe base64 (A-Z a-z 0-9 - _); keep exactly that
            // charset so the token still matches the saved file, while blocking any
            // path separators / dots (traversal safety).
            $token = preg_replace('/[^A-Za-z0-9_-]/', '', $_POST['token'] ?? '');
            $path = $token !== '' ? upload_path('imports/' . $token . '.xlsx') : '';
            if ($path === '' || !is_file($path)) {
                flash('The uploaded file expired — please upload it again.', 'error');
                redirect('/app/import');
            }
            $parse = import_parse_file($path);
            if ($parse['error']) {
                @unlink($path);
                flash($parse['error'], 'error');
                redirect('/app/import');
            }
            $s = import_ingest($u, $parse['rows'], true);
            @unlink($path);
            if (!empty($s['fatal'])) {
                flash('Import failed: ' . implode('; ', $s['errors']), 'error');
                redirect('/app/import');
            }
            $msg = sprintf(
                'Import complete — clients: %d new, %d existing; employees: %d new, %d updated; time entries: %d added, %d updated.',
                $s['clients_new'], $s['clients_existing'], $s['emps_new'], $s['emps_updated'],
                $s['sessions_new'], $s['sessions_updated']
            );
            if ($s['errors']) {
                $msg .= ' (' . count($s['errors']) . ' row error(s) skipped.)';
            }
            flash($msg, 'success');
            redirect('/app/import');
        }
        redirect('/app/import');
    }

    view('dashboard/import', array_merge(nav_context($u), $ctx));
}

// ─────────────────────────── Efficiency & effectiveness report ───────────────────────────

/** Per-user task counts for the visible ids: [user_id => ['total'=>n, 'done'=>n]]. */
function task_completion_by_user(array $ids): array
{
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $rows = db_all("SELECT user_id, status, COUNT(*) c FROM tasks
                     WHERE user_id IN ($in) GROUP BY user_id, status", $ids);
    $out = [];
    foreach ($rows as $r) {
        $uid = (int) $r['user_id'];
        $out[$uid]['total'] = ($out[$uid]['total'] ?? 0) + (int) $r['c'];
        if ($r['status'] === 'done') {
            $out[$uid]['done'] = ($out[$uid]['done'] ?? 0) + (int) $r['c'];
        }
    }
    return $out;
}

/**
 * Per-user efficiency (activity %) and effectiveness (activity % blended with
 * task completion %) rows for a period, scoped by visible_user_ids() the same
 * way Team/Payroll are. This is a new view over existing primitives
 * (summarize(), sessions_for_users(), the tasks table) — no new tracking data.
 */
function efficiency_rows(array $u, string $start, string $end): array
{
    $ids = visible_user_ids($u);
    $usersById = array_intersect_key(users_by_id((int) $u['org_id']), array_flip($ids));
    $sessions = sessions_for_users($ids, $start, $end);
    $byUser = [];
    foreach ($sessions as $s) {
        $byUser[(int) $s['user_id']][] = $s;
    }
    $tasks = task_completion_by_user($ids);
    $canRates = can($u, 'view_rates');

    $rows = [];
    foreach ($usersById as $id => $m) {
        $sum = summarize($byUser[$id] ?? []);
        $tasksTotal = $tasks[$id]['total'] ?? 0;
        $tasksDone = $tasks[$id]['done'] ?? 0;
        $taskPct = $tasksTotal ? (int) round(100 * $tasksDone / $tasksTotal) : 0;
        // Blend activity% with task-completion% when the person has tasks assigned;
        // fall back to activity% alone (no tasks isn't a strike against them).
        // Someone with neither tracked time nor tasks (e.g. an admin/HR/IT role
        // that doesn't track time) has nothing to score — null, not a punitive 0%.
        $hasData = $sum['count'] > 0 || $tasksTotal > 0;
        $effectiveness = !$hasData ? null : ($tasksTotal ? (int) round(($sum['activity_pct'] + $taskPct) / 2) : $sum['activity_pct']);
        $rows[] = [
            'id' => $id, 'name' => $m['name'], 'role' => $m['role'],
            'active_s' => $sum['active_s'], 'inactive_s' => $sum['inactive_s'],
            'activity_pct' => $sum['activity_pct'], 'sessions' => $sum['count'],
            'tasks_total' => $tasksTotal, 'tasks_done' => $tasksDone, 'task_completion_pct' => $taskPct,
            'effectiveness_pct' => $effectiveness,
            'cost' => $canRates ? $sum['active_s'] / 3600.0 * user_hourly_rate($m) : null,
            'currency' => $m['currency'] ?? 'USD',
        ];
    }
    usort($rows, fn($a, $b) => ($b['effectiveness_pct'] ?? -1) <=> ($a['effectiveness_pct'] ?? -1));
    return $rows;
}

function dash_efficiency(): void
{
    $u = require_cap('reports');
    if ($u['role'] === 'client_viewer') {
        redirect('/app/overview');   // internal performance report — not for client portals
    }
    [$start, $end, $period, $periodDate] = period_range_from_request('month', ['week', 'pay', 'month']);
    $rows = efficiency_rows($u, $start, $end);

    $orgActive = array_sum(array_column($rows, 'active_s'));
    $orgInactive = array_sum(array_column($rows, 'inactive_s'));
    $scored = array_filter($rows, fn($r) => $r['effectiveness_pct'] !== null);
    $orgAvgEffectiveness = $scored ? (int) round(array_sum(array_column($scored, 'effectiveness_pct')) / count($scored)) : 0;

    view('dashboard/efficiency', array_merge(nav_context($u), [
        'title' => 'Efficiency report', 'active' => 'efficiency',
        'period' => $period, 'period_date' => $periodDate,
        'rows' => $rows, 'can_rates' => can($u, 'view_rates'),
        'org_active_s' => $orgActive, 'org_inactive_s' => $orgInactive,
        'org_avg_effectiveness' => $orgAvgEffectiveness,
    ]));
}

function dash_efficiency_csv(): void
{
    $u = require_cap('reports');
    if ($u['role'] === 'client_viewer') {
        redirect('/app/overview');   // internal performance report — not for client portals
    }
    [$start, $end, $period] = period_range_from_request('month', ['week', 'pay', 'month']);
    $rows = efficiency_rows($u, $start, $end);

    $out = fopen('php://temp', 'r+');
    fputcsv($out, ['Employee efficiency & effectiveness — period: ' . $period]);
    fputcsv($out, ['Name', 'Role', 'Active (h)', 'Inactive (h)', 'Activity %', 'Sessions',
                   'Tasks done', 'Tasks total', 'Task completion %', 'Effectiveness %']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['name'], role_label($r['role']), round($r['active_s'] / 3600, 2), round($r['inactive_s'] / 3600, 2),
            $r['activity_pct'], $r['sessions'], $r['tasks_done'], $r['tasks_total'],
            $r['task_completion_pct'], $r['effectiveness_pct'] ?? 'n/a',
        ]);
    }
    rewind($out);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="deskpulse-efficiency-' . $period . '.csv"');
    echo stream_get_contents($out);
    exit;
}

// ─────────────────────────── Profile (all users) ───────────────────────────

/**
 * First-login password reset for temp-password accounts (e.g. client portal logins).
 * The require_login() gate routes such users here until they set their own password.
 */
function dash_change_password(): void
{
    $u = require_login();
    if (request_method() === 'POST') {
        check_csrf();
        $pass    = $_POST['password'] ?? '';
        $confirm = $_POST['password_confirm'] ?? '';
        $error = null;
        if (strlen($pass) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($pass !== $confirm) {
            $error = 'The two passwords do not match.';
        }
        if ($error) {
            flash($error, 'error');
        } else {
            db_exec('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?',
                [password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
            flash('Password set — welcome to DeskPulse.', 'success');
            redirect('/app');
        }
        redirect('/app/change-password');
    }
    view('auth/change_password', ['title' => 'Set your password', 'user' => $u], 'layout_public');
}

function dash_profile(): void
{
    $u = require_login();
    if (request_method() === 'POST') {
        check_csrf();
        $name = trim($_POST['name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $error = null;
        if (!$name || !$email) {
            $error = 'Name and email are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address.';
        } else {
            $dupe = db_one('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $u['id']]);
            if ($dupe) {
                $error = 'That email is already in use.';
            }
        }
        $pass = $_POST['password'] ?? '';
        if (!$error && $pass !== '' && strlen($pass) < 8) {
            $error = 'New password must be at least 8 characters.';
        }
        if ($error) {
            flash($error, 'error');
        } else {
            db_exec('UPDATE users SET name = ?, email = ?, phone = ?, job_title = ? WHERE id = ?',
                [$name, $email, substr($_POST['phone'] ?? '', 0, 40),
                 substr($_POST['job_title'] ?? '', 0, 120), $u['id']]);
            if ($pass !== '') {
                db_exec('UPDATE users SET password_hash = ? WHERE id = ?',
                    [password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
            }
            flash('Profile updated.', 'success');
        }
        redirect('/app/profile');
    }
    $me = db_one('SELECT * FROM users WHERE id = ?', [$u['id']]);
    $org = db_one('SELECT name FROM organizations WHERE id = ?', [$u['org_id']]);
    view('dashboard/profile', array_merge(nav_context($u), [
        'title' => 'My profile', 'active' => 'profile', 'me' => $me, 'org' => $org,
        'can_rates' => can($u, 'view_rates'),
    ]));
}

// ─────────────────────────── Platform (super admin only) ───────────────────────────

/**
 * Wipe every tenant's data, keeping only the super admin account(s) and the platform
 * org. Deleting the other organizations cascades their users, teams, clients,
 * contracts, sessions, devices, screenshots, share links, etc. Returns [ok, message].
 */
function platform_reset_data(array $u): array
{
    $platformOrg = (int) (db_one('SELECT org_id FROM users WHERE id = ?', [$u['id']])['org_id'] ?? $u['org_id']);
    db_exec('DELETE FROM organizations WHERE id <> ?', [$platformOrg]);   // cascades all tenant data
    db_exec("DELETE FROM users WHERE role <> 'super_admin'");             // any stragglers in the platform org
    return [true, 'All tenant data deleted — only the super admin account remains.'];
}

/**
 * Apply an uploaded SQL script to the current database (super-admin DB maintenance:
 * migrations / schema updates / data fixes). Strips `--` and `#` comments, splits on
 * `;` and runs each statement, reporting how many succeeded/failed. This is a simple
 * splitter, not a full SQL parser — it won't handle stored-procedure DELIMITER blocks.
 * Returns [ok, message].
 */
function platform_run_sql_file(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return [false, 'No SQL file was uploaded.'];
    }
    if (($file['size'] ?? 0) > 16 * 1024 * 1024) {
        return [false, 'SQL file is too large (max 16 MB).'];
    }
    $sql = @file_get_contents($file['tmp_name']);
    if ($sql === false || trim($sql) === '') {
        return [false, 'Could not read the SQL file, or it was empty.'];
    }
    // Strip `--` / `#` comments (mirrors ensure_schema), then split into statements.
    $clean = [];
    foreach (explode("\n", $sql) as $line) {
        $pos = strpos($line, '--');
        if ($pos !== false) {
            $line = substr($line, 0, $pos);
        }
        if (str_starts_with(ltrim($line), '#')) {
            continue;
        }
        $clean[] = $line;
    }
    $statements = array_filter(array_map('trim', explode(';', implode("\n", $clean))));
    $ok = 0;
    $errors = [];
    foreach ($statements as $stmt) {
        if ($stmt === '') {
            continue;
        }
        try {
            db()->exec($stmt);
            $ok++;
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
    if ($errors) {
        return [false, "Ran {$ok} statement(s); " . count($errors) . ' failed — '
            . implode(' | ', array_slice($errors, 0, 3)) . (count($errors) > 3 ? ' …' : '')];
    }
    return [true, "Database updated — {$ok} statement(s) executed successfully."];
}

/**
 * Create a complete demo organization (admin/manager/HR/IT/members + a client portal
 * login, teams, clients, contracts, agent assignments, tasks and a week of tracked
 * sessions with activity/windows/idle) so every dashboard has data to explore.
 * Idempotent: does nothing if the demo org already exists. Returns [ok, message].
 */
function seed_demo_data(): array
{
    if (db_one('SELECT id FROM organizations WHERE name = ?', ['DeskPulse Demo Co'])) {
        return [false, 'Demo data already present — "DeskPulse Demo Co" exists. Reset first to reseed.'];
    }
    // A small placeholder PNG generator (GD if available, else a 1×1 fallback) so the
    // demo has real on-disk screenshots and a company logo, exercising those features.
    $makePng = function (string $abs, int $w, int $h, string $label): bool {
        @mkdir(dirname($abs), 0775, true);
        if (function_exists('imagecreatetruecolor')) {
            $im = imagecreatetruecolor($w, $h);
            $bg = imagecolorallocate($im, 30, 41, 59);
            $ac = imagecolorallocate($im, 45, 212, 191);
            $fg = imagecolorallocate($im, 203, 213, 225);
            imagefilledrectangle($im, 0, 0, $w, $h, $bg);
            imagefilledrectangle($im, 0, 0, $w, 6, $ac);
            imagestring($im, 5, 12, (int) ($h / 2) - 8, substr($label, 0, 40), $fg);
            $ok = @imagepng($im, $abs);
            imagedestroy($im);
            return (bool) $ok;
        }
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC');
        return @file_put_contents($abs, $png) !== false;
    };

    db()->beginTransaction();
    try {
        // Organization: approved, onboarded, with a real monitoring policy + platform
        // billing so the policy, Platform billing and branding features are all populated.
        $orgId = db_exec("INSERT INTO organizations
            (name, status, onboarded_at, screenshot_interval_min, screenshot_blur, idle_threshold_min,
             track_screenshots, track_windows, track_processes, screenshot_retention_days,
             billing_status, monthly_fee, plan_type, discount_pct, billing_currency, billing_started_at,
             subscription_status, current_period_end)
            VALUES (?, 'approved', UTC_TIMESTAMP(), 8, 1, 10, 1, 1, 1, 30,
             'active', 99, 'organization', 10, 'USD', UTC_TIMESTAMP(),
             'active', DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 MONTH))",
            ['DeskPulse Demo Co']);

        $mk = function ($name, $email, $role, $payType, $payRate, $billType, $billRate, $mustChange = 0) use ($orgId) {
            return db_exec(
                'INSERT INTO users (org_id, name, email, password_hash, role, pay_type, pay_rate, bill_type, bill_rate, currency, must_change_password)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "USD", ?)',
                [$orgId, $name, $email, password_hash('Demo12345', PASSWORD_DEFAULT), $role,
                 $payType, $payRate, $billType, $billRate, $mustChange]
            );
        };
        // A work schedule + profile (Mon–Fri 09:00–17:00) so schedules, overtime and
        // payslips have something to work with.
        $profile = function ($uid, $title, $phone) {
            db_exec("UPDATE users SET job_title = ?, phone = ?, work_start = '09:00:00',
                     work_end = '17:00:00', work_days = '1,2,3,4,5' WHERE id = ?", [$title, $phone, $uid]);
        };
        $admin = $mk('Cara Admin',   'admin@demo.test',   'client_admin', 'monthly', 7000, 'monthly', 12000);
        $mgr = $mk('Maria Manager', 'manager@demo.test', 'manager', 'monthly', 4500, 'hourly', 45);
        $hr  = $mk('Hank HR',      'hr@demo.test',      'hr_manager', 'monthly', 3800, 'hourly', 0);
        $it  = $mk('Ivy IT',       'it@demo.test',      'it_admin',   'monthly', 4000, 'hourly', 0);
        $ava   = $mk('Ava Reyes',  'ava@demo.test',   'member', 'hourly', 22, 'hourly', 40);
        $ben   = $mk('Ben Cruz',   'ben@demo.test',   'member', 'hourly', 25, 'hourly', 45);
        $carla = $mk('Carla Diaz', 'carla@demo.test', 'member', 'monthly', 3200, 'monthly', 5500);
        $members = [$ava, $ben, $carla];
        $profile($mgr, 'Team Manager', '+1 555 0100');
        $profile($ava, 'Support Agent', '+1 555 0111');
        $profile($ben, 'Front-end Developer', '+1 555 0122');
        $profile($carla, 'Account Manager', '+1 555 0133');

        // Team
        $teamId = db_exec('INSERT INTO teams (org_id, name) VALUES (?, ?)', [$orgId, 'Remote Squad']);
        foreach ([$mgr, $ava, $ben, $carla] as $uid) {
            db_exec('INSERT INTO team_members (team_id, user_id) VALUES (?, ?)', [$teamId, $uid]);
        }

        // Clients (with bill rates + a read-only portal login on Acme) and contracts.
        $acme = db_exec('INSERT INTO clients (org_id, name, contact_email, notes, bill_rate, currency) VALUES (?, ?, ?, ?, ?, "USD")',
            [$orgId, 'Acme Corp', 'ops@acme.test', 'Flagship client', 50]);
        $globex = db_exec('INSERT INTO clients (org_id, name, contact_email, bill_rate, currency) VALUES (?, ?, ?, ?, "USD")',
            [$orgId, 'Globex LLC', 'pm@globex.test', 60]);
        $acmePortal = $mk('Acme Corp (portal)', 'ops@acme.test', 'client_viewer', 'hourly', 0, 'hourly', 0, 0);
        db_exec('UPDATE clients SET user_id = ? WHERE id = ?', [$acmePortal, $acme]);
        $acmeContract = db_exec('INSERT INTO contracts (org_id, client_id, title, bill_rate, currency, status, start_date)
                 VALUES (?, ?, ?, ?, "USD", "active", ?)', [$orgId, $acme, 'Acme Support Retainer', 50, date('Y-m-01')]);
        $globexContract = db_exec('INSERT INTO contracts (org_id, client_id, title, bill_rate, currency, status, start_date)
                 VALUES (?, ?, ?, ?, "USD", "active", ?)', [$orgId, $globex, 'Globex Web Build', 60, date('Y-m-01')]);
        // An ended contract too, so the Contracts page shows both states.
        db_exec('INSERT INTO contracts (org_id, client_id, title, bill_rate, currency, status, start_date, end_date)
                 VALUES (?, ?, ?, ?, "USD", "ended", ?, ?)',
            [$orgId, $acme, 'Acme 2024 Migration', 55, date('Y-m-01', strtotime('-8 months')), date('Y-m-t', strtotime('-2 months'))]);

        // Agent ↔ client assignment (scopes each client's portal & reporting).
        foreach ([$ava, $ben] as $uid) {
            db_exec('INSERT INTO agent_clients (agent_id, client_id) VALUES (?, ?)', [$uid, $acme]);
        }
        db_exec('INSERT INTO agent_clients (agent_id, client_id) VALUES (?, ?)', [$carla, $globex]);

        // Contract rosters (contract_members).
        foreach ([$ava, $ben] as $uid) {
            db_exec('INSERT IGNORE INTO contract_members (contract_id, user_id) VALUES (?, ?)', [$acmeContract, $uid]);
        }
        db_exec('INSERT IGNORE INTO contract_members (contract_id, user_id) VALUES (?, ?)', [$globexContract, $carla]);

        $clients = [$acme, $globex];

        // Devices (one per tracked worker) — populates Devices, agent detail + audit.
        $deviceByUser = [];
        foreach ([$mgr => 'Maria — Laptop', $ava => "Ava — Desktop", $ben => "Ben — Laptop", $carla => "Carla — Laptop"] as $uid => $dname) {
            $deviceByUser[$uid] = db_exec(
                'INSERT INTO devices (user_id, name, secret, last_seen) VALUES (?, ?, ?, ?)',
                [$uid, $dname, random_token(32), gmdate('Y-m-d H:i:s', strtotime('-2 hours'))]);
        }

        // Tasks: an open (current) task and a completed one per member, tagged to a client.
        $taskByUser = [];
        $taskTitles = ['Triage support tickets', 'Build landing page', 'Weekly client report'];
        $doneTitles = ['Onboard new client', 'Fix checkout bug', 'Q2 activity summary'];
        foreach ($members as $i => $uid) {
            $taskByUser[$uid] = db_exec('INSERT INTO tasks (org_id, user_id, client_id, title, status) VALUES (?, ?, ?, ?, "open")',
                [$orgId, $uid, $clients[$i % 2], $taskTitles[$i % 3]]);
            db_exec('INSERT INTO tasks (org_id, user_id, client_id, title, status) VALUES (?, ?, ?, ?, "done")',
                [$orgId, $uid, $clients[$i % 2], $doneTitles[$i % 3]]);
        }

        // A week of sample sessions with activity / windows / idle / screenshots. Each
        // seeded session is marked overtime_computed=1 so the nightly recompute leaves
        // our controlled overtime values alone.
        $apps = [
            ['chrome.exe', 'Acme Helpdesk — Google Chrome'],
            ['Code.exe', 'project — Visual Studio Code'],
            ['slack.exe', 'Slack | #remote-squad'],
            ['zoom.exe', 'Zoom Meeting'],
        ];
        for ($d = 6; $d >= 0; $d--) {
            foreach ($members as $i => $uid) {
                $dayStart = strtotime("-$d days", strtotime(date('Y-m-d') . ' 09:00:00'));
                if (date('N', $dayStart) >= 6) {
                    continue;   // skip weekends
                }
                $activeS = 3600 * (5 + ($i + $d) % 3);
                $inactiveS = 600 * (1 + ($i + $d) % 4);
                $ended = $dayStart + $activeS + $inactiveS;
                // Controlled overtime: two members accrue pending OT on the latest day
                // (an older session is pre-approved after the loop, below).
                $ot = ($d === 0 && $i < 2) ? 3600 : 0;
                $otStatus = $ot > 0 ? 'pending' : 'none';
                $sid = db_exec(
                    'INSERT INTO sessions
                        (user_id, device_id, client_id, task_id, started_at, ended_at, last_seen_at,
                         active_s, inactive_s, source, approval_status,
                         overtime_s, overtime_status, overtime_computed)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "agent", "approved", ?, ?, 1)',
                    [$uid, $deviceByUser[$uid] ?? null, $clients[$i % 2], $taskByUser[$uid] ?? null,
                     gmdate('Y-m-d H:i:s', $dayStart), gmdate('Y-m-d H:i:s', $ended), gmdate('Y-m-d H:i:s', $ended),
                     $activeS, $inactiveS, $ot, $otStatus]
                );
                $t = $dayStart;
                foreach ($apps as $a) {
                    $focus = 1800 + 600 * ($i % 3);
                    db_exec('INSERT INTO window_events (session_id, ts, app_name, window_title, focus_seconds) VALUES (?, ?, ?, ?, ?)',
                        [$sid, gmdate('Y-m-d H:i:s', $t), $a[0], $a[1], $focus]);
                    $t += $focus;
                }
                for ($s = 0; $s < 8; $s++) {
                    db_exec('INSERT INTO activity_samples (session_id, ts, keyboard_count, mouse_count, activity_pct) VALUES (?, ?, ?, ?, ?)',
                        [$sid, gmdate('Y-m-d H:i:s', $dayStart + $s * 1800),
                         50 + ($s * 7) % 60, 30 + ($s * 5) % 40, 40 + ($s * 11) % 55]);
                }
                foreach (['chrome.exe', 'Code.exe', 'slack.exe', 'explorer.exe'] as $pi => $pn) {
                    db_exec('INSERT INTO process_snapshots (session_id, ts, app_name, pid) VALUES (?, ?, ?, ?)',
                        [$sid, gmdate('Y-m-d H:i:s', $dayStart), $pn, 1000 + $pi]);
                }
                db_exec('INSERT INTO idle_periods (session_id, start_ts, end_ts, duration_s) VALUES (?, ?, ?, ?)',
                    [$sid, gmdate('Y-m-d H:i:s', $dayStart + 3600),
                     gmdate('Y-m-d H:i:s', $dayStart + 3600 + $inactiveS), $inactiveS]);

                // Screenshots (on disk + rows) for the two most recent days.
                if ($d <= 1) {
                    for ($k = 0; $k < 2; $k++) {
                        $shotTs = $dayStart + 3600 * ($k + 1);
                        $rel = $uid . '/' . $sid . '/' . random_token(8) . '.png';
                        if ($makePng(upload_path($rel), 480, 270, 'DEMO ' . gmdate('H:i', $shotTs))) {
                            db_exec('INSERT INTO screenshots (session_id, ts, file_path, blurred, monitor) VALUES (?, ?, ?, ?, ?)',
                                [$sid, gmdate('Y-m-d H:i:s', $shotTs), $rel, $k === 0 ? 1 : 0, $k]);
                        }
                    }
                }
            }
        }

        // Pre-approve an older overtime block for Ava so payroll/payslip show credited
        // (HR-approved) overtime, not just pending. Deterministic regardless of weekdays.
        $avaSess = db_one("SELECT id FROM sessions WHERE user_id = ? AND source = 'agent' ORDER BY started_at ASC LIMIT 1", [$ava]);
        if ($avaSess) {
            db_exec("UPDATE sessions SET overtime_s = 1800, overtime_status = 'approved',
                     overtime_reviewed_by_id = ?, overtime_reviewed_at = ?, overtime_computed = 1 WHERE id = ?",
                [$hr, gmdate('Y-m-d H:i:s'), $avaSess['id']]);
        }

        // A pending manual entry (so Approvals has something to review).
        db_exec(
            'INSERT INTO sessions (user_id, client_id, started_at, ended_at, active_s, inactive_s, source, note, approval_status, overtime_computed)
             VALUES (?, ?, ?, ?, ?, 0, "manual", ?, "pending", 1)',
            [$ava, $acme, gmdate('Y-m-d H:i:s', strtotime('-1 day 14:00')),
             gmdate('Y-m-d H:i:s', strtotime('-1 day 15:30')), 5400, 'Client call I forgot to track']
        );
        // A rejected manual entry (reviewed by the manager) so Approvals shows history.
        db_exec(
            'INSERT INTO sessions (user_id, client_id, started_at, ended_at, active_s, inactive_s, source, note,
                approval_status, reviewed_by_id, reviewed_at, overtime_computed)
             VALUES (?, ?, ?, ?, ?, 0, "manual", ?, "rejected", ?, ?, 1)',
            [$ben, $acme, gmdate('Y-m-d H:i:s', strtotime('-3 day 18:00')),
             gmdate('Y-m-d H:i:s', strtotime('-3 day 19:00')), 3600, 'Duplicate of tracked time',
             $mgr, gmdate('Y-m-d H:i:s', strtotime('-2 day 09:30'))]
        );

        // Public share links: team scope + an org-wide scope.
        db_exec('INSERT INTO share_links (org_id, scope, target_id, token, label, period_default) VALUES (?, "team", ?, ?, ?, "week")',
            [$orgId, $teamId, random_token(18), 'Remote Squad — weekly']);
        db_exec('INSERT INTO share_links (org_id, scope, target_id, token, label, period_default) VALUES (?, "org", ?, ?, ?, "month")',
            [$orgId, $orgId, random_token(18), 'DeskPulse Demo Co — monthly']);

        // Company branding logo (best-effort; skipped if the image can't be written).
        $logoRel = 'logos/demo-' . random_token(6) . '.png';
        if ($makePng(upload_path($logoRel), 240, 80, 'Demo Co')) {
            db_exec('UPDATE organizations SET logo_path = ? WHERE id = ?', [$logoRel, $orgId]);
        }

        ensure_personal_links($orgId);
        db()->commit();
    } catch (\Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        return [false, 'Seeding failed: ' . $e->getMessage()];
    }
    return [true, 'Demo org "DeskPulse Demo Co" seeded with schedules, overtime, devices, '
        . 'screenshots, contracts and billing. Sign in (act-as the org) with '
        . 'admin@demo.test / manager@demo.test / hr@demo.test / ava@demo.test — password Demo12345. '
        . 'Client portal: ops@acme.test / Demo12345.'];
}

function dash_platform(): void
{
    $u = require_super();
    if (request_method() === 'POST') {
        check_csrf();
        $action = $_POST['action'] ?? '';
        $orgId = (int) ($_POST['org_id'] ?? 0);
        if ($action === 'approve_org' || $action === 'reject_org') {
            $decision = $action === 'approve_org' ? 'approved' : 'rejected';
            $org = db_one('SELECT name FROM organizations WHERE id = ?', [$orgId]) ?? [];
            db_exec('UPDATE organizations SET status = ?, reviewed_at = NOW() WHERE id = ?', [$decision, $orgId]);
            notify_sales('signup.' . $decision, 'Signup ' . $decision . ': ' . ($org['name'] ?? ('org #' . $orgId)), [
                'Organization' => $org['name'] ?? ('#' . $orgId),
                'Decision'     => $decision,
                'Reviewed by'  => $u['name'] . ' (super admin)',
            ], (int) $orgId);
            flash('Signup ' . $decision . '.', 'success');
        } elseif ($action === 'create_org') {
            // Super admin provisions a customer org + its company-admin login directly
            // (mirrors handle_register(), but starts approved and skips self-signup).
            $company = trim($_POST['company'] ?? '');
            $name    = trim($_POST['admin_name'] ?? '');
            $email   = strtolower(trim($_POST['admin_email'] ?? ''));
            $pass    = $_POST['admin_password'] ?? '';
            $plan    = plan_type_clean($_POST['plan'] ?? null);
            $err = null;
            if ($company === '' || $name === '' || $email === '') {
                $err = 'Organization name, admin name and admin email are required.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $err = 'Enter a valid admin email address.';
            } elseif (db_one('SELECT id FROM users WHERE email = ?', [$email])) {
                $err = 'That admin email is already registered.';
            } elseif ($pass !== '' && strlen($pass) < 8) {
                $err = 'Password must be at least 8 characters (or leave blank to auto-generate).';
            }
            if ($err) {
                flash($err, 'error');
            } else {
                $mustChange = 0;
                $tempNote = '';
                if ($pass === '') {
                    $pass = random_token(10);
                    $mustChange = 1;
                    $tempNote = ' Temporary password: ' . $pass . ' (the admin must reset it on first sign-in).';
                }
                $newOrg = db_exec("INSERT INTO organizations (name, status, reviewed_at) VALUES (?, 'approved', NOW())",
                    [$company]);
                db_exec('INSERT INTO users (org_id, name, email, password_hash, role, must_change_password)
                         VALUES (?, ?, ?, ?, ?, ?)',
                    [$newOrg, $name, $email, password_hash($pass, PASSWORD_DEFAULT), 'client_admin', $mustChange]);
                ensure_personal_links($newOrg);
                start_trial($newOrg, $plan);
                notify_sales('signup.manual', 'New organization created: ' . $company, [
                    'Organization' => $company,
                    'Plan'         => $plan,
                    'Contact'      => $name,
                    'Email'        => $email,
                    'Created by'   => $u['name'] . ' (super admin)',
                ], (int) $newOrg);
                flash('Organization "' . $company . '" created with admin ' . $email . '.' . $tempNote, 'success');
            }
        } elseif ($action === 'delete_org') {
            db_exec('DELETE FROM organizations WHERE id = ?', [$orgId]);   // cascades
            flash('Organization deleted.', 'success');
        } elseif ($action === 'start_billing') {
            $org = db_one('SELECT name, plan_type, discount_pct FROM organizations WHERE id = ?', [$orgId]) ?? [];
            $fee = effective_monthly_fee($org);
            db_exec("UPDATE organizations SET billing_status = 'active', monthly_fee = ?,
                     billing_started_at = COALESCE(billing_started_at, NOW()) WHERE id = ?",
                [$fee, $orgId]);
            notify_sales('subscription.billing_started', 'Billing started: ' . ($org['name'] ?? ('org #' . $orgId)), [
                'Organization' => $org['name'] ?? ('#' . $orgId),
                'Plan'         => $org['plan_type'] ?? '',
                'Monthly fee'  => number_format((float) $fee, 2),
                'Started by'   => $u['name'] . ' (super admin)',
            ], (int) $orgId);
            flash('Billing started.', 'success');
        } elseif ($action === 'stop_billing') {
            $org = db_one('SELECT name FROM organizations WHERE id = ?', [$orgId]) ?? [];
            db_exec("UPDATE organizations SET billing_status = 'paused' WHERE id = ?", [$orgId]);
            notify_sales('subscription.billing_paused', 'Billing paused: ' . ($org['name'] ?? ('org #' . $orgId)), [
                'Organization' => $org['name'] ?? ('#' . $orgId),
                'Paused by'    => $u['name'] . ' (super admin)',
            ], (int) $orgId);
            flash('Billing paused.', 'success');
        } elseif ($action === 'set_role') {
            $role = in_array($_POST['role'] ?? '',
                ['super_admin', 'client_admin', 'manager', 'hr_manager', 'it_admin', 'member', 'client_viewer'], true)
                ? $_POST['role'] : 'member';
            db_exec('UPDATE users SET role = ? WHERE id = ?', [$role, (int) ($_POST['user_id'] ?? 0)]);
            flash('Role updated.', 'success');
        } elseif ($action === 'delete_user') {
            $uid = (int) ($_POST['user_id'] ?? 0);
            if ($uid !== (int) $u['id']) {
                db_exec('DELETE FROM users WHERE id = ?', [$uid]);
                flash('User deleted.', 'success');
            }
        } elseif ($action === 'seed_demo') {
            [$ok, $msg] = seed_demo_data();
            flash($msg, $ok ? 'success' : 'error');
        } elseif ($action === 'reset_data') {
            [$ok, $msg] = platform_reset_data($u);
            flash($msg, $ok ? 'success' : 'error');
        } elseif ($action === 'update_db') {
            [$ok, $msg] = platform_run_sql_file($_FILES['sqlfile'] ?? []);
            flash($msg, $ok ? 'success' : 'error');
        }
        // Management forms live on sub-pages; bounce back to where they posted from.
        $dest = ['orgs' => '/app/platform/orgs', 'accounts' => '/app/platform/accounts',
                 'overview' => '/app/overview'];
        redirect($dest[$_POST['return'] ?? ''] ?? '/app/platform');
    }

    // ── At-a-glance stats ──
    [$wkStart, $wkEnd] = period_range('week');
    $os = db_one("SELECT COUNT(*) total,
        SUM(status = 'pending') pending,
        SUM(billing_status = 'active') billing_active
        FROM organizations");
    // MRR = sum of this cycle's seat-based dues across actively-billed customers.
    $mrr = 0.0;
    foreach (db_all("SELECT * FROM organizations WHERE billing_status = 'active'") as $bo) {
        $mrr += customer_billing($bo)['due'];
    }
    $stats = [
        'orgs'           => (int) $os['total'],
        'pending'        => (int) $os['pending'],
        'billing_active' => (int) $os['billing_active'],
        'mrr'            => $mrr,
        'users'          => (int) db_one("SELECT COUNT(*) c FROM users WHERE role <> 'super_admin'")['c'],
        'open_now'       => (int) db_one('SELECT COUNT(*) c FROM sessions WHERE ended_at IS NULL')['c'],
        'week_secs'      => (int) db_one('SELECT COALESCE(SUM(active_s), 0) s FROM sessions
                                          WHERE started_at >= ? AND started_at < ?', [$wkStart, $wkEnd])['s'],
    ];

    // ── Quick actions: signups awaiting review ──
    $pending = db_all("SELECT * FROM organizations WHERE status = 'pending' ORDER BY created_at DESC");
    foreach ($pending as &$p) {
        $owner = db_one("SELECT name, email FROM users WHERE org_id = ?
                         ORDER BY (role = 'client_admin') DESC, id ASC LIMIT 1", [$p['id']]);
        $p['owner_name']  = $owner['name'] ?? '—';
        $p['owner_email'] = $owner['email'] ?? '';
        $p['users']       = (int) db_one('SELECT COUNT(*) c FROM users WHERE org_id = ?', [$p['id']])['c'];
    }
    unset($p);

    // ── Site traffic: daily active users over the last 14 days ──
    $since = gmdate('Y-m-d 00:00:00', strtotime('today -13 days'));
    $byDay = [];
    foreach (db_all('SELECT DATE(started_at) d, COUNT(DISTINCT user_id) c FROM sessions
                     WHERE started_at >= ? GROUP BY DATE(started_at)', [$since]) as $r) {
        $byDay[$r['d']] = (int) $r['c'];
    }
    $traffic = ['labels' => [], 'values' => []];
    for ($i = 13; $i >= 0; $i--) {
        $day = gmdate('Y-m-d', strtotime("today -$i days"));
        $traffic['labels'][] = $day;
        $traffic['values'][] = $byDay[$day] ?? 0;
    }

    // ── Employee activity across every org, last 7 days (JS timeline) ──
    $allUsers = array_map('intval', array_column(
        db_all("SELECT id FROM users WHERE role <> 'super_admin'"), 'id'));
    $timeline = activity_timeline($allUsers,
        gmdate('Y-m-d H:i:s', strtotime('today -6 days')),
        gmdate('Y-m-d H:i:s', strtotime('tomorrow')));

    view('dashboard/platform', [
        'title' => 'Platform', 'active' => 'platform', 'user' => $u, 'is_super' => true,
        'is_manager' => true, 'pending_count' => 0, 'acting_org' => null,
        'stats' => $stats, 'pending' => $pending, 'traffic' => $traffic,
        'timeline' => $timeline, 'audit' => platform_audit_feed(40),
    ]);
}

/** A cross-org activity feed for the platform console, newest first. Derived from
 *  signups, reviews, new accounts, device registrations and billing changes. */
function platform_audit_feed(int $limit = 40): array
{
    $ev = [];
    foreach (db_all('SELECT name, created_at ts FROM organizations ORDER BY created_at DESC LIMIT 25') as $r) {
        $ev[] = ['ts' => $r['ts'], 'org' => $r['name'], 'event' => 'Organization signed up', 'detail' => ''];
    }
    foreach (db_all('SELECT name, reviewed_at ts, status FROM organizations
                     WHERE reviewed_at IS NOT NULL ORDER BY reviewed_at DESC LIMIT 25') as $r) {
        $ev[] = ['ts' => $r['ts'], 'org' => $r['name'], 'event' => 'Signup ' . $r['status'], 'detail' => ''];
    }
    foreach (db_all("SELECT u.name, u.created_at ts, o.name org FROM users u
                     JOIN organizations o ON o.id = u.org_id WHERE u.role <> 'super_admin'
                     ORDER BY u.created_at DESC LIMIT 25") as $r) {
        $ev[] = ['ts' => $r['ts'], 'org' => $r['org'], 'event' => 'Account created', 'detail' => $r['name']];
    }
    foreach (db_all('SELECT d.created_at ts, d.name dev, o.name org FROM devices d
                     JOIN users u ON u.id = d.user_id JOIN organizations o ON o.id = u.org_id
                     ORDER BY d.created_at DESC LIMIT 25') as $r) {
        $ev[] = ['ts' => $r['ts'], 'org' => $r['org'], 'event' => 'Device registered', 'detail' => $r['dev']];
    }
    foreach (db_all("SELECT name, billing_started_at ts FROM organizations
                     WHERE billing_status = 'active' AND billing_started_at IS NOT NULL
                     ORDER BY billing_started_at DESC LIMIT 15") as $r) {
        $ev[] = ['ts' => $r['ts'], 'org' => $r['name'], 'event' => 'Billing started', 'detail' => ''];
    }
    // Remote-control sessions (super-admin only, unpublished).
    foreach (db_all("SELECT rs.started_at ts, o.name org, w.name worker, adm.name admin, d.name dev
                     FROM remote_sessions rs JOIN organizations o ON o.id = rs.org_id
                     JOIN users w ON w.id = rs.user_id
                     LEFT JOIN users adm ON adm.id = rs.admin_user_id
                     LEFT JOIN devices d ON d.id = rs.device_id
                     ORDER BY rs.started_at DESC LIMIT 25") as $r) {
        $ev[] = ['ts' => $r['ts'], 'org' => $r['org'], 'event' => 'Remote session started',
                 'detail' => ($r['admin'] ?: 'admin') . ' → ' . $r['worker']
                             . ($r['dev'] ? ' (' . $r['dev'] . ')' : '')];
    }
    foreach (db_all("SELECT rs.ended_at ts, o.name org, w.name worker, rs.end_reason
                     FROM remote_sessions rs JOIN organizations o ON o.id = rs.org_id
                     JOIN users w ON w.id = rs.user_id
                     WHERE rs.ended_at IS NOT NULL ORDER BY rs.ended_at DESC LIMIT 25") as $r) {
        $ev[] = ['ts' => $r['ts'], 'org' => $r['org'], 'event' => 'Remote session ended',
                 'detail' => $r['worker'] . ' · ' . ($r['end_reason'] ?: 'ended')];
    }
    usort($ev, fn($a, $b) => strcmp((string) $b['ts'], (string) $a['ts']));
    return array_slice($ev, 0, $limit);
}

/** Super admin: full organization management (billing, act-as, delete). */
function dash_platform_orgs(): void
{
    $u = require_super();
    [$wkStart, $wkEnd] = period_range('week');
    $rows = db_all('SELECT * FROM organizations WHERE id <> ? ORDER BY
        FIELD(status, "pending", "approved", "rejected"), created_at DESC', [platform_org_id()]);
    $orgs = [];
    foreach ($rows as $o) {
        $oid = (int) $o['id'];
        $o['users'] = (int) db_one("SELECT COUNT(*) c FROM users
                                    WHERE org_id = ? AND role <> 'super_admin'", [$oid])['c'];
        $o['week_active_s'] = (int) (db_one('SELECT SUM(s.active_s) secs FROM sessions s
            JOIN users us ON us.id = s.user_id
            WHERE us.org_id = ? AND s.started_at >= ? AND s.started_at < ?',
            [$oid, $wkStart, $wkEnd])['secs'] ?? 0);

        $teams = db_all('SELECT id, name FROM teams WHERE org_id = ? ORDER BY name', [$oid]);
        $byTeam = [];
        foreach (db_all('SELECT tm.team_id, us.id, us.name, us.role FROM team_members tm
                         JOIN users us ON us.id = tm.user_id JOIN teams t ON t.id = tm.team_id
                         WHERE t.org_id = ? ORDER BY us.name', [$oid]) as $r) {
            $byTeam[$r['team_id']][] = $r;
        }
        $unassigned = db_all(
            "SELECT id, name, role FROM users WHERE org_id = ? AND role <> 'super_admin'
             AND id NOT IN (SELECT tm.user_id FROM team_members tm
                            JOIN teams t ON t.id = tm.team_id WHERE t.org_id = ?)
             ORDER BY name", [$oid, $oid]);
        $clients = db_all('SELECT id, name, archived FROM clients WHERE org_id = ? ORDER BY archived, name', [$oid]);
        $byClient = [];
        foreach (db_all('SELECT DISTINCT s.client_id, us.id, us.name, us.role FROM sessions s
                         JOIN users us ON us.id = s.user_id
                         WHERE us.org_id = ? AND s.client_id IS NOT NULL ORDER BY us.name', [$oid]) as $r) {
            $byClient[$r['client_id']][] = $r;
        }
        $orgs[] = ['row' => $o, 'teams' => $teams, 'by_team' => $byTeam,
                   'unassigned' => $unassigned, 'clients' => $clients, 'by_client' => $byClient];
    }
    view('dashboard/platform_orgs', [
        'title' => 'Organizations', 'active' => 'platform_orgs', 'user' => $u, 'is_super' => true,
        'is_manager' => true, 'pending_count' => 0, 'acting_org' => null, 'orgs' => $orgs,
    ]);
}

/** GET /app/platform/user/{id} — full detail for one user (super-admin modal). */
function dash_platform_user(array $p): void
{
    require_super();
    $uid = (int) $p['id'];
    $usr = db_one('SELECT u.*, o.name org_name FROM users u JOIN organizations o ON o.id = u.org_id
                   WHERE u.id = ?', [$uid]);
    if (!$usr) {
        abort(404, 'user not found');
    }
    [$wkStart, $wkEnd] = period_range('week');
    [$dayStart, $dayEnd] = period_range('day');
    $week = summarize(sessions_for_users([$uid], $wkStart, $wkEnd, false));
    $day  = summarize(sessions_for_users([$uid], $dayStart, $dayEnd, false));
    $live = db_one('SELECT id, started_at FROM sessions WHERE user_id = ? AND ended_at IS NULL
                    ORDER BY started_at DESC LIMIT 1', [$uid]);
    $last = db_one('SELECT started_at FROM sessions WHERE user_id = ? ORDER BY started_at DESC LIMIT 1', [$uid]);

    json_out([
        'id'        => $uid,
        'name'      => $usr['name'],
        'email'     => $usr['email'],
        'phone'     => $usr['phone'] ?: '—',
        'job_title' => $usr['job_title'] ?: '—',
        'role'      => role_label($usr['role']),
        'org'       => $usr['org_name'],
        'pay'       => money((float) $usr['pay_rate'], $usr['currency']) . ' / ' . ($usr['pay_type'] ?: 'hourly'),
        'bill'      => money((float) $usr['bill_rate'], $usr['currency']) . ' / ' . ($usr['bill_type'] ?: 'hourly'),
        'teams'     => array_column(db_all('SELECT t.name FROM team_members tm
                         JOIN teams t ON t.id = tm.team_id WHERE tm.user_id = ? ORDER BY t.name', [$uid]), 'name'),
        'clients'   => array_column(db_all('SELECT DISTINCT c.name FROM sessions s
                         JOIN clients c ON c.id = s.client_id WHERE s.user_id = ? ORDER BY c.name', [$uid]), 'name'),
        'devices'   => db_all('SELECT id, name, created_at FROM devices WHERE user_id = ? ORDER BY created_at DESC', [$uid]),
        'today'     => ['active' => fmt_hms($day['active_s']), 'pct' => $day['activity_pct']],
        'week'      => ['active' => fmt_hms($week['active_s']), 'pct' => $week['activity_pct'], 'sessions' => $week['count']],
        'total_active' => fmt_hms((int) db_one('SELECT COALESCE(SUM(active_s),0) s FROM sessions WHERE user_id = ?', [$uid])['s']),
        'open_tasks'   => (int) db_one("SELECT COUNT(*) c FROM tasks WHERE user_id = ? AND status = 'open'", [$uid])['c'],
        'live'         => (bool) $live,
        'last_seen'    => $last['started_at'] ?? null,
    ]);
}

/** Super admin: account & role management across every organization. */
function dash_platform_accounts(): void
{
    $u = require_super();
    $users = db_all('SELECT u.*, o.name AS org_name FROM users u JOIN organizations o ON o.id = u.org_id
                     ORDER BY o.name, u.name');
    view('dashboard/platform_accounts', [
        'title' => 'Accounts', 'active' => 'platform_accounts', 'user' => $u, 'is_super' => true,
        'is_manager' => true, 'pending_count' => 0, 'acting_org' => null, 'users' => $users,
    ]);
}

/** The id of the super-admin's platform org (holds platform-wide settings). */
function platform_org_id(): int
{
    $r = db_one("SELECT org_id FROM users WHERE role = 'super_admin' ORDER BY id LIMIT 1");
    return (int) ($r['org_id'] ?? 0);
}

/**
 * The platform-wide standard subscription prices, held on the super-admin's platform
 * org row. Falls back to the product defaults.
 *
 * The ladder fields (solo / seat_cap / seats_bill_min / seats_cap_covers) all default
 * to 0 meaning "inactive". seat_cap in particular must NOT fall back to
 * `organization` — an existing per-seat tenant billing above that figure would be
 * silently re-priced the moment the column appeared.
 */
function plan_base_prices(): array
{
    $defaults = ['individual' => 9.0, 'organization' => 49.0, 'per_seat' => 5.0,
                 'seats_min' => 2, 'seats_max' => 50,
                 'solo' => 0.0, 'seat_cap' => 0.0, 'seats_bill_min' => 0, 'seats_cap_covers' => 0];
    $id = platform_org_id();
    if (!$id) {
        return $defaults;
    }
    $r = db_one('SELECT price_individual, price_organization, price_per_seat, seats_min, seats_max,
                        price_solo, price_seat_cap, seats_bill_min, seats_cap_covers
                 FROM organizations WHERE id = ?', [$id]);
    $num = fn(string $c, $d) => isset($r[$c]) ? (float) $r[$c] : $d;
    $int = fn(string $c, $d) => isset($r[$c]) ? (int) $r[$c] : $d;
    return [
        'individual'       => $num('price_individual', $defaults['individual']),
        'organization'     => $num('price_organization', $defaults['organization']),
        'per_seat'         => $num('price_per_seat', $defaults['per_seat']),
        'seats_min'        => $int('seats_min', $defaults['seats_min']),
        'seats_max'        => $int('seats_max', $defaults['seats_max']),
        'solo'             => $num('price_solo', 0.0),
        'seat_cap'         => $num('price_seat_cap', 0.0),
        'seats_bill_min'   => $int('seats_bill_min', 0),
        'seats_cap_covers' => $int('seats_cap_covers', 0),
    ];
}

/** The plan_type values the billing engine understands. */
function plan_types(): array
{
    return ['solo', 'individual', 'per_seat', 'organization', 'enterprise'];
}

/** Human labels for the plan ladder, used by every plan <select> and badge. */
function plan_labels(): array
{
    return [
        'solo'         => 'Solo (free)',
        'individual'   => 'Individual',
        'per_seat'     => 'Team (per seat)',
        'organization' => 'Organization (flat)',
        'enterprise'   => 'Enterprise (custom)',
    ];
}

/** Normalise a submitted/stored plan_type to a known value. */
function plan_type_clean(?string $plan, string $fallback = 'organization'): string
{
    return in_array((string) $plan, plan_types(), true) ? (string) $plan : $fallback;
}

/**
 * Seat count a per-seat tenant is billed for: its billable seats, raised to the
 * platform's minimum (the "5-seat minimum" on the Team plan). A floor of 0 disables it.
 */
function plan_billable_seats(int $orgId, ?array $prices = null): int
{
    $prices = $prices ?? plan_base_prices();
    return max((int) $prices['seats_bill_min'], max(1, org_seat_count($orgId)));
}

/** Seat count at which the per-seat total reaches the cap (450 / 7 → 65). 0 if uncapped. */
function plan_cap_seats(?array $prices = null): int
{
    $prices = $prices ?? plan_base_prices();
    $seat = (float) $prices['per_seat'];
    $cap  = (float) $prices['seat_cap'];
    return ($seat > 0 && $cap > 0) ? (int) ceil($cap / $seat) : 0;
}

/**
 * What the Team plan costs at a given seat count: floor → multiply → cap.
 *
 * This is the whole "per-seat pricing that stops" mechanic in one place, so billing,
 * the pricing page and the public cost calculator can never disagree about it. Pass a
 * hypothetical seat count to price a prospect; effective_monthly_fee() passes the
 * tenant's real one.
 */
function plan_seat_price(int $seats, ?array $prices = null): float
{
    $prices = $prices ?? plan_base_prices();
    $seats = max((int) $prices['seats_bill_min'], max(1, $seats));
    $total = (float) $prices['per_seat'] * $seats;
    $cap = (float) $prices['seat_cap'];
    return round($cap > 0 ? min($total, $cap) : $total, 2);
}

/** Standard (undiscounted) monthly price for a plan type. */
function plan_base_price(string $planType): float
{
    $prices = plan_base_prices();
    return $prices[$planType] ?? $prices['organization'];
}

/** Effective monthly fee for a tenant: base price for its plan, less its
 *  percentage discount. base * (1 - discount/100), rounded to cents. */
function effective_monthly_fee(array $org): float
{
    $plan = plan_type_clean($org['plan_type'] ?? null);
    $prices = plan_base_prices();
    $disc = max(0.0, min(100.0, (float) ($org['discount_pct'] ?? 0)));
    if ($plan === 'solo') {
        $base = (float) $prices['solo'];
    } elseif ($plan === 'enterprise') {
        // Negotiated per tenant. discount_pct can only price DOWN, so an Enterprise
        // deal above the cap is unrepresentable without its own column.
        $base = isset($org['custom_fee']) && $org['custom_fee'] !== null
            ? (float) $org['custom_fee']
            : (float) ($prices['seat_cap'] ?: $prices['organization']);
    } elseif ($plan === 'per_seat') {
        // Floor → multiply → cap. The cap is what makes "per-seat pricing that stops"
        // real: past plan_cap_seats() the bill is flat however many people are added.
        // $org['seats'] lets a caller price a hypothetical org without a database row.
        $seats = isset($org['seats'])
            ? (int) $org['seats']
            : (isset($org['id']) ? org_seat_count((int) $org['id']) : 0);
        $base = plan_seat_price($seats, $prices);
    } else {
        $base = (float) ($prices[$plan] ?? $prices['organization']);
    }
    // Discount applies to the CAPPED base: 10% off a 90-seat Team org is 10% off $450,
    // not 10% off an uncapped $630.
    return round($base * (1 - $disc / 100), 2);
}

/** Billable seat count for a per-seat plan: staff logins in the org (excludes the
 *  read-only client_viewer portal logins and the platform super admin). */
function org_seat_count(int $orgId): int
{
    $r = db_one("SELECT COUNT(*) c FROM users WHERE org_id = ? AND role NOT IN ('client_viewer', 'super_admin')", [$orgId]);
    return (int) ($r['c'] ?? 0);
}

/** Billable employees for a customer org: only people who actually run the
 *  tracker (members + team managers); admin staff and viewers are excluded. */
function org_billable_users(int $orgId): array
{
    return db_all("SELECT id, created_at FROM users
                   WHERE org_id = ? AND role IN ('member', 'manager')", [$orgId]);
}

/**
 * Per-customer billing for the current 30-day cycle. Each tenant pays a flat
 * monthly subscription fee for its plan type (individual vs organization), less
 * its percentage discount — the effective fee (see effective_monthly_fee()),
 * which is also mirrored into monthly_fee. The cycle window is anchored to the
 * start of service (billing_started_at) or, failing that, the first employee's
 * registration. Returns plan, base price, discount, effective fee, currency,
 * seat count (informational), cycle window and amount due this cycle.
 */
function customer_billing(array $org): array
{
    $plan  = plan_type_clean($org['plan_type'] ?? null);
    $prices = plan_base_prices();
    $base  = $plan === 'per_seat' ? $prices['per_seat'] : plan_base_price($plan);
    $disc  = max(0.0, min(100.0, (float) ($org['discount_pct'] ?? 0)));
    $fee   = effective_monthly_fee($org);
    $cur   = $org['billing_currency'] ?: 'USD';
    $users = org_billable_users((int) $org['id']);
    // Seats REPORTED must be the seats BILLED, or a per-seat row shows a count that
    // doesn't multiply out to the fee beside it. org_billable_users() counts only
    // member|manager and is still what the cycle anchor below needs.
    $billedSeats = plan_billable_seats((int) $org['id'], $prices);
    $capSeats = plan_cap_seats($prices);

    $anchor = $org['billing_started_at'] ?? null;
    if (!$anchor) {
        $earliest = null;
        foreach ($users as $usr) {
            $t = strtotime($usr['created_at'] . ' UTC');
            if ($earliest === null || $t < $earliest) {
                $earliest = $t;
            }
        }
        $anchor = $earliest !== null ? gmdate('Y-m-d H:i:s', $earliest) : null;
    }

    $out = ['seats' => $plan === 'per_seat' ? $billedSeats : count($users),
            'tracked_users' => count($users),
            'capped' => $plan === 'per_seat' && $capSeats > 0 && $billedSeats >= $capSeats,
            'plan' => $plan, 'base' => $base,
            'discount_pct' => $disc, 'rate' => $fee, 'currency' => $cur,
            'anchor' => $anchor, 'cycle_start' => null, 'cycle_end' => null, 'due' => 0.0];
    if (!$anchor || $fee <= 0) {
        return $out;
    }

    $anchorE = strtotime($anchor . ' UTC');
    $cycleLen = 30 * 86400;
    $n = max(0, (int) floor((time() - $anchorE) / $cycleLen));
    $cycleStart = $anchorE + $n * $cycleLen;
    $cycleEnd   = $cycleStart + $cycleLen;

    $out['cycle_start'] = gmdate('Y-m-d H:i:s', $cycleStart);
    $out['cycle_end']   = gmdate('Y-m-d H:i:s', $cycleEnd);
    $out['due']         = $fee;   // flat subscription fee for the cycle
    return $out;
}

/** Platform-wide screenshot retention in days (0 = keep forever). */
function screenshot_retention_days(): int
{
    $id = platform_org_id();
    if (!$id) {
        return 0;
    }
    $r = db_one('SELECT screenshot_retention_days FROM organizations WHERE id = ?', [$id]);
    return (int) ($r['screenshot_retention_days'] ?? 0);
}

/** Delete screenshots (rows + files) older than the retention window. Capped per
 *  call so it can run opportunistically without stalling a request. */
function purge_old_screenshots(int $limit = 500): int
{
    $days = screenshot_retention_days();
    if ($days <= 0) {
        return 0;
    }
    $cutoff = gmdate('Y-m-d H:i:s', strtotime("-$days days"));
    $old = db_all('SELECT id, file_path FROM screenshots WHERE ts < ? ORDER BY ts LIMIT ?',
        [$cutoff, $limit]);
    $n = 0;
    foreach ($old as $s) {
        $path = upload_path($s['file_path']);
        if (is_file($path)) {
            @unlink($path);
        }
        db_exec('DELETE FROM screenshots WHERE id = ?', [$s['id']]);
        $n++;
    }
    return $n;
}

/** Super admin: per-employee customer billing — set rates, see each customer's
 *  amount due for the current cycle. */
/**
 * Super-admin Accounting: the money view of the platform. Revenue actually collected,
 * what's outstanding, every invoice and payment, deposits that arrived without a
 * usable reference — plus the Wise connection itself (credentials, webhook, sync),
 * because the payment method and the books belong on the same screen.
 */
function dash_platform_accounting(): void
{
    $u = require_super();
    $pid = platform_org_id();

    if (request_method() === 'POST') {
        check_csrf();
        $action = $_POST['action'] ?? '';

        if ($action === 'save_bank') {
            // The live payment method: the account details customers are shown. Kept in
            // its own action so saving them can never clear the archived API credentials
            // (and vice versa) — one combined form would null out whatever it omitted.
            $t = fn (string $k, int $len) => substr(trim((string) ($_POST[$k] ?? '')), 0, $len) ?: null;
            db_exec('UPDATE organizations SET pay_enabled = ?, wise_usd_holder = ?, wise_usd_type = ?,
                            wise_usd_account = ?, wise_usd_routing = ?, wise_usd_swift = ?,
                            wise_usd_bank = ?, wise_usd_address = ? WHERE id = ?',
                [isset($_POST['pay_enabled']) ? 1 : 0,
                 $t('wise_usd_holder', 160), $t('wise_usd_type', 24), $t('wise_usd_account', 60),
                 $t('wise_usd_routing', 40), $t('wise_usd_swift', 24), $t('wise_usd_bank', 160),
                 $t('wise_usd_address', 255), $pid]);
            flash('Bank transfer details saved. Customers see them on their Subscription page.', 'success');

        } elseif ($action === 'set_pay_method') {
            $m = ($_POST['pay_method'] ?? 'bank') === 'wise_api' ? 'wise_api' : 'bank';
            db_exec('UPDATE organizations SET pay_method = ? WHERE id = ?', [$m, $pid]);
            flash($m === 'bank'
                ? 'Payment method set to direct bank transfer. The Wise API integration is archived and its webhook now refuses deliveries.'
                : 'Wise API integration re-enabled. Its webhook will accept signed deliveries again.', 'success');

        } elseif ($action === 'save_wise') {
            // A blank token means "keep the stored one" — the field renders masked, so
            // submitting the form must never wipe the credential.
            $token = trim((string) ($_POST['wise_api_token'] ?? ''));
            // Validate the webhook key here rather than letting a bad paste sit in the
            // database and fail at 3am on a real delivery.
            $pem = trim((string) ($_POST['wise_webhook_key'] ?? ''));
            if ($pem !== '') {
                [$keyOk, $keyErr] = wise_parse_public_key($pem);
                if (!$keyOk) {
                    flash('Webhook public key not saved — ' . $keyErr
                        . ' Get it from Wise\'s webhook documentation for your environment; it is not something you generate.', 'error');
                    redirect('/app/platform/accounting');
                }
            }
            $sets = ['wise_env = ?', 'wise_profile_id = ?', 'wise_balance_id = ?', 'wise_webhook_key = ?'];
            $args = [
                ($_POST['wise_env'] ?? 'sandbox') === 'live' ? 'live' : 'sandbox',
                substr(trim((string) ($_POST['wise_profile_id'] ?? '')), 0, 40) ?: null,
                substr(trim((string) ($_POST['wise_balance_id'] ?? '')), 0, 40) ?: null,
                trim((string) ($_POST['wise_webhook_key'] ?? '')) ?: null,
            ];
            if ($token !== '' && strpos($token, '•') === false) {
                $sets[] = 'wise_api_token_enc = ?';
                $args[] = dp_encrypt($token);
            }
            $args[] = $pid;
            db_exec('UPDATE organizations SET ' . implode(', ', $sets) . ' WHERE id = ?', $args);
            flash('Wise API settings saved.' . ($token !== '' && strpos($token, '•') === false
                ? ' API token stored encrypted.' : ''), 'success');

        } elseif ($action === 'gen_sca') {
            // Regenerating invalidates whatever is already uploaded to Wise, so the
            // template gates this behind a confirm when a key already exists.
            [$ok, $err, $pub] = wise_generate_sca_keypair();
            flash($ok
                ? 'SCA keypair generated. Upload the public key below to Wise '
                  . '(Settings → API tokens → Manage public keys), then re-run Sync statement.'
                : $err, $ok ? 'success' : 'error');

        } elseif ($action === 'clear_sca') {
            db_exec('UPDATE organizations SET wise_sca_public = NULL, wise_sca_private_enc = NULL,
                            wise_sca_created_at = NULL WHERE id = ?', [$pid]);
            flash('SCA keypair removed. Remember to delete the matching public key in your Wise account.', 'success');

        } elseif ($action === 'clear_token') {
            db_exec('UPDATE organizations SET wise_api_token_enc = NULL WHERE id = ?', [$pid]);
            flash('Wise API token removed.', 'success');

        } elseif ($action === 'test_wise') {
            [$ok, $err, $profiles] = wise_test_connection();
            if ($ok) {
                $names = array_map(fn ($p) => $p['name'] . ' (' . $p['type'] . ' · id ' . $p['id'] . ')', $profiles);
                flash('Wise connection OK. Profiles: ' . (implode('; ', $names) ?: 'none returned'), 'success');
            } else {
                flash('Wise connection failed: ' . $err, 'error');
            }

        } elseif ($action === 'list_balances') {
            [$ok, $err, $balances] = wise_list_balances();
            if ($ok) {
                $b = array_map(fn ($x) => $x['currency'] . ' ' . number_format($x['amount'], 2) . ' (id ' . $x['id'] . ')', $balances);
                flash('Balances: ' . (implode('; ', $b) ?: 'none'), 'success');
            } else {
                flash('Could not list balances: ' . $err, 'error');
            }

        } elseif ($action === 'sync_wise') {
            $days = max(1, min(180, (int) ($_POST['days'] ?? 30)));
            [$ok, $err, $stats] = wise_sync_statement($days);
            flash($ok
                ? sprintf('Statement synced (%d days): %d credit(s) seen, %d reconciled, %d unmatched.',
                    $days, $stats['seen'], $stats['matched'], $stats['unmatched'])
                : 'Statement sync failed: ' . $err, $ok ? 'success' : 'error');

        } elseif ($action === 'register_webhook') {
            [$ok, $err, $id] = wise_register_webhook();
            flash($ok ? 'Webhook registered with Wise (subscription ' . $id . ').'
                      : 'Could not register the webhook: ' . $err, $ok ? 'success' : 'error');

        } elseif ($action === 'assign_payment') {
            $payId = (int) ($_POST['payment_id'] ?? 0);
            $orgId = (int) ($_POST['org_id'] ?? 0);
            if ($payId && $orgId) {
                assign_payment($payId, $orgId);
                flash('Deposit assigned — subscription extended.', 'success');
            } else {
                flash('Pick an organization to assign the deposit to.', 'error');
            }

        } elseif ($action === 'confirm_claim') {
            $override = trim((string) ($_POST['amount'] ?? '')) !== ''
                ? (int) round(((float) $_POST['amount']) * 100) : null;
            [$ok, $err] = confirm_payment_claim((int) ($_POST['claim_id'] ?? 0), $u, $override,
                (string) ($_POST['review_note'] ?? ''));
            flash($ok ? 'Payment confirmed — invoice settled and the subscription extended.'
                      : ($err ?: 'Could not confirm that notification.'), $ok ? 'success' : 'error');

        } elseif ($action === 'reject_claim') {
            db_exec("UPDATE payment_claims SET status = 'rejected', reviewed_by_id = ?, reviewed_at = ?,
                            review_note = ? WHERE id = ? AND status = 'pending'",
                [$u['id'], gmdate('Y-m-d H:i:s'),
                 substr(trim((string) ($_POST['review_note'] ?? '')), 0, 2000) ?: 'Not found in our account.',
                 (int) ($_POST['claim_id'] ?? 0)]);
            flash('Payment notification rejected. The customer sees your note on their subscription page.', 'success');

        } elseif ($action === 'void_payment') {
            db_exec('DELETE FROM payments WHERE id = ? AND matched = 0', [(int) ($_POST['payment_id'] ?? 0)]);
            flash('Unassigned deposit discarded.', 'success');
        }
        redirect('/app/platform/accounting');
    }

    [$start, $end, $period, $periodDate] = period_range_from_request('month', ['week', 'pay', 'month']);
    $ctx = $GLOBALS['DP_PERIOD_CTX'];

    // Collected in the selected window, plus all-time.
    $collected = (int) (db_one('SELECT COALESCE(SUM(amount_cents),0) c FROM payments
                                WHERE matched = 1 AND occurred_at >= ? AND occurred_at < ?',
                               [$ctx['start'], $ctx['end']])['c'] ?? 0);
    $collectedAll = (int) (db_one('SELECT COALESCE(SUM(amount_cents),0) c FROM payments WHERE matched = 1')['c'] ?? 0);
    $outstanding  = (int) (db_one("SELECT COALESCE(SUM(amount_cents),0) c FROM invoices WHERE status = 'open'")['c'] ?? 0);
    $unmatchedSum = (int) (db_one('SELECT COALESCE(SUM(amount_cents),0) c FROM payments WHERE matched = 0')['c'] ?? 0);

    // MRR and subscription mix across paying tenants.
    $mrr = 0.0;
    $mix = ['active' => 0, 'trialing' => 0, 'past_due' => 0, 'none' => 0, 'canceled' => 0];
    $receivables = [];
    foreach (db_all("SELECT * FROM organizations WHERE status = 'approved' AND id <> ? ORDER BY name", [$pid]) as $o) {
        $bill = customer_billing($o);
        $st = $o['subscription_status'] ?: 'none';
        $mix[$st] = ($mix[$st] ?? 0) + 1;
        if ($st === 'active' || $o['billing_status'] === 'active') {
            $mrr += (float) $bill['due'];
        }
        $open = db_one("SELECT COALESCE(SUM(amount_cents),0) c FROM invoices WHERE org_id = ? AND status = 'open'",
            [(int) $o['id']])['c'] ?? 0;
        if ((int) $open > 0 || !sub_is_current($o)) {
            $receivables[] = ['org' => $o, 'open_cents' => (int) $open,
                              'state' => sub_state_label($o), 'due' => (float) $bill['due']];
        }
    }

    // Revenue by month for the chart (12 months of settled payments).
    $series = db_all("SELECT DATE_FORMAT(occurred_at,'%Y-%m') m, SUM(amount_cents) c
                      FROM payments WHERE matched = 1 AND occurred_at >= ?
                      GROUP BY m ORDER BY m", [gmdate('Y-m-01', strtotime('-11 months'))]);
    $labels = [];
    $values = [];
    for ($i = 11; $i >= 0; $i--) {
        $key = gmdate('Y-m', strtotime("-$i months"));
        $labels[] = gmdate('M y', strtotime("-$i months"));
        $hit = 0.0;
        foreach ($series as $row) {
            if ($row['m'] === $key) {
                $hit = ((int) $row['c']) / 100;
            }
        }
        $values[] = round($hit, 2);
    }

    view('dashboard/platform_accounting', [
        'title' => 'Accounting', 'active' => 'platform_accounting', 'user' => $u,
        'is_super' => true, 'is_manager' => true, 'pending_count' => 0, 'acting_org' => null,
        'period' => $period, 'period_date' => $periodDate, 'ctx' => $ctx,
        'collected' => $collected, 'collected_all' => $collectedAll,
        'outstanding' => $outstanding, 'unmatched_sum' => $unmatchedSum,
        'mrr' => $mrr, 'mix' => $mix, 'receivables' => $receivables,
        'chart_labels' => $labels, 'chart_values' => $values,
        'invoices' => db_all('SELECT i.*, o.name org_name FROM invoices i
                              LEFT JOIN organizations o ON o.id = i.org_id
                              WHERE i.issued_at >= ? AND i.issued_at < ?
                              ORDER BY i.id DESC LIMIT 200', [$ctx['start'], $ctx['end']]),
        'payments' => db_all('SELECT p.*, o.name org_name FROM payments p
                              LEFT JOIN organizations o ON o.id = p.org_id
                              WHERE p.occurred_at >= ? AND p.occurred_at < ?
                              ORDER BY p.occurred_at DESC, p.id DESC LIMIT 200', [$ctx['start'], $ctx['end']]),
        'unmatched' => db_all('SELECT * FROM payments WHERE matched = 0 ORDER BY occurred_at DESC, id DESC LIMIT 50'),
        'orgs' => db_all("SELECT id, name FROM organizations WHERE status = 'approved' AND id <> ? ORDER BY name", [$pid]),
        'claims' => db_all("SELECT c.*, o.name org_name, u.name submitter, u.email submitter_email
                            FROM payment_claims c
                            JOIN organizations o ON o.id = c.org_id
                            LEFT JOIN users u ON u.id = c.submitted_by_id
                            ORDER BY FIELD(c.status,'pending','confirmed','rejected'), c.id DESC LIMIT 60"),
        'claim_methods' => claim_methods(),
        'wise' => wise_settings(),
        'wise_url' => wise_webhook_url(),
        'wise_events' => db_all("SELECT * FROM webhook_events WHERE provider = 'wise' ORDER BY id DESC LIMIT 20"),
    ]);
}

/**
 * Super-admin Subscribers: who the customers are, rather than what they owe.
 *
 * Replaces the tenant-facing "Subscription" page that used to leak into the platform
 * operator's own nav — the platform org never pays itself, so that page was noise.
 * Accounting answers "how much money"; this answers "who, on what plan, still using it".
 */
function dash_platform_subscribers(): void
{
    $u = require_super();
    $pid = platform_org_id();

    $rows = [];
    $totals = ['subscribers' => 0, 'active' => 0, 'trialing' => 0, 'past_due' => 0,
               'canceled' => 0, 'none' => 0, 'seats' => 0, 'mrr' => 0.0];

    foreach (db_all('SELECT * FROM organizations WHERE id <> ? ORDER BY name', [$pid]) as $o) {
        $orgId = (int) $o['id'];
        $bill  = customer_billing($o);
        $state = $o['subscription_status'] ?: 'none';
        $current = sub_is_current($o);

        $admin = db_one("SELECT name, email FROM users WHERE org_id = ? AND role = 'client_admin'
                         ORDER BY id LIMIT 1", [$orgId]);
        $people = (int) (db_one("SELECT COUNT(*) c FROM users WHERE org_id = ? AND role <> 'client_viewer'",
            [$orgId])['c'] ?? 0);
        $devices = (int) (db_one('SELECT COUNT(*) c FROM devices d JOIN users us ON us.id = d.user_id
                                  WHERE us.org_id = ?', [$orgId])['c'] ?? 0);
        $last = db_one('SELECT MAX(s.started_at) t FROM sessions s JOIN users us ON us.id = s.user_id
                        WHERE us.org_id = ?', [$orgId])['t'] ?? null;
        $sessions30 = (int) (db_one('SELECT COUNT(*) c FROM sessions s JOIN users us ON us.id = s.user_id
                                     WHERE us.org_id = ? AND s.started_at >= ?',
                                    [$orgId, gmdate('Y-m-d H:i:s', strtotime('-30 days'))])['c'] ?? 0);
        $paid = (int) (db_one('SELECT COALESCE(SUM(amount_cents),0) c FROM payments
                               WHERE org_id = ? AND matched = 1', [$orgId])['c'] ?? 0);

        $totals['subscribers']++;
        $totals[$state] = ($totals[$state] ?? 0) + 1;
        $totals['seats'] += (int) $bill['seats'];
        if ($state === 'active' || $o['billing_status'] === 'active') {
            $totals['mrr'] += (float) $bill['due'];
        }

        $rows[] = [
            'org' => $o, 'state' => $state, 'state_label' => sub_state_label($o),
            'is_current' => $current, 'due' => (float) $bill['due'], 'seats' => (int) $bill['seats'],
            'admin' => $admin, 'people' => $people, 'devices' => $devices,
            'last_active' => $last, 'sessions30' => $sessions30, 'paid_cents' => $paid,
            // "Signed up but never tracked anything" is the churn signal worth surfacing.
            'dormant' => $sessions30 === 0,
        ];
    }

    view('dashboard/platform_subscribers', [
        'title' => 'Subscribers', 'active' => 'platform_subscribers', 'user' => $u,
        'is_super' => true, 'is_manager' => true, 'pending_count' => 0, 'acting_org' => null,
        'rows' => $rows, 'totals' => $totals,
    ]);
}

/** Subscribers export — the same roster as a spreadsheet. */
function dash_platform_subscribers_csv(): void
{
    require_super();
    $pid = platform_org_id();
    $out = fopen('php://temp', 'r+');
    fputcsv($out, ['DeskPulse subscribers — ' . gmdate('Y-m-d')]);
    fputcsv($out, ['Organization', 'Status', 'Plan', 'Discount %', 'Due per cycle', 'Currency',
                   'Seats', 'People', 'Devices', 'Sessions (30d)', 'Last activity',
                   'Signed up', 'Paid until', 'Lifetime paid', 'Contact', 'Email']);
    foreach (db_all('SELECT * FROM organizations WHERE id <> ? ORDER BY name', [$pid]) as $o) {
        $bill = customer_billing($o);
        $admin = db_one("SELECT name, email FROM users WHERE org_id = ? AND role = 'client_admin'
                         ORDER BY id LIMIT 1", [(int) $o['id']]);
        $paid = (int) (db_one('SELECT COALESCE(SUM(amount_cents),0) c FROM payments
                               WHERE org_id = ? AND matched = 1', [(int) $o['id']])['c'] ?? 0);
        fputcsv($out, [
            $o['name'], sub_state_label($o), $o['plan_type'], $o['discount_pct'],
            number_format((float) $bill['due'], 2, '.', ''), $o['billing_currency'],
            $bill['seats'],
            (int) (db_one("SELECT COUNT(*) c FROM users WHERE org_id = ? AND role <> 'client_viewer'", [(int) $o['id']])['c'] ?? 0),
            (int) (db_one('SELECT COUNT(*) c FROM devices d JOIN users us ON us.id = d.user_id WHERE us.org_id = ?', [(int) $o['id']])['c'] ?? 0),
            (int) (db_one('SELECT COUNT(*) c FROM sessions s JOIN users us ON us.id = s.user_id
                           WHERE us.org_id = ? AND s.started_at >= ?',
                          [(int) $o['id'], gmdate('Y-m-d H:i:s', strtotime('-30 days'))])['c'] ?? 0),
            db_one('SELECT MAX(s.started_at) t FROM sessions s JOIN users us ON us.id = s.user_id
                    WHERE us.org_id = ?', [(int) $o['id']])['t'] ?? '',
            $o['created_at'], $o['current_period_end'],
            number_format($paid / 100, 2, '.', ''),
            $admin['name'] ?? '', $admin['email'] ?? '',
        ]);
    }
    rewind($out);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="deskpulse-subscribers-' . gmdate('Y-m-d') . '.csv"');
    header('X-Content-Type-Options: nosniff');
    echo stream_get_contents($out);
    exit;
}

/**
 * Stream a remittance receipt. Receipts live in private storage because they carry
 * bank details; only a super admin, or someone who can manage that org's own
 * subscription, may fetch one.
 */
function dash_payment_receipt(array $p): void
{
    $u = require_login();
    $claim = db_one('SELECT * FROM payment_claims WHERE id = ?', [(int) ($p['id'] ?? 0)]);
    if (!$claim || empty($claim['receipt_path'])) {
        abort(404, 'No receipt for that submission.');
    }
    $ownOrg = (int) $claim['org_id'] === (int) $u['org_id'] && can($u, 'subscription');
    if (!is_super($u) && !$ownOrg) {
        deny_access();
    }
    $abs = private_path($claim['receipt_path']);
    if (!is_file($abs)) {
        abort(404, 'That receipt file is missing.');
    }
    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    $mime = ['pdf' => 'application/pdf', 'png' => 'image/png',
             'jpg' => 'image/jpeg', 'webp' => 'image/webp'][$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="receipt-' . (int) $claim['id'] . '.' . $ext . '"');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($abs));
    readfile($abs);
    exit;
}

/** Serve the SCA public key as a .pem download, for uploading to Wise. */
function dash_platform_sca_key(): void
{
    require_super();
    $pem = wise_settings()['sca_public'];
    if (trim($pem) === '') {
        flash('No SCA keypair has been generated yet.', 'error');
        redirect('/app/platform/accounting');
    }
    header('Content-Type: application/x-pem-file');
    header('Content-Disposition: attachment; filename="deskpulse-wise-sca-public.pem"');
    header('X-Content-Type-Options: nosniff');
    echo rtrim($pem) . "
";
    exit;
}

/** Accounting export: every settled payment in the selected window. */
function dash_platform_accounting_csv(): void
{
    require_super();
    [$start, $end, $period, $periodDate] = period_range_from_request('month', ['week', 'pay', 'month']);
    $ctx = $GLOBALS['DP_PERIOD_CTX'];
    $rows = db_all('SELECT p.*, o.name org_name FROM payments p
                    LEFT JOIN organizations o ON o.id = p.org_id
                    WHERE p.occurred_at >= ? AND p.occurred_at < ?
                    ORDER BY p.occurred_at ASC', [$ctx['start'], $ctx['end']]);
    $out = fopen('php://temp', 'r+');
    fputcsv($out, ['DeskPulse payments — ' . $ctx['label']]);
    fputcsv($out, ['Received (UTC)', 'Organization', 'Amount', 'Currency', 'Provider',
                   'Reference', 'Matched', 'Wise tx id', 'Note']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['occurred_at'], $r['org_name'] ?? '(unassigned)',
                       number_format(((int) $r['amount_cents']) / 100, 2, '.', ''), $r['currency'],
                       $r['provider'], $r['reference_raw'], $r['matched'] ? 'yes' : 'no',
                       $r['wise_tx_id'], $r['note']]);
    }
    rewind($out);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="deskpulse-payments-' . $ctx['start_date'] . '_' . $ctx['end_date'] . '.csv"');
    header('X-Content-Type-Options: nosniff');
    echo stream_get_contents($out);
    exit;
}

/**
 * Everything about ONE customer's subscription in one place: plan and pricing,
 * subscription/trial state, deposit reference, invoice and payment history, the
 * account's people and usage — plus the actions to change any of it.
 *
 * The cross-org list lives on /app/platform/billing; this is the drill-down.
 */
function dash_platform_subscription(array $p): void
{
    $u = require_super();
    $orgId = (int) ($p['id'] ?? 0);
    $org = db_one('SELECT * FROM organizations WHERE id = ?', [$orgId]);
    if (!$org || $orgId === platform_org_id()) {
        flash('That organization does not exist.', 'error');
        redirect('/app/platform/billing');
    }
    $back = '/app/platform/subscription/' . $orgId;

    if (request_method() === 'POST') {
        check_csrf();
        $action = $_POST['action'] ?? '';

        if ($action === 'set_plan') {
            $plan = plan_type_clean($_POST['plan_type'] ?? null);
            $disc = max(0.0, min(100.0, (float) ($_POST['discount_pct'] ?? 0)));
            $cur  = substr(strtoupper((string) ($_POST['billing_currency'] ?? 'USD')), 0, 8) ?: 'USD';
            // Enterprise is a negotiated figure; blank clears it and falls back to the cap.
            $customRaw = trim((string) ($_POST['custom_fee'] ?? ''));
            $custom = ($plan === 'enterprise' && $customRaw !== '')
                ? max(0.0, (float) $customRaw)
                : ($plan === 'enterprise' ? ($org['custom_fee'] ?? null) : null);
            $fee  = effective_monthly_fee(['plan_type' => $plan, 'discount_pct' => $disc,
                                           'id' => $orgId, 'custom_fee' => $custom]);
            db_exec('UPDATE organizations SET plan_type = ?, discount_pct = ?, monthly_fee = ?,
                            custom_fee = ?, billing_currency = ? WHERE id = ?',
                [$plan, $disc, $fee, $custom, $cur, $orgId]);
            notify_sales('subscription.plan_changed', 'Plan changed: ' . $org['name'], [
                'Organization' => $org['name'],
                'New plan'     => $plan,
                'Discount'     => $disc . '%',
                'Monthly fee'  => number_format($fee, 2) . ' ' . $cur,
                'Changed by'   => $u['name'] . ' (super admin)',
            ], $orgId);
            flash('Plan and pricing saved.', 'success');

        } elseif ($action === 'set_status') {
            // Manual override of the gateway state — for comped accounts, disputes and
            // cancellations that never go through a payment.
            $st = in_array($_POST['subscription_status'] ?? '',
                ['none', 'trialing', 'active', 'past_due', 'canceled'], true)
                ? $_POST['subscription_status'] : 'none';
            $trialEnds = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['trial_ends_at'] ?? '')
                ? $_POST['trial_ends_at'] . ' 23:59:59' : null;
            $periodEnd = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['current_period_end'] ?? '')
                ? $_POST['current_period_end'] . ' 23:59:59' : null;
            db_exec('UPDATE organizations SET subscription_status = ?, trial_ends_at = ?,
                            current_period_end = ? WHERE id = ?',
                [$st, $trialEnds, $periodEnd, $orgId]);
            notify_sales('subscription.status_changed', 'Subscription status: ' . $org['name'] . ' → ' . $st, [
                'Organization' => $org['name'],
                'Status'       => $st,
                'Paid until'   => $periodEnd ?: '(cleared)',
                'Trial ends'   => $trialEnds ?: '(cleared)',
                'Changed by'   => $u['name'] . ' (super admin)',
            ], $orgId);
            flash('Subscription state updated.', 'success');

        } elseif ($action === 'start_billing' || $action === 'pause_billing') {
            $on = $action === 'start_billing';
            db_exec($on
                ? "UPDATE organizations SET billing_status = 'active',
                     billing_started_at = COALESCE(billing_started_at, NOW()) WHERE id = ?"
                : "UPDATE organizations SET billing_status = 'paused' WHERE id = ?", [$orgId]);
            notify_sales($on ? 'subscription.billing_started' : 'subscription.billing_paused',
                ($on ? 'Billing started: ' : 'Billing paused: ') . $org['name'],
                ['Organization' => $org['name'], 'By' => $u['name'] . ' (super admin)'], $orgId);
            flash($on ? 'Billing started.' : 'Billing paused.', 'success');

        } elseif ($action === 'record_payment') {
            $cents = (int) round(((float) ($_POST['amount'] ?? 0)) * 100);
            if ($cents > 0) {
                record_manual_payment($orgId, $cents,
                    substr(trim((string) ($_POST['note'] ?? '')), 0, 255) ?: 'Recorded by super admin');
                flash('Payment recorded — subscription extended one period.', 'success');
            } else {
                flash('Enter an amount greater than 0.', 'error');
            }

        } elseif ($action === 'extend') {
            $n = max(1, min(24, (int) ($_POST['periods'] ?? 1)));
            activate_period($orgId, $n);
            notify_sales('subscription.extended', 'Subscription extended: ' . $org['name'], [
                'Organization' => $org['name'],
                'Periods'      => $n,
                'By'           => $u['name'] . ' (super admin)',
            ], $orgId);
            flash("Subscription extended by $n period(s) with no payment recorded.", 'success');

        } elseif ($action === 'void_invoice') {
            db_exec("UPDATE invoices SET status = 'void' WHERE id = ? AND org_id = ?",
                [(int) ($_POST['invoice_id'] ?? 0), $orgId]);
            flash('Invoice voided.', 'success');

        } elseif ($action === 'issue_invoice') {
            issue_invoice($org);
            flash('Invoice issued for the current cycle.', 'success');

        } elseif ($action === 'assign_payment') {
            $pid = (int) ($_POST['payment_id'] ?? 0);
            if ($pid) {
                assign_payment($pid, $orgId);
                flash('Deposit assigned — subscription extended.', 'success');
            }
        }
        redirect($back);
    }

    $bill  = customer_billing($org);
    $users = db_all('SELECT id, name, email, role, created_at FROM users WHERE org_id = ?
                     ORDER BY FIELD(role, \'client_admin\',\'hr_manager\',\'it_admin\',\'manager\',\'member\',\'client_viewer\'), name',
                    [$orgId]);
    $invoices = db_all('SELECT * FROM invoices WHERE org_id = ? ORDER BY id DESC LIMIT 60', [$orgId]);
    $payments = db_all('SELECT * FROM payments WHERE org_id = ? ORDER BY occurred_at DESC, id DESC LIMIT 60', [$orgId]);
    $unmatched = db_all('SELECT * FROM payments WHERE matched = 0 ORDER BY occurred_at DESC, id DESC LIMIT 25');
    $paid = db_one("SELECT COALESCE(SUM(amount_cents),0) c FROM payments WHERE org_id = ? AND matched = 1", [$orgId]);

    // Usage — the "is this customer actually using it" question a renewal call needs.
    $usage = [
        'devices'   => (int) (db_one('SELECT COUNT(*) c FROM devices d JOIN users us ON us.id = d.user_id
                                      WHERE us.org_id = ?', [$orgId])['c'] ?? 0),
        'sessions'  => (int) (db_one('SELECT COUNT(*) c FROM sessions s JOIN users us ON us.id = s.user_id
                                      WHERE us.org_id = ? AND s.started_at >= ?',
                                     [$orgId, gmdate('Y-m-d H:i:s', strtotime('-30 days'))])['c'] ?? 0),
        'last_seen' => db_one('SELECT MAX(s.started_at) t FROM sessions s JOIN users us ON us.id = s.user_id
                               WHERE us.org_id = ?', [$orgId])['t'] ?? null,
        'clients'   => (int) (db_one('SELECT COUNT(*) c FROM clients WHERE org_id = ?', [$orgId])['c'] ?? 0),
    ];

    view('dashboard/platform_subscription', [
        'title' => $org['name'] . ' — subscription', 'active' => 'platform_billing',
        'user' => $u, 'is_super' => true, 'is_manager' => true, 'pending_count' => 0, 'acting_org' => null,
        'org' => $org, 'bill' => $bill, 'state' => sub_state_label($org),
        'days_left' => sub_days_left($org), 'is_current' => sub_is_current($org),
        'users' => $users, 'invoices' => $invoices, 'payments' => $payments,
        'unmatched' => $unmatched, 'paid_total' => (int) $paid['c'], 'usage' => $usage,
        'period' => billing_period(), 'prices' => plan_base_prices(),
        'deposit' => wise_usd_deposit_details(),
    ]);
}

function dash_platform_billing(): void
{
    $u = require_super();
    if (request_method() === 'POST') {
        check_csrf();
        $action = $_POST['action'] ?? '';
        $orgId = (int) ($_POST['org_id'] ?? 0);
        if ($action === 'set_rate') {
            $plan = plan_type_clean($_POST['plan_type'] ?? null);
            $disc = max(0.0, min(100.0, (float) ($_POST['discount_pct'] ?? 0)));
            $cur  = substr(strtoupper($_POST['billing_currency'] ?? 'USD'), 0, 8);
            // Store the effective fee so everything reading monthly_fee stays correct
            // (per-seat is computed from the org's live seat count, then capped).
            $existing = db_one('SELECT custom_fee FROM organizations WHERE id = ?', [$orgId]);
            $fee  = effective_monthly_fee(['plan_type' => $plan, 'discount_pct' => $disc, 'id' => $orgId,
                                           'custom_fee' => $existing['custom_fee'] ?? null]);
            db_exec('UPDATE organizations SET plan_type = ?, discount_pct = ?, monthly_fee = ?, billing_currency = ? WHERE id = ?',
                [$plan, $disc, $fee, $cur, $orgId]);
            flash('Plan & discount saved.', 'success');
        } elseif ($action === 'start_billing') {
            db_exec("UPDATE organizations SET billing_status = 'active',
                     billing_started_at = COALESCE(billing_started_at, NOW()) WHERE id = ?", [$orgId]);
            flash('Billing started.', 'success');
        } elseif ($action === 'pause_billing') {
            db_exec("UPDATE organizations SET billing_status = 'paused' WHERE id = ?", [$orgId]);
            flash('Billing paused.', 'success');
        } elseif ($action === 'record_payment') {
            $cents = (int) round(((float) ($_POST['amount'] ?? 0)) * 100);
            if ($orgId && $cents > 0) {
                record_manual_payment($orgId, $cents, 'Recorded by super admin');
                flash('Payment recorded — subscription extended one period.', 'success');
            } else {
                flash('Enter an amount greater than 0.', 'error');
            }
        } elseif ($action === 'assign_payment') {
            $pid = (int) ($_POST['payment_id'] ?? 0);
            if ($pid && $orgId) {
                assign_payment($pid, $orgId);
                flash('Deposit assigned — subscription extended.', 'success');
            }
        }
        redirect(($_POST['return'] ?? '') === 'orgs' ? '/app/platform/orgs' : '/app/platform/billing');
    }

    $rows = [];
    $mrr = 0.0;
    $activeCount = 0;
    foreach (db_all("SELECT * FROM organizations WHERE status = 'approved' AND id <> ?
                     ORDER BY name", [platform_org_id()]) as $o) {
        $bill = customer_billing($o);
        // Recurring revenue = orgs actively subscribed (paid period current) or comped.
        if ($o['subscription_status'] === 'active' || $o['billing_status'] === 'active') {
            $mrr += $bill['due'];
            $activeCount++;
        }
        $rows[] = ['org' => $o, 'bill' => $bill, 'state' => sub_state_label($o)];
    }
    $unmatched = db_all("SELECT * FROM payments WHERE matched = 0 ORDER BY occurred_at DESC, id DESC LIMIT 50");
    view('dashboard/platform_billing', [
        'title' => 'Billing', 'active' => 'platform_billing', 'user' => $u, 'is_super' => true,
        'is_manager' => true, 'pending_count' => 0, 'acting_org' => null,
        'rows' => $rows, 'mrr' => $mrr, 'active_count' => $activeCount,
        'unmatched' => $unmatched, 'period_label' => plan_period_label(),
    ]);
}

/** Super admin: platform-wide settings (screenshot retention). */
function dash_platform_settings(): void
{
    $u = require_super();
    $pid = platform_org_id();
    if (request_method() === 'POST') {
        check_csrf();
        if (($_POST['action'] ?? '') === 'set_prices') {
            $ind = max(0.0, (float) ($_POST['price_individual'] ?? 0));
            $org = max(0.0, (float) ($_POST['price_organization'] ?? 0));
            $seat = max(0.0, (float) ($_POST['price_per_seat'] ?? 0));
            $smin = max(1, (int) ($_POST['seats_min'] ?? 2));
            $smax = max($smin, (int) ($_POST['seats_max'] ?? 50));
            $solo    = max(0.0, (float) ($_POST['price_solo'] ?? 0));
            $capAmt  = max(0.0, (float) ($_POST['price_seat_cap'] ?? 0));      // 0 = uncapped
            $billMin = max(0, (int) ($_POST['seats_bill_min'] ?? 0));          // 0 = no floor
            $covers  = max(0, (int) ($_POST['seats_cap_covers'] ?? 0));        // 0 = unstated
            $pUnit  = ($_POST['billing_period_unit'] ?? 'month') === 'day' ? 'day' : 'month';
            $pCount = max(1, (int) ($_POST['billing_period_count'] ?? 1));
            db_exec('UPDATE organizations SET price_individual = ?, price_organization = ?, price_per_seat = ?,
                     seats_min = ?, seats_max = ?, price_solo = ?, price_seat_cap = ?, seats_bill_min = ?,
                     seats_cap_covers = ?, billing_period_unit = ?, billing_period_count = ? WHERE id = ?',
                [$ind, $org, $seat, $smin, $smax, $solo, $capAmt, $billMin, $covers, $pUnit, $pCount, $pid]);
            // Recompute the stored effective fee for every tenant so monthly_fee (and
            // MRR) reflect the new base prices immediately.
            //
            // This was a single UPDATE with IF(plan_type='individual', ?, ?), which
            // stamped EVERY non-individual tenant with the organization price — already
            // wrong for per-seat tenants, and under the ladder it would have stamped a
            // 5-seat Team org at the full cap. A per-seat fee depends on that org's own
            // seat count, so it cannot be computed in one statement.
            foreach (db_all('SELECT id, plan_type, discount_pct, custom_fee FROM organizations WHERE id <> ?', [$pid]) as $o) {
                db_exec('UPDATE organizations SET monthly_fee = ? WHERE id = ?',
                    [effective_monthly_fee($o), (int) $o['id']]);
            }
            flash('Standard prices saved.', 'success');
            redirect('/app/platform/settings');
        }
        if (($_POST['action'] ?? '') === 'set_analytics') {
            // Validated again on render (analytics_ids()) because these land inside a
            // <script> on the public marketing site.
            $ga = trim((string) ($_POST['ga4_measurement_id'] ?? ''));
            $cl = trim((string) ($_POST['clarity_project_id'] ?? ''));
            $ga = preg_match('/^G-[A-Z0-9]{4,15}$/', $ga) ? $ga : null;
            $cl = preg_match('/^[a-z0-9]{6,15}$/i', $cl) ? $cl : null;
            db_exec('UPDATE organizations SET ga4_measurement_id = ?, clarity_project_id = ? WHERE id = ?',
                [$ga, $cl, $pid]);
            flash('Analytics settings saved.'
                . ($ga || $cl ? '' : ' Both fields were blank or invalid, so nothing is tracked.'), 'success');
            redirect('/app/platform/settings');
        }
        if (($_POST['action'] ?? '') === 'set_trial') {
            $td = max(0, min(365, (int) ($_POST['trial_days'] ?? 14)));
            db_exec('UPDATE organizations SET trial_days = ? WHERE id = ?', [$td, $pid]);
            flash('Free-trial length saved.', 'success');
            redirect('/app/platform/settings');
        }
        if (($_POST['action'] ?? '') === 'set_mail') {
            // Blank = "don't override", so the value falls back to config.php.
            $addr = function (string $field): ?string {
                $v = strtolower(trim((string) ($_POST[$field] ?? '')));
                return ($v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL)) ? substr($v, 0, 190) : null;
            };
            $from = $addr('mail_from');
            if (($_POST['mail_from'] ?? '') !== '' && $from === null) {
                flash('That "from" address is not a valid email address.', 'error');
                redirect('/app/platform/settings');
            }
            db_exec('UPDATE organizations SET mail_from = ?, mail_from_name = ?, mail_reply_to = ?,
                            mail_notify = ?, mail_transport = ?, mail_enabled = ? WHERE id = ?',
                [$from,
                 substr(trim((string) ($_POST['mail_from_name'] ?? '')), 0, 120) ?: null,
                 $addr('mail_reply_to'),
                 $addr('mail_notify'),
                 ($_POST['mail_transport'] ?? '') === 'smtp' ? 'smtp'
                     : (($_POST['mail_transport'] ?? '') === 'mail' ? 'mail' : null),
                 isset($_POST['mail_override_enabled']) ? (isset($_POST['mail_enabled']) ? 1 : 0) : null,
                 $pid]);
            flash('Email settings saved.', 'success');
            redirect('/app/platform/settings');
        }
        if (($_POST['action'] ?? '') === 'mail_test') {
            $to = strtolower(trim((string) ($_POST['test_to'] ?? ''))) ?: (string) $u['email'];
            if (!mail_address_ok($to)) {
                flash('Enter a valid address to send the test to.', 'error');
                redirect('/app/platform/settings');
            }
            $id = mail_queue([
                'to_email' => $to, 'to_name' => 'DeskPulse test',
                'subject'  => 'DeskPulse email test',
                'text'     => "This is a test from the DeskPulse platform console.\n\n"
                            . 'Transport: ' . mail_transport_label() . "\n"
                            . 'From: ' . mail_cfg('mail_from') . "\n",
                'html'     => mail_render_html(['org' => ['name' => 'DeskPulse'],
                    'heading'   => 'Email is working',
                    'body_html' => 'This is a test from the DeskPulse platform console.<br><br>'
                                 . 'Transport: <b>' . e(mail_transport_label()) . '</b><br>'
                                 . 'From: <b>' . e((string) mail_cfg('mail_from')) . '</b>']),
                'kind' => 'test', 'created_by_id' => (int) $u['id'],
            ]);
            if (!$id) {
                flash('Could not queue the test message.', 'error');
            } else {
                [$ok, $err] = mail_send_row(db_one('SELECT * FROM email_outbox WHERE id = ?', [$id]));
                flash($ok ? "Test email sent to $to via " . mail_transport_label() . '.'
                          : 'Test send failed: ' . $err, $ok ? 'success' : 'error');
            }
            redirect('/app/platform/settings');
        }
        $days = max(0, min(3650, (int) ($_POST['screenshot_retention_days'] ?? 0)));
        db_exec('UPDATE organizations SET screenshot_retention_days = ? WHERE id = ?', [$days, $pid]);
        flash('Platform settings saved.', 'success');
        redirect('/app/platform/settings');
    }
    $purged = purge_old_screenshots();   // tidy up on each visit
    if ($purged > 0) {
        flash("Cleaned up $purged screenshot(s) past the retention window.", 'success');
    }
    view('dashboard/platform_settings', [
        'title' => 'Platform settings', 'active' => 'platform_settings', 'user' => $u,
        'is_super' => true, 'is_manager' => true, 'pending_count' => 0, 'acting_org' => null,
        'retention' => screenshot_retention_days(),
        'shots_total' => (int) db_one('SELECT COUNT(*) c FROM screenshots')['c'],
        'prices' => plan_base_prices(),
        'analytics' => analytics_ids(),
        'trial_days' => platform_trial_days(),
        'period' => billing_period(),
        // Effective values (platform override, else config.php) + which are overridden.
        'mail' => [
            'from'       => (string) mail_cfg('mail_from', ''),
            'from_name'  => (string) mail_cfg('mail_from_name', ''),
            'reply_to'   => (string) mail_cfg('mail_reply_to', ''),
            'notify'     => (string) mail_cfg('mail_notify', ''),
            'transport'  => mail_transport(),
            'enabled'    => mail_enabled(),
            'label'      => mail_transport_label(),
            'overrides'  => platform_mail_settings(),
            'queued'     => (int) (db_one("SELECT COUNT(*) c FROM email_outbox WHERE status = 'queued'")['c'] ?? 0),
            'failed'     => (int) (db_one("SELECT COUNT(*) c FROM email_outbox WHERE status = 'failed'")['c'] ?? 0),
        ],
    ]);
}

/** Downgrade schema features that older MySQL/MariaDB servers reject. MySQL 8.0
 *  dumps default to the utf8mb4_0900_* collations (8.0-only) and utf8mb3 aliases;
 *  map them to long-supported equivalents so the file restores on 5.6+/MariaDB. */
function export_sanitize_ddl(string $ddl): string
{
    $ddl = preg_replace('/utf8mb4_0900_\w+/i', 'utf8mb4_unicode_ci', $ddl);
    $ddl = preg_replace('/\butf8mb3\b/i', 'utf8', $ddl);
    return $ddl;
}

/** Stream one table's rows as batched multi-row INSERTs. Paginated so memory stays
 *  bounded on large tables (activity_samples, window_events, …). */
function export_dump_rows(string $table, int $page = 2000): void
{
    $safe = str_replace('`', '', $table);
    $offset = 0;
    $colList = null;
    do {
        $rows = db_all("SELECT * FROM `$safe` LIMIT $page OFFSET $offset");
        $batch = [];
        foreach ($rows as $row) {
            if ($colList === null) {
                $colList = '`' . implode('`, `', array_keys($row)) . '`';
            }
            $vals = array_map(
                fn($v) => $v === null ? 'NULL' : db()->quote((string) $v),
                array_values($row)
            );
            $batch[] = '(' . implode(', ', $vals) . ')';
        }
        if ($batch) {
            echo "INSERT INTO `$safe` ($colList) VALUES\n" . implode(",\n", $batch) . ";\n";
            flush();
        }
        $offset += $page;
    } while (count($rows) === $page);
}

/** Super admin: download a full SQL dump of the platform database. Pure PHP (no
 *  dependency on the mysqldump binary); the schema is sanitized so the file is
 *  compatible with older MySQL/MariaDB versions. */
function dash_platform_export(): void
{
    require_super();
    $cfg = config('db');
    $dbName = $cfg['name'] ?? 'deskpulse';
    $fname = 'deskpulse-' . preg_replace('/[^a-z0-9_-]+/i', '', $dbName)
           . '-' . gmdate('Ymd-His') . '.sql';

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    @set_time_limit(0);
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('X-Content-Type-Options: nosniff');

    echo "-- DeskPulse database export\n";
    echo "-- Database: $dbName\n";
    echo '-- Generated: ' . gmdate('Y-m-d H:i:s') . " UTC\n";
    echo "-- Restores on MySQL 5.6+ / MariaDB (8.0 collations downgraded).\n\n";
    echo "SET NAMES utf8mb4;\n";
    echo "SET FOREIGN_KEY_CHECKS = 0;\n";
    echo "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
    echo "SET time_zone = '+00:00';\n\n";

    foreach (db_all('SHOW TABLES') as $row) {
        $table = array_values($row)[0];
        $safe = str_replace('`', '', $table);
        $create = db_one("SHOW CREATE TABLE `$safe`");
        $ddl = export_sanitize_ddl($create['Create Table'] ?? '');
        echo "-- ---------- Table `$safe` ----------\n";
        echo "DROP TABLE IF EXISTS `$safe`;\n";
        echo $ddl . ";\n\n";
        export_dump_rows($table);
        echo "\n";
        flush();
    }

    echo "SET FOREIGN_KEY_CHECKS = 1;\n";
    exit;
}

/** Super admin home: the business owner's view — revenue, leads (signups), and
 *  the customer base at a glance. */
function dash_platform_overview(array $u): void
{
    $pid = platform_org_id();
    [$wkStart, $wkEnd] = period_range('week');
    $orgs = db_all('SELECT * FROM organizations WHERE id <> ? ORDER BY created_at DESC', [$pid]);

    $customers = 0; $leadsCount = 0; $billingActive = 0; $mrr = 0.0; $seats = 0;
    $top = [];
    foreach ($orgs as $o) {
        if ($o['status'] === 'approved') { $customers++; }
        if ($o['status'] === 'pending')  { $leadsCount++; }
        $b = customer_billing($o);
        $seats += $b['seats'];
        if ($o['billing_status'] === 'active') { $billingActive++; $mrr += $b['due']; }
        if ($o['status'] === 'approved') {
            $top[] = ['name' => $o['name'], 'seats' => $b['seats'], 'due' => $b['due'],
                      'status' => $o['billing_status'], 'currency' => $b['currency']];
        }
    }
    usort($top, fn($a, $b) => $b['due'] <=> $a['due']);
    $top = array_slice($top, 0, 6);

    // Leads = signups awaiting review (prospective paying customers).
    $leads = db_all("SELECT * FROM organizations WHERE status = 'pending' ORDER BY created_at DESC");
    foreach ($leads as &$l) {
        $owner = db_one("SELECT name, email FROM users WHERE org_id = ?
                         ORDER BY (role = 'client_admin') DESC, id ASC LIMIT 1", [$l['id']]);
        $l['owner_name']  = $owner['name'] ?? '—';
        $l['owner_email'] = $owner['email'] ?? '';
        $l['users']       = (int) db_one('SELECT COUNT(*) c FROM users WHERE org_id = ?', [$l['id']])['c'];
    }
    unset($l);

    $stats = [
        'mrr'        => $mrr,
        'customers'  => $customers,
        'leads'      => $leadsCount,
        'billing'    => $billingActive,
        'seats'      => $seats,
        'week_secs'  => (int) db_one('SELECT COALESCE(SUM(active_s), 0) s FROM sessions
                                      WHERE started_at >= ? AND started_at < ?', [$wkStart, $wkEnd])['s'],
        'new_month'  => (int) db_one('SELECT COUNT(*) c FROM organizations
                                      WHERE id <> ? AND created_at >= ?', [$pid, gmdate('Y-m-01 00:00:00')])['c'],
    ];

    view('dashboard/platform_overview', [
        'title' => 'Overview', 'active' => 'overview', 'user' => $u, 'is_super' => true,
        'is_manager' => true, 'pending_count' => 0, 'acting_org' => null,
        'stats' => $stats, 'leads' => $leads, 'top' => $top, 'recent' => array_slice($orgs, 0, 8),
    ]);
}

/** Super admin: every team across all customers, grouped by organization, with
 *  the agents under each team (plus any agents not yet on a team). */
function dash_platform_teams(array $u): void
{
    $orgs = [];
    foreach (db_all("SELECT id, name FROM organizations WHERE status = 'approved' AND id <> ?
                     ORDER BY name", [platform_org_id()]) as $o) {
        $oid = (int) $o['id'];
        $teams = db_all('SELECT id, name FROM teams WHERE org_id = ? ORDER BY name', [$oid]);
        $byTeam = [];
        foreach (db_all('SELECT tm.team_id, us.id, us.name, us.role, us.job_title
                         FROM team_members tm JOIN users us ON us.id = tm.user_id
                         JOIN teams t ON t.id = tm.team_id
                         WHERE t.org_id = ? ORDER BY us.name', [$oid]) as $r) {
            $byTeam[$r['team_id']][] = $r;
        }
        $unassigned = db_all(
            "SELECT id, name, role, job_title FROM users
             WHERE org_id = ? AND role <> 'super_admin'
             AND id NOT IN (SELECT tm.user_id FROM team_members tm
                            JOIN teams t ON t.id = tm.team_id WHERE t.org_id = ?)
             ORDER BY name", [$oid, $oid]);
        $agentCount = (int) db_one("SELECT COUNT(*) c FROM users
                                    WHERE org_id = ? AND role <> 'super_admin'", [$oid])['c'];
        $orgs[] = ['org' => $o, 'teams' => $teams, 'by_team' => $byTeam,
                   'unassigned' => $unassigned, 'agent_count' => $agentCount];
    }
    view('dashboard/platform_teams', [
        'title' => 'Teams', 'active' => 'team', 'user' => $u, 'is_super' => true,
        'is_manager' => true, 'pending_count' => 0, 'acting_org' => null, 'orgs' => $orgs,
    ]);
}

/** Super admin: every client across all customers, grouped by organization, with
 *  the agents who have logged time under each client. */
function dash_platform_clients(array $u): void
{
    $orgs = [];
    foreach (db_all("SELECT id, name FROM organizations WHERE status = 'approved' AND id <> ?
                     ORDER BY name", [platform_org_id()]) as $o) {
        $oid = (int) $o['id'];
        $clients = db_all('SELECT id, name, archived FROM clients WHERE org_id = ? ORDER BY archived, name', [$oid]);
        $byClient = [];
        foreach (db_all('SELECT DISTINCT s.client_id, us.id, us.name, us.role
                         FROM sessions s JOIN users us ON us.id = s.user_id
                         WHERE us.org_id = ? AND s.client_id IS NOT NULL ORDER BY us.name', [$oid]) as $r) {
            $byClient[$r['client_id']][] = $r;
        }
        $orgs[] = ['org' => $o, 'clients' => $clients, 'by_client' => $byClient];
    }
    view('dashboard/platform_clients', [
        'title' => 'Clients', 'active' => 'clients', 'user' => $u, 'is_super' => true,
        'is_manager' => true, 'pending_count' => 0, 'acting_org' => null, 'orgs' => $orgs,
    ]);
}

/** Super admin: act as an org (view its dashboards), or return to the platform. */
function dash_platform_act(array $p): void
{
    $u = require_super();
    if (db_one('SELECT id FROM organizations WHERE id = ?', [$p['id']])) {
        $_SESSION['act_org'] = (int) $p['id'];
    }
    redirect('/app/overview');
}

function dash_platform_return(): void
{
    require_super();
    unset($_SESSION['act_org']);
    redirect('/app/platform');
}

// ─────────────────────────── Devices (IT / admin) ───────────────────────────

function dash_devices(): void
{
    $u = require_cap('devices');
    if (request_method() === 'POST') {
        check_csrf();
        if (($_POST['action'] ?? '') === 'revoke') {
            // Only devices belonging to this org's users.
            db_exec('DELETE d FROM devices d JOIN users us ON us.id = d.user_id
                     WHERE d.id = ? AND us.org_id = ?',
                [(int) ($_POST['device_id'] ?? 0), $u['org_id']]);
            flash('Device revoked.', 'success');
        }
        redirect('/app/devices');
    }
    $devices = db_all(
        'SELECT d.*, us.name AS user_name, us.email FROM devices d
         JOIN users us ON us.id = d.user_id WHERE us.org_id = ? ORDER BY d.last_seen DESC, d.created_at DESC',
        [$u['org_id']]
    );
    view('dashboard/devices', array_merge(nav_context($u), [
        'title' => 'Devices', 'active' => 'devices', 'devices' => $devices,
        'can_remote' => can($u, 'remote'),
    ]));
}

// ─────────────────────────── Audit log (IT / admin) ───────────────────────────

function dash_audit(): void
{
    $u = require_cap('audit');
    $ids = org_user_ids((int) $u['org_id']);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $events = [];

    foreach (db_all("SELECT s.id, s.started_at ts, s.source, us.name FROM sessions s
                     JOIN users us ON us.id = s.user_id WHERE s.user_id IN ($in)
                     ORDER BY s.started_at DESC LIMIT 60", $ids) as $r) {
        $events[] = ['ts' => $r['ts'], 'who' => $r['name'],
                     'event' => ($r['source'] === 'manual' ? 'Manual entry created' : 'Session started'),
                     'detail' => 'session #' . $r['id']];
    }
    foreach (db_all("SELECT s.reviewed_at ts, s.approval_status, us.name,
                            rv.name reviewer FROM sessions s JOIN users us ON us.id = s.user_id
                     LEFT JOIN users rv ON rv.id = s.reviewed_by_id
                     WHERE s.user_id IN ($in) AND s.reviewed_at IS NOT NULL
                     ORDER BY s.reviewed_at DESC LIMIT 40", $ids) as $r) {
        $events[] = ['ts' => $r['ts'], 'who' => $r['reviewer'] ?: 'manager',
                     'event' => 'Time entry ' . $r['approval_status'],
                     'detail' => 'for ' . $r['name']];
    }
    foreach (db_all("SELECT d.created_at ts, d.name dev, us.name FROM devices d
                     JOIN users us ON us.id = d.user_id WHERE us.org_id = ?
                     ORDER BY d.created_at DESC LIMIT 30", [$u['org_id']]) as $r) {
        $events[] = ['ts' => $r['ts'], 'who' => $r['name'],
                     'event' => 'Device registered', 'detail' => $r['dev']];
    }
    foreach (db_all("SELECT created_at ts, label, scope FROM share_links WHERE org_id = ?
                     ORDER BY created_at DESC LIMIT 30", [$u['org_id']]) as $r) {
        $events[] = ['ts' => $r['ts'], 'who' => 'system',
                     'event' => 'Share link created', 'detail' => ($r['label'] ?: $r['scope'])];
    }
    // Remote-control sessions performed by a platform admin against this org's devices.
    foreach (db_all("SELECT rs.started_at ts, w.name worker, adm.name admin FROM remote_sessions rs
                     JOIN users w ON w.id = rs.user_id LEFT JOIN users adm ON adm.id = rs.admin_user_id
                     WHERE rs.org_id = ? ORDER BY rs.started_at DESC LIMIT 30", [$u['org_id']]) as $r) {
        $events[] = ['ts' => $r['ts'], 'who' => $r['admin'] ?: 'DeskPulse admin',
                     'event' => 'Remote session started', 'detail' => 'on ' . $r['worker']];
    }
    foreach (db_all("SELECT rs.ended_at ts, w.name worker, rs.end_reason FROM remote_sessions rs
                     JOIN users w ON w.id = rs.user_id
                     WHERE rs.org_id = ? AND rs.ended_at IS NOT NULL
                     ORDER BY rs.ended_at DESC LIMIT 30", [$u['org_id']]) as $r) {
        $events[] = ['ts' => $r['ts'], 'who' => 'system', 'event' => 'Remote session ended',
                     'detail' => $r['worker'] . ' · ' . ($r['end_reason'] ?: 'ended')];
    }
    usort($events, fn($a, $b) => strcmp($b['ts'], $a['ts']));
    $events = array_slice($events, 0, 120);

    view('dashboard/audit', array_merge(nav_context($u), [
        'title' => 'Audit log', 'active' => 'audit', 'events' => $events,
    ]));
}
