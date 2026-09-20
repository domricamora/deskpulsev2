<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\Capability;
use App\Http\Controllers\Controller;
use App\Models\ShareLink;
use App\Models\Team;
use App\Models\User;
use App\Services\Reporting\SessionStats;
use App\Services\Sharing\PersonalLinks;
use App\Support\Visibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * `/app/share-links` — the directory of public summary links.
 *
 * Capability `view_all`, so company admins, HR and IT see it and managers and
 * members do not. Everybody still HAS a personal link — one is minted for each
 * member automatically — this page is just where somebody with organization
 * scope can find them all.
 *
 * Links are grouped by team, with an `Unassigned` bucket for anybody on no
 * team, so a long roster stays navigable.
 *
 * @see docs/migration/sharing.md §2
 */
class ShareLinkController extends Controller
{
    public function __construct(
        private readonly PersonalLinks $links,
        private readonly SessionStats $stats,
    ) {}

    public function show(Request $request)
    {
        $user = $request->user();
        $organizationId = (int) $user->effectiveOrgId();

        // Idempotent: mints only for people who have no active personal link.
        $this->links->ensureFor($organizationId);

        $tokens = ShareLink::query()
            ->where('org_id', $organizationId)
            ->where('scope', 'user')
            ->where('revoked', 0)
            ->pluck('token', 'target_id');

        $usersById = $this->stats->usersById($organizationId);
        $allowed = Visibility::userIds($user);
        $allowedSet = array_flip($allowed);

        $row = fn (int $id) => isset($usersById[$id]) ? [
            'id'    => $id,
            'name'  => $usersById[$id]->name,
            'role'  => $usersById[$id]->role,
            'token' => $tokens[$id] ?? null,
        ] : null;

        $byTeam = [];
        $assigned = [];

        foreach (DB::table('team_members as tm')
            ->join('teams as t', 't.id', '=', 'tm.team_id')
            ->where('t.org_id', $organizationId)
            ->get(['tm.team_id', 'tm.user_id']) as $membership) {
            $id = (int) $membership->user_id;

            if (! isset($allowedSet[$id])) {
                continue;
            }

            // Keyed so somebody on two teams is not listed twice per team.
            $byTeam[(int) $membership->team_id][$id] = true;
            $assigned[$id] = true;
        }

        $groups = [];

        foreach (Team::query()->where('org_id', $organizationId)->orderBy('name')->get() as $team) {
            $members = array_values(array_filter(array_map($row, array_keys($byTeam[$team->id] ?? []))));

            usort($members, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

            if ($members) {
                $groups[] = ['name' => $team->name, 'members' => $members];
            }
        }

        $unassigned = array_values(array_filter(array_map(
            $row,
            array_values(array_filter($allowed, fn (int $id) => ! isset($assigned[$id])))
        )));

        usort($unassigned, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        if ($unassigned) {
            $groups[] = ['name' => 'Unassigned', 'members' => $unassigned];
        }

        return view('dashboard.share_links', [
            'title'  => 'Share links',
            'active' => 'share_links',
            'self'   => ['name' => $user->name, 'token' => $tokens[$user->id] ?? null],
            'groups' => $groups,
        ]);
    }
}
