<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\Oidc\ProviderRegistry;
use App\Support\Access;
use App\Support\Flash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Password sign-in and sign-out.
 *
 * @see docs/migration/authentication.md §3
 */
class LoginController extends Controller
{
    public function __construct(private readonly ProviderRegistry $providers) {}

    /**
     * GET /login
     *
     * Already signed in — opened /login directly, or a stale tab after switching
     * accounts — goes to the dashboard instead of showing the form.
     */
    public function show(Request $request)
    {
        if ($request->user()) {
            return redirect('/app');
        }

        return $this->form();
    }

    /**
     * POST /login
     *
     * Returns the form again on failure rather than redirecting, so `?next=` on
     * the action URL survives a wrong password.
     */
    public function store(Request $request)
    {
        $email = strtolower(trim((string) $request->input('email')));
        $password = (string) $request->input('password');

        $user = User::query()->where('email', $email)->first();

        // An organization can require SSO. Refuse the password path for its
        // people even when the password is correct — otherwise "enforce SSO" is
        // decorative. Checked BEFORE the password so a correct password does not
        // leak that the address exists to someone probing an SSO-only tenant.
        if ($user) {
            $org = Organization::query()
                ->where('id', $user->org_id)
                ->first(['id', 'name', 'sso_enabled', 'sso_enforce']);

            if ($org && $org->sso_enabled && $org->sso_enforce) {
                Flash::error($org->name . ' requires single sign-on. Use the button above.');

                return $this->form($org, $email);
            }
        }

        if (Auth::attempt(['email' => $email, 'password' => $password])) {
            // A fresh session id on privilege change — the standard fixation
            // defence, and what session_regenerate_id(true) did.
            $request->session()->regenerate();

            return redirect(Access::internalPath($request->query('next')));
        }

        // Offer SSO if the address belongs to a domain an organization has
        // claimed, even though the password was wrong — that is usually why it
        // was wrong.
        Flash::error('Invalid email or password.');

        return $this->form($this->providers->ssoOrganizationForEmail($email), $email);
    }

    /** GET /logout */
    public function destroy(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    /**
     * The sign-in form.
     *
     * Social buttons sit ABOVE the password form: for a B2B audience they
     * convert better than email/password, and burying them wastes that.
     */
    private function form(?Organization $ssoHint = null, string $oldEmail = '')
    {
        return view('auth.login', [
            'title'     => 'Sign in to DeskPulse',
            'providers' => $this->providers->providers(),
            'ssoHint'   => $ssoHint,
            'oldEmail'  => $oldEmail,
        ]);
    }
}
