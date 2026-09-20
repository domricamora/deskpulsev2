<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\PasswordResetService;
use App\Support\Flash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Forgot-password and reset-password.
 *
 * @see docs/migration/authentication.md §5
 */
class PasswordResetController extends Controller
{
    public function __construct(private readonly PasswordResetService $resets) {}

    /** GET /forgot-password */
    public function showRequestForm(Request $request)
    {
        if ($request->user()) {
            return redirect('/app');
        }

        return view('auth.forgot_password', [
            'title' => 'Reset your DeskPulse password',
            'sent'  => false,
        ]);
    }

    /**
     * POST /forgot-password
     *
     * Always reports the same thing, whether or not the address is registered.
     * The confirmation is rendered directly rather than redirected to, so a
     * refresh does not re-submit and the answer cannot differ between branches.
     */
    public function sendResetLink(Request $request)
    {
        if ($request->user()) {
            return redirect('/app');
        }

        $this->resets->request(
            strtolower(trim((string) $request->input('email'))),
            self::clientIp($request)
        );

        return view('auth.forgot_password', [
            'title' => 'Reset your DeskPulse password',
            'sent'  => true,
        ]);
    }

    /** GET /reset-password?token=… */
    public function showResetForm(Request $request)
    {
        $token = (string) $request->query('token', '');
        [$found, $error] = $this->resets->lookup($token);

        return $this->resetForm($token, $error, $found['user']->email ?? null);
    }

    /** POST /reset-password */
    public function reset(Request $request)
    {
        $token = (string) $request->input('token', '');
        [$found, $error] = $this->resets->lookup($token);

        if ($found === null) {
            return $this->resetForm($token, $error, null);
        }

        $password = (string) $request->input('password');
        $confirm = (string) $request->input('password_confirm');

        if (strlen($password) < 8) {
            Flash::error('Password must be at least 8 characters.');

            return $this->resetForm($token, null, $found['user']->email);
        }

        if ($password !== $confirm) {
            Flash::error('The two passwords do not match.');

            return $this->resetForm($token, null, $found['user']->email);
        }

        $this->resets->complete($found['user'], $found['reset'], $password);

        // Proving control of the inbox signs them in — the alternative is
        // asking for the password they have just set.
        Auth::login($found['user']);
        $request->session()->regenerate();

        Flash::success('Your password has been changed.');

        return redirect('/app');
    }

    /* ── Parts ───────────────────────────────────────────────────────────── */

    private function resetForm(string $token, ?string $error, ?string $email)
    {
        return view('auth.reset_password', [
            'title' => 'Choose a new password',
            'token' => $token,
            'error' => $error,
            'email' => $email,
        ]);
    }

    /**
     * The source address recorded against a reset request.
     *
     * Laravel's ip() honours X-Forwarded-For only for trusted proxies, which is
     * the correct behaviour: an untrusted forwarded header would let anyone
     * forge the address shown in the reset email and sidestep the per-IP limit.
     */
    public static function clientIp(Request $request): string
    {
        return substr((string) $request->ip(), 0, 45);
    }
}
