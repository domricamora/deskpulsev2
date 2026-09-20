<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate 3 — members of an organization that has not been approved are held at
 * /app/pending.
 *
 * A super admin is exempt: they are the person who does the approving, and
 * holding them at the gate would make the platform console unreachable.
 *
 * An organization row with no status at all counts as approved, matching the
 * legacy `($org['status'] ?? 'approved')`. That default is load-bearing for
 * tenants created before the column existed.
 *
 * @see docs/migration/authentication.md §2
 */
class EnsureOrganizationApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->isSuperAdmin()) {
            return $next($request);
        }

        $org = $user->effectiveOrganization();

        if (($org?->status ?? 'approved') !== 'approved') {
            return redirect('/app/pending');
        }

        return $next($request);
    }
}
