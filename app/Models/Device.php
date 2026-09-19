<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'created_at' => 'datetime',
            'last_seen' => 'datetime',
        ];
    }
}
