<?php

namespace App\Support;

use App\Models\Organization;

/**
 * Whether an organization is currently entitled to use the product, and how a
 * new one gets its trial.
 *
 * Ports sub_is_current(), start_trial() and ensure_pay_reference(). Gate 4 of
 * authentication — the paywall — is nothing more than isCurrent() returning
 * false while payments are switched on.
 *
 * @see docs/migration/authentication.md §2
 * @see docs/migration/payments.md
 */
class Subscription
{
    public function __construct(
        private readonly Platform $platform,
        private readonly Plans $plans,
    ) {}

    /**
     * Is this organization's subscription current?
     *
     * `billing_status = active` is a manual comp set by a super admin and beats
     * everything else — it is how an unpaid partner, a pilot or a refunded
     * account keeps working without a fake trial date.
     *
     * A missing period end on an active subscription means "no end recorded",
     * which counts as current; the legacy `empty()` check is preserved.
     */
    public function isCurrent(Organization $org): bool
    {
        if (($org->billing_status ?? '') === 'active') {
            return true;
        }

        $status = $org->subscription_status ?? 'none';

        if ($status === 'trialing') {
            return $org->trial_ends_at !== null && $org->trial_ends_at->isFuture();
        }

        if ($status === 'active') {
            return $org->current_period_end === null || $org->current_period_end->isFuture();
        }

        // none | past_due | canceled
        return false;
    }

    /**
     * Put a new organization on its plan, and on a trial when payments are on.
     *
     * With payments off, only the plan and fee are recorded: there is no trial
     * clock to start, and writing `trialing` would arm a paywall that is not
     * running.
     */
    public function startTrial(Organization $org, string $planType): void
    {
        $plan = in_array($planType, ['solo', 'individual', 'per_seat', 'organization'], true)
            ? $planType
            : 'organization';

        $fee = $this->plans->effectiveMonthlyFee(
            array_merge($org->getAttributes(), ['plan_type' => $plan])
        );

        if ($this->platform->paymentsEnabled()) {
            $org->forceFill([
                'plan_type'           => $plan,
                'monthly_fee'         => $fee,
                'subscription_status' => 'trialing',
                'trial_ends_at'       => now()->addDays($this->platform->trialDays()),
            ])->save();
        } else {
            $org->forceFill(['plan_type' => $plan, 'monthly_fee' => $fee])->save();
        }

        $this->ensurePayReference($org);
    }

    /**
     * The reference a customer quotes on a bank transfer.
     *
     * Minted once and never changed — it is what reconciles an incoming payment
     * to a tenant, so a regenerated reference would orphan a transfer already in
     * flight.
     */
    public function ensurePayReference(Organization $org): string
    {
        if (! empty($org->pay_reference)) {
            return (string) $org->pay_reference;
        }

        $ref = 'DP-' . (int) $org->id . '-' . strtoupper(bin2hex(random_bytes(3)));
        $org->forceFill(['pay_reference' => $ref])->save();

        return $ref;
    }
}
