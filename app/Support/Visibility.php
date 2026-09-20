<?php

namespace App\Support;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use App\Models\WorkSession;
use Illuminate\Support\Facades\DB;

/**
 * Which people's data a viewer may see.
 *
 * This is the second layer of authorization described in
 * docs/migration/authorization.md §2: the capability decides whether a page
 * opens at all, and this decides whose rows appear on it. `/app/overview`,
 * `/app/timesheets` and `/app/tasks` require only a login — everything that
 * separates a member from a company admin on those pages happens here.
 *
 * Ports visible_user_ids(), org_user_ids(), team_member_ids() and
 * client_viewer_user_ids() from the legacy server/src.
 *
 * ## The zero sentinel
 *
 * Every one of these returns `[0]` rather than `[]` when nobody matches. The
 * legacy code builds `IN (…)` by hand and an empty list produces `IN ()`, which
 * is a MySQL syntax error. Eloquent would not break on an empty array — but it
 * would turn `whereIn` into a contradiction that silently matches nothing,
 * which is the same answer. The sentinel is kept because callers count on a
 * non-empty list (the overview's `count($ids) > 1` roster test is one), and
 * because id 0 matches no row in any table.
 */
class Visibility
{
    /**
     * The user ids this viewer may see.
     *
     * @return list<int>
     */
    public static function userIds(User $user): array
    {
        if ($user->role === UserRole::ClientViewer) {
            // Scoped to their own engagement, never to the organization.
            return self::clientViewerUserIds($user);
        }

        if ($user->hasCapability(Capability::ViewAll)) {
            return self::organizationUserIds($user->effectiveOrgId());
        }

        if ($user->hasCapability(Capability::ViewTeam)) {
            return self::teamMemberIds((int) $user->id);
        }

        return [(int) $user->id];
    }

    /**
     * Everyone in an organization except platform operators.
     *
     * A super admin's own account lives in the platform organization and must
     * never be counted as one of a tenant's people — headcount, payroll and
     * seat billing all read this.
     *
     * @return list<int>
     */
    public static function organizationUserIds(int $organizationId): array
    {
        $ids = User::query()
            ->where('org_id', $organizationId)
            ->members()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $ids ?: [0];
    }

    /**
     * Everyone sharing at least one team with this user, including themselves.
     *
     * A manager with no team assignment sees nobody — not the organization.
     *
     * @return list<int>
     */
    public static function teamMemberIds(int $userId): array
    {
        $ids = DB::table('team_members as mine')
            ->join('team_members as theirs', 'theirs.team_id', '=', 'mine.team_id')
            ->join('users', 'users.id', '=', 'theirs.user_id')
            ->where('mine.user_id', $userId)
            ->where('users.role', '<>', UserRole::SuperAdmin->value)
            ->distinct()
            ->pluck('theirs.user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $ids ?: [0];
    }

    /**
     * The agents a client portal login may see: those assigned to its client,
     * plus anyone who has logged time against that client.
     *
     * The second half matters — time can be tagged to a client by someone who
     * was never formally assigned, and the client is already being billed for
     * it, so hiding the person who did the work would make the portal's own
     * billing page unexplainable.
     *
     * @return list<int>
     */
    public static function clientViewerUserIds(User $user): array
    {
        $client = self::clientFor($user);

        if (! $client) {
            return [0];
        }

        $assigned = DB::table('agent_clients')
            ->where('client_id', $client->id)
            ->pluck('agent_id');

        $logged = WorkSession::query()
            ->where('client_id', $client->id)
            ->distinct()
            ->pluck('user_id');

        $ids = $assigned->merge($logged)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        return $ids ?: [0];
    }

    /**
     * The client record a portal login is attached to (clients.user_id), or
     * null for every other role.
     */
    public static function clientFor(User $user): ?Client
    {
        if ($user->role !== UserRole::ClientViewer) {
            return null;
        }

        return Client::query()
            ->where('user_id', $user->id)
            ->where('org_id', $user->effectiveOrgId())
            ->first();
    }

    /**
     * The client id a portal login is restricted to, or null when the viewer is
     * not a portal login at all.
     *
     * Returns -1 — a client id that cannot exist — when the login has no client
     * attached. Ports billing_client_filter(). The distinction is deliberate:
     * null means "do not filter", -1 means "filter to nothing". Collapsing them
     * would show an unlinked portal login the whole organization's data.
     */
    public static function clientFilter(User $user): ?int
    {
        if ($user->role !== UserRole::ClientViewer) {
            return null;
        }

        $client = self::clientFor($user);

        return $client ? (int) $client->id : -1;
    }

    /**
     * The agents under a team manager, excluding the manager themselves.
     *
     * Ports manager_roster_ids(). Used to decide whether a manager still needs
     * the first-run roster wizard.
     *
     * @return list<int>
     */
    public static function managerRosterIds(User $user): array
    {
        return array_values(array_filter(
            self::teamMemberIds((int) $user->id),
            fn (int $id) => $id > 0 && $id !== (int) $user->id
        ));
    }
}
