<?php

namespace App\Models;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A DeskPulse account — employee, manager, admin, or a read-only client portal
 * login.
 *
 * Carries two rate pairs that must never be confused:
 *   pay_type / pay_rate    internal labor cost  (capability view_rates)
 *   bill_type / bill_rate  what a client is charged (capability billing)
 *
 * @see docs/migration/authorization.md
 * @see docs/migration/billing.md
 */
class User extends Authenticatable
{
    use BelongsToOrganization;

    protected $table = 'users';

    /** This table has created_at but no updated_at. */
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $hidden = [
        'password_hash',
        'remember_token',
    ];

    /** The legacy column is `password_hash`, not Laravel's `password`. */
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    protected function casts(): array
    {
        return [
            'role'                 => UserRole::class,
            'created_at'           => 'datetime',
            'welcomed_at'          => 'datetime',
            'hired_on'             => 'date',
            'must_change_password' => 'boolean',
            'email_opt_out'        => 'boolean',

            // Money keeps its legacy type (decision D1).
            'pay_rate'             => 'float',
            'bill_rate'            => 'float',

            // Hour caps are advisory — over-cap hours still track and still pay.
            'daily_hours_cap'      => 'decimal:2',
            'weekly_hours_cap'     => 'decimal:2',
            'period_hours_cap'     => 'decimal:2',
        ];
    }

    /* ── Authorization ───────────────────────────────────────────────────── */

    /**
     * Capability check. A super admin holds everything, matching the legacy
     * wildcard '*'.
     */
    public function hasCapability(Capability $capability): bool
    {
        return $this->role instanceof UserRole && $this->role->can($capability);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SuperAdmin;
    }

    /** A client portal login is a customer, not an employee. */
    public function isStaff(): bool
    {
        return $this->role instanceof UserRole && $this->role->isStaff();
    }

    /* ── Relationships ───────────────────────────────────────────────────── */

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_members', 'user_id', 'team_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(WorkSession::class, 'user_id');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class, 'user_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'user_id');
    }

    /** Clients this agent is assigned to. */
    public function assignedClients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'agent_clients', 'agent_id', 'client_id');
    }

    public function contracts(): BelongsToMany
    {
        return $this->belongsToMany(Contract::class, 'contract_members', 'user_id', 'contract_id');
    }

    public function identities(): HasMany
    {
        return $this->hasMany(UserIdentity::class, 'user_id');
    }

    public function payAdjustments(): HasMany
    {
        return $this->hasMany(PayAdjustment::class, 'user_id');
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class, 'user_id');
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class, 'user_id');
    }

    public function wiseAccount(): HasMany
    {
        return $this->hasMany(WiseAccount::class, 'user_id');
    }

    /** The client record this portal login belongs to, if any. */
    public function portalClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'id', 'user_id');
    }

    /* ── Scopes ──────────────────────────────────────────────────────────── */

    /**
     * Members of a tenant, excluding platform operators — the model-level
     * equivalent of the legacy org_user_ids().
     */
    public function scopeMembers(Builder $query): Builder
    {
        return $query->where('role', '<>', UserRole::SuperAdmin->value);
    }
}
