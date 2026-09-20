<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\User;

/**
 * The plan ladder and what each rung costs.
 *
 * One place, so billing, the pricing page, the public cost calculator and the
 * signup form can never disagree about a price. Ports plan_types(),
 * plan_type_clean(), plan_base_prices(), plan_base_price(), plan_seat_price(),
 * plan_cap_seats(), org_seat_count(), effective_monthly_fee() and
 * plan_signup_price().
 *
 * Phase 5 needs this because registration prices the plan it is signing someone
 * up to. The rest of billing lands in Phase 12.
 *
 * @see docs/migration/billing.md
 */
class Plans
{
    /** @var array<string, float|int>|null */
    private ?array $prices = null;

    public function __construct(private readonly Platform $platform) {}

    /** The plan_type values the billing engine understands. */
    public function types(): array
    {
        return ['solo', 'individual', 'per_seat', 'organization', 'enterprise'];
    }

    public function typeClean(?string $plan, string $fallback = 'organization'): string
    {
        return in_array((string) $plan, $this->types(), true) ? (string) $plan : $fallback;
    }

    /**
     * Platform-wide standard prices: the platform org's columns, else the
     * product defaults in config.
     *
     * `solo`, `seat_cap`, `seats_bill_min` and `seats_cap_covers` default to 0
     * meaning "inactive". seat_cap must NOT fall back to `organization` — an
     * existing per-seat tenant billing above that figure would be silently
     * re-priced the moment the column appeared.
     *
     * @return array<string, float|int>
     */
    public function basePrices(): array
    {
        if ($this->prices !== null) {
            return $this->prices;
        }

        $defaults = (array) config('deskpulse.plan_prices');
        $id = $this->platform->organizationId();

        if ($id <= 0) {
            return $this->prices = $defaults;
        }

        $row = Organization::query()
            ->where('id', $id)
            ->first([
                'price_individual', 'price_organization', 'price_per_seat',
                'seats_min', 'seats_max', 'price_solo', 'price_seat_cap',
                'seats_bill_min', 'seats_cap_covers',
            ]);

        $num = fn (string $c, $d) => isset($row?->$c) ? (float) $row->$c : (float) $d;
        $int = fn (string $c, $d) => isset($row?->$c) ? (int) $row->$c : (int) $d;

        return $this->prices = [
            'individual'       => $num('price_individual', $defaults['individual']),
            'organization'     => $num('price_organization', $defaults['organization']),
            'per_seat'         => $num('price_per_seat', $defaults['per_seat']),
            'seats_min'        => $int('seats_min', $defaults['seats_min']),
            'seats_max'        => $int('seats_max', $defaults['seats_max']),
            'solo'             => $num('price_solo', 0.0),
            'seat_cap'         => $num('price_seat_cap', 0.0),
            'seats_bill_min'   => $int('seats_bill_min', 0),
            'seats_cap_covers' => $int('seats_cap_covers', 0),
        ];
    }

    /** Standard (undiscounted) monthly price for a plan type. */
    public function basePrice(string $planType): float
    {
        $prices = $this->basePrices();

        return (float) ($prices[$planType] ?? $prices['organization']);
    }

    /**
     * What the Team plan costs at a given seat count: floor, multiply, cap.
     *
     * This is the whole "per-seat pricing that stops" mechanic. Pass a
     * hypothetical seat count to price a prospect.
     */
    public function seatPrice(int $seats, ?array $prices = null): float
    {
        $prices = $prices ?? $this->basePrices();
        $seats = max((int) $prices['seats_bill_min'], max(1, $seats));
        $total = (float) $prices['per_seat'] * $seats;
        $cap = (float) $prices['seat_cap'];

        return round($cap > 0 ? min($total, $cap) : $total, 2);
    }

    /** The seat count past which the Team bill stops growing. 0 = uncapped. */
    public function capSeats(?array $prices = null): int
    {
        $prices = $prices ?? $this->basePrices();
        $seat = (float) $prices['per_seat'];
        $cap = (float) $prices['seat_cap'];

        return ($seat > 0 && $cap > 0) ? (int) ceil($cap / $seat) : 0;
    }

    /**
     * Billable seats: staff logins in the org.
     *
     * Excludes the read-only client portal logins and the platform super admin.
     */
    public function seatCount(int $organizationId): int
    {
        return User::query()
            ->where('org_id', $organizationId)
            ->whereNotIn('role', ['client_viewer', 'super_admin'])
            ->count();
    }

    /**
     * Effective monthly fee for a tenant: the base price for its plan, less its
     * percentage discount.
     *
     * Accepts a plain array so a caller can price a hypothetical organization
     * that has no row yet — which is exactly what the signup form does.
     *
     * @param  array<string, mixed>  $org  plan_type, discount_pct, custom_fee, seats, id
     */
    public function effectiveMonthlyFee(array $org): float
    {
        $plan = $this->typeClean($org['plan_type'] ?? null);
        $prices = $this->basePrices();
        $discount = max(0.0, min(100.0, (float) ($org['discount_pct'] ?? 0)));

        if ($plan === 'solo') {
            $base = (float) $prices['solo'];
        } elseif ($plan === 'enterprise') {
            // Negotiated per tenant. discount_pct can only price DOWN, so an
            // Enterprise deal above the cap is unrepresentable without its own
            // column.
            $base = isset($org['custom_fee']) && $org['custom_fee'] !== null
                ? (float) $org['custom_fee']
                : (float) ($prices['seat_cap'] ?: $prices['organization']);
        } elseif ($plan === 'per_seat') {
            $seats = isset($org['seats'])
                ? (int) $org['seats']
                : (isset($org['id']) ? $this->seatCount((int) $org['id']) : 0);
            $base = $this->seatPrice($seats, $prices);
        } else {
            $base = (float) ($prices[$plan] ?? $prices['organization']);
        }

        // The discount applies to the CAPPED base: 10% off a 90-seat Team org is
        // 10% off $450, not 10% off an uncapped $630.
        return round($base * (1 - $discount / 100), 2);
    }

    /** The headline price a signup form shows for a plan, as a short string. */
    public function signupPrice(string $plan, ?array $prices = null): string
    {
        $prices = $prices ?? $this->basePrices();
        $fmt = fn (float $v) => '$' . (fmod($v, 1.0) === 0.0 ? number_format($v, 0) : number_format($v, 2));

        switch ($plan) {
            case 'solo':
                return 'Free forever';
            case 'individual':
                return $fmt((float) $prices['individual']) . '/month';
            case 'organization':
                return $fmt((float) $prices['organization']) . '/month';
            default:
                $min = max(1, (int) $prices['seats_bill_min']);

                return $fmt((float) $prices['per_seat']) . '/seat/month'
                    . ($min > 1 ? ' · from ' . $fmt($this->seatPrice($min, $prices)) : '');
        }
    }

    /** Drop the memoised prices — for a test, or after a super admin edits them. */
    public function flush(): void
    {
        $this->prices = null;
    }
}
