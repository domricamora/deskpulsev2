<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\Capability;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Agent\ScreenshotIngest;
use App\Support\Period;
use App\Support\Visibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * `/app/screenshots` — the monitoring gallery, and the images themselves.
 *
 * Ports dash_screenshots(). Two routes with deliberately different gates:
 *
 * - **The page** needs the `screenshots` capability, as the legacy
 *   `require_cap('screenshots')` does. An HR manager is refused; they see time
 *   and money, never monitoring imagery.
 * - **An image** needs only that the viewer can see the person in it. It must
 *   NOT require the capability, because `/app/overview` shows recent
 *   screenshots to everyone — including a member, who holds no capabilities at
 *   all, and an HR manager, who is refused the gallery. That is what the legacy
 *   overview does (it loads `$recentShots` with no capability check), and
 *   requiring the capability here would blank those panels.
 *
 * Visibility is the real boundary in both cases: `Visibility::userIds()` is the
 * single scope rule, so a manager sees their team, a client portal sees the
 * agents on its own engagement, and a member sees themselves.
 *
 * @see docs/migration/screenshots.md
 */
class ScreenshotController extends Controller
{
    public function __construct(private readonly Period $period) {}

    public function index(Request $request)
    {
        $user = $request->user();

        // The FULL visible scope. It stays unnarrowed on purpose: the member
        // dropdown is built from it, and narrowing first used to leave the
        // filtered person as the only selectable option, so switching people
        // meant hitting Clear first.
        $ids = Visibility::userIds($user);

        $filterUser = (int) $request->query('user_id', 0);
        $filterDate = (string) $request->query('date', '');

        $query = DB::table('screenshots as sc')
            ->join('sessions as s', 's.id', '=', 'sc.session_id')
            ->join('users as us', 'us.id', '=', 's.user_id')
            ->whereIn('s.user_id', $filterUser && in_array($filterUser, $ids, true) ? [$filterUser] : $ids);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) {
            // A calendar day in the ORGANIZATION's reporting timezone, turned
            // into the UTC instants actually stored in screenshots.ts. Cutting
            // it in UTC or in the server's timezone moves shots between days.
            $timezone = $this->period->timezone((int) $user->effectiveOrgId());

            $query->where('sc.ts', '>=', Period::localToUtc($filterDate . ' 00:00:00', $timezone))
                ->where('sc.ts', '<', Period::localToUtc(Period::addDays($filterDate, 1) . ' 00:00:00', $timezone));
        }

        $isManager = $user->hasCapability(Capability::ViewAll)
            || $user->hasCapability(Capability::ViewTeam);

        return view('dashboard.screenshots', [
            'title'      => 'Screenshots',
            'active'     => 'screenshots',
            'shots'      => $query->orderByDesc('sc.ts')->limit(200)
                ->get(['sc.*', 's.user_id', 'us.name as user_name']),
            // Only the people this viewer may actually see — not the whole
            // organization. It matters for a client portal login, which holds
            // view_team and so counts as a manager here.
            'members'    => $isManager
                ? User::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])
                : collect(),
            'isManager'  => $isManager,
            'filterUser' => $filterUser,
            'filterDate' => $filterDate,
        ]);
    }

    /**
     * Stream one screenshot from the private disk.
     *
     * 404 — never 403 — on a screenshot belonging to someone outside the
     * viewer's scope. A 403 would confirm that the id exists, which is exactly
     * what the old public URLs leaked.
     */
    public function image(Request $request, int $id): StreamedResponse
    {
        $shot = DB::table('screenshots as sc')
            ->join('sessions as s', 's.id', '=', 'sc.session_id')
            ->where('sc.id', $id)
            ->first(['sc.file_path', 's.user_id']);

        abort_if($shot === null, 404);
        abort_unless(
            in_array((int) $shot->user_id, Visibility::userIds($request->user()), true),
            404
        );

        $path = ScreenshotIngest::diskPath($shot->file_path);
        $disk = ScreenshotIngest::disk();

        abort_unless($disk->exists($path), 404);

        return $disk->response($path, basename($shot->file_path), [
            // Private, because the response is authorized for this viewer and
            // this viewer only. A shared cache holding it would recreate the
            // hole this route exists to close.
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
