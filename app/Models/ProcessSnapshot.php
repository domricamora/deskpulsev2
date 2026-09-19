<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @see docs/migration/database.md
 */
class ProcessSnapshot extends Model
{

    use Concerns\BelongsToOrganizationThroughSession;

    protected $table = 'process_snapshots';

    /** This table has no timestamp columns at all. */
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'session_id' => 'integer',
            'ts' => 'datetime',
            'pid' => 'integer',
        ];
    }
}
