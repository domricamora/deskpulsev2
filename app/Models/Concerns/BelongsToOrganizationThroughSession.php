<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Models\WorkSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For the 6 monitoring tables that hang off `session_id`: activity samples, window
 * events, process snapshots, idle periods, screenshots and remote input events.
 *
 * These are two hops from a tenant (session -> user -> organization) and hold the
 * most sensitive data in the product, so they are exactly the tables the mandatory
 * isolation test must cover. A test that only checks `org_id` tables proves nothing
 * about these.
 */
trait BelongsToOrganizationThroughSession
{
    use ScopesTenancy;

    public function session(): BelongsTo
    {
        return $this->belongsTo(WorkSession::class, 'session_id');
    }

    public function scopeForOrganization(Builder $query, Organization|int $organization): Builder
    {
        $organizationId = static::organizationId($organization);

        return $query->whereIn($this->qualifyColumn('session_id'), function ($sub) use ($organizationId) {
            $sub->select('id')
                ->from('sessions')
                ->whereIn('user_id', static::organizationUserIds($organizationId));
        });
    }
}
