<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @see docs/migration/database.md
 */
class RemoteInputEvent extends Model
{

    use Concerns\BelongsToOrganizationThroughSession;

    protected $table = 'remote_input_events';

    /** This table has created_at but no updated_at. */
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'session_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
