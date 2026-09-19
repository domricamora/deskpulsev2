<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @see docs/migration/database.md
 */
class PaymentClaim extends Model
{

    use Concerns\BelongsToOrganization;

    protected $table = 'payment_claims';

    /** This table has created_at but no updated_at. */
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'org_id' => 'integer',
            'submitted_by_id' => 'integer',
            'amount_cents' => 'integer',
            'paid_on' => 'date',
            'payment_id' => 'integer',
            'reviewed_by_id' => 'integer',
            'reviewed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
