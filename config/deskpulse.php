<?php

/**
 * DeskPulse application configuration.
 *
 * These are DEFAULTS. Most of them are overridden per organization by columns on
 * the `organizations` row — the legacy behaviour is documented in
 * docs/migration/monitoring.md §1 (org_policy) and must be preserved: an
 * organization's monitoring policy wins, and the subscription plan can only ever
 * turn capture OFF, never on.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Monitoring policy defaults
    |--------------------------------------------------------------------------
    | Served to the desktop agent by GET /webhooks/policy. The 15-minute idle
    | threshold is defined here once — do not re-hardcode it elsewhere.
    */
    'monitoring' => [
        'idle_threshold_min' => (int) env('DESKPULSE_IDLE_THRESHOLD_MIN', 15),
        'sync_interval_s'    => (int) env('DESKPULSE_SYNC_INTERVAL_S', 60),

        // A session whose heartbeat is older than this is closed at its LAST
        // heartbeat, never at "now" — a crashed agent must not bank hours.
        'stale_session_min'  => (int) env('DESKPULSE_STALE_SESSION_MIN', 15),

        'track_windows'      => true,
        'track_processes'    => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Screenshots
    |--------------------------------------------------------------------------
    | Blur is applied by the agent before upload; the server stores what it
    | receives and records the flag. Retention is platform-wide (0 = forever).
    */
    'screenshots' => [
        'interval_min'   => (int) env('SCREENSHOT_INTERVAL_MIN', 10),
        'retention_days' => (int) env('SCREENSHOT_RETENTION_DAYS', 30),
        'disk'           => env('SCREENSHOT_DISK', 'private'),
        'allowed_ext'    => ['png', 'jpg', 'jpeg', 'webp'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Desktop agent API
    |--------------------------------------------------------------------------
    | FROZEN CONTRACT. Windows, macOS and Linux agents are already deployed
    | against /webhooks/* and sign the RAW request body with HMAC-SHA256 using
    | their device secret. Do not add a version prefix, do not re-encode the body
    | before verifying, and do not return 422 on a queued endpoint — the agent
    | cannot distinguish error classes and will retry forever.
    |
    | See docs/migration/api-contract.md and agent-protocol.md.
    */
    'agent' => [
        'webhook_prefix'    => env('AGENT_WEBHOOK_PREFIX', 'webhooks'),
        'signature_header'  => 'X-DeskPulse-Signature',
        'device_header'     => 'X-DeskPulse-Device',
        'signature_algo'    => 'sha256',
    ],

    /*
    |--------------------------------------------------------------------------
    | Remote desktop control
    |--------------------------------------------------------------------------
    | Server-enforced expiry is the containment mechanism — the server never
    | trusts the agent to end a session. Garbage collection runs at the top of
    | every remote endpoint, not only on a schedule.
    */
    'remote' => [
        'agent_timeout_s'   => (int) env('REMOTE_AGENT_TIMEOUT_S', 15),
        'idle_max_s'        => (int) env('REMOTE_IDLE_MAX_S', 600),
        'pending_timeout_s' => (int) env('REMOTE_PENDING_TIMEOUT_S', 30),

        // Stream parameters are dictated by the server, not negotiated.
        'fps'               => 3,
        'max_width'         => 1280,
        'jpeg_quality'      => 55,
    ],

    /*
    |--------------------------------------------------------------------------
    | Internal mail
    |--------------------------------------------------------------------------
    | Where signup and billing alerts to ourselves go. Blank switches them off.
    | Customer-facing mail is addressed per message from the outbox row.
    */
    'mail' => [
        'notify' => env('MAIL_NOTIFY', 'sales@deskpulse.click'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Legacy secret-at-rest key
    |--------------------------------------------------------------------------
    | Keys the `v1:` AES-256-GCM envelope that already protects every stored
    | Wise token and SSO client secret. It is NOT Laravel's APP_KEY and must not
    | be regenerated: change it and every existing ciphertext stops opening.
    |
    | Carry the legacy config.php `app_secret` across verbatim at cutover.
    | See docs/migration/security.md §4 (decision D15).
    */
    'app_secret' => env('DESKPULSE_APP_SECRET', 'deskpulse'),

    /*
    |--------------------------------------------------------------------------
    | Subscriptions and the paywall
    |--------------------------------------------------------------------------
    | Master switch and trial length. Both are FALLBACKS: the platform
    | organization's own row wins (`pay_enabled`, `trial_days`), which is how a
    | super admin flips payments on from the Accounting page without a deploy.
    |
    | With payments off the trial and paywall are inert and the app behaves as it
    | did before billing existed — signups then fall back to "pending until
    | approved". See docs/migration/authentication.md §2 gate 4.
    */
    'payments' => [
        'enabled'    => (bool) env('PAYMENTS_ENABLED', false),
        'trial_days' => (int) env('TRIAL_DAYS', 14),
    ],

    /*
    |--------------------------------------------------------------------------
    | Standard plan prices
    |--------------------------------------------------------------------------
    | Product defaults, overridden per platform by columns on the platform
    | organization's row. The ladder fields (solo / seat_cap / seats_bill_min /
    | seats_cap_covers) default to 0 meaning "inactive".
    |
    | seat_cap in particular must NOT fall back to `organization`: an existing
    | per-seat tenant billing above that figure would be silently re-priced the
    | moment the column appeared.
    */
    'plan_prices' => [
        'individual'       => 9.0,
        'organization'     => 49.0,
        'per_seat'         => 5.0,
        'seats_min'        => 2,
        'seats_max'        => 50,
        'solo'             => 0.0,
        'seat_cap'         => 0.0,
        'seats_bill_min'   => 0,
        'seats_cap_covers' => 0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Federated sign-in (OIDC)
    |--------------------------------------------------------------------------
    | A provider is only offered when BOTH its client id and secret are set —
    | with nothing configured the buttons do not render at all, rather than
    | showing a dead button. Credentials live in config/services.php.
    |
    | Per-organization enterprise SSO is configured on the organization row
    | (sso_issuer / sso_client_id / sso_client_secret / sso_domains), not here.
    |
    | SAML is deliberately not supported. See docs/migration/authentication.md §6.
    */
    'oauth' => [
        'providers' => [
            'google' => [
                'label'  => 'Google',
                'issuer' => 'https://accounts.google.com',
                'scope'  => 'openid email profile',
            ],
            'microsoft' => [
                'label' => 'Microsoft',

                // 'common' allows both work/school (Entra) and personal accounts.
                // The issuer is tenant-specific at runtime, so it is validated
                // against a pattern rather than by equality.
                'issuer' => 'https://login.microsoftonline.com/common/v2.0',
                'scope'  => 'openid email profile',
            ],
        ],

        // Discovery and token endpoints only — short, because a slow identity
        // provider must not hold a web request open.
        'http_timeout_s'  => 12,
        'connect_timeout_s' => 6,

        // How long a started sign-in may take to come back to the callback.
        'flow_max_age_s'  => 600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Password resets
    |--------------------------------------------------------------------------
    | Tokens are stored as a SHA-256 hash, are single-use, and every other
    | outstanding token for the account is burned on use. The per-IP limit is
    | deliberately a multiple of the per-email one, so a shared office NAT does
    | not lock out a whole floor.
    */
    'password_reset' => [
        'ttl_minutes'    => 60,
        'max_per_hour'   => 5,
        'ip_multiplier'  => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Plan limits
    |--------------------------------------------------------------------------
    | The free Solo tier is enforced server-side, not just in the UI.
    | 0 means unlimited.
    */
    'plans' => [
        'solo' => [
            'history_days' => 7,
            'screenshots'  => false,
            'max_seats'    => 1,
        ],
    ],

];
