<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Support\Visibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * `/app/audit` — a security and activity log.
 *
 * Capability `audit`, held by company admins and IT.
 *
 * ## It is a DERIVED view, not a log table
 *
 * Nothing in this system writes audit rows. This page reads the timestamps
 * that already exist — when a session started, when somebody approved an
 * entry, when a device registered, when a share link was minted, when a remote
 * session began and ended — and merges them newest-first.
 *
 * Decision D14 keeps it that way. A real append-only audit table is new
 * functionality, and building one would also make this page start on the day
 * it shipped rather than covering the history already in the database.
 *
 * The consequence worth knowing: this shows what the schema happens to record.
 * A deletion leaves no trace here, because there is no row left to read a
 * timestamp from.
 *
 * @see docs/migration/database.md §5
 */
class AuditController extends Controller
{
    /** Newest 120 across every source. */
    private const LIMIT = 120;

    public function show(Request $request)
    {
        $organizationId = (int) $request->user()->effectiveOrgId();
        $ids = Visibility::organizationUserIds($organizationId);

        $events = [
            ...$this->sessionStarts($ids),
            ...$this->timeReviews($ids),
            ...$this->deviceRegistrations($organizationId),
            ...$this->shareLinks($organizationId),
            ...$this->remoteSessions($organizationId),
        ];

        usort($events, fn (array $a, array $b) => strcmp((string) $b['ts'], (string) $a['ts']));

        return view('dashboard.audit', [
            'title'  => 'Audit log',
            'active' => 'audit',
            'events' => array_slice($events, 0, self::LIMIT),
        ]);
    }

    /** @param  list<int>  $ids */
    private function sessionStarts(array $ids): array
    {
        return DB::table('sessions as s')
            ->join('users as us', 'us.id', '=', 's.user_id')
            ->whereIn('s.user_id', $ids)
            ->orderByDesc('s.started_at')
            ->limit(60)
            ->get(['s.id', 's.started_at as ts', 's.source', 'us.name'])
            ->map(fn ($row) => [
                'ts'     => $row->ts,
                'who'    => $row->name,
                'event'  => $row->source === 'manual' ? 'Manual entry created' : 'Session started',
                'detail' => 'session #' . $row->id,
            ])
            ->all();
    }

    /** @param  list<int>  $ids */
    private function timeReviews(array $ids): array
    {
        return DB::table('sessions as s')
            ->join('users as us', 'us.id', '=', 's.user_id')
            ->leftJoin('users as rv', 'rv.id', '=', 's.reviewed_by_id')
            ->whereIn('s.user_id', $ids)
            ->whereNotNull('s.reviewed_at')
            ->orderByDesc('s.reviewed_at')
            ->limit(40)
            ->get(['s.reviewed_at as ts', 's.approval_status', 'us.name', 'rv.name as reviewer'])
            ->map(fn ($row) => [
                'ts'     => $row->ts,
                'who'    => $row->reviewer ?: 'manager',
                'event'  => 'Time entry ' . $row->approval_status,
                'detail' => 'for ' . $row->name,
            ])
            ->all();
    }

    private function deviceRegistrations(int $organizationId): array
    {
        return DB::table('devices as d')
            ->join('users as us', 'us.id', '=', 'd.user_id')
            ->where('us.org_id', $organizationId)
            ->orderByDesc('d.created_at')
            ->limit(30)
            ->get(['d.created_at as ts', 'd.name as device', 'us.name'])
            ->map(fn ($row) => [
                'ts'     => $row->ts,
                'who'    => $row->name,
                'event'  => 'Device registered',
                'detail' => $row->device,
            ])
            ->all();
    }

    private function shareLinks(int $organizationId): array
    {
        return DB::table('share_links')
            ->where('org_id', $organizationId)
            ->orderByDesc('created_at')
            ->limit(30)
            ->get(['created_at as ts', 'label', 'scope'])
            ->map(fn ($row) => [
                'ts'     => $row->ts,
                // Minted automatically for every member, so nobody "did" it.
                'who'    => 'system',
                'event'  => 'Share link created',
                'detail' => $row->label ?: $row->scope,
            ])
            ->all();
    }

    private function remoteSessions(int $organizationId): array
    {
        $started = DB::table('remote_sessions as rs')
            ->join('users as w', 'w.id', '=', 'rs.user_id')
            ->leftJoin('users as adm', 'adm.id', '=', 'rs.admin_user_id')
            ->where('rs.org_id', $organizationId)
            ->orderByDesc('rs.started_at')
            ->limit(30)
            ->get(['rs.started_at as ts', 'w.name as worker', 'adm.name as admin'])
            ->map(fn ($row) => [
                'ts'     => $row->ts,
                'who'    => $row->admin ?: 'DeskPulse admin',
                'event'  => 'Remote session started',
                'detail' => 'on ' . $row->worker,
            ])
            ->all();

        $ended = DB::table('remote_sessions as rs')
            ->join('users as w', 'w.id', '=', 'rs.user_id')
            ->where('rs.org_id', $organizationId)
            ->whereNotNull('rs.ended_at')
            ->orderByDesc('rs.ended_at')
            ->limit(30)
            ->get(['rs.ended_at as ts', 'w.name as worker', 'rs.end_reason'])
            ->map(fn ($row) => [
                'ts'     => $row->ts,
                'who'    => 'system',
                'event'  => 'Remote session ended',
                'detail' => $row->worker . ' · ' . ($row->end_reason ?: 'ended'),
            ])
            ->all();

        return [...$started, ...$ended];
    }
}
