<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;

/**
 * The platform organization and the platform-wide settings it carries.
 *
 * DeskPulse has no settings table. Every platform-wide value — prices, trial
 * length, the payments master switch, mail transport, payout credentials — lives
 * on the `organizations` row that the first super admin belongs to. Ports
 * platform_org_id(), platform_trial_days() and payments_enabled().
 *
 * Resolved once and memoised, matching the legacy per-request statics. Registered
 * as a container singleton so the memo dies with the request (and with the test).
 *
 * @see docs/migration/platform.md
 */
class Platform
{
    private ?int $organizationId = null;

    private ?bool $paymentsEnabled = null;

    private ?int $trialDays = null;

    /**
     * The platform organization's id, or 0 when no super admin exists yet.
     *
     * Ordered by id so a second super admin created later cannot move the
     * platform org and take every price with it.
     */
    public function organizationId(): int
    {
        return $this->organizationId ??= (int) User::query()
            ->where('role', 'super_admin')
            ->orderBy('id')
            ->value('org_id');
    }

    public function organization(): ?Organization
    {
        $id = $this->organizationId();

        return $id > 0 ? Organization::find($id) : null;
    }

    /**
     * Master switch: when false the trial and the paywall are inert and the app
     * behaves as it did before billing existed.
     *
     * The platform org's `pay_enabled` wins when it is set to anything at all —
     * including 0, which is how a super admin turns payments back OFF. Only a
     * NULL column falls through to config.
     */
    public function paymentsEnabled(): bool
    {
        if ($this->paymentsEnabled !== null) {
            return $this->paymentsEnabled;
        }

        $raw = $this->settingRaw('pay_enabled');

        return $this->paymentsEnabled = $raw === null
            ? (bool) config('deskpulse.payments.enabled', false)
            : (bool) (int) $raw;
    }

    /** Trial length in days: the platform org's value, else the config default. */
    public function trialDays(): int
    {
        if ($this->trialDays !== null) {
            return $this->trialDays;
        }

        $days = (int) $this->settingRaw('trial_days');

        return $this->trialDays = $days > 0
            ? $days
            : max(0, (int) config('deskpulse.payments.trial_days', 14));
    }

    /**
     * One platform setting, straight off the row and uncast.
     *
     * Deliberately not routed through the model: several of these columns are
     * three-state (null / 0 / 1) and a boolean cast would erase the difference
     * between "never configured" and "switched off".
     */
    public function settingRaw(string $column): mixed
    {
        $id = $this->organizationId();

        if ($id <= 0) {
            return null;
        }

        try {
            return Organization::query()->where('id', $id)->value($column);
        } catch (QueryException) {
            // A database that predates the column. The legacy wise_settings()
            // swallows this the same way so a half-migrated install still boots.
            return null;
        }
    }

    /** Drop the memo — for a test, or after a super admin saves platform settings. */
    public function flush(): void
    {
        $this->organizationId = null;
        $this->paymentsEnabled = null;
        $this->trialDays = null;
    }
}
