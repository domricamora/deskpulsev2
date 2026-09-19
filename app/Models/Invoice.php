<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @see docs/migration/database.md
 */
class Invoice extends Model
{

    use Concerns\BelongsToOrganization;

    protected $table = 'invoices';

    /** This table has no timestamp columns at all. */
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'org_id' => 'integer',
            'amount_cents' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'issued_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }
}
