<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Access;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Employee-only gate. Ports require_staff().
 *
 * A client portal login (`client_viewer`) is an outside customer, not an
 * employee: it has no leave, no payslip and no pay of any kind, so every
 * payroll-personal page turns it away even though those pages are otherwise
 * open to any signed-in user.
 *
 * Note a plain `member` IS staff and passes — it holds no capabilities at all,
 * which is why this gate cannot be expressed as one.
 *
 * @see docs/migration/authorization.md §3
 * @see docs/migration/payroll.md
 */
class RequireStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isStaff()) {
            return Access::deny();
        }

        return $next($request);
    }
}
