<?php
/**
 * Federated sign-in: Google, Microsoft, and per-organization enterprise SSO.
 *
 * ── Why OpenID Connect and not SAML ─────────────────────────────────────────
 * "SSO" in an enterprise RFP often means SAML 2.0. We deliberately do not implement
 * it here. Verifying a SAML assertion means canonicalising XML and validating an
 * XML-DSig signature, and hand-rolling that is a well-documented way to ship a
 * signature-wrapping vulnerability — the class of bug that lets an attacker
 * authenticate as anyone. This repo has no Composer and therefore no vetted SAML
 * library, so the honest options were "do it badly" or "don't do it".
 *
 * OIDC is OAuth 2.0 plus an ID token that is a JWT signed with RS256. Verifying it is
 * a JWKS fetch and an openssl_verify() — small enough to audit, with no XML. Google
 * Workspace, Microsoft Entra ID, Okta, Auth0, JumpCloud and OneLogin all speak it, so
 * it covers the overwhelming majority of what customers actually ask for.
 *
 * ── Security properties ─────────────────────────────────────────────────────
 *   • PKCE (S256) on every flow, plus a `state` nonce bound to the session.
 *   • The ID token signature is verified against the issuer's published JWKS. `alg`
 *     comes from the JWKS key type, never from the token header, so `alg: none` and
 *     RS256→HS256 confusion are both impossible.
 *   • iss / aud / exp / iat / nonce are all checked.
 *   • Accounts are matched on the immutable `sub` claim, not on email. Matching on
 *     email lets a workspace admin who reassigns an address inherit the old account.
 *   • An unverified email never auto-links to an existing password account.
 */

/** Providers that are configured platform-wide. Order is the order shown on the form. */
function oauth_providers(): array
{
    static $out = null;
    if ($out !== null) {
        return $out;
    }
    $cfg = (array) config('oauth', []);
    $defs = [
        'google' => [
            'label'    => 'Google',
            'issuer'   => 'https://accounts.google.com',
            'scope'    => 'openid email profile',
        ],
        'microsoft' => [
            'label'    => 'Microsoft',
            // 'common' allows both work/school (Entra) and personal accounts. The issuer
            // is tenant-specific at runtime, so it is validated against a pattern below.
            'issuer'   => 'https://login.microsoftonline.com/common/v2.0',
            'scope'    => 'openid email profile',
        ],
    ];
    $out = [];
    foreach ($defs as $key => $def) {
        $id = trim((string) ($cfg[$key]['client_id'] ?? ''));
        $secret = trim((string) ($cfg[$key]['client_secret'] ?? ''));
        if ($id !== '' && $secret !== '') {
            $out[$key] = $def + ['client_id' => $id, 'client_secret' => $secret];
        }
    }
    return $out;
}

function oauth_enabled(): bool
{
    return oauth_providers() !== [];
}

/** Redirect URI registered with the provider. Must match byte-for-byte. */
function oauth_redirect_uri(string $provider): string
{
    return abs_url('/auth/' . $provider . '/callback');
}

/** GET/POST with a short timeout. Returns a decoded array, or null. */
function oidc_get_json(string $url, array $post = [], array $headers = []): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
    ]);
    if ($post) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300) {
        return null;
    }
    $data = json_decode((string) $body, true);
    return is_array($data) ? $data : null;
}

/** OIDC discovery document, cached for the request. */
function oidc_discover(string $issuer): ?array
{
    static $cache = [];
    $issuer = rtrim($issuer, '/');
    if (isset($cache[$issuer])) {
        return $cache[$issuer];
    }
    // Only ever fetch over HTTPS — discovery drives every subsequent endpoint.
    if (!preg_match('~^https://~i', $issuer)) {
        return null;
    }
    return $cache[$issuer] = oidc_get_json($issuer . '/.well-known/openid-configuration');
}

function base64url_decode(string $s): string
{
    return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
}

/** Build a PEM public key from a JWKS RSA entry (n, e), without any dependency. */
function jwk_to_pem(array $jwk): ?string
{
    if (($jwk['kty'] ?? '') !== 'RSA' || empty($jwk['n']) || empty($jwk['e'])) {
        return null;
    }
    $der = function (string $raw): string {
        // Prepend 0x00 when the high bit is set, so the INTEGER stays positive.
        if (ord($raw[0]) > 0x7f) {
            $raw = "\x00" . $raw;
        }
        $len = strlen($raw);
        $lenBytes = $len < 128
            ? chr($len)
            : chr(0x80 | strlen($l = ltrim(pack('N', $len), "\x00"))) . $l;
        return "\x02" . $lenBytes . $raw;
    };
    $seq = function (string $body): string {
        $len = strlen($body);
        $lenBytes = $len < 128
            ? chr($len)
            : chr(0x80 | strlen($l = ltrim(pack('N', $len), "\x00"))) . $l;
        return "\x30" . $lenBytes . $body;
    };
    $modulus = $der(base64url_decode($jwk['n']));
    $exponent = $der(base64url_decode($jwk['e']));
    $rsaKey = $seq($modulus . $exponent);
    // Wrap the PKCS#1 key in a SubjectPublicKeyInfo so openssl accepts it.
    $algId = $seq("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01" . "\x05\x00");
    $bitStr = "\x03" . chr(strlen($rsaKey) + 1) . "\x00" . $rsaKey;
    if (strlen($rsaKey) + 1 >= 128) {
        $l = ltrim(pack('N', strlen($rsaKey) + 1), "\x00");
        $bitStr = "\x03" . chr(0x80 | strlen($l)) . $l . "\x00" . $rsaKey;
    }
    $spki = $seq($algId . $bitStr);
    return "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode($spki), 64, "\n")
        . "-----END PUBLIC KEY-----\n";
}

/**
 * Verify an ID token and return its claims, or null.
 *
 * The signing algorithm is taken from the JWKS key, never from the token's own header:
 * trusting the header is how `alg: none` and RS256→HS256 key-confusion attacks work.
 */
function oidc_verify_id_token(string $jwt, array $disco, string $clientId, string $nonce): ?array
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        return null;
    }
    [$h64, $p64, $s64] = $parts;
    $header = json_decode(base64url_decode($h64), true);
    $claims = json_decode(base64url_decode($p64), true);
    if (!is_array($header) || !is_array($claims)) {
        return null;
    }
    $jwks = oidc_get_json((string) ($disco['jwks_uri'] ?? ''));
    if (!$jwks || empty($jwks['keys'])) {
        return null;
    }
    $kid = (string) ($header['kid'] ?? '');
    $pem = null;
    foreach ($jwks['keys'] as $key) {
        if (($key['kty'] ?? '') !== 'RSA') {
            continue;                       // we only accept RSA; no HMAC path exists
        }
        if ($kid === '' || ($key['kid'] ?? '') === $kid) {
            $pem = jwk_to_pem($key);
            if ($pem) {
                break;
            }
        }
    }
    if (!$pem) {
        return null;
    }
    $ok = openssl_verify($h64 . '.' . $p64, base64url_decode($s64), $pem, OPENSSL_ALGO_SHA256);
    if ($ok !== 1) {
        return null;
    }

    $now = time();
    $iss = rtrim((string) ($claims['iss'] ?? ''), '/');
    $expected = rtrim((string) ($disco['issuer'] ?? ''), '/');
    // Microsoft's 'common' endpoint issues tenant-specific issuers, so compare against
    // the discovery document's own issuer with the {tenantid} placeholder resolved.
    if (str_contains($expected, '{tenantid}')) {
        $pattern = '~^' . str_replace('\{tenantid\}', '[0-9a-f-]{36}', preg_quote($expected, '~')) . '$~i';
        if (!preg_match($pattern, $iss)) {
            return null;
        }
    } elseif (!hash_equals($expected, $iss)) {
        return null;
    }

    $aud = $claims['aud'] ?? '';
    $audOk = is_array($aud) ? in_array($clientId, $aud, true) : hash_equals($clientId, (string) $aud);
    if (!$audOk) {
        return null;
    }
    if (($claims['exp'] ?? 0) < $now - 60 || ($claims['iat'] ?? 0) > $now + 300) {
        return null;
    }
    if ($nonce !== '' && !hash_equals($nonce, (string) ($claims['nonce'] ?? ''))) {
        return null;
    }
    if (empty($claims['sub'])) {
        return null;
    }
    return $claims;
}

/** Resolve the provider config for a key: platform provider, or an org's SSO. */
function oauth_config(string $provider, ?int $orgId = null): ?array
{
    if ($provider === 'sso') {
        if (!$orgId) {
            return null;
        }
        $o = db_one('SELECT sso_enabled, sso_issuer, sso_client_id, sso_client_secret
                     FROM organizations WHERE id = ?', [$orgId]);
        if (!$o || empty($o['sso_enabled']) || empty($o['sso_issuer']) || empty($o['sso_client_id'])) {
            return null;
        }
        return [
            'label' => 'your organization',
            'issuer' => (string) $o['sso_issuer'],
            'scope' => 'openid email profile',
            'client_id' => (string) $o['sso_client_id'],
            'client_secret' => (string) dp_decrypt((string) ($o['sso_client_secret'] ?? '')),
        ];
    }
    return oauth_providers()[$provider] ?? null;
}

/** GET /auth/{provider} — start the flow. */
function oauth_start(string $provider): void
{
    $orgId = isset($_GET['org']) ? (int) $_GET['org'] : null;
    $cfg = oauth_config($provider, $orgId);
    if (!$cfg) {
        flash('That sign-in method is not available.', 'error');
        redirect('/login');
    }
    $disco = oidc_discover($cfg['issuer']);
    if (!$disco || empty($disco['authorization_endpoint'])) {
        flash('That identity provider could not be reached. Try again, or sign in with a password.', 'error');
        redirect('/login');
    }

    $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $state = random_token(24);
    $nonce = random_token(24);
    // Bound to the session, so a state value cannot be replayed from another browser.
    $_SESSION['oauth'] = [
        'provider' => $provider, 'org_id' => $orgId, 'state' => $state,
        'nonce' => $nonce, 'verifier' => $verifier,
        'link_user' => current_user()['id'] ?? null,   // linking rather than signing in
        'started' => time(),
    ];

    $params = [
        'client_id'             => $cfg['client_id'],
        'response_type'         => 'code',
        'scope'                 => $cfg['scope'],
        'redirect_uri'          => oauth_redirect_uri($provider),
        'state'                 => $state,
        'nonce'                 => $nonce,
        'code_challenge'        => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256',
        'prompt'                => 'select_account',
    ];
    header('Location: ' . $disco['authorization_endpoint'] . '?' . http_build_query($params));
    exit;
}

/** GET /auth/{provider}/callback — exchange the code and sign in. */
function oauth_callback(string $provider): void
{
    $sess = $_SESSION['oauth'] ?? null;
    unset($_SESSION['oauth']);            // single use, whatever happens next

    $fail = function (string $msg) {
        flash($msg, 'error');
        redirect('/login');
    };

    if (!$sess || ($sess['provider'] ?? '') !== $provider) {
        $fail('That sign-in attempt has expired. Please try again.');
    }
    if (time() - (int) $sess['started'] > 600) {
        $fail('That sign-in attempt took too long. Please try again.');
    }
    if (!hash_equals((string) $sess['state'], (string) ($_GET['state'] ?? ''))) {
        $fail('Sign-in could not be verified. Please try again.');
    }
    if (!empty($_GET['error'])) {
        $fail('Your identity provider declined the sign-in.');
    }
    $code = (string) ($_GET['code'] ?? '');
    if ($code === '') {
        $fail('Sign-in did not complete. Please try again.');
    }

    $cfg = oauth_config($provider, $sess['org_id'] ?? null);
    $disco = $cfg ? oidc_discover($cfg['issuer']) : null;
    if (!$cfg || !$disco || empty($disco['token_endpoint'])) {
        $fail('That identity provider could not be reached.');
    }

    $tok = oidc_get_json($disco['token_endpoint'], [
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => oauth_redirect_uri($provider),
        'client_id'     => $cfg['client_id'],
        'client_secret' => $cfg['client_secret'],
        'code_verifier' => $sess['verifier'],
    ]);
    if (!$tok || empty($tok['id_token'])) {
        $fail('Sign-in could not be completed. Please try again or use a password.');
    }

    $claims = oidc_verify_id_token((string) $tok['id_token'], $disco, $cfg['client_id'],
        (string) $sess['nonce']);
    if (!$claims) {
        $fail('Your identity provider returned a token we could not verify.');
    }

    oauth_sign_in($provider, $claims, $sess);
}

/**
 * Turn verified claims into a session.
 *
 * Matching order: existing identity → linking an already-signed-in user → an existing
 * password account with the SAME VERIFIED email. An unverified email never links, and
 * we never create an organization here — federated sign-in is for people who already
 * have an account, or who are being added to one via enterprise SSO.
 */
function oauth_sign_in(string $provider, array $claims, array $sess): void
{
    $subject = (string) $claims['sub'];
    $email = strtolower(trim((string) ($claims['email'] ?? '')));
    $verified = !empty($claims['email_verified']) || $provider === 'sso';
    $name = trim((string) ($claims['name'] ?? '')) ?: ($email !== '' ? explode('@', $email)[0] : 'User');

    $identity = db_one('SELECT * FROM user_identities WHERE provider = ? AND subject = ?',
        [$provider, $subject]);

    // Linking a provider to the account already signed in.
    if (!empty($sess['link_user'])) {
        $uid = (int) $sess['link_user'];
        if ($identity && (int) $identity['user_id'] !== $uid) {
            flash('That ' . $provider . ' account is already linked to a different DeskPulse user.', 'error');
            redirect('/app/profile');
        }
        if (!$identity) {
            db_exec('INSERT INTO user_identities (user_id, provider, subject, email, last_login_at)
                     VALUES (?, ?, ?, ?, UTC_TIMESTAMP())', [$uid, $provider, $subject, $email ?: null]);
        }
        flash(ucfirst($provider) . ' sign-in linked to your account.', 'success');
        redirect('/app/profile');
    }

    $user = null;
    if ($identity) {
        $user = db_one('SELECT * FROM users WHERE id = ?', [(int) $identity['user_id']]);
    }

    if (!$user && $email !== '' && $verified) {
        $user = db_one('SELECT * FROM users WHERE email = ?', [$email]);
        if ($user && $provider === 'sso') {
            // Enterprise SSO must not reach across tenants.
            if ((int) $user['org_id'] !== (int) ($sess['org_id'] ?? 0)) {
                $user = null;
            }
        }
    }

    // Enterprise SSO may provision a member into its own organization on first sign-in;
    // the org admin has already vouched for the domain by configuring it.
    if (!$user && $provider === 'sso' && !empty($sess['org_id']) && $email !== '') {
        $org = db_one('SELECT id, sso_domains FROM organizations WHERE id = ?', [(int) $sess['org_id']]);
        $domain = substr(strrchr($email, '@') ?: '', 1);
        $allowed = array_filter(array_map('trim',
            explode(',', strtolower((string) ($org['sso_domains'] ?? '')))));
        if ($org && $domain !== '' && in_array($domain, $allowed, true)) {
            $uid = (int) db_exec(
                'INSERT INTO users (org_id, name, email, password_hash, role)
                 VALUES (?, ?, ?, ?, ?)',
                [(int) $org['id'], $name, $email,
                 password_hash(random_token(32), PASSWORD_DEFAULT), 'member']
            );
            $user = db_one('SELECT * FROM users WHERE id = ?', [$uid]);
        }
    }

    if (!$user) {
        flash($email === '' || !$verified
            ? 'Your identity provider did not return a verified email address, so we could not match an account.'
            : 'No DeskPulse account is registered for ' . $email . '. Ask your administrator to add you, or create a workspace first.',
            'error');
        redirect('/login');
    }

    if (!$identity) {
        db_exec('INSERT INTO user_identities (user_id, provider, subject, email, last_login_at)
                 VALUES (?, ?, ?, ?, UTC_TIMESTAMP())',
            [(int) $user['id'], $provider, $subject, $email ?: null]);
    } else {
        db_exec('UPDATE user_identities SET last_login_at = UTC_TIMESTAMP(), email = ? WHERE id = ?',
            [$email ?: null, (int) $identity['id']]);
    }

    // A federated sign-in proves control of the account, so a pending forced password
    // change no longer applies — otherwise an SSO-only user is stuck at a form they
    // have no password for.
    if (!empty($user['must_change_password'])) {
        db_exec('UPDATE users SET must_change_password = 0 WHERE id = ?', [(int) $user['id']]);
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    redirect('/app');
}

/** Organizations whose enforced SSO covers an email domain. */
function sso_org_for_email(string $email): ?array
{
    $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));
    if ($domain === '') {
        return null;
    }
    foreach (db_all('SELECT id, name, sso_domains, sso_enforce FROM organizations
                     WHERE sso_enabled = 1 AND sso_domains IS NOT NULL') as $o) {
        $allowed = array_filter(array_map('trim', explode(',', strtolower((string) $o['sso_domains']))));
        if (in_array($domain, $allowed, true)) {
            return $o;
        }
    }
    return null;
}
