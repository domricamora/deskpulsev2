<?php

namespace App\Services\Agent;

use App\Models\Client;
use App\Models\Device;
use App\Models\Task;
use App\Models\WorkSession;
use App\Services\Reporting\Overtime;
use Illuminate\Support\Facades\DB;

/**
 * Everything the agent writes about a work session.
 *
 * Ports the wh_* session handlers from server/src/webhooks.php.
 *
 * ## Validation here is permissive, on purpose
 *
 * {@see self::validClientId()} and {@see self::validTaskId()} return NULL when
 * the id does not belong to the caller — they do not error. Posting another
 * organization's `client_id` silently stores `NULL` and still answers 200.
 *
 * That is the tenant-isolation boundary for ingest, and it is fail-quiet by
 * design. An `exists:` rule returning 422 here would break the agent: it
 * treats any non-2xx as a failure and re-queues the call, so a request that can
 * never succeed retries forever. See docs/migration/api-contract.md §3.
 */
class SessionIngest
{
    public function __construct(private readonly Overtime $overtime) {}

    /**
     * Parse an ISO-8601 timestamp into a UTC MySQL datetime; now when blank or
     * unparseable. Ports wh_ts().
     *
     * Deliberately forgiving: a malformed timestamp from an agent with a
     * confused clock becomes "now" rather than a 400 that would re-queue the
     * batch forever.
     */
    public static function timestamp(mixed $value): string
    {
        if (! $value) {
            return gmdate('Y-m-d H:i:s');
        }

        $parsed = strtotime((string) $value);

        return $parsed ? gmdate('Y-m-d H:i:s', $parsed) : gmdate('Y-m-d H:i:s');
    }

    /**
     * Open a session, closing any the agent left behind.
     *
     * A reconnecting agent — after a crash, a sleep or a force-quit — always
     * opens a FRESH session. Closing the user's stale ones first is what stops
     * them lingering open forever and holding the live view on. They are closed
     * at their last heartbeat, not at now, so the recorded duration reflects
     * time actually tracked rather than the dead gap.
     */
    public function start(Device $device, array $payload): int
    {
        $this->closeStaleFor((int) $device->user_id);

        $session = WorkSession::create([
            'user_id'         => $device->user_id,
            'device_id'       => $device->id,
            'client_id'       => $this->validClientId($device, $payload['client_id'] ?? null),
            'task_id'         => $this->validTaskId($device, $payload['task_id'] ?? null),
            'started_at'      => self::timestamp($payload['started_at'] ?? null),
            'last_seen_at'    => gmdate('Y-m-d H:i:s'),
            'source'          => 'agent',
            'approval_status' => 'approved',
        ]);

        return (int) $session->id;
    }

    public function closeStaleFor(int $userId): void
    {
        WorkSession::query()
            ->where('user_id', $userId)
            ->whereNull('ended_at')
            ->update(['ended_at' => DB::raw('COALESCE(last_seen_at, started_at)')]);
    }

    /**
     * Close every session whose heartbeat has gone quiet, everywhere.
     *
     * Ports close_stale_sessions(). This is the crash case rather than the
     * reconnect case above: an agent that was killed, slept or lost its network
     * never sends the closing PATCH, so without this the session stays open
     * forever and the live view keeps showing someone who left hours ago.
     *
     * It closes at the LAST HEARTBEAT, never at "now" — a machine that crashed
     * at lunch must not bank the afternoon.
     *
     * There is deliberately no scheduler entry (decision D11). The legacy calls
     * this on every dashboard render and every live poll, so `ended_at` lands
     * when somebody looks. Moving it to cron would close sessions earlier for
     * organizations that never open the dashboard, which changes their reported
     * hours — a data change dressed as a cleanup.
     */
    public function closeStale(?int $minutes = null): void
    {
        $minutes = max(1, $minutes ?? (int) config('deskpulse.monitoring.stale_session_min', 15));

        WorkSession::query()
            ->whereNull('ended_at')
            ->whereRaw(
                'COALESCE(last_seen_at, started_at) < (UTC_TIMESTAMP() - INTERVAL ? MINUTE)',
                [$minutes]
            )
            ->update(['ended_at' => DB::raw('COALESCE(last_seen_at, started_at)')]);
    }

    /**
     * Close a session with its final totals, then split the activity into
     * regular and overtime.
     *
     * The recompute starts a day before the session so the daily allowance walk
     * sees that whole day rather than beginning mid-day with the allowance
     * already partly spent.
     */
    public function stop(WorkSession $session, array $payload): void
    {
        $session->forceFill([
            'ended_at'   => self::timestamp($payload['ended_at'] ?? null),
            'active_s'   => (int) ($payload['active_s'] ?? $session->active_s),
            'inactive_s' => (int) ($payload['inactive_s'] ?? $session->inactive_s),
        ])->save();

        $this->overtime->recompute(
            (int) $session->user_id,
            Overtime::dayBefore($session->getRawOriginal('started_at'))
        );
    }

    /** Re-tag an OPEN session with a task. A closed session is not re-tagged. */
    public function setTask(Device $device, int $sessionId, mixed $taskId): ?int
    {
        $resolved = $this->validTaskId($device, $taskId);

        WorkSession::query()
            ->whereKey($sessionId)
            ->where('user_id', $device->user_id)
            ->whereNull('ended_at')
            ->update(['task_id' => $resolved]);

        return $resolved;
    }

    /**
     * Activity samples, plus the live running totals.
     *
     * The `ended_at IS NULL` guard on the totals update is the one piece of
     * replay safety in the system: a batch queued offline and delivered after
     * the session was closed must not overwrite the finalized figures written
     * at stop. Keep it.
     */
    public function activity(WorkSession $session, array $payload): void
    {
        $rows = [];

        foreach ($payload['samples'] ?? [] as $sample) {
            $rows[] = [
                'session_id'     => $session->id,
                'ts'             => self::timestamp($sample['ts'] ?? null),
                'keyboard_count' => (int) ($sample['keyboard'] ?? 0),
                'mouse_count'    => (int) ($sample['mouse'] ?? 0),
                'activity_pct'   => (int) ($sample['pct'] ?? 0),
            ];
        }

        if ($rows) {
            DB::table('activity_samples')->insert($rows);
        }

        if (array_key_exists('active_s', $payload) && $session->ended_at === null) {
            WorkSession::query()
                ->whereKey($session->id)
                ->whereNull('ended_at')
                ->update([
                    'active_s'   => (int) $payload['active_s'],
                    'inactive_s' => (int) ($payload['inactive_s'] ?? 0),
                ]);
        }
    }

    /** Focused-window events and running-process snapshots, in one call. */
    public function windows(WorkSession $session, array $payload): void
    {
        $windows = [];

        foreach ($payload['windows'] ?? [] as $window) {
            $windows[] = [
                'session_id'    => $session->id,
                'ts'            => self::timestamp($window['ts'] ?? null),
                'app_name'      => substr((string) ($window['app'] ?? ''), 0, 160),
                'window_title'  => substr((string) ($window['title'] ?? ''), 0, 400),
                'focus_seconds' => (int) ($window['focus_seconds'] ?? 0),
            ];
        }

        if ($windows) {
            DB::table('window_events')->insert($windows);
        }

        $processes = [];

        foreach ($payload['processes'] ?? [] as $process) {
            $processes[] = [
                'session_id' => $session->id,
                'ts'         => self::timestamp($process['ts'] ?? null),
                'app_name'   => substr((string) ($process['app'] ?? ''), 0, 200),
                'pid'        => (int) ($process['pid'] ?? 0),
            ];
        }

        if ($processes) {
            DB::table('process_snapshots')->insert($processes);
        }
    }

    /**
     * Idle periods. The duration is computed HERE, not sent — the agent
     * reports only the bounds, and a negative span floors at zero.
     */
    public function idle(WorkSession $session, array $payload): void
    {
        $rows = [];

        foreach ($payload['periods'] ?? [] as $period) {
            $start = self::timestamp($period['start'] ?? null);
            $end = self::timestamp($period['end'] ?? null);

            $rows[] = [
                'session_id' => $session->id,
                'start_ts'   => $start,
                'end_ts'     => $end,
                'duration_s' => max(0, strtotime($end) - strtotime($start)),
            ];
        }

        if ($rows) {
            DB::table('idle_periods')->insert($rows);
        }
    }

    /* ── Permissive validation ───────────────────────────────────────────── */

    /** A client in the device user's organization, or null. Never an error. */
    public function validClientId(Device $device, mixed $clientId): ?int
    {
        if (! $clientId) {
            return null;
        }

        $organizationId = $device->user?->org_id;

        $exists = Client::query()
            ->whereKey($clientId)
            ->where('org_id', $organizationId)
            ->exists();

        return $exists ? (int) $clientId : null;
    }

    /** A task owned by the device's user, or null. Never an error. */
    public function validTaskId(Device $device, mixed $taskId): ?int
    {
        if (! $taskId) {
            return null;
        }

        $exists = Task::query()
            ->whereKey($taskId)
            ->where('user_id', $device->user_id)
            ->exists();

        return $exists ? (int) $taskId : null;
    }
}
