<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The tenant boundary. Everything else scopes to an organization, directly through
 * `org_id` or transitively through a user or a session.
 *
 * The row also carries the organization's monitoring policy, reporting clock, pay
 * cycle, branding, subscription state and — on the platform organization only —
 * the platform-wide settings such as prices, mail transport and payout credentials.
 *
 * @see docs/migration/database.md
 * @see docs/migration/monitoring.md for how the monitoring policy is applied
 */
class Organization extends Model
{
    protected $table = 'organizations';

    /** This table has created_at but no updated_at. */
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /**
     * Encrypted-at-rest credentials and SSO secrets never belong in a response or
     * a log. They are also in the platform SQL export as ciphertext by design.
     */
    protected $hidden = [
        'wise_api_token_enc',
        'wise_sca_private_enc',
        'sso_client_secret',
    ];

    protected function casts(): array
    {
        return [
            'created_at'          => 'datetime',
            'billing_started_at'  => 'datetime',
            'reviewed_at'         => 'datetime',
            'onboarded_at'        => 'datetime',
            'trial_ends_at'       => 'datetime',
            'current_period_end'  => 'datetime',
            'wise_last_sync_at'   => 'datetime',
            'wise_sca_created_at' => 'datetime',
            'pay_cycle_anchor'    => 'date',

            // Monitoring policy.
            'screenshot_blur'     => 'boolean',
            'track_screenshots'   => 'boolean',
            'track_windows'       => 'boolean',
            'track_processes'     => 'boolean',

            'mail_enabled'        => 'boolean',
            'pay_enabled'         => 'boolean',
            'sso_enabled'         => 'boolean',
            'sso_enforce'         => 'boolean',

            // Money stays as the legacy types (decision D1) — `double` here is not
            // an oversight, and converting it changes reported figures.
            'monthly_fee'         => 'float',
            'seat_rate'           => 'float',
            'price_per_seat'      => 'float',
            'price_solo'          => 'float',
            'price_seat_cap'      => 'float',
            'custom_fee'          => 'float',
            'discount_pct'        => 'decimal:2',
            'price_individual'    => 'decimal:2',
            'price_organization'  => 'decimal:2',
        ];
    }

    /* ── Members ─────────────────────────────────────────────────────────── */

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'org_id');
    }

    /**
     * Members of this organization, excluding platform operators.
     *
     * Mirrors the legacy org_user_ids(), which filters `role <> 'super_admin'`
     * because a super admin lives in the platform organization and is never one of
     * a tenant's members.
     */
    public function members(): HasMany
    {
        return $this->users()->where('role', '<>', 'super_admin');
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class, 'org_id');
    }

    /* ── Work ────────────────────────────────────────────────────────────── */

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'org_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'org_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'org_id');
    }

    public function shareLinks(): HasMany
    {
        return $this->hasMany(ShareLink::class, 'org_id');
    }

    /* ── Payroll and HR ──────────────────────────────────────────────────── */

    public function payAdjustments(): HasMany
    {
        return $this->hasMany(PayAdjustment::class, 'org_id');
    }

    public function leaveTypes(): HasMany
    {
        return $this->hasMany(LeaveType::class, 'org_id');
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class, 'org_id');
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class, 'org_id');
    }

    public function wiseAccounts(): HasMany
    {
        return $this->hasMany(WiseAccount::class, 'org_id');
    }

    /* ── Subscription ────────────────────────────────────────────────────── */

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'org_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'org_id');
    }

    public function paymentClaims(): HasMany
    {
        return $this->hasMany(PaymentClaim::class, 'org_id');
    }

    /* ── Messaging ───────────────────────────────────────────────────────── */

    public function notices(): HasMany
    {
        return $this->hasMany(Notice::class, 'org_id');
    }

    public function emailOutbox(): HasMany
    {
        return $this->hasMany(EmailOutbox::class, 'org_id');
    }

    /* ── Transitive: reached through this organization's users ───────────── */

    /**
     * Work sessions belonging to this organization.
     *
     * `sessions` has no `org_id`; it is reached through `user_id`. Expressed as a
     * has-many-through so it stays a single query.
     */
    public function sessions()
    {
        return $this->hasManyThrough(WorkSession::class, User::class, 'org_id', 'user_id');
    }

    public function devices()
    {
        return $this->hasManyThrough(Device::class, User::class, 'org_id', 'user_id');
    }

    /* ── Status ──────────────────────────────────────────────────────────── */

    public function isApproved(): bool
    {
        return ($this->status ?? 'approved') === 'approved';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
