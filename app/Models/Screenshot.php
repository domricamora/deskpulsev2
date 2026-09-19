<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @see docs/migration/database.md
 */
class Screenshot extends Model
{

    use Concerns\BelongsToOrganizationThroughSession;

    protected $table = 'screenshots';

    /** This table has no timestamp columns at all. */
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'session_id' => 'integer',
            'ts' => 'datetime',
            'blurred' => 'boolean',
            'monitor' => 'integer',
        ];
    }
}
