<?php

namespace App\Http\Middleware;

use App\Enums\Capability;
use App\Models\User;
use App\Support\Access;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Capability gate. Ports require_cap().
 *
 *     Route::get('/app/reports', …)->middleware('cap:reports');
 *
 * Always a capability, never a role name — the role → capability matrix is the
 * single source of truth and a role check would drift from it the first time a
 * grant changes. See docs/migration/authorization.md §3.
 *
 * Failure is a redirect to /app with a notice, not a 403. That is the legacy
 * deny_access() behaviour and it is what stale bookmarks and back buttons hit
 * after someone switches accounts.
 *
 * An unknown capability string throws rather than silently denying: a typo in a
 * route definition must fail loudly in development, not quietly lock a page.
 */
class RequireCapability
{
    public function handle(Request $request, Closure $next, string $capability): Response
    {
        $cap = Capability::tryFrom($capability);

        if ($cap === null) {
            throw new InvalidArgumentException("Unknown capability [{$capability}].");
        }

        $user = $request->user();

        if (! $user instanceof User || ! $user->hasCapability($cap)) {
            return Access::deny();
        }

        return $next($request);
    }
}
