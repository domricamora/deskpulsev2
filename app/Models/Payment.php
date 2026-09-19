<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @see docs/migration/database.md
 */
class Payment extends Model
{

    use Concerns\BelongsToOrganization;

    protected $table = 'payments';

    /** This table has created_at but no updated_at. */
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'org_id' => 'integer',
            'invoice_id' => 'integer',
            'amount_cents' => 'integer',
            'occurred_at' => 'datetime',
            'matched' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
