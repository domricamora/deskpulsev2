<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\RemoteSession;
use App\Models\User;
use App\Services\Remote\RemoteSessions;
use App\Support\Token;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The admin's half of remote control.
 *
 * Capability `remote`, held by platform operators, company admins and IT.
 * (The legacy route comment says "super-admin only, unpublished" — that is
 * stale; `role_caps()` is the truth.)
 *
 * A platform operator reaches any device cross-tenant; everybody else is
 * confined to their own organization, and a mismatch answers **404** so a
 * foreign device is not even revealed to exist.
 *
 * Every endpoint collects expired sessions first. The three limits in
 * {@see RemoteSessions} are the containment mechanism for somebody watching
 * and driving another person's screen, and they are enforced here rather than
 * trusted to the agent.
 *
 * @see docs/migration/remote-control.md §3
 */
class RemoteController extends Controller
{
    public function __construct(private readonly RemoteSessions $sessions) {}

    /** `GET /app/remote/{device}` — the console. */
    public function show(Request $request, int $deviceId)
    {
        $this->sessions->collect();

        [$device, $worker] = $this->device($request, $deviceId);

        return view('dashboard.remote', [
            'title'  => 'Remote control',
            'active' => 'devices',
            'device' => $device,
            'worker' => $worker,
        ]);
    }

    /** `POST /app/remote/{device}/start` — open a pending session. */
    public function start(Request $request, int $deviceId): JsonResponse
    {
        $this->sessions->collect();

        [$device, $worker] = $this->device($request, $deviceId);

        // One session per device: end anything already open so two admins
        // cannot drive the same screen at once.
        foreach (RemoteSession::query()
            ->where('device_id', $device->id)
            ->whereIn('status', ['pending', 'active'])
            ->get() as $existing) {
            $this->sessions->end($existing, 'admin');
        }

        $session = RemoteSession::create([
            'device_id'     => $device->id,
            'user_id'       => $worker->id,
            'admin_user_id' => $request->user()->id,
            'org_id'        => $worker->org_id,
            'token'         => Token::hex(24),
            'status'        => 'pending',
        ]);

        return response()->json(['session_id' => (int) $session->id, 'token' => $session->token]);
    }

    /** `POST /app/remote/{id}/stop` */
    public function stop(Request $request, int $id): JsonResponse
    {
        $session = $this->session($request, $id);

        if ($session) {
            $this->sessions->end($session, 'admin');
        }

        return response()->json(['ok' => true]);
    }

    /** `GET /app/remote/{id}/status` — polled by the console. */
    public function status(Request $request, int $id): JsonResponse
    {
        $this->sessions->collect();

        $session = $this->session($request, $id);

        if (! $session) {
            return response()->json([
                'status' => 'ended', 'seq' => 0,
                'screen_w' => null, 'screen_h' => null, 'last_frame_age' => null,
            ]);
        }

        $age = null;

        if ($session->last_frame_at) {
            $age = max(0, time() - strtotime($session->getRawOriginal('last_frame_at') . ' UTC'));
        }

        return response()->json([
            'status'         => $session->status,
            'seq'            => (int) $session->frame_seq,
            'screen_w'       => $session->screen_w !== null ? (int) $session->screen_w : null,
            'screen_h'       => $session->screen_h !== null ? (int) $session->screen_h : null,
            'last_frame_age' => $age,
        ]);
    }

    /**
     * `GET /app/remote/{id}/frame` — the current frame.
     *
     * 204 when there is none yet, which is what the console expects while a
     * session is still pending. Never cached: this is a live screen.
     */
    public function frame(Request $request, int $id)
    {
        $session = $this->session($request, $id);

        if (! $session) {
            return response()->noContent();
        }

        $path = $this->sessions->framePath($id);

        if (! $this->sessions->disk()->exists($path)) {
            return response()->noContent();
        }

        return response($this->sessions->disk()->get($path), 200, [
            'Content-Type'  => 'image/jpeg',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * `POST /app/remote/{id}/input` — queue events for the agent.
     *
     * The events arrive as a JSON string in a normal form field rather than a
     * JSON body, so Laravel's CSRF check works on them unchanged.
     */
    public function input(Request $request, int $id): JsonResponse
    {
        $this->sessions->collect();

        $session = $this->session($request, $id);

        if (! $session || $session->status !== 'active') {
            return response()->json([
                'ok' => false, 'status' => $session->status ?? 'ended', 'queued' => 0,
            ]);
        }

        $payload = json_decode((string) $request->input('payload', ''), true);
        $events = (is_array($payload) && is_array($payload['events'] ?? null)) ? $payload['events'] : [];

        $queued = 0;

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            DB::table('remote_input_events')->insert([
                'session_id' => $id,
                'payload'    => json_encode($event),
            ]);

            $queued++;
        }

        if ($queued > 0) {
            // Resets the 10-minute idle clock: somebody is still driving.
            $session->forceFill(['last_input_at' => gmdate('Y-m-d H:i:s')])->save();
        }

        return response()->json(['ok' => true, 'queued' => $queued]);
    }

    /* ── Scope ───────────────────────────────────────────────────────────── */

    /**
     * The device and the person it belongs to, or 404.
     *
     * @return array{0: Device, 1: User}
     */
    private function device(Request $request, int $deviceId): array
    {
        $device = Device::query()->whereKey($deviceId)->first();

        abort_if($device === null, 404, 'device not found');

        $worker = User::query()->whereKey($device->user_id)->first();

        abort_if($worker === null, 404, 'worker not found');

        $this->sessions->authorize($request->user(), (int) $worker->org_id);

        return [$device, $worker];
    }

    /** A session this admin may act on, or null. */
    private function session(Request $request, int $id): ?RemoteSession
    {
        $session = RemoteSession::query()->whereKey($id)->first();

        if ($session) {
            $this->sessions->authorize($request->user(), (int) $session->org_id);
        }

        return $session;
    }
}
