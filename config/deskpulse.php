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
