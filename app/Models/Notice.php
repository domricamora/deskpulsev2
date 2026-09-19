<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @see docs/migration/database.md
 */
class Notice extends Model
{

    use Concerns\BelongsToOrganization;

    protected $table = 'notices';

    /** This table has created_at but no updated_at. */
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'org_id' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'emailed' => 'boolean',
            'created_by_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
