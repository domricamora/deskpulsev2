<?php
/** Authentication: register/login/logout, current-user, role guards, and the
 *  webhook HMAC verification used by the desktop agent. */

function current_user(): ?array
{
    static $loaded = false, $user = null;
    if (!$loaded) {
        $loaded = true;
        if (!empty($_SESSION['user_id'])) {
            $user = db_one('SELECT * FROM users WHERE id = ?', [$_SESSION['user_id']]);
            // Super admins can "act as" any organization; while doing so, their
            // effective org_id is the one they're viewing so the normal org-scoped
            // dashboards work unchanged.
            if ($user && $user['role'] === 'super_admin' && !empty($_SESSION['act_org'])) {
                if (db_one('SELECT id FROM organizations WHERE id = ?', [$_SESSION['act_org']])) {
                    $user['org_id'] = (int) $_SESSION['act_org'];
                }
            }
        }
    }
    return $user;
}

function is_super(?array $u = null): bool
{
    $u = $u ?? current_user();
    return $u && $u['role'] === 'super_admin';
}

/**
 * Capability matrix — the single source of truth for what each role may do.
 * Capabilities gate existing features; super_admin holds all of them ('*').
 *
 *  view_all        see every member in the org
 *  view_team       see only members of teams the user belongs to
 *  reports         time/activity/productivity reports
 *  screenshots     view screenshots
 *  live            live team view
 *  approve_time    approve/reject manual time entries
 *  approve_overtime approve/reject the overtime portion of activity (HR) before it is paid
 *  users_manage    create/edit users, assign roles, team membership
 *  profiles_manage edit employee profiles (HR)
 *  view_rates      see/edit pay & bill rates
 *  billing         client billing
 *  clients_manage  manage clients & contracts
 *  contracts_manage assign specific team members to a contract's roster (narrower
 *                  than clients_manage — no client CRUD/portal-login access)
 *  manage_agents   assign clients to agents (managers/admins) — edits the roster
 *  view_agents     read-only agent roster + agent detail (implied by manage_agents;
 *                  also granted to a client portal login, scoped to its own agents)
 *  org_settings    monitoring policy / company settings
 *  devices         manage agent installs / devices (IT)
 *  audit           audit / security log (IT)
 *  remote          remote desktop control of an org's agents (super/company/IT admins),
 *                  org-scoped for non-super callers
 *  data_import     bulk-import clients/employees/time from a payroll Excel upload
 *                  (company + HR admin); writes into the uploader's own org
 *  pay_adjustments add/edit bonuses, commissions, reimbursements and deductions
 *  leave_approve   administer leave types/entitlements and approve leave requests
 *                  (every user may *request* leave for themselves — no cap needed)
 *  wise_manage     maintain Wise payout details and run the salary-run export
 *  messaging       send notices, custom messages and reminder mail to the org
 *  platform        cross-tenant platform console (super only)
 */
function role_caps(string $role): array
{
    static $matrix = [
        'super_admin'  => ['*'],
        'client_admin' => ['view_all', 'reports', 'screenshots', 'live', 'approve_time', 'approve_overtime',
                           'users_manage', 'profiles_manage', 'view_rates', 'billing',
                           'clients_manage', 'contracts_manage', 'org_settings', 'devices', 'audit', 'manage_agents',
                           'set_pay_rate', 'payroll', 'remote', 'subscription', 'data_import',
                           'pay_adjustments', 'leave_approve', 'wise_manage', 'messaging'],
        'manager'      => ['view_team', 'reports', 'screenshots', 'live', 'approve_time', 'manage_agents', 'view_agents',
                           'leave_approve', 'messaging'],
        'hr_manager'   => ['view_all', 'reports', 'approve_time', 'approve_overtime', 'profiles_manage',
                           'set_pay_rate', 'payroll', 'contracts_manage', 'data_import',
                           'pay_adjustments', 'leave_approve', 'wise_manage', 'messaging'],
        'it_admin'     => ['view_all', 'devices', 'audit', 'org_settings', 'remote'],
        // Client portal login — read-only, scoped to its own client record. Sees its
        // engagement's agents, timesheets, tasks, screenshots and billing (what it is
        // charged); never internal pay rates / labor cost (no view_rates).
        'client_viewer'=> ['view_team', 'reports', 'screenshots', 'billing', 'view_agents'],
        'member'       => [],                                        // self only
    ];
    return $matrix[$role] ?? [];
}

function can(array $u, string $cap): bool
{
    $caps = role_caps($u['role']);
    return in_array('*', $caps, true) || in_array($cap, $caps, true);
}

/**
 * An authenticated user reached a page their role can't access — commonly after
 * switching accounts, or following a stale link / bookmark / back button. Instead of
 * a hard 403, bounce them to their own dashboard (/app routes each role to its home)
 * with a notice. Unauthenticated users are already redirected to /login upstream by
 * require_login(), so this never strands a logged-out visitor.
 */
function deny_access(): void
{
    flash('You don\'t have access to that page.', 'error');
    redirect('/app');
}

function require_cap(string $cap): array
{
    $u = require_login();
    if (!can($u, $cap)) {
        deny_access();
    }
    return $u;
}

function is_admin(array $u): bool
{
    return can($u, 'users_manage');
}

function require_super(): array
{
    $u = require_login();
    if ($u['role'] !== 'super_admin') {
        deny_access();
    }
    return $u;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        redirect('/login?next=' . rawurlencode(current_route_path()));
    }
    // Accounts created with a temporary password (e.g. client portal logins) must set
    // their own password before they can use the app. Hold them at the reset page.
    if (!empty($u['must_change_password'])) {
        $path = current_route_path();
        if (!str_contains($path, '/app/change-password') && !str_contains($path, '/logout')) {
            redirect('/app/change-password');
        }
    }
    return $u;
}

/** Members/managers of an org that hasn't been approved yet are held at a gate. */
function require_approved_org(array $u): void
{
    if ($u['role'] === 'super_admin') {
        return;
    }
    $org = db_one('SELECT * FROM organizations WHERE id = ?', [$u['org_id']]);
    if (($org['status'] ?? 'approved') !== 'approved') {
        redirect('/app/pending');
    }
    // Paywall: once the free trial ends with no active subscription (and no super-admin
    // comp), hold the org at the subscription page. Exempt the subscription/account
    // pages, logout and the webhook/API endpoints so paying (and leaving) stays possible.
    if (payments_enabled() && !sub_is_current($org)) {
        $path = current_route_path();
        $exempt = ['/app/subscription', '/app/change-password', '/app/profile', '/logout', '/webhooks'];
        foreach ($exempt as $e) {
            if (str_starts_with($path, $e)) {
                return;
            }
        }
        redirect('/app/subscription');
    }
}

function require_manager(): array
{
    $u = require_login();
    if (!is_manager($u)) {
        deny_access();
    }
    return $u;
}

/** "Oversees other people" — sees all or an assigned team. */
function is_manager(array $u): bool
{
    return can($u, 'view_all') || can($u, 'view_team');
}

/** Normalize a user's pay (internal cost) to an hourly figure. */
function user_hourly_rate(array $u): float
{
    $rate = (float) ($u['pay_rate'] ?? 0);
    if ($rate <= 0) {
        return 0.0;
    }
    return ($u['pay_type'] ?? 'hourly') === 'monthly' ? $rate / 173.33 : $rate;
}

/** Hourly rate billed to the client for this user's time. */
function user_bill_rate(array $u): float
{
    return (float) ($u['bill_rate'] ?? 0);
}

// ─────────────────────────── Web auth handlers ───────────────────────────

/**
 * Self-serve signup: plan chosen on /pricing → account → trial → dashboard.
 *
 * The plan travels as a hidden field, NOT only as ?plan= — the form POSTs to a bare
 * /register with no query string, so a visitor arriving from a pricing CTA used to
 * silently land on the default plan whatever they clicked.
 *
 * Solo and Individual are single-user plans, so a "company workspace name" is pure
 * friction there; it is optional and derived from the person's name.
 */
function handle_register(): void
{
    // Plan may arrive on the link (GET) or the round-trip (POST). Enterprise is excluded:
    // it carries a negotiated custom_fee and must be set by a super admin.
    $planReq = $_POST['plan'] ?? $_GET['plan'] ?? '';
    $plan = in_array($planReq, ['solo', 'individual', 'per_seat', 'organization'], true)
        ? $planReq : 'per_seat';
    $soloish = in_array($plan, ['solo', 'individual'], true);
    // Repopulate on error. Losing four fields to one typo is the single biggest
    // avoidable drop-off in a signup form.
    $old = ['company' => '', 'name' => '', 'email' => ''];

    if (request_method() === 'POST') {
        check_csrf();
        $company = trim($_POST['company'] ?? '');
        $name    = trim($_POST['name'] ?? '');
        $email   = strtolower(trim($_POST['email'] ?? ''));
        $pass    = $_POST['password'] ?? '';
        $old = ['company' => $company, 'name' => $name, 'email' => $email];

        // A single-user plan doesn't need a company name — derive one.
        if ($company === '' && $soloish && $name !== '') {
            $company = $name;
        }

        $error = null;
        if (!$company || !$name || !$email || !$pass) {
            $error = $soloish
                ? 'Your name, email and password are required.'
                : 'All fields are required.';
        } elseif (strlen($pass) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address.';
        } elseif (db_one('SELECT id FROM users WHERE email = ?', [$email])) {
            // Flash messages are escaped by the layout, so this stays plain text; the
            // template renders the "Sign in" recovery link beside the form.
            $error = 'That email is already registered — sign in instead, or use another address.';
        }

        if ($error) {
            flash($error, 'error');
        } else {
            // With payments/trials on, signups start on a free trial with immediate
            // access (super admin still oversees on the Platform page); otherwise they
            // fall back to the old "pending until approved" gate.
            $status = payments_enabled() ? 'approved' : 'pending';
            $orgId = db_exec("INSERT INTO organizations (name, status) VALUES (?, ?)", [$company, $status]);
            $uid = db_exec(
                'INSERT INTO users (org_id, name, email, password_hash, role)
                 VALUES (?, ?, ?, ?, ?)',
                [$orgId, $name, $email, password_hash($pass, PASSWORD_DEFAULT), 'client_admin']
            );
            ensure_personal_links($orgId);   // give the new user a public link
            start_trial($orgId, $plan);       // plan + monthly fee + (trial + deposit ref when payments on)
            // Stamp the acquisition source so "which channel produced PAYING orgs" is a
            // database question, not a guess. GA4 alone cannot join a click to revenue.
            attribution_stamp((int) $orgId);
            // Internal alert to sales (config/platform 'mail_notify'). Queued, never fatal.
            notify_sales('signup', 'New DeskPulse signup: ' . $company, [
                'Organization' => $company,
                'Plan'         => $plan,
                'Contact'      => $name,
                'Email'        => $email,
                'Status'       => $status,
                'Source'       => $_SESSION['dp_utm']['utm_source'] ?? 'direct',
            ], (int) $orgId);
            session_regenerate_id(true);
            $_SESSION['user_id'] = $uid;
            // Conversion fires on the next page render — a 302 is invisible to a tag.
            $_SESSION['dp_track'][] = ['sign_up', ['method' => 'email', 'plan' => $plan]];
            redirect('/app');
        }
    }

    $prices = plan_base_prices();
    view('auth/register', [
        'title' => 'Create your DeskPulse account',
        'plan' => $plan, 'soloish' => $soloish, 'old' => $old,
        'prices' => $prices,
        'trial_days' => platform_trial_days(),
        'plan_price' => plan_signup_price($plan, $prices),
    ], 'layout_public');
}

/** The headline price a signup form should show for a plan, as a short string. */
function plan_signup_price(string $plan, ?array $prices = null): string
{
    $prices = $prices ?? plan_base_prices();
    $fmt = fn(float $v) => '$' . (fmod($v, 1.0) === 0.0 ? number_format($v, 0) : number_format($v, 2));
    switch ($plan) {
        case 'solo':
            return 'Free forever';
        case 'individual':
            return $fmt((float) $prices['individual']) . '/month';
        case 'organization':
            return $fmt((float) $prices['organization']) . '/month';
        default:
            $min = max(1, (int) $prices['seats_bill_min']);
            return $fmt((float) $prices['per_seat']) . '/seat/month'
                . ($min > 1 ? ' · from ' . $fmt(plan_seat_price($min, $prices)) : '');
    }
}

// ─────────────────────────── Password reset ───────────────────────────
//
// Design constraints, all of which are the reason this is more than 20 lines:
//   • The stored value is a SHA-256 of the token, never the token. A reset token is a
//     bearer credential — a leaked database or SQL export must not yield working links.
//   • The response never reveals whether an address exists. Enumerating which emails
//     have accounts is the classic leak in this flow.
//   • Rate limited per email and per IP, because "send mail to an arbitrary address"
//     is otherwise a free spam cannon pointed at our own sending reputation.
//   • Tokens are single-use and short-lived, and every other outstanding token for the
//     user is invalidated on use.
//   • Changing the password regenerates the session id and clears must_change_password.

const RESET_TTL_MIN = 60;          // link validity
const RESET_MAX_PER_HOUR = 5;      // per email address, and per IP

/** Constant-time-comparable lookup key for a reset token. */
function reset_token_hash(string $token): string
{
    return hash('sha256', $token);
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/**
 * GET/POST /forgot-password — request a reset link.
 *
 * Always reports the same thing, whether or not the address is registered.
 */
function handle_forgot_password(): void
{
    if (current_user()) {
        redirect('/app');
    }
    $sent = false;
    if (request_method() === 'POST') {
        check_csrf();
        $email = strtolower(trim($_POST['email'] ?? ''));
        $ip = client_ip();

        // Rate limit before doing anything else, and count attempts for addresses that
        // do not exist too — otherwise the limiter itself becomes an oracle.
        $recent = db_one(
            'SELECT COUNT(*) c FROM password_resets
             WHERE requested_ip = ? AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)',
            [$ip]
        );
        $tooMany = (int) ($recent['c'] ?? 0) >= RESET_MAX_PER_HOUR * 3;

        if (!$tooMany && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $user = db_one('SELECT id, name, email, org_id FROM users WHERE email = ?', [$email]);
            if ($user) {
                $perUser = db_one(
                    'SELECT COUNT(*) c FROM password_resets
                     WHERE user_id = ? AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)',
                    [(int) $user['id']]
                );
                if ((int) ($perUser['c'] ?? 0) < RESET_MAX_PER_HOUR) {
                    send_password_reset($user, $ip);
                }
            }
        }
        // Same answer in every branch — existing address, unknown address, or throttled.
        $sent = true;
    }
    view('auth/forgot_password', [
        'title' => 'Reset your DeskPulse password',
        'sent' => $sent,
    ], 'layout_public');
}

/** Mint a token, store its hash, and queue the email. */
function send_password_reset(array $user, string $ip): void
{
    $token = random_token(32);
    db_exec(
        'INSERT INTO password_resets (user_id, token_hash, requested_ip, expires_at)
         VALUES (?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE))',
        [(int) $user['id'], reset_token_hash($token), $ip, RESET_TTL_MIN]
    );
    $link = abs_url('/reset-password?token=' . urlencode($token));
    $name = trim((string) ($user['name'] ?? '')) ?: 'there';

    $rowId = mail_queue([
        'org_id'   => $user['org_id'] ?? null,
        'user_id'  => (int) $user['id'],
        'to_email' => $user['email'],
        'to_name'  => $user['name'] ?? null,
        'kind'     => 'password_reset',
        'subject'  => 'Reset your DeskPulse password',
        'html'     => render('email/password_reset', [
            'name' => $name, 'link' => $link, 'ttl' => RESET_TTL_MIN, 'ip' => $ip,
        ]),
    ]);

    // Send THIS message now, through the configured transport (PHP mail() by default),
    // rather than waiting for the cron drain — a reset link that arrives ten minutes
    // late is a support ticket, and this deployment has no cron drainer.
    //
    // It is still queued first, deliberately: the outbox row is the durable record and
    // the retry state. mail_send_row() marks it sent, or records last_error and leaves
    // it queued for the worker, and either way it shows up in the email log. Note this
    // sends the SPECIFIC row — mail_flush() would drain the oldest queued messages,
    // which need not include this one if there is any backlog.
    if ($rowId > 0) {
        try {
            $row = db_one('SELECT * FROM email_outbox WHERE id = ?', [$rowId]);
            if ($row) {
                mail_send_row($row);
            }
        } catch (Throwable $e) {
            // Delivery must never break the request; the row stays queued for retry.
        }
    }
}

/**
 * Look up a reset token. Returns [user, error] — error is a human-readable reason so
 * the page can distinguish expired from already-used from never-valid.
 */
function reset_token_lookup(string $token): array
{
    if ($token === '') {
        return [null, 'This reset link is incomplete.'];
    }
    $row = db_one('SELECT * FROM password_resets WHERE token_hash = ?', [reset_token_hash($token)]);
    if (!$row) {
        return [null, 'This reset link is not valid. It may have been superseded by a newer one.'];
    }
    if ($row['used_at'] !== null) {
        return [null, 'This reset link has already been used. Request a new one if you still need it.'];
    }
    if (strtotime($row['expires_at'] . ' UTC') < time()) {
        return [null, 'This reset link has expired. Reset links are valid for ' . RESET_TTL_MIN . ' minutes.'];
    }
    $user = db_one('SELECT * FROM users WHERE id = ?', [(int) $row['user_id']]);
    if (!$user) {
        return [null, 'That account no longer exists.'];
    }
    return [['user' => $user, 'reset' => $row], null];
}

/** GET/POST /reset-password?token=… — set a new password. */
function handle_reset_password(): void
{
    $token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
    [$found, $error] = reset_token_lookup($token);

    if ($found && request_method() === 'POST') {
        check_csrf();
        $pass = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');
        if (strlen($pass) < 8) {
            flash('Password must be at least 8 characters.', 'error');
        } elseif ($pass !== $confirm) {
            flash('The two passwords do not match.', 'error');
        } else {
            $uid = (int) $found['user']['id'];
            db_exec('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?',
                [password_hash($pass, PASSWORD_DEFAULT), $uid]);
            // Burn this token, and every other outstanding one for the account — a second
            // request in the inbox must not stay usable after a successful reset.
            db_exec('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE id = ?',
                [(int) $found['reset']['id']]);
            db_exec('UPDATE password_resets SET used_at = UTC_TIMESTAMP()
                     WHERE user_id = ? AND used_at IS NULL', [$uid]);

            session_regenerate_id(true);
            $_SESSION['user_id'] = $uid;
            flash('Your password has been changed.', 'success');
            redirect('/app');
        }
    }

    view('auth/reset_password', [
        'title' => 'Choose a new password',
        'token' => $token, 'error' => $error,
        'email' => $found['user']['email'] ?? null,
    ], 'layout_public');
}

function handle_login(): void
{
    // Already signed in (e.g. opened /login directly, or a stale tab after switching
    // accounts) — go to the dashboard instead of showing the form.
    if (request_method() === 'GET' && current_user()) {
        redirect('/app');
    }
    $email = '';
    $ssoHint = null;
    if (request_method() === 'POST') {
        check_csrf();
        $email = strtolower(trim($_POST['email'] ?? ''));
        $pass  = $_POST['password'] ?? '';
        $user = db_one('SELECT * FROM users WHERE email = ?', [$email]);

        // An organization can require SSO. Refuse the password path for its people even
        // if the password is correct — otherwise "enforce SSO" is decorative.
        if ($user) {
            $org = db_one('SELECT id, name, sso_enabled, sso_enforce FROM organizations WHERE id = ?',
                [(int) $user['org_id']]);
            if ($org && !empty($org['sso_enabled']) && !empty($org['sso_enforce'])) {
                $ssoHint = $org;
                flash($org['name'] . ' requires single sign-on. Use the button above.', 'error');
                view('auth/login', ['title' => 'Sign in to DeskPulse',
                    'providers' => oauth_providers(), 'sso_hint' => $ssoHint,
                    'old_email' => $email], 'layout_public');
                return;
            }
        }

        if ($user && password_verify($pass, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $next = $_GET['next'] ?? '/app';
            redirect($next !== '' && $next[0] === '/' ? $next : '/app');
        }
        // Offer SSO if the address belongs to a domain an org has claimed, even when the
        // password was wrong — that is usually why it was wrong.
        $ssoHint = $ssoHint ?: sso_org_for_email($email);
        flash('Invalid email or password.', 'error');
    }
    view('auth/login', [
        'title' => 'Sign in to DeskPulse',
        'providers' => oauth_providers(),
        'sso_hint' => $ssoHint,
        'old_email' => $email,
    ], 'layout_public');
}

function handle_logout(): void
{
    $_SESSION = [];
    session_destroy();
    redirect('/');
}

// ─────────────────────────── Webhook auth (HMAC) ───────────────────────────

/**
 * Resolve the Device from request headers and verify the HMAC-SHA256 signature
 * of the raw body. Aborts 401 on any failure; returns the device row on success.
 */
function verify_webhook(): array
{
    $deviceId = $_SERVER['HTTP_X_DESKPULSE_DEVICE'] ?? '';
    $signature = $_SERVER['HTTP_X_DESKPULSE_SIGNATURE'] ?? '';
    if (!$deviceId) {
        abort(401, 'missing device header');
    }
    $device = db_one('SELECT * FROM devices WHERE id = ?', [$deviceId]);
    if (!$device) {
        abort(401, 'unknown device');
    }
    $expected = hash_hmac('sha256', raw_body(), $device['secret']);
    if (!hash_equals($expected, $signature)) {
        abort(401, 'bad signature');
    }
    db_exec('UPDATE devices SET last_seen = NOW() WHERE id = ?', [$device['id']]);
    return $device;
}
