<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Support\RoleGuides;
use Illuminate\Http\Request;

/**
 * `/app/welcome` — the first-run role guide.
 *
 * A stepped tour whose position lives entirely in `?step=N`, so there is no
 * server-side wizard state to get out of sync with the URL. Only the final
 * step writes anything, and it writes one column: `welcomed_at`.
 *
 * ## Why `welcomed_at` is stamped only when it is null
 *
 * The guide is reopenable from the sidebar forever. Re-stamping on every visit
 * would turn "when did this person first sign in" into "when did they last
 * read the guide", and the first-run redirect reads that column.
 *
 * A super admin who is not acting as an organization is sent to the platform
 * console instead: there is no tenant whose guide they would be reading.
 *
 * @see docs/migration/ui-inventory.md §7
 */
class WelcomeController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();

        if ($user->isSuperAdmin() && ! $user->isActingAsOrganization()) {
            return redirect('/app/platform');
        }

        $guide = RoleGuides::for($user->role);
        $cards = $guide['cards'];

        // Clamped into [0, count] — one past the last card IS the done step.
        $step = $request->has('step')
            ? max(0, min((int) $request->input('step'), count($cards)))
            : 0;

        return view('dashboard.welcome', [
            'title'      => 'Getting started',
            'active'     => 'welcome',
            'me'         => $user,
            'guide'      => $guide,
            'cards'      => $cards,
            'step'       => $step,
            'isDone'     => $step >= count($cards),
            'isFirstRun' => RoleGuides::isFirstRunRole($user->role) && $user->welcomed_at === null,
        ]);
    }

    public function dismiss(Request $request)
    {
        $user = $request->user();

        if ($request->input('action') === 'dismiss' && $user->welcomed_at === null) {
            $user->forceFill(['welcomed_at' => now()])->save();
        }

        // The destination travels in the form, because "where was I going" is
        // the caller's business and the guide is reachable from anywhere.
        $to = (string) $request->input('to', '/app/overview');

        return redirect(str_starts_with($to, '/') ? $to : '/app/overview');
    }
}
