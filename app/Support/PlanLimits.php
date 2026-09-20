<?php

namespace App\Support;

use App\Models\Organization;

/**
 * What the free Solo plan actually restricts.
 *
 * Solo is advertised as "1 user, 7 days of history, no screenshots".
 * Advertising a limit that nothing enforces is a promise you cannot keep, so
 * the legacy code applies it in exactly three places and no others:
 *
 *   - the monitoring policy the agent is handed (Phase 7/9)
 *   - {@see Period::context()}, so every report, export and share link inherits
 *     one clamp rather than each re-deriving it and one of them forgetting
 *   - the team invite path, which refuses a second seat
 *
 * Everything else is unrestricted. Features are not gated by tier on the paid
 * plans, and nothing here should grow into that.
 *
 * @see docs/migration/billing.md
 */
class PlanLimits
{
    /** @var array<int, array{solo: bool, history_days: int, screenshots: bool, max_seats: int}> */
    private array $cache = [];

    /**
     * @return array{solo: bool, history_days: int, screenshots: bool, max_seats: int}
     */
    public function for(int $organizationId): array
    {
        if (isset($this->cache[$organizationId])) {
            return $this->cache[$organizationId];
        }

        $plan = Organization::query()
            ->whereKey($organizationId)
            ->value('plan_type');

        $solo = $plan === 'solo';

        // 0 means unlimited for both history and seats.
        return $this->cache[$organizationId] = [
            'solo'         => $solo,
            'history_days' => $solo ? 7 : 0,
            'screenshots'  => ! $solo,
            'max_seats'    => $solo ? 1 : 0,
        ];
    }

    /** Test seam: the legacy static cache lives for one request, this must too. */
    public function flush(): void
    {
        $this->cache = [];
    }
}
