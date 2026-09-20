<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\Request;

/**
 * `/app/download` — get the desktop agent, and what to type into it.
 *
 * Login only, and deliberately so: a client portal viewer will never install
 * the agent, but hiding the page from them would mean one more role branch for
 * no protection.
 *
 * The three buttons point at `/download/app?os=…`, the public marketing route
 * that picks a native installer or falls back to the portable package. That
 * route is Phase 18.
 *
 * The Server URL shown here is the one the agent's login window asks for, so
 * it must be the PUBLIC base — not whatever host the operator happens to be
 * browsing from.
 *
 * @see docs/migration/routes.md §2
 */
class DownloadController extends Controller
{
    public function show(Request $request)
    {
        return view('dashboard.download', [
            'title'     => 'Download the agent',
            'active'    => 'download',
            'serverUrl' => rtrim(url('/'), '/'),
            'devices'   => Device::query()
                ->where('user_id', $request->user()->id)
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }
}
