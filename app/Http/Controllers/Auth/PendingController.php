<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Gate 3's destination — an organization awaiting review.
 *
 * Deliberately NOT behind the approved-organization or paywall middleware: it is
 * where those gates send people, and putting it behind them would loop. It is
 * still behind sign-in and the forced password change, exactly as the legacy
 * dash_pending() is.
 *
 * The page re-checks status on every load, so an approved tenant is let through
 * without having to sign in again.
 *
 * @see docs/migration/authentication.md §2
 */
class PendingController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();

        if ($user->isSuperAdmin()) {
            return redirect('/app/platform');
        }

        $org = $user->effectiveOrganization();

        if (($org?->status ?? 'approved') === 'approved') {
            return redirect('/app/overview');
        }

        return view('dashboard.pending', [
            'title' => 'Pending approval',
            'org'   => $org,
            'user'  => $user,
        ]);
    }
}
