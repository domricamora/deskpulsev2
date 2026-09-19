<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For the 7 tables that reach a tenant through `user_id` — including `sessions`,
 * which is the busiest table in the product and has no `org_id` of its own.
 */
trait BelongsToOrganizationThroughUser
{
    use ScopesTenancy;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeForOrganization(Builder $query, Organization|int $organization): Builder
    {
        return $query->whereIn(
            $this->qualifyColumn('user_id'),
            static::organizationUserIds(static::organizationId($organization))
        );
    }
}
