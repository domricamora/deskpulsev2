<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganizationThroughUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A work session — the time entry at the centre of the product.
 *
 * The table is `sessions`, but the model is WorkSession: Laravel's own `sessions`
 * concept is HTTP sessions, and conflating the two would be a trap. It has no
 * `org_id`; it reaches a tenant through `user_id`.
 *
 * Approval defaults by source, and this must not drift:
 *   agent  -> approved   (automatic tracking)
 *   manual -> pending    (until a manager with approve_time acts)
 *   import -> approved   (payroll import writes settled history)
 *
 * @see docs/migration/monitoring.md
 */
class WorkSession extends Model
{
    use BelongsToOrganizationThroughUser;

    protected $table = 'sessions';

    /** This table has no timestamp columns at all. */
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'started_at'           => 'datetime',
            'ended_at'             => 'datetime',
            'reviewed_at'          => 'datetime',
            'last_seen_at'         => 'datetime',
            'overtime_reviewed_at' => 'datetime',
            'overtime_computed'    => 'boolean',
            'active_s'             => 'integer',
            'inactive_s'           => 'integer',
            'overtime_s'           => 'integer',
        ];
    }

    /* ── Relationships ───────────────────────────────────────────────────── */

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }

    public function overtimeReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overtime_reviewed_by_id');
    }

    public function activitySamples(): HasMany
    {
        return $this->hasMany(ActivitySample::class, 'session_id');
    }

    public function windowEvents(): HasMany
    {
        return $this->hasMany(WindowEvent::class, 'session_id');
    }

    public function processSnapshots(): HasMany
    {
        return $this->hasMany(ProcessSnapshot::class, 'session_id');
    }

    public function idlePeriods(): HasMany
    {
        return $this->hasMany(IdlePeriod::class, 'session_id');
    }

    public function screenshots(): HasMany
    {
        return $this->hasMany(Screenshot::class, 'session_id');
    }

    /* ── Scopes ──────────────────────────────────────────────────────────── */

    /**
     * Only approved sessions.
     *
     * Every report and every invoice is built on this. It is why a pending manual
     * entry does not appear in a report or on a bill until a manager approves it.
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('approval_status', 'approved');
    }

    /** Sessions still open — the live view's entire input. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    public function scopeBetween(Builder $query, string $startUtc, string $endUtc): Builder
    {
        return $query->where('started_at', '>=', $startUtc)
            ->where('started_at', '<', $endUtc);
    }

    /* ── Derived values ──────────────────────────────────────────────────── */

    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * Seconds that may actually be paid.
     *
     * Unapproved overtime never pays. Mirrors creditable_active_s() in the legacy
     * reports.php, which is the only figure compute_pay_run() uses.
     *
     * Note billing deliberately differs: it charges the full active_s, including
     * unapproved overtime. The two disagreeing is correct — see
     * docs/migration/billing.md §3.
     */
    public function creditableActiveSeconds(): int
    {
        if (($this->overtime_status ?? 'none') === 'approved') {
            return (int) $this->active_s;
        }

        return max(0, (int) $this->active_s - (int) ($this->overtime_s ?? 0));
    }
}
