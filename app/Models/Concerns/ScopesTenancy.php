<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared helpers for the three tenancy paths.
 *
 * 17 of the 37 tables have no `org_id` at all — including `sessions`, `screenshots`
 * and every monitoring table — so a single global scope on an `organization_id`
 * column cannot work. Tenancy is reached transitively, exactly as the legacy
 * application does it with org_user_ids() / visible_user_ids().
 *
 * Decision D6: keep it transitive. No column is added and no backfill is needed.
 * Denormalising `org_id` onto the child tables remains available later as a
 * performance change, deliberately taken.
 *
 * See docs/migration/database.md §2.
 */
trait ScopesTenancy
{
    protected static function organizationId(Organization|int $organization): int
    {
        return $organization instanceof Organization ? (int) $organization->id : (int) $organization;
    }

    /**
     * The user ids belonging to an organization.
     *
     * Mirrors the legacy org_user_ids(), including its exclusion of platform
     * operators: a super admin lives in the DeskPulse platform organization and is
     * never counted as one of a tenant's members.
     *
     * Returned as a sub-select rather than a fetched array so the whole thing stays
     * one query.
     */
    protected static function organizationUserIds(int $organizationId): \Closure
    {
        return function ($query) use ($organizationId) {
            $query->select('id')
                ->from('users')
                ->where('org_id', $organizationId)
                ->where('role', '<>', 'super_admin');
        };
    }
}
