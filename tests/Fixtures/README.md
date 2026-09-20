# Test fixtures

## `oidc-signing-key.testing.pem`

A throwaway 2048-bit RSA private key used only by
[`tests/Feature/OidcTest.php`](../Feature/OidcTest.php) to sign the ID tokens it
then asks `OidcClient` to verify.

**It is not a credential.** It is not referenced by any configuration file, it
does not correspond to any identity provider, and nothing outside the test suite
loads it. Rotating or regenerating it changes nothing but the test.

It is committed rather than generated at run time because PHP cannot generate a
key on a machine whose OpenSSL has no `openssl.cnf`:

```
openssl_pkey_new(): error:07000072:configuration file routines::no such file
```

That is the default state of a WAMP PHP install, so generating the key in
`setUp()` would make the OIDC tests pass on CI and fail on a developer's
machine — which is the worst outcome for a test whose whole job is to prove that
signature verification cannot be bypassed.
