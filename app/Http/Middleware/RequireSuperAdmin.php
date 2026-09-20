<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Access;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform console gate. Ports require_super().
 *
 * The one place a role name is checked rather than a capability: `super_admin`
 * is not a role in the matrix, it is the wildcard that holds every capability,
 * so "has the platform capability" and "is a platform operator" are the same
 * statement and the role is the clearer one.
 *
 * @see docs/migration/authorization.md §3
 */
class RequireSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isSuperAdmin()) {
            return Access::deny();
        }

        return $next($request);
    }
}
