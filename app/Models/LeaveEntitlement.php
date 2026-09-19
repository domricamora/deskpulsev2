<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @see docs/migration/database.md
 */
class LeaveEntitlement extends Model
{

    use Concerns\BelongsToOrganization;

    protected $table = 'leave_entitlements';

    /** This table has no timestamp columns at all. */
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'org_id' => 'integer',
            'user_id' => 'integer',
            'leave_type_id' => 'integer',
            'year' => 'integer',
            'days' => 'decimal:2',
            'carried_days' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }
}
