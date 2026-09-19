<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @see docs/migration/database.md
 */
class Team extends Model
{

    use Concerns\BelongsToOrganization;

    protected $table = 'teams';

    /** This table has created_at but no updated_at. */
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'org_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
