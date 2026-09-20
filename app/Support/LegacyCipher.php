<?php

namespace App\Support;

/**
 * The legacy `v1:` symmetric cipher — AES-256-GCM under a SHA-256 of the app
 * secret, stored base64 as `v1:` . iv(12) . tag(16) . ciphertext.
 *
 * Ports dp_encrypt() / dp_decrypt(). This is decision D15, settled by porting
 * the reader rather than asking every tenant to re-enter their secrets: the
 * ciphertext already in `organizations.sso_client_secret`,
 * `wise_api_token_enc` and `wise_sca_private_enc` has to keep opening after
 * cutover, and an enterprise SSO tenant whose client secret stopped decrypting
 * would simply be unable to sign in.
 *
 * Deliberately NOT Laravel's Crypt: that uses APP_KEY and its own envelope
 * format, and would not read a single existing row. New secrets are written in
 * the same format so a rollback to the legacy application still works — which
 * is what makes the cutover reversible.
 *
 * @see docs/migration/security.md §4
 */
class LegacyCipher
{
    private const PREFIX = 'v1:';

    /** iv(12) + tag(16) + at least one byte of ciphertext. */
    private const MIN_RAW_BYTES = 29;

    public static function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }

        $iv = random_bytes(12);
        $tag = '';

        $cipher = openssl_encrypt(
            $plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag
        );

        if ($cipher === false) {
            return '';
        }

        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    /** Returns '' for anything that is not readable — never throws, never leaks. */
    public static function decrypt(?string $stored): string
    {
        $stored = (string) $stored;

        if ($stored === '' || ! str_starts_with($stored, self::PREFIX)) {
            return '';
        }

        $raw = base64_decode(substr($stored, 3), true);

        if ($raw === false || strlen($raw) < self::MIN_RAW_BYTES) {
            return '';
        }

        $plain = openssl_decrypt(
            substr($raw, 28),
            'aes-256-gcm',
            self::key(),
            OPENSSL_RAW_DATA,
            substr($raw, 0, 12),
            substr($raw, 12, 16)
        );

        return $plain === false ? '' : $plain;
    }

    /**
     * The legacy key derivation, byte for byte.
     *
     * The default matters: a deployment that never set `app_secret` encrypted
     * everything under the string 'deskpulse', and those rows must still open.
     */
    private static function key(): string
    {
        return hash('sha256', (string) config('deskpulse.app_secret', 'deskpulse'), true);
    }
}
