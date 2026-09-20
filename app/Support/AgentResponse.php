<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * The exact response shapes the desktop agent expects.
 *
 * Ports json_out() and the JSON branch of abort(). Two things make this worth a
 * class rather than inline `response()->json()` calls:
 *
 * **The status codes are a closed set.** 200, 400, 401, 402, 403, 404 and 410 —
 * nothing else. There is no 201 anywhere, and 422 and 429 are never returned.
 * The agent treats any non-2xx as a failure and re-queues the call, so a 422
 * from a validation rule that the legacy handler would have accepted turns into
 * an infinite retry loop against a request that will never succeed.
 * See docs/migration/agent-protocol.md §4.
 *
 * **The error body is `{"error": "<message>"}`** — one key, a bare string. The
 * agent logs it; some messages (`account no longer exists`) are how it detects
 * a revoked account and stops.
 *
 * @see docs/migration/api-contract.md §4
 */
class AgentResponse
{
    /**
     * Every status the agent may legitimately receive.
     *
     * @var list<int>
     */
    public const ALLOWED = [200, 400, 401, 402, 403, 404, 410];

    /** A success body. Always 200 — the legacy json_out() has no other path. */
    public static function ok(mixed $payload = ['ok' => true]): JsonResponse
    {
        return response()->json($payload, 200);
    }

    public static function error(int $status, string $message): JsonResponse
    {
        return response()->json(['error' => $message], $status);
    }
}
