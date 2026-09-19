<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @see docs/migration/database.md
 */
class Client extends Model
{

    use Concerns\BelongsToOrganization;

    protected $table = 'clients';

    /** This table has created_at but no updated_at. */
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'org_id' => 'integer',
            'archived' => 'boolean',
            'created_at' => 'datetime',
            'user_id' => 'integer',
            'bill_rate' => 'float',
        ];
    }
}
