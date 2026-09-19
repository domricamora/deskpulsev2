<?php

/**
 * Configuration invariants established by the Phase 1 audit.
 *
 * These are not style preferences — each one encodes a constraint that, if
 * broken, silently changes behaviour or breaks the deployed desktop agents.
 */

test('the application clock is UTC', function () {
    // Storage and computation are UTC; reporting windows are cut in the org's
    // report_tz and display happens in the viewer's browser timezone.
    // docs/migration/reports.md §1.
    expect(config('app.timezone'))->toBe('UTC');
});

test('the agent API keeps its frozen prefix', function () {
    // Windows, macOS and Linux agents are already deployed against /webhooks/*.
    // A versioned prefix would break every install.
    // docs/migration/api-contract.md §7.
    expect(config('deskpulse.agent.webhook_prefix'))->toBe('webhooks');
});

test('the agent HMAC contract is unchanged', function () {
    expect(config('deskpulse.agent.signature_algo'))->toBe('sha256')
        ->and(config('deskpulse.agent.device_header'))->toBe('X-DeskPulse-Device')
        ->and(config('deskpulse.agent.signature_header'))->toBe('X-DeskPulse-Signature');
});

test('the idle threshold default is 15 minutes and defined in one place', function () {
    // docs/migration/monitoring.md §1 — must not be re-hardcoded elsewhere.
    expect(config('deskpulse.monitoring.idle_threshold_min'))->toBe(15);
});

test('remote control keeps its server-enforced containment windows', function () {
    // These three constants are the containment mechanism for remote desktop
    // control. docs/migration/remote-control.md §2.
    expect(config('deskpulse.remote.agent_timeout_s'))->toBe(15)
        ->and(config('deskpulse.remote.idle_max_s'))->toBe(600)
        ->and(config('deskpulse.remote.pending_timeout_s'))->toBe(30);
});

test('remote stream parameters stay server-dictated', function () {
    expect(config('deskpulse.remote.fps'))->toBe(3)
        ->and(config('deskpulse.remote.max_width'))->toBe(1280)
        ->and(config('deskpulse.remote.jpeg_quality'))->toBe(55);
});

test('the free Solo plan excludes screenshots and caps history at 7 days', function () {
    // Enforced server-side, not merely hidden in the UI.
    // docs/migration/monitoring.md §1.
    expect(config('deskpulse.plans.solo.screenshots'))->toBeFalse()
        ->and(config('deskpulse.plans.solo.history_days'))->toBe(7)
        ->and(config('deskpulse.plans.solo.max_seats'))->toBe(1);
});

test('screenshots default to a private disk', function () {
    // Legacy stores them under public/uploads, where anyone with the URL can read
    // them cross-tenant. docs/migration/screenshots.md §4.
    expect(config('deskpulse.screenshots.disk'))->toBe('private');
});

