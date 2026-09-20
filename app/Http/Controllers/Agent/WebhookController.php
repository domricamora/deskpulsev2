<?php

namespace App\Http\Controllers\Agent;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Middleware\VerifyAgentSignature;
use App\Models\Client;
use App\Models\Device;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\Agent\MonitoringPolicy;
use App\Services\Agent\ScreenshotIngest;
use App\Services\Agent\SessionIngest;
use App\Support\AgentResponse;
use App\Support\PlanLimits;
use App\Support\Token;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * The desktop agent's API. FROZEN.
 *
 * Every route, header, payload shape and status code here is fixed by agents
 * already installed on Windows, macOS and Linux machines. They are not being
 * rebuilt, so this must serve what they already send.
 *
 * Three habits that would each break it:
 *
 * - **Returning 201 on a create.** There is no 201 anywhere; every success is
 *   200. The agent queues on any non-2xx, and 201 is fine, but the contract is
 *   documented as 200 and `tools/test_webhook.py` asserts it.
 * - **Validating with a FormRequest.** That returns 422, which the agent
 *   re-queues forever because the request can never start succeeding. Payloads
 *   are validated by hand here and answer 400/401/402/404.
 * - **Reading the body as an array before verifying.** The HMAC covers the raw
 *   bytes. See {@see VerifyAgentSignature}.
 *
 * @see docs/migration/api-contract.md
 * @see docs/migration/agent-protocol.md
 */
class WebhookController extends Controller
{
    public function __construct(
        private readonly SessionIngest $sessions,
        private readonly ScreenshotIngest $screenshots,
        private readonly MonitoringPolicy $policy,
        private readonly PlanLimits $planLimits,
    ) {}

    /* ── Registration — the one unsigned call ────────────────────────────── */

    /**
     * `POST /webhooks/auth` — exchange credentials for a device secret.
     *
     * NOT idempotent: every call inserts a NEW devices row, so registering
     * twice yields two devices. That is the existing behaviour and the agent
     * simply keeps whichever secret it was last handed; de-duplicating here
     * would orphan a secret an installed agent is still signing with.
     */
    public function register(Request $request): JsonResponse
    {
        $payload = $this->json($request);

        $user = User::query()
            ->where('email', strtolower((string) ($payload['email'] ?? '')))
            ->first();

        if (! $user || ! Hash::check((string) ($payload['password'] ?? ''), $user->password_hash)) {
            return AgentResponse::error(401, 'invalid credentials');
        }

        // A platform operator has no tracked time of their own and must not
        // appear in a tenant's monitoring data.
        if ($user->role === UserRole::SuperAdmin) {
            return AgentResponse::error(403, 'platform operators cannot run the tracker');
        }

        $secret = Token::hex(32);   // 64 lowercase hex characters

        $device = Device::create([
            'user_id' => $user->id,
            'name'    => substr((string) ($payload['device_name'] ?? 'Desktop'), 0, 160),
            'secret'  => $secret,
        ]);

        return AgentResponse::ok([
            'device_id' => (int) $device->id,
            'secret'    => $secret,
            'user'      => ['id' => (int) $user->id, 'name' => $user->name, 'email' => $user->email],
        ]);
    }

    /* ── Bootstrap ───────────────────────────────────────────────────────── */

    /**
     * `GET /webhooks/me` — who this device belongs to.
     *
     * The 401 when the user row is gone is how the agent detects a revoked
     * account and stops tracking, so it must stay a 401 rather than a 404.
     */
    public function me(Request $request): JsonResponse
    {
        $device = $this->device($request);
        $user = User::query()->whereKey($device->user_id)->first();

        if (! $user) {
            return AgentResponse::error(401, 'account no longer exists');
        }

        $organization = Organization::query()->whereKey($user->org_id)->first(['name', 'logo_path']);

        // ISO weekdays as ints (1=Mon … 7=Sun) — the agent uses these to
        // auto-start and auto-stop tracking.
        $days = array_values(array_filter(array_map(
            'intval',
            explode(',', (string) $user->work_days)
        )));

        return AgentResponse::ok([
            'user' => [
                'id'     => (int) $user->id,
                'name'   => $user->name,
                'email'  => $user->email,
                'role'   => $user->role?->value,
                'org_id' => (int) $user->org_id,
            ],
            'device_id' => (int) $device->id,
            'org' => [
                'name' => $organization->name ?? '',
                // Relative; the agent prepends its configured server_url, which
                // already carries the app base path.
                'logo_url' => $organization?->logo_path ? '/uploads/' . $organization->logo_path : null,
            ],
            'schedule' => [
                'work_start' => $user->work_start ?: null,
                'work_end'   => $user->work_end ?: null,
                'work_days'  => $days,
            ],
        ]);
    }

    /** `GET /webhooks/clients` — the organization's active clients. */
    public function clients(Request $request): JsonResponse
    {
        $device = $this->device($request);

        return AgentResponse::ok(
            Client::query()
                ->where('org_id', $device->user?->org_id)
                ->where('archived', 0)
                ->orderBy('name')
                ->get(['id', 'name'])
        );
    }

    /** `GET /webhooks/policy` — what to capture. */
    public function policy(Request $request): JsonResponse
    {
        $device = $this->device($request);

        return AgentResponse::ok($this->policy->for((int) $device->user?->org_id));
    }

    /* ── Tasks ───────────────────────────────────────────────────────────── */

    /** `GET /webhooks/tasks` — open tasks only, newest first. */
    public function tasks(Request $request): JsonResponse
    {
        $device = $this->device($request);

        return AgentResponse::ok(
            Task::query()
                ->where('user_id', $device->user_id)
                ->where('status', 'open')
                ->orderByDesc('created_at')
                ->get(['id', 'title', 'client_id', 'status'])
        );
    }

    /** `POST /webhooks/tasks` — create one. */
    public function createTask(Request $request): JsonResponse
    {
        $device = $this->device($request);
        $payload = $this->json($request);

        $title = trim((string) ($payload['title'] ?? ''));

        if ($title === '') {
            return AgentResponse::error(400, 'title required');
        }

        $task = Task::create([
            'org_id'    => $device->user?->org_id,
            'user_id'   => $device->user_id,
            'client_id' => $this->sessions->validClientId($device, $payload['client_id'] ?? null),
            'title'     => substr($title, 0, 240),
        ]);

        return AgentResponse::ok(['task_id' => (int) $task->id, 'title' => $title]);
    }

    /**
     * `DELETE /webhooks/tasks/{id}` — remove one of this user's tasks.
     *
     * Answers `{"ok":true}` even when nothing matched. Deleting a task the
     * agent has already dropped is not an error, and a 404 would make the
     * agent re-queue a call that can never succeed.
     */
    public function deleteTask(Request $request, int $id): JsonResponse
    {
        $device = $this->device($request);

        Task::query()->whereKey($id)->where('user_id', $device->user_id)->delete();

        return AgentResponse::ok();
    }

    /* ── Session lifecycle ───────────────────────────────────────────────── */

    /** `POST /webhooks/session` — open one, closing anything stale first. */
    public function startSession(Request $request): JsonResponse
    {
        $device = $this->device($request);

        return AgentResponse::ok([
            'session_id' => $this->sessions->start($device, $this->json($request)),
        ]);
    }

    /** `PATCH /webhooks/session/{id}` — close it with final totals. */
    public function stopSession(Request $request, int $id): JsonResponse
    {
        $session = $this->ownedSession($request, $id);

        if (! $session instanceof WorkSession) {
            return $session;
        }

        $this->sessions->stop($session, $this->json($request));

        return AgentResponse::ok();
    }

    /** `POST /webhooks/session/{id}/task` — re-tag an open session. */
    public function setSessionTask(Request $request, int $id): JsonResponse
    {
        $session = $this->ownedSession($request, $id);

        if (! $session instanceof WorkSession) {
            return $session;
        }

        $taskId = $this->sessions->setTask(
            $this->device($request),
            $id,
            $this->json($request)['task_id'] ?? null
        );

        return AgentResponse::ok(['ok' => true, 'task_id' => $taskId]);
    }

    /* ── Monitoring data ─────────────────────────────────────────────────── */

    public function activity(Request $request, int $id): JsonResponse
    {
        $session = $this->ownedSession($request, $id);

        if (! $session instanceof WorkSession) {
            return $session;
        }

        $this->sessions->activity($session, $this->json($request));

        return AgentResponse::ok();
    }

    public function windows(Request $request, int $id): JsonResponse
    {
        $session = $this->ownedSession($request, $id);

        if (! $session instanceof WorkSession) {
            return $session;
        }

        $this->sessions->windows($session, $this->json($request));

        return AgentResponse::ok();
    }

    public function idle(Request $request, int $id): JsonResponse
    {
        $session = $this->ownedSession($request, $id);

        if (! $session instanceof WorkSession) {
            return $session;
        }

        $this->sessions->idle($session, $this->json($request));

        return AgentResponse::ok();
    }

    /**
     * `POST /webhooks/session/{id}/screenshot` — raw image bytes as the body,
     * metadata in the query string.
     *
     * The 402 is the real enforcement of the screenshots plan limit.
     * {@see MonitoringPolicy} asks the agent not to capture, but that is a
     * request to a program on someone else's machine; a stale or modified agent
     * posts anyway. 402 rather than 403 because the call is authenticated and
     * well-formed — the plan simply does not cover it.
     */
    public function screenshot(Request $request, int $id): JsonResponse
    {
        $session = $this->ownedSession($request, $id);

        if (! $session instanceof WorkSession) {
            return $session;
        }

        $device = $this->device($request);
        $organizationId = (int) $device->user?->org_id;

        if (! $this->planLimits->for($organizationId)['screenshots']) {
            return AgentResponse::error(402, 'screenshots are not included on this plan');
        }

        $bytes = $request->getContent();

        if ($bytes === '') {
            return AgentResponse::error(400, 'missing image');
        }

        $extension = strtolower((string) $request->query('ext', 'png'));

        if (! in_array($extension, ScreenshotIngest::ALLOWED_EXTENSIONS, true)) {
            return AgentResponse::error(400, 'unsupported image type');
        }

        return AgentResponse::ok([
            'screenshot_id' => $this->screenshots->store($session, $bytes, $extension, $request->query()),
        ]);
    }

    /* ── Helpers ─────────────────────────────────────────────────────────── */

    /** The device the signature middleware authenticated. */
    private function device(Request $request): Device
    {
        return $request->attributes->get(VerifyAgentSignature::ATTRIBUTE);
    }

    /**
     * Decode the JSON body, tolerating anything.
     *
     * Ports json_body(). A malformed body becomes an empty array rather than a
     * 400, because every handler already treats every field as optional — and
     * a 400 would re-queue the call forever.
     *
     * @return array<string, mixed>
     */
    private function json(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * A session belonging to this device's user, or a 404 response.
     *
     * Also the heartbeat: an open session is stamped `last_seen_at`, which is
     * what keeps it from being auto-closed as stale and what drives the live
     * view. Ports wh_owned_session().
     *
     * Returns the 404 response rather than throwing, so the caller returns it
     * and nothing can turn it into an HTML error page.
     */
    private function ownedSession(Request $request, int $sessionId): WorkSession|JsonResponse
    {
        $device = $this->device($request);
        $session = WorkSession::query()->whereKey($sessionId)->first();

        // "Not found" covers another user's session too — the agent is told no
        // more than that such a session is not its own.
        if (! $session || (int) $session->user_id !== (int) $device->user_id) {
            return AgentResponse::error(404, 'session not found');
        }

        if ($session->ended_at === null) {
            WorkSession::query()
                ->whereKey($session->id)
                ->update(['last_seen_at' => gmdate('Y-m-d H:i:s')]);
        }

        return $session;
    }
}
