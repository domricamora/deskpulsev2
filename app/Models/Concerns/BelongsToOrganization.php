<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For the 20 tables that carry `org_id` directly.
 *
 * Note the column is `org_id`, not Laravel's conventional `organization_id` —
 * the legacy name is preserved because the schema is not being rewritten.
 */
trait BelongsToOrganization
{
    use ScopesTenancy;

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function scopeForOrganization(Builder $query, Organization|int $organization): Builder
    {
        return $query->where($this->qualifyColumn('org_id'), static::organizationId($organization));
    }
}
