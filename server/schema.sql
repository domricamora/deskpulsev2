-- DeskPulse MySQL schema.
-- Multi-tenant: every row belongs to an organization (directly via org_id, or
-- transitively through its user). Import with:
--   mysql -u root deskpulse < server/schema.sql
-- (the app also auto-creates these tables on first run if missing).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS organizations (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(160) NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Reporting clock + payroll cycle. Sessions are stored in UTC; every period
    -- window is cut in report_tz so "today" doesn't depend on the server's php.ini.
    report_tz          VARCHAR(64) NOT NULL DEFAULT 'UTC',
    week_start         TINYINT NOT NULL DEFAULT 1,               -- ISO 1=Mon..7=Sun
    pay_cycle          VARCHAR(16) NOT NULL DEFAULT 'semimonthly', -- weekly|biweekly|semimonthly|rolling15|monthly
    pay_cycle_anchor   DATE NULL,                                -- origin for the fixed 15/14-day cycles
    pay_currency       VARCHAR(8) NOT NULL DEFAULT 'USD',
    -- Platform-wide mail settings, held on the super-admin's platform org row.
    -- NULL = not overridden here; falls back to config.php.
    mail_from          VARCHAR(190) NULL,
    mail_from_name     VARCHAR(120) NULL,
    mail_reply_to      VARCHAR(190) NULL,
    mail_notify        VARCHAR(190) NULL,           -- signup + subscription alerts
    mail_transport     VARCHAR(8) NULL,             -- mail | smtp
    mail_enabled       TINYINT(1) NULL,
    -- Payment method / Wise connection (platform org row). The API token is stored
    -- ENCRYPTED via dp_encrypt(); NULL here means "fall back to config('payments.*')".
    pay_enabled        TINYINT(1) NULL,
    wise_env           VARCHAR(12) NULL,          -- sandbox | live
    wise_api_token_enc TEXT NULL,
    wise_profile_id    VARCHAR(40) NULL,
    wise_balance_id    VARCHAR(40) NULL,
    wise_webhook_key   TEXT NULL,                 -- Wise public key (PEM) for signatures
    wise_webhook_id    VARCHAR(64) NULL,
    wise_last_sync_at  DATETIME NULL,
    wise_last_error    VARCHAR(400) NULL,
    wise_usd_bank      VARCHAR(160) NULL,
    wise_usd_routing   VARCHAR(40) NULL,
    wise_usd_account   VARCHAR(60) NULL,
    wise_usd_type      VARCHAR(24) NULL,
    wise_usd_address   VARCHAR(255) NULL,
    -- Direct bank transfer is the live payment method; 'wise_api' re-enables the
    -- archived webhook + statement reconciliation.
    pay_method         VARCHAR(16) NOT NULL DEFAULT 'bank',
    wise_usd_holder    VARCHAR(160) NULL,
    wise_usd_swift     VARCHAR(24) NULL,
    -- Strong Customer Authentication: WE own this private key and upload the public
    -- half to Wise. Distinct from wise_webhook_key, where Wise owns the private half.
    wise_sca_public      TEXT NULL,
    wise_sca_private_enc TEXT NULL,
    wise_sca_created_at  DATETIME NULL,
    -- Signup lifecycle (super-admin reviewed) + billing.
    status             VARCHAR(16) NOT NULL DEFAULT 'approved',  -- pending | approved | rejected
    billing_status     VARCHAR(16) NOT NULL DEFAULT 'none',      -- none | active | paused
    monthly_fee        DOUBLE NOT NULL DEFAULT 0,                -- effective monthly subscription fee = base * (1 - discount/100)
    seat_rate          DOUBLE NOT NULL DEFAULT 0,                -- legacy per-employee / month (superseded by plan pricing)
    plan_type          VARCHAR(16) NOT NULL DEFAULT 'organization',  -- solo | individual | per_seat | organization | enterprise
    discount_pct       DECIMAL(5,2) NOT NULL DEFAULT 0,          -- 0..100 off the plan's standard price
    price_individual   DECIMAL(10,2) NOT NULL DEFAULT 9,         -- platform base price (set on the platform org row)
    price_organization DECIMAL(10,2) NOT NULL DEFAULT 49,        -- platform base price (set on the platform org row)
    seats_min          INT NOT NULL DEFAULT 2,                   -- organization plan seat range (platform org row, display)
    seats_max          INT NOT NULL DEFAULT 50,
    -- Published price ladder (platform org row). Each default means "inactive", so a
    -- fresh install bills exactly as it did before the ladder existed.
    price_solo         DOUBLE NOT NULL DEFAULT 0,                -- Solo plan price (0 = free)
    price_seat_cap     DOUBLE NOT NULL DEFAULT 0,                -- per-seat bill never exceeds this; 0 = uncapped
    seats_bill_min     INT NOT NULL DEFAULT 0,                   -- per-seat billing floor; 0 = no minimum
    seats_cap_covers   INT NOT NULL DEFAULT 0,                   -- display: seats the cap covers; 0 = unstated
    custom_fee         DOUBLE NULL,                              -- per-tenant fee for plan_type = 'enterprise'
    ga4_measurement_id VARCHAR(24) NULL,                         -- analytics (platform org row); NULL = use config.php
    clarity_project_id VARCHAR(24) NULL,
    -- Per-organization enterprise SSO (OpenID Connect). The client secret is encrypted
    -- at rest with dp_encrypt(). SAML is deliberately unsupported — see src/oauth.php.
    sso_enabled        TINYINT(1) NOT NULL DEFAULT 0,
    sso_issuer         VARCHAR(255) NULL,
    sso_client_id      VARCHAR(255) NULL,
    sso_client_secret  TEXT NULL,
    sso_domains        VARCHAR(255) NULL,
    sso_enforce        TINYINT(1) NOT NULL DEFAULT 0,
    -- First-touch acquisition attribution, stamped at signup.
    utm_source         VARCHAR(80) NULL,
    utm_medium         VARCHAR(80) NULL,
    utm_campaign       VARCHAR(80) NULL,
    utm_content        VARCHAR(80) NULL,
    utm_landing        VARCHAR(190) NULL,
    billing_currency   VARCHAR(8) NOT NULL DEFAULT 'USD',
    billing_started_at DATETIME NULL,
    reviewed_at        DATETIME NULL,
    onboarded_at       DATETIME NULL,                            -- company-admin first-run wizard done
    logo_path          VARCHAR(400) NULL,                        -- company branding logo (WebP, under uploads/logos/)
    -- Platform-wide screenshot retention in days (0 = keep forever); the value on
    -- the super-admin's platform org governs cleanup across every organization.
    screenshot_retention_days INT NOT NULL DEFAULT 30,
    -- Admin-controlled monitoring policy (the desktop agent fetches and obeys these).
    screenshot_interval_min INT NOT NULL DEFAULT 10,
    screenshot_blur         TINYINT(1) NOT NULL DEFAULT 0,
    idle_threshold_min      INT NOT NULL DEFAULT 15,
    sync_interval_s         INT NOT NULL DEFAULT 60,
    track_screenshots       TINYINT(1) NOT NULL DEFAULT 1,
    track_windows           TINYINT(1) NOT NULL DEFAULT 1,
    track_processes         TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    org_id        INT NOT NULL,
    name          VARCHAR(120) NOT NULL,
    email         VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role          VARCHAR(16) NOT NULL DEFAULT 'member',   -- super_admin | client_admin | manager | member
    phone         VARCHAR(40) NULL,
    job_title     VARCHAR(120) NULL,
    pay_type      VARCHAR(16) NOT NULL DEFAULT 'hourly',   -- hourly | monthly (internal cost)
    pay_rate      DOUBLE NOT NULL DEFAULT 0,
    bill_type     VARCHAR(16) NOT NULL DEFAULT 'hourly',   -- hourly | monthly (client charge)
    bill_rate     DOUBLE NOT NULL DEFAULT 0,               -- per hour, or flat monthly service charge
    currency      VARCHAR(8) NOT NULL DEFAULT 'USD',
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,    -- temp-password accounts: force a reset on first login
    -- NOTE: password_resets and user_identities are created by the migrator in db.php
    -- (they are additive tables, so a pre-existing database picks them up on first boot).
    welcomed_at   DATETIME NULL,                           -- first-run role guide dismissed (employees/HR/IT/client viewers)
    -- Standard work schedule (drives the overtime split; see recompute_overtime()).
    work_start    TIME NULL,
    work_end      TIME NULL,
    work_days     VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5', -- ISO weekdays, Mon=1..Sun=7
    -- Employment attributes + contractual hour caps (0 = no cap; caps are advisory
    -- and surface as flags on the Payroll page, they never block tracking).
    employment_type  VARCHAR(16) NOT NULL DEFAULT 'full_time',  -- full_time | part_time | contractor
    daily_hours_cap  DECIMAL(5,2) NOT NULL DEFAULT 0,
    weekly_hours_cap DECIMAL(6,2) NOT NULL DEFAULT 0,
    period_hours_cap DECIMAL(7,2) NOT NULL DEFAULT 0,
    hired_on         DATE NULL,
    email_opt_out    TINYINT(1) NOT NULL DEFAULT 0,             -- mutes non-transactional mail only
    -- Payroll-import identity keys (see dash_import() / dash_wise()).
    external_ref  VARCHAR(40) NULL,                        -- the payroll sheet's "VT ID"
    wise_id       VARCHAR(64) NULL,
    wise_name     VARCHAR(160) NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (org_id),
    INDEX idx_users_external_ref (org_id, external_ref),
    FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS teams (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    name        VARCHAR(120) NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (org_id),
    FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS team_members (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    team_id   INT NOT NULL,
    user_id   INT NOT NULL,
    UNIQUE KEY uniq_team_user (team_id, user_id),
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS devices (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    name        VARCHAR(160) NOT NULL DEFAULT 'Desktop',
    secret      VARCHAR(64) NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen   DATETIME NULL,
    INDEX (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Clients = the end companies a staffing org serves. Managed by admins/managers.
CREATE TABLE IF NOT EXISTS clients (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    org_id        INT NOT NULL,
    name          VARCHAR(200) NOT NULL,
    contact_email VARCHAR(190) NOT NULL DEFAULT '',
    notes         TEXT NULL,
    archived      TINYINT(1) NOT NULL DEFAULT 0,
    user_id       INT NULL,           -- the client's own read-only login account (client_viewer)
    bill_rate     DOUBLE NOT NULL DEFAULT 0,             -- standard/quoted rate for this client (reference only —
    currency      VARCHAR(8) NOT NULL DEFAULT 'USD',     -- actual billing still rolls up from each agent's own bill_rate)
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (org_id),
    FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Contracts = an engagement with a client (billing rate, term, status).
CREATE TABLE IF NOT EXISTS contracts (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    client_id   INT NOT NULL,
    title       VARCHAR(200) NOT NULL,
    bill_rate   DOUBLE NOT NULL DEFAULT 0,    -- billed to the client, per hour
    currency    VARCHAR(8) NOT NULL DEFAULT 'USD',
    status      VARCHAR(16) NOT NULL DEFAULT 'active',  -- active | ended
    start_date  DATE NULL,
    end_date    DATE NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (org_id), INDEX (client_id),
    FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Contract ↔ member roster: which org members are assigned to work under a given
-- contract. Managed on the Contracts page by company admin / HR admin.
CREATE TABLE IF NOT EXISTS contract_members (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    contract_id  INT NOT NULL,
    user_id      INT NOT NULL,
    assigned_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_contract_user (contract_id, user_id),
    INDEX (contract_id), INDEX (user_id),
    FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Agent ↔ client assignment (an agent may serve one OR many clients). Managed on
-- the Agents page by managers/admins; used to scope an agent's clients & reporting.
CREATE TABLE IF NOT EXISTS agent_clients (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    agent_id    INT NOT NULL,
    client_id   INT NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_agent_client (agent_id, client_id),
    INDEX (agent_id), INDEX (client_id),
    FOREIGN KEY (agent_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS projects (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    client_id   INT NULL,
    name        VARCHAR(160) NOT NULL,
    client      VARCHAR(160) NOT NULL DEFAULT '',  -- legacy free-text client name
    billable    TINYINT(1) NOT NULL DEFAULT 1,
    hourly_rate DOUBLE NOT NULL DEFAULT 0,
    archived    TINYINT(1) NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (org_id), INDEX (client_id),
    FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Worker-managed tasks. A worker can add/remove their own tasks and mark which
-- one they're working on; that task is attached to the sessions they track.
CREATE TABLE IF NOT EXISTS tasks (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    user_id     INT NOT NULL,
    client_id   INT NULL,
    project_id  INT NULL,           -- deprecated (Projects retired); kept for migration
    title       VARCHAR(240) NOT NULL,
    status      VARCHAR(16) NOT NULL DEFAULT 'open',   -- open | done
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (org_id), INDEX (user_id),
    FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sessions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    client_id       INT NULL,
    project_id      INT NULL,        -- deprecated (Projects retired); kept for migration
    task_id         INT NULL,
    device_id       INT NULL,
    started_at      DATETIME NOT NULL,
    ended_at        DATETIME NULL,
    last_seen_at    DATETIME NULL,        -- last time the agent posted data (UTC); used to auto-close offline sessions
    active_s        INT NOT NULL DEFAULT 0,
    inactive_s      INT NOT NULL DEFAULT 0,
    source          VARCHAR(16) NOT NULL DEFAULT 'agent',     -- agent | manual
    note            TEXT NULL,
    approval_status VARCHAR(16) NOT NULL DEFAULT 'approved',  -- approved | pending | rejected
    reviewed_by_id  INT NULL,
    reviewed_at     DATETIME NULL,
    review_note     TEXT NULL,
    overtime_s              INT NOT NULL DEFAULT 0,             -- overtime portion of active_s
    overtime_status         VARCHAR(16) NOT NULL DEFAULT 'none',-- none | pending | approved | rejected (HR)
    overtime_reviewed_by_id INT NULL,
    overtime_reviewed_at    DATETIME NULL,
    overtime_review_note    TEXT NULL,
    overtime_computed       TINYINT(1) NOT NULL DEFAULT 0,      -- 1 once the day's overtime split has been calculated
    INDEX (user_id), INDEX (started_at), INDEX (approval_status), INDEX (overtime_status), INDEX (task_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL,
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS activity_samples (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    session_id     INT NOT NULL,
    ts             DATETIME NOT NULL,
    keyboard_count INT NOT NULL DEFAULT 0,
    mouse_count    INT NOT NULL DEFAULT 0,
    activity_pct   INT NOT NULL DEFAULT 0,
    INDEX (session_id), INDEX (ts),
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS window_events (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    session_id    INT NOT NULL,
    ts            DATETIME NOT NULL,
    app_name      VARCHAR(160) NOT NULL DEFAULT '',
    window_title  VARCHAR(400) NOT NULL DEFAULT '',
    focus_seconds INT NOT NULL DEFAULT 0,
    INDEX (session_id), INDEX (ts),
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS process_snapshots (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    session_id  INT NOT NULL,
    ts          DATETIME NOT NULL,
    app_name    VARCHAR(200) NOT NULL DEFAULT '',
    pid         INT NOT NULL DEFAULT 0,
    INDEX (session_id),
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS idle_periods (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    session_id  INT NOT NULL,
    start_ts    DATETIME NOT NULL,
    end_ts      DATETIME NOT NULL,
    duration_s  INT NOT NULL DEFAULT 0,
    INDEX (session_id),
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS screenshots (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    session_id  INT NOT NULL,
    ts          DATETIME NOT NULL,
    file_path   VARCHAR(400) NOT NULL,
    blurred     TINYINT(1) NOT NULL DEFAULT 0,
    monitor     INT NULL,                                -- physical screen index (multi-monitor); NULL = single
    INDEX (session_id), INDEX (ts),
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Remote desktop control (super-admin only, unpublished). A live session streams
-- JPEG frames captured by the agent and relays queued input commands back to it;
-- auto-expiry is enforced server-side (see remote_gc() in src/remote.php).
CREATE TABLE IF NOT EXISTS remote_sessions (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    device_id      INT NOT NULL,
    user_id        INT NOT NULL,
    admin_user_id  INT NOT NULL,
    org_id         INT NOT NULL,
    token          VARCHAR(48) NOT NULL,
    status         VARCHAR(16) NOT NULL DEFAULT 'pending',   -- pending | active | ended
    end_reason     VARCHAR(32) NULL,                         -- admin | agent_gone | expired
    screen_w       INT NULL,
    screen_h       INT NULL,
    frame_seq      INT NOT NULL DEFAULT 0,
    last_frame_at  DATETIME NULL,
    last_input_at  DATETIME NULL,
    started_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    activated_at   DATETIME NULL,
    ended_at       DATETIME NULL,
    INDEX (device_id), INDEX (status), INDEX (org_id),
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS remote_input_events (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    session_id  INT NOT NULL,
    payload     TEXT NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (session_id),
    FOREIGN KEY (session_id) REFERENCES remote_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS share_links (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    scope           VARCHAR(16) NOT NULL DEFAULT 'user',   -- user | team | org
    target_id       INT NULL,
    token           VARCHAR(48) NOT NULL UNIQUE,
    label           VARCHAR(160) NOT NULL DEFAULT '',
    period_default  VARCHAR(16) NOT NULL DEFAULT 'week',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      DATETIME NULL,
    revoked         TINYINT(1) NOT NULL DEFAULT 0,
    INDEX (org_id),
    FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Payroll, time off, payouts, messaging (see ensure_migrations() in src/db.php) ──

CREATE TABLE IF NOT EXISTS pay_adjustments (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS leave_types (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS leave_requests (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS leave_entitlements (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wise_accounts (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS email_outbox (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notices (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notice_reads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        notice_id INT NOT NULL,
        user_id INT NOT NULL,
        dismissed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_notice_user (notice_id, user_id),
        INDEX (user_id),
        FOREIGN KEY (notice_id) REFERENCES notices(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payslips (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Customer payment notifications: an unverified claim that money was sent. Kept out
-- of the payments ledger until a super admin confirms it.
CREATE TABLE IF NOT EXISTS payment_claims (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
