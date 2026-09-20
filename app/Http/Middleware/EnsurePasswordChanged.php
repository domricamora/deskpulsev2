<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate 2 — an account created with a temporary password is held at
 * /app/change-password until it sets its own.
 *
 * Client portal logins and super-admin-created accounts arrive this way. Only
 * the change-password page and /logout are exempt; the legacy check is a
 * substring match on the route path, kept so the exempt set cannot drift.
 *
 * Note this runs BEFORE the organization and paywall gates, matching the legacy
 * order: require_login() enforces it, and require_approved_org() runs after.
 *
 * @see docs/migration/authentication.md §2
 */
class EnsurePasswordChanged
{
    /** Substring matches, as in the legacy require_login(). */
    private const EXEMPT = ['/app/change-password', '/logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->must_change_password) {
            $path = '/' . ltrim($request->path(), '/');

            foreach (self::EXEMPT as $exempt) {
                if (str_contains($path, $exempt)) {
                    return $next($request);
                }
            }

            return redirect('/app/change-password');
        }

        return $next($request);
    }
}
