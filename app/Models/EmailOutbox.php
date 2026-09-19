<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @see docs/migration/database.md
 */
class EmailOutbox extends Model
{

    use Concerns\BelongsToOrganization;

    protected $table = 'email_outbox';

    /** This table has created_at but no updated_at. */
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'org_id' => 'integer',
            'user_id' => 'integer',
            'attempts' => 'integer',
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'created_by_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
