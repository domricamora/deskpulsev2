<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\Capability;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Support\Flash;
use Illuminate\Http\Request;

/**
 * `/app/devices` — every desktop-agent install in the organization.
 *
 * Capability `devices`, held by company admins and IT.
 *
 * ## Revoking deletes the row, and that is the whole mechanism
 *
 * A device authenticates with the HMAC secret it was handed at registration.
 * Delete the row and `VerifyAgentSignature` has nothing to check against, so
 * every signed request from that install answers 401 — which is exactly what
 * the agent treats as "signed out", sending the person back to the login
 * window. There is no revocation flag and none is needed.
 *
 * Devices are reached through their USER, because `devices` has no `org_id`
 * column — the join is the tenant boundary, and it is the same transitive
 * scoping the rest of the monitoring tables use.
 *
 * @see docs/migration/api-contract.md
 */
class DeviceController extends Controller
{
    public function show(Request $request)
    {
        return view('dashboard.devices', [
            'title'     => 'Devices',
            'active'    => 'devices',
            'devices'   => Device::query()
                ->join('users', 'users.id', '=', 'devices.user_id')
                ->where('users.org_id', (int) $request->user()->effectiveOrgId())
                ->orderByDesc('devices.last_seen')
                ->orderByDesc('devices.created_at')
                ->get(['devices.*', 'users.name as user_name', 'users.email']),
            'canRemote' => $request->user()->hasCapability(Capability::Remote),
        ]);
    }

    public function update(Request $request)
    {
        if ($request->input('action') === 'revoke') {
            // Scoped through the owning user: a posted device id is not
            // evidence that it belongs to this tenant.
            $deleted = Device::query()
                ->whereKey((int) $request->input('device_id', 0))
                ->whereIn('user_id', function ($query) use ($request) {
                    $query->select('id')
                        ->from('users')
                        ->where('org_id', (int) $request->user()->effectiveOrgId());
                })
                ->delete();

            if ($deleted) {
                Flash::success('Device revoked.');
            }
        }

        return redirect('/app/devices');
    }
}
