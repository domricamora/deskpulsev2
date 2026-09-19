<?php
/**
 * DeskPulse server configuration.
 *
 * Copy this file to `config.php` (gitignored) and edit the values. It is
 * environment-aware: on the local WAMP box it uses local MySQL credentials; on
 * the live server it uses the production database — so the same file deploys to
 * both. On a default WAMP install the MySQL user is `root` with no password.
 */

// ── Detect localhost (WAMP) vs the live server ──
$host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? ''))[0]);
$onLocalhost = PHP_OS_FAMILY === 'Windows'                       // WAMP dev box
    || in_array($host, ['localhost', '127.0.0.1', '::1'], true)
    || in_array($_SERVER['SERVER_ADDR'] ?? '', ['127.0.0.1', '::1'], true);

$db = $onLocalhost
    ? [   // Local (WAMP default)
        'host' => 'localhost', 'port' => 3306, 'name' => 'deskpulse',
        'user' => 'root', 'pass' => '', 'charset' => 'utf8mb4',
    ]
    : [   // Live server — set your production DB credentials here
        'host' => 'localhost', 'port' => 3306, 'name' => 'deskpulse',
        'user' => 'CHANGE_ME', 'pass' => 'CHANGE_ME', 'charset' => 'utf8mb4',
    ];

return [
    // ── Database (MySQL / MariaDB via PDO) ──
    'db' => $db,

    // Secret used to sign session-related tokens / CSRF. Change this.
    'app_secret' => 'change-me-to-a-long-random-string',

    // Public base URL of this server, used when displaying share links.
    // Leave empty to auto-detect from the current request.
    'public_base_url' => '',

    // Where uploaded screenshots are stored (absolute path or relative to server/).
    'upload_dir' => 'public/uploads',

    // Max screenshot upload size (MB).
    'max_upload_mb' => 10,

    // Idle threshold (minutes) the agent uses — surfaced in the UI for labels.
    'idle_threshold_min' => 15,

    // Where generated payslip PDFs are stored. This MUST stay outside the document
    // root: public/.htaccess serves any real file it finds, so anything under
    // public/uploads is readable by anyone with the URL. Leave empty for the
    // default (server/storage), which is created with a deny-all .htaccess.
    'private_dir' => '',

    // ── Outbound email ──
    // Messages are always queued in email_outbox first and delivered afterwards, so a
    // slow or broken mail host never blocks a page load and every send is auditable.
    //
    //   mail_transport: 'mail' = PHP's built-in mail() via the server's own MTA
    //                            (nothing to configure, but the host must actually be
    //                            able to send, and SPF/DKIM for the From domain must
    //                            be set up in DNS or mail will land in spam).
    //                   'smtp' = the built-in SMTP client in src/mailer.php
    //                            (authenticated; works from anywhere, incl. local WAMP).
    //
    //   NOTE: mail() does NOT work on a stock Windows/WAMP box — there is no local
    //   sendmail. Use 'smtp' for local testing, or just accept that queued mail stays
    //   queued until you deploy.
    //
    //   mail_autoflush: deliver a couple of queued messages on each dashboard request.
    //                Turn this on only if you cannot run the cron worker below.
    //
    // Preferred drain (cron, every 5 minutes):
    //   */5 * * * * php /path/to/server/tools/mail_worker.php >/dev/null 2>&1
    //
    // Verify with "Send test to myself" on the Messages page.
    'mail_enabled'   => true,
    'mail_transport' => 'mail',                      // mail | smtp
    'mail_from'      => 'support@deskpulse.click',
    'mail_from_name' => 'DeskPulse',
    'mail_reply_to'  => 'support@deskpulse.click',
    'mail_autoflush' => false,

    // Internal alerts: where "a new organization signed up" and subscription-activity
    // notifications are sent. Not customer-facing. Leave empty to disable them.
    'mail_notify' => 'sales@deskpulse.click',

    // Only used when mail_transport = 'smtp'.
    //   smtp_secure: 'tls' = STARTTLS (usually port 587) · 'ssl' = implicit TLS
    //                (usually port 465) · 'none' = plaintext (testing only).
    'smtp_host'      => '',
    'smtp_port'      => 587,
    'smtp_secure'    => 'tls',
    'smtp_user'      => '',
    'smtp_pass'      => '',
    'smtp_timeout'   => 20,

    // ── Payments (Wise · US bank deposit) ──
    // Platform subscriptions: a free trial then a flat monthly plan fee collected by
    // US bank deposit (ACH/wire, USD) to a Wise Business USD balance, auto-reconciled
    // via the Wise API + balances#credit webhook. OMIT this whole block (or set
    // enabled=false) to disable the trial/paywall entirely — the app then behaves as
    // before. trial_days here is only the default; the live value is set by the super
    // admin (stored on the platform org row). Fill wise.usd_details to show real deposit
    // instructions before the Wise API is wired up.
    'payments' => [
        'enabled'    => false,
        'trial_days' => 14,
        'wise' => [
            'env'                => 'sandbox',   // sandbox | live
            'api_token'          => '',
            'profile_id'         => 0,
            'balance_id'         => 0,
            'webhook_public_key' => '',          // Wise webhook signing public key (PEM) for openssl_verify
            'usd_details' => [
                'bank'           => '',
                'routing'        => '',
                'account_number' => '',
                'account_type'   => 'Checking',
                'address'        => '',
            ],
        ],
    ],

    // Analytics for the public marketing site. Both optional — blank means nothing is
    // loaded and no third-party script reaches the page. A super admin can override
    // these without a deploy on Platform settings → Analytics (stored on the platform
    // org row), which is the preferred place to set them.
    //
    // NOTE: the Content-Security-Policy in src/bootstrap.php AND its copy in
    // public/.htaccess must allow googletagmanager.com / google-analytics.com /
    // clarity.ms, or the tags load into a blocked request and silently collect nothing.
    'analytics' => [
        'ga4_id'     => '',   // G-XXXXXXXXXX
        'clarity_id' => '',   // Microsoft Clarity project id
    ],

    // Federated sign-in (OpenID Connect). Leave a client_id blank and that button simply
    // does not render. Register the app with each provider and set the redirect URI to
    // exactly https://<your-host>/auth/google/callback (and /auth/microsoft/callback).
    //
    //   Google:    https://console.cloud.google.com/apis/credentials  → OAuth client ID
    //              → Web application. Add the redirect URI verbatim.
    //   Microsoft: https://entra.microsoft.com → App registrations → New registration
    //              → Web platform. "Accounts in any organizational directory and personal
    //              Microsoft accounts" matches the 'common' issuer used here.
    //
    // Per-organization enterprise SSO is configured by each customer in the app
    // (Settings → Single sign-on), not here — it is tenant data, not platform config.
    // SAML is deliberately not supported; see the note at the top of src/oauth.php.
    'oauth' => [
        'google'    => ['client_id' => '', 'client_secret' => ''],
        'microsoft' => ['client_id' => '', 'client_secret' => ''],
    ],

    // Optional first-run bootstrap super admin. When set, a fresh database (with no
    // super admin yet) auto-creates this account so a new deployment is ready to sign
    // in with zero manual setup. pass_hash is a bcrypt string from password_hash();
    // generate one with:  php -r "echo password_hash('YourPassword', PASSWORD_DEFAULT);"
    // Uncomment and set your own before deploying. Remove/omit to disable.
    // 'bootstrap_admin' => [
    //     'email'     => 'admin@example.com',
    //     'name'      => 'Super Admin',
    //     'pass_hash' => '$2y$10$replace_with_your_own_bcrypt_hash',
    // ],
];
