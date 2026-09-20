<?php

namespace App\Services\Reporting;

use App\Models\User;
use App\Support\Format;
use App\Support\Period;
use App\Support\Visibility;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The live view's payload — everyone tracking right now.
 *
 * Ports dash_live_data(), dash_live_data_platform(), live_agent_card() and
 * live_team_by_user(). Polled every 15 seconds by `resources/js/live.js`, which
 * is the existing implementation and stays that way: the migration plan §26
 * defers WebSockets until after parity, and polling already IS parity.
 *
 * ## Two shapes from one route
 *
 * A platform operator who is NOT acting as a tenant gets every live agent
 * everywhere, grouped organization → team → client. Everybody else gets their
 * visible scope grouped client → team. The JSON carries `mode` so the client
 * knows which tree it is rendering.
 *
 * ## Built in a fixed number of queries
 *
 * The legacy builds each card with four per-session queries — latest window,
 * latest activity sample, latest screenshot, and that user's day so far. At ten
 * live agents that is forty round trips every fifteen seconds, from every open
 * dashboard. Everything here is batched instead, so the query count does not
 * move with the number of people tracking. There is a test that asserts it.
 *
 * ## Not cached, deliberately
 *
 * The migration plan §43 warns against caching volatile live data: with a 15s
 * poll, any TTL worth having makes the view visibly wrong. The endpoint also
 * WRITES — it closes stale sessions — so it cannot be served from a replica or
 * a cached response either.
 *
 * @see docs/migration/live-monitoring.md
 */
class LiveBoard
{
    private const NO_TEAM = 'No team';

    private const NO_CLIENT = 'No client / direct';

    public function __construct(
        private readonly SessionStats $stats,
        private readonly Period $period,
    ) {}

    /**
     * The whole payload for one viewer.
     *
     * @return array<string, mixed>
     */
    public function forViewer(User $viewer, bool $canSeeScreenshots): array
    {
        [$dayStart, $dayEnd] = $this->today((int) $viewer->effectiveOrgId());

        $platformMode = $viewer->isSuperAdmin() && ! $viewer->isActingAsOrganization();

        $open = $platformMode
            ? $this->openEverywhere()
            : $this->openFor(Visibility::userIds($viewer));

        $now = gmdate('c');

        if ($open->isEmpty()) {
            // `now` is still set on an empty board: the client renders a
            // timestamp from it either way.
            return $platformMode
                ? ['now' => $now, 'mode' => 'platform', 'orgs' => [], 'total' => 0]
                : ['now' => $now, 'mode' => 'team', 'groups' => [], 'total' => 0];
        }

        $cards = $this->cards($open, $canSeeScreenshots, $dayStart, $dayEnd);
        $teams = $this->teamNames($open->pluck('user_id')->unique()->all());

        if ($platformMode) {
            return [
                'now'   => $now,
                'mode'  => 'platform',
                'orgs'  => $this->groupPlatform($open, $cards, $teams),
                'total' => $open->count(),
            ];
        }

        return [
            'now'    => $now,
            'mode'   => 'team',
            'groups' => $this->groupTeam($open, $cards, $teams),
            'total'  => $open->count(),
        ];
    }

    /* ── The open sessions ───────────────────────────────────────────────── */

    /** @param  list<int>  $userIds */
    private function openFor(array $userIds): Collection
    {
        if (! $userIds) {
            return collect();
        }

        return DB::table('sessions as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->leftJoin('clients as c', 'c.id', '=', 's.client_id')
            ->whereIn('s.user_id', $userIds)
            ->whereNull('s.ended_at')
            ->orderBy('u.name')
            ->get(['s.id', 's.user_id', 's.started_at', 'u.name as user_name', 'c.name as client_name']);
    }

    /**
     * Every live agent in every tenant.
     *
     * Platform operators are excluded: they have no tracked time of their own
     * and must not appear inside a customer's monitoring data.
     */
    private function openEverywhere(): Collection
    {
        return DB::table('sessions as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->join('organizations as o', 'o.id', '=', 'u.org_id')
            ->leftJoin('clients as c', 'c.id', '=', 's.client_id')
            ->whereNull('s.ended_at')
            ->where('u.role', '<>', 'super_admin')
            ->orderBy('o.name')
            ->orderBy('u.name')
            ->get([
                's.id', 's.user_id', 's.started_at',
                'u.name as user_name', 'o.name as org_name', 'c.name as client_name',
            ]);
    }

    /* ── Cards ───────────────────────────────────────────────────────────── */

    /**
     * One card per open session, keyed by session id.
     *
     * @return array<int, array<string, mixed>>
     */
    private function cards(Collection $open, bool $canSeeScreenshots, string $dayStart, string $dayEnd): array
    {
        $sessionIds = $open->pluck('id')->all();
        $userIds = $open->pluck('user_id')->unique()->all();

        $windows = $this->latestPerSession('window_events', $sessionIds, ['app_name', 'window_title']);
        $activity = $this->latestPerSession('activity_samples', $sessionIds, ['activity_pct']);
        $shots = $canSeeScreenshots
            ? $this->latestPerSession('screenshots', $sessionIds, ['id'])
            : collect();

        // The whole day for everyone on the board, in one query, then rolled up
        // per user with the same summarize() the rest of the reporting uses.
        // approvedOnly is false here, matching the legacy: a session pending
        // approval is still time the person has put in today.
        $todayByUser = $this->stats
            ->forUsers($userIds, $dayStart, $dayEnd, false)
            ->groupBy('user_id');

        $cards = [];

        foreach ($open as $session) {
            $today = $this->stats->summarize($todayByUser->get($session->user_id) ?? collect());
            $shot = $shots->get($session->id);

            $cards[$session->id] = [
                'user_id'      => (int) $session->user_id,
                'name'         => $session->user_name,
                'app'          => $windows->get($session->id)->app_name ?? '',
                'title'        => $windows->get($session->id)->window_title ?? '',
                'activity'     => (int) ($activity->get($session->id)->activity_pct ?? 0),
                'since'        => $session->started_at,
                'client'       => $session->client_name ?: self::NO_CLIENT,
                'screenshot'   => $shot ? route('screenshots.image', $shot->id) : null,
                'today_active' => Format::hms($today['active_s']),
                'today_pct'    => $today['activity_pct'],
            ];
        }

        return $cards;
    }

    /**
     * The newest row per session from one of the monitoring tables.
     *
     * A plain `whereIn` would drag back every sample of every open session — a
     * session running all day holds thousands — so the maximum timestamp is
     * resolved first and joined back to it. One query, one row per session.
     *
     * @param  list<int>  $sessionIds
     * @param  list<string>  $columns
     */
    private function latestPerSession(string $table, array $sessionIds, array $columns): Collection
    {
        if (! $sessionIds) {
            return collect();
        }

        $newest = DB::table($table)
            ->selectRaw('session_id, MAX(ts) AS newest_ts')
            ->whereIn('session_id', $sessionIds)
            ->groupBy('session_id');

        return DB::table($table . ' as row')
            ->joinSub($newest, 'newest', function ($join) {
                $join->on('newest.session_id', '=', 'row.session_id')
                    ->on('newest.newest_ts', '=', 'row.ts');
            })
            ->get(array_map(
                fn (string $column) => 'row.' . $column,
                array_merge(['session_id'], $columns)
            ))
            // Two rows can share the exact newest timestamp; either will do.
            ->keyBy('session_id');
    }

    /**
     * First team name per user, alphabetically. Ports live_team_by_user().
     *
     * @param  list<int>  $userIds
     * @return array<int, string>
     */
    private function teamNames(array $userIds): array
    {
        if (! $userIds) {
            return [];
        }

        $names = [];

        foreach (DB::table('team_members as tm')
            ->join('teams as t', 't.id', '=', 'tm.team_id')
            ->whereIn('tm.user_id', $userIds)
            ->orderBy('t.name')
            ->get(['tm.user_id', 't.name']) as $row) {
            $names[(int) $row->user_id] ??= $row->name;
        }

        return $names;
    }

    /* ── Grouping ────────────────────────────────────────────────────────── */

    /**
     * client → team → members, both levels sorted by name.
     *
     * @param  array<int, array<string, mixed>>  $cards
     * @param  array<int, string>  $teams
     * @return list<array<string, mixed>>
     */
    private function groupTeam(Collection $open, array $cards, array $teams): array
    {
        $tree = [];

        foreach ($open as $session) {
            $card = $cards[$session->id];
            $tree[$card['client']][$teams[$card['user_id']] ?? self::NO_TEAM][] = $card;
        }

        ksort($tree);

        $groups = [];

        foreach ($tree as $client => $byTeam) {
            ksort($byTeam);

            $teamList = [];
            $count = 0;

            foreach ($byTeam as $team => $members) {
                $teamList[] = ['team' => $team, 'members' => $members];
                $count += count($members);
            }

            $groups[] = ['client' => $client, 'count' => $count, 'teams' => $teamList];
        }

        return $groups;
    }

    /**
     * organization → team → client → members, every level sorted by name.
     *
     * @param  array<int, array<string, mixed>>  $cards
     * @param  array<int, string>  $teams
     * @return list<array<string, mixed>>
     */
    private function groupPlatform(Collection $open, array $cards, array $teams): array
    {
        $tree = [];

        foreach ($open as $session) {
            $card = $cards[$session->id];
            $tree[$session->org_name][$teams[$card['user_id']] ?? self::NO_TEAM][$card['client']][] = $card;
        }

        ksort($tree);

        $orgs = [];

        foreach ($tree as $org => $byTeam) {
            ksort($byTeam);

            $teamList = [];
            $orgCount = 0;

            foreach ($byTeam as $team => $byClient) {
                ksort($byClient);

                $clientList = [];
                $teamCount = 0;

                foreach ($byClient as $client => $members) {
                    $clientList[] = ['client' => $client, 'members' => $members];
                    $teamCount += count($members);
                }

                $teamList[] = ['team' => $team, 'count' => $teamCount, 'clients' => $clientList];
                $orgCount += $teamCount;
            }

            $orgs[] = ['org' => $org, 'count' => $orgCount, 'teams' => $teamList];
        }

        return $orgs;
    }

    /**
     * Today in the organization's reporting timezone, as UTC bounds.
     *
     * The same clock the overtime split and the pay run use — the card's
     * "today" has to mean the viewer's organization's today.
     *
     * @return array{0: string, 1: string}
     */
    private function today(int $organizationId): array
    {
        $config = $this->period->organizationConfig($organizationId);

        $today = (new DateTimeImmutable('now', new DateTimeZone($config['tz'])))->format('Y-m-d');

        [$start, $end] = $this->period->bounds('day', $today, $config);

        return [$start, $end];
    }
}
