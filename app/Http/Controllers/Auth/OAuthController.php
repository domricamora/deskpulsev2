<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Oidc\OidcClient;
use App\Services\Oidc\ProviderRegistry;
use App\Support\Flash;
use App\Support\Token;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Federated sign-in: /auth/{provider} and /auth/{provider}/callback.
 *
 * `provider` is one of the configured platform providers (google, microsoft) or
 * the literal `sso`, which resolves to the organization named by ?org=.
 *
 * Every security property of this flow is enforced here or in OidcClient:
 * PKCE (S256), a `state` bound to the session, a `nonce` carried through to the
 * ID token, and matching on the immutable `sub` claim rather than on email.
 *
 * @see docs/migration/authentication.md §6
 */
class OAuthController extends Controller
{
    private const SESSION_KEY = 'oauth';

    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly OidcClient $oidc,
    ) {}

    /** GET /auth/{provider} — start the flow. */
    public function start(Request $request, string $provider): RedirectResponse
    {
        $orgId = $request->query('org') !== null ? (int) $request->query('org') : null;
        $config = $this->providers->config($provider, $orgId);

        if (! $config) {
            Flash::error('That sign-in method is not available.');

            return redirect('/login');
        }

        $discovery = $this->oidc->discover($config['issuer']);

        if (! $discovery || empty($discovery['authorization_endpoint'])) {
            Flash::error('That identity provider could not be reached. Try again, or sign in with a password.');

            return redirect('/login');
        }

        $verifier = OidcClient::base64UrlEncode(random_bytes(48));
        $state = Token::random(24);
        $nonce = Token::random(24);

        // Bound to the session, so a state value cannot be replayed from another
        // browser.
        $request->session()->put(self::SESSION_KEY, [
            'provider'  => $provider,
            'org_id'    => $orgId,
            'state'     => $state,
            'nonce'     => $nonce,
            'verifier'  => $verifier,

            // Set when an already-signed-in user is LINKING a provider rather
            // than signing in with one.
            'link_user' => $request->user()?->id,
            'started'   => time(),
        ]);

        return redirect()->away($discovery['authorization_endpoint'] . '?' . http_build_query([
            'client_id'             => $config['client_id'],
            'response_type'         => 'code',
            'scope'                 => $config['scope'],
            'redirect_uri'          => $this->providers->redirectUri($provider),
            'state'                 => $state,
            'nonce'                 => $nonce,
            'code_challenge'        => OidcClient::base64UrlEncode(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
            'prompt'                => 'select_account',
        ]));
    }

    /** GET /auth/{provider}/callback — exchange the code and sign in. */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        $flow = $request->session()->pull(self::SESSION_KEY);   // single use, whatever happens next

        if (! is_array($flow) || ($flow['provider'] ?? '') !== $provider) {
            return $this->fail('That sign-in attempt has expired. Please try again.');
        }

        $maxAge = (int) config('deskpulse.oauth.flow_max_age_s', 600);

        if (time() - (int) $flow['started'] > $maxAge) {
            return $this->fail('That sign-in attempt took too long. Please try again.');
        }

        if (! hash_equals((string) $flow['state'], (string) $request->query('state', ''))) {
            return $this->fail('Sign-in could not be verified. Please try again.');
        }

        if ($request->query('error')) {
            return $this->fail('Your identity provider declined the sign-in.');
        }

        $code = (string) $request->query('code', '');

        if ($code === '') {
            return $this->fail('Sign-in did not complete. Please try again.');
        }

        $config = $this->providers->config($provider, $flow['org_id'] ?? null);
        $discovery = $config ? $this->oidc->discover($config['issuer']) : null;

        if (! $config || ! $discovery || empty($discovery['token_endpoint'])) {
            return $this->fail('That identity provider could not be reached.');
        }

        $tokens = $this->oidc->exchange($discovery['token_endpoint'], [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $this->providers->redirectUri($provider),
            'client_id'     => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'code_verifier' => $flow['verifier'],
        ]);

        if (! $tokens || empty($tokens['id_token'])) {
            return $this->fail('Sign-in could not be completed. Please try again or use a password.');
        }

        $claims = $this->oidc->verifyIdToken(
            (string) $tokens['id_token'],
            $discovery,
            $config['client_id'],
            (string) $flow['nonce']
        );

        if (! $claims) {
            return $this->fail('Your identity provider returned a token we could not verify.');
        }

        return $this->signIn($request, $provider, $claims, $flow);
    }

    /**
     * Turn verified claims into a session.
     *
     * Matching order: an existing identity → linking an already-signed-in user →
     * an existing password account with the SAME VERIFIED email.
     *
     * An unverified email NEVER links to an existing account, and no
     * organization is ever created here: federated sign-in is for people who
     * already have an account, or who are being added to one by enterprise SSO.
     *
     * @param  array<string, mixed>  $claims
     * @param  array<string, mixed>  $flow
     */
    private function signIn(Request $request, string $provider, array $claims, array $flow): RedirectResponse
    {
        $subject = (string) $claims['sub'];
        $email = strtolower(trim((string) ($claims['email'] ?? '')));

        // An enterprise IdP the organization configured itself is trusted for
        // its own domain; a public provider must say the address is verified.
        $verified = ! empty($claims['email_verified']) || $provider === 'sso';

        $name = trim((string) ($claims['name'] ?? ''))
            ?: ($email !== '' ? explode('@', $email)[0] : 'User');

        $identity = UserIdentity::query()
            ->where('provider', $provider)
            ->where('subject', $subject)
            ->first();

        if (! empty($flow['link_user'])) {
            return $this->link((int) $flow['link_user'], $provider, $subject, $email, $identity);
        }

        $user = $identity ? User::find($identity->user_id) : null;

        if (! $user && $email !== '' && $verified) {
            $user = User::query()->where('email', $email)->first();

            // Enterprise SSO must not reach across tenants.
            if ($user && $provider === 'sso' && (int) $user->org_id !== (int) ($flow['org_id'] ?? 0)) {
                $user = null;
            }
        }

        if (! $user && $provider === 'sso' && ! empty($flow['org_id']) && $email !== '') {
            $user = $this->provisionSsoMember((int) $flow['org_id'], $email, $name);
        }

        if (! $user) {
            return $this->fail($email === '' || ! $verified
                ? 'Your identity provider did not return a verified email address, so we could not match an account.'
                : 'No DeskPulse account is registered for ' . $email . '. Ask your administrator to add you, or create a workspace first.');
        }

        $this->recordIdentity($identity, $user, $provider, $subject, $email);

        // A federated sign-in proves control of the account, so a pending forced
        // password change no longer applies — otherwise an SSO-only user is
        // stuck at a form they have no password for.
        if ($user->must_change_password) {
            $user->forceFill(['must_change_password' => 0])->save();
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/app');
    }

    /** Link a provider to the account already signed in. */
    private function link(
        int $userId,
        string $provider,
        string $subject,
        string $email,
        ?UserIdentity $identity,
    ): RedirectResponse {
        if ($identity && (int) $identity->user_id !== $userId) {
            Flash::error('That ' . $provider . ' account is already linked to a different DeskPulse user.');

            return redirect('/app/profile');
        }

        if (! $identity) {
            UserIdentity::create([
                'user_id'       => $userId,
                'provider'      => $provider,
                'subject'       => $subject,
                'email'         => $email ?: null,
                'last_login_at' => now(),
            ]);
        }

        Flash::success(ucfirst($provider) . ' sign-in linked to your account.');

        return redirect('/app/profile');
    }

    /**
     * Enterprise SSO may provision a member into its own organization on first
     * sign-in — the organization's admin has already vouched for the domain by
     * configuring it.
     *
     * The new account gets a random password it will never know: its only way in
     * is the identity provider.
     */
    private function provisionSsoMember(int $organizationId, string $email, string $name): ?User
    {
        $org = Organization::query()->where('id', $organizationId)->first(['id', 'sso_domains']);
        $domain = substr(strrchr($email, '@') ?: '', 1);

        if (! $org || $domain === '') {
            return null;
        }

        if (! in_array($domain, ProviderRegistry::domains($org->sso_domains), true)) {
            return null;
        }

        return User::create([
            'org_id'        => $org->id,
            'name'          => $name,
            'email'         => $email,
            'password_hash' => bcrypt(Token::random(32)),
            'role'          => UserRole::Member,
        ]);
    }

    /**
     * The identity row is keyed on the provider's immutable `subject`.
     *
     * Matching on email alone would let a workspace admin who reassigns an
     * address inherit the old account — so the email here is a record of what
     * the provider last said, never the key.
     */
    private function recordIdentity(
        ?UserIdentity $identity,
        User $user,
        string $provider,
        string $subject,
        string $email,
    ): void {
        if ($identity) {
            $identity->forceFill([
                'last_login_at' => now(),
                'email'         => $email ?: null,
            ])->save();

            return;
        }

        UserIdentity::create([
            'user_id'       => $user->id,
            'provider'      => $provider,
            'subject'       => $subject,
            'email'         => $email ?: null,
            'last_login_at' => now(),
        ]);
    }

    private function fail(string $message): RedirectResponse
    {
        Flash::error($message);

        return redirect('/login');
    }
}
