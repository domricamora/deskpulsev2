<?php

namespace App\Http\Middleware;

use App\Models\Device;
use App\Support\AgentResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * HMAC-SHA256 device authentication for `/webhooks/*`.
 *
 * Ports verify_webhook() from server/src/auth.php. Every rule below is frozen:
 * the desktop agent for Windows, macOS and Linux is not changing, and it is the
 * only client. See docs/migration/api-contract.md §1.
 *
 * ## The four rules that cannot drift
 *
 * 1. **The signature covers the RAW request body**, byte for byte, read once.
 *    `$request->getContent()` — never a re-encoded array. Re-serializing JSON
 *    reorders keys and respaces separators, which changes the bytes and fails
 *    every signature.
 * 2. **Lowercase hex**, not base64. The agent calls `.hexdigest()`.
 * 3. **An empty body is signed too.** GET and DELETE sign `b""`, and the agent
 *    always sends the header. `hash_hmac` of `''` is a valid, required
 *    signature — not a reason to skip verification.
 * 4. **`hash_equals`**, for constant-time comparison.
 *
 * Every verified call stamps `devices.last_seen`.
 *
 * ## No replay protection
 *
 * There is no nonce, timestamp or window, so a captured request replays
 * forever. That is the current behaviour and it is reproduced deliberately;
 * adding rejection would be a behaviour change the agent has not been built
 * for. The one piece of replay safety in the system is the `ended_at IS NULL`
 * guard on the activity endpoint. See docs/migration/security.md.
 */
class VerifyAgentSignature
{
    /** The authenticated device, for the controller. */
    public const ATTRIBUTE = 'dp_device';

    public function handle(Request $request, Closure $next): Response
    {
        $deviceId = $request->header('X-DeskPulse-Device', '');
        $signature = (string) $request->header('X-DeskPulse-Signature', '');

        if ($deviceId === '' || $deviceId === null) {
            return AgentResponse::error(401, 'missing device header');
        }

        $device = Device::query()->whereKey($deviceId)->first();

        if (! $device) {
            return AgentResponse::error(401, 'unknown device');
        }

        $expected = hash_hmac('sha256', $request->getContent(), (string) $device->secret);

        if (! hash_equals($expected, $signature)) {
            return AgentResponse::error(401, 'bad signature');
        }

        /*
         * NOW(), not UTC_TIMESTAMP() — the only write in the ingest path on
         * server-local time while everything else is UTC. Reproduced as-is:
         * "fixing" it would shift every displayed last-seen the moment this
         * ships, which is a visible change to a column operators read.
         * Recorded in docs/migration/api-contract.md §1.
         */
        // where('id'), not whereKey(): whereKey() is an Eloquent Builder
        // method, and on a query builder it silently becomes where('key').
        DB::table('devices')->where('id', $device->id)->update(['last_seen' => DB::raw('NOW()')]);

        $request->attributes->set(self::ATTRIBUTE, $device);

        return $next($request);
    }
}
