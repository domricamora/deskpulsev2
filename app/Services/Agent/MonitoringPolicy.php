<?php

namespace App\Services\Agent;

use App\Models\Organization;
use App\Support\PlanLimits;

/**
 * What the organization's admin has asked the agent to capture.
 *
 * Ports org_policy(). Served verbatim at `GET /webhooks/policy`, so the shape
 * and the key names are part of the frozen contract — the agent reads these
 * exact keys.
 *
 * ## A policy is a request, not a control
 *
 * Everything here is advice to a program running on someone else's machine. A
 * stale or modified agent can ignore all of it and post anyway, which is why
 * the screenshot endpoint refuses an upload on a plan without screenshots
 * rather than relying on `track_screenshots` being honoured.
 *
 * The plan limit can only ever turn capture OFF. An admin toggling
 * `track_screenshots` on does not buy the feature.
 *
 * @see docs/migration/api-contract.md §2
 * @see docs/migration/monitoring.md
 */
class MonitoringPolicy
{
    public function __construct(private readonly PlanLimits $planLimits) {}

    /** @return array<string, int|bool> */
    public function for(int $organizationId): array
    {
        $organization = Organization::query()
            ->whereKey($organizationId)
            ->first([
                'screenshot_interval_min', 'screenshot_blur', 'idle_threshold_min',
                'sync_interval_s', 'track_screenshots', 'track_windows', 'track_processes',
            ]);

        $limits = $this->planLimits->for($organizationId);

        return [
            'screenshot_interval_min' => (int) ($organization->screenshot_interval_min ?? 10),
            'screenshot_blur'         => (bool) ($organization->screenshot_blur ?? 0),
            'idle_threshold_min'      => (int) ($organization->idle_threshold_min ?? 15),
            'sync_interval_s'         => (int) ($organization->sync_interval_s ?? 60),

            // The plan overrides the admin toggle, one way only.
            'track_screenshots'       => (bool) ($organization->track_screenshots ?? 1) && $limits['screenshots'],
            'track_windows'           => (bool) ($organization->track_windows ?? 1),
            'track_processes'         => (bool) ($organization->track_processes ?? 1),
        ];
    }
}
