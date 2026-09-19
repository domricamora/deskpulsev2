<?php
/**
 * PDO connection helper. Lazily connects, creates the database and schema on
 * first run if they don't exist, and returns a shared PDO instance.
 */

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $cfg = config('db');
    $dsnBase = "mysql:host={$cfg['host']};port={$cfg['port']};charset={$cfg['charset']}";
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    // Prefer connecting straight to the database — on shared hosting (cPanel) the DB
    // is pre-created and the user has no server-level CREATE DATABASE privilege. Only
    // if that fails (e.g. local dev where the DB doesn't exist yet) do we connect to
    // the server and create it.
    try {
        $pdo = new PDO($dsnBase . ";dbname={$cfg['name']}", $cfg['user'], $cfg['pass'], $opts);
    } catch (PDOException $e) {
        $root = new PDO($dsnBase, $cfg['user'], $cfg['pass'], $opts);
        $root->exec("CREATE DATABASE IF NOT EXISTS `{$cfg['name']}` "
            . "CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo = new PDO($dsnBase . ";dbname={$cfg['name']}", $cfg['user'], $cfg['pass'], $opts);
    }

    ensure_schema($pdo);
    ensure_migrations($pdo);
    ensure_super_admin();
    return $pdo;
}

/**
 * First-run bootstrap: if no super admin exists yet and config('bootstrap_admin')
 * is set, create the platform org + the default super admin from it. Lets a fresh
 * deployment come up ready to sign in with zero manual setup. Idempotent — a no-op
 * once any super admin exists. Config shape:
 *   'bootstrap_admin' => ['email' => '…', 'pass_hash' => '…', 'name' => 'Super Admin'].
 */
function ensure_super_admin(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $boot = config('bootstrap_admin');
    if (!is_array($boot) || empty($boot['email']) || empty($boot['pass_hash'])) {
        return;
    }
    if (db_one("SELECT id FROM users WHERE role = 'super_admin' LIMIT 1")) {
        return;
    }
    $email = strtolower(trim($boot['email']));
    $org = db_one("SELECT id FROM organizations WHERE name = 'DeskPulse Platform' LIMIT 1");
    $orgId = $org['id'] ?? db_exec(
        "INSERT INTO organizations (name, status, billing_status, billing_currency)
         VALUES ('DeskPulse Platform', 'approved', 'none', 'USD')");
    $existing = db_one('SELECT id FROM users WHERE email = ?', [$email]);
    if ($existing) {
        db_exec('UPDATE users SET role = "super_admin", org_id = ?, password_hash = ? WHERE id = ?',
            [$orgId, $boot['pass_hash'], $existing['id']]);
    } else {
        db_exec('INSERT INTO users (org_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, "super_admin")',
            [$orgId, $boot['name'] ?? 'Super Admin', $email, $boot['pass_hash']]);
    }
}

/** Idempotent column migrations for databases created before a feature landed. */
function ensure_migrations(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $cols = [
        'screenshot_interval_min' => 'INT NOT NULL DEFAULT 10',
        'screenshot_blur'         => 'TINYINT(1) NOT NULL DEFAULT 0',
        'idle_threshold_min'      => 'INT NOT NULL DEFAULT 15',
        'sync_interval_s'         => 'INT NOT NULL DEFAULT 60',
        'track_screenshots'       => 'TINYINT(1) NOT NULL DEFAULT 1',
        'track_windows'           => 'TINYINT(1) NOT NULL DEFAULT 1',
        'track_processes'         => 'TINYINT(1) NOT NULL DEFAULT 1',
    ];
    add_columns($pdo, 'organizations', $cols);

    // Signup lifecycle + billing on the organization (super-admin reviewed).
    // Default 'approved' so orgs created before this stay accessible; new marketing
    // signups are explicitly set 'pending' at registration.
    add_columns($pdo, 'organizations', [
        'status'             => "VARCHAR(16) NOT NULL DEFAULT 'approved'",
        'billing_status'     => "VARCHAR(16) NOT NULL DEFAULT 'none'",
        'monthly_fee'        => 'DOUBLE NOT NULL DEFAULT 0',
        'seat_rate'          => 'DOUBLE NOT NULL DEFAULT 0',
        'billing_currency'   => "VARCHAR(8) NOT NULL DEFAULT 'USD'",
        'billing_started_at' => 'DATETIME NULL',
        'reviewed_at'        => 'DATETIME NULL',
        // Platform-wide screenshot retention (days; 0 = keep forever). The value
        // stored on the super-admin's platform org governs cleanup for every org.
        'screenshot_retention_days' => 'INT NOT NULL DEFAULT 30',
    ]);

    // Subscription pricing model. Each tenant has a plan type (individual vs
    // organization) and a percentage discount off that plan's standard price;
    // the effective monthly fee (base * (1 - discount/100)) is stored in
    // monthly_fee. The two standard/base prices are platform-wide values held on
    // the super-admin's platform org row (like screenshot_retention_days).
    add_columns($pdo, 'organizations', [
        'plan_type'          => "VARCHAR(16) NOT NULL DEFAULT 'organization'",   // solo | individual | per_seat | organization | enterprise
        'discount_pct'       => 'DECIMAL(5,2) NOT NULL DEFAULT 0',               // 0..100 off the plan's base price
        'price_individual'   => 'DECIMAL(10,2) NOT NULL DEFAULT 9',              // platform base price (platform org row)
        'price_organization' => 'DECIMAL(10,2) NOT NULL DEFAULT 49',             // platform base price (platform org row)
        'seats_min'          => 'INT NOT NULL DEFAULT 2',                        // org plan seat range (platform org row)
        'seats_max'          => 'INT NOT NULL DEFAULT 50',
    ]);

    // First-run onboarding flag for the company admin. Existing orgs are treated as
    // already set up; only brand-new signups go through the wizard.
    if (add_columns($pdo, 'organizations', ['onboarded_at' => 'DATETIME NULL'])) {
        $pdo->exec('UPDATE organizations SET onboarded_at = created_at WHERE onboarded_at IS NULL');
    }

    // Profile fields for all users.
    add_columns($pdo, 'users', [
        'phone'     => 'VARCHAR(40) NULL',
        'job_title' => 'VARCHAR(120) NULL',
    ]);
    // Role model: the org "admin" became "client_admin"; "super_admin" sits above it.
    $pdo->exec("UPDATE users SET role = 'client_admin' WHERE role = 'admin'");

    // Track time against clients directly (Projects retired). Add client_id to
    // sessions and tasks and backfill from the old project -> client mapping.
    $addedSess = add_columns($pdo, 'sessions', ['client_id' => 'INT NULL']);
    $addedTask = add_columns($pdo, 'tasks', ['client_id' => 'INT NULL']);
    if ($addedSess && table_exists($pdo, 'projects')) {
        $pdo->exec("UPDATE sessions s JOIN projects p ON p.id = s.project_id
                    SET s.client_id = p.client_id
                    WHERE s.client_id IS NULL AND p.client_id IS NOT NULL");
    }
    if ($addedTask && table_exists($pdo, 'projects')) {
        $pdo->exec("UPDATE tasks t JOIN projects p ON p.id = t.project_id
                    SET t.client_id = p.client_id
                    WHERE t.client_id IS NULL AND p.client_id IS NOT NULL");
    }

    // Track when an agent last posted, so stale (offline/crashed) sessions can be
    // auto-closed.
    add_columns($pdo, 'sessions', ['last_seen_at' => 'DATETIME NULL']);

    // Overtime split + HR approval: the portion of a session's active time worked
    // beyond the worker's schedule is held for HR approval before it counts toward pay.
    add_columns($pdo, 'sessions', [
        'overtime_s'              => 'INT NOT NULL DEFAULT 0',
        'overtime_status'         => "VARCHAR(16) NOT NULL DEFAULT 'none'",  // none|pending|approved|rejected
        'overtime_reviewed_by_id' => 'INT NULL',
        'overtime_reviewed_at'    => 'DATETIME NULL',
        'overtime_review_note'    => 'TEXT NULL',
        'overtime_computed'       => 'TINYINT(1) NOT NULL DEFAULT 0',
    ]);

    // Per-user work schedule (standard hours), set by HR/company admin.
    add_columns($pdo, 'users', [
        'work_start' => 'TIME NULL',
        'work_end'   => 'TIME NULL',
        'work_days'  => "VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5'",   // ISO weekdays Mon=1..Sun=7
    ]);

    // Client login accounts: clients can be given a read-only portal login with a
    // temporary password they must change on first sign-in.
    add_columns($pdo, 'users', ['must_change_password' => 'TINYINT(1) NOT NULL DEFAULT 0']);
    add_columns($pdo, 'clients', ['user_id' => 'INT NULL']);

    // ── Password reset ──
    // Only a HASH of the token is stored: a reset token is a bearer credential, so a
    // leaked database (or a SQL export) must not hand out working reset links. Rows are
    // kept after use so a used token can be reported as used rather than as invalid.
    if (!table_exists($pdo, 'password_resets')) {
        $pdo->exec("CREATE TABLE password_resets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            requested_ip VARCHAR(45) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            UNIQUE KEY uq_token (token_hash),
            INDEX (user_id), INDEX (expires_at),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // ── Federated sign-in (OIDC: Google, Microsoft, per-org enterprise SSO) ──
    // One row per (provider, subject). The subject is the provider's immutable user id —
    // never the email, which can be reassigned inside a workspace and would let a new
    // holder of an old address inherit the account.
    if (!table_exists($pdo, 'user_identities')) {
        $pdo->exec("CREATE TABLE user_identities (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            provider VARCHAR(32) NOT NULL,          -- google | microsoft | sso
            subject VARCHAR(190) NOT NULL,          -- 'sub' claim, immutable per provider
            email VARCHAR(190) NULL,                -- as seen at link time, for display
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_login_at DATETIME NULL,
            UNIQUE KEY uq_provider_subject (provider, subject),
            INDEX (user_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // Per-organization enterprise SSO. The client secret is encrypted at rest with the
    // same dp_encrypt() used for the Wise API token.
    add_columns($pdo, 'organizations', [
        'sso_enabled'        => 'TINYINT(1) NOT NULL DEFAULT 0',
        'sso_issuer'         => 'VARCHAR(255) NULL',   // OIDC issuer URL (discovery base)
        'sso_client_id'      => 'VARCHAR(255) NULL',
        'sso_client_secret'  => 'TEXT NULL',           // dp_encrypt()ed
        'sso_domains'        => 'VARCHAR(255) NULL',   // comma-separated email domains
        'sso_enforce'        => 'TINYINT(1) NOT NULL DEFAULT 0',  // block password login
    ]);

    // Client-level standard/quoted bill rate — reference only (billing still rolls
    // up from each agent's own bill_rate; see compute_billing()). Lets admins record
    // what a client is quoted without changing how any employee is actually billed.
    add_columns($pdo, 'clients', [
        'bill_rate' => 'DOUBLE NOT NULL DEFAULT 0',
        'currency'  => "VARCHAR(8) NOT NULL DEFAULT 'USD'",
    ]);
    // Contract-level bill rate: the column already existed in contracts, but was
    // never actually settable from the UI — now that it is, make sure pre-existing
    // databases have it too (this is a no-op for anyone who already has the column).
    add_columns($pdo, 'contracts', ['bill_rate' => 'DOUBLE NOT NULL DEFAULT 0']);

    // First-run welcome guide for the roles that don't run a setup wizard
    // (employees, HR, IT, client viewers). Left NULL so each user sees the
    // role-specific guide once on their next sign-in; dismissing stamps it.
    add_columns($pdo, 'users', ['welcomed_at' => 'DATETIME NULL']);

    // Company branding: an uploaded logo (stored as WebP under uploads/logos/).
    add_columns($pdo, 'organizations', ['logo_path' => 'VARCHAR(400) NULL']);

    // Payroll spreadsheet import (see dash_import()): a stable per-employee external
    // key (the payroll sheet's "VT ID") so re-importing the same sheet updates the
    // same people instead of duplicating them, plus the optional Wise payout identity
    // carried straight through from the sheet.
    if (add_columns($pdo, 'users', [
            'external_ref' => 'VARCHAR(40) NULL',
            'wise_id'      => 'VARCHAR(64) NULL',
            'wise_name'    => 'VARCHAR(160) NULL',
        ])) {
        // Employees are matched on (org_id, external_ref) during import — index it.
        try {
            $pdo->exec('CREATE INDEX idx_users_external_ref ON users (org_id, external_ref)');
        } catch (\PDOException $e) {
            // Index already exists (columns predate this migration) — ignore.
        }
    }

    // Multi-monitor: which physical screen a screenshot came from (1-based; NULL
    // for single-monitor captures made before this / on one-screen machines).
    add_columns($pdo, 'screenshots', ['monitor' => 'INT NULL']);

    // Agent ↔ client assignment table (added with the Agents management page).
    // Created here too so databases that pre-date the table pick it up on boot.
    if (!table_exists($pdo, 'agent_clients')) {
        $pdo->exec("CREATE TABLE agent_clients (
            id INT AUTO_INCREMENT PRIMARY KEY,
            agent_id INT NOT NULL,
            client_id INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_agent_client (agent_id, client_id),
            INDEX (agent_id), INDEX (client_id),
            FOREIGN KEY (agent_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // Contract ↔ member roster (added with the Contracts page). Created here too so
    // databases that pre-date the table pick it up on boot.
    if (!table_exists($pdo, 'contract_members')) {
        $pdo->exec("CREATE TABLE contract_members (
            id INT AUTO_INCREMENT PRIMARY KEY,
            contract_id INT NOT NULL,
            user_id INT NOT NULL,
            assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_contract_user (contract_id, user_id),
            INDEX (contract_id), INDEX (user_id),
            FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // Remote desktop control (super-admin only, unpublished). A live session
    // streams JPEG frames captured by the agent and relays queued input commands
    // back to it. Auto-expiry is enforced server-side (see remote_gc()).
    if (!table_exists($pdo, 'remote_sessions')) {
        $pdo->exec("CREATE TABLE remote_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            device_id INT NOT NULL,
            user_id INT NOT NULL,
            admin_user_id INT NOT NULL,
            org_id INT NOT NULL,
            token VARCHAR(48) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'pending',   -- pending | active | ended
            end_reason VARCHAR(32) NULL,                      -- admin | agent_gone | expired
            screen_w INT NULL,
            screen_h INT NULL,
            frame_seq INT NOT NULL DEFAULT 0,
            last_frame_at DATETIME NULL,
            last_input_at DATETIME NULL,
            started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            activated_at DATETIME NULL,
            ended_at DATETIME NULL,
            INDEX (device_id), INDEX (status), INDEX (org_id),
            FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if (!table_exists($pdo, 'remote_input_events')) {
        $pdo->exec("CREATE TABLE remote_input_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_id INT NOT NULL,
            payload TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (session_id),
            FOREIGN KEY (session_id) REFERENCES remote_sessions(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // ── Subscriptions / payments (Wise, US bank deposit) ──
    // Real money collection on top of the existing plan/monthly_fee model. The
    // gateway state lives in subscription_status; billing_status stays as the
    // super-admin manual comp/override. trial_days on the PLATFORM org row is the
    // global default (read via platform_trial_days()).
    add_columns($pdo, 'organizations', [
        'subscription_status' => "VARCHAR(16) NOT NULL DEFAULT 'none'",   // none|trialing|active|past_due|canceled
        'trial_ends_at'       => 'DATETIME NULL',
        'current_period_end'  => 'DATETIME NULL',
        'pay_reference'       => 'VARCHAR(24) NULL',                      // stable deposit reference (DP-<id>-<rand>)
        'promo_code'          => 'VARCHAR(40) NULL',                      // promo currently applied (display/history)
        'trial_days'          => 'INT NOT NULL DEFAULT 14',              // global default on the platform org row
        // Per-seat plan + configurable billing period (globals on the platform org row).
        'price_per_seat'       => 'DOUBLE NOT NULL DEFAULT 0',            // price per seat per period
        'billing_period_unit'  => "VARCHAR(8) NOT NULL DEFAULT 'month'", // month | day
        'billing_period_count' => 'INT NOT NULL DEFAULT 1',              // e.g. 1 month, or 30 days
    ]);
    // Published price ladder: Solo (free) · Individual · Team (per-seat, floored at
    // seats_bill_min) · Organization (the per-seat total, capped at price_seat_cap) ·
    // Enterprise (per-tenant custom_fee). Globals live on the platform org row.
    //
    // Every one of these defaults to a value that means "inactive", so applying this
    // migration cannot change what any tenant is billed:
    //   price_seat_cap  0 → no cap    (do NOT fall back to price_organization: that
    //                                  would silently re-price existing per-seat orgs)
    //   seats_bill_min  0 → no floor  (seats_min keeps its display-only meaning)
    // The super admin opts in by saving Platform settings.
    add_columns($pdo, 'organizations', [
        'price_solo'       => 'DOUBLE NOT NULL DEFAULT 0',   // Solo plan price (0 = free)
        'price_seat_cap'   => 'DOUBLE NOT NULL DEFAULT 0',   // per-seat bill never exceeds this; 0 = uncapped
        'seats_bill_min'   => 'INT NOT NULL DEFAULT 0',      // per-seat billing floor; 0 = no minimum
        'seats_cap_covers' => 'INT NOT NULL DEFAULT 0',      // display: seats the cap covers; 0 = unstated
        'custom_fee'       => 'DOUBLE NULL',                 // per-tenant, plan_type = 'enterprise'
        // Analytics measurement IDs, editable without a deploy. NULL = defer to config.php.
        'ga4_measurement_id' => 'VARCHAR(24) NULL',
        'clarity_project_id' => 'VARCHAR(24) NULL',
        // First-touch acquisition attribution, stamped at signup. GA4 reports which
        // channel produced sessions; only this reports which produced paying customers.
        'utm_source'   => 'VARCHAR(80) NULL',
        'utm_medium'   => 'VARCHAR(80) NULL',
        'utm_campaign' => 'VARCHAR(80) NULL',
        'utm_content'  => 'VARCHAR(80) NULL',
        'utm_landing'  => 'VARCHAR(190) NULL',
    ]);
    if (!table_exists($pdo, 'invoices')) {
        $pdo->exec("CREATE TABLE invoices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            org_id INT NOT NULL,
            reference VARCHAR(24) NOT NULL,
            amount_cents INT NOT NULL DEFAULT 0,
            currency VARCHAR(8) NOT NULL DEFAULT 'USD',
            period_start DATE NULL,
            period_end DATE NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'open',   -- open | paid | void
            issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            paid_at DATETIME NULL,
            INDEX (org_id), INDEX (status), INDEX (reference),
            FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if (!table_exists($pdo, 'payments')) {
        $pdo->exec("CREATE TABLE payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            org_id INT NULL,
            invoice_id INT NULL,
            provider VARCHAR(12) NOT NULL DEFAULT 'wise',
            wise_tx_id VARCHAR(64) NULL,
            amount_cents INT NOT NULL DEFAULT 0,
            currency VARCHAR(8) NOT NULL DEFAULT 'USD',
            reference_raw VARCHAR(255) NULL,
            occurred_at DATETIME NULL,
            matched TINYINT(1) NOT NULL DEFAULT 0,
            note VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_wise_tx (wise_tx_id),
            INDEX (org_id), INDEX (invoice_id), INDEX (matched),
            FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if (!table_exists($pdo, 'webhook_events')) {
        $pdo->exec("CREATE TABLE webhook_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            provider VARCHAR(16) NOT NULL,
            event_id VARCHAR(120) NOT NULL,
            type VARCHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_provider_event (provider, event_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if (!table_exists($pdo, 'promo_codes')) {
        $pdo->exec("CREATE TABLE promo_codes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(40) NOT NULL,
            kind VARCHAR(16) NOT NULL DEFAULT 'percent',   -- percent | amount | trial_days | free_months
            value DECIMAL(10,2) NOT NULL DEFAULT 0,
            plan_type VARCHAR(16) NULL,                     -- restrict to individual|organization, or any
            max_redemptions INT NULL,
            redemptions INT NOT NULL DEFAULT 0,
            expires_at DATETIME NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            note VARCHAR(200) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if (!table_exists($pdo, 'promo_redemptions')) {
        $pdo->exec("CREATE TABLE promo_redemptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            promo_id INT NOT NULL,
            org_id INT NOT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_promo_org (promo_id, org_id),
            INDEX (org_id),
            FOREIGN KEY (promo_id) REFERENCES promo_codes(id) ON DELETE CASCADE,
            FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // ── Payroll cycle + reporting timezone (org-level) ──
    // report_tz is the clock every period window is cut in. Sessions are stored in
    // UTC, so without this the range silently depended on php.ini's date.timezone
    // while the table cells rendered in the *viewer's* browser tz — three clocks.
    // Existing orgs default to UTC, which is exactly what they were getting before
    // only if the server ran on UTC; admins can now set it explicitly on Settings.
    add_columns($pdo, 'organizations', [
        'report_tz'        => "VARCHAR(64) NOT NULL DEFAULT 'UTC'",
        'week_start'       => 'TINYINT NOT NULL DEFAULT 1',              // ISO 1=Mon..7=Sun
        'pay_cycle'        => "VARCHAR(16) NOT NULL DEFAULT 'semimonthly'", // weekly|biweekly|semimonthly|rolling15|monthly
        'pay_cycle_anchor' => 'DATE NULL',                               // rolling15 / biweekly origin
        'pay_currency'     => "VARCHAR(8) NOT NULL DEFAULT 'USD'",
    ]);

    // ── Payment method / Wise connection (platform org row) ──
    // Editable from the super-admin Accounting page instead of only config.php.
    // The API token is stored ENCRYPTED (dp_encrypt, AES-256-GCM keyed from
    // app_secret) so DB backups and the SQL export never carry it in the clear.
    // NULL = not set here, fall back to config('payments.*').
    add_columns($pdo, 'organizations', [
        'pay_enabled'         => 'TINYINT(1) NULL',      // master switch override
        'wise_env'            => 'VARCHAR(12) NULL',     // sandbox | live
        'wise_api_token_enc'  => 'TEXT NULL',            // dp_encrypt()ed
        'wise_profile_id'     => 'VARCHAR(40) NULL',
        'wise_balance_id'     => 'VARCHAR(40) NULL',
        'wise_webhook_key'    => 'TEXT NULL',            // Wise's public key (PEM) for signatures
        'wise_webhook_id'     => 'VARCHAR(64) NULL',     // subscription id returned by Wise
        'wise_last_sync_at'   => 'DATETIME NULL',
        'wise_last_error'     => 'VARCHAR(400) NULL',
        // Deposit instructions shown to the payer.
        'wise_usd_bank'       => 'VARCHAR(160) NULL',
        'wise_usd_routing'    => 'VARCHAR(40) NULL',
        'wise_usd_account'    => 'VARCHAR(60) NULL',
        'wise_usd_type'       => 'VARCHAR(24) NULL',
        'wise_usd_address'    => 'VARCHAR(255) NULL',
        // Strong Customer Authentication (SCA). Wise protects some endpoints — notably
        // balance statements — behind a challenge: the call returns 403 with an
        // x-2fa-approval token, which we sign with OUR private key and replay. This is
        // a DIFFERENT key from wise_webhook_key: here we own the private half and
        // upload the public half to Wise. Private key stored encrypted, like the token.
        'wise_sca_public'      => 'TEXT NULL',
        'wise_sca_private_enc' => 'TEXT NULL',
        'wise_sca_created_at'  => 'DATETIME NULL',
    ]);

    // ── Payment method ──
    // 'bank'  — customers pay by direct bank transfer and a super admin records the
    //           payment. This is the live method.
    // 'wise_api' — the automated Wise reconciliation (webhook + statement sync). Kept
    //           so it can be switched back on, but ARCHIVED: with 'bank' selected the
    //           webhook endpoint refuses deliveries and the API panels are collapsed.
    // The account holder and SWIFT/BIC complete the deposit details already stored in
    // wise_usd_* — an international payer needs both, and a domestic US payer needs the
    // routing number, so the customer page shows each set separately.
    add_columns($pdo, 'organizations', [
        'pay_method'      => "VARCHAR(16) NOT NULL DEFAULT 'bank'",
        'wise_usd_holder' => 'VARCHAR(160) NULL',
        'wise_usd_swift'  => 'VARCHAR(24) NULL',
    ]);

    // ── Platform-wide mail settings (held on the super-admin's platform org row,
    // like screenshot_retention_days and the plan prices). NULL means "not set here"
    // and falls back to config.php, so an existing deployment keeps behaving exactly
    // as its config file says until a super admin overrides it in the UI.
    add_columns($pdo, 'organizations', [
        'mail_from'      => 'VARCHAR(190) NULL',
        'mail_from_name' => 'VARCHAR(120) NULL',
        'mail_reply_to'  => 'VARCHAR(190) NULL',
        'mail_notify'    => 'VARCHAR(190) NULL',   // signup + subscription alerts
        'mail_transport' => 'VARCHAR(8) NULL',     // mail | smtp
        'mail_enabled'   => 'TINYINT(1) NULL',
    ]);

    // ── Employment attributes + hour caps ──
    // The overtime engine already derives a *daily* allowance from work_start/work_end;
    // these caps are the explicit contractual limits (0 = no cap) used to flag
    // over-cap members on the payroll page and to size part-time entitlements.
    add_columns($pdo, 'users', [
        'employment_type'  => "VARCHAR(16) NOT NULL DEFAULT 'full_time'", // full_time|part_time|contractor
        'daily_hours_cap'  => 'DECIMAL(5,2) NOT NULL DEFAULT 0',
        'weekly_hours_cap' => 'DECIMAL(6,2) NOT NULL DEFAULT 0',
        'period_hours_cap' => 'DECIMAL(7,2) NOT NULL DEFAULT 0',
        'email_opt_out'    => 'TINYINT(1) NOT NULL DEFAULT 0',            // excludes non-transactional mail only
        'hired_on'         => 'DATE NULL',
    ]);

    // ── Manual pay adjustments (bonuses, commissions, reimbursements, deductions) ──
    // sign: +1 earning, -1 deduction. Held separately from sessions so they never
    // distort hours/activity reporting — they only join in at pay time.
    if (!table_exists($pdo, 'pay_adjustments')) {
        $pdo->exec("CREATE TABLE pay_adjustments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            org_id INT NOT NULL,
            user_id INT NOT NULL,
            kind VARCHAR(24) NOT NULL DEFAULT 'bonus',      -- bonus|commission|reimbursement|allowance|deduction|other
            label VARCHAR(160) NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,        -- always positive; sign carries the direction
            sign TINYINT NOT NULL DEFAULT 1,                -- 1 = earning, -1 = deduction
            currency VARCHAR(8) NOT NULL DEFAULT 'USD',
            taxable TINYINT(1) NOT NULL DEFAULT 1,
            effective_date DATE NOT NULL,                   -- decides which pay period it lands in
            note TEXT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'approved', -- approved|pending|rejected
            created_by_id INT NULL,
            reviewed_by_id INT NULL,
            reviewed_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (org_id), INDEX (user_id), INDEX (effective_date), INDEX (status),
            FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // ── Paid time off ──
    // leave_types are per-org so each company names its own (Vacation, Sick, Unpaid…).
    // paid=1 types convert approved days into paid hours at pay time; paid=0 types are
    // recorded for the calendar only. Entitlement is a simple per-year day allowance;
    // "used" is derived from approved requests, never stored (no drift).
    if (!table_exists($pdo, 'leave_types')) {
        $pdo->exec("CREATE TABLE leave_types (
            id INT AUTO_INCREMENT PRIMARY KEY,
            org_id INT NOT NULL,
            name VARCHAR(80) NOT NULL,
            code VARCHAR(24) NOT NULL,
            paid TINYINT(1) NOT NULL DEFAULT 1,
            days_per_year DECIMAL(5,2) NOT NULL DEFAULT 0,   -- default entitlement for a full-timer
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_org_code (org_id, code),
            INDEX (org_id),
            FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if (!table_exists($pdo, 'leave_requests')) {
        $pdo->exec("CREATE TABLE leave_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            org_id INT NOT NULL,
            user_id INT NOT NULL,
            leave_type_id INT NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            hours_per_day DECIMAL(5,2) NOT NULL DEFAULT 8,
            total_days DECIMAL(6,2) NOT NULL DEFAULT 0,     -- working days in range (half day = 0.5)
            total_hours DECIMAL(7,2) NOT NULL DEFAULT 0,
            half_day TINYINT(1) NOT NULL DEFAULT 0,
            reason TEXT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'pending',  -- pending|approved|rejected|cancelled
            reviewed_by_id INT NULL,
            reviewed_at DATETIME NULL,
            review_note TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (org_id), INDEX (user_id), INDEX (status), INDEX (start_date),
            FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (leave_type_id) REFERENCES leave_types(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if (!table_exists($pdo, 'leave_entitlements')) {
        $pdo->exec("CREATE TABLE leave_entitlements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            org_id INT NOT NULL,
            user_id INT NOT NULL,
            leave_type_id INT NOT NULL,
            year SMALLINT NOT NULL,
            days DECIMAL(5,2) NOT NULL DEFAULT 0,
            carried_days DECIMAL(5,2) NOT NULL DEFAULT 0,
            UNIQUE KEY uniq_ent (user_id, leave_type_id, year),
            INDEX (org_id),
            FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (leave_type_id) REFERENCES leave_types(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // ── Wise payout details (one active account per employee) ──
    // users.wise_id / wise_name stay as the payroll-import identity keys; this table
    // holds the full payout record used to build the Wise batch CSV.
    if (!table_exists($pdo, 'wise_accounts')) {
        $pdo->exec("CREATE TABLE wise_accounts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            org_id INT NOT NULL,
            user_id INT NOT NULL,
            recipient_id VARCHAR(64) NULL,                   -- Wise Recipient ID (UUID)
            account_holder VARCHAR(160) NOT NULL,            -- Wise Name
            email VARCHAR(190) NULL,
            account_summary VARCHAR(160) NULL,               -- 'Wise account' or 'BPI ending ·· 1593'
            source_currency VARCHAR(8) NOT NULL DEFAULT 'USD',
            target_currency VARCHAR(8) NOT NULL DEFAULT 'USD',
            recipient_type VARCHAR(16) NOT NULL DEFAULT 'PERSON',  -- PERSON|BUSINESS
            source_label VARCHAR(40) NOT NULL DEFAULT 'source',
            reference VARCHAR(80) NULL,                      -- payment reference template
            active TINYINT(1) NOT NULL DEFAULT 1,
            note TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            UNIQUE KEY uniq_wise_user (user_id),
            INDEX (org_id), INDEX (recipient_id),
            FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // ── Outbound email queue ──
    // Every message is queued first and sent by a drainer, so a failing SMTP host
    // never blocks a page load and every send is auditable/retryable.
    if (!table_exists($pdo, 'email_outbox')) {
        $pdo->exec("CREATE TABLE email_outbox (
            id INT AUTO_INCREMENT PRIMARY KEY,
            org_id INT NULL,
            user_id INT NULL,                                -- recipient, when they are a DeskPulse user
            to_email VARCHAR(190) NOT NULL,
            to_name VARCHAR(160) NULL,
            subject VARCHAR(255) NOT NULL,
            body_html MEDIUMTEXT NULL,
            body_text MEDIUMTEXT NULL,
            attachments TEXT NULL,                           -- JSON: [{path,name,mime}]
            kind VARCHAR(24) NOT NULL DEFAULT 'notice',      -- payslip|reminder|notice|message|system|test
            status VARCHAR(12) NOT NULL DEFAULT 'queued',    -- queued|sending|sent|failed|cancelled
            attempts INT NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            scheduled_at DATETIME NULL,
            sent_at DATETIME NULL,
            created_by_id INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (org_id), INDEX (status), INDEX (kind), INDEX (scheduled_at),
            FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // ── In-app notices (dismissible banner) ──
    if (!table_exists($pdo, 'notices')) {
        $pdo->exec("CREATE TABLE notices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            org_id INT NOT NULL,
            title VARCHAR(160) NOT NULL,
            body TEXT NOT NULL,
            level VARCHAR(12) NOT NULL DEFAULT 'info',       -- info|success|warn|urgent
            audience VARCHAR(16) NOT NULL DEFAULT 'all',     -- all|role|team|users
            audience_ref VARCHAR(255) NULL,                  -- role name, team id, or csv of user ids
            starts_at DATETIME NULL,
            ends_at DATETIME NULL,
            emailed TINYINT(1) NOT NULL DEFAULT 0,
            created_by_id INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (org_id), INDEX (ends_at),
            FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if (!table_exists($pdo, 'notice_reads')) {
        $pdo->exec("CREATE TABLE notice_reads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            notice_id INT NOT NULL,
            user_id INT NOT NULL,
            dismissed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_notice_user (notice_id, user_id),
            INDEX (user_id),
            FOREIGN KEY (notice_id) REFERENCES notices(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // ── Customer payment notifications ──
    // With bank transfer as the payment method, nothing tells the platform that money
    // is on its way — the admin would have to watch the bank. An organization submits
    // what it sent here; a super admin verifies it against the actual account and
    // confirms, which is what settles the invoice.
    //
    // Deliberately NOT the payments table: a claim is an unverified customer assertion,
    // and letting it near the money ledger would corrupt revenue reporting. Confirming
    // creates the payments row and links it back via payment_id.
    if (!table_exists($pdo, 'payment_claims')) {
        $pdo->exec("CREATE TABLE payment_claims (
            id INT AUTO_INCREMENT PRIMARY KEY,
            org_id INT NOT NULL,
            submitted_by_id INT NULL,
            amount_cents INT NOT NULL DEFAULT 0,
            currency VARCHAR(8) NOT NULL DEFAULT 'USD',
            paid_on DATE NULL,                              -- when the customer says they sent it
            method VARCHAR(24) NOT NULL DEFAULT 'bank_transfer',  -- bank_transfer|wire|ach|other
            sender_name VARCHAR(160) NULL,                  -- account the money came from
            sender_bank VARCHAR(160) NULL,
            reference VARCHAR(120) NULL,                    -- what they put in the transfer message
            note TEXT NULL,
            receipt_path VARCHAR(400) NULL,                 -- PRIVATE storage, never under the docroot
            status VARCHAR(16) NOT NULL DEFAULT 'pending',  -- pending|confirmed|rejected
            payment_id INT NULL,                            -- the payments row created on confirm
            reviewed_by_id INT NULL,
            reviewed_at DATETIME NULL,
            review_note TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (org_id), INDEX (status), INDEX (created_at),
            FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // ── Generated payslips ──
    // One row per (user, period) so "generate & email" is idempotent and the PDF can
    // be re-served without regenerating. pdf_path is relative to upload_path().
    if (!table_exists($pdo, 'payslips')) {
        $pdo->exec("CREATE TABLE payslips (
            id INT AUTO_INCREMENT PRIMARY KEY,
            org_id INT NOT NULL,
            user_id INT NOT NULL,
            period_start DATE NOT NULL,
            period_end DATE NOT NULL,                        -- inclusive last day
            cycle VARCHAR(16) NOT NULL DEFAULT 'semimonthly',
            hours DECIMAL(9,2) NOT NULL DEFAULT 0,
            gross DECIMAL(12,2) NOT NULL DEFAULT 0,
            deductions DECIMAL(12,2) NOT NULL DEFAULT 0,
            net DECIMAL(12,2) NOT NULL DEFAULT 0,
            currency VARCHAR(8) NOT NULL DEFAULT 'USD',
            breakdown MEDIUMTEXT NULL,                       -- JSON snapshot of the lines
            pdf_path VARCHAR(400) NULL,
            email_id INT NULL,                               -- email_outbox.id
            generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            emailed_at DATETIME NULL,
            UNIQUE KEY uniq_payslip (user_id, period_start, period_end),
            INDEX (org_id), INDEX (period_start),
            FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

function table_exists(PDO $pdo, string $name): bool
{
    return (bool) $pdo->query("SHOW TABLES LIKE " . $pdo->quote($name))->fetch();
}

/** Add any missing columns to a table. Returns true if it added at least one. */
function add_columns(PDO $pdo, string $table, array $cols): bool
{
    $existing = [];
    foreach ($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll() as $c) {
        $existing[$c['Field']] = true;
    }
    $added = false;
    foreach ($cols as $name => $def) {
        if (!isset($existing[$name])) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN $name $def");
            $added = true;
        }
    }
    return $added;
}

/** Create tables from schema.sql if the core table is missing. */
function ensure_schema(PDO $pdo): void
{
    $exists = $pdo->query("SHOW TABLES LIKE 'organizations'")->fetch();
    if ($exists) {
        return;
    }
    $sql = file_get_contents(dirname(__DIR__) . '/schema.sql');
    // Strip `--` comments first (they may contain semicolons, which would break a
    // naive split), then execute statement-by-statement.
    $clean = [];
    foreach (explode("\n", $sql) as $line) {
        $pos = strpos($line, '--');
        $clean[] = $pos === false ? $line : substr($line, 0, $pos);
    }
    foreach (array_filter(array_map('trim', explode(';', implode("\n", $clean)))) as $stmt) {
        if ($stmt !== '') {
            $pdo->exec($stmt);
        }
    }
}

/** Fetch a single row or null. */
function db_one(string $sql, array $params = []): ?array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

/** Fetch all rows. */
function db_all(string $sql, array $params = []): array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** Run an INSERT/UPDATE/DELETE; returns last insert id for inserts. */
function db_exec(string $sql, array $params = []): int
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return (int) db()->lastInsertId();
}
