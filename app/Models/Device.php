<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @see docs/migration/database.md
 */
class Device extends Model
{

    use Concerns\BelongsToOrganizationThroughUser;

    protected $table = 'devices';

    /** This table has created_at but no updated_at. */
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /** The secret is an HMAC key; it must never reach a response body. */
    protected $hidden = [
        'secret',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'created_at' => 'datetime',
            'last_seen' => 'datetime',
        ];
    }

    /**
     * The device's owner.
     *
     * `devices` has no `org_id`, so this relation is the ONLY route from a
     * signed agent request to a tenant. The legacy screenshot handler read
     * `$device['org_id']` directly, which is not a column — see
     * docs/migration/api-contract.md.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
