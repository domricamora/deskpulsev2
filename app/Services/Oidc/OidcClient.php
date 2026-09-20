<?php

namespace App\Services\Oidc;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * OpenID Connect: discovery, token exchange, and ID token verification.
 *
 * ── Why OIDC and not SAML ────────────────────────────────────────────────────
 * "SSO" in an enterprise RFP often means SAML 2.0. DeskPulse deliberately does
 * not implement it. Verifying a SAML assertion means canonicalising XML and
 * validating an XML-DSig signature, which is a well-documented way to ship a
 * signature-wrapping vulnerability — the class of bug that lets an attacker
 * authenticate as anyone. OIDC is OAuth 2.0 plus a JWT signed with RS256:
 * a JWKS fetch and a signature check, small enough to audit, with no XML.
 * Google Workspace, Entra ID, Okta, Auth0, JumpCloud and OneLogin all speak it.
 *
 * That decision is stated publicly. Do not add SAML.
 *
 * ── Security properties that must survive any refactor ───────────────────────
 *   • The signing algorithm comes from the JWKS key type, NEVER from the
 *     token's own header. Trusting the header is how `alg: none` and RS256→HS256
 *     key-confusion attacks work. There is no HMAC path in this file at all.
 *   • iss, aud, exp, iat and nonce are all checked. Dropping the nonce
 *     reintroduces callback replay.
 *   • Discovery is only ever fetched over HTTPS, because it names every
 *     subsequent endpoint.
 *
 * @see docs/migration/authentication.md §6
 */
class OidcClient
{
    /** @var array<string, array|null> Discovery documents, for the request. */
    private array $discovery = [];

    /**
     * The OIDC discovery document for an issuer.
     *
     * @return array<string, mixed>|null
     */
    public function discover(string $issuer): ?array
    {
        $issuer = rtrim($issuer, '/');

        if (array_key_exists($issuer, $this->discovery)) {
            return $this->discovery[$issuer];
        }

        // Only ever over HTTPS — discovery drives every subsequent endpoint.
        if (! preg_match('~^https://~i', $issuer)) {
            return null;
        }

        return $this->discovery[$issuer] = $this->getJson(
            $issuer . '/.well-known/openid-configuration'
        );
    }

    /**
     * Exchange an authorization code for tokens.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>|null
     */
    public function exchange(string $tokenEndpoint, array $form): ?array
    {
        return $this->getJson($tokenEndpoint, $form);
    }

    /**
     * Verify an ID token and return its claims, or null.
     *
     * Returns null — never a partially-verified token and never an exception
     * carrying provider detail — because the caller turns any failure into the
     * same user-facing message. Distinguishing "bad signature" from "wrong
     * audience" in the UI would only help someone probing the callback.
     *
     * @param  array<string, mixed>  $discovery
     * @return array<string, mixed>|null
     */
    public function verifyIdToken(string $jwt, array $discovery, string $clientId, string $nonce): ?array
    {
        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            return null;
        }

        [$header64, $payload64, $signature64] = $parts;

        $header = json_decode(self::base64UrlDecode($header64), true);
        $claims = json_decode(self::base64UrlDecode($payload64), true);

        if (! is_array($header) || ! is_array($claims)) {
            return null;
        }

        $pem = $this->signingKey((string) ($discovery['jwks_uri'] ?? ''), (string) ($header['kid'] ?? ''));

        if ($pem === null) {
            return null;
        }

        $verified = openssl_verify(
            $header64 . '.' . $payload64,
            self::base64UrlDecode($signature64),
            $pem,
            OPENSSL_ALGO_SHA256
        );

        if ($verified !== 1) {
            return null;
        }

        if (! $this->issuerMatches($claims, $discovery)) {
            return null;
        }

        if (! $this->audienceMatches($claims, $clientId)) {
            return null;
        }

        $now = time();

        // 60s of clock skew on expiry, 300s on issuance — the same tolerances
        // the legacy verifier allows, which real providers do need.
        if (($claims['exp'] ?? 0) < $now - 60 || ($claims['iat'] ?? 0) > $now + 300) {
            return null;
        }

        if ($nonce !== '' && ! hash_equals($nonce, (string) ($claims['nonce'] ?? ''))) {
            return null;
        }

        if (empty($claims['sub'])) {
            return null;
        }

        return $claims;
    }

    /* ── Verification parts ──────────────────────────────────────────────── */

    /**
     * The issuer must match the discovery document's own issuer.
     *
     * Microsoft's `common` endpoint issues tenant-specific issuers, so a
     * discovery issuer containing {tenantid} is compared as a pattern. Everything
     * else is an exact match.
     *
     * @param  array<string, mixed>  $claims
     * @param  array<string, mixed>  $discovery
     */
    private function issuerMatches(array $claims, array $discovery): bool
    {
        $actual = rtrim((string) ($claims['iss'] ?? ''), '/');
        $expected = rtrim((string) ($discovery['issuer'] ?? ''), '/');

        if ($expected === '') {
            return false;
        }

        if (str_contains($expected, '{tenantid}')) {
            $pattern = '~^' . str_replace(
                preg_quote('{tenantid}', '~'),
                '[0-9a-f-]{36}',
                preg_quote($expected, '~')
            ) . '$~i';

            return (bool) preg_match($pattern, $actual);
        }

        return hash_equals($expected, $actual);
    }

    /** @param array<string, mixed> $claims */
    private function audienceMatches(array $claims, string $clientId): bool
    {
        $aud = $claims['aud'] ?? '';

        return is_array($aud)
            ? in_array($clientId, $aud, true)
            : hash_equals($clientId, (string) $aud);
    }

    /**
     * Find the RSA key that signed this token and return it as a PEM.
     *
     * Only RSA keys are considered. A `kid` selects one; an absent `kid` takes
     * the first usable RSA key, which is what a single-key JWKS looks like.
     */
    private function signingKey(string $jwksUri, string $kid): ?string
    {
        if ($jwksUri === '') {
            return null;
        }

        $jwks = $this->getJson($jwksUri);

        if (! $jwks || empty($jwks['keys'])) {
            return null;
        }

        foreach ($jwks['keys'] as $key) {
            if (($key['kty'] ?? '') !== 'RSA') {
                continue;   // no HMAC path exists, by design
            }

            if ($kid === '' || ($key['kid'] ?? '') === $kid) {
                $pem = self::jwkToPem($key);

                if ($pem !== null) {
                    return $pem;
                }
            }
        }

        return null;
    }

    /* ── Wire and encoding ───────────────────────────────────────────────── */

    /**
     * GET, or POST when form fields are given. Returns decoded JSON, or null.
     *
     * Every failure — transport, timeout, non-2xx, malformed body — collapses to
     * null, because the caller treats them identically: the provider could not
     * be reached and the user is offered a password instead.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>|null
     */
    private function getJson(string $url, array $form = []): ?array
    {
        if ($url === '') {
            return null;
        }

        try {
            $request = Http::asForm()
                ->accept('application/json')
                ->connectTimeout((int) config('deskpulse.oauth.connect_timeout_s', 6))
                ->timeout((int) config('deskpulse.oauth.http_timeout_s', 12));

            $response = $form ? $request->post($url, $form) : $request->get($url);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();

        return is_array($data) ? $data : null;
    }

    public static function base64UrlDecode(string $value): string
    {
        return (string) base64_decode(
            strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4)
        );
    }

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * Build a PEM public key from a JWKS RSA entry (n, e).
     *
     * Hand-rolled DER because this repo has no JWT library and adding one to
     * read two integers would be a larger trust surface than the 20 lines below.
     *
     * @param  array<string, mixed>  $jwk
     */
    public static function jwkToPem(array $jwk): ?string
    {
        if (($jwk['kty'] ?? '') !== 'RSA' || empty($jwk['n']) || empty($jwk['e'])) {
            return null;
        }

        $length = function (int $len): string {
            if ($len < 128) {
                return chr($len);
            }

            $bytes = ltrim(pack('N', $len), "\x00");

            return chr(0x80 | strlen($bytes)) . $bytes;
        };

        $integer = function (string $raw) use ($length): string {
            // Prepend 0x00 when the high bit is set, so the INTEGER stays positive.
            if (ord($raw[0]) > 0x7f) {
                $raw = "\x00" . $raw;
            }

            return "\x02" . $length(strlen($raw)) . $raw;
        };

        $sequence = fn (string $body): string => "\x30" . $length(strlen($body)) . $body;

        $rsaKey = $sequence(
            $integer(self::base64UrlDecode($jwk['n'])) . $integer(self::base64UrlDecode($jwk['e']))
        );

        // Wrap the PKCS#1 key in a SubjectPublicKeyInfo so openssl accepts it.
        $algorithm = $sequence("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");
        $bitString = "\x03" . $length(strlen($rsaKey) + 1) . "\x00" . $rsaKey;

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($sequence($algorithm . $bitString)), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }
}
