<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @see docs/migration/database.md
 */
class LeaveType extends Model
{

    use Concerns\BelongsToOrganization;

    protected $table = 'leave_types';

    /** This table has created_at but no updated_at. */
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'org_id' => 'integer',
            'paid' => 'boolean',
            'days_per_year' => 'decimal:2',
            'active' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
