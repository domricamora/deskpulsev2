<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @see docs/migration/database.md
 */
class IdlePeriod extends Model
{

    use Concerns\BelongsToOrganizationThroughSession;

    protected $table = 'idle_periods';

    /** This table has no timestamp columns at all. */
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'session_id' => 'integer',
            'start_ts' => 'datetime',
            'end_ts' => 'datetime',
            'duration_s' => 'integer',
        ];
    }
}
