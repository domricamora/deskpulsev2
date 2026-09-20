<?php

namespace App\Services\Remote;

use App\Models\RemoteSession;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Remote desktop control — expiry, scope and frame storage.
 *
 * Ports remote_gc(), remote_scope_or_deny() and the frame paths.
 *
 * ## The three constants ARE the containment
 *
 * A control session is somebody watching and driving another person's machine.
 * Nothing about it may outlive:
 *
 *   - 15s without a frame        → the agent is gone
 *   - 10 minutes without input   → the admin walked away
 *   - 30s never picked up        → the agent never answered
 *
 * `collect()` runs at the top of EVERY endpoint, agent and admin alike, and
 * that placement matters: moving it to the scheduler leaves a window between
 * ticks in which an abandoned session is still live. The server never trusts
 * the agent to end a session.
 *
 * ## Frames are private (decision D5)
 *
 * The legacy writes them to `public/uploads/remote/{id}.jpg`, where
 * `.htaccess` serves any real file — so the live screen of a worker's machine
 * was fetchable, unauthenticated, at a SEQUENTIAL id. That is worse than the
 * screenshot exposure, which at least used sixteen random bytes. Frames now
 * live on the private disk and are deleted when the session ends.
 *
 * @see docs/migration/remote-control.md
 */
class RemoteSessions
{
    /** Active, no frame for this long → the agent has gone. */
    public const AGENT_TIMEOUT_S = 15;

    /** Active, no admin input for this long → nobody is driving. */
    public const IDLE_MAX_S = 600;

    /** Pending and never picked up → the agent never answered. */
    public const PENDING_TIMEOUT_S = 30;

    /** Server-dictated stream parameters. Not negotiable by the agent. */
    public const FPS = 3;

    public const MAX_WIDTH = 1280;

    public const QUALITY = 55;

    /**
     * End every session that has outlived one of the three limits.
     *
     * Called at the top of every endpoint. Cheap: three indexed UPDATEs that
     * normally match nothing.
     */
    public function collect(): void
    {
        RemoteSession::query()
            ->where('status', 'active')
            ->whereNotNull('last_frame_at')
            ->whereRaw('last_frame_at < (UTC_TIMESTAMP() - INTERVAL ? SECOND)', [self::AGENT_TIMEOUT_S])
            ->update($this->ending('agent_gone'));

        RemoteSession::query()
            ->where('status', 'active')
            ->whereNotNull('last_input_at')
            ->whereRaw('last_input_at < (UTC_TIMESTAMP() - INTERVAL ? SECOND)', [self::IDLE_MAX_S])
            ->update($this->ending('expired'));

        RemoteSession::query()
            ->where('status', 'pending')
            ->whereRaw('started_at < (UTC_TIMESTAMP() - INTERVAL ? SECOND)', [self::PENDING_TIMEOUT_S])
            ->update($this->ending('expired'));
    }

    /**
     * May this admin control something in that organization?
     *
     * A platform operator reaches any device, cross-tenant. Everybody else is
     * confined to their own organization, and a mismatch answers **404, not
     * 403** — a 403 would confirm the device exists.
     */
    public function authorize(User $admin, ?int $targetOrganizationId): void
    {
        if ($admin->isSuperAdmin()) {
            return;
        }

        abort_if(
            (int) $targetOrganizationId !== (int) $admin->effectiveOrgId(),
            404,
            'device not found'
        );
    }

    /** Where a session's current frame lives on the private disk. */
    public function framePath(int $sessionId): string
    {
        return 'remote/' . $sessionId . '.jpg';
    }

    public function disk(): Filesystem
    {
        return Storage::disk('private');
    }

    /** End a session and drop its frame — the bytes do not outlive the session. */
    public function end(RemoteSession $session, string $reason): void
    {
        if ($session->status !== 'ended') {
            $session->forceFill([
                'status'     => 'ended',
                'ended_at'   => gmdate('Y-m-d H:i:s'),
                'end_reason' => $reason,
            ])->save();
        }

        $this->disk()->delete($this->framePath((int) $session->id));
    }

    /**
     * Hand over queued input, then delete it.
     *
     * Delivery is AT MOST ONCE by design: commands are removed as they are
     * returned, so a lost response loses them. Making it at-least-once would
     * risk replaying clicks and keystrokes onto somebody's machine.
     *
     * @return list<array<string, mixed>>
     */
    public function drainInput(int $sessionId): array
    {
        $rows = DB::table('remote_input_events')
            ->where('session_id', $sessionId)
            ->orderBy('id')
            ->get(['id', 'payload']);

        if ($rows->isEmpty()) {
            return [];
        }

        $commands = [];

        foreach ($rows as $row) {
            $decoded = json_decode($row->payload, true);

            if (is_array($decoded)) {
                $commands[] = $decoded;
            }
        }

        DB::table('remote_input_events')->whereIn('id', $rows->pluck('id'))->delete();

        return $commands;
    }

    /** @return array<string, mixed> */
    private function ending(string $reason): array
    {
        return [
            'status'     => 'ended',
            'ended_at'   => DB::raw('UTC_TIMESTAMP()'),
            'end_reason' => $reason,
        ];
    }
}
