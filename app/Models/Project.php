<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @see docs/migration/database.md
 */
class Project extends Model
{

    use Concerns\BelongsToOrganization;

    protected $table = 'projects';

    /** This table has created_at but no updated_at. */
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'org_id' => 'integer',
            'client_id' => 'integer',
            'billable' => 'boolean',
            'hourly_rate' => 'float',
            'archived' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }
}
