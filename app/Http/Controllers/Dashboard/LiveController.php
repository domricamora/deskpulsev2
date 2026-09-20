<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\Capability;
use App\Http\Controllers\Controller;
use App\Services\Agent\SessionIngest;
use App\Services\Reporting\LiveBoard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/app/live` — everyone tracking right now.
 *
 * Ports dash_live() and dash_live_data(). Two routes, both behind the `live`
 * capability: the page shell, and the JSON its script polls every 15 seconds.
 *
 * The endpoint keeps its existing path. `/api/v1/live` is what the migration
 * plan §51 proposes and what the replica constraint rules out — `live.js`
 * targets `/app/live/data`, and so would anything anyone has bookmarked or
 * scripted against it.
 *
 * @see docs/migration/live-monitoring.md
 */
class LiveController extends Controller
{
    public function __construct(
        private readonly LiveBoard $board,
        private readonly SessionIngest $sessions,
    ) {}

    /** The shell. Everything on it arrives from the poll. */
    public function index()
    {
        return view('dashboard.live', [
            'title'  => 'Live',
            'active' => 'live',
        ]);
    }

    /**
     * `GET /app/live/data` — the polled snapshot.
     *
     * This endpoint WRITES before it reads. Closing stale sessions on every
     * poll is what stops a crashed agent showing as live forever, and there is
     * no cron doing it instead: the live view and the dashboard render are the
     * only two things that ever call it. Dropping the call here would leave the
     * board showing people who went offline hours ago, and their sessions open
     * while it does. See docs/migration/monitoring.md §3.
     */
    public function data(Request $request): JsonResponse
    {
        $this->sessions->closeStale();

        $user = $request->user();

        return response()->json($this->board->forViewer(
            $user,
            // A role with `live` but not `screenshots` gets cards with no
            // imagery. No role currently holds that combination, so this is a
            // guard rather than a live code path — but the capability split is
            // the product's, not this port's, to change.
            $user->hasCapability(Capability::Screenshots)
        ));
    }
}
