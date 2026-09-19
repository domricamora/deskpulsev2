<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @see docs/migration/database.md
 */
class PayAdjustment extends Model
{

    use Concerns\BelongsToOrganization;

    protected $table = 'pay_adjustments';

    /** This table has created_at but no updated_at. */
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'org_id' => 'integer',
            'user_id' => 'integer',
            'amount' => 'decimal:2',
            'taxable' => 'boolean',
            'effective_date' => 'date',
            'created_by_id' => 'integer',
            'reviewed_by_id' => 'integer',
            'reviewed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
