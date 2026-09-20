<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\Capability;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Support\Flash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * `/app/profile` — your own name, contact details and password.
 *
 * Login only. Everybody has a profile, including a client portal viewer and a
 * platform operator, and nothing here reaches anybody else's record.
 *
 * ## The email uniqueness check is global, not per organization
 *
 * `users.email` is unique across the whole table, because it is the login
 * identifier — two tenants cannot both own `nick@example.com`. The duplicate
 * lookup therefore has no `org_id` filter, which looks like a tenancy leak and
 * is not one: it reveals only that some address is taken, which the signup
 * form reveals anyway.
 *
 * ## Pay and schedule are shown, never edited
 *
 * They are set by an admin or HR on `/app/team`. Rendering them here is what
 * stops somebody having to ask what their own rate is; `view_rates` decides
 * whether the client-facing BILL rate appears beside it, because that is the
 * agency's margin and not the employee's business.
 *
 * @see docs/migration/routes.md §2
 */
class ProfileController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();

        return view('dashboard.profile', [
            'title'     => 'My profile',
            'active'    => 'profile',
            'me'        => $user->fresh(),
            'org'       => Organization::query()->whereKey($user->effectiveOrgId())->first(['name']),
            'canRates'  => $user->hasCapability(Capability::ViewRates),
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        $name = trim((string) $request->input('name', ''));
        $email = strtolower(trim((string) $request->input('email', '')));
        $password = (string) $request->input('password', '');

        $error = match (true) {
            $name === '' || $email === ''            => 'Name and email are required.',
            ! filter_var($email, FILTER_VALIDATE_EMAIL) => 'Enter a valid email address.',
            User::query()->where('email', $email)->whereKeyNot($user->id)->exists()
                                                     => 'That email is already in use.',
            $password !== '' && strlen($password) < 8 => 'New password must be at least 8 characters.',
            default                                  => null,
        };

        if ($error) {
            Flash::error($error);

            return redirect('/app/profile');
        }

        $user->forceFill([
            'name'      => $name,
            'email'     => $email,
            'phone'     => substr((string) $request->input('phone', ''), 0, 40),
            'job_title' => substr((string) $request->input('job_title', ''), 0, 120),
        ])->save();

        if ($password !== '') {
            // Not must_change_password: changing your own password on purpose
            // is not the forced rotation that flag exists for.
            $user->forceFill(['password_hash' => Hash::make($password)])->save();
        }

        Flash::success('Profile updated.');

        return redirect('/app/profile');
    }
}
