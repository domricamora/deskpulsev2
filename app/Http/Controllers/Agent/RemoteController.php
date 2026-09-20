<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Http\Middleware\VerifyAgentSignature;
use App\Models\RemoteSession;
use App\Services\Remote\RemoteSessions;
use App\Support\AgentResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The agent's half of remote control. FROZEN, like the rest of `/webhooks`.
 *
 * `agent/monitor/remote.py` has been polling `/webhooks/remote/poll` on a
 * three-second loop since before this migration started — it has been getting
 * a 404 for every one of them, which the poller treats as an outage and backs
 * off from. These three routes are what it has been waiting for.
 *
 * Every endpoint runs the garbage collector first. The server never trusts the
 * agent to end a session; see {@see RemoteSessions}.
 *
 * @see docs/migration/remote-control.md §4
 */
class RemoteController extends Controller
{
    public function __construct(private readonly RemoteSessions $sessions) {}

    /**
     * `GET /webhooks/remote/poll` — should this device be streaming?
     *
     * Activates a pending session as a side effect: the poll IS the agent
     * accepting the request.
     */
    public function poll(Request $request): JsonResponse
    {
        $this->sessions->collect();

        $device = $request->attributes->get(VerifyAgentSignature::ATTRIBUTE);

        $session = RemoteSession::query()
            ->where('device_id', $device->id)
            ->whereIn('status', ['pending', 'active'])
            ->orderByDesc('id')
            ->first();

        if (! $session) {
            return AgentResponse::ok(['session' => null]);
        }

        if ($session->status === 'pending') {
            $session->forceFill([
                'status'        => 'active',
                'activated_at'  => gmdate('Y-m-d H:i:s'),
                // Stamped now so the 15s agent timeout measures from pickup,
                // not from a null that would never expire.
                'last_frame_at' => gmdate('Y-m-d H:i:s'),
            ])->save();
        }

        return AgentResponse::ok(['session' => [
            'id'    => (int) $session->id,
            'token' => $session->token,
            // Server-dictated: these bound the bandwidth a control session can
            // consume and the agent does not get a say.
            'fps'       => RemoteSessions::FPS,
            'max_width' => RemoteSessions::MAX_WIDTH,
            'quality'   => RemoteSessions::QUALITY,
        ]]);
    }

    /**
     * `POST /webhooks/remote/{id}/frame` — raw JPEG body, commands back.
     *
     * Raw bytes for the same reason as screenshots: the HMAC covers the body,
     * and multipart would empty it.
     */
    public function frame(Request $request, int $id): JsonResponse
    {
        $this->sessions->collect();

        $device = $request->attributes->get(VerifyAgentSignature::ATTRIBUTE);
        $session = RemoteSession::query()->whereKey($id)->first();

        // "ended" is the agent's stop signal — it drops back to idle polling.
        if (! $session
            || (int) $session->device_id !== (int) $device->id
            || $session->status !== 'active') {
            return AgentResponse::ok(['status' => 'ended']);
        }

        $bytes = $request->getContent();

        if ($bytes !== '') {
            $this->sessions->disk()->put($this->sessions->framePath($id), $bytes);
        }

        $update = [
            'frame_seq'     => $session->frame_seq + 1,
            'last_frame_at' => gmdate('Y-m-d H:i:s'),
        ];

        // Only on the first frame: the real screen size, which is what maps
        // the admin's normalized coordinates back to pixels.
        if ((int) $session->frame_seq === 0 && $request->has('w') && $request->has('h')) {
            $update['screen_w'] = (int) $request->query('w');
            $update['screen_h'] = (int) $request->query('h');
        }

        $session->forceFill($update)->save();

        return AgentResponse::ok([
            'status'   => 'active',
            'commands' => $this->sessions->drainInput($id),
        ]);
    }

    /** `POST /webhooks/remote/{id}/end` — the agent stopped streaming. */
    public function end(Request $request, int $id): JsonResponse
    {
        $this->sessions->collect();

        $device = $request->attributes->get(VerifyAgentSignature::ATTRIBUTE);

        $session = RemoteSession::query()
            ->whereKey($id)
            ->where('device_id', $device->id)
            ->first();

        if ($session) {
            $this->sessions->end($session, 'agent_gone');
        }

        return AgentResponse::ok();
    }
}
