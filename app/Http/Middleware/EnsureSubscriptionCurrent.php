<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Platform;
use App\Support\Subscription;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate 4 — the paywall.
 *
 * Once the free trial ends with no active subscription and no super-admin comp,
 * the organization is held at the subscription page.
 *
 * ── The exemption list is the important part ─────────────────────────────────
 * Paying, and leaving, and THE AGENT all have to keep working for a lapsed org:
 *
 *   /app/subscription      or the tenant cannot pay, and the paywall is a trap
 *   /app/change-password   gate 2 would otherwise deadlock against this one
 *   /app/profile           so someone can still reach their own account
 *   /logout                so they can leave
 *   /webhooks              THE DESKTOP AGENT
 *
 * `/webhooks/*` is the one that must never be lost. Putting the agent API behind
 * the paywall silently stops tracking for a lapsed organization: the agents keep
 * running, keep retrying, and every hour of work is thrown away. It is listed
 * here — and tested — even though the agent routes are registered outside this
 * middleware group, so the property survives someone later applying the gate
 * globally.
 *
 * A super admin is exempt by way of gate 3's ordering: they never reach this
 * middleware with a tenant's lapsed subscription unless they are acting as that
 * tenant, in which case seeing its paywall is correct.
 *
 * @see docs/migration/authentication.md §2
 * @see docs/migration/api-contract.md
 */
class EnsureSubscriptionCurrent
{
    /** Prefix matches, as in the legacy require_approved_org(). */
    private const EXEMPT = [
        '/app/subscription',
        '/app/change-password',
        '/app/profile',
        '/logout',
        '/webhooks',
    ];

    public function __construct(
        private readonly Platform $platform,
        private readonly Subscription $subscription,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->isSuperAdmin()) {
            return $next($request);
        }

        if (! $this->platform->paymentsEnabled()) {
            return $next($request);
        }

        $org = $user->effectiveOrganization();

        if ($org === null || $this->subscription->isCurrent($org)) {
            return $next($request);
        }

        if (self::isExempt('/' . ltrim($request->path(), '/'))) {
            return $next($request);
        }

        return redirect('/app/subscription');
    }

    /** Public so the exemption can be asserted directly, without a routed request. */
    public static function isExempt(string $path): bool
    {
        foreach (self::EXEMPT as $exempt) {
            if (str_starts_with($path, $exempt)) {
                return true;
            }
        }

        return false;
    }
}
