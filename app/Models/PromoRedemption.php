<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @see docs/migration/database.md
 */
class PromoRedemption extends Model
{

    use Concerns\BelongsToOrganization;

    protected $table = 'promo_redemptions';

    /** This table has no timestamp columns at all. */
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'promo_id' => 'integer',
            'org_id' => 'integer',
            'applied_at' => 'datetime',
        ];
    }

    public function promo(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class, 'promo_id');
    }
}
