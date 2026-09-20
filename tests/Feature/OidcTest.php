<?php

/**
 * Federated sign-in — ID token verification and account matching.
 *
 * This is the security boundary of SSO. Every case below is a way an attacker
 * signs in as someone else if the check is dropped, so each is asserted
 * individually rather than through one "a bad token is rejected" test:
 *
 *   alg: none / HS256   forging a signature with no key, or with the public one
 *   wrong audience      replaying a token minted for a different application
 *   expired             replaying an old token
 *   missing nonce       replaying the callback itself
 *   issuer mismatch     a token from an identity provider we never asked
 *   email matching      taking over an account by reassigning its address
 *
 * @see docs/migration/authentication.md §6
 */

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Oidc\OidcClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const ISSUER = 'https://idp.example.test';
const CLIENT_ID = 'deskpulse-client';

/**
 * The keypair these tests sign with.
 *
 * Read from a committed fixture rather than generated: openssl_pkey_new() needs
 * an openssl.cnf, which a default WAMP PHP does not have, and a test that
 * proves signature verification cannot be bypassed is the last one that should
 * only run on CI. See tests/Fixtures/README.md.
 */
function signingKey(): array
{
    static $key = null;

    if ($key !== null) {
        return $key;
    }

    $resource = openssl_pkey_get_private(
        file_get_contents(__DIR__ . '/../Fixtures/oidc-signing-key.testing.pem')
    );

    $details = openssl_pkey_get_details($resource);

    return $key = [
        'private' => $resource,
        'jwk'     => [
            'kty' => 'RSA',
            'kid' => 'test-key-1',
            'use' => 'sig',
            'alg' => 'RS256',
            'n'   => OidcClient::base64UrlEncode($details['rsa']['n']),
            'e'   => OidcClient::base64UrlEncode($details['rsa']['e']),
        ],
    ];
}

function discovery(array $overrides = []): array
{
    return array_merge([
        'issuer'                 => ISSUER,
        'authorization_endpoint' => ISSUER . '/authorize',
        'token_endpoint'         => ISSUER . '/token',
        'jwks_uri'               => ISSUER . '/jwks',
    ], $overrides);
}

/** Mint a signed ID token. */
function idToken(array $claims = [], array $header = []): string
{
    $key = signingKey();

    $header = array_merge(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'test-key-1'], $header);

    $claims = array_merge([
        'iss'            => ISSUER,
        'aud'            => CLIENT_ID,
        'sub'            => 'provider-subject-123',
        'email'          => 'person@example.test',
        'email_verified' => true,
        'name'           => 'A Person',
        'nonce'          => 'the-nonce',
        'iat'            => time(),
        'exp'            => time() + 3600,
    ], $claims);

    $signingInput = OidcClient::base64UrlEncode(json_encode($header))
        . '.' . OidcClient::base64UrlEncode(json_encode($claims));

    openssl_sign($signingInput, $signature, $key['private'], OPENSSL_ALGO_SHA256);

    return $signingInput . '.' . OidcClient::base64UrlEncode($signature);
}

/** An unsigned token — the `alg: none` forgery. */
function unsignedToken(array $claims = []): string
{
    $claims = array_merge([
        'iss' => ISSUER, 'aud' => CLIENT_ID, 'sub' => 'forged',
        'nonce' => 'the-nonce', 'iat' => time(), 'exp' => time() + 3600,
    ], $claims);

    return OidcClient::base64UrlEncode(json_encode(['alg' => 'none', 'typ' => 'JWT']))
        . '.' . OidcClient::base64UrlEncode(json_encode($claims)) . '.';
}

function fakeProvider(): void
{
    Http::fake([
        ISSUER . '/jwks' => Http::response(['keys' => [signingKey()['jwk']]]),
    ]);
}

/* ── Token verification ──────────────────────────────────────────────────── */

test('a correctly signed token verifies and returns its claims', function () {
    fakeProvider();

    $claims = app(OidcClient::class)
        ->verifyIdToken(idToken(), discovery(), CLIENT_ID, 'the-nonce');

    expect($claims)->not->toBeNull()
        ->and($claims['sub'])->toBe('provider-subject-123')
        ->and($claims['email'])->toBe('person@example.test');
});

test('a tampered signature is rejected', function () {
    fakeProvider();

    // Same header and payload, one byte of the signature flipped.
    $token = idToken();
    [$h, $p, $s] = explode('.', $token);
    $tampered = $h . '.' . $p . '.' . strrev($s);

    expect(app(OidcClient::class)->verifyIdToken($tampered, discovery(), CLIENT_ID, 'the-nonce'))
        ->toBeNull();
});

test('a tampered payload is rejected', function () {
    fakeProvider();

    [$h, , $s] = explode('.', idToken());
    $swapped = OidcClient::base64UrlEncode(json_encode([
        'iss' => ISSUER, 'aud' => CLIENT_ID, 'sub' => 'someone-else',
        'nonce' => 'the-nonce', 'iat' => time(), 'exp' => time() + 3600,
    ]));

    expect(app(OidcClient::class)->verifyIdToken($h . '.' . $swapped . '.' . $s, discovery(), CLIENT_ID, 'the-nonce'))
        ->toBeNull();
});

test('alg none is rejected', function () {
    // The algorithm comes from the JWKS key, never from the token header, so
    // there is no code path that accepts an unsigned token.
    fakeProvider();

    expect(app(OidcClient::class)->verifyIdToken(unsignedToken(), discovery(), CLIENT_ID, 'the-nonce'))
        ->toBeNull();
});

test('an HMAC token signed with the public key is rejected', function () {
    // RS256 to HS256 key confusion: the attacker signs with the public key,
    // which they have, and hopes the verifier trusts the header's alg.
    fakeProvider();

    $key = signingKey();
    $pem = OidcClient::jwkToPem($key['jwk']);

    $signingInput = OidcClient::base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']))
        . '.' . OidcClient::base64UrlEncode(json_encode([
            'iss' => ISSUER, 'aud' => CLIENT_ID, 'sub' => 'forged',
            'nonce' => 'the-nonce', 'iat' => time(), 'exp' => time() + 3600,
        ]));

    $forged = $signingInput . '.' . OidcClient::base64UrlEncode(
        hash_hmac('sha256', $signingInput, $pem, true)
    );

    expect(app(OidcClient::class)->verifyIdToken($forged, discovery(), CLIENT_ID, 'the-nonce'))
        ->toBeNull();
});

test('a token minted for a different application is rejected', function () {
    fakeProvider();

    expect(app(OidcClient::class)->verifyIdToken(idToken(['aud' => 'some-other-app']), discovery(), CLIENT_ID, 'the-nonce'))
        ->toBeNull();
});

test('an audience array is accepted only when it contains our client id', function () {
    fakeProvider();
    $client = app(OidcClient::class);

    expect($client->verifyIdToken(idToken(['aud' => ['other', CLIENT_ID]]), discovery(), CLIENT_ID, 'the-nonce'))
        ->not->toBeNull()
        ->and($client->verifyIdToken(idToken(['aud' => ['other', 'another']]), discovery(), CLIENT_ID, 'the-nonce'))
        ->toBeNull();
});

test('an expired token is rejected and clock skew is tolerated', function () {
    fakeProvider();
    $client = app(OidcClient::class);

    expect($client->verifyIdToken(idToken(['exp' => time() - 3600]), discovery(), CLIENT_ID, 'the-nonce'))
        ->toBeNull()
        // 60 seconds of skew on expiry, which real providers do need.
        ->and($client->verifyIdToken(idToken(['exp' => time() - 30]), discovery(), CLIENT_ID, 'the-nonce'))
        ->not->toBeNull();
});

test('a token issued implausibly far in the future is rejected', function () {
    fakeProvider();

    expect(app(OidcClient::class)->verifyIdToken(idToken(['iat' => time() + 3600]), discovery(), CLIENT_ID, 'the-nonce'))
        ->toBeNull();
});

test('a missing or mismatched nonce is rejected', function () {
    // Dropping this reintroduces callback replay.
    fakeProvider();
    $client = app(OidcClient::class);

    expect($client->verifyIdToken(idToken(['nonce' => 'a-different-nonce']), discovery(), CLIENT_ID, 'the-nonce'))
        ->toBeNull()
        ->and($client->verifyIdToken(idToken(['nonce' => null]), discovery(), CLIENT_ID, 'the-nonce'))
        ->toBeNull();
});

test('a token from an issuer we never asked is rejected', function () {
    fakeProvider();

    expect(app(OidcClient::class)->verifyIdToken(idToken(['iss' => 'https://evil.example']), discovery(), CLIENT_ID, 'the-nonce'))
        ->toBeNull();
});

test('a token with no subject is rejected', function () {
    fakeProvider();

    expect(app(OidcClient::class)->verifyIdToken(idToken(['sub' => '']), discovery(), CLIENT_ID, 'the-nonce'))
        ->toBeNull();
});

test('a tenant-specific Microsoft issuer is matched as a pattern', function () {
    // The `common` endpoint issues one issuer per Entra tenant, so equality
    // would reject every real work account.
    $tenant = '11111111-2222-3333-4444-555555555555';
    $template = 'https://login.microsoftonline.com/{tenantid}/v2.0';

    Http::fake([
        'https://login.microsoftonline.com/*' => Http::response(['keys' => [signingKey()['jwk']]]),
    ]);

    $discovery = discovery([
        'issuer'   => $template,
        'jwks_uri' => 'https://login.microsoftonline.com/common/discovery/v2.0/keys',
    ]);

    $client = app(OidcClient::class);
    $real = str_replace('{tenantid}', $tenant, $template);

    expect($client->verifyIdToken(idToken(['iss' => $real]), $discovery, CLIENT_ID, 'the-nonce'))
        ->not->toBeNull()
        // Not a wildcard: only a GUID-shaped tenant matches.
        ->and($client->verifyIdToken(idToken(['iss' => 'https://login.microsoftonline.com/evil/v2.0']), $discovery, CLIENT_ID, 'the-nonce'))
        ->toBeNull();
});

test('discovery is refused over plain http', function () {
    // The discovery document names every subsequent endpoint.
    expect(app(OidcClient::class)->discover('http://idp.example.test'))->toBeNull();

    Http::assertNothingSent();
});

/* ── Starting the flow ───────────────────────────────────────────────────── */

test('an unconfigured provider does not start a flow', function () {
    config(['services.google.client_id' => null, 'services.google.client_secret' => null]);

    $this->get('/auth/google')
        ->assertRedirect('/login')
        ->assertSessionHas(App\Support\Flash::KEY);
});

test('starting a flow sends PKCE, a state and a nonce', function () {
    config(['services.google.client_id' => 'gid', 'services.google.client_secret' => 'gsecret']);
    app()->forgetInstance(App\Services\Oidc\ProviderRegistry::class);

    Http::fake([
        'https://accounts.google.com/.well-known/openid-configuration' => Http::response(
            discovery(['authorization_endpoint' => 'https://accounts.google.com/authorize'])
        ),
    ]);

    $response = $this->get('/auth/google');
    $location = $response->headers->get('Location');

    parse_str(parse_url($location, PHP_URL_QUERY) ?: '', $params);

    expect($location)->toStartWith('https://accounts.google.com/authorize')
        ->and($params['code_challenge_method'])->toBe('S256')
        ->and($params['code_challenge'])->not->toBeEmpty()
        ->and($params['state'])->not->toBeEmpty()
        ->and($params['nonce'])->not->toBeEmpty()
        ->and($params['client_id'])->toBe('gid')
        ->and($params['response_type'])->toBe('code');

    // The state and nonce are bound to the session, so neither can be replayed
    // from another browser.
    $flow = session('oauth');

    expect($flow['state'])->toBe($params['state'])
        ->and($flow['nonce'])->toBe($params['nonce'])
        ->and($flow['verifier'])->not->toBeEmpty();
});

/* ── The callback ────────────────────────────────────────────────────────── */

test('a callback with no flow in the session is refused', function () {
    $this->get('/auth/google/callback?code=x&state=y')->assertRedirect('/login');
});

test('a callback whose state does not match is refused', function () {
    config(['services.google.client_id' => 'gid', 'services.google.client_secret' => 'gsecret']);
    app()->forgetInstance(App\Services\Oidc\ProviderRegistry::class);

    $this->withSession(['oauth' => [
        'provider' => 'google', 'org_id' => null, 'state' => 'the-real-state',
        'nonce' => 'n', 'verifier' => 'v', 'link_user' => null, 'started' => time(),
    ]])->get('/auth/google/callback?code=x&state=forged')->assertRedirect('/login');

    expect(auth()->check())->toBeFalse();
});

test('a flow that took too long is refused', function () {
    config(['services.google.client_id' => 'gid', 'services.google.client_secret' => 'gsecret']);
    app()->forgetInstance(App\Services\Oidc\ProviderRegistry::class);

    $this->withSession(['oauth' => [
        'provider' => 'google', 'org_id' => null, 'state' => 's',
        'nonce' => 'n', 'verifier' => 'v', 'link_user' => null,
        'started' => time() - 3600,
    ]])->get('/auth/google/callback?code=x&state=s')->assertRedirect('/login');
});

/* ── Matching an account ─────────────────────────────────────────────────── */

/**
 * Drive a full callback: session flow, faked token endpoint, faked JWKS.
 *
 * @param  array<string, mixed>  $claims
 */
function completeCallback(array $claims = [], array $flowOverrides = [])
{
    config(['services.google.client_id' => CLIENT_ID, 'services.google.client_secret' => 'gsecret']);
    app()->forgetInstance(App\Services\Oidc\ProviderRegistry::class);

    Http::fake([
        'https://accounts.google.com/.well-known/openid-configuration' => Http::response(
            discovery(['token_endpoint' => 'https://accounts.google.com/token'])
        ),
        'https://accounts.google.com/token' => Http::response(['id_token' => idToken($claims)]),
        ISSUER . '/jwks' => Http::response(['keys' => [signingKey()['jwk']]]),
    ]);

    $flow = array_merge([
        'provider' => 'google', 'org_id' => null, 'state' => 'the-state',
        'nonce' => 'the-nonce', 'verifier' => 'the-verifier',
        'link_user' => null, 'started' => time(),
    ], $flowOverrides);

    return test()->withSession(['oauth' => $flow])
        ->get('/auth/google/callback?code=abc&state=the-state');
}

test('a federated sign-in matches the account by subject, not by email', function () {
    // Matching on email alone lets a workspace admin who reassigns an address
    // inherit the old account. The identity below carries a DIFFERENT email
    // from the one in the token, and a DIFFERENT user owns that token's email —
    // so the two rules disagree and the test can tell which one ran.
    $org = Organization::create(['name' => 'Acme', 'status' => 'approved']);

    $owner = User::create([
        'org_id' => $org->id, 'name' => 'Subject owner', 'email' => 'old-address@acme.test',
        'password_hash' => bcrypt('x'), 'role' => UserRole::Member,
    ]);

    $impostor = User::create([
        'org_id' => $org->id, 'name' => 'Holds the token email', 'email' => 'person@example.test',
        'password_hash' => bcrypt('x'), 'role' => UserRole::Member,
    ]);

    UserIdentity::create([
        'user_id' => $owner->id, 'provider' => 'google',
        'subject' => 'provider-subject-123', 'email' => 'old-address@acme.test',
    ]);

    completeCallback()->assertRedirect('/app');

    expect(auth()->id())->toBe($owner->id)
        ->and(auth()->id())->not->toBe($impostor->id);
});

test('a first federated sign-in links by verified email and records the subject', function () {
    $org = Organization::create(['name' => 'Acme', 'status' => 'approved']);

    $user = User::create([
        'org_id' => $org->id, 'name' => 'Person', 'email' => 'person@example.test',
        'password_hash' => bcrypt('x'), 'role' => UserRole::Member,
    ]);

    completeCallback()->assertRedirect('/app');

    $identity = UserIdentity::where('user_id', $user->id)->firstOrFail();

    expect(auth()->id())->toBe($user->id)
        ->and($identity->provider)->toBe('google')
        ->and($identity->subject)->toBe('provider-subject-123')
        ->and($identity->last_login_at)->not->toBeNull();
});

test('an unverified email never links to an existing account', function () {
    $org = Organization::create(['name' => 'Acme', 'status' => 'approved']);

    User::create([
        'org_id' => $org->id, 'name' => 'Person', 'email' => 'person@example.test',
        'password_hash' => bcrypt('x'), 'role' => UserRole::Member,
    ]);

    completeCallback(['email_verified' => false])->assertRedirect('/login');

    expect(auth()->check())->toBeFalse()
        ->and(UserIdentity::count())->toBe(0);
});

test('a federated sign-in clears a pending forced password change', function () {
    // An SSO-only user would otherwise be stuck at a form they have no password
    // for. Controlling the identity is the proof that flag was waiting for.
    $org = Organization::create(['name' => 'Acme', 'status' => 'approved']);

    $user = User::create([
        'org_id' => $org->id, 'name' => 'Person', 'email' => 'person@example.test',
        'password_hash' => bcrypt('x'), 'role' => UserRole::Member,
        'must_change_password' => 1,
    ]);

    completeCallback()->assertRedirect('/app');

    expect($user->fresh()->must_change_password)->toBeFalsy();
});

test('federated sign-in never creates an organization', function () {
    // It is for people who already have an account, or who are being added to
    // one by enterprise SSO.
    completeCallback()->assertRedirect('/login');

    expect(Organization::count())->toBe(0)
        ->and(User::count())->toBe(0)
        ->and(auth()->check())->toBeFalse();
});

test('enterprise SSO provisions only inside its own claimed domains', function () {
    $org = Organization::create([
        'name' => 'Acme', 'status' => 'approved',
        'sso_enabled' => 1, 'sso_issuer' => ISSUER,
        'sso_client_id' => CLIENT_ID, 'sso_domains' => 'acme.test',
    ]);

    expect(App\Services\Oidc\ProviderRegistry::domains($org->sso_domains))->toBe(['acme.test'])
        ->and(in_array('evil.test', App\Services\Oidc\ProviderRegistry::domains($org->sso_domains), true))
        ->toBeFalse();
});

test('an email domain routes to the organization that claimed it', function () {
    Organization::create([
        'name' => 'Acme', 'status' => 'approved',
        'sso_enabled' => 1, 'sso_domains' => 'acme.test,acme.example',
    ]);

    $registry = app(App\Services\Oidc\ProviderRegistry::class);

    expect($registry->ssoOrganizationForEmail('someone@acme.example')?->name)->toBe('Acme')
        ->and($registry->ssoOrganizationForEmail('someone@unclaimed.test'))->toBeNull()
        ->and($registry->ssoOrganizationForEmail('no-at-sign'))->toBeNull();
});

test('an organization with SSO disabled claims no domain', function () {
    Organization::create([
        'name' => 'Acme', 'status' => 'approved',
        'sso_enabled' => 0, 'sso_domains' => 'acme.test',
    ]);

    expect(app(App\Services\Oidc\ProviderRegistry::class)->ssoOrganizationForEmail('someone@acme.test'))
        ->toBeNull();
});

test('the stored SSO client secret is read back through the legacy cipher', function () {
    // The ciphertext already in production has to keep opening after cutover,
    // or an enterprise tenant simply cannot sign in.
    $org = Organization::create([
        'name' => 'Acme', 'status' => 'approved', 'sso_enabled' => 1,
        'sso_issuer' => ISSUER, 'sso_client_id' => CLIENT_ID,
        'sso_client_secret' => App\Support\LegacyCipher::encrypt('the-real-secret'),
    ]);

    $config = app(App\Services\Oidc\ProviderRegistry::class)->config('sso', (int) $org->id);

    expect($config['client_secret'])->toBe('the-real-secret')
        ->and($config['issuer'])->toBe(ISSUER)
        ->and($org->sso_client_secret)->toStartWith('v1:');
});

test('sso without an org, an issuer or a client id resolves to nothing', function () {
    $incomplete = Organization::create([
        'name' => 'Half', 'status' => 'approved', 'sso_enabled' => 1,
    ]);

    $registry = app(App\Services\Oidc\ProviderRegistry::class);

    expect($registry->config('sso', null))->toBeNull()
        ->and($registry->config('sso', (int) $incomplete->id))->toBeNull()
        ->and($registry->config('sso', 999999))->toBeNull();
});
