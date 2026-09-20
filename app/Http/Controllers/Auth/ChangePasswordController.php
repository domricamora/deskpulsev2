<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Flash;
use Illuminate\Http\Request;

/**
 * Gate 2's destination — set your own password.
 *
 * Reached by anyone whose account was created with a temporary one: client
 * portal logins, and accounts a super admin creates from the Platform page.
 * The page is also open to someone who simply wants to change their password.
 *
 * @see docs/migration/authentication.md §2
 */
class ChangePasswordController extends Controller
{
    /** GET /app/change-password */
    public function show(Request $request)
    {
        return view('auth.change_password', [
            'title' => 'Set your password',
            'user'  => $request->user(),
        ]);
    }

    /**
     * POST /app/change-password
     *
     * Redirects back to itself on a validation failure rather than re-rendering,
     * so a refresh cannot resubmit a password.
     */
    public function update(Request $request)
    {
        $password = (string) $request->input('password');
        $confirm = (string) $request->input('password_confirm');

        if (strlen($password) < 8) {
            Flash::error('Password must be at least 8 characters.');

            return redirect('/app/change-password');
        }

        if ($password !== $confirm) {
            Flash::error('The two passwords do not match.');

            return redirect('/app/change-password');
        }

        $request->user()->forceFill([
            'password_hash'        => bcrypt($password),
            'must_change_password' => 0,
        ])->save();

        Flash::success('Password set — welcome to DeskPulse.');

        return redirect('/app');
    }
}
