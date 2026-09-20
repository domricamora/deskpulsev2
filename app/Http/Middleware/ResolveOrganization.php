<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Super-admin "act as": view the app as one tenant.
 *
 * Ports the act_org branch of current_user(). Two properties matter and are
 * tested:
 *
 *   • The organization id is NEVER read from the request. It comes from the
 *     session or from the user's own row.
 *   • The session value is validated against the database before use, so a
 *     forged act_org naming an organization that does not exist is ignored
 *     rather than producing an empty, confusing tenant.
 *
 * A non-super session that somehow carries act_org is ignored outright.
 *
 * @see docs/migration/authentication.md §1
 */
class ResolveOrganization
{
    public const SESSION_KEY = 'act_org';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->isSuperAdmin()) {
            $acting = (int) $request->session()->get(self::SESSION_KEY, 0);

            if ($acting > 0 && Organization::whereKey($acting)->exists()) {
                $user->actAs($acting);
            }
        }

        return $next($request);
    }
}
