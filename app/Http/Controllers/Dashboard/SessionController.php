<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSession;
use App\Support\Flash;
use App\Support\Visibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * `/app/session/{id}` — one session, in full.
 *
 * Login only, with ownership checked in the handler rather than at the route:
 * the rule is "anyone whose scope includes the person this session belongs to",
 * which no capability expresses.
 *
 * A session that is missing or out of scope **redirects to Timesheets with a
 * flash**, not a 403 or a 404 page. That is deliberate in the legacy and worth
 * keeping: the usual way to land here is a stale link after switching accounts
 * or organizations, and an error page for that is a dead end.
 *
 * @see docs/migration/reports.md
 */
class SessionController extends Controller
{
    public function show(Request $request, int $id)
    {
        $session = WorkSession::query()->whereKey($id)->first();

        if (! $session || ! in_array((int) $session->user_id, Visibility::userIds($request->user()), true)) {
            Flash::error('That session is not available.');

            return redirect('/app/timesheets');
        }

        $screenshots = DB::table('screenshots')
            ->where('session_id', $session->id)
            ->orderBy('ts')
            ->get(['id', 'ts', 'file_path', 'monitor']);

        return view('dashboard.session', [
            'title'   => 'Session detail',
            'active'  => 'timesheets',
            'session' => $session,
            'owner'   => User::query()->whereKey($session->user_id)->value('name'),
            'client'  => $session->client_id
                ? Client::query()->whereKey($session->client_id)->value('name')
                : null,
            'task'    => $session->task_id
                ? Task::query()->whereKey($session->task_id)->value('title')
                : null,
            'samples' => DB::table('activity_samples')
                ->where('session_id', $session->id)
                ->orderBy('ts')
                ->get(['ts', 'activity_pct']),
            // Grouped for the summary, and raw for the timeline below it —
            // the same rows read two ways, as the legacy does.
            'windowsAgg' => DB::table('window_events')
                ->selectRaw('app_name, window_title, SUM(focus_seconds) AS secs')
                ->where('session_id', $session->id)
                ->groupBy('app_name', 'window_title')
                ->orderByDesc('secs')
                ->get(),
            'windowTimeline' => DB::table('window_events')
                ->where('session_id', $session->id)
                ->orderBy('ts')
                ->get(['ts', 'app_name', 'window_title']),
            'processes' => DB::table('process_snapshots')
                ->where('session_id', $session->id)
                ->distinct()
                ->orderBy('app_name')
                ->pluck('app_name'),
            'idlePeriods' => DB::table('idle_periods')
                ->where('session_id', $session->id)
                ->orderBy('start_ts')
                ->get(['start_ts', 'end_ts', 'duration_s']),
            'screenshots' => $screenshots,
        ]);
    }
}
