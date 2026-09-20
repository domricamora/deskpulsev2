<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveOrganization;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Super-admin act-as: start viewing a tenant, and stop.
 *
 * The other half of ResolveOrganization. Ports dash_platform_act() and
 * dash_platform_return(), on their existing URLs — the platform console links
 * to `/app/platform/act/{id}` from four different pages and those links must
 * keep working.
 *
 * The rest of the platform console arrives in Phase 17. These two live here
 * because they are authentication, not administration: all they do is set and
 * clear a session key that decides which tenant the request is scoped to.
 *
 * @see docs/migration/authentication.md §1, §8
 * @see docs/migration/platform.md
 */
class ActAsController extends Controller
{
    /**
     * GET /app/platform/act/{id}
     *
     * The organization is validated before the session is written, so a session
     * can never carry an id that does not resolve. ResolveOrganization checks
     * again on every request, because a tenant can be deleted while a platform
     * operator is inside it.
     *
     * An unknown id is ignored rather than refused — the legacy handler simply
     * falls through to the dashboard, and a super admin following a stale link
     * from a deleted tenant should land somewhere useful.
     */
    public function store(Request $request, int $id): RedirectResponse
    {
        if (Organization::whereKey($id)->exists()) {
            $request->session()->put(ResolveOrganization::SESSION_KEY, $id);
        }

        return redirect('/app/overview');
    }

    /** GET /app/platform/return — back to the platform console. */
    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->forget(ResolveOrganization::SESSION_KEY);

        return redirect('/app/platform');
    }
}
