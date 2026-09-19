<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @see docs/migration/database.md
 */
class RemoteSession extends Model
{

    use Concerns\BelongsToOrganization;

    protected $table = 'remote_sessions';

    /** This table has no timestamp columns at all. */
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'device_id' => 'integer',
            'user_id' => 'integer',
            'admin_user_id' => 'integer',
            'org_id' => 'integer',
            'screen_w' => 'integer',
            'screen_h' => 'integer',
            'frame_seq' => 'integer',
            'last_frame_at' => 'datetime',
            'last_input_at' => 'datetime',
            'started_at' => 'datetime',
            'activated_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }
}
