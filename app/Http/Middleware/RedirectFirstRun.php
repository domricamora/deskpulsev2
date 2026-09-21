<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Support\RoleGuides;
use App\Support\Visibility;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * First run: send somebody to their setup wizard or role guide once.
 *
 * Ports the three redirects at the top of `nav_context()`. They lived inside
 * that function in the legacy because it is called by every dashboard handler;
 * here they are middleware, because {@see \App\View\Composers\NavigationComposer}
 * runs during RENDERING — by then the response exists and redirecting from it
 * is either ignored or an exception, depending on where in the render it fires.
 *
 * ## Three rules, three different "have they seen it" columns
 *
 * | Role | Sent to | Stops when |
 * |---|---|---|
 * | `client_admin` | `/app/onboarding` | `organizations.onboarded_at` is set |
 * | `manager` | `/app/onboarding` | `users.welcomed_at` is set, or they have a roster |
 * | member, HR, IT, client viewer | `/app/welcome` | `users.welcomed_at` is set |
 *
 * The company admin's rule reads an ORGANIZATION column and the other two read
 * a USER column, which is why finishing setup does not silence the guide for a
 * second admin who joins later.
 *
 * ## The exempt paths are what stop a redirect loop
 *
 * The destination itself is always exempt, obviously. `/app/subscription` is
 * exempt for all three because a lapsed tenant is sent THERE by
 * {@see EnsureSubscriptionCurrent}, and a first-run redirect would bounce them
 * straight back out of the only page that can fix the lapse. The welcome rule
 * also exempts `/app/change-password`, because a forced rotation must complete
 * before anything else — including a tour.
 *
 * Nothing here is access control. Every destination enforces its own guard.
 *
 * @see docs/migration/routes.md §5
 */
class RedirectFirstRun
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Only ever redirect a plain page view. A POST bounced to a wizard
        // would silently discard whatever was being saved, and a redirected
        // fetch() answers the poller with HTML it cannot parse.
        if (! $user || ! $request->isMethod('GET') || $request->expectsJson()) {
            return $next($request);
        }

        $destination = $this->destinationFor($request, $user);

        return $destination ? redirect($destination) : $next($request);
    }

    private function destinationFor(Request $request, $user): ?string
    {
        // A platform operator has no tenant first run of their own. While
        // acting as one they are looking at somebody else's workspace, and
        // being dragged into that tenant's wizard is not what act-as is for.
        if ($user->isSuperAdmin()) {
            return null;
        }

        $path = '/' . ltrim($request->path(), '/');

        if ($user->role === UserRole::ClientAdmin) {
            if ($this->on($path, ['/app/onboarding', '/app/subscription'])) {
                return null;
            }

            // Setup is optional — an empty roster is not a reason to drag the
            // admin back. Only "never finished or skipped it" counts.
            $onboarded = Organization::query()
                ->whereKey($user->effectiveOrgId())
                ->value('onboarded_at');

            return $onboarded ? null : '/app/onboarding';
        }

        if ($user->role === UserRole::Manager) {
            if ($user->welcomed_at !== null || $this->on($path, ['/app/onboarding', '/app/subscription'])) {
                return null;
            }

            // A manager who already has a roster has effectively done the
            // wizard, whether or not they pressed its button.
            return $this->hasRoster($user) ? null : '/app/onboarding';
        }

        if (! RoleGuides::isFirstRunRole($user->role)) {
            return null;
        }

        if ($user->welcomed_at !== null
            || $this->on($path, ['/app/welcome', '/app/change-password', '/app/subscription'])) {
            return null;
        }

        return '/app/welcome';
    }

    /** Ports manager_roster_ids(): their team, minus themselves. */
    private function hasRoster($user): bool
    {
        $ids = array_filter(
            Visibility::teamMemberIds((int) $user->id),
            fn (int $id) => $id > 0 && $id !== (int) $user->id
        );

        return $ids !== [];
    }

    /** @param list<string> $paths */
    private function on(string $path, array $paths): bool
    {
        foreach ($paths as $candidate) {
            if (str_starts_with($path, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
